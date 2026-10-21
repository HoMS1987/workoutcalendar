<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\CalDav;

/** A calendar owned by a person. */
final class CalendarInfo
{
    public function __construct(
        public readonly int $id,
        public readonly string $uri,
        public readonly string $name,
        public readonly ?string $color,
    ) {
    }
}
