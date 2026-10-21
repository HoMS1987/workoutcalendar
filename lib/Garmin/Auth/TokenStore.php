<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Auth;

/** Storage for a user's Garmin tokens. The Nextcloud implementation stores them encrypted (ADR 0003). */
interface TokenStore
{
    public function load(): ?GarminTokens;

    public function save(GarminTokens $tokens): void;
}
