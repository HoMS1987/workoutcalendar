<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Auth;

final class Credentials
{
    public function __construct(
        public readonly string $email,
        #[\SensitiveParameter] public readonly string $password,
    ) {
    }

    /** Verhindert, dass das Passwort in var_dump/print_r-Ausgaben landet. */
    public function __debugInfo(): array
    {
        return ['email' => '***', 'password' => '***'];
    }
}
