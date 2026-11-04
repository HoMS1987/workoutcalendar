<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin;

use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Garmin\Auth\TokenProvider;
use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\Diagnostics\Redactor;
use OCA\WorkoutCalendar\Garmin\Diagnostics\ResponseClassifier;
use OCA\WorkoutCalendar\Garmin\Http\HttpClient;
use OCA\WorkoutCalendar\Garmin\Http\HttpRequest;
use OCA\WorkoutCalendar\Garmin\Http\HttpResponse;
use OCA\WorkoutCalendar\Garmin\Http\NetworkException;
use Psr\Log\LoggerInterface;

/**
 * Data access to Garmin Connect. The tokens come from a provider per sign-in method, which
 * obtains, stores and renews them.
 */
final class GarminClient
{
    private const ACTIVITIES_PATH = '/activitylist-service/activities/search/activities';

    private readonly ResponseClassifier $classifier;
    private readonly Redactor $redactor;

    /**
     * @param string $apiBase Base URL of the API, e.g. GarminConstants::CONNECT_API
     * @param list<string> $knownSecrets Values error messages and excerpts must never contain (password or API key)
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly TokenProvider $tokens,
        private readonly LoggerInterface $logger,
        private readonly string $apiBase,
        array $knownSecrets = [],
    ) {
        $this->classifier = new ResponseClassifier();
        $this->redactor = new Redactor($knownSecrets);
    }

    /**
     * Activities, newest first (Garmin sorts descending by local start time).
     *
     * @param ?string $startDate Local date YYYY-MM-DD, inclusive
     * @param ?string $endDate Local date YYYY-MM-DD, inclusive
     * @return list<array<string, mixed>>
     * @throws GarminException
     */
    public function activities(int $start, int $limit, ?string $startDate = null, ?string $endDate = null): array
    {
        $step = 'api.activities';
        $query = array_filter(['startDate' => $startDate, 'endDate' => $endDate], static fn (?string $d): bool => $d !== null)
            + ['start' => $start, 'limit' => $limit];
        $url = $this->apiBase . self::ACTIVITIES_PATH . '?' . http_build_query($query);

        $tokens = $this->tokens->current();
        $response = $this->get($step, $url, $tokens);
        // One retry with a renewed token, as in python-garminconnect; a second 401 is reported as TokenRejected.
        if ($response->status === 401) {
            $this->logger->info('Garmin rejected the access token, renewing it', ['step' => $step]);
            $response = $this->get($step, $url, $this->tokens->renew($tokens));
        }

        $category = $this->classifier->classify($response);
        if ($category !== null) {
            throw $this->failure($category, $step, "HTTP {$response->status}", $response);
        }
        $data = $response->json();
        if ($data === null || !array_is_list($data)) {
            throw $this->failure(FailureCategory::ProtocolChanged, $step, 'Response is not a list of activities', $response);
        }

        return $data;
    }

    private function get(string $step, string $url, GarminTokens $tokens): HttpResponse
    {
        try {
            return $this->http->send(new HttpRequest($step, 'GET', $url, GarminConstants::NATIVE_HEADERS + [
                'Authorization' => 'Bearer ' . $tokens->accessToken,
                'Accept' => 'application/json',
            ]));
        } catch (NetworkException $e) {
            throw $this->networkFailure($step, $e);
        }
    }

    /** The activity request got no response; the message is masked with the known secrets. */
    private function networkFailure(string $step, NetworkException $e): GarminException
    {
        return new GarminException(FailureCategory::Network, $step, $this->redactor->redact($e->getMessage()), previous: $e);
    }

    private function failure(FailureCategory $category, string $step, string $message, HttpResponse $response): GarminException
    {
        return new GarminException($category, $step, $message, $response->status, $this->redactor->excerpt($response->body));
    }
}
