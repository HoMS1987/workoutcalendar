<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\CalDav;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Calendar management for settings and background jobs: find, create and delete calendars,
 * count, move and remove the app's events. Foreign events are left untouched.
 */
final class CalendarService
{
    private const URI = 'workoutcalendar';

    public function __construct(
        private readonly CalendarObjects $objects,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /** @return list<CalendarInfo> */
    public function ownCalendars(string $uid): array
    {
        return $this->objects->ownCalendars($uid);
    }

    /** Finds an own calendar by name, ignoring surrounding whitespace and letter case. */
    public function findByName(string $uid, string $name): ?CalendarInfo
    {
        $needle = self::normalize($name);
        foreach ($this->objects->ownCalendars($uid) as $calendar) {
            if (self::normalize($calendar->name) === $needle) {
                return $calendar;
            }
        }

        return null;
    }

    /** Creates a calendar under `workoutcalendar`, or `workoutcalendar-2`, `-3` etc. if that is taken. */
    public function createCalendar(string $uid, string $name): CalendarInfo
    {
        $used = array_flip($this->objects->usedCalendarUris($uid));
        $uri = self::URI;
        for ($n = 2; isset($used[$uri]); $n++) {
            $uri = self::URI . "-$n";
        }

        return new CalendarInfo($this->objects->createCalendar($uid, $uri, $name), $uri, $name, null);
    }

    public function deleteCalendar(int $calendarId): void
    {
        $this->objects->deleteCalendar($calendarId);
    }

    public function countAll(int $calendarId): int
    {
        return count($this->objects->uris($calendarId));
    }

    public function countAppEvents(int $calendarId): int
    {
        return count($this->appObjects($calendarId));
    }

    /**
     * Counts by the URI form `garmin-<activityId>.ics` only, without reading ICS. For display;
     * operations that change data also check the UID.
     */
    public function countAppEventsByUri(int $calendarId): int
    {
        return count(array_filter(
            $this->objects->uris($calendarId),
            static fn (string $uri): bool => AppObjects::activityId($uri) !== null,
        ));
    }

    /**
     * Moves at most `limit` events of the app. If the target already holds an event of the
     * app under the URI, only the source is deleted. If a foreign object is there, the source stays and
     * no longer counts towards the remainder, so the maintenance job can finish.
     *
     * @return int Number of the app's events that can still be moved
     */
    public function moveAppEvents(int $from, int $to, int $limit): int
    {
        $pending = $this->appObjects($from);
        $existing = array_flip($this->objects->uris($to));
        $collisions = array_values(array_filter(array_keys($pending), static fn (string $uri): bool => isset($existing[$uri])));
        $appInTarget = [];
        foreach ($collisions === [] ? [] : $this->objects->get($to, $collisions) as $uri => $ics) {
            if (AppObjects::isAppObject((string)$uri, $ics)) {
                $appInTarget[(string)$uri] = true;
            }
        }
        foreach ($collisions as $uri) {
            if (!isset($appInTarget[$uri])) {
                $this->logger->debug('Event stays in the old calendar, the new one holds a foreign object under the URI', ['uri' => $uri]);
                unset($pending[$uri]);
            }
        }
        foreach (array_slice($pending, 0, max(0, $limit), true) as $uri => $ics) {
            if (!isset($existing[$uri])) {
                $this->objects->create($to, $uri, $ics);
            }
            $this->objects->delete($from, $uri);
            unset($pending[$uri]);
        }

        return count($pending);
    }

    /** @return int Number of the app's events still in the calendar */
    public function removeAppEvents(int $calendarId, int $limit): int
    {
        $pending = $this->appObjects($calendarId);
        foreach (array_slice(array_keys($pending), 0, max(0, $limit)) as $uri) {
            $this->objects->delete($calendarId, $uri);
            unset($pending[$uri]);
        }

        return count($pending);
    }

    /** @return array<string, string> URI → ICS of the app's events, sorted by URI */
    private function appObjects(int $calendarId): array
    {
        $uris = array_values(array_filter(
            $this->objects->uris($calendarId),
            static fn (string $uri): bool => AppObjects::activityId($uri) !== null,
        ));
        $result = [];
        foreach ($this->objects->get($calendarId, $uris) as $uri => $ics) {
            if (AppObjects::isAppObject((string)$uri, $ics)) {
                $result[(string)$uri] = $ics;
            }
        }
        ksort($result, SORT_NATURAL);

        return $result;
    }

    private static function normalize(string $name): string
    {
        return mb_strtolower(trim($name), 'UTF-8');
    }
}
