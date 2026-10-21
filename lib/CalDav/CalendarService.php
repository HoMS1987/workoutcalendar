<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\CalDav;

/**
 * Kalenderverwaltung für Einstellungen und Hintergrundjobs: Kalender suchen, anlegen und löschen,
 * Termine der App zählen, verschieben und entfernen. Fremde Termine bleiben unangetastet.
 */
final class CalendarService
{
    private const URI = 'workoutcalendar';

    public function __construct(
        private readonly CalendarObjects $objects,
    ) {
    }

    /** @return list<CalendarInfo> */
    public function ownCalendars(string $uid): array
    {
        return $this->objects->ownCalendars($uid);
    }

    /** Sucht einen eigenen Kalender nach Namen, ohne Leerzeichen am Rand und ohne Groß- und Kleinschreibung. */
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

    /** Legt einen Kalender unter `workoutcalendar` an, bei Belegung unter `workoutcalendar-2`, `-3` usw. */
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
     * Zählt nur nach der URI-Form `garmin-<activityId>.ics`, ohne ICS zu lesen. Für die Anzeige;
     * ändernde Vorgänge prüfen zusätzlich die UID.
     */
    public function countAppEventsByUri(int $calendarId): int
    {
        return count(array_filter(
            $this->objects->uris($calendarId),
            static fn (string $uri): bool => AppObjects::activityId($uri) !== null,
        ));
    }

    /**
     * Verschiebt höchstens `limit` Termine der App. Gibt es die URI im Ziel schon, wird nur die
     * Quelle gelöscht.
     *
     * @return int Zahl der Termine der App, die noch in der Quelle liegen
     */
    public function moveAppEvents(int $from, int $to, int $limit): int
    {
        $pending = $this->appObjects($from);
        $existing = array_flip($this->objects->uris($to));
        foreach (array_slice($pending, 0, max(0, $limit), true) as $uri => $ics) {
            if (!isset($existing[$uri])) {
                $this->objects->create($to, $uri, $ics);
            }
            $this->objects->delete($from, $uri);
            unset($pending[$uri]);
        }

        return count($pending);
    }

    /** @return int Zahl der Termine der App, die noch im Kalender liegen */
    public function removeAppEvents(int $calendarId, int $limit): int
    {
        $pending = $this->appObjects($calendarId);
        foreach (array_slice(array_keys($pending), 0, max(0, $limit)) as $uri) {
            $this->objects->delete($calendarId, $uri);
            unset($pending[$uri]);
        }

        return count($pending);
    }

    /** @return array<string, string> URI → ICS der Termine der App, nach URI sortiert */
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
