<?php

declare(strict_types=1);

namespace OCA\GarminCalendar\Garmin;

use OCA\GarminCalendar\Garmin\Auth\Authenticator;
use OCA\GarminCalendar\Garmin\Auth\Credentials;
use OCA\GarminCalendar\Garmin\Auth\DiTokenService;
use OCA\GarminCalendar\Garmin\Auth\GarminTokens;
use OCA\GarminCalendar\Garmin\Auth\TokenStore;
use OCA\GarminCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\GarminCalendar\Garmin\Diagnostics\Redactor;
use OCA\GarminCalendar\Garmin\Diagnostics\ResponseClassifier;
use OCA\GarminCalendar\Garmin\Http\HttpClient;
use OCA\GarminCalendar\Garmin\Http\HttpRequest;
use OCA\GarminCalendar\Garmin\Http\HttpResponse;
use Psr\Log\LoggerInterface;

/**
 * Datenzugriff auf Garmin Connect mit Token-Verwaltung: gespeicherte Tokens nutzen, bei Ablauf
 * erneuern und nur dann neu anmelden, wenn der Refresh-Token nicht mehr akzeptiert wird.
 */
final class GarminClient
{
    private const ACTIVITIES_PATH = '/activitylist-service/activities/search/activities';

    /** @var \Closure(): int */
    private readonly \Closure $now;
    private readonly ResponseClassifier $classifier;
    private readonly Redactor $redactor;

    /** @param (callable(): int)|null $now Aktuelle Unix-Zeit, in Tests ersetzbar */
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
     * Aktivitäten, neueste zuerst.
     *
     * @return list<array<string, mixed>>
     * @throws GarminException
     */
    public function activities(int $start, int $limit): array
    {
        $step = 'api.activities';
        $url = GarminConstants::CONNECT_API . self::ACTIVITIES_PATH . '?' . http_build_query(['start' => $start, 'limit' => $limit]);

        $tokens = $this->validTokens();
        $response = $this->get($step, $url, $tokens);
        if ($response->status === 401) {
            $this->logger->info('Garmin lehnt Access-Token ab, erneuere ihn', ['step' => $step]);
            $response = $this->get($step, $url, $this->renew($tokens));
        }

        $category = $this->classifier->classify($response);
        if ($category !== null) {
            throw $this->failure($category, $step, "HTTP {$response->status}", $response);
        }
        $data = $response->json();
        if ($data === null || !array_is_list($data)) {
            throw $this->failure(FailureCategory::ProtocolChanged, $step, 'Antwort ist keine Liste von Aktivitäten', $response);
        }

        return $data;
    }

    private function get(string $step, string $url, GarminTokens $tokens): HttpResponse
    {
        return $this->http->send(new HttpRequest($step, 'GET', $url, GarminConstants::NATIVE_HEADERS + [
            'Authorization' => 'Bearer ' . $tokens->accessToken,
            'Accept' => 'application/json',
        ]));
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

    /** Refresh, bei abgelehntem Refresh-Token neuer Login. */
    private function renew(GarminTokens $tokens): GarminTokens
    {
        try {
            $renewed = $this->diTokens->refresh($tokens);
        } catch (GarminException $e) {
            if ($e->category !== FailureCategory::TokenRejected) {
                throw $e;
            }
            $this->logger->info('Garmin-Refresh-Token abgelehnt, melde neu an', $e->logContext());

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

    private function failure(FailureCategory $category, string $step, string $message, HttpResponse $response): GarminException
    {
        return new GarminException($category, $step, $message, $response->status, $this->redactor->excerpt($response->body));
    }
}
