<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Sync;

/** Einstellungen einer Person mit Standardwerten und Grenzen aus docs/anforderungen.md. */
final class SyncSettings
{
    /** Garmin-Web-Oberfläche und python-garminconnect laden 20 Aktivitäten pro Seite. */
    public const PAGE_SIZE = 20;
    /** Seiten Historien-Import pro Lauf, damit ein großer Import den Nextcloud-Cron nicht blockiert (F-22). */
    public const BACKFILL_PAGES_PER_RUN = 5;

    /** Wartezeit bis zum nächsten Lauf, solange der Historien-Import noch nicht fertig ist. */
    public const BACKFILL_NEXT_RUN_SECONDS = 60;

    /**
     * @param ?int $historyDays null = komplette Historie
     */
    public function __construct(
        public readonly ?int $historyDays = 90,
        public readonly int $intervalMinutes = 30,
        public readonly int $jitterPercent = 20,
        public readonly DeletionMode $deletionMode = DeletionMode::Mark,
        public readonly int $deletionCheckDays = 30,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $defaults = new self();
        $int = static fn (string $key, int $min, int $max, ?int $default): ?int => is_int($data[$key] ?? null)
            ? max($min, min($max, $data[$key]))
            : $default;

        $historyDays = array_key_exists('historyDays', $data) && $data['historyDays'] === null
            ? null
            : $int('historyDays', 1, PHP_INT_MAX, $defaults->historyDays);

        return new self(
            $historyDays,
            (int)$int('intervalMinutes', 15, 1440, $defaults->intervalMinutes),
            (int)$int('jitterPercent', 0, 50, $defaults->jitterPercent),
            is_string($data['deletionMode'] ?? null)
                ? DeletionMode::tryFrom($data['deletionMode']) ?? $defaults->deletionMode
                : $defaults->deletionMode,
            (int)$int('deletionCheckDays', 7, 365, $defaults->deletionCheckDays),
        );
    }

    /**
     * Die Schlüssel, die fromArray() liest.
     *
     * @return array{historyDays: ?int, intervalMinutes: int, jitterPercent: int, deletionMode: string, deletionCheckDays: int}
     */
    public function toArray(): array
    {
        return [
            'historyDays' => $this->historyDays,
            'intervalMinutes' => $this->intervalMinutes,
            'jitterPercent' => $this->jitterPercent,
            'deletionMode' => $this->deletionMode->value,
            'deletionCheckDays' => $this->deletionCheckDays,
        ];
    }

    /**
     * Zeitpunkt des nächsten Laufs: Intervall, zufällig um ±jitterPercent gestreut (F-16).
     *
     * @param (callable(int, int): int)|null $random Zufallszahl im Bereich [min, max], in Tests ersetzbar
     */
    public function nextRunAt(int $now, ?callable $random = null): int
    {
        $interval = $this->intervalMinutes * 60;
        $spread = intdiv($interval * $this->jitterPercent, 100);
        $random ??= random_int(...);

        return $now + $random($interval - $spread, $interval + $spread);
    }
}
