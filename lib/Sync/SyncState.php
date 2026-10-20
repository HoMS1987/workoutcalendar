<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Sync;

/**
 * Sync-Zustand einer Person. Feste Größe, wird als JSON gespeichert.
 * Zeitpunkte sind Unix-Sekunden, Daten lokale Garmin-Daten im Format YYYY-MM-DD.
 */
final class SyncState
{
    public bool $initialized = false;
    public ?int $lastRunAt = null;
    public ?int $lastSuccessAt = null;
    public ?int $nextRunAt = null;

    /** Historien-Import: Aktivitäten bis einschließlich dieses Datums sind noch zu holen. */
    public ?string $backfillBefore = null;
    /** Ältestes zu importierendes Datum, null = komplette Historie. */
    public ?string $backfillUntil = null;
    public bool $backfillDone = false;

    public ?string $failureCategory = null;
    public ?string $failureStep = null;
    /** Bereinigte Kurzbeschreibung für die Einstellungsseite */
    public ?string $failureMessage = null;
    public ?int $failureSince = null;
    public int $failureCount = 0;
    public ?int $failureReportedAt = null;

    /** FailureCategory-Wert, der den Sync anhält, bis neue Zugangsdaten vorliegen (INVALID_CREDENTIALS, MFA_REQUIRED). */
    public ?string $pausedFor = null;
    /** Für diese Fehlerkategorie wurde bereits eine Benachrichtigung gesendet. */
    public ?string $notifiedCategory = null;
    /** Ältestes lokales Startdatum aller bisher importierten Aktivitäten, wandert nie nach vorn. */
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
