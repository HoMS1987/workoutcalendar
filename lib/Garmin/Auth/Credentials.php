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

    /** Prevents the password from ending up in var_dump/print_r output. */
    public function __debugInfo(): array
    {
        return ['email' => '***', 'password' => '***'];
    }
}
