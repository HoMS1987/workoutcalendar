<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Garmin\Diagnostics;

use OCA\WorkoutCalendar\Garmin\Diagnostics\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function secrets(): iterable
    {
        yield 'JSON password' => ['{"username":"x","password":"secret123"}', 'secret123'];
        yield 'form password' => ['username=x&password=secret123&embed=true', 'secret123'];
        yield 'access token' => ['{"access_token":"abc.def.ghi","expires_in":3600}', 'abc.def.ghi'];
        yield 'refresh token' => ['{"refresh_token":"r3fr3sh"}', 'r3fr3sh'];
        yield 'service ticket' => ['<a href="https://sso.garmin.com/sso/embed?ticket=ST-0815-abc-cas">', 'ST-0815-abc-cas'];
        yield 'ticket in JSON' => ['{"serviceTicketId":"ST-4711-xyz"}', 'ST-4711-xyz'];
        yield 'CSRF value' => ['<input type="hidden" name="_csrf" value="c5rf-v4lu3"/>', 'c5rf-v4lu3'];
        yield 'Bearer header' => ['Authorization: Bearer eyJabc.eyJdef.sig', 'eyJabc.eyJdef.sig'];
        yield 'Basic header' => ['Authorization: Basic R0FSTUlOOg==', 'R0FSTUlOOg=='];
        yield 'standalone JWT' => ['token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXI and more', 'eyJhbGciOiJIUzI1NiJ9'];
        yield 'email address' => ['{"username":"max.muster@example.org"}', 'max.muster@example.org'];
    }

    #[DataProvider('secrets')]
    public function testRemovesSecret(string $input, string $secret): void
    {
        $result = (new Redactor())->redact($input);

        self::assertStringNotContainsString($secret, $result);
        self::assertStringContainsString('***', $result);
    }

    public function testKeepsHarmlessTextIncludingUmlauts(): void
    {
        $text = '{"responseStatus":{"type":"INVALID_USERNAME_PASSWORD"},"message":"Ungültige Anmeldung"}';

        self::assertSame($text, (new Redactor())->redact($text));
    }

    public function testRedactsKnownSecretValuesEverywhere(): void
    {
        $redactor = new Redactor(['s3cr3t-pw']);

        self::assertSame('Error at *** in the text', $redactor->redact('Error at s3cr3t-pw in the text'));
    }

    public function testExcerptIsShortenedAfterRedaction(): void
    {
        $long = '{"password":"secret"}' . str_repeat('ä', 500);

        $excerpt = (new Redactor())->excerpt($long, 50);

        self::assertStringNotContainsString('secret', $excerpt);
        self::assertSame(51, mb_strlen($excerpt));
        self::assertStringEndsWith('…', $excerpt);
        self::assertTrue(mb_check_encoding($excerpt, 'UTF-8'));
    }
}
