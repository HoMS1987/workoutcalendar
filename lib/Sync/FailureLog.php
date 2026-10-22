<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Sync;

use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\GarminException;
use Psr\Log\LoggerInterface;

/**
 * Logs sync errors throttled (ADR 0004, F-43): verbose only for a new or changed error,
 * at most one reminder per day while it persists, and an info entry once it is fixed.
 */
final class FailureLog
{
    private const REMINDER_INTERVAL_SECONDS = 86_400;

    /** These errors need intervention (credentials, MFA, app adjustment). */
    private const ERROR_CATEGORIES = [FailureCategory::InvalidCredentials, FailureCategory::MfaRequired, FailureCategory::ProtocolChanged];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /** Updates the failure fields of the state and logs, throttled. */
    public function recordFailure(SyncState $state, GarminException $e, int $now): void
    {
        // Same category and step count as the same failure, even if the message differs.
        $same = $state->failureCategory === $e->category->value && $state->failureStep === $e->step;
        $state->failureMessage = $e->getMessage();

        if (!$same) {
            $state->failureCategory = $e->category->value;
            $state->failureStep = $e->step;
            $state->failureSince = $now;
            $state->failureCount = 1;
            $state->failureReportedAt = $now;
            $level = in_array($e->category, self::ERROR_CATEGORIES, true) ? 'error' : 'warning';
            $this->logger->log($level, 'Garmin sync failed', $e->logContext());

            return;
        }

        $state->failureCount++;
        $context = [
            'category' => $state->failureCategory,
            'step' => $state->failureStep,
            'failedRuns' => $state->failureCount,
            'since' => $state->failureSince,
        ];
        if ($now - (int)$state->failureReportedAt >= self::REMINDER_INTERVAL_SECONDS) {
            $state->failureReportedAt = $now;
            $this->logger->warning('Garmin sync failure persists', $context);
        } else {
            $this->logger->debug('Garmin sync failure unchanged', $context);
        }
    }

    /** Clears the failure fields of the state; logs once if a failure is over. */
    public function recordSuccess(SyncState $state, int $now): void
    {
        if ($state->failureCount > 0) {
            $this->logger->info('Garmin sync succeeded again', [
                'category' => $state->failureCategory,
                'step' => $state->failureStep,
                'failedRuns' => $state->failureCount,
                'since' => $state->failureSince,
                'durationSeconds' => $now - (int)$state->failureSince,
            ]);
        }
        $state->failureCategory = null;
        $state->failureStep = null;
        $state->failureMessage = null;
        $state->failureSince = null;
        $state->failureCount = 0;
        $state->failureReportedAt = null;
    }
}
