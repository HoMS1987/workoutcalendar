<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\BackgroundJob;

use OCA\WorkoutCalendar\BackgroundJob\CalendarMaintenanceJob;
use OCA\WorkoutCalendar\CalDav\CalendarService;
use OCA\WorkoutCalendar\Storage\SetupState;
use OCA\WorkoutCalendar\Storage\SetupStep;
use OCA\WorkoutCalendar\Storage\UserStore;
use OCA\WorkoutCalendar\Tests\Support\InMemoryConfigMocks;
use OCA\WorkoutCalendar\Tests\Support\MemoryCalendarObjects;
use OCA\WorkoutCalendar\Tests\Support\MemoryLogger;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;

final class CalendarMaintenanceJobTest extends TestCase
{
    use InMemoryConfigMocks;

    private const UID = 'anna';

    /** @var array<string, array<string, string|int>> */
    private array $store = [];
    private UserStore $users;
    private MemoryCalendarObjects $objects;
    private MemoryLogger $logger;
    private int $from;
    private int $to;

    protected function setUp(): void
    {
        $this->users = new UserStore($this->memoryUserConfig($this->store));
        $this->objects = new MemoryCalendarObjects();
        $this->logger = new MemoryLogger();
        $this->from = $this->objects->addCalendar(self::UID, 'alt', 'Alt', null, ['VEVENT']);
        $this->to = $this->objects->addCalendar(self::UID, 'neu', 'Neu', null, ['VEVENT']);
    }

    public function testMoveRequeuesUntilDone(): void
    {
        $this->fill($this->from, 450);
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, 'abc', null, $this->to, false, 'move'));
        $argument = ['uid' => self::UID, 'action' => 'move', 'from' => $this->from, 'to' => $this->to];
        $jobList = $this->createMock(IJobList::class);
        $jobList->expects(self::exactly(2))->method('add')->with(CalendarMaintenanceJob::class, $argument);
        $job = $this->job($jobList);

        $this->execute($job, $argument);
        self::assertCount(250, $this->objects->objects[$this->from]);
        self::assertSame('move', $this->users->setup(self::UID)->maintenance);
        $this->execute($job, $argument);
        self::assertSame('move', $this->users->setup(self::UID)->maintenance);
        $this->execute($job, $argument);

        self::assertCount(0, $this->objects->objects[$this->from]);
        self::assertCount(450, $this->objects->objects[$this->to]);
        self::assertNull($this->users->setup(self::UID)->maintenance);
    }

    public function testRemoveClearsMaintenanceWhenDone(): void
    {
        $this->fill($this->from, 3);
        $this->objects->put($this->from, 'fremd.ics', 'BEGIN:VCALENDAR');
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, 'abc', null, $this->to, false, 'remove'));
        $jobList = $this->createMock(IJobList::class);
        $jobList->expects(self::never())->method('add');

        $this->execute($this->job($jobList), ['uid' => self::UID, 'action' => 'remove', 'from' => $this->from, 'to' => null]);

        self::assertSame(['fremd.ics'], array_keys($this->objects->objects[$this->from]));
        self::assertNull($this->users->setup(self::UID)->maintenance);
    }

    public function testMalformedArgumentDoesNothing(): void
    {
        $this->fill($this->from, 3);
        $jobList = $this->createMock(IJobList::class);
        $jobList->expects(self::never())->method('add');
        $job = $this->job($jobList);

        $this->execute($job, null);
        $this->execute($job, ['uid' => self::UID, 'action' => 'wipe', 'from' => $this->from, 'to' => null]);
        $this->execute($job, ['uid' => self::UID, 'action' => 'remove', 'from' => 'x', 'to' => null]);
        $this->execute($job, ['uid' => self::UID, 'action' => 'move', 'from' => $this->from, 'to' => null]);

        self::assertCount(3, $this->objects->objects[$this->from]);
        self::assertCount(4, $this->logger->byLevel('warning'));
    }

    private function fill(int $calendarId, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->objects->put($calendarId, 'garmin-' . $i . '.ics', "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:garmin-$i\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        }
    }

    private function job(IJobList $jobList): CalendarMaintenanceJob
    {
        return new CalendarMaintenanceJob(
            $this->createStub(ITimeFactory::class),
            new CalendarService($this->objects),
            $this->users,
            $jobList,
            $this->logger,
        );
    }

    /** `start()` braucht einen laufenden Server (Logger), deshalb geht der Test direkt auf das protected `run()`. */
    private function execute(CalendarMaintenanceJob $job, mixed $argument): void
    {
        (new \ReflectionMethod($job, 'run'))->invoke($job, $argument);
    }
}
