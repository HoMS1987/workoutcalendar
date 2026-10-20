<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Auth;

use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Garmin\Http\NetworkException;

interface LoginStrategy
{
    /** Kurzname für das Log, z. B. "mobile" */
    public function name(): string;

    /**
     * @throws GarminException
     * @throws NetworkException
     */
    public function login(Credentials $credentials): GarminTokens;
}
