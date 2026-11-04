<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Auth;

use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\Diagnostics\Redactor;
use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Garmin\Http\NetworkException;
use Psr\Log\LoggerInterface;

/**
 * Tokens for sign-in with email and password: use stored tokens, refresh them on expiry and
 * sign in again only when the refresh token is no longer accepted.
 */
final class PasswordTokenProvider implements TokenProvider
{
    /** @var \Closure(): int */
    private readonly \Closure $now;
    private readonly Redactor $redactor;

    /**
     * @param (callable(): int)|null $now Current Unix time, replaceable in tests
     * @param bool $passwordLogin False for an account with two-step verification: a due login is not attempted
     *                            but reported as MFA_REQUIRED, so the background sync never uses the password
     */
    public function __construct(
        private readonly Authenticator $authenticator,
        private readonly DiTokenService $diTokens,
        private readonly TokenStore $store,
        private readonly Credentials $credentials,
        private readonly LoggerInterface $logger,
        ?callable $now = null,
        private readonly bool $passwordLogin = true,
    ) {
        $this->now = $now !== null ? \Closure::fromCallable($now) : static fn (): int => time();
        $this->redactor = new Redactor([$credentials->password]);
    }

    public function current(): GarminTokens
    {
        $tokens = $this->store->load();
        if ($tokens === null) {
            return $this->login();
        }
        if ($tokens->isExpired(($this->now)())) {
            return $this->renew($tokens);
        }

        return $tokens;
    }

    /** Refresh; a new login if the refresh token is rejected. */
    public function renew(GarminTokens $rejected): GarminTokens
    {
        try {
            $renewed = $this->diTokens->refresh($rejected);
        } catch (NetworkException $e) {
            // Keeps the step of the request without a response, so a failed refresh is not reported as the activity request.
            throw new GarminException(FailureCategory::Network, 'refresh', $this->redactor->redact($e->getMessage()), previous: $e);
        } catch (GarminException $e) {
            if ($e->category !== FailureCategory::TokenRejected) {
                throw $e;
            }
            $this->logger->info('Garmin rejected the refresh token, signing in again', $e->logContext());

            return $this->login();
        }
        $this->store->save($renewed);

        return $renewed;
    }

    private function login(): GarminTokens
    {
        if (!$this->passwordLogin) {
            throw new GarminException(FailureCategory::MfaRequired, 'login.skipped', 'Account uses two-step verification, no background sign-in');
        }
        $tokens = $this->authenticator->login($this->credentials);
        $this->store->save($tokens);

        return $tokens;
    }
}
