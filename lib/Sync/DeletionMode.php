<?php

declare(strict_types=1);

namespace OCA\GarminCalendar\Sync;

/** Umgang mit Terminen, deren Aktivität in Garmin gelöscht wurde (F-13). */
enum DeletionMode: string
{
    case Keep = 'keep';
    case Mark = 'mark';
    case Delete = 'delete';
}
