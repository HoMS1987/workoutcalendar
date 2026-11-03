<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Storage;

/** A person's personal Garmin API key and the time it was entered. Stored encrypted by the SecretStore. */
final class ApiKeyRecord
{
    public function __construct(
        public readonly string $key,
        public readonly int $enteredAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['key' => $this->key, 'enteredAt' => $this->enteredAt];
    }

    /**
     * @param array<mixed> $data
     * @throws \InvalidArgumentException if the data does not have the expected shape
     */
    public static function fromArray(array $data): self
    {
        if (!is_string($data['key'] ?? null) || $data['key'] === '') {
            throw new \InvalidArgumentException('API key is missing');
        }
        if (!is_int($data['enteredAt'] ?? null)) {
            throw new \InvalidArgumentException('API key entry time is invalid');
        }

        return new self($data['key'], $data['enteredAt']);
    }
}
