<?php

declare(strict_types=1);

namespace OCA\GarminCalendar\Tests\Unit\Calendar;

use OCA\GarminCalendar\Calendar\CalendarEvent;
use OCA\GarminCalendar\Calendar\IcsReader;
use OCA\GarminCalendar\Calendar\IcsSerializer;
use PHPUnit\Framework\TestCase;

final class IcsReaderTest extends TestCase
{
    private static function event(): CalendarEvent
    {
        $utc = new \DateTimeZone('UTC');

        return new CalendarEvent(
            'garmin-4711',
            '🏃 Laufen – 7,6 km',
            'Dauer: 42:10 min',
            new \DateTimeImmutable('2026-10-19 05:12:03', $utc),
            new \DateTimeImmutable('2026-10-19 05:54:13', $utc),
            'https://connect.garmin.com/modern/activity/4711',
            '4711',
            ['Garmin', 'Laufen'],
        );
    }

    private static function ics(): string
    {
        return (new IcsSerializer())->serialize(self::event(), new \DateTimeImmutable('2026-10-20 08:00:00'));
    }

    public function testReadsActivityIdAndFingerprintFromSerializedEvent(): void
    {
        $reader = new IcsReader();

        self::assertSame('4711', $reader->activityId(self::ics()));
        self::assertSame(self::event()->fingerprint(), $reader->fingerprint(self::ics()));
        self::assertFalse($reader->isMarkedDeleted(self::ics()));
    }

    public function testReadsFoldedLinesWithLfLineEndings(): void
    {
        $ics = "BEGIN:VCALENDAR\nBEGIN:VEVENT\nX-GARMIN-ACTIVITY-ID:12\n 34\nX-GARMIN-FINGERPRINT:abc\n\tdef\nX-GARMIN-DELETED:TRUE\nEND:VEVENT\nEND:VCALENDAR\n";
        $reader = new IcsReader();

        self::assertSame('1234', $reader->activityId($ics));
        self::assertSame('abcdef', $reader->fingerprint($ics));
        self::assertTrue($reader->isMarkedDeleted($ics));
    }

    public function testMissingPropertiesGiveNull(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:fremd\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $reader = new IcsReader();

        self::assertNull($reader->activityId($ics));
        self::assertNull($reader->fingerprint($ics));
        self::assertFalse($reader->isMarkedDeleted($ics));
    }

    public function testDeletedFlagIsCaseInsensitiveAndIgnoresOtherValues(): void
    {
        $reader = new IcsReader();

        self::assertTrue($reader->isMarkedDeleted("BEGIN:VEVENT\r\nx-garmin-deleted:true\r\nEND:VEVENT\r\n"));
        self::assertFalse($reader->isMarkedDeleted("BEGIN:VEVENT\r\nX-GARMIN-DELETED:FALSE\r\nEND:VEVENT\r\n"));
    }
}
