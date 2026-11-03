<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

use OCA\WorkoutCalendar\BackgroundJob\MfaCleanupJob;
use OCA\WorkoutCalendar\Storage\PendingMfa;
use OCA\WorkoutCalendar\Storage\SecretStore;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/** Stores a code request and schedules its removal once it expires. Every place that saves a PendingMfa uses this. */
final class PendingMfaSaver
{
    private const KNOWN_METHODS = ['email', 'sms'];

    public function __construct(
        private readonly SecretStore $secrets,
        private readonly IJobList $jobs,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function save(string $uid, PendingMfa $pending): void
    {
        $this->secrets->savePendingMfa($uid, $pending);
        $this->jobs->scheduleAfter(MfaCleanupJob::class, $pending->expiresAt(), ['uid' => $uid]);

        $method = $pending->challenge->method;
        if ($method !== null && !in_array($method, self::KNOWN_METHODS, true)) {
            // WHY: A new value from Garmin (e.g. an authenticator app) should show up in the log. Only the name, never cookies or codes.
            $this->logger->info('Garmin reported an unknown MFA method', ['uid' => $uid, 'method' => $method]);
        }
    }
}
