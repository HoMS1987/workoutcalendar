<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Storage;

/** Steps of a person's setup. */
enum SetupStep: string
{
    case Garmin = 'garmin';
    case Calendar = 'calendar';
    case History = 'history';
    case Done = 'done';
}
