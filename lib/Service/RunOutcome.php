<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

/** Result of a sync run for a person. */
enum RunOutcome: string
{
    /** Garmin was queried and the state is saved (also on errors from Garmin). */
    case Done = 'done';
    /** Another run for the person holds the lock. */
    case Skipped = 'skipped';
    /** Paused after a rejected password or verification code until new credentials are available. */
    case Paused = 'paused';
    case NotConnected = 'notConnected';
    /** The target calendar is missing or is no longer the person's own calendar. */
    case CalendarMissing = 'calendarMissing';
    /** The setup is not finished yet; the person has not chosen the history period. */
    case NotReady = 'notReady';
}
