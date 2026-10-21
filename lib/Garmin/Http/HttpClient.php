<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Http;

/**
 * Cookies are kept across all requests of an instance (needed for the widget login).
 */
interface HttpClient
{
    /** @throws NetworkException if no HTTP response is obtained */
    public function send(HttpRequest $request): HttpResponse;
}
