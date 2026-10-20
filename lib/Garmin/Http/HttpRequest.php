<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Http;

final class HttpRequest
{
    /**
     * @param string $step Name des Ablaufschritts für das Diagnose-Log, z. B. "widget.signin-post"
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $step,
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly ?string $body = null,
    ) {
    }
}
