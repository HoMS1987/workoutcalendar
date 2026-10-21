<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\CalDav;

use OCA\WorkoutCalendar\CalDav\CalendarInfo;
use OCA\WorkoutCalendar\CalDav\CalendarService;
use OCA\WorkoutCalendar\Tests\Support\MemoryCalendarObjects;
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
            . "DTSTART:20261010T080000Z\r\nDTEND:20261010T090000Z\r\nSUMMARY:Termin\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /** Legt n Termine der App (garmin-1.ics …) und m fremde Termine an. */
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
        $this->objects->addCalendar('anna', 'alt', 'Alt', null, ['VEVENT'], true);
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
        $this->objects->addCalendar('anna', 'workoutcalendar', 'Alt');
        $this->objects->addCalendar('bert', 'workoutcalendar-2', 'Bert');

        $created = $this->service->createCalendar('anna', 'Garmin-Aktivitäten');

        self::assertSame('workoutcalendar-2', $created->uri);
        self::assertSame('Garmin-Aktivitäten', $created->name);
        self::assertSame('workoutcalendar-2', $this->objects->calendars[$created->id]['uri']);
    }

    public function testCreateCalendarSkipsUriInTrash(): void
    {
        $this->objects->addCalendar('anna', 'workoutcalendar', 'Alt', null, ['VEVENT'], true);

        self::assertSame('workoutcalendar-2', $this->service->createCalendar('anna', 'Neu')->uri);
    }

    public function testCreateCalendarUsesPlainUriWhenFree(): void
    {
        self::assertSame('workoutcalendar', $this->service->createCalendar('anna', 'Neu')->uri);
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
        $from = $this->objects->addCalendar('anna', 'alt', 'Alt');
        $to = $this->objects->addCalendar('anna', 'neu', 'Neu');
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
        $from = $this->objects->addCalendar('anna', 'alt', 'Alt');
        $to = $this->objects->addCalendar('anna', 'neu', 'Neu');
        $this->objects->put($from, 'garmin-1.ics', self::ics('garmin-1'));
        $this->objects->put($to, 'garmin-1.ics', 'NEUER STAND');

        self::assertSame(0, $this->service->moveAppEvents($from, $to, 10));
        self::assertSame([], $this->objects->uris($from));
        self::assertSame('NEUER STAND', $this->objects->objects[$to]['garmin-1.ics']);
        self::assertSame(["delete:$from:garmin-1.ics"], $this->objects->writes);
    }

    public function testRemoveOnlyTouchesAppEvents(): void
    {
        $id = $this->objects->addCalendar('anna', 'sport', 'Sport');
        $this->fill($id, 3, 1);
        // Eigene URI-Form, aber fremde UID: kein Objekt der App
        $this->objects->put($id, 'garmin-99.ics', self::ics('etwas-anderes'));

        self::assertSame(1, $this->service->removeAppEvents($id, 2));
        self::assertSame(0, $this->service->removeAppEvents($id, 2));
        self::assertEqualsCanonicalizing(['fremd-1.ics', 'garmin-99.ics'], $this->objects->uris($id));
        self::assertSame(0, $this->service->countAppEvents($id));
    }
}
