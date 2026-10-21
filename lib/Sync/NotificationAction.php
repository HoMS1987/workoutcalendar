<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Sync;

/** What the caller should do with the notification. */
enum NotificationAction
{
    case None;
    case Send;
    case Dismiss;
}
