<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

use OCA\WorkoutCalendar\Garmin\Http\HttpClient;

/**
 * Liefert den HTTP-Client für Garmin. Integrationstests ersetzen die Implementierung, damit sie nie
 * mit Garmin sprechen.
 */
interface HttpClientProvider
{
    /** Jeder Aufruf liefert einen neuen Client, Cookies werden nie zwischen Personen geteilt. */
    public function create(): HttpClient;
}
