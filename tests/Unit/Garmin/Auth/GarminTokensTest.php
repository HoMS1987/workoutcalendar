<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Garmin\Auth;

use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Tests\Support\Jwt;
use PHPUnit\Framework\TestCase;

final class GarminTokensTest extends TestCase
{
    public function testRoundTripThroughArray(): void
    {
        $tokens = new GarminTokens('access', 'refresh', 'CLIENT');

        self::assertEquals($tokens, GarminTokens::fromArray($tokens->toArray()));
    }

    public function testReadsArrayInPythonLibraryFormat(): void
    {
        $tokens = GarminTokens::fromArray(['di_token' => 'a', 'di_refresh_token' => 'r', 'di_client_id' => 'C']);

        self::assertSame(['a', 'r', 'C'], [$tokens->accessToken, $tokens->refreshToken, $tokens->clientId]);
    }

    public function testRejectsIncompleteArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GarminTokens::fromArray(['di_token' => 'a']);
    }

    public function testExpiryComesFromJwt(): void
    {
        $tokens = new GarminTokens(Jwt::make(['exp' => 1_800_000_000]), null, 'C');

        self::assertSame(1_800_000_000, $tokens->expiresAt());
        self::assertFalse($tokens->isExpired(1_799_999_000));
        self::assertTrue($tokens->isExpired(1_799_999_950), 'Innerhalb der 60 s Vorlauf gilt der Token als abgelaufen');
    }

    public function testTokenWithoutReadableExpiryCountsAsValid(): void
    {
        self::assertFalse((new GarminTokens('kein-jwt', null, 'C'))->isExpired(PHP_INT_MAX));
    }

    public function testClientIdFromJwt(): void
    {
        self::assertSame('GARMIN_X', GarminTokens::clientIdFromJwt(Jwt::make(['client_id' => 'GARMIN_X'])));
        self::assertNull(GarminTokens::clientIdFromJwt('kein-jwt'));
    }
}
