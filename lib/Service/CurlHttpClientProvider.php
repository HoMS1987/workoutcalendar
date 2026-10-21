<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

use OCA\WorkoutCalendar\Garmin\Http\CurlHttpClient;
use OCA\WorkoutCalendar\Garmin\Http\HttpClient;

/** Default implementation using cURL. */
final class CurlHttpClientProvider implements HttpClientProvider
{
    public function create(): HttpClient
    {
        return new CurlHttpClient();
    }
}
