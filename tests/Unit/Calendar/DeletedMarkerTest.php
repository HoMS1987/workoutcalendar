<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Calendar;

use OCA\WorkoutCalendar\Calendar\CalendarEvent;
use OCA\WorkoutCalendar\Calendar\DeletedMarker;
use OCA\WorkoutCalendar\Calendar\IcsReader;
use OCA\WorkoutCalendar\Calendar\IcsSerializer;
use PHPUnit\Framework\TestCase;

final class DeletedMarkerTest extends TestCase
{
    private static function ics(string $summary = '🏃 Laufen – 7,6 km'): string
    {
        $utc = new \DateTimeZone('UTC');
        $event = new CalendarEvent(
            'garmin-42',
            $summary,
            "Dauer: 42:10 min\nDistanz: 7,60 km",
            new \DateTimeImmutable('2026-10-19 05:12:03', $utc),
            new \DateTimeImmutable('2026-10-19 05:54:13', $utc),
            'https://connect.garmin.com/modern/activity/42',
            '42',
            ['Garmin', 'Laufen'],
        );

        return (new IcsSerializer())->serialize($event, new \DateTimeImmutable('2026-10-20 08:00:00', $utc));
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-02 12:30:00', new \DateTimeZone('UTC'));
    }

    /** @return list<string> */
    private static function unfold(string $ics): array
    {
        return explode("\r\n", rtrim(str_replace("\r\n ", '', $ics), "\r\n"));
    }

    public function testMarksEventAsCancelledAndDeleted(): void
    {
        $lines = self::unfold((new DeletedMarker())->apply(self::ics(), self::now()));

        self::assertContains('SUMMARY:🏃 Laufen – 7\\,6 km (in Garmin gelöscht)', $lines);
        self::assertContains('STATUS:CANCELLED', $lines);
        self::assertContains('X-GARMIN-DELETED:TRUE', $lines);
        self::assertContains('DTSTAMP:20261102T123000Z', $lines);
        self::assertNotContains('DTSTAMP:20261020T080000Z', $lines);
    }

    public function testOtherLinesStayUnchanged(): void
    {
        $before = self::unfold(self::ics());
        $after = self::unfold((new DeletedMarker())->apply(self::ics(), self::now()));

        $changed = static fn (string $line): bool => (bool)preg_match('/^(SUMMARY|STATUS|DTSTAMP|X-GARMIN-DELETED)[:;]/', $line);
        self::assertSame(
            array_values(array_filter($before, static fn (string $l): bool => !$changed($l))),
            array_values(array_filter($after, static fn (string $l): bool => !$changed($l))),
        );
    }

    public function testMarkedEventStaysInsideVevent(): void
    {
        $lines = self::unfold((new DeletedMarker())->apply(self::ics(), self::now()));
        $end = array_search('END:VEVENT', $lines, true);

        self::assertLessThan($end, array_search('STATUS:CANCELLED', $lines, true));
        self::assertLessThan($end, array_search('X-GARMIN-DELETED:TRUE', $lines, true));
    }

    public function testApplyingTwiceEqualsApplyingOnce(): void
    {
        $marker = new DeletedMarker();
        $once = $marker->apply(self::ics(), self::now());

        self::assertSame($once, $marker->apply($once, self::now()));
    }

    public function testReplacesExistingStatus(): void
    {
        $ics = str_replace("END:VEVENT\r\n", "STATUS:CONFIRMED\r\nEND:VEVENT\r\n", self::ics());

        $lines = self::unfold((new DeletedMarker())->apply($ics, self::now()));

        self::assertNotContains('STATUS:CONFIRMED', $lines);
        self::assertCount(1, array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'STATUS:')));
    }

    public function testReaderRecognisesMarkedEvent(): void
    {
        self::assertTrue((new IcsReader())->isMarkedDeleted((new DeletedMarker())->apply(self::ics(), self::now())));
    }

    public function testLongSummaryWithUmlautsAndEmojisIsRefoldedCorrectly(): void
    {
        $summary = '🏃 Größter Lauf über die Brücke – Fußgängerzone, Ölmühle & Äußere Straße 🌧️ ' . str_repeat('äöüß🚴', 12);

        $ics = (new DeletedMarker())->apply(self::ics($summary), self::now());

        foreach (explode("\r\n", rtrim($ics, "\r\n")) as $line) {
            self::assertLessThanOrEqual(75, strlen($line), "Line too long: $line");
            self::assertTrue(mb_check_encoding($line, 'UTF-8'), "Mehrbytezeichen zerschnitten: $line");
        }
        self::assertContains('SUMMARY:' . str_replace(',', '\\,', $summary) . ' (in Garmin gelöscht)', self::unfold($ics));
    }

    public function testAcceptsLfLineEndingsAndReturnsCrlf(): void
    {
        $ics = str_replace("\r\n", "\n", self::ics());

        $result = (new DeletedMarker())->apply($ics, self::now());

        self::assertStringNotContainsString("\n", str_replace("\r\n", '', $result));
        self::assertContains('X-GARMIN-DELETED:TRUE', self::unfold($result));
    }
}
