<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Sync;

/** Was der Aufrufer mit der Benachrichtigung tun soll. */
enum NotificationAction
{
    case None;
    case Send;
    case Dismiss;
}
