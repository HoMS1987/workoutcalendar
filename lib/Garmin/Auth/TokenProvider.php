<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Auth;

use OCA\WorkoutCalendar\Garmin\GarminException;

/** Supplies the access token for the Garmin API, one implementation per sign-in method. */
interface TokenProvider
{
    /**
     * Tokens that are valid for the next request; obtains and stores new ones when needed.
     *
     * @throws GarminException
     */
    public function current(): GarminTokens;

    /**
     * New tokens after Garmin rejected `$rejected`; stores them.
     *
     * @throws GarminException
     */
    public function renew(GarminTokens $rejected): GarminTokens;
}
