<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Support;

use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Garmin\Auth\TokenStore;

final class MemoryTokenStore implements TokenStore
{
    public int $saves = 0;

    public function __construct(public ?GarminTokens $tokens = null)
    {
    }

    public function load(): ?GarminTokens
    {
        return $this->tokens;
    }

    public function save(GarminTokens $tokens): void
    {
        $this->tokens = $tokens;
        $this->saves++;
    }
}
