<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\CalDav;

/**
 * Kalender und Kalenderobjekte einer Nextcloud-Instanz (ADR 0005).
 * Die einzige Implementierung mit Nextcloud-Bezug ist `DavCalendarObjects`.
 */
interface CalendarObjects
{
    /** @return list<CalendarInfo> Eigene Kalender (keine geteilten), nicht im Papierkorb, mit VEVENT */
    public function ownCalendars(string $uid): array;

    /** @return list<string> URIs aller eigenen Kalender, auch der im Papierkorb. Diese URIs sind belegt. */
    public function usedCalendarUris(string $uid): array;

    /** @return int ID des neuen Kalenders, der nur VEVENT unterstützt */
    public function createCalendar(string $uid, string $uri, string $name): int;

    /** Verschiebt den Kalender in den Papierkorb. */
    public function deleteCalendar(int $calendarId): void;

    /** @return list<string> URIs aller Objekte des Kalenders */
    public function uris(int $calendarId): array;

    /** @return list<string> URIs der VEVENTs, die sich mit dem Zeitraum überschneiden (Beginn < to, Ende > from) */
    public function urisInRange(int $calendarId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /**
     * @param list<string> $uris
     * @return array<string, string> URI → ICS, fehlende URIs fehlen auch im Ergebnis
     */
    public function get(int $calendarId, array $uris): array;

    public function create(int $calendarId, string $uri, string $ics): void;

    public function update(int $calendarId, string $uri, string $ics): void;

    /**
     * Verschiebt das Objekt in den Papierkorb, fehlende Objekte werden ignoriert. Endgültig gelöscht
     * wird, wenn der Papierkorb abgeschaltet ist (Aufbewahrung 0) oder schon eine Fassung desselben
     * Objekts enthält.
     */
    public function delete(int $calendarId, string $uri): void;
}
