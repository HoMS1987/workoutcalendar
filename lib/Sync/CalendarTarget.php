<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Sync;

use OCA\WorkoutCalendar\Calendar\CalendarEvent;

/** Zielkalender einer Person. Die Nextcloud-Implementierung nutzt die interne Kalender-API (ADR 0003). */
interface CalendarTarget
{
    /**
     * @param list<string> $activityIds
     * @return array<string, StoredEvent> Vorhandene Termine, Schlüssel ist die Activity-ID
     */
    public function find(array $activityIds): array;

    /** @return array<string, StoredEvent> Termine der App mit Beginn in [from, to] */
    public function inRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array;

    public function create(CalendarEvent $event): void;

    /** Überschreibt den Termin vollständig, eine Löschmarkierung entfällt dabei. */
    public function update(CalendarEvent $event): void;

    public function markDeleted(string $activityId): void;

    public function delete(string $activityId): void;
}
