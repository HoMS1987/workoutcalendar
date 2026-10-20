<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Garmin\Auth;

use OCA\WorkoutCalendar\Garmin\Auth\Credentials;
use OCA\WorkoutCalendar\Garmin\Auth\DiTokenService;
use OCA\WorkoutCalendar\Garmin\Auth\MobileLogin;
use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Garmin\Http\HttpResponse;
use OCA\WorkoutCalendar\Tests\Support\FakeHttpClient;
use OCA\WorkoutCalendar\Tests\Support\Jwt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MobileLoginTest extends TestCase
{
    private static function login(FakeHttpClient $http): MobileLogin
    {
        return new MobileLogin($http, new DiTokenService($http));
    }

    public function testSuccessfulLoginExchangesTicket(): void
    {
        $http = new FakeHttpClient(
            FakeHttpClient::json(200, ['responseStatus' => ['type' => 'SUCCESSFUL'], 'serviceTicketId' => 'ST-42']),
            FakeHttpClient::json(200, ['access_token' => Jwt::make(['client_id' => 'C']), 'refresh_token' => 'r']),
        );

        $tokens = self::login($http)->login(new Credentials('a@b.de', 'pw'));

        self::assertSame('C', $tokens->clientId);
        self::assertSame(['mobile.login', 'mobile.di-exchange'], $http->steps());
        self::assertStringStartsWith('https://sso.garmin.com/mobile/api/login?clientId=GCM_IOS_DARK', $http->requests[0]->url);
        self::assertSame(
            ['username' => 'a@b.de', 'password' => 'pw', 'rememberMe' => true, 'captchaToken' => ''],
            json_decode((string)$http->requests[0]->body, true),
        );
        parse_str((string)$http->requests[1]->body, $form);
        self::assertSame('ST-42', $form['service_ticket']);
        self::assertSame(MobileLogin::SERVICE_URL, $form['service_url']);
    }

    /** @return iterable<string, array{HttpResponse, FailureCategory}> */
    public static function failures(): iterable
    {
        yield 'Rate-Limit' => [new HttpResponse(429, [], ''), FailureCategory::Blocked];
        yield 'Cloudflare' => [new HttpResponse(403, ['cf-mitigated' => 'challenge'], '<title>Just a moment...</title>'), FailureCategory::Blocked];
        yield 'falsches Passwort' => [FakeHttpClient::json(200, ['responseStatus' => ['type' => 'INVALID_USERNAME_PASSWORD']]), FailureCategory::InvalidCredentials];
        yield 'MFA' => [FakeHttpClient::json(200, ['responseStatus' => ['type' => 'MFA_REQUIRED']]), FailureCategory::MfaRequired];
        yield 'unbekannter Status' => [FakeHttpClient::json(200, ['responseStatus' => ['type' => 'CAPTCHA_REQUIRED']]), FailureCategory::ProtocolChanged];
        yield 'kein JSON' => [new HttpResponse(200, [], '<html>neu</html>'), FailureCategory::ProtocolChanged];
        yield 'Erfolg ohne Ticket' => [FakeHttpClient::json(200, ['responseStatus' => ['type' => 'SUCCESSFUL']]), FailureCategory::ProtocolChanged];
    }

    #[DataProvider('failures')]
    public function testClassifiesFailure(HttpResponse $response, FailureCategory $expected): void
    {
        try {
            self::login(new FakeHttpClient($response))->login(new Credentials('a@b.de', 'pw'));
            self::fail('GarminException erwartet');
        } catch (GarminException $e) {
            self::assertSame($expected, $e->category);
            self::assertSame('mobile.login', $e->step);
        }
    }

    public function testUnknownStatusTypeIsNamedInMessage(): void
    {
        try {
            self::login(new FakeHttpClient(FakeHttpClient::json(200, ['responseStatus' => ['type' => 'CAPTCHA_REQUIRED']])))
                ->login(new Credentials('a@b.de', 'pw'));
            self::fail('GarminException erwartet');
        } catch (GarminException $e) {
            self::assertStringContainsString('CAPTCHA_REQUIRED', $e->getMessage());
        }
    }
}
