<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Sync;

/** A person's settings with defaults and limits from docs/anforderungen.md. */
final class SyncSettings
{
    /** The Garmin web UI and python-garminconnect load 20 activities per page. */
    public const PAGE_SIZE = 20;
    /** Pages of history import per run so a large import does not block the Nextcloud cron (F-22). */
    public const BACKFILL_PAGES_PER_RUN = 5;

    /** Wait until the next run while the history import is not finished yet. */
    public const BACKFILL_NEXT_RUN_SECONDS = 60;

    /**
     * @param ?int $historyDays null = complete history
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
     * The keys that fromArray() reads.
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
     * Time of the next run: interval, randomly spread by ±jitterPercent (F-16).
     *
     * @param (callable(int, int): int)|null $random Random number in the range [min, max], replaceable in tests
     */
    public function nextRunAt(int $now, ?callable $random = null): int
    {
        $interval = $this->intervalMinutes * 60;
        $spread = intdiv($interval * $this->jitterPercent, 100);
        $random ??= random_int(...);

        return $now + $random($interval - $spread, $interval + $spread);
    }
}
