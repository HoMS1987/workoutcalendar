<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\CalDav;

use OCA\WorkoutCalendar\CalDav\DavCalendarTarget;
use OCA\WorkoutCalendar\Calendar\CalendarEvent;
use OCA\WorkoutCalendar\Calendar\DeletedMarker;
use OCA\WorkoutCalendar\Calendar\IcsReader;
use OCA\WorkoutCalendar\Calendar\IcsSerializer;
use OCA\WorkoutCalendar\Sync\StoredEvent;
use OCA\WorkoutCalendar\Tests\Support\MemoryCalendarObjects;
use OCA\WorkoutCalendar\Tests\Support\MemoryLogger;
use PHPUnit\Framework\TestCase;

final class DavCalendarTargetTest extends TestCase
{
    private const ACCOUNT = '0123456789abcdef';

    private MemoryCalendarObjects $objects;
    private int $calendarId;

    protected function setUp(): void
    {
        $this->objects = new MemoryCalendarObjects();
        $this->calendarId = $this->objects->addCalendar('anna', 'sport', 'Sport');
    }

    private function target(string $accountKey = self::ACCOUNT, ?MemoryLogger $logger = null): DavCalendarTarget
    {
        return new DavCalendarTarget(
            $this->objects,
            $this->calendarId,
            $accountKey,
            new IcsSerializer(),
            new IcsReader(),
            new DeletedMarker(),
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-20 08:00:00', new \DateTimeZone('UTC')),
            $logger ?? new MemoryLogger(),
        );
    }

    private static function event(string $id, string $start = '2026-10-19 05:12:03', string $summary = 'Laufen'): CalendarEvent
    {
        $utc = new \DateTimeZone('UTC');
        $begin = new \DateTimeImmutable($start, $utc);

        return new CalendarEvent(
            "garmin-$id",
            $summary,
            'Dauer: 42:10 min',
            $begin,
            $begin->modify('+42 minutes'),
            "https://connect.garmin.com/modern/activity/$id",
            $id,
            ['Garmin'],
        );
    }

    private static function utc(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    private static function foreignIcs(string $uid, string $start): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20261001T000000Z\r\n"
            . "DTSTART:$start\r\nDTEND:$start\r\nSUMMARY:Zahnarzt\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    public function testCreateWritesUriUidAndAccount(): void
    {
        $this->target()->create(self::event('42'));

        $ics = $this->objects->objects[$this->calendarId]['garmin-42.ics'] ?? '';
        self::assertStringContainsString("UID:garmin-42\r\n", $ics);
        self::assertSame(self::ACCOUNT, (new IcsReader())->accountKey($ics));
        self::assertSame(["create:{$this->calendarId}:garmin-42.ics"], $this->objects->writes);
    }

    public function testFindReturnsFingerprintAndMark(): void
    {
        $target = $this->target();
        $target->create(self::event('42'));
        $target->create(self::event('43'));
        $target->markDeleted('43');

        self::assertEquals(
            [
                '42' => new StoredEvent('42', self::event('42')->fingerprint(), false),
                '43' => new StoredEvent('43', self::event('43')->fingerprint(), true),
            ],
            $target->find(['42', '43', '44']),
        );
    }

    public function testInRangeIgnoresForeignEvents(): void
    {
        $target = $this->target();
        $target->create(self::event('42', '2026-10-10 06:00:00'));
        $this->objects->put($this->calendarId, 'zahnarzt.ics', self::foreignIcs('zahnarzt', '20261011T080000Z'));

        self::assertSame(['42'], array_map('strval', array_keys($target->inRange(self::utc('2026-10-01'), self::utc('2026-10-20')))));
    }

    public function testInRangeIgnoresOtherAccount(): void
    {
        $this->target('fedcba9876543210')->create(self::event('7', '2026-10-10 06:00:00'));
        $this->target()->create(self::event('8', '2026-10-10 07:00:00'));
        // App event without account key (older version) does not count as own
        $this->objects->put(
            $this->calendarId,
            'garmin-9.ics',
            (new IcsSerializer())->serialize(self::event('9', '2026-10-10 08:00:00'), self::utc('2026-10-10')),
        );

        self::assertSame(['8'], array_map('strval', array_keys($this->target()->inRange(self::utc('2026-10-01'), self::utc('2026-10-20')))));
    }

    public function testInRangeUsesStartOfEvent(): void
    {
        $target = $this->target();
        // Starts before the range and ends within it: the backend returns it, but the start is outside.
        $target->create(self::event('1', '2026-09-30 23:50:00'));
        $target->create(self::event('2', '2026-10-01 00:00:00'));
        $target->create(self::event('3', '2026-10-20 00:00:00'));
        $target->create(self::event('4', '2026-10-20 00:00:01'));

        self::assertEqualsCanonicalizing(['2', '3'], array_map('strval', array_keys($target->inRange(self::utc('2026-10-01'), self::utc('2026-10-20')))));
    }

    public function testMarkDeletedAndDelete(): void
    {
        $target = $this->target();
        $target->create(self::event('42'));
        $target->markDeleted('42');

        $ics = $this->objects->objects[$this->calendarId]['garmin-42.ics'];
        self::assertTrue((new IcsReader())->isMarkedDeleted($ics));
        self::assertStringContainsString('(deleted in Garmin)', $ics);

        $target->update(self::event('42'));
        self::assertFalse((new IcsReader())->isMarkedDeleted($this->objects->objects[$this->calendarId]['garmin-42.ics']));

        $target->delete('42');
        self::assertSame([], $this->objects->uris($this->calendarId));
    }

    public function testMarkDeletedIgnoresMissingEvent(): void
    {
        $this->target()->markDeleted('42');

        self::assertSame([], $this->objects->writes);
    }

    public function testForeignObjectUnderAppUriIsNeverOverwritten(): void
    {
        $foreign = self::foreignIcs('zahnarzt', '20261011T080000Z');
        $this->objects->put($this->calendarId, 'garmin-42.ics', $foreign);
        $logger = new MemoryLogger();
        $target = $this->target(self::ACCOUNT, $logger);

        self::assertSame([], $target->find(['42']));

        $target->create(self::event('42'));
        $target->update(self::event('42'));
        $target->markDeleted('42');
        $target->delete('42');

        self::assertSame($foreign, $this->objects->objects[$this->calendarId]['garmin-42.ics']);
        self::assertSame([], $this->objects->writes);
        self::assertCount(4, $logger->byLevel('debug'));
        self::assertStringContainsString('42', $logger->dump());
    }

    public function testInRangeIgnoresForeignUidUnderAppUri(): void
    {
        // Foreign UID, but the app's account key and URI
        $ics = str_replace(
            'UID:garmin-42',
            'UID:etwas-anderes',
            (new IcsSerializer())->serialize(self::event('42', '2026-10-10 06:00:00'), self::utc('2026-10-10'), self::ACCOUNT),
        );
        $this->objects->put($this->calendarId, 'garmin-42.ics', $ics);

        self::assertSame([], $this->target()->inRange(self::utc('2026-10-01'), self::utc('2026-10-20')));
    }
}
