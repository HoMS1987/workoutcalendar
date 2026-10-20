<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Calendar;

use OCA\WorkoutCalendar\Calendar\CalendarEvent;
use OCA\WorkoutCalendar\Calendar\IcsSerializer;
use PHPUnit\Framework\TestCase;

final class IcsSerializerTest extends TestCase
{
    private static function event(string $summary = '🏃 Laufen – 7,6 km', string $description = "Dauer: 42:10 min\nDistanz: 7,60 km"): CalendarEvent
    {
        $utc = new \DateTimeZone('UTC');

        return new CalendarEvent(
            'garmin-42',
            $summary,
            $description,
            new \DateTimeImmutable('2026-10-19 05:12:03', $utc),
            new \DateTimeImmutable('2026-10-19 05:54:13', $utc),
            'https://connect.garmin.com/modern/activity/42',
            '42',
            ['Garmin', 'Laufen'],
        );
    }

    private static function serialize(CalendarEvent $event): string
    {
        return (new IcsSerializer())->serialize($event, new \DateTimeImmutable('2026-10-20 08:00:00', new \DateTimeZone('UTC')));
    }

    /** @return list<string> Logische Zeilen nach RFC 5545 entfaltet */
    private static function unfold(string $ics): array
    {
        return explode("\r\n", rtrim(str_replace("\r\n ", '', $ics), "\r\n"));
    }

    public function testCompleteCalendar(): void
    {
        self::assertSame(
            "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "PRODID:-//HoMS1987//workoutcalendar//DE\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:garmin-42\r\n"
            . "DTSTAMP:20261020T080000Z\r\n"
            . "DTSTART:20261019T051203Z\r\n"
            . "DTEND:20261019T055413Z\r\n"
            . "SUMMARY:🏃 Laufen – 7\\,6 km\r\n"
            . "DESCRIPTION:Dauer: 42:10 min\\nDistanz: 7\\,60 km\r\n"
            . "URL:https://connect.garmin.com/modern/activity/42\r\n"
            . "CATEGORIES:Garmin,Laufen\r\n"
            . "X-GARMIN-ACTIVITY-ID:42\r\n"
            . 'X-GARMIN-FINGERPRINT:' . self::event()->fingerprint() . "\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n",
            self::serialize(self::event()),
        );
    }

    public function testEscapesSpecialCharacters(): void
    {
        $lines = self::unfold(self::serialize(self::event('A;B,C\\D', "Zeile 1\r\nZeile 2")));

        self::assertContains('SUMMARY:A\\;B\\,C\\\\D', $lines);
        self::assertContains('DESCRIPTION:Zeile 1\\nZeile 2', $lines);
    }

    public function testDtstampIsConvertedToUtc(): void
    {
        $ics = (new IcsSerializer())->serialize(self::event(), new \DateTimeImmutable('2026-10-20 10:00:00', new \DateTimeZone('Europe/Berlin')));

        self::assertStringContainsString("DTSTAMP:20261020T080000Z\r\n", $ics);
    }

    public function testFoldingKeepsUmlautsAndEmojisIntact(): void
    {
        $name = 'Größter Lauf über die Brücke – Fußgängerzone, Ölmühle & Äußere Straße 🏃‍♂️🌧️ ' . str_repeat('äöüß🚴', 20);
        $ics = self::serialize(self::event('🏃 ' . $name, $name));

        foreach (explode("\r\n", rtrim($ics, "\r\n")) as $line) {
            self::assertLessThanOrEqual(75, strlen($line), "Zeile zu lang: $line");
            self::assertTrue(mb_check_encoding($line, 'UTF-8'), "Mehrbytezeichen zerschnitten: $line");
        }
        $lines = self::unfold($ics);
        self::assertContains('SUMMARY:🏃 ' . str_replace(',', '\\,', $name), $lines);
        self::assertContains('DESCRIPTION:' . str_replace(',', '\\,', $name), $lines);
    }

    public function testShortLinesAreNotFolded(): void
    {
        self::assertStringNotContainsString("\r\n ", self::serialize(self::event()));
    }

    public function testAccountKeyIsWritten(): void
    {
        $ics = (new IcsSerializer())->serialize(self::event(), new \DateTimeImmutable('2026-10-20 08:00:00', new \DateTimeZone('UTC')), '0123456789abcdef');

        self::assertStringContainsString(
            'X-GARMIN-FINGERPRINT:' . self::event()->fingerprint() . "
X-GARMIN-ACCOUNT:0123456789abcdef
END:VEVENT
",
            $ics,
        );
    }

    public function testNoAccountKeyNoLine(): void
    {
        self::assertStringNotContainsString('X-GARMIN-ACCOUNT', self::serialize(self::event()));
    }
}
