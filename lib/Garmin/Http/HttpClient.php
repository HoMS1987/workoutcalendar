<?php

declare(strict_types=1);

namespace OCA\GarminCalendar\Garmin\Http;

/**
 * Cookies bleiben über alle Anfragen einer Instanz erhalten (nötig für den Widget-Login).
 */
interface HttpClient
{
    /** @throws NetworkException wenn keine HTTP-Antwort zustande kommt */
    public function send(HttpRequest $request): HttpResponse;
}
