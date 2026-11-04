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
        $http = new FakeHttpClient(FakeHttpClient::personalApiToken($jwt));

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

    public function testDeletedKeyAnsweredWith404NotFoundIsApiKeyRejected(): void
    {
        // Recorded on 2026-11-04 after deleting a key in Garmin Connect.
        $body = '{"message":"Personal API Key with ID 09efac17-40c6-407f-889f-c0c55677d3c5 does not exist.","error":"NotFoundException"}';
        $response = new HttpResponse(404, ['content-type' => 'application/json', 'cf-ray' => '8f1e2d3c4b5a6978-FRA', 'server' => 'cloudflare'], $body);

        $e = $this->exchangeFailure(new FakeHttpClient($response));

        self::assertSame(FailureCategory::ApiKeyRejected, $e->category);
        self::assertSame(404, $e->status);
    }

    public function testCloudflare403IsBlockedNotARejectedKey(): void
    {
        $response = new HttpResponse(403, ['cf-mitigated' => 'challenge', 'server' => 'cloudflare'], '<title>Just a moment...</title>');

        $e = $this->exchangeFailure(new FakeHttpClient($response));

        self::assertSame(FailureCategory::Blocked, $e->category);
        self::assertSame('apikey.token', $e->step);
        self::assertSame(403, $e->status);
    }

    public function testChallengePageWithoutCfMitigatedIsBlocked(): void
    {
        $response = new HttpResponse(403, ['cf-ray' => '8f1e2d3c4b5a6978-FRA'], '<html><title>Attention Required! | Cloudflare</title></html>');

        self::assertSame(FailureCategory::Blocked, $this->exchangeFailure(new FakeHttpClient($response))->category);
    }

    public function testRejection403PassingThroughCloudflareIsApiKeyRejected(): void
    {
        // Every response of a host behind Cloudflare carries cf-ray and server: cloudflare; only a challenge is a block.
        $response = new HttpResponse(403, ['cf-ray' => '8f1e2d3c4b5a6978-FRA', 'server' => 'cloudflare'], '{"message":"Invalid API key"}');

        self::assertSame(FailureCategory::ApiKeyRejected, $this->exchangeFailure(new FakeHttpClient($response))->category);
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

    public function testTokenSentAsPlainTextWithSurroundingWhitespaceIsRead(): void
    {
        $jwt = Jwt::make(['exp' => 2000000000]);

        $tokens = $this->service(new FakeHttpClient(new HttpResponse(200, ['content-type' => 'text/plain'], "
 $jwt 
")))->exchange('k-1');

        self::assertSame($jwt, $tokens->accessToken);
    }

    /** @return array<string, array{string}> */
    public static function bodiesWithoutAToken(): array
    {
        return [
            'empty' => [''],
            'json object' => ['{"access_token":"' . Jwt::make(['exp' => 2000000000]) . '"}'],
            'html page' => ['<html><title>Garmin</title></html>'],
            'jwt without exp' => [Jwt::make(['sub' => 'x'])],
        ];
    }

    #[DataProvider('bodiesWithoutAToken')]
    public function testBodyThatIsNotAJwtWithExpIsProtocolChanged(string $body): void
    {
        $e = $this->exchangeFailure(new FakeHttpClient(new HttpResponse(200, ['content-type' => 'text/plain'], $body)));

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
        yield 'account service login' => [AccountService::class, 'login', 'password'];
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
