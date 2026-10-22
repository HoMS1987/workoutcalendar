<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Http;

/** One request to Garmin, named by its step in the flow. */
final class HttpRequest
{
    /**
     * @param string $step Name of the flow step for the diagnostic log, e.g. "widget.signin-post"
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
