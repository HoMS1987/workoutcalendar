<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Storage;

use OCA\WorkoutCalendar\Storage\SetupState;
use OCA\WorkoutCalendar\Storage\SetupStep;
use OCA\WorkoutCalendar\Storage\UserStore;
use OCA\WorkoutCalendar\Sync\DeletionMode;
use OCA\WorkoutCalendar\Sync\SyncSettings;
use OCA\WorkoutCalendar\Sync\SyncState;
use OCA\WorkoutCalendar\Tests\Support\InMemoryConfigMocks;
use OCP\Config\IUserConfig;
use PHPUnit\Framework\TestCase;

final class UserStoreTest extends TestCase
{
    use InMemoryConfigMocks;

    /** @var array<string, array<string, string|int>> */
    private array $data = [];

    private function store(): UserStore
    {
        return new UserStore($this->memoryUserConfig($this->data));
    }

    public function testDefaultsWhenNothingStored(): void
    {
        $store = $this->store();

        self::assertEquals(new SyncSettings(), $store->settings('anna'));
        self::assertEquals(new SyncState(), $store->state('anna'));
        self::assertEquals(new SetupState(), $store->setup('anna'));
        self::assertSame(SetupStep::Garmin, $store->setup('anna')->step);
    }

    public function testInvalidJsonReadsAsDefaults(): void
    {
        $this->data['anna'] = ['settings' => '{kaputt', 'state' => '"x"', 'setup' => ''];
        $store = $this->store();

        self::assertEquals(new SyncSettings(), $store->settings('anna'));
        self::assertEquals(new SyncState(), $store->state('anna'));
        self::assertEquals(new SetupState(), $store->setup('anna'));
    }

    public function testSettingsRoundTrip(): void
    {
        $store = $this->store();
        $settings = new SyncSettings(null, 60, 10, DeletionMode::Delete, 14);

        $store->saveSettings('anna', $settings);

        self::assertEquals($settings, $store->settings('anna'));
    }

    public function testStateRoundTripWritesNextRunAt(): void
    {
        $config = $this->createMock(IUserConfig::class);
        $config->expects(self::once())->method('setValueInt')
            ->with('anna', 'workoutcalendar', 'next_run_at', 1234);
        $state = new SyncState();
        $state->nextRunAt = 1234;

        (new UserStore($config))->saveState('anna', $state);
    }

    public function testStateRoundTrip(): void
    {
        $store = $this->store();
        $state = new SyncState();
        $state->initialized = true;
        $state->nextRunAt = 1234;
        $state->failureMessage = 'Zeitüberschreitung';

        $store->saveState('anna', $state);

        self::assertEquals($state, $store->state('anna'));
        self::assertSame(1234, $this->data['anna']['next_run_at']);
    }

    public function testStateWithoutNextRunRemovesTheKey(): void
    {
        $store = $this->store();
        $state = new SyncState();
        $state->nextRunAt = 50;
        $store->saveState('anna', $state);

        $state->nextRunAt = null;
        $store->saveState('anna', $state);

        self::assertArrayNotHasKey('next_run_at', $this->data['anna']);
    }

    public function testSetupRoundTrip(): void
    {
        $store = $this->store();
        $setup = new SetupState(SetupStep::History, true, 'abcd1234abcd1234', 'a***@example.org', 7, true, 'move');

        $store->saveSetup('anna', $setup);

        self::assertEquals($setup, $store->setup('anna'));
    }

    public function testSetupRoundTripKeepsFailureMarkers(): void
    {
        $store = $this->store();
        $setup = new SetupState(SetupStep::Calendar, true, 'abcd1234abcd1234', 'a***@example.org', null, false, null, 'remove', true);

        $store->saveSetup('anna', $setup);

        self::assertEquals($setup, $store->setup('anna'));
        self::assertSame('remove', $store->setup('anna')->maintenanceFailed);
        self::assertTrue($store->setup('anna')->calendarMissing);
    }

    public function testUnknownMaintenanceFailureIsIgnored(): void
    {
        $this->data = ['anna' => ['setup' => '{"maintenanceFailed":"wipe","calendarMissing":"ja"}']];

        $setup = $this->store()->setup('anna');

        self::assertNull($setup->maintenanceFailed);
        self::assertFalse($setup->calendarMissing);
    }

    public function testDueUsersAreOldestFirstAndLimited(): void
    {
        $this->data = ['a' => ['next_run_at' => 300], 'b' => ['next_run_at' => 100], 'c' => ['next_run_at' => 900]];
        $store = $this->store();

        self::assertSame(['b'], $store->dueUsers(500, 1));
        self::assertSame(['b', 'a'], $store->dueUsers(500, 10));
        self::assertSame([], $store->dueUsers(50, 10));
    }

    public function testClearRemovesAllKeysOfThePerson(): void
    {
        $this->data = ['anna' => ['settings' => '{}', 'state' => '{}', 'setup' => '{}', 'next_run_at' => 5, 'other' => 'x'], 'bob' => ['state' => '{}']];

        $this->store()->clear('anna');

        self::assertSame(['other' => 'x'], $this->data['anna']);
        self::assertSame(['state' => '{}'], $this->data['bob']);
    }
}
