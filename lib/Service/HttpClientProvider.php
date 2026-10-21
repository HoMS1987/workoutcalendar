<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

use OCA\WorkoutCalendar\Garmin\Http\HttpClient;

/**
 * Provides the HTTP client for Garmin. Integration tests replace the implementation so they never
 * talk to Garmin.
 */
interface HttpClientProvider
{
    /** Every call returns a new client; cookies are never shared between people. */
    public function create(): HttpClient;
}
