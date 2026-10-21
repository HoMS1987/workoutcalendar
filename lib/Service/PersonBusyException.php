<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

/** Ein Sync-Lauf oder eine andere Änderung für die Person hält gerade die Sperre. */
final class PersonBusyException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Für die Person läuft gerade ein Sync oder eine andere Änderung');
    }
}
