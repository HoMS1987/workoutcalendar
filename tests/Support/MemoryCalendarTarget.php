<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Support;

use OCA\WorkoutCalendar\Calendar\CalendarEvent;
use OCA\WorkoutCalendar\Sync\CalendarTarget;
use OCA\WorkoutCalendar\Sync\StoredEvent;

final class MemoryCalendarTarget implements CalendarTarget
{
    /** @var array<string, CalendarEvent> */
    public array $events = [];
    /** @var array<string, true> */
    public array $marked = [];
    /** @var list<string> Ausgeführte Schreibvorgänge, z. B. "create:1" */
    public array $writes = [];

    public function find(array $activityIds): array
    {
        return array_intersect_key($this->stored(), array_flip($activityIds));
    }

    public function inRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return array_filter(
            $this->stored(),
            fn (StoredEvent $s): bool => $this->events[$s->activityId]->startUtc >= $from && $this->events[$s->activityId]->startUtc <= $to,
        );
    }

    public function create(CalendarEvent $event): void
    {
        $this->writes[] = "create:{$event->activityId}";
        $this->events[$event->activityId] = $event;
    }

    public function update(CalendarEvent $event): void
    {
        $this->writes[] = "update:{$event->activityId}";
        $this->events[$event->activityId] = $event;
        unset($this->marked[$event->activityId]);
    }

    public function markDeleted(string $activityId): void
    {
        $this->writes[] = "mark:$activityId";
        $this->marked[$activityId] = true;
    }

    public function delete(string $activityId): void
    {
        $this->writes[] = "delete:$activityId";
        unset($this->events[$activityId], $this->marked[$activityId]);
    }

    /** @return array<string, StoredEvent> */
    private function stored(): array
    {
        $stored = [];
        foreach ($this->events as $id => $event) {
            $stored[(string)$id] = new StoredEvent((string)$id, $event->fingerprint(), isset($this->marked[$id]));
        }

        return $stored;
    }
}
