<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

use OCA\WorkoutCalendar\Garmin\Auth\Credentials;
use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\Diagnostics\Redactor;
use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Garmin\Http\NetworkException;
use OCA\WorkoutCalendar\Storage\MfaOrigin;
use OCA\WorkoutCalendar\Storage\PendingMfa;
use OCA\WorkoutCalendar\Storage\SecretStore;
use OCA\WorkoutCalendar\Storage\UserStore;
use OCA\WorkoutCalendar\Sync\FailureLog;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Two-step verification triggered by the person: request a code, confirm it, cancel the request.
 * Everything runs under the person's lock. Codes are never stored and never logged.
 */
final class MfaService
{
    private readonly FailureLog $failureLog;

    public function __construct(
        private readonly AccountService $accounts,
        private readonly GarminClientFactory $garmin,
        private readonly SecretStore $secrets,
        private readonly UserStore $users,
        private readonly PersonLock $lock,
        private readonly ITimeFactory $time,
        LoggerInterface $logger,
    ) {
        $this->failureLog = new FailureLog($logger);
    }

    /**
     * Signs in with the stored password (during setup: with the credentials of the pending setup request).
     * Garmin then either signs in directly or sends a code, which waits as a pending request.
     *
     * @throws MfaException MFA_RATE_LIMITED with the seconds until the next allowed request
     * @throws GarminException for any other answer from Garmin; INVALID_CREDENTIALS also pauses the sync
     * @throws \InvalidArgumentException if there are no credentials to sign in with
     * @throws PersonBusyException if a sync run or another change holds the person's lock
     */
    public function request(string $uid): LoginOutcome
    {
        return $this->lock->run($uid, function () use ($uid): LoginOutcome {
            $now = $this->time->getTime();
            $pending = $this->secrets->pendingMfa($uid, $now);
            $setupCredentials = $pending?->origin === MfaOrigin::Setup ? $pending->credentials : null;
            $credentials = $setupCredentials ?? $this->secrets->credentials($uid);
            if ($credentials === null) {
                throw new \InvalidArgumentException('No Garmin credentials to sign in with');
            }

            $requests = $this->users->mfaRequests($uid);
            $nextAllowedAt = MfaRequestLimit::nextAllowedAt($requests, $now);
            if ($nextAllowedAt !== null) {
                throw new MfaException(MfaException::RATE_LIMITED, $nextAllowedAt - $now);
            }
            // WHY before Garmin: a failed sign-in counts as well, it also counts towards an account lock.
            $this->users->saveMfaRequests($uid, [...MfaRequestLimit::recent($requests, $now), $now]);

            // The remembered cookies belong to the connected account, not to a new one from the setup.
            $cookies = $setupCredentials === null ? $this->secrets->ssoCookies($uid) : [];
            $login = $this->garmin->mobileLogin($cookies, [$credentials->password, $credentials->email]);
            try {
                $tokens = $this->network('mobile.login', $credentials, fn (): GarminTokens => $login->login($credentials));
            } catch (GarminException $e) {
                if ($e->category === FailureCategory::MfaRequired && $e->mfaChallenge !== null) {
                    $origin = $setupCredentials !== null ? MfaOrigin::Setup : MfaOrigin::Relogin;
                    // WHY no pause: valid tokens keep the sync running; a pause from the sync stays until the code.
                    $this->accounts->requireCode($uid, new PendingMfa($e->mfaChallenge, $origin, $now, $setupCredentials));

                    return LoginOutcome::CodeRequired;
                }
                if ($e->category === FailureCategory::InvalidCredentials && $setupCredentials === null) {
                    $this->pauseForInvalidCredentials($uid, $e, $now);
                }

                throw $e;
            }

            $this->accounts->completeLogin($uid, $credentials->email, $credentials, $tokens);
            if ($cookies === []) {
                // Signed in with the password alone: the account does not use two-step verification (any more).
                $this->accounts->setMfaAccount($uid, false, null);
            }

            return LoginOutcome::SignedIn;
        });
    }

    /**
     * Confirms the code of the pending request in its SSO session. A wrong code keeps the request.
     *
     * @throws \InvalidArgumentException if the code does not have six digits (spaces are removed); Garmin is not asked
     * @throws MfaException MFA_NOT_PENDING without a request, MFA_EXPIRED after its 30 minutes
     * @throws GarminException MFA_CODE_INVALID for a wrong code, or another failure from Garmin
     * @throws PersonBusyException if a sync run or another change holds the person's lock
     */
    public function verify(string $uid, string $code): void
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            throw new \InvalidArgumentException('The code must have six digits');
        }

        $this->lock->run($uid, function () use ($uid, $code): void {
            $now = $this->time->getTime();
            $pending = $this->pending($uid, $now);
            $credentials = $pending->origin === MfaOrigin::Setup ? $pending->credentials : $this->secrets->credentials($uid);
            if ($credentials === null) {
                $this->secrets->deletePendingMfa($uid);

                throw new MfaException(MfaException::NOT_PENDING);
            }

            $login = $this->garmin->mobileLogin([], [$code, ...$pending->challenge->cookies]);
            $tokens = $this->network('mobile.mfa-verify', $credentials, fn (): GarminTokens => $login->verifyCode($pending->challenge, $code));

            $this->accounts->completeLogin($uid, $credentials->email, $credentials, $tokens);
            $this->accounts->setMfaAccount($uid, true, $pending->challenge->method);
            // "Remember this browser": the cookies may spare a code at the next sign-in with the password.
            $this->secrets->saveSsoCookies($uid, $login->exportCookies());
        });
    }

    /** @throws PersonBusyException if a sync run or another change holds the person's lock */
    public function cancel(string $uid): void
    {
        $this->lock->run($uid, fn () => $this->secrets->deletePendingMfa($uid));
    }

    /** @throws MfaException */
    private function pending(string $uid, int $now): PendingMfa
    {
        // WHY read without expiry: SecretStore::pendingMfa() reports an expired request as missing,
        // but the person should learn that it expired and a new code is needed.
        $pending = $this->secrets->pendingMfa($uid, PHP_INT_MIN);
        if ($pending === null) {
            throw new MfaException(MfaException::NOT_PENDING);
        }
        if ($pending->isExpired($now)) {
            $this->secrets->deletePendingMfa($uid);

            throw new MfaException(MfaException::EXPIRED);
        }

        return $pending;
    }

    /** The same way as the sync: no further sign-in attempt until new credentials are entered. */
    private function pauseForInvalidCredentials(string $uid, GarminException $e, int $now): void
    {
        $state = $this->users->state($uid);
        $state->pausedFor = FailureCategory::InvalidCredentials->value;
        $this->failureLog->recordFailure($state, $e, $now);
        $this->users->saveState($uid, $state);
    }

    /**
     * MobileLogin is used without the Authenticator here, so a missing HTTP response is turned into a
     * GarminException the same way.
     *
     * @param callable(): GarminTokens $fn
     * @throws GarminException
     */
    private function network(string $step, Credentials $credentials, callable $fn): GarminTokens
    {
        try {
            return $fn();
        } catch (NetworkException $e) {
            $redactor = new Redactor([$credentials->password, $credentials->email]);

            throw new GarminException(FailureCategory::Network, $step, $redactor->redact($e->getMessage()), previous: $e);
        }
    }
}
