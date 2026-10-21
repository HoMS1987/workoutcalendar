<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\CalDav;

/**
 * Calendars and calendar objects of a Nextcloud instance (ADR 0005).
 * The only implementation tied to Nextcloud is `DavCalendarObjects`.
 */
interface CalendarObjects
{
    /** @return list<CalendarInfo> Own calendars (not shared ones), not in the trash, with VEVENT */
    public function ownCalendars(string $uid): array;

    /** @return list<string> URIs of all own calendars, including those in the trash. These URIs are taken. */
    public function usedCalendarUris(string $uid): array;

    /** @return int ID of the new calendar, which supports VEVENT only */
    public function createCalendar(string $uid, string $uri, string $name): int;

    /** Moves the calendar to the trash. */
    public function deleteCalendar(int $calendarId): void;

    /** @return list<string> URIs of all objects in the calendar */
    public function uris(int $calendarId): array;

    /** @return list<string> URIs of the VEVENTs that overlap the time range (start < to, end > from) */
    public function urisInRange(int $calendarId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /**
     * @param list<string> $uris
     * @return array<string, string> URI → ICS, missing URIs are also missing from the result
     */
    public function get(int $calendarId, array $uris): array;

    public function create(int $calendarId, string $uri, string $ics): void;

    public function update(int $calendarId, string $uri, string $ics): void;

    /**
     * Moves the object to the trash, missing objects are ignored. It is deleted permanently
     * when the trash is disabled (retention 0) or already contains a version of the same
     * object.
     */
    public function delete(int $calendarId, string $uri): void;
}
