<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Http;

/**
 * HTTP transport of the Garmin client, replaceable in tests. Cookies are kept across all requests
 * of an instance (needed for the widget login).
 */
interface HttpClient
{
    /** @throws NetworkException if no HTTP response is obtained */
    public function send(HttpRequest $request): HttpResponse;
}
