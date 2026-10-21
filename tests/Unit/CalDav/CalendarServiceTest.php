<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\CalDav;

use OCA\WorkoutCalendar\CalDav\CalendarInfo;
use OCA\WorkoutCalendar\CalDav\CalendarService;
use OCA\WorkoutCalendar\Tests\Support\MemoryCalendarObjects;
use OCA\WorkoutCalendar\Tests\Support\MemoryLogger;
use PHPUnit\Framework\TestCase;

final class CalendarServiceTest extends TestCase
{
    private MemoryCalendarObjects $objects;
    private CalendarService $service;

    protected function setUp(): void
    {
        $this->objects = new MemoryCalendarObjects();
        $this->service = new CalendarService($this->objects);
    }

    private static function ics(string $uid): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20261001T000000Z\r\n"
            . "DTSTART:20261010T080000Z\r\nDTEND:20261010T090000Z\r\nSUMMARY:Event\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /** Creates n app events (garmin-1.ics …) and m foreign events. */
    private function fill(int $calendarId, int $appEvents, int $foreignEvents, int $firstId = 1): void
    {
        for ($i = $firstId; $i < $firstId + $appEvents; $i++) {
            $this->objects->put($calendarId, "garmin-$i.ics", self::ics("garmin-$i"));
        }
        for ($i = 1; $i <= $foreignEvents; $i++) {
            $this->objects->put($calendarId, "fremd-$i.ics", self::ics("fremd-$i"));
        }
    }

    public function testOwnCalendarsSkipsOtherUsersTrashAndTaskLists(): void
    {
        $sport = $this->objects->addCalendar('anna', 'sport', 'Sport', '#ff0000');
        $this->objects->addCalendar('bert', 'sport', 'Sport');
        $this->objects->addCalendar('anna', 'old', 'Old', null, ['VEVENT'], true);
        $this->objects->addCalendar('anna', 'aufgaben', 'Aufgaben', null, ['VTODO']);

        self::assertEquals([new CalendarInfo($sport, 'sport', 'Sport', '#ff0000')], $this->service->ownCalendars('anna'));
    }

    public function testFindByNameIgnoresCaseAndSpaces(): void
    {
        $id = $this->objects->addCalendar('anna', 'garmin', 'Garmin-Aktivitäten');
        $this->objects->addCalendar('bert', 'garmin', 'Garmin-Aktivitäten');

        self::assertSame($id, $this->service->findByName('anna', ' garmin-aktivitäten ')?->id);
        self::assertSame($id, $this->service->findByName('anna', 'GARMIN-AKTIVITÄTEN')?->id);
        self::assertNull($this->service->findByName('anna', 'Garmin'));
    }

    public function testCreateCalendarPicksFreeUri(): void
    {
        $this->objects->addCalendar('anna', 'workoutcalendar', 'Old');
        $this->objects->addCalendar('bert', 'workoutcalendar-2', 'Bert');

        $created = $this->service->createCalendar('anna', 'Garmin-Aktivitäten');

        self::assertSame('workoutcalendar-2', $created->uri);
        self::assertSame('Garmin-Aktivitäten', $created->name);
        self::assertSame('workoutcalendar-2', $this->objects->calendars[$created->id]['uri']);
    }

    public function testCreateCalendarSkipsUriInTrash(): void
    {
        $this->objects->addCalendar('anna', 'workoutcalendar', 'Old', null, ['VEVENT'], true);

        self::assertSame('workoutcalendar-2', $this->service->createCalendar('anna', 'New')->uri);
    }

    public function testCreateCalendarUsesPlainUriWhenFree(): void
    {
        self::assertSame('workoutcalendar', $this->service->createCalendar('anna', 'New')->uri);
    }

    public function testDeleteCalendarMovesItToTrash(): void
    {
        $id = $this->objects->addCalendar('anna', 'workoutcalendar', 'Garmin');

        $this->service->deleteCalendar($id);

        self::assertTrue($this->objects->calendars[$id]['trashed']);
        self::assertSame([], $this->service->ownCalendars('anna'));
    }

    public function testCountAppEventsSkipsForeignEvents(): void
    {
        $id = $this->objects->addCalendar('anna', 'sport', 'Sport');
        $this->fill($id, 3, 2);

        self::assertSame(3, $this->service->countAppEvents($id));
        self::assertSame(5, $this->service->countAll($id));
    }

    public function testCountAppEventsByUriReadsNoIcs(): void
    {
        $id = $this->objects->addCalendar('anna', 'sport', 'Sport');
        $this->fill($id, 3, 2);
        $this->objects->put($id, 'garmin-x.ics', self::ics('garmin-x'));

        self::assertSame(3, $this->service->countAppEventsByUri($id));
        self::assertSame(0, $this->objects->getCalls);
    }

    public function testMoveKeepsForeignEventsAndReportsRest(): void
    {
        $from = $this->objects->addCalendar('anna', 'old', 'Old');
        $to = $this->objects->addCalendar('anna', 'new', 'New');
        $this->fill($from, 5, 2);

        self::assertSame(3, $this->service->moveAppEvents($from, $to, 2));
        self::assertSame(2, $this->service->countAppEvents($to));
        self::assertSame(3, $this->service->countAppEvents($from));

        self::assertSame(0, $this->service->moveAppEvents($from, $to, 10));
        self::assertSame(5, $this->service->countAppEvents($to));
        self::assertEqualsCanonicalizing(['fremd-1.ics', 'fremd-2.ics'], $this->objects->uris($from));
        self::assertSame(self::ics('garmin-1'), $this->objects->objects[$to]['garmin-1.ics']);
    }

    public function testMoveDropsSourceWhenTargetHasUri(): void
    {
        $from = $this->objects->addCalendar('anna', 'old', 'Old');
        $to = $this->objects->addCalendar('anna', 'new', 'New');
        $this->objects->put($from, 'garmin-1.ics', self::ics('garmin-1'));
        $newer = str_replace('SUMMARY:Event', 'SUMMARY:Neuer Stand', self::ics('garmin-1'));
        $this->objects->put($to, 'garmin-1.ics', $newer);

        self::assertSame(0, $this->service->moveAppEvents($from, $to, 10));
        self::assertSame([], $this->objects->uris($from));
        self::assertSame($newer, $this->objects->objects[$to]['garmin-1.ics']);
        self::assertSame(["delete:$from:garmin-1.ics"], $this->objects->writes);
    }

    public function testMoveKeepsSourceWhenTargetUriHoldsForeignObject(): void
    {
        $from = $this->objects->addCalendar('anna', 'old', 'Old');
        $to = $this->objects->addCalendar('anna', 'new', 'New');
        $this->fill($from, 3, 0);
        $this->objects->put($to, 'garmin-2.ics', self::ics('etwas-anderes'));
        $logger = new MemoryLogger();
        $service = new CalendarService($this->objects, $logger);

        self::assertSame(1, $service->moveAppEvents($from, $to, 1));
        self::assertSame(0, $service->moveAppEvents($from, $to, 10));

        self::assertSame(['garmin-2.ics'], $this->objects->uris($from));
        self::assertSame(self::ics('etwas-anderes'), $this->objects->objects[$to]['garmin-2.ics']);
        self::assertEqualsCanonicalizing(['garmin-1.ics', 'garmin-2.ics', 'garmin-3.ics'], $this->objects->uris($to));
        self::assertNotEmpty($logger->byLevel('debug'));
    }

    public function testRemoveOnlyTouchesAppEvents(): void
    {
        $id = $this->objects->addCalendar('anna', 'sport', 'Sport');
        $this->fill($id, 3, 1);
        // Own URI form but foreign UID: not an app object
        $this->objects->put($id, 'garmin-99.ics', self::ics('etwas-anderes'));

        self::assertSame(1, $this->service->removeAppEvents($id, 2));
        self::assertSame(0, $this->service->removeAppEvents($id, 2));
        self::assertEqualsCanonicalizing(['fremd-1.ics', 'garmin-99.ics'], $this->objects->uris($id));
        self::assertSame(0, $this->service->countAppEvents($id));
    }
}
