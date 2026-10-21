<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Sync;

/**
 * A person's sync state. Fixed size, stored as JSON.
 * Times are Unix seconds, dates are local Garmin dates in the format YYYY-MM-DD.
 */
final class SyncState
{
    public bool $initialized = false;
    public ?int $lastRunAt = null;
    public ?int $lastSuccessAt = null;
    public ?int $nextRunAt = null;

    /** History import: activities up to and including this date are still to be fetched. */
    public ?string $backfillBefore = null;
    /** Oldest date to import, null = complete history. */
    public ?string $backfillUntil = null;
    public bool $backfillDone = false;

    public ?string $failureCategory = null;
    public ?string $failureStep = null;
    /** Sanitized short description for the settings page */
    public ?string $failureMessage = null;
    public ?int $failureSince = null;
    public int $failureCount = 0;
    public ?int $failureReportedAt = null;

    /** FailureCategory value that pauses the sync until new credentials are available (INVALID_CREDENTIALS, MFA_REQUIRED). */
    public ?string $pausedFor = null;
    /** A notification has already been sent for this failure category. */
    public ?string $notifiedCategory = null;
    /** Oldest local start date of all activities imported so far; it never moves forward. */
    public ?string $oldestActivityDate = null;

    private const INT_FIELDS = ['lastRunAt', 'lastSuccessAt', 'nextRunAt', 'failureSince', 'failureReportedAt'];
    private const STRING_FIELDS = ['backfillBefore', 'backfillUntil', 'failureCategory', 'failureStep', 'failureMessage', 'pausedFor', 'notifiedCategory', 'oldestActivityDate'];
    private const BOOL_FIELDS = ['initialized', 'backfillDone'];

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $state = new self();
        foreach (self::INT_FIELDS as $field) {
            $state->$field = is_int($data[$field] ?? null) ? $data[$field] : null;
        }
        foreach (self::STRING_FIELDS as $field) {
            $state->$field = is_string($data[$field] ?? null) ? $data[$field] : null;
        }
        foreach (self::BOOL_FIELDS as $field) {
            $state->$field = ($data[$field] ?? false) === true;
        }
        $state->failureCount = is_int($data['failureCount'] ?? null) ? $data['failureCount'] : 0;

        return $state;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
