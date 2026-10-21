<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Garmin\Auth;

use OCA\WorkoutCalendar\Garmin\Auth\DiTokenService;
use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Garmin\Http\HttpResponse;
use OCA\WorkoutCalendar\Tests\Support\FakeHttpClient;
use OCA\WorkoutCalendar\Tests\Support\Jwt;
use PHPUnit\Framework\TestCase;

final class DiTokenServiceTest extends TestCase
{
    public function testExchangeTriesClientIdsUntilOneWorks(): void
    {
        $access = Jwt::make(['client_id' => 'GARMIN_CONNECT_MOBILE_ANDROID_DI_2024Q4']);
        $http = new FakeHttpClient(
            FakeHttpClient::json(400, ['error' => 'invalid_client']),
            FakeHttpClient::json(200, ['access_token' => $access, 'refresh_token' => 'r1']),
        );

        $tokens = (new DiTokenService($http))->exchangeTicket('ST-1', 'https://svc', 'widget');

        self::assertSame($access, $tokens->accessToken);
        self::assertSame('r1', $tokens->refreshToken);
        self::assertSame('GARMIN_CONNECT_MOBILE_ANDROID_DI_2024Q4', $tokens->clientId);
        self::assertSame('Basic ' . base64_encode('GARMIN_CONNECT_MOBILE_ANDROID_DI_2025Q2:'), $http->requests[0]->headers['Authorization']);
        parse_str((string)$http->requests[1]->body, $form);
        self::assertSame('ST-1', $form['service_ticket']);
        self::assertSame('https://svc', $form['service_url']);
    }

    public function testExchangeFailingForAllClientIdsMeansProtocolChanged(): void
    {
        $http = new FakeHttpClient(...array_fill(0, count(DiTokenService::CLIENT_IDS), FakeHttpClient::json(400, ['error' => 'invalid_client'])));

        try {
            (new DiTokenService($http))->exchangeTicket('ST-1', 'https://svc', 'mobile');
            self::fail('GarminException expected');
        } catch (GarminException $e) {
            self::assertSame(FailureCategory::ProtocolChanged, $e->category);
            self::assertSame('mobile.di-exchange', $e->step);
        }
    }

    public function testExchangeStopsImmediatelyWhenBlocked(): void
    {
        $http = new FakeHttpClient(new HttpResponse(429, [], 'Too Many Requests'));

        try {
            (new DiTokenService($http))->exchangeTicket('ST-1', 'https://svc', 'mobile');
            self::fail('GarminException expected');
        } catch (GarminException $e) {
            self::assertSame(FailureCategory::Blocked, $e->category);
            self::assertCount(1, $http->requests);
        }
    }

    public function testRefreshKeepsOldRefreshTokenWhenNoneReturned(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::json(200, ['access_token' => 'new']));

        $tokens = (new DiTokenService($http))->refresh(new GarminTokens('old', 'r-old', 'CLIENT'));

        self::assertSame(['new', 'r-old', 'CLIENT'], [$tokens->accessToken, $tokens->refreshToken, $tokens->clientId]);
        parse_str((string)$http->requests[0]->body, $form);
        self::assertSame(['grant_type' => 'refresh_token', 'client_id' => 'CLIENT', 'refresh_token' => 'r-old'], $form);
    }

    public function testRejectedRefreshTokenIsTokenRejected(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::json(400, ['error' => 'invalid_grant']));

        try {
            (new DiTokenService($http))->refresh(new GarminTokens('old', 'r-old', 'CLIENT'));
            self::fail('GarminException expected');
        } catch (GarminException $e) {
            self::assertSame(FailureCategory::TokenRejected, $e->category);
            self::assertSame(400, $e->status);
        }
    }

    public function testRefreshWithoutRefreshTokenIsTokenRejected(): void
    {
        try {
            (new DiTokenService(new FakeHttpClient()))->refresh(new GarminTokens('old', null, 'CLIENT'));
            self::fail('GarminException expected');
        } catch (GarminException $e) {
            self::assertSame(FailureCategory::TokenRejected, $e->category);
        }
    }

    public function testRefreshResponseWithoutAccessTokenMeansProtocolChanged(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::json(200, ['token' => 'umbenannt']));

        try {
            (new DiTokenService($http))->refresh(new GarminTokens('old', 'r', 'CLIENT'));
            self::fail('GarminException expected');
        } catch (GarminException $e) {
            self::assertSame(FailureCategory::ProtocolChanged, $e->category);
        }
    }
}
