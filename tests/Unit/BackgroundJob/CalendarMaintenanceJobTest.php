<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\BackgroundJob;

use OCA\WorkoutCalendar\BackgroundJob\CalendarMaintenanceJob;
use OCA\WorkoutCalendar\CalDav\CalendarObjects;
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
        $queued = [];
        $jobList->expects(self::exactly(2))->method('add')->willReturnCallback(
            static function (string $class, array $arg) use (&$queued): void {
                self::assertSame(CalendarMaintenanceJob::class, $class);
                $queued[] = $arg;
            },
        );
        $job = $this->job($jobList);

        $this->execute($job, $argument);
        self::assertCount(250, $this->objects->objects[$this->from]);
        self::assertSame('move', $this->users->setup(self::UID)->maintenance);
        self::assertSame($argument + ['attempt' => 0, 'rest' => 250], $queued[0]);
        $this->execute($job, $queued[0]);
        self::assertSame('move', $this->users->setup(self::UID)->maintenance);
        self::assertSame($argument + ['attempt' => 0, 'rest' => 50], $queued[1]);
        $this->execute($job, $queued[1]);

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

    public function testExceptionRequeuesWithAttempt(): void
    {
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, 'abc', null, $this->to, false, 'remove'));
        $argument = ['uid' => self::UID, 'action' => 'remove', 'from' => $this->from, 'to' => null, 'attempt' => 2, 'rest' => 7];
        $jobList = $this->createMock(IJobList::class);
        $jobList->expects(self::once())->method('add')
            ->with(CalendarMaintenanceJob::class, ['uid' => self::UID, 'action' => 'remove', 'from' => $this->from, 'to' => null, 'attempt' => 3, 'rest' => 7]);

        $this->execute($this->job($jobList, $this->failingObjects()), $argument);

        self::assertSame('remove', $this->users->setup(self::UID)->maintenance);
        self::assertCount(1, $this->logger->byLevel('error'));
    }

    public function testGivesUpAfterFiveAttemptsAndClearsMaintenance(): void
    {
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, 'abc', null, $this->to, false, 'remove'));
        $argument = ['uid' => self::UID, 'action' => 'remove', 'from' => $this->from, 'to' => null, 'attempt' => 5];
        $jobList = $this->createMock(IJobList::class);
        $jobList->expects(self::never())->method('add');

        $this->execute($this->job($jobList, $this->failingObjects('geheim-passwort')), $argument);

        self::assertNull($this->users->setup(self::UID)->maintenance);
        $errors = $this->logger->byLevel('error');
        self::assertNotEmpty($errors);
        $last = end($errors);
        self::assertSame(['uid' => self::UID, 'action' => 'remove', 'attempts' => 6], $last['context']);
        self::assertStringNotContainsString('geheim-passwort', $last['message'] . json_encode($last['context']));
    }

    public function testNoProgressCountsAsFailedAttempt(): void
    {
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, 'abc', null, $this->to, false, 'remove'));
        $argument = ['uid' => self::UID, 'action' => 'remove', 'from' => $this->from, 'to' => null, 'attempt' => 1, 'rest' => 50];
        $jobList = $this->createMock(IJobList::class);
        $jobList->expects(self::once())->method('add')
            ->with(CalendarMaintenanceJob::class, ['uid' => self::UID, 'action' => 'remove', 'from' => $this->from, 'to' => null, 'attempt' => 2, 'rest' => 50]);

        $this->execute($this->job($jobList, $this->stuckObjects()), $argument);

        self::assertSame('remove', $this->users->setup(self::UID)->maintenance);
    }

    public function testProgressResetsAttempt(): void
    {
        $this->fill($this->from, 450);
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, 'abc', null, $this->to, false, 'remove'));
        $argument = ['uid' => self::UID, 'action' => 'remove', 'from' => $this->from, 'to' => null, 'attempt' => 4, 'rest' => 900];
        $jobList = $this->createMock(IJobList::class);
        $jobList->expects(self::once())->method('add')
            ->with(CalendarMaintenanceJob::class, ['uid' => self::UID, 'action' => 'remove', 'from' => $this->from, 'to' => null, 'attempt' => 0, 'rest' => 250]);

        $this->execute($this->job($jobList), $argument);

        self::assertSame('remove', $this->users->setup(self::UID)->maintenance);
    }

    public function testMalformedOptionalKeysAreTreatedAsAbsent(): void
    {
        $this->fill($this->from, 450);
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, 'abc', null, $this->to, false, 'remove'));
        $argument = ['uid' => self::UID, 'action' => 'remove', 'from' => $this->from, 'to' => null, 'attempt' => 'x', 'rest' => -3];
        $jobList = $this->createMock(IJobList::class);
        $jobList->expects(self::once())->method('add')
            ->with(CalendarMaintenanceJob::class, ['uid' => self::UID, 'action' => 'remove', 'from' => $this->from, 'to' => null, 'attempt' => 0, 'rest' => 250]);

        $this->execute($this->job($jobList), $argument);
    }

    /** Wirft bei jedem Zugriff, wie ein fehlschlagender CalDAV-Aufruf. */
    private function failingObjects(string $message = 'CalDAV nicht erreichbar'): CalendarObjects
    {
        $objects = $this->createStub(CalendarObjects::class);
        $objects->method('uris')->willThrowException(new \RuntimeException($message));

        return $objects;
    }

    /** 250 Termine der App, von denen nichts verschwindet: nach jedem Lauf bleiben 50 übrig. */
    private function stuckObjects(): CalendarObjects
    {
        $objects = $this->createStub(CalendarObjects::class);
        $uris = array_map(static fn (int $i): string => 'garmin-' . $i . '.ics', range(1, 250));
        $objects->method('uris')->willReturn($uris);
        $ics = array_map(
            static fn (int $i): string => "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:garmin-$i\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
            range(1, 250),
        );
        $objects->method('get')->willReturn(array_combine($uris, $ics));

        return $objects;
    }

    private function fill(int $calendarId, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->objects->put($calendarId, 'garmin-' . $i . '.ics', "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:garmin-$i\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        }
    }

    private function job(IJobList $jobList, ?CalendarObjects $objects = null): CalendarMaintenanceJob
    {
        return new CalendarMaintenanceJob(
            $this->createStub(ITimeFactory::class),
            new CalendarService($objects ?? $this->objects),
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
