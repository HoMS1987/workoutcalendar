<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Service;

use OCA\WorkoutCalendar\Service\MfaRequestLimit;
use PHPUnit\Framework\TestCase;

final class MfaRequestLimitTest extends TestCase
{
    public function testWithoutRequestsTheNextOneIsAllowedNow(): void
    {
        self::assertNull(MfaRequestLimit::nextAllowedAt([], 1000));
    }

    public function testOneRequestPerMinute(): void
    {
        self::assertSame(160, MfaRequestLimit::nextAllowedAt([100], 130));
        self::assertNull(MfaRequestLimit::nextAllowedAt([100], 160));
    }

    public function testFiveRequestsPerHour(): void
    {
        $now = 10_000;
        $requests = [$now - 3000, $now - 2000, $now - 1000, $now - 500, $now - 100];

        self::assertSame($now - 3000 + 3600, MfaRequestLimit::nextAllowedAt($requests, $now));
    }

    public function testRequestsOlderThanAnHourDoNotCount(): void
    {
        $now = 10_000;
        $requests = [$now - 7200, $now - 3600, $now - 1000, $now - 500, $now - 100];

        self::assertNull(MfaRequestLimit::nextAllowedAt($requests, $now));
        self::assertSame([$now - 1000, $now - 500, $now - 100], MfaRequestLimit::recent($requests, $now));
    }
}
