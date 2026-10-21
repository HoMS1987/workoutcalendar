<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

/** A sync run or another change for the person currently holds the lock. */
final class PersonBusyException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('A sync run or another change is currently running for the person');
    }
}
