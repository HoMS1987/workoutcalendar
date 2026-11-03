<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Garmin\Auth;

use OCA\WorkoutCalendar\Garmin\Auth\MfaChallenge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MfaChallengeTest extends TestCase
{
    public function testRoundTripsThroughAnArray(): void
    {
        $challenge = new MfaChallenge('sms', ['c1', 'c2']);

        $copy = MfaChallenge::fromArray($challenge->toArray());

        self::assertSame('sms', $copy->method);
        self::assertSame(['c1', 'c2'], $copy->cookies);
    }

    public function testNullMethodSurvivesTheRoundTrip(): void
    {
        self::assertNull(MfaChallenge::fromArray((new MfaChallenge(null, []))->toArray())->method);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalid(): iterable
    {
        yield 'method is a number' => [['method' => 5, 'cookies' => []]];
        yield 'cookies missing' => [['method' => 'sms']];
        yield 'cookies not a list' => [['method' => 'sms', 'cookies' => ['a' => 'x']]];
        yield 'cookie is not a string' => [['method' => 'sms', 'cookies' => [1]]];
    }

    /** @param array<mixed> $data */
    #[DataProvider('invalid')]
    public function testRejectsMalformedData(array $data): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MfaChallenge::fromArray($data);
    }
}
