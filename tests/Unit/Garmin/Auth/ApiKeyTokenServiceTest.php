<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Garmin\Auth;

use OCA\WorkoutCalendar\Controller\ApiController;
use OCA\WorkoutCalendar\Garmin\Auth\ApiKeyTokenProvider;
use OCA\WorkoutCalendar\Garmin\Auth\ApiKeyTokenService;
use OCA\WorkoutCalendar\Service\AccountService;
use OCA\WorkoutCalendar\Service\GarminClientFactory;
use OCA\WorkoutCalendar\Storage\ApiKeyRecord;
use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\Diagnostics\Redactor;
use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Garmin\Http\HttpResponse;
use OCA\WorkoutCalendar\Garmin\Http\NetworkException;
use OCA\WorkoutCalendar\Tests\Support\FakeHttpClient;
use OCA\WorkoutCalendar\Tests\Support\Jwt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiKeyTokenServiceTest extends TestCase
{
    private function service(FakeHttpClient $http, string $key = 'k-1'): ApiKeyTokenService
    {
        return new ApiKeyTokenService($http, new Redactor([$key]));
    }

    private function exchangeFailure(FakeHttpClient $http, string $key = 'k-1'): GarminException
    {
        try {
            $this->service($http, $key)->exchange($key);
        } catch (GarminException $e) {
            return $e;
        }
        self::fail('GarminException expected');
    }

    public function testExchangeSendsTheKeyAsApiKeyHeader(): void
    {
        $jwt = Jwt::make(['exp' => 2000000000]);
        $http = new FakeHttpClient(FakeHttpClient::json(200, ['access_token' => $jwt]));

        $tokens = $this->service($http)->exchange('k-1');

        $request = $http->requests[0];
        self::assertSame('POST', $request->method);
        self::assertSame('https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token', $request->url);
        self::assertSame('apikey.token', $request->step);
        self::assertSame('ApiKey k-1', $request->headers['Authorization']);
        self::assertSame('*/*', $request->headers['Accept']);
        self::assertSame($jwt, $tokens->accessToken);
        self::assertNull($tokens->refreshToken);
        self::assertSame('personal-api', $tokens->clientId);
    }

    /** @return array<string, array{int}> */
    public static function rejectedStatuses(): array
    {
        return ['401' => [401], '403' => [403]];
    }

    #[DataProvider('rejectedStatuses')]
    public function testRejectedKeyIsApiKeyRejected(int $status): void
    {
        $e = $this->exchangeFailure(new FakeHttpClient(new HttpResponse($status, [], 'denied')));

        self::assertSame(FailureCategory::ApiKeyRejected, $e->category);
        self::assertSame('apikey.token', $e->step);
        self::assertSame($status, $e->status);
    }

    public function testCloudflare403IsBlockedNotARejectedKey(): void
    {
        $response = new HttpResponse(403, ['cf-mitigated' => 'challenge', 'server' => 'cloudflare'], '<title>Just a moment...</title>');

        $e = $this->exchangeFailure(new FakeHttpClient($response));

        self::assertSame(FailureCategory::Blocked, $e->category);
        self::assertSame('apikey.token', $e->step);
        self::assertSame(403, $e->status);
    }

    public function testRejectedResponseExcerptIsRedacted(): void
    {
        $key = 'secret-key-9f8e7d';
        $e = $this->exchangeFailure(new FakeHttpClient(new HttpResponse(401, [], "{\"error\":\"unknown key $key\"}")), $key);

        self::assertSame(FailureCategory::ApiKeyRejected, $e->category);
        self::assertStringNotContainsString($key, $e->excerpt);
        self::assertStringContainsString('unknown key', $e->excerpt);
    }

    /** @return array<string, array{HttpResponse|\Throwable, FailureCategory}> */
    public static function otherFailures(): array
    {
        return [
            '429' => [new HttpResponse(429, [], 'slow down'), FailureCategory::Blocked],
            '503' => [new HttpResponse(503, [], 'down'), FailureCategory::ServerError],
            '404' => [new HttpResponse(404, [], 'gone'), FailureCategory::ProtocolChanged],
            'network' => [new NetworkException('Could not resolve host'), FailureCategory::Network],
        ];
    }

    #[DataProvider('otherFailures')]
    public function testOtherFailuresKeepTheirCategory(HttpResponse|\Throwable $reply, FailureCategory $expected): void
    {
        $e = $this->exchangeFailure(new FakeHttpClient($reply));

        self::assertSame($expected, $e->category);
        self::assertSame('apikey.token', $e->step);
    }

    public function testResponseWithoutAccessTokenIsProtocolChanged(): void
    {
        $e = $this->exchangeFailure(new FakeHttpClient(FakeHttpClient::json(200, ['other' => 'x'])));

        self::assertSame(FailureCategory::ProtocolChanged, $e->category);
        self::assertSame('apikey.token', $e->step);
    }

    public function testKeyNeverAppearsInTheException(): void
    {
        $key = 'secret-key-9f8e7d';
        $http = new FakeHttpClient(new HttpResponse(401, [], "Invalid key $key"));

        $e = $this->exchangeFailure($http, $key);
        self::assertStringNotContainsString($key, $e->getMessage());
        self::assertStringNotContainsString($key, $e->excerpt);

        $network = $this->exchangeFailure(new FakeHttpClient(new NetworkException("failed for $key")), $key);
        self::assertSame(FailureCategory::Network, $network->category);
        self::assertStringNotContainsString($key, $network->getMessage());
        self::assertStringNotContainsString($key, $network->excerpt);
        // The original NetworkException carries the unredacted text, so it must not stay in the chain.
        self::assertNull($network->getPrevious());
    }

    public function testEmptyAccessTokenIsProtocolChanged(): void
    {
        $e = $this->exchangeFailure(new FakeHttpClient(FakeHttpClient::json(200, ['access_token' => ''])));

        self::assertSame(FailureCategory::ProtocolChanged, $e->category);
    }

    public function testGarminUserIdReadsTheFirstKnownClaim(): void
    {
        $tokens = new GarminTokens(Jwt::make(['sub' => 'sub-1', 'user_id' => '', 'userProfilePK' => 'pk-7']), null, 'personal-api');

        self::assertSame('pk-7', ApiKeyTokenService::garminUserId($tokens));

        $first = new GarminTokens(Jwt::make(['garmin_guid' => 'guid-1', 'sub' => 'sub-1']), null, 'personal-api');
        self::assertSame('guid-1', ApiKeyTokenService::garminUserId($first));
    }

    public function testGarminUserIdAcceptsANumericUserProfilePk(): void
    {
        $tokens = new GarminTokens(Jwt::make(['userProfilePK' => 81234567, 'sub' => 'sub-1']), null, 'personal-api');

        self::assertSame('81234567', ApiKeyTokenService::garminUserId($tokens));
    }

    public function testGarminUserIdIgnoresSubBecauseItMayChangeWithEveryKey(): void
    {
        $tokens = new GarminTokens(Jwt::make(['sub' => 'key-subject-1', 'exp' => 1]), null, 'personal-api');

        self::assertNull(ApiKeyTokenService::garminUserId($tokens));
    }

    public function testGarminUserIdWithoutClaimsIsNull(): void
    {
        $tokens = new GarminTokens(Jwt::make(['exp' => 1, 'user_id' => ['nested'], 'userProfilePK' => true]), null, 'personal-api');

        self::assertNull(ApiKeyTokenService::garminUserId($tokens));
        self::assertNull(ApiKeyTokenService::garminUserId(new GarminTokens('not-a-jwt', null, 'personal-api')));
    }

    /** @return iterable<string, array{class-string, string, string}> */
    public static function keyParameters(): iterable
    {
        yield 'connectApiKey' => [AccountService::class, 'connectApiKey', 'apiKey'];
        yield 'apiKeyTokens' => [GarminClientFactory::class, 'apiKeyTokens', 'apiKey'];
        yield 'apiKeyClient' => [GarminClientFactory::class, 'apiKeyClient', 'apiKey'];
        yield 'exchange' => [ApiKeyTokenService::class, 'exchange', 'apiKey'];
        yield 'token provider' => [ApiKeyTokenProvider::class, '__construct', 'apiKey'];
        yield 'record' => [ApiKeyRecord::class, '__construct', 'key'];
        yield 'controller apiKey' => [ApiController::class, 'apiKey', 'apiKey'];
        // The password is just as secret as the key.
        yield 'controller login' => [ApiController::class, 'login', 'password'];
    }

    /** @param class-string $class */
    #[DataProvider('keyParameters')]
    public function testKeyIsASensitiveParameter(string $class, string $method, string $parameter): void
    {
        $parameters = (new \ReflectionMethod($class, $method))->getParameters();
        $found = array_values(array_filter($parameters, static fn (\ReflectionParameter $p): bool => $p->getName() === $parameter));

        self::assertCount(1, $found);
        self::assertCount(1, $found[0]->getAttributes(\SensitiveParameter::class), "{$class}::{$method}(\${$parameter})");
    }
}
