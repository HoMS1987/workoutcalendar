<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Storage;

use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Garmin\Auth\TokenStore;

/** Token-Ablage einer Person auf Basis des SecretStore. */
final class UserTokenStore implements TokenStore
{
    public function __construct(private readonly SecretStore $secrets, private readonly string $uid)
    {
    }

    public function load(): ?GarminTokens
    {
        return $this->secrets->tokens($this->uid);
    }

    public function save(GarminTokens $tokens): void
    {
        $this->secrets->saveTokens($this->uid, $tokens);
    }
}
