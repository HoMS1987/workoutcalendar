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
        yield 'JSON-Passwort' => ['{"username":"x","password":"geheim123"}', 'geheim123'];
        yield 'Formular-Passwort' => ['username=x&password=geheim123&embed=true', 'geheim123'];
        yield 'Access-Token' => ['{"access_token":"abc.def.ghi","expires_in":3600}', 'abc.def.ghi'];
        yield 'Refresh-Token' => ['{"refresh_token":"r3fr3sh"}', 'r3fr3sh'];
        yield 'Service-Ticket' => ['<a href="https://sso.garmin.com/sso/embed?ticket=ST-0815-abc-cas">', 'ST-0815-abc-cas'];
        yield 'Ticket im JSON' => ['{"serviceTicketId":"ST-4711-xyz"}', 'ST-4711-xyz'];
        yield 'CSRF-Wert' => ['<input type="hidden" name="_csrf" value="c5rf-W3rt"/>', 'c5rf-W3rt'];
        yield 'Bearer-Header' => ['Authorization: Bearer eyJabc.eyJdef.sig', 'eyJabc.eyJdef.sig'];
        yield 'Basic-Header' => ['Authorization: Basic R0FSTUlOOg==', 'R0FSTUlOOg=='];
        yield 'freistehendes JWT' => ['token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXI und mehr', 'eyJhbGciOiJIUzI1NiJ9'];
        yield 'E-Mail-Adresse' => ['{"username":"max.muster@example.org"}', 'max.muster@example.org'];
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

        self::assertSame('Fehler bei *** im Text', $redactor->redact('Fehler bei s3cr3t-pw im Text'));
    }

    public function testExcerptIsShortenedAfterRedaction(): void
    {
        $long = '{"password":"geheim"}' . str_repeat('ä', 500);

        $excerpt = (new Redactor())->excerpt($long, 50);

        self::assertStringNotContainsString('geheim', $excerpt);
        self::assertSame(51, mb_strlen($excerpt));
        self::assertStringEndsWith('…', $excerpt);
        self::assertTrue(mb_check_encoding($excerpt, 'UTF-8'));
    }
}
