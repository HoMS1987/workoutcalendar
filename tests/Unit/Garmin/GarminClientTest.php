<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Garmin;

use OCA\WorkoutCalendar\Garmin\Auth\ApiKeyTokenProvider;
use OCA\WorkoutCalendar\Garmin\Auth\ApiKeyTokenService;
use OCA\WorkoutCalendar\Garmin\Auth\Authenticator;
use OCA\WorkoutCalendar\Garmin\Auth\Credentials;
use OCA\WorkoutCalendar\Garmin\Auth\DiTokenService;
use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Garmin\Auth\LoginStrategy;
use OCA\WorkoutCalendar\Garmin\Auth\PasswordTokenProvider;
use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\Diagnostics\Redactor;
use OCA\WorkoutCalendar\Garmin\GarminClient;
use OCA\WorkoutCalendar\Garmin\GarminConstants;
use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Garmin\Http\HttpResponse;
use OCA\WorkoutCalendar\Garmin\Http\NetworkException;
use OCA\WorkoutCalendar\Tests\Support\FakeHttpClient;
use OCA\WorkoutCalendar\Tests\Support\Jwt;
use OCA\WorkoutCalendar\Tests\Support\MemoryLogger;
use OCA\WorkoutCalendar\Tests\Support\MemoryTokenStore;
use PHPUnit\Framework\TestCase;

final class GarminClientTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const API_KEY = 'personal-key-4711';

    private FakeHttpClient $http;
    private MemoryTokenStore $store;
    private MemoryLogger $logger;
    private int $logins = 0;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->store = new MemoryTokenStore();
        $this->logger = new MemoryLogger();
        $this->logins = 0;
    }

    private function client(bool $passwordLogin = true): GarminClient
    {
        $strategy = new class ($this->logins) implements LoginStrategy {
            public function __construct(private int &$logins)
            {
            }

            public function name(): string
            {
                return 'fake';
            }

            public function login(Credentials $credentials): GarminTokens
            {
                $this->logins++;

                return new GarminTokens(Jwt::make(['exp' => GarminClientTest::validExp(), 'n' => $this->logins]), 'r-login', 'C');
            }
        };

        $credentials = new Credentials('a@b.de', 'pw');
        $tokens = new PasswordTokenProvider(
            new Authenticator([$strategy], $this->logger),
            new DiTokenService($this->http),
            $this->store,
            $credentials,
            $this->logger,
            static fn (): int => self::NOW,
            $passwordLogin,
        );

        return new GarminClient($this->http, $tokens, $this->logger, GarminConstants::CONNECT_API, [$credentials->password]);
    }

    private function apiKeyClient(): GarminClient
    {
        $tokens = new ApiKeyTokenProvider(
            new ApiKeyTokenService($this->http, new Redactor([self::API_KEY])),
            $this->store,
            self::API_KEY,
            static fn (): int => self::NOW,
        );

        return new GarminClient($this->http, $tokens, $this->logger, GarminConstants::PERSONAL_API, [self::API_KEY]);
    }

    public static function validExp(): int
    {
        return self::NOW + 3600;
    }

    private static function validTokens(): GarminTokens
    {
        return new GarminTokens(Jwt::make(['exp' => self::validExp()]), 'r', 'C');
    }

    public function testUsesStoredTokenWithoutLogin(): void
    {
        $this->store->tokens = self::validTokens();
        $this->http->queue(FakeHttpClient::json(200, [['activityId' => 1]]));

        $activities = $this->client()->activities(20, 10);

        self::assertSame([['activityId' => 1]], $activities);
        self::assertSame(0, $this->logins);
        $request = $this->http->requests[0];
        self::assertSame('api.activities', $request->step);
        self::assertSame('https://connectapi.garmin.com/activitylist-service/activities/search/activities?start=20&limit=10', $request->url);
        self::assertSame('Bearer ' . self::validTokens()->accessToken, $request->headers['Authorization']);
        self::assertSame('GCM-Android-5.23', $request->headers['User-Agent']);
    }

    public function testFiltersByDateRange(): void
    {
        $this->store->tokens = self::validTokens();
        $this->http->queue(FakeHttpClient::json(200, []), FakeHttpClient::json(200, []));

        $this->client()->activities(0, 20, '2026-09-20', '2026-10-20');
        $this->client()->activities(40, 20, null, '2026-01-31');

        self::assertSame(
            'https://connectapi.garmin.com/activitylist-service/activities/search/activities?startDate=2026-09-20&endDate=2026-10-20&start=0&limit=20',
            $this->http->requests[0]->url,
        );
        self::assertSame(
            'https://connectapi.garmin.com/activitylist-service/activities/search/activities?endDate=2026-01-31&start=40&limit=20',
            $this->http->requests[1]->url,
        );
    }

    public function testLogsInAndStoresTokensWhenNoneStored(): void
    {
        $this->http->queue(FakeHttpClient::json(200, []));

        $this->client()->activities(0, 10);

        self::assertSame(1, $this->logins);
        self::assertSame('r-login', $this->store->tokens?->refreshToken);
    }

    public function testRefreshesExpiredTokenBeforeRequest(): void
    {
        $this->store->tokens = new GarminTokens(Jwt::make(['exp' => self::NOW - 10]), 'r-old', 'C');
        $fresh = Jwt::make(['exp' => self::validExp(), 'fresh' => true]);
        $this->http->queue(FakeHttpClient::json(200, ['access_token' => $fresh, 'refresh_token' => 'r-new']), FakeHttpClient::json(200, []));

        $this->client()->activities(0, 10);

        self::assertSame(['refresh', 'api.activities'], $this->http->steps());
        self::assertSame($fresh, $this->store->tokens?->accessToken);
        self::assertSame('r-new', $this->store->tokens?->refreshToken);
        self::assertSame(0, $this->logins);
    }

    public function testLogsInAgainWhenRefreshTokenIsRejected(): void
    {
        $this->store->tokens = new GarminTokens(Jwt::make(['exp' => self::NOW - 10]), 'r-old', 'C');
        $this->http->queue(FakeHttpClient::json(400, ['error' => 'invalid_grant']), FakeHttpClient::json(200, []));

        $this->client()->activities(0, 10);

        self::assertSame(1, $this->logins);
        self::assertSame('r-login', $this->store->tokens?->refreshToken);
        self::assertSame('TOKEN_REJECTED', $this->logger->byLevel('info')[0]['context']['category'] ?? null);
    }

    public function testRetriesOnceAfter401WithRefreshedToken(): void
    {
        $this->store->tokens = self::validTokens();
        $this->http->queue(
            new HttpResponse(401, [], ''),
            FakeHttpClient::json(200, ['access_token' => Jwt::make(['exp' => self::validExp(), 'retry' => 1])]),
            FakeHttpClient::json(200, [['activityId' => 7]]),
        );

        self::assertSame([['activityId' => 7]], $this->client()->activities(0, 10));
        self::assertSame(['api.activities', 'refresh', 'api.activities'], $this->http->steps());
    }

    public function testSecond401IsTokenRejected(): void
    {
        $this->store->tokens = self::validTokens();
        $this->http->queue(
            new HttpResponse(401, [], ''),
            FakeHttpClient::json(200, ['access_token' => Jwt::make(['exp' => self::validExp()])]),
            new HttpResponse(401, [], ''),
        );

        $e = $this->failure();

        self::assertSame(FailureCategory::TokenRejected, $e->category);
        self::assertSame('api.activities', $e->step);
    }

    public function testBlockedApiIsReported(): void
    {
        $this->store->tokens = self::validTokens();
        $this->http->queue(new HttpResponse(429, [], 'Too Many Requests'));

        self::assertSame(FailureCategory::Blocked, $this->failure()->category);
    }

    public function testUnexpectedResponseShapeMeansProtocolChanged(): void
    {
        $this->store->tokens = self::validTokens();
        $this->http->queue(FakeHttpClient::json(200, ['activities' => []]));

        self::assertSame(FailureCategory::ProtocolChanged, $this->failure()->category);
    }

    public function testNetworkErrorOfRequestIsNetworkFailureOfThatStep(): void
    {
        $this->store->tokens = self::validTokens();
        $this->http->queue(new NetworkException('Could not resolve host: connectapi.garmin.com'));

        $e = $this->failure();

        self::assertSame(FailureCategory::Network, $e->category);
        self::assertSame('api.activities', $e->step);
    }

    public function testNetworkErrorDuringRefreshNamesRefreshStep(): void
    {
        $this->store->tokens = new GarminTokens(Jwt::make(['exp' => self::NOW - 10]), 'r-old', 'C');
        $this->http->queue(new NetworkException('Could not resolve host: diauth.garmin.com'));

        $e = $this->failure();

        self::assertSame(FailureCategory::Network, $e->category);
        self::assertSame('refresh', $e->step);
        self::assertSame(['refresh'], $this->http->steps());
    }

    public function testWithoutPasswordLoginARejectedRefreshTokenIsMfaRequired(): void
    {
        $this->store->tokens = new GarminTokens(Jwt::make(['exp' => self::NOW - 10]), 'r-old', 'C');
        $this->http->queue(FakeHttpClient::json(400, ['error' => 'invalid_grant']));

        $e = $this->failure(false);

        self::assertSame(FailureCategory::MfaRequired, $e->category);
        self::assertSame('login.skipped', $e->step);
        self::assertSame('Account uses two-step verification, no background sign-in', $e->getMessage());
        self::assertNull($e->status);
        self::assertSame('', $e->excerpt);
        self::assertNull($e->mfaChallenge);
        self::assertSame(0, $this->logins);
        self::assertSame(['refresh'], $this->http->steps());
    }

    public function testWithoutPasswordLoginMissingTokensAreMfaRequired(): void
    {
        $e = $this->failure(false);

        self::assertSame(FailureCategory::MfaRequired, $e->category);
        self::assertSame('login.skipped', $e->step);
        self::assertSame(0, $this->logins);
        self::assertSame([], $this->http->requests);
    }

    public function testApiKeyClientFetchesFromThePersonalApi(): void
    {
        $exchanged = Jwt::make(['exp' => self::NOW + 7200, 'n' => 'exchanged']);
        $this->http->queue(FakeHttpClient::personalApiToken($exchanged), FakeHttpClient::json(200, [['activityId' => 3]]));

        $activities = $this->apiKeyClient()->activities(0, 10);

        self::assertSame([['activityId' => 3]], $activities);
        self::assertSame(['apikey.token', 'api.activities'], $this->http->steps());
        $request = $this->http->requests[1];
        self::assertStringStartsWith('https://personal.connectapi.garmin.com/activitylist-service/', $request->url);
        self::assertSame(
            'https://personal.connectapi.garmin.com/activitylist-service/activities/search/activities?start=0&limit=10',
            $request->url,
        );
        self::assertSame('Bearer ' . $exchanged, $request->headers['Authorization']);
        self::assertSame($exchanged, $this->store->tokens?->accessToken);
    }

    public function testApiKeyClientRetriesOnceWithANewTokenAfter401(): void
    {
        $this->store->tokens = new GarminTokens(Jwt::make(['exp' => self::NOW + 7200, 'n' => 'stored']), null, ApiKeyTokenService::CLIENT_ID);
        $renewed = Jwt::make(['exp' => self::NOW + 7200, 'n' => 'renewed']);
        $this->http->queue(
            new HttpResponse(401, [], ''),
            FakeHttpClient::personalApiToken($renewed),
            FakeHttpClient::json(200, [['activityId' => 9]]),
        );

        self::assertSame([['activityId' => 9]], $this->apiKeyClient()->activities(0, 10));
        self::assertSame(['api.activities', 'apikey.token', 'api.activities'], $this->http->steps());
        self::assertSame('Bearer ' . $renewed, $this->http->requests[2]->headers['Authorization']);
        self::assertSame($renewed, $this->store->tokens?->accessToken);
    }

    public function testApiKeyClientMasksTheKeyInNetworkErrors(): void
    {
        $this->store->tokens = new GarminTokens(Jwt::make(['exp' => self::NOW + 7200]), null, ApiKeyTokenService::CLIENT_ID);
        $this->http->queue(new NetworkException('reset after ' . self::API_KEY));

        try {
            $this->apiKeyClient()->activities(0, 10);
            self::fail('GarminException expected');
        } catch (GarminException $e) {
            self::assertSame(FailureCategory::Network, $e->category);
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }
    }

    private function failure(bool $passwordLogin = true): GarminException
    {
        try {
            $this->client($passwordLogin)->activities(0, 10);
        } catch (GarminException $e) {
            return $e;
        }
        self::fail('GarminException expected');
    }
}
