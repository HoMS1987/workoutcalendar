<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Sync;

/** An app event already stored in the calendar. */
final class StoredEvent
{
    public function __construct(
        public readonly string $activityId,
        public readonly ?string $fingerprint,
        public readonly bool $markedDeleted,
    ) {
    }
}
