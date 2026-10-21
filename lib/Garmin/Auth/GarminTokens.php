<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Auth;

/** Garmin DI OAuth2 tokens. Array format as in python-garminconnect. */
final class GarminTokens
{
    private const EXPIRY_LEEWAY_SECONDS = 60;

    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly string $clientId,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $access = $data['di_token'] ?? null;
        $client = $data['di_client_id'] ?? null;
        if (!is_string($access) || $access === '' || !is_string($client) || $client === '') {
            throw new \InvalidArgumentException('Token-Daten unvollständig');
        }
        $refresh = $data['di_refresh_token'] ?? null;

        return new self($access, is_string($refresh) && $refresh !== '' ? $refresh : null, $client);
    }

    /** @return array{di_token: string, di_refresh_token: ?string, di_client_id: string} */
    public function toArray(): array
    {
        return ['di_token' => $this->accessToken, 'di_refresh_token' => $this->refreshToken, 'di_client_id' => $this->clientId];
    }

    public function expiresAt(): ?int
    {
        $exp = self::jwtPayload($this->accessToken)['exp'] ?? null;

        return is_int($exp) ? $exp : null;
    }

    public function isExpired(int $now): bool
    {
        $expiresAt = $this->expiresAt();

        return $expiresAt !== null && $now >= $expiresAt - self::EXPIRY_LEEWAY_SECONDS;
    }

    public static function clientIdFromJwt(string $jwt): ?string
    {
        $clientId = self::jwtPayload($jwt)['client_id'] ?? null;

        return is_string($clientId) ? $clientId : null;
    }

    /** @return array<string, mixed> */
    private static function jwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return [];
        }
        $payload = json_decode((string)base64_decode(strtr($parts[1], '-_', '+/')), true);

        return is_array($payload) ? $payload : [];
    }
}
