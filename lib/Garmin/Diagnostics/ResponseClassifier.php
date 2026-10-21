<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Diagnostics;

use OCA\WorkoutCalendar\Garmin\Http\HttpResponse;

/** Assigns HTTP responses from Garmin to a failure category. */
final class ResponseClassifier
{
    /** Titles of the Cloudflare challenge pages, as of 2026. */
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

    /** @return array<string, string> Cloudflare markers found, for the log */
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
