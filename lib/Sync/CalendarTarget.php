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

    /** @return array<string, StoredEvent> The app's events starting in [from, to] */
    public function inRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array;

    public function create(CalendarEvent $event): void;

    /** Overwrites the event completely; a deletion marker is dropped in the process. */
    public function update(CalendarEvent $event): void;

    public function markDeleted(string $activityId): void;

    public function delete(string $activityId): void;
}
