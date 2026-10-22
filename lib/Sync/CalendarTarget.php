<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Sync;

use OCA\WorkoutCalendar\Calendar\CalendarEvent;

/** A person's target calendar. The Nextcloud implementation uses the internal calendar API (ADR 0003). */
interface CalendarTarget
{
    /**
     * @param list<string> $activityIds
     * @return array<string, StoredEvent> Existing events, keyed by activity ID
     */
    public function find(array $activityIds): array;

    /** @return array<string, StoredEvent> This account's app events starting in [from, to] */
    public function inRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array;

    public function create(CalendarEvent $event): void;

    /** Overwrites the event completely; a deletion marker is dropped in the process. */
    public function update(CalendarEvent $event): void;

    /** Marks the event as deleted in Garmin; a missing or foreign object is left alone. */
    public function markDeleted(string $activityId): void;

    /** A missing or foreign object is left alone. */
    public function delete(string $activityId): void;
}
