<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Service;

use OCA\WorkoutCalendar\BackgroundJob\CalendarMaintenanceJob;
use OCA\WorkoutCalendar\CalDav\CalendarService;
use OCA\WorkoutCalendar\Service\CalendarSetupService;
use OCA\WorkoutCalendar\Service\PersonBusyException;
use OCA\WorkoutCalendar\Service\PersonLock;
use OCA\WorkoutCalendar\Storage\SetupState;
use OCA\WorkoutCalendar\Storage\SetupStep;
use OCA\WorkoutCalendar\Storage\UserStore;
use OCA\WorkoutCalendar\Sync\SyncState;
use OCA\WorkoutCalendar\Tests\Support\InMemoryConfigMocks;
use OCA\WorkoutCalendar\Tests\Support\MemoryCalendarObjects;
use OCA\WorkoutCalendar\Tests\Support\MemoryLockingProvider;
use OCA\WorkoutCalendar\Tests\Support\MemoryLogger;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class CalendarSetupServiceTest extends TestCase
{
    use InMemoryConfigMocks;

    private const UID = 'anna';
    private const NOW = 1_792_497_600;

    /** @var array<string, array<string, string|int>> */
    private array $store = [];
    private UserStore $users;
    private MemoryCalendarObjects $objects;
    private MemoryLockingProvider $locking;
    /** @var list<array{string, mixed}> */
    private array $jobs = [];
    /** @var ?\Closure(): void */
    private ?\Closure $onJobAdded = null;
    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->users = new UserStore($this->memoryUserConfig($this->store));
        $this->objects = new MemoryCalendarObjects();
        $this->locking = new MemoryLockingProvider();
        $this->logger = new MemoryLogger();
    }

    public function testDefaultNameIsTranslated(): void
    {
        self::assertSame('Garmin-Aktivitäten', $this->service()->defaultName());
    }

    public function testNewCalendarIsCreatedWithoutQuestion(): void
    {
        $this->wizard();

        $choice = $this->service()->choose(self::UID, '  Garmin-Aktivitäten ', null, false, false);

        self::assertFalse($choice->needsConfirmation);
        self::assertSame('Garmin-Aktivitäten', $choice->calendar?->name);
        $setup = $this->users->setup(self::UID);
        self::assertSame($choice->calendar->id, $setup->calendarId);
        self::assertTrue($setup->calendarCreatedByApp);
        self::assertSame(SetupStep::History, $setup->step);
        self::assertCount(1, $this->objects->ownCalendars(self::UID));
    }

    public function testExistingNameNeedsConfirmation(): void
    {
        $this->wizard();
        $id = $this->objects->addCalendar(self::UID, 'x', 'Garmin-Aktivitäten');

        $choice = $this->service()->choose(self::UID, 'garmin-aktivitäten', null, false, false);

        self::assertTrue($choice->needsConfirmation);
        self::assertSame($id, $choice->calendar?->id);
        self::assertCount(1, $this->objects->ownCalendars(self::UID));
        self::assertNull($this->users->setup(self::UID)->calendarId);
        self::assertSame(SetupStep::Calendar, $this->users->setup(self::UID)->step);
    }

    public function testExistingCalendarNeedsConfirmation(): void
    {
        $this->wizard();
        $id = $this->objects->addCalendar(self::UID, 'x', 'Training');
        for ($i = 0; $i < 37; $i++) {
            $this->objects->put($id, "e$i.ics", 'x');
        }

        $choice = $this->service()->choose(self::UID, null, $id, false, false);

        self::assertTrue($choice->needsConfirmation);
        self::assertSame(37, $choice->existingEvents);
        self::assertNull($this->users->setup(self::UID)->calendarId);
    }

    public function testConfirmedExistingCalendarIsNotMarkedAsCreatedByApp(): void
    {
        $this->wizard();
        $id = $this->objects->addCalendar(self::UID, 'x', 'Training');

        $choice = $this->service()->choose(self::UID, null, $id, true, false);

        self::assertFalse($choice->needsConfirmation);
        $setup = $this->users->setup(self::UID);
        self::assertSame($id, $setup->calendarId);
        self::assertFalse($setup->calendarCreatedByApp);
        self::assertSame(SetupStep::History, $setup->step);
    }

    public function testForeignCalendarIdIsRejected(): void
    {
        $this->wizard();
        $foreign = $this->objects->addCalendar('bernd', 'x', 'Fremd');
        $before = $this->store;

        try {
            $this->service()->choose(self::UID, null, $foreign, true, false);
            self::fail('InvalidArgumentException erwartet');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, $this->store);
        }
    }

    public function testExactlyOneOfNameAndIdIsRequired(): void
    {
        $this->wizard();
        $id = $this->objects->addCalendar(self::UID, 'x', 'Training');

        foreach ([[null, null], ['Neu', $id]] as [$name, $calendarId]) {
            try {
                $this->service()->choose(self::UID, $name, $calendarId, true, false);
                self::fail('InvalidArgumentException erwartet');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertCount(1, $this->objects->ownCalendars(self::UID));
    }

    public function testEmptyNameIsRejected(): void
    {
        $this->wizard();

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->choose(self::UID, '   ', null, false, false);
    }

    public function testNotLoggedInIsRejected(): void
    {
        $this->users->saveSetup(self::UID, new SetupState());

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->choose(self::UID, 'Neu', null, false, false);
    }

    public function testChoosingSameCalendarChangesNothing(): void
    {
        $id = $this->done();
        $before = $this->store;

        $choice = $this->service()->choose(self::UID, null, $id, false, true);

        self::assertFalse($choice->needsConfirmation);
        self::assertSame($id, $choice->calendar?->id);
        self::assertSame($before, $this->store);
        self::assertSame([], $this->jobs);
    }

    public function testWizardChoiceDoesNotArmQueue(): void
    {
        $this->wizard();

        $this->service()->choose(self::UID, 'Neu', null, false, false);

        self::assertNull($this->users->state(self::UID)->nextRunAt);
        self::assertSame([], $this->users->dueUsers(self::NOW + 100, 10));
    }

    public function testChangeWithMoveQueuesJob(): void
    {
        $old = $this->done();
        $state = new SyncState();
        $state->initialized = true;
        $this->users->saveState(self::UID, $state);
        $new = $this->objects->addCalendar(self::UID, 'y', 'Neu');

        $this->service()->choose(self::UID, null, $new, true, true);

        $setup = $this->users->setup(self::UID);
        self::assertSame($new, $setup->calendarId);
        self::assertSame('move', $setup->maintenance);
        self::assertSame(SetupStep::Done, $setup->step);
        self::assertSame([[CalendarMaintenanceJob::class, ['uid' => self::UID, 'action' => 'move', 'from' => $old, 'to' => $new]]], $this->jobs);
        self::assertTrue($this->users->state(self::UID)->initialized);
    }

    public function testChangeWithMoveSavesMarkerBeforeQueueing(): void
    {
        $this->done();
        $new = $this->objects->addCalendar(self::UID, 'y', 'Neu');
        $setupWhenQueued = [];
        $this->onJobAdded = function () use (&$setupWhenQueued): void {
            $setup = $this->users->setup(self::UID);
            $setupWhenQueued[] = [$setup->maintenance, $setup->calendarId];
        };

        $this->service()->choose(self::UID, null, $new, true, true);

        self::assertSame([['move', $new]], $setupWhenQueued);
    }

    public function testFailedMoveQueueingRestoresSetupAndState(): void
    {
        $this->done();
        $new = $this->objects->addCalendar(self::UID, 'y', 'Neu');
        $before = $this->store;
        $this->onJobAdded = static function (): void {
            throw new \RuntimeException('Database unreachable');
        };

        try {
            $this->service()->choose(self::UID, null, $new, true, true);
            self::fail('RuntimeException erwartet');
        } catch (\RuntimeException $e) {
            self::assertSame('Database unreachable', $e->getMessage());
        }

        self::assertSame($before, $this->store);
        self::assertSame([], $this->locking->held());
    }

    public function testFailedRestoreKeepsOriginalQueueingException(): void
    {
        $failSetupWrites = false;
        $this->users = new UserStore($this->memoryUserConfig(
            $this->store,
            static function (string $uid, string $key) use (&$failSetupWrites): void {
                if ($failSetupWrites && $key === 'setup') {
                    throw new \RuntimeException('Restore failed');
                }
            },
        ));
        $this->done();
        $new = $this->objects->addCalendar(self::UID, 'y', 'Neu');
        $this->onJobAdded = static function () use (&$failSetupWrites): void {
            $failSetupWrites = true;

            throw new \RuntimeException('Database unreachable');
        };

        try {
            $this->service()->choose(self::UID, null, $new, true, true);
            self::fail('RuntimeException erwartet');
        } catch (\RuntimeException $e) {
            self::assertSame('Database unreachable', $e->getMessage());
        }

        $errors = $this->logger->byLevel('error');
        self::assertCount(1, $errors);
        self::assertSame(\RuntimeException::class, $errors[0]['context']['exception'] ?? null);
    }

    public function testChangeWithoutMoveRestartsHistory(): void
    {
        $this->done();
        $state = new SyncState();
        $state->initialized = true;
        $this->users->saveState(self::UID, $state);
        $new = $this->objects->addCalendar(self::UID, 'y', 'Neu');

        $this->service()->choose(self::UID, null, $new, true, false);

        self::assertFalse($this->users->state(self::UID)->initialized);
        self::assertNull($this->users->setup(self::UID)->maintenance);
        self::assertSame([], $this->jobs);
    }

    public function testChangeWhileMaintenanceRunsIsRejected(): void
    {
        $old = $this->objects->addCalendar(self::UID, 'x', 'Alt');
        $target = $this->objects->addCalendar(self::UID, 'y', 'Ziel');
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, 'abc', 'a***@b.de', $target, false, 'move'));
        $other = $this->objects->addCalendar(self::UID, 'z', 'Dritter');
        $before = $this->store;
        $calendars = count($this->objects->ownCalendars(self::UID));

        foreach ([[null, $other], ['Brandneu', null]] as [$name, $id]) {
            try {
                $this->service()->choose(self::UID, $name, $id, true, true);
                self::fail('InvalidArgumentException erwartet');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame($before, $this->store);
        self::assertSame([], $this->jobs);
        self::assertCount($calendars, $this->objects->ownCalendars(self::UID));
        self::assertNotNull($old);
    }

    public function testWizardChoiceAllowedDuringRemoval(): void
    {
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Calendar, true, 'abc', 'a***@b.de', null, false, 'remove'));

        $choice = $this->service()->choose(self::UID, 'Neu', null, false, false);

        $setup = $this->users->setup(self::UID);
        self::assertSame($choice->calendar?->id, $setup->calendarId);
        self::assertSame('remove', $setup->maintenance);
        self::assertSame(SetupStep::History, $setup->step);
    }

    public function testChangeArmsQueue(): void
    {
        $this->done();
        $new = $this->objects->addCalendar(self::UID, 'y', 'Neu');

        $this->service()->choose(self::UID, null, $new, true, false);

        self::assertSame(self::NOW, $this->users->state(self::UID)->nextRunAt);
        self::assertSame([self::UID], $this->users->dueUsers(self::NOW, 10));
    }

    public function testMissingPreviousCalendarJustSetsNewOne(): void
    {
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, 'abc', 'a***@b.de'));

        $choice = $this->service()->choose(self::UID, 'Neu', null, false, true);

        self::assertSame($choice->calendar?->id, $this->users->setup(self::UID)->calendarId);
        self::assertNull($this->users->setup(self::UID)->maintenance);
        self::assertSame([], $this->jobs);
        self::assertSame(self::NOW, $this->users->state(self::UID)->nextRunAt);
    }

    public function testChoiceClearsFailureMarkers(): void
    {
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Calendar, true, 'abc', 'a***@b.de', null, false, null, 'move', true));

        $this->service()->choose(self::UID, 'Neu', null, false, false);

        $setup = $this->users->setup(self::UID);
        self::assertNull($setup->maintenanceFailed);
        self::assertFalse($setup->calendarMissing);
        self::assertSame(SetupStep::History, $setup->step);
    }

    public function testChangeAfterFailedMaintenanceClearsMarker(): void
    {
        $old = $this->objects->addCalendar(self::UID, 'x', 'Alt');
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, 'abc', 'a***@b.de', $old, false, null, 'move'));
        $new = $this->objects->addCalendar(self::UID, 'y', 'Neu');

        $this->service()->choose(self::UID, null, $new, true, false);

        self::assertNull($this->users->setup(self::UID)->maintenanceFailed);
        self::assertSame($new, $this->users->setup(self::UID)->calendarId);
    }

    public function testChoiceWaitsForRunningSync(): void
    {
        $old = $this->done();
        $new = $this->objects->addCalendar(self::UID, 'y', 'Neu');
        $before = $this->store;
        $this->locking->hold('workoutcalendar/sync/' . md5(self::UID));

        try {
            $this->service()->choose(self::UID, null, $new, true, true);
            self::fail('PersonBusyException erwartet');
        } catch (PersonBusyException) {
        }

        self::assertSame($before, $this->store);
        self::assertSame($old, $this->users->setup(self::UID)->calendarId);
        self::assertSame([], $this->jobs);
    }

    private function wizard(): void
    {
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Calendar, true, 'abc', 'a***@b.de'));
    }

    private function done(): int
    {
        $id = $this->objects->addCalendar(self::UID, 'x', 'Alt');
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, 'abc', 'a***@b.de', $id));

        return $id;
    }

    private function service(): CalendarSetupService
    {
        $jobList = $this->createMock(IJobList::class);
        $jobList->method('add')->willReturnCallback(function (string $job, $argument = null): void {
            $this->jobs[] = [$job, $argument];
            if ($this->onJobAdded !== null) {
                ($this->onJobAdded)();
            }
        });
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);
        $l = $this->createStub(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $text): string => $text === 'Garmin activities' ? 'Garmin-Aktivitäten' : $text);

        return new CalendarSetupService(new CalendarService($this->objects), $this->users, $jobList, $time, $l, new PersonLock($this->locking), $this->logger);
    }
}
