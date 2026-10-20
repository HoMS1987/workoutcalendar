<?php

declare(strict_types=1);

namespace OCA\GarminCalendar\Calendar;

final class CalendarEvent
{
    /** @param list<string> $categories */
    public function __construct(
        public readonly string $uid,
        public readonly string $summary,
        public readonly string $description,
        public readonly \DateTimeImmutable $startUtc,
        public readonly \DateTimeImmutable $endUtc,
        public readonly string $url,
        public readonly string $activityId,
        public readonly array $categories,
    ) {
    }
}
