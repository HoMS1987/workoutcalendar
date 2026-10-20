<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Sync;

use OCA\WorkoutCalendar\Calendar\ActivityEventMapper;
use OCA\WorkoutCalendar\Calendar\CalendarEvent;
use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\GarminClient;
use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Garmin\Http\NetworkException;
use Psr\Log\LoggerInterface;

/**
 * Ein Sync-Lauf für eine Person:
 * 1. Aktuelles Fenster (seit letztem Erfolg, mindestens der Prüfzeitraum für Löschungen) vollständig
 *    abrufen und Termine anlegen bzw. aktualisieren.
 * 2. Im Prüfzeitraum Termine behandeln, deren Aktivität es in Garmin nicht mehr gibt (F-13).
 * 3. Historien-Import in Etappen fortsetzen (F-20 bis F-22).
 */
final class SyncService
{
    /** Schutz gegen eine Paginierung, die nie endet. */
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
        } catch (NetworkException $e) {
            $this->fail($state, $result, new GarminException(FailureCategory::Network, 'api.activities', $e->getMessage(), previous: $e), $now);
        }

        $level = $result->hasChanges() ? 'info' : 'debug';
        $this->logger->log($level, 'Garmin-Sync-Lauf', $result->toArray());

        return $result;
    }

    private function initialize(SyncState $state, SyncSettings $settings, \DateTimeImmutable $now, \DateTimeImmutable $checkFrom): void
    {
        $state->initialized = true;
        // Der Historien-Import beginnt dort, wo das aktuelle Fenster endet.
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
        // WHY: Garmin filtert nach lokalem Datum, ein Tag Vorlauf deckt jede Zeitzone ab.
        $startDate = $from->modify('-1 day')->format('Y-m-d');

        $activities = [];
        for ($page = 0; ; $page++) {
            if ($page >= self::MAX_RECENT_PAGES) {
                throw new GarminException(FailureCategory::ProtocolChanged, 'api.activities', 'Paginierung endet nicht');
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
     * @param array<string, true> $seen Activity-IDs, die Garmin im Fenster geliefert hat
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
            // Die nächste Seite endet am ältesten Datum dieser Seite (inklusive). Liegt die ganze Seite
            // auf einem Tag, geht es einen Tag zurück, damit der Import sicher vorankommt.
            $oldest = min(array_map(self::localDate(...), $page));
            if ($oldest === null || $oldest >= (string)$state->backfillBefore) {
                $oldest = (new \DateTimeImmutable((string)$state->backfillBefore))->modify('-1 day')->format('Y-m-d');
                $this->logger->debug('Historien-Import: Seite liegt auf einem Tag, gehe einen Tag zurück', ['date' => $oldest]);
            }
            $state->backfillBefore = $oldest;
            if ($state->backfillUntil !== null && $state->backfillBefore < $state->backfillUntil) {
                $state->backfillDone = true;
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $activities
     * @return array<string, true> Activity-IDs der abgebildeten Aktivitäten
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
                $this->logger->debug('Garmin-Aktivität übersprungen', ['reason' => $e->getMessage()]);
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
            // Kein weiterer Login-Versuch, bis neue Zugangsdaten vorliegen (SyncRunner prüft das).
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
