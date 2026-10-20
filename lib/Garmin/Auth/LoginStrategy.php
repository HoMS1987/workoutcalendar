<?php

declare(strict_types=1);

namespace OCA\GarminCalendar\Garmin\Auth;

use OCA\GarminCalendar\Garmin\GarminException;
use OCA\GarminCalendar\Garmin\Http\NetworkException;

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
