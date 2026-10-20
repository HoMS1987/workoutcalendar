<?php

declare(strict_types=1);

namespace OCA\GarminCalendar\Tests\Support;

/** Baut unsignierte JWTs mit beliebigem Payload für Tests. */
final class Jwt
{
    public static function make(array $payload): string
    {
        $encode = static fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $encode(['alg' => 'none']) . '.' . $encode($payload) . '.sig';
    }
}
