<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Storage;

/** Stand der Einrichtung einer Person. Wird als JSON gespeichert. */
final class SetupState
{
    /**
     * @param ?string $accountKey Kontoschlüssel des verbundenen Garmin-Kontos
     * @param ?string $maskedEmail maskierte E-Mail-Adresse für die Anzeige
     * @param bool $calendarCreatedByApp der Kalender wurde von der App angelegt
     * @param ?string $maintenance 'move', 'remove' oder null
     * @param ?string $maintenanceFailed 'move' oder 'remove', wenn der Wartungsjob aufgegeben hat, sonst null
     * @param bool $calendarMissing der Zielkalender fehlte beim letzten Sync-Lauf, die Einrichtung steht wieder bei der Kalenderwahl
     */
    public function __construct(
        public readonly SetupStep $step = SetupStep::Garmin,
        public readonly bool $connected = false,
        public readonly ?string $accountKey = null,
        public readonly ?string $maskedEmail = null,
        public readonly ?int $calendarId = null,
        public readonly bool $calendarCreatedByApp = false,
        public readonly ?string $maintenance = null,
        public readonly ?string $maintenanceFailed = null,
        public readonly bool $calendarMissing = false,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $string = static fn (string $key): ?string => is_string($data[$key] ?? null) ? $data[$key] : null;
        $maintenance = $string('maintenance');
        $failed = $string('maintenanceFailed');

        return new self(
            is_string($data['step'] ?? null) ? SetupStep::tryFrom($data['step']) ?? SetupStep::Garmin : SetupStep::Garmin,
            ($data['connected'] ?? false) === true,
            $string('accountKey'),
            $string('maskedEmail'),
            is_int($data['calendarId'] ?? null) ? $data['calendarId'] : null,
            ($data['calendarCreatedByApp'] ?? false) === true,
            $maintenance === 'move' || $maintenance === 'remove' ? $maintenance : null,
            $failed === 'move' || $failed === 'remove' ? $failed : null,
            ($data['calendarMissing'] ?? false) === true,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'step' => $this->step->value,
            'connected' => $this->connected,
            'accountKey' => $this->accountKey,
            'maskedEmail' => $this->maskedEmail,
            'calendarId' => $this->calendarId,
            'calendarCreatedByApp' => $this->calendarCreatedByApp,
            'maintenance' => $this->maintenance,
            'maintenanceFailed' => $this->maintenanceFailed,
            'calendarMissing' => $this->calendarMissing,
        ];
    }
}
