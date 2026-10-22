<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Sync;

use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;

/** Counters of one sync run, logged at its end. */
final class SyncResult
{
    public int $created = 0;
    public int $updated = 0;
    public int $unchanged = 0;
    public int $markedDeleted = 0;
    public int $deleted = 0;
    /** Activities that could not be mapped */
    public int $skipped = 0;
    public int $backfillPages = 0;
    public ?FailureCategory $failure = null;

    /** A history import stage counts as a change, so it is logged at info level even if no event changed. */
    public function hasChanges(): bool
    {
        return $this->created + $this->updated + $this->markedDeleted + $this->deleted + $this->backfillPages > 0;
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return ['failure' => $this->failure?->value] + array_filter(get_object_vars($this), 'is_int');
    }
}
