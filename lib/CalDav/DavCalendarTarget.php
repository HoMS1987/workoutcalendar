<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\CalDav;

use OCA\WorkoutCalendar\AppInfo\Application;
use OCA\WorkoutCalendar\Calendar\CalendarEvent;
use OCA\WorkoutCalendar\Calendar\DeletedMarker;
use OCA\WorkoutCalendar\Calendar\IcsLines;
use OCA\WorkoutCalendar\Calendar\IcsReader;
use OCA\WorkoutCalendar\Calendar\IcsSerializer;
use OCA\WorkoutCalendar\Sync\CalendarTarget;
use OCA\WorkoutCalendar\Sync\StoredEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * A person's target calendar via `CalendarObjects`. Events live under `garmin-<activityId>.ics`.
 * If a foreign object (different UID) is under this URI, it counts as taken and is never touched.
 */
final class DavCalendarTarget implements CalendarTarget
{
    /** @param \Closure(): \DateTimeImmutable $now */
    public function __construct(
        private readonly CalendarObjects $objects,
        private readonly int $calendarId,
        private readonly string $accountKey,
        private readonly IcsSerializer $serializer,
        private readonly IcsReader $reader,
        private readonly DeletedMarker $marker,
        private readonly \Closure $now,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function find(array $activityIds): array
    {
        $uris = [];
        foreach ($activityIds as $activityId) {
            $uris[AppObjects::uri($activityId)] = $activityId;
        }

        $stored = [];
        foreach ($this->objects->get($this->calendarId, array_keys($uris)) as $uri => $ics) {
            if (!AppObjects::isAppObject((string)$uri, $ics)) {
                continue;
            }
            $activityId = $uris[$uri];
            $stored[$activityId] = $this->stored($activityId, $ics);
        }

        return $stored;
    }

    /**
     * Only app events carrying this account's key. The backend returns all events that touch the
     * time range, so the start is checked as well.
     */
    public function inRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        // WHY +1 second: calendarQuery's time range excludes events that start exactly at `to`.
        $uris = array_values(array_filter(
            $this->objects->urisInRange($this->calendarId, $from, $to->modify('+1 second')),
            static fn (string $uri): bool => AppObjects::activityId($uri) !== null,
        ));

        $stored = [];
        foreach ($this->objects->get($this->calendarId, $uris) as $uri => $ics) {
            $start = self::start($ics);
            if (!AppObjects::isAppObject((string)$uri, $ics)
                || $this->reader->accountKey($ics) !== $this->accountKey
                || $start === null || $start < $from || $start > $to) {
                continue;
            }
            $activityId = (string)AppObjects::activityId((string)$uri);
            $stored[$activityId] = $this->stored($activityId, $ics);
        }

        return $stored;
    }

    public function create(CalendarEvent $event): void
    {
        $uri = AppObjects::uri($event->activityId);
        $current = $this->current($uri);
        if ($current !== null && !AppObjects::isAppObject($uri, $current)) {
            $this->skip('create', $event->activityId);

            return;
        }

        $this->objects->create($this->calendarId, $uri, $this->ics($event));
    }

    public function update(CalendarEvent $event): void
    {
        $uri = AppObjects::uri($event->activityId);
        if ($this->appObject($uri, 'update', $event->activityId) === null) {
            return;
        }

        $this->objects->update($this->calendarId, $uri, $this->ics($event));
    }

    public function markDeleted(string $activityId): void
    {
        $uri = AppObjects::uri($activityId);
        $ics = $this->appObject($uri, 'markDeleted', $activityId);
        if ($ics === null) {
            return;
        }

        $this->objects->update($this->calendarId, $uri, $this->marker->apply($ics, ($this->now)()));
    }

    public function delete(string $activityId): void
    {
        $uri = AppObjects::uri($activityId);
        if ($this->appObject($uri, 'delete', $activityId) === null) {
            return;
        }

        $this->objects->delete($this->calendarId, $uri);
    }

    private function current(string $uri): ?string
    {
        return $this->objects->get($this->calendarId, [$uri])[$uri] ?? null;
    }

    /** ICS of the app's object under the URI, otherwise null (missing or foreign object, the latter with a debug log). */
    private function appObject(string $uri, string $operation, string $activityId): ?string
    {
        $ics = $this->current($uri);
        if ($ics === null) {
            return null;
        }
        if (!AppObjects::isAppObject($uri, $ics)) {
            $this->skip($operation, $activityId);

            return null;
        }

        return $ics;
    }

    private function skip(string $operation, string $activityId): void
    {
        $this->logger->debug('Calendar object of activity {activityId} belongs to someone else, {operation} skipped', [
            'app' => Application::APP_ID,
            'activityId' => $activityId,
            'operation' => $operation,
        ]);
    }

    private function ics(CalendarEvent $event): string
    {
        return $this->serializer->serialize($event, ($this->now)(), $this->accountKey);
    }

    private function stored(string $activityId, string $ics): StoredEvent
    {
        return new StoredEvent($activityId, $this->reader->fingerprint($ics), $this->reader->isMarkedDeleted($ics));
    }

    /** Start of the event. The app writes UTC; `TZID` and all-day events are still read. */
    private static function start(string $ics): ?\DateTimeImmutable
    {
        foreach (IcsLines::unfold($ics) as $line) {
            if (IcsLines::name($line) !== 'DTSTART') {
                continue;
            }
            $value = IcsLines::value($line);
            $zone = new \DateTimeZone('UTC');
            if (preg_match('/;TZID=("?)([^;:"]+)\1/i', $line, $m) === 1) {
                try {
                    $zone = new \DateTimeZone($m[2]);
                } catch (\Exception) {
                    return null;
                }
            }
            foreach (['!Ymd\THis\Z', '!Ymd\THis', '!Ymd'] as $format) {
                $time = \DateTimeImmutable::createFromFormat($format, $value, $zone);
                if ($time !== false) {
                    return $time;
                }
            }

            return null;
        }

        return null;
    }
}
