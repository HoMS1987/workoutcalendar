<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Sync;

use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\GarminException;
use Psr\Log\LoggerInterface;

/**
 * Loggt Sync-Fehler gedrosselt (ADR 0004, F-43): ausführlich nur bei neuem oder geändertem Fehler,
 * solange er anhält höchstens eine Erinnerung pro Tag, nach Behebung ein Info-Eintrag.
 */
final class FailureLog
{
    private const REMINDER_INTERVAL_SECONDS = 86_400;

    /** Diese Fehler brauchen ein Eingreifen (Zugangsdaten, MFA, App-Anpassung). */
    private const ERROR_CATEGORIES = [FailureCategory::InvalidCredentials, FailureCategory::MfaRequired, FailureCategory::ProtocolChanged];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function recordFailure(SyncState $state, GarminException $e, int $now): void
    {
        $same = $state->failureCategory === $e->category->value && $state->failureStep === $e->step;
        $state->failureMessage = $e->getMessage();

        if (!$same) {
            $state->failureCategory = $e->category->value;
            $state->failureStep = $e->step;
            $state->failureSince = $now;
            $state->failureCount = 1;
            $state->failureReportedAt = $now;
            $level = in_array($e->category, self::ERROR_CATEGORIES, true) ? 'error' : 'warning';
            $this->logger->log($level, 'Garmin-Sync fehlgeschlagen', $e->logContext());

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
            $this->logger->warning('Garmin-Sync-Fehler hält an', $context);
        } else {
            $this->logger->debug('Garmin-Sync-Fehler unverändert', $context);
        }
    }

    public function recordSuccess(SyncState $state, int $now): void
    {
        if ($state->failureCount > 0) {
            $this->logger->info('Garmin-Sync wieder erfolgreich', [
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
