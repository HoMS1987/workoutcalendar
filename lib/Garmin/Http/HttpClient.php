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

    /**
     * Cookies currently held, as Netscape lines like CURLINFO_COOKIELIST returns them.
     *
     * @return list<string>
     */
    public function exportCookies(): array;

    /**
     * Loads cookies in the same Netscape line format, e.g. from an earlier export.
     *
     * @param list<string> $lines
     */
    public function importCookies(array $lines): void;
}
