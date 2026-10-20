<?php

declare(strict_types=1);

namespace OCA\GarminCalendar\Calendar;

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
     * Stabiler Hash über alle inhaltlichen Felder (SHA-256, base64url ohne Padding, 43 Zeichen).
     * Damit erkennt der Sync, ob ein gespeicherter Termin neu geschrieben werden muss.
     * WHY base64url statt hex: Die Zeile X-GARMIN-FINGERPRINT bleibt so unter 75 Oktetten.
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
