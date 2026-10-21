<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

/** What happens to the app's events on sign-out or reset. */
enum Cleanup: string
{
    /** Events and calendar stay. */
    case None = 'none';
    /** The app's events are removed from the calendar. */
    case Events = 'events';
    /** The calendar created by the app is deleted. */
    case Calendar = 'calendar';
}
