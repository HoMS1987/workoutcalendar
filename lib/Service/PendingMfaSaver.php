<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

use OCA\WorkoutCalendar\BackgroundJob\MfaCleanupJob;
use OCA\WorkoutCalendar\Storage\MfaOrigin;
use OCA\WorkoutCalendar\Storage\PendingMfa;
use OCA\WorkoutCalendar\Storage\SecretStore;
use OCA\WorkoutCalendar\Storage\UserStore;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Stores a code request, counts it for the request limit and schedules its removal once it expires.
 * Every place that saves a PendingMfa uses this.
 */
final class PendingMfaSaver
{
    /** "phone" is what Garmin reports for a text message. */
    private const KNOWN_METHODS = ['email', 'sms', 'phone'];

    public function __construct(
        private readonly SecretStore $secrets,
        private readonly IJobList $jobs,
        private readonly LoggerInterface $logger,
        private readonly UserStore $users,
    ) {
    }

    public function save(string $uid, PendingMfa $pending): void
    {
        $this->secrets->savePendingMfa($uid, $pending);
        $this->jobs->scheduleAfter(MfaCleanupJob::class, $pending->expiresAt(), ['uid' => $uid]);
        $this->countRequest($uid, $pending);

        $method = $pending->challenge->method;
        if ($method !== null && !in_array($method, self::KNOWN_METHODS, true)) {
            // WHY: A new value from Garmin (e.g. an authenticator app) should show up in the log. Only the name, never cookies or codes.
            $this->logger->info('Garmin reported an unknown MFA method', ['uid' => $uid, 'method' => $method]);
        }
    }

    /**
     * The setup login and the sync's attempt send a code too, so the next request waits as long as after
     * one the person asked for. WHY skip a time already counted: MfaService::request counts its sign-in
     * before asking Garmin, also when it renews a setup request.
     */
    private function countRequest(string $uid, PendingMfa $pending): void
    {
        if ($pending->origin === MfaOrigin::Relogin) {
            return;
        }
        $requests = MfaRequestLimit::recent($this->users->mfaRequests($uid), $pending->createdAt);
        if (!in_array($pending->createdAt, $requests, true)) {
            $this->users->saveMfaRequests($uid, [...$requests, $pending->createdAt]);
        }
    }
}
