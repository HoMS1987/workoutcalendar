<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

use OCA\WorkoutCalendar\CalDav\CalendarInfo;

/** Result of {@see CalendarSetupService::choose()}. */
final class CalendarChoice
{
    public function __construct(
        public readonly bool $needsConfirmation,
        public readonly ?CalendarInfo $calendar,
        public readonly int $existingEvents = 0,
    ) {
    }
}
