<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Sync;

use OCA\WorkoutCalendar\Calendar\ActivityEventMapper;
use OCA\WorkoutCalendar\Calendar\CalendarEvent;
use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\GarminClient;
use OCA\WorkoutCalendar\Garmin\GarminException;
use Psr\Log\LoggerInterface;

/**
 * A sync run for a person:
 * 1. Fetch the current window (since the last success, at least the check period for deletions) completely
 *    and create or update events.
 * 2. Within the check period, handle events whose activity no longer exists in Garmin (F-13).
 * 3. Continue the history import in stages (F-20 to F-22).
 */
final class SyncService
{
    /** Safeguard against pagination that never ends. */
    private const MAX_RECENT_PAGES = 50;

    private readonly FailureLog $failureLog;

    public function __construct(
        private readonly GarminClient $garmin,
        private readonly ActivityEventMapper $mapper,
        private readonly CalendarTarget $calendar,
        private readonly LoggerInterface $logger,
    ) {
        $this->failureLog = new FailureLog($logger);
    }

    public function run(SyncSettings $settings, SyncState $state, \DateTimeImmutable $now): SyncResult
    {
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $result = new SyncResult();
        $state->lastRunAt = $now->getTimestamp();
        $state->nextRunAt = $settings->nextRunAt($now->getTimestamp());
        $checkFrom = $now->modify("-{$settings->deletionCheckDays} days");

        if (!$state->initialized) {
            $this->initialize($state, $settings, $now, $checkFrom);
        }

        try {
            $this->syncRecent($settings, $state, $now, $checkFrom, $result);
            $state->lastSuccessAt = $now->getTimestamp();
            $this->continueBackfill($state, $result);
            if (!$state->backfillDone) {
                $state->nextRunAt = $now->getTimestamp() + SyncSettings::BACKFILL_NEXT_RUN_SECONDS;
            }
            $this->failureLog->recordSuccess($state, $now->getTimestamp());
        } catch (GarminException $e) {
            $this->fail($state, $result, $e, $now);
        }

        $level = $result->hasChanges() ? 'info' : 'debug';
        $this->logger->log($level, 'Garmin sync run', $result->toArray());

        return $result;
    }

    /**
     * Changes the history period afterwards. A longer period resumes the import;
     * an equal or shorter one changes nothing and deletes no events. `null` means the complete history.
     * Static because no Garmin access is needed for it (settings page after the setup).
     */
    public static function changeHistory(SyncState $state, ?int $historyDays, \DateTimeImmutable $now): void
    {
        if (!$state->initialized) {
            return;
        }

        $newUntil = $historyDays === null
            ? null
            : $now->setTimezone(new \DateTimeZone('UTC'))->modify("-{$historyDays} days")->format('Y-m-d');
        $isLonger = $state->backfillUntil !== null && ($newUntil === null || $newUntil < $state->backfillUntil);
        if (!$isLonger) {
            return;
        }

        $state->backfillUntil = $newUntil;
        $state->backfillDone = $newUntil !== null && $state->backfillBefore !== null && $newUntil >= $state->backfillBefore;
        if (!$state->backfillDone) {
            $state->nextRunAt = $now->getTimestamp() + SyncSettings::BACKFILL_NEXT_RUN_SECONDS;
        }
    }

    private function initialize(SyncState $state, SyncSettings $settings, \DateTimeImmutable $now, \DateTimeImmutable $checkFrom): void
    {
        $state->initialized = true;
        // The history import starts where the current window ends.
        $state->backfillBefore = $checkFrom->format('Y-m-d');
        $state->backfillUntil = $settings->historyDays === null ? null : $now->modify("-{$settings->historyDays} days")->format('Y-m-d');
        $state->backfillDone = $state->backfillUntil !== null && $state->backfillUntil >= $state->backfillBefore;
    }

    private function syncRecent(SyncSettings $settings, SyncState $state, \DateTimeImmutable $now, \DateTimeImmutable $checkFrom, SyncResult $result): void
    {
        $from = $checkFrom;
        if ($state->lastSuccessAt !== null && $state->lastSuccessAt < $from->getTimestamp()) {
            $from = $now->setTimestamp($state->lastSuccessAt);
        }
        // WHY: Garmin filters by the local date of an activity; starting one day earlier covers
        // every time zone.
        $startDate = $from->modify('-1 day')->format('Y-m-d');

        $activities = [];
        for ($page = 0; ; $page++) {
            if ($page >= self::MAX_RECENT_PAGES) {
                throw new GarminException(FailureCategory::ProtocolChanged, 'api.activities', 'Pagination does not end');
            }
            $batch = $this->garmin->activities($page * SyncSettings::PAGE_SIZE, SyncSettings::PAGE_SIZE, $startDate);
            array_push($activities, ...$batch);
            if (count($batch) < SyncSettings::PAGE_SIZE) {
                break;
            }
        }

        $seen = $this->upsert($activities, $state, $result);
        if ($settings->deletionMode !== DeletionMode::Keep) {
            $this->handleDeleted($settings->deletionMode, $this->calendar->inRange($checkFrom, $now), $seen, $result);
        }
    }

    /**
     * @param array<string, StoredEvent> $stored
     * @param array<string, true> $seen Activity IDs that Garmin returned in the window
     */
    private function handleDeleted(DeletionMode $mode, array $stored, array $seen, SyncResult $result): void
    {
        foreach ($stored as $activityId => $event) {
            if (isset($seen[(string)$activityId])) {
                continue;
            }
            if ($mode === DeletionMode::Delete) {
                $this->calendar->delete((string)$activityId);
                $result->deleted++;
            } elseif (!$event->markedDeleted) {
                $this->calendar->markDeleted((string)$activityId);
                $result->markedDeleted++;
            }
        }
    }

    private function continueBackfill(SyncState $state, SyncResult $result): void
    {
        while (!$state->backfillDone && $result->backfillPages < SyncSettings::BACKFILL_PAGES_PER_RUN) {
            $page = $this->garmin->activities(0, SyncSettings::PAGE_SIZE, $state->backfillUntil, $state->backfillBefore);
            $result->backfillPages++;
            $this->upsert($page, $state, $result);

            if (count($page) < SyncSettings::PAGE_SIZE) {
                $state->backfillDone = true;
                break;
            }
            // The next page ends at the oldest date of this page (inclusive). If the whole page
            // falls on one day, step back one day so the import is guaranteed to make progress.
            $oldest = min(array_map(self::localDate(...), $page));
            if ($oldest === null || $oldest >= (string)$state->backfillBefore) {
                $oldest = (new \DateTimeImmutable((string)$state->backfillBefore))->modify('-1 day')->format('Y-m-d');
                $this->logger->debug('History import: page falls on a single day, stepping back one day', ['date' => $oldest]);
            }
            $state->backfillBefore = $oldest;
            if ($state->backfillUntil !== null && $state->backfillBefore < $state->backfillUntil) {
                $state->backfillDone = true;
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $activities
     * @return array<string, true> Activity IDs of the mapped activities
     */
    private function upsert(array $activities, SyncState $state, SyncResult $result): array
    {
        /** @var array<string, CalendarEvent> $events */
        $events = [];
        foreach ($activities as $activity) {
            try {
                $event = $this->mapper->map($activity);
                $events[$event->activityId] = $event;
                $date = self::localDate($activity);
                if ($date !== null && ($state->oldestActivityDate === null || $date < $state->oldestActivityDate)) {
                    $state->oldestActivityDate = $date;
                }
            } catch (\InvalidArgumentException $e) {
                $result->skipped++;
                $this->logger->debug('Garmin activity skipped', ['reason' => $e->getMessage()]);
            }
        }

        $stored = $this->calendar->find(array_map('strval', array_keys($events)));
        foreach ($events as $activityId => $event) {
            $existing = $stored[(string)$activityId] ?? null;
            if ($existing === null) {
                $this->calendar->create($event);
                $result->created++;
            } elseif ($existing->markedDeleted || $existing->fingerprint !== $event->fingerprint()) {
                $this->calendar->update($event);
                $result->updated++;
            } else {
                $result->unchanged++;
            }
        }

        return array_fill_keys(array_map('strval', array_keys($events)), true);
    }

    private function fail(SyncState $state, SyncResult $result, GarminException $e, \DateTimeImmutable $now): void
    {
        $result->failure = $e->category;
        if ($e->category === FailureCategory::InvalidCredentials || $e->category === FailureCategory::MfaRequired) {
            // No further login attempt until new credentials are available (SyncRunner checks this).
            $state->pausedFor = $e->category->value;
        }
        $this->failureLog->recordFailure($state, $e, $now->getTimestamp());
    }

    /** @param array<string, mixed> $activity */
    private static function localDate(array $activity): ?string
    {
        $start = $activity['startTimeLocal'] ?? $activity['startTimeGMT'] ?? null;

        return is_string($start) && preg_match('/^\d{4}-\d{2}-\d{2}/', $start, $m) ? $m[0] : null;
    }
}
