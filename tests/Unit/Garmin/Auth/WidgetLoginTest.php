<?php

declare(strict_types=1);

namespace OCA\GarminCalendar\Tests\Unit\Garmin\Auth;

use OCA\GarminCalendar\Garmin\Auth\Credentials;
use OCA\GarminCalendar\Garmin\Auth\DiTokenService;
use OCA\GarminCalendar\Garmin\Auth\WidgetLogin;
use OCA\GarminCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\GarminCalendar\Garmin\GarminException;
use OCA\GarminCalendar\Garmin\Http\HttpResponse;
use OCA\GarminCalendar\Tests\Support\FakeHttpClient;
use OCA\GarminCalendar\Tests\Support\Jwt;
use PHPUnit\Framework\TestCase;

final class WidgetLoginTest extends TestCase
{
    private const SIGNIN_URL = 'https://sso.garmin.com/sso/signin?id=gauth-widget';
    private const SIGNIN_PAGE = '<html><title>GARMIN Authentication Application</title>'
        . '<form><input type="hidden" name="_csrf" value="csrf-123"/></form></html>';
    private const SUCCESS_PAGE = '<html><title>Success</title><script>var response_url = '
        . '"https:\/\/sso.garmin.com\/sso\/embed?ticket=ST-99-abc-cas";</script></html>';

    /** @var list<int> */
    private array $sleeps = [];

    private function login(FakeHttpClient $http): WidgetLogin
    {
        return new WidgetLogin($http, new DiTokenService($http), function (int $seconds): void {
            $this->sleeps[] = $seconds;
        });
    }

    private static function page(string $html, string $url = self::SIGNIN_URL): HttpResponse
    {
        return new HttpResponse(200, ['content-type' => 'text/html'], $html, $url);
    }

    public function testSuccessfulLoginPostsCsrfAndExchangesTicket(): void
    {
        $http = new FakeHttpClient(
            self::page('<html>embed</html>'),
            self::page(self::SIGNIN_PAGE),
            self::page(self::SUCCESS_PAGE),
            FakeHttpClient::json(200, ['access_token' => Jwt::make(['client_id' => 'C']), 'refresh_token' => 'r']),
        );

        $tokens = $this->login($http)->login(new Credentials('a@b.de', 'pw'));

        self::assertSame('C', $tokens->clientId);
        self::assertSame(['widget.embed', 'widget.signin-page', 'widget.signin-post', 'widget.di-exchange'], $http->steps());
        parse_str((string)$http->requests[2]->body, $form);
        self::assertSame(['username' => 'a@b.de', 'password' => 'pw', 'embed' => 'true', '_csrf' => 'csrf-123'], $form);
        self::assertSame(self::SIGNIN_URL, $http->requests[2]->headers['Referer']);
        parse_str((string)$http->requests[3]->body, $exchange);
        self::assertSame('ST-99-abc-cas', $exchange['service_ticket']);
        self::assertSame('https://sso.garmin.com/sso/embed', $exchange['service_url']);
        self::assertCount(1, $this->sleeps);
        self::assertGreaterThanOrEqual(3, $this->sleeps[0]);
        self::assertLessThanOrEqual(8, $this->sleeps[0]);
    }

    public function testMissingCsrfMeansProtocolChanged(): void
    {
        $http = new FakeHttpClient(self::page('embed'), self::page('<html><title>Neu</title></html>'));

        $e = $this->failure($http);

        self::assertSame(FailureCategory::ProtocolChanged, $e->category);
        self::assertSame('widget.signin-page', $e->step);
        self::assertSame([], $this->sleeps, 'Ohne CSRF wird nicht gewartet und nicht gesendet');
    }

    public function testBlockedSigninPage(): void
    {
        $http = new FakeHttpClient(self::page('embed'), new HttpResponse(403, ['cf-mitigated' => 'challenge'], '<title>Just a moment...</title>'));

        $e = $this->failure($http);

        self::assertSame(FailureCategory::Blocked, $e->category);
        self::assertSame('widget.signin-page', $e->step);
    }

    public function testRepeatedSigninFormMeansInvalidCredentials(): void
    {
        $http = new FakeHttpClient(self::page('embed'), self::page(self::SIGNIN_PAGE), self::page(self::SIGNIN_PAGE));

        self::assertSame(FailureCategory::InvalidCredentials, $this->failure($http)->category);
    }

    public function testMfaPage(): void
    {
        $http = new FakeHttpClient(self::page('embed'), self::page(self::SIGNIN_PAGE), self::page('<html><title>Enter MFA code for login</title></html>'));

        self::assertSame(FailureCategory::MfaRequired, $this->failure($http)->category);
    }

    public function testSuccessWithoutTicketMeansProtocolChanged(): void
    {
        $http = new FakeHttpClient(self::page('embed'), self::page(self::SIGNIN_PAGE), self::page('<html><title>Success</title></html>'));

        $e = $this->failure($http);

        self::assertSame(FailureCategory::ProtocolChanged, $e->category);
        self::assertSame('widget.signin-post', $e->step);
    }

    public function testUnknownPageTitleIsNamedInMessage(): void
    {
        $http = new FakeHttpClient(self::page('embed'), self::page(self::SIGNIN_PAGE), self::page('<html><title>Account locked</title></html>'));

        $e = $this->failure($http);

        self::assertSame(FailureCategory::ProtocolChanged, $e->category);
        self::assertStringContainsString('Account locked', $e->getMessage());
    }

    private function failure(FakeHttpClient $http): GarminException
    {
        try {
            $this->login($http)->login(new Credentials('a@b.de', 'pw'));
        } catch (GarminException $e) {
            return $e;
        }
        self::fail('GarminException erwartet');
    }
}
