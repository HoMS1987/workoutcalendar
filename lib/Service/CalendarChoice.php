<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

use OCA\WorkoutCalendar\CalDav\CalendarInfo;

/** Ergebnis von {@see CalendarSetupService::choose()}. */
final class CalendarChoice
{
    public function __construct(
        public readonly bool $needsConfirmation,
        public readonly ?CalendarInfo $calendar,
        public readonly int $existingEvents = 0,
    ) {
    }
}
