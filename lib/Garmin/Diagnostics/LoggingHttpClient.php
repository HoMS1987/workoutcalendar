<?php

declare(strict_types=1);

namespace OCA\GarminCalendar\Garmin\Diagnostics;

use OCA\GarminCalendar\Garmin\Http\HttpClient;
use OCA\GarminCalendar\Garmin\Http\HttpRequest;
use OCA\GarminCalendar\Garmin\Http\HttpResponse;
use OCA\GarminCalendar\Garmin\Http\NetworkException;
use Psr\Log\LoggerInterface;

/**
 * Protokolliert jede Garmin-Anfrage (ADR 0004). Query, Header und Bodies werden nie geloggt,
 * weil sie Tickets, Tokens oder Passwörter enthalten können.
 */
final class LoggingHttpClient implements HttpClient
{
    public function __construct(
        private readonly HttpClient $inner,
        private readonly LoggerInterface $logger,
        private readonly ResponseClassifier $classifier,
        private readonly Redactor $redactor,
    ) {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $context = [
            'step' => $request->step,
            'method' => $request->method,
            'endpoint' => self::endpoint($request->url),
        ];
        $started = hrtime(true);

        try {
            $response = $this->inner->send($request);
        } catch (NetworkException $e) {
            $this->logger->warning('Garmin-Anfrage ohne Antwort', $context + [
                'category' => FailureCategory::Network->value,
                'error' => $this->redactor->redact($e->getMessage()),
                'durationMs' => self::elapsedMs($started),
            ]);
            throw $e;
        }

        $this->logger->debug('Garmin-Anfrage', $context + [
            'status' => $response->status,
            'durationMs' => self::elapsedMs($started),
            'cloudflare' => $this->classifier->cloudflareMarkers($response),
        ]);

        return $response;
    }

    private static function endpoint(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['host'] ?? '') . ($parts['path'] ?? '');
    }

    private static function elapsedMs(int|float $started): int
    {
        return (int)round((hrtime(true) - $started) / 1_000_000);
    }
}
