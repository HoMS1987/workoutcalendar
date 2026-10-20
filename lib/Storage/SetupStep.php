<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Storage;

/** Schritte der Einrichtung einer Person. */
enum SetupStep: string
{
    case Garmin = 'garmin';
    case Calendar = 'calendar';
    case History = 'history';
    case Done = 'done';
}
