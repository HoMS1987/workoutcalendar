<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Http;

final class HttpResponse
{
    /** @var array<string, string> Header names in lower case */
    public readonly array $headers;

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        array $headers,
        public readonly string $body,
        public readonly string $effectiveUrl = '',
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<mixed>|null null if the body is not a JSON object or array */
    public function json(): ?array
    {
        $data = json_decode($this->body, true);

        return is_array($data) ? $data : null;
    }
}
