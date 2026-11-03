<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Garmin\Auth;

use OCA\WorkoutCalendar\Garmin\Auth\Credentials;
use OCA\WorkoutCalendar\Garmin\Auth\DiTokenService;
use OCA\WorkoutCalendar\Garmin\Auth\MfaChallenge;
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
        yield 'wrong password' => [FakeHttpClient::json(200, ['responseStatus' => ['type' => 'INVALID_USERNAME_PASSWORD']]), FailureCategory::InvalidCredentials];
        yield 'MFA' => [FakeHttpClient::json(200, ['responseStatus' => ['type' => 'MFA_REQUIRED']]), FailureCategory::MfaRequired];
        yield 'unknown status' => [FakeHttpClient::json(200, ['responseStatus' => ['type' => 'CAPTCHA_REQUIRED']]), FailureCategory::ProtocolChanged];
        yield 'no JSON' => [new HttpResponse(200, [], '<html>new</html>'), FailureCategory::ProtocolChanged];
        yield 'success without ticket' => [FakeHttpClient::json(200, ['responseStatus' => ['type' => 'SUCCESSFUL']]), FailureCategory::ProtocolChanged];
    }

    #[DataProvider('failures')]
    public function testClassifiesFailure(HttpResponse $response, FailureCategory $expected): void
    {
        try {
            self::login(new FakeHttpClient($response))->login(new Credentials('a@b.de', 'pw'));
            self::fail('GarminException expected');
        } catch (GarminException $e) {
            self::assertSame($expected, $e->category);
            self::assertSame('mobile.login', $e->step);
        }
    }

    private static function mfaResponse(?string $method): HttpResponse
    {
        $body = ['responseStatus' => ['type' => 'MFA_REQUIRED']];
        if ($method !== null) {
            $body['customerMfaInfo'] = ['mfaLastMethodUsed' => $method];
        }

        return FakeHttpClient::json(200, $body);
    }

    private static function verifyOk(): HttpResponse
    {
        return FakeHttpClient::json(200, ['responseStatus' => ['type' => 'SUCCESSFUL'], 'serviceTicketId' => 'ST-7']);
    }

    private static function diToken(): HttpResponse
    {
        return FakeHttpClient::json(200, ['access_token' => Jwt::make(['client_id' => 'C']), 'refresh_token' => 'r']);
    }

    public function testMfaRequiredCarriesMethodAndCookies(): void
    {
        $http = new FakeHttpClient(self::mfaResponse('sms'));
        $http->cookies = ['c1'];

        try {
            self::login($http)->login(new Credentials('a@b.de', 'pw'));
            self::fail('GarminException expected');
        } catch (GarminException $e) {
            self::assertSame(FailureCategory::MfaRequired, $e->category);
            self::assertNotNull($e->mfaChallenge);
            self::assertSame('sms', $e->mfaChallenge->method);
            self::assertSame(['c1'], $e->mfaChallenge->cookies);
        }
    }

    public function testMfaRequiredWithoutMethodHasNullMethod(): void
    {
        $http = new FakeHttpClient(self::mfaResponse(null));

        try {
            self::login($http)->login(new Credentials('a@b.de', 'pw'));
            self::fail('GarminException expected');
        } catch (GarminException $e) {
            self::assertNotNull($e->mfaChallenge);
            self::assertNull($e->mfaChallenge->method);
        }
    }

    public function testVerifyCodeSendsCodeInTheSameSession(): void
    {
        $http = new FakeHttpClient(self::verifyOk(), self::diToken());

        $tokens = self::login($http)->verifyCode(new MfaChallenge('email', ['c1']), '123456');

        self::assertSame('C', $tokens->clientId);
        self::assertSame(['c1'], $http->cookies);
        self::assertSame(['mobile.mfa-verify', 'mobile.di-exchange'], $http->steps());
        self::assertStringStartsWith('https://sso.garmin.com/mobile/api/mfa/verifyCode?clientId=GCM_IOS_DARK', $http->requests[0]->url);
        self::assertSame(
            ['mfaMethod' => 'email', 'mfaVerificationCode' => '123456', 'rememberMyBrowser' => true, 'reconsentList' => [], 'mfaSetup' => false],
            json_decode((string)$http->requests[0]->body, true),
        );
        parse_str((string)$http->requests[1]->body, $form);
        self::assertSame('ST-7', $form['service_ticket']);
        self::assertSame(MobileLogin::SERVICE_URL, $form['service_url']);
    }

    public function testVerifyCodeSendsEmailWhenMethodIsMissing(): void
    {
        $http = new FakeHttpClient(self::verifyOk(), self::diToken());

        self::login($http)->verifyCode(new MfaChallenge(null, []), '123456');

        self::assertSame('email', json_decode((string)$http->requests[0]->body, true)['mfaMethod']);
    }

    public function testVerifyCodePassesAnUnknownMethodUnchanged(): void
    {
        $http = new FakeHttpClient(self::verifyOk(), self::diToken());

        self::login($http)->verifyCode(new MfaChallenge('totp', []), '123456');

        self::assertSame('totp', json_decode((string)$http->requests[0]->body, true)['mfaMethod']);
    }

    public function testVerifyCodeFallsBackToThePortalEndpoint(): void
    {
        $http = new FakeHttpClient(new HttpResponse(429, [], ''), self::verifyOk(), self::diToken());

        self::login($http)->verifyCode(new MfaChallenge('email', []), '123456');

        self::assertSame(['mobile.mfa-verify', 'portal.mfa-verify', 'mobile.di-exchange'], $http->steps());
        self::assertStringContainsString('/portal/api/mfa/verifyCode?clientId=GarminConnect', $http->requests[1]->url);
        self::assertStringContainsString('service=https%3A%2F%2Fconnect.garmin.com%2Fapp', $http->requests[1]->url);
        parse_str((string)$http->requests[2]->body, $form);
        self::assertSame(MobileLogin::SERVICE_URL, $form['service_url']);
    }

    public function testWrongCodeOnBothEndpointsIsMfaCodeInvalid(): void
    {
        $wrong = FakeHttpClient::json(200, ['responseStatus' => ['type' => 'MFA_CODE_INVALID']]);
        $http = new FakeHttpClient($wrong, $wrong);

        try {
            self::login($http)->verifyCode(new MfaChallenge('email', []), '000000');
            self::fail('GarminException expected');
        } catch (GarminException $e) {
            self::assertSame(FailureCategory::MfaCodeInvalid, $e->category);
            self::assertSame('portal.mfa-verify', $e->step);
        }
    }

    public function testBlockedOnBothEndpointsIsBlocked(): void
    {
        $http = new FakeHttpClient(new HttpResponse(429, [], ''), new HttpResponse(429, [], ''));

        try {
            self::login($http)->verifyCode(new MfaChallenge('email', []), '123456');
            self::fail('GarminException expected');
        } catch (GarminException $e) {
            self::assertSame(FailureCategory::Blocked, $e->category);
        }
    }

    public function testExportCookiesDelegatesToTheHttpClient(): void
    {
        $http = new FakeHttpClient();
        $http->cookies = ['c9'];

        self::assertSame(['c9'], self::login($http)->exportCookies());
    }

    public function testUnknownStatusTypeIsNamedInMessage(): void
    {
        try {
            self::login(new FakeHttpClient(FakeHttpClient::json(200, ['responseStatus' => ['type' => 'CAPTCHA_REQUIRED']])))
                ->login(new Credentials('a@b.de', 'pw'));
            self::fail('GarminException expected');
        } catch (GarminException $e) {
            self::assertStringContainsString('CAPTCHA_REQUIRED', $e->getMessage());
        }
    }
}
