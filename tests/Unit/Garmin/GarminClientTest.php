<?php

declare(strict_types=1);

namespace OCA\GarminCalendar\Tests\Unit\Garmin;

use OCA\GarminCalendar\Garmin\Auth\Authenticator;
use OCA\GarminCalendar\Garmin\Auth\Credentials;
use OCA\GarminCalendar\Garmin\Auth\DiTokenService;
use OCA\GarminCalendar\Garmin\Auth\GarminTokens;
use OCA\GarminCalendar\Garmin\Auth\LoginStrategy;
use OCA\GarminCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\GarminCalendar\Garmin\GarminClient;
use OCA\GarminCalendar\Garmin\GarminException;
use OCA\GarminCalendar\Garmin\Http\HttpResponse;
use OCA\GarminCalendar\Tests\Support\FakeHttpClient;
use OCA\GarminCalendar\Tests\Support\Jwt;
use OCA\GarminCalendar\Tests\Support\MemoryLogger;
use OCA\GarminCalendar\Tests\Support\MemoryTokenStore;
use PHPUnit\Framework\TestCase;

final class GarminClientTest extends TestCase
{
    private const NOW = 1_800_000_000;

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

    private function client(): GarminClient
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

        return new GarminClient(
            $this->http,
            new Authenticator([$strategy], $this->logger),
            new DiTokenService($this->http),
            $this->store,
            new Credentials('a@b.de', 'pw'),
            $this->logger,
            static fn (): int => self::NOW,
        );
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
        $this->store->tokens = new GarminTokens(Jwt::make(['exp' => self::NOW - 10]), 'r-alt', 'C');
        $fresh = Jwt::make(['exp' => self::validExp(), 'fresh' => true]);
        $this->http->queue(FakeHttpClient::json(200, ['access_token' => $fresh, 'refresh_token' => 'r-neu']), FakeHttpClient::json(200, []));

        $this->client()->activities(0, 10);

        self::assertSame(['refresh', 'api.activities'], $this->http->steps());
        self::assertSame($fresh, $this->store->tokens?->accessToken);
        self::assertSame('r-neu', $this->store->tokens?->refreshToken);
        self::assertSame(0, $this->logins);
    }

    public function testLogsInAgainWhenRefreshTokenIsRejected(): void
    {
        $this->store->tokens = new GarminTokens(Jwt::make(['exp' => self::NOW - 10]), 'r-alt', 'C');
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

    private function failure(): GarminException
    {
        try {
            $this->client()->activities(0, 10);
        } catch (GarminException $e) {
            return $e;
        }
        self::fail('GarminException erwartet');
    }
}
