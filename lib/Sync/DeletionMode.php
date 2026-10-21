<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Sync;

/** Handling of events whose activity was deleted in Garmin (F-13). */
enum DeletionMode: string
{
    case Keep = 'keep';
    case Mark = 'mark';
    case Delete = 'delete';
}
