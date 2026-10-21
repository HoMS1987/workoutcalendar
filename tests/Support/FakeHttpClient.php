<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Support;

use OCA\WorkoutCalendar\Garmin\Http\HttpClient;
use OCA\WorkoutCalendar\Garmin\Http\HttpRequest;
use OCA\WorkoutCalendar\Garmin\Http\HttpResponse;

/** Returns prepared responses in order and records all requests. */
final class FakeHttpClient implements HttpClient
{
    /** @var list<HttpRequest> */
    public array $requests = [];

    /** @var list<HttpResponse|\Throwable> */
    private array $queue;

    public function __construct(HttpResponse|\Throwable ...$responses)
    {
        $this->queue = $responses;
    }

    public function queue(HttpResponse|\Throwable ...$responses): void
    {
        array_push($this->queue, ...$responses);
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException("No response queued for {$request->step} {$request->method} {$request->url}");
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /** @return list<string> */
    public function steps(): array
    {
        return array_map(static fn (HttpRequest $r): string => $r->step, $this->requests);
    }

    public static function json(int $status, array $data, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, $headers + ['content-type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }
}
