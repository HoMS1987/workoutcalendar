<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Diagnostics;

use OCA\WorkoutCalendar\Garmin\Http\HttpResponse;

/** Ordnet HTTP-Antworten von Garmin einer Fehlerkategorie zu. */
final class ResponseClassifier
{
    /** Titel der Cloudflare-Challenge-Seiten, Stand 2026. */
    private const CHALLENGE_TITLES = ['Just a moment...', 'Attention Required! | Cloudflare'];

    public function classify(HttpResponse $response): ?FailureCategory
    {
        return match (true) {
            $response->status < 400 => null,
            $response->status === 401 => FailureCategory::TokenRejected,
            $response->status === 403, $response->status === 429 => FailureCategory::Blocked,
            $response->status >= 500 => FailureCategory::ServerError,
            default => FailureCategory::ProtocolChanged,
        };
    }

    /** @return array<string, string> Gefundene Cloudflare-Merkmale für das Log */
    public function cloudflareMarkers(HttpResponse $response): array
    {
        $markers = [];
        foreach (['cf-ray', 'cf-mitigated'] as $name) {
            if ($response->header($name) !== null) {
                $markers[$name] = $response->header($name);
            }
        }
        if (strcasecmp((string)$response->header('server'), 'cloudflare') === 0) {
            $markers['server'] = 'cloudflare';
        }
        foreach (self::CHALLENGE_TITLES as $title) {
            if (str_contains($response->body, "<title>$title</title>")) {
                $markers['challenge-page'] = $title;
            }
        }

        return $markers;
    }
}
