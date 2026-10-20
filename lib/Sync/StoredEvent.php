<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Sync;

/** Ein bereits im Kalender gespeicherter Termin der App. */
final class StoredEvent
{
    public function __construct(
        public readonly string $activityId,
        public readonly ?string $fingerprint,
        public readonly bool $markedDeleted,
    ) {
    }
}
