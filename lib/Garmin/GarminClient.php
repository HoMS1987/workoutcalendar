<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin;

use OCA\WorkoutCalendar\Garmin\Auth\Authenticator;
use OCA\WorkoutCalendar\Garmin\Auth\Credentials;
use OCA\WorkoutCalendar\Garmin\Auth\DiTokenService;
use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Garmin\Auth\TokenStore;
use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\Diagnostics\Redactor;
use OCA\WorkoutCalendar\Garmin\Diagnostics\ResponseClassifier;
use OCA\WorkoutCalendar\Garmin\Http\HttpClient;
use OCA\WorkoutCalendar\Garmin\Http\HttpRequest;
use OCA\WorkoutCalendar\Garmin\Http\HttpResponse;
use OCA\WorkoutCalendar\Garmin\Http\NetworkException;
use Psr\Log\LoggerInterface;

/**
 * Data access to Garmin Connect with token management: use stored tokens, refresh them on
 * expiry and sign in again only when the refresh token is no longer accepted.
 */
final class GarminClient
{
    private const ACTIVITIES_PATH = '/activitylist-service/activities/search/activities';

    /** @var \Closure(): int */
    private readonly \Closure $now;
    private readonly ResponseClassifier $classifier;
    private readonly Redactor $redactor;

    /** @param (callable(): int)|null $now Current Unix time, replaceable in tests */
    public function __construct(
        private readonly HttpClient $http,
        private readonly Authenticator $authenticator,
        private readonly DiTokenService $diTokens,
        private readonly TokenStore $store,
        private readonly Credentials $credentials,
        private readonly LoggerInterface $logger,
        ?callable $now = null,
    ) {
        $this->now = $now !== null ? \Closure::fromCallable($now) : static fn (): int => time();
        $this->classifier = new ResponseClassifier();
        $this->redactor = new Redactor([$credentials->password]);
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
        $url = GarminConstants::CONNECT_API . self::ACTIVITIES_PATH . '?' . http_build_query($query);

        $tokens = $this->validTokens();
        $response = $this->get($step, $url, $tokens);
        // One retry with a renewed token, as in python-garminconnect; a second 401 is reported as TokenRejected.
        if ($response->status === 401) {
            $this->logger->info('Garmin rejected the access token, renewing it', ['step' => $step]);
            $response = $this->get($step, $url, $this->renew($tokens));
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

    private function validTokens(): GarminTokens
    {
        $tokens = $this->store->load();
        if ($tokens === null) {
            return $this->login();
        }
        if ($tokens->isExpired(($this->now)())) {
            return $this->renew($tokens);
        }

        return $tokens;
    }

    /** Refresh; a new login if the refresh token is rejected. */
    private function renew(GarminTokens $tokens): GarminTokens
    {
        try {
            $renewed = $this->diTokens->refresh($tokens);
        } catch (NetworkException $e) {
            throw $this->networkFailure('refresh', $e);
        } catch (GarminException $e) {
            if ($e->category !== FailureCategory::TokenRejected) {
                throw $e;
            }
            $this->logger->info('Garmin rejected the refresh token, signing in again', $e->logContext());

            return $this->login();
        }
        $this->store->save($renewed);

        return $renewed;
    }

    private function login(): GarminTokens
    {
        $tokens = $this->authenticator->login($this->credentials);
        $this->store->save($tokens);

        return $tokens;
    }

    /** Keeps the step of the request without a response, so a failed refresh is not reported as the activity request. */
    private function networkFailure(string $step, NetworkException $e): GarminException
    {
        return new GarminException(FailureCategory::Network, $step, $this->redactor->redact($e->getMessage()), previous: $e);
    }

    private function failure(FailureCategory $category, string $step, string $message, HttpResponse $response): GarminException
    {
        return new GarminException($category, $step, $message, $response->status, $this->redactor->excerpt($response->body));
    }
}
