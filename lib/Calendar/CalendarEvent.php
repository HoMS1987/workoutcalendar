<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Calendar;

/** Calendar event for one Garmin activity, times in UTC. */
final class CalendarEvent
{
    /** @param list<string> $categories */
    public function __construct(
        public readonly string $uid,
        public readonly string $summary,
        public readonly string $description,
        public readonly \DateTimeImmutable $startUtc,
        public readonly \DateTimeImmutable $endUtc,
        public readonly string $url,
        public readonly string $activityId,
        public readonly array $categories,
    ) {
    }

    /**
     * Stable hash over all content fields (SHA-256, base64url without padding, 43 characters).
     * The sync uses it to detect whether a stored event has to be rewritten.
     * WHY base64url instead of hex: this keeps the X-GARMIN-FINGERPRINT line under 75 octets.
     */
    public function fingerprint(): string
    {
        $data = json_encode([
            $this->uid,
            $this->summary,
            $this->description,
            IcsSerializer::utc($this->startUtc),
            IcsSerializer::utc($this->endUtc),
            $this->url,
            $this->activityId,
            $this->categories,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode(hash('sha256', $data, true)), '+/', '-_'), '=');
    }
}
