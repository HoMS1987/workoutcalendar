<?php

declare(strict_types=1);

namespace OCA\GarminCalendar\Calendar;

/** Liest die Garmin-Eigenschaften aus einem gespeicherten Termin. */
final class IcsReader
{
    public function activityId(string $ics): ?string
    {
        return $this->property($ics, 'X-GARMIN-ACTIVITY-ID');
    }

    public function fingerprint(string $ics): ?string
    {
        return $this->property($ics, 'X-GARMIN-FINGERPRINT');
    }

    public function isMarkedDeleted(string $ics): bool
    {
        return strcasecmp((string)$this->property($ics, 'X-GARMIN-DELETED'), 'TRUE') === 0;
    }

    private function property(string $ics, string $name): ?string
    {
        foreach (IcsLines::unfold($ics) as $line) {
            if (IcsLines::name($line) === $name) {
                return IcsLines::value($line);
            }
        }

        return null;
    }
}
