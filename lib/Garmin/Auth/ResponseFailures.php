<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Auth;

use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\Diagnostics\Redactor;
use OCA\WorkoutCalendar\Garmin\Diagnostics\ResponseClassifier;
use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Garmin\Http\HttpResponse;

/** Shared error handling of the login strategies. */
final class ResponseFailures
{
    private readonly ResponseClassifier $classifier;
    private readonly Redactor $redactor;

    public function __construct(?Redactor $redactor = null)
    {
        $this->classifier = new ResponseClassifier();
        $this->redactor = $redactor ?? new Redactor();
    }

    /** @throws GarminException on an HTTP error status */
    public function assertOk(HttpResponse $response, string $step): void
    {
        $category = $this->classifier->classify($response);
        if ($category !== null) {
            throw $this->create($category, $step, "HTTP {$response->status}", $response);
        }
    }

    public function create(FailureCategory $category, string $step, string $message, HttpResponse $response): GarminException
    {
        return new GarminException($category, $step, $message, $response->status, $this->redactor->excerpt($response->body));
    }

    public static function pageTitle(string $html): string
    {
        return preg_match('#<title>\s*(.*?)\s*</title>#is', $html, $m) ? html_entity_decode($m[1]) : '';
    }
}
