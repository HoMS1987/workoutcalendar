<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Support;

/** Builds unsigned JWTs with an arbitrary payload for tests. */
final class Jwt
{
    public static function make(array $payload): string
    {
        $encode = static fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $encode(['alg' => 'none']) . '.' . $encode($payload) . '.sig';
    }
}
