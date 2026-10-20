<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Garmin\Diagnostics;

use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\Diagnostics\ResponseClassifier;
use OCA\WorkoutCalendar\Garmin\Http\HttpResponse;
use PHPUnit\Framework\TestCase;

final class ResponseClassifierTest extends TestCase
{
    private ResponseClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new ResponseClassifier();
    }

    public function testSuccessIsNoFailure(): void
    {
        self::assertNull($this->classifier->classify(new HttpResponse(200, [], '[]')));
    }

    public function testRateLimitIsBlocked(): void
    {
        self::assertSame(FailureCategory::Blocked, $this->classifier->classify(new HttpResponse(429, [], '')));
    }

    public function testCloudflareChallengeIsBlocked(): void
    {
        $response = new HttpResponse(403, ['cf-mitigated' => 'challenge', 'server' => 'cloudflare'], '<title>Just a moment...</title>');

        self::assertSame(FailureCategory::Blocked, $this->classifier->classify($response));
    }

    public function testPlain403IsBlocked(): void
    {
        self::assertSame(FailureCategory::Blocked, $this->classifier->classify(new HttpResponse(403, [], 'Forbidden')));
    }

    public function testUnauthorizedIsTokenRejected(): void
    {
        self::assertSame(FailureCategory::TokenRejected, $this->classifier->classify(new HttpResponse(401, [], '')));
    }

    public function testServerErrorIsServerError(): void
    {
        self::assertSame(FailureCategory::ServerError, $this->classifier->classify(new HttpResponse(503, [], '')));
    }

    public function testOtherClientErrorMeansProtocolChanged(): void
    {
        self::assertSame(FailureCategory::ProtocolChanged, $this->classifier->classify(new HttpResponse(404, [], '')));
    }

    public function testCollectsCloudflareMarkers(): void
    {
        $response = new HttpResponse(
            403,
            ['cf-ray' => '8f00abc-FRA', 'cf-mitigated' => 'challenge', 'server' => 'cloudflare'],
            '<html><title>Just a moment...</title></html>',
        );

        self::assertSame(
            ['cf-ray' => '8f00abc-FRA', 'cf-mitigated' => 'challenge', 'server' => 'cloudflare', 'challenge-page' => 'Just a moment...'],
            $this->classifier->cloudflareMarkers($response),
        );
    }

    public function testNoMarkersWithoutCloudflare(): void
    {
        self::assertSame([], $this->classifier->cloudflareMarkers(new HttpResponse(200, ['server' => 'nginx'], '{}')));
    }
}
