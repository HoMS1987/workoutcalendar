<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Storage;

use OCA\WorkoutCalendar\Garmin\Auth\Credentials;
use OCA\WorkoutCalendar\Garmin\Auth\MfaChallenge;

/** A requested MFA code that is waiting to be entered. The code itself is never stored. */
final class PendingMfa
{
    public const LIFETIME_SECONDS = 1800;

    public function __construct(
        public readonly MfaChallenge $challenge,
        public readonly MfaOrigin $origin,
        public readonly int $createdAt,
        public readonly ?Credentials $credentials = null,
    ) {
    }

    public function expiresAt(): int
    {
        return $this->createdAt + self::LIFETIME_SECONDS;
    }

    public function isExpired(int $now): bool
    {
        return $now >= $this->expiresAt();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'challenge' => $this->challenge->toArray(),
            'origin' => $this->origin->value,
            'createdAt' => $this->createdAt,
            'credentials' => $this->credentials === null
                ? null
                : ['email' => $this->credentials->email, 'password' => $this->credentials->password],
        ];
    }

    /**
     * @param array<mixed> $data
     * @throws \InvalidArgumentException if the data does not have the expected shape
     */
    public static function fromArray(array $data): self
    {
        $challenge = $data['challenge'] ?? null;
        if (!is_array($challenge)) {
            throw new \InvalidArgumentException('Pending MFA challenge is missing');
        }
        $origin = is_string($data['origin'] ?? null) ? MfaOrigin::tryFrom($data['origin']) : null;
        if ($origin === null) {
            throw new \InvalidArgumentException('Pending MFA origin is invalid');
        }
        if (!is_int($data['createdAt'] ?? null)) {
            throw new \InvalidArgumentException('Pending MFA creation time is invalid');
        }
        $credentials = $data['credentials'] ?? null;
        if ($credentials !== null) {
            if (!is_array($credentials) || !is_string($credentials['email'] ?? null) || !is_string($credentials['password'] ?? null)) {
                throw new \InvalidArgumentException('Pending MFA credentials are invalid');
            }
            $credentials = new Credentials($credentials['email'], $credentials['password']);
        }

        return new self(MfaChallenge::fromArray($challenge), $origin, $data['createdAt'], $credentials);
    }
}
