<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Garmin\Auth;

use OCA\WorkoutCalendar\Garmin\Auth\ApiKeyTokenProvider;
use OCA\WorkoutCalendar\Garmin\Auth\ApiKeyTokenService;
use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Garmin\Diagnostics\Redactor;
use OCA\WorkoutCalendar\Tests\Support\FakeHttpClient;
use OCA\WorkoutCalendar\Tests\Support\Jwt;
use OCA\WorkoutCalendar\Tests\Support\MemoryTokenStore;
use PHPUnit\Framework\TestCase;

final class ApiKeyTokenProviderTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const KEY = 'personal-key-4711';

    private FakeHttpClient $http;
    private MemoryTokenStore $store;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->store = new MemoryTokenStore();
    }

    private function provider(): ApiKeyTokenProvider
    {
        return new ApiKeyTokenProvider(
            new ApiKeyTokenService($this->http, new Redactor([self::KEY])),
            $this->store,
            self::KEY,
            static fn (): int => self::NOW,
        );
    }

    private static function tokens(?int $exp, string $marker = 'stored'): GarminTokens
    {
        $claims = $exp !== null ? ['exp' => $exp, 'n' => $marker] : ['n' => $marker];

        return new GarminTokens(Jwt::make($claims), null, ApiKeyTokenService::CLIENT_ID);
    }

    private function queueExchange(string $jwt): void
    {
        $this->http->queue(FakeHttpClient::json(200, ['access_token' => $jwt]));
    }

    public function testUsesAStoredTokenThatLastsMoreThanAnHour(): void
    {
        $stored = self::tokens(self::NOW + 3600);
        $this->store->tokens = $stored;

        self::assertSame($stored, $this->provider()->current());
        self::assertSame([], $this->http->requests);
        self::assertSame(0, $this->store->saves);
    }

    public function testExchangesWhenTheTokenExpiresWithinAnHour(): void
    {
        $this->store->tokens = self::tokens(self::NOW + 3599);
        $fresh = Jwt::make(['exp' => self::NOW + 7200, 'n' => 'fresh']);
        $this->queueExchange($fresh);

        $tokens = $this->provider()->current();

        self::assertSame($fresh, $tokens->accessToken);
        self::assertSame(['apikey.token'], $this->http->steps());
        self::assertSame('ApiKey ' . self::KEY, $this->http->requests[0]->headers['Authorization']);
        self::assertSame($fresh, $this->store->tokens?->accessToken);
        self::assertSame(1, $this->store->saves);
    }

    public function testExchangesWithoutStoredToken(): void
    {
        $fresh = Jwt::make(['exp' => self::NOW + 7200]);
        $this->queueExchange($fresh);

        self::assertSame($fresh, $this->provider()->current()->accessToken);
        self::assertSame(['apikey.token'], $this->http->steps());
        self::assertSame($fresh, $this->store->tokens?->accessToken);
    }

    public function testExchangesWhenTheStoredTokenHasNoExpiry(): void
    {
        $this->store->tokens = self::tokens(null);
        $fresh = Jwt::make(['exp' => self::NOW + 7200]);
        $this->queueExchange($fresh);

        self::assertSame($fresh, $this->provider()->current()->accessToken);
        self::assertSame(['apikey.token'], $this->http->steps());
    }

    public function testExchangesWhenTheStoredTokenBelongsToThePasswordWay(): void
    {
        // A DI token left over from an interrupted switch: it is not valid at the personal API.
        $this->store->tokens = new GarminTokens(Jwt::make(['exp' => self::NOW + 86_400]), 'r', 'GARMIN_CONNECT_MOBILE_ANDROID_DI');
        $fresh = Jwt::make(['exp' => self::NOW + 7200, 'n' => 'fresh']);
        $this->queueExchange($fresh);

        $tokens = $this->provider()->current();

        self::assertSame($fresh, $tokens->accessToken);
        self::assertSame(ApiKeyTokenService::CLIENT_ID, $tokens->clientId);
        self::assertSame(['apikey.token'], $this->http->steps());
        self::assertSame($fresh, $this->store->tokens?->accessToken);
    }

    public function testRenewAlwaysExchanges(): void
    {
        $stored = self::tokens(self::NOW + 7200);
        $this->store->tokens = $stored;
        $fresh = Jwt::make(['exp' => self::NOW + 7200, 'n' => 'renewed']);
        $this->queueExchange($fresh);

        $tokens = $this->provider()->renew($stored);

        self::assertSame($fresh, $tokens->accessToken);
        self::assertSame(['apikey.token'], $this->http->steps());
        self::assertSame($fresh, $this->store->tokens?->accessToken);
        self::assertSame(1, $this->store->saves);
    }
}
