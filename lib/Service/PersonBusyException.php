<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

/** A sync run or another change for the person currently holds the lock. */
final class PersonBusyException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Für die Person läuft gerade ein Sync oder eine andere Änderung');
    }
}
