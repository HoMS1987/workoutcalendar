<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Garmin\Diagnostics;

use OCA\WorkoutCalendar\Garmin\Diagnostics\LoggingHttpClient;
use OCA\WorkoutCalendar\Garmin\Diagnostics\Redactor;
use OCA\WorkoutCalendar\Garmin\Diagnostics\ResponseClassifier;
use OCA\WorkoutCalendar\Garmin\Http\HttpRequest;
use OCA\WorkoutCalendar\Garmin\Http\HttpResponse;
use OCA\WorkoutCalendar\Garmin\Http\NetworkException;
use OCA\WorkoutCalendar\Tests\Support\FakeHttpClient;
use OCA\WorkoutCalendar\Tests\Support\MemoryLogger;
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
            'https://sso.garmin.com/mobile/api/login?clientId=GCM_IOS_DARK&ticket=ST-123-secret',
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
        self::assertStringNotContainsString('secret', $this->logger->dump());
        self::assertStringNotContainsString('"pw"', $this->logger->dump());
    }

    public function testLogsNetworkErrorsAsDebugAndRethrows(): void
    {
        $client = $this->client(new FakeHttpClient(new NetworkException('Could not resolve host: sso.garmin.com')));

        try {
            $client->send(new HttpRequest('api.activities', 'GET', 'https://connectapi.garmin.com/x'));
            self::fail('NetworkException expected');
        } catch (NetworkException) {
        }

        self::assertSame([], $this->logger->byLevel('warning'), 'Warnings are logged, throttled, only by the sync run');
        $debug = $this->logger->byLevel('debug');
        self::assertCount(1, $debug);
        self::assertSame('NETWORK', $debug[0]['context']['category']);
        self::assertSame('api.activities', $debug[0]['context']['step']);
        self::assertStringContainsString('Could not resolve host', $debug[0]['context']['error']);
    }
}
