<?php

declare(strict_types=1);

namespace OCA\GarminCalendar\Sync;

use OCA\GarminCalendar\Garmin\Diagnostics\FailureCategory;

final class SyncResult
{
    public int $created = 0;
    public int $updated = 0;
    public int $unchanged = 0;
    public int $markedDeleted = 0;
    public int $deleted = 0;
    /** Aktivitäten, die sich nicht abbilden ließen */
    public int $skipped = 0;
    public int $backfillPages = 0;
    public ?FailureCategory $failure = null;

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
