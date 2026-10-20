<?php

declare(strict_types=1);

namespace OCA\GarminCalendar\Tests\Unit\Garmin\Diagnostics;

use OCA\GarminCalendar\Garmin\Diagnostics\LoggingHttpClient;
use OCA\GarminCalendar\Garmin\Diagnostics\Redactor;
use OCA\GarminCalendar\Garmin\Diagnostics\ResponseClassifier;
use OCA\GarminCalendar\Garmin\Http\HttpRequest;
use OCA\GarminCalendar\Garmin\Http\HttpResponse;
use OCA\GarminCalendar\Garmin\Http\NetworkException;
use OCA\GarminCalendar\Tests\Support\FakeHttpClient;
use OCA\GarminCalendar\Tests\Support\MemoryLogger;
use PHPUnit\Framework\TestCase;

final class LoggingHttpClientTest extends TestCase
{
    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
    }

    private function client(FakeHttpClient $inner): LoggingHttpClient
    {
        return new LoggingHttpClient($inner, $this->logger, new ResponseClassifier(), new Redactor());
    }

    public function testLogsEachRequestWithoutQueryOrHeaders(): void
    {
        $response = new HttpResponse(403, ['cf-ray' => 'abc-FRA', 'server' => 'cloudflare'], 'Forbidden');
        $client = $this->client(new FakeHttpClient($response));

        $result = $client->send(new HttpRequest(
            'mobile.login',
            'POST',
            'https://sso.garmin.com/mobile/api/login?clientId=GCM_IOS_DARK&ticket=ST-123-geheim',
            ['Authorization' => 'Bearer geheimer-token'],
            '{"password":"pw"}',
        ));

        self::assertSame($response, $result);
        $debug = $this->logger->byLevel('debug');
        self::assertCount(1, $debug);
        $context = $debug[0]['context'];
        self::assertSame('mobile.login', $context['step']);
        self::assertSame('POST', $context['method']);
        self::assertSame('sso.garmin.com/mobile/api/login', $context['endpoint']);
        self::assertSame(403, $context['status']);
        self::assertSame(['cf-ray' => 'abc-FRA', 'server' => 'cloudflare'], $context['cloudflare']);
        self::assertIsInt($context['durationMs']);
        self::assertStringNotContainsString('geheim', $this->logger->dump());
        self::assertStringNotContainsString('"pw"', $this->logger->dump());
    }

    public function testLogsNetworkErrorsAsWarningAndRethrows(): void
    {
        $client = $this->client(new FakeHttpClient(new NetworkException('Could not resolve host: sso.garmin.com')));

        try {
            $client->send(new HttpRequest('api.activities', 'GET', 'https://connectapi.garmin.com/x'));
            self::fail('NetworkException erwartet');
        } catch (NetworkException) {
        }

        $warnings = $this->logger->byLevel('warning');
        self::assertCount(1, $warnings);
        self::assertSame('NETWORK', $warnings[0]['context']['category']);
        self::assertSame('api.activities', $warnings[0]['context']['step']);
        self::assertStringContainsString('Could not resolve host', $warnings[0]['context']['error']);
    }
}
