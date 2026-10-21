<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Service;

use OCA\WorkoutCalendar\CalDav\CalendarService;
use OCA\WorkoutCalendar\Garmin\Auth\Credentials;
use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Service\StatusService;
use OCA\WorkoutCalendar\Storage\SecretStore;
use OCA\WorkoutCalendar\Storage\SetupState;
use OCA\WorkoutCalendar\Storage\SetupStep;
use OCA\WorkoutCalendar\Storage\UserStore;
use OCA\WorkoutCalendar\Sync\SyncSettings;
use OCA\WorkoutCalendar\Tests\Support\InMemoryConfigMocks;
use OCA\WorkoutCalendar\Tests\Support\MemoryCalendarObjects;
use OCA\WorkoutCalendar\Tests\Support\MemoryLogger;
use PHPUnit\Framework\TestCase;

final class StatusServiceTest extends TestCase
{
    use InMemoryConfigMocks;

    private const UID = 'anna';
    private const EMAIL = 'anna.mueller@example.org';
    private const PASSWORD = 'Geheim-Passwort-42';
    private const ACCESS_TOKEN = 'eyJhbGciOiJub25lIn0.eyJjbGllbnRfaWQiOiJDIn0.access';
    private const REFRESH_TOKEN = 'refresh-token-value-123';
    private const ACCOUNT = '0123456789abcdef';
    private const ICS = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:garmin-%s\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    /** @var array<string, array<string, string|int>> */
    private array $store = [];
    private UserStore $users;
    private SecretStore $secrets;
    private MemoryCalendarObjects $objects;

    protected function setUp(): void
    {
        $config = $this->memoryUserConfig($this->store);
        $this->users = new UserStore($config);
        $this->secrets = new SecretStore($config, $this->fakeCrypto(), new MemoryLogger());
        $this->objects = new MemoryCalendarObjects();
    }

    public function testStatusNeverContainsSecrets(): void
    {
        $calendarId = $this->objects->addCalendar(self::UID, 'workoutcalendar', 'Training');
        $this->secrets->saveCredentials(self::UID, new Credentials(self::EMAIL, self::PASSWORD));
        $this->secrets->saveTokens(self::UID, new GarminTokens(self::ACCESS_TOKEN, self::REFRESH_TOKEN, 'C'));
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, self::ACCOUNT, 'a***@example.org', $calendarId, true));
        $state = $this->users->state(self::UID);
        $state->failureCategory = 'INVALID_CREDENTIALS';
        $state->failureMessage = 'Garmin lehnt ' . self::EMAIL . ' ab';
        $state->failureSince = 1_792_497_600;
        $state->failureCount = 2;
        $state->pausedFor = 'INVALID_CREDENTIALS';
        $this->users->saveState(self::UID, $state);

        $json = json_encode($this->service()->status(self::UID), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        self::assertStringNotContainsString(self::EMAIL, $json);
        self::assertStringNotContainsString('anna.mueller', $json);
        self::assertStringNotContainsString(self::PASSWORD, $json);
        self::assertStringNotContainsString(self::ACCESS_TOKEN, $json);
        self::assertStringNotContainsString(self::REFRESH_TOKEN, $json);
        self::assertStringNotContainsString(self::ACCOUNT, $json);
        self::assertStringContainsString('a***@example.org', $json);
    }

    public function testBackfillProgress(): void
    {
        $state = $this->users->state(self::UID);
        $state->initialized = true;
        $state->backfillDone = false;
        $state->backfillBefore = '2026-08-01';
        $state->backfillUntil = '2026-07-22';
        $this->users->saveState(self::UID, $state);

        $backfill = $this->service()->status(self::UID)['sync']['backfill'];

        self::assertSame(['running' => true, 'reachedDate' => '2026-08-01'], $backfill);
    }

    public function testFinishedOrUninitializedBackfillIsNotRunning(): void
    {
        self::assertFalse($this->service()->status(self::UID)['sync']['backfill']['running']);

        $state = $this->users->state(self::UID);
        $state->initialized = true;
        $state->backfillDone = true;
        $state->backfillBefore = '2026-07-20';
        $this->users->saveState(self::UID, $state);

        self::assertSame(['running' => false, 'reachedDate' => '2026-07-20'], $this->service()->status(self::UID)['sync']['backfill']);
    }

    public function testFullShapeAfterSetup(): void
    {
        $calendarId = $this->objects->addCalendar(self::UID, 'workoutcalendar', 'Training');
        $this->objects->put($calendarId, 'garmin-1.ics', sprintf(self::ICS, '1'));
        $this->objects->put($calendarId, 'garmin-2.ics', sprintf(self::ICS, '2'));
        $this->objects->put($calendarId, 'eigener-termin.ics', sprintf(self::ICS, 'x'));
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, self::ACCOUNT, 'a***@example.org', $calendarId, true, 'move'));
        $this->users->saveSettings(self::UID, new SyncSettings(365, 60));
        $state = $this->users->state(self::UID);
        $state->initialized = true;
        $state->backfillDone = true;
        $state->backfillBefore = '2025-10-01';
        $state->lastRunAt = 100;
        $state->lastSuccessAt = 90;
        $state->nextRunAt = 200;
        $state->oldestActivityDate = '2025-10-03';
        $state->failureCategory = 'SERVER_ERROR';
        $state->failureSince = 80;
        $state->failureCount = 3;
        $this->users->saveState(self::UID, $state);

        self::assertSame([
            'setup' => [
                'step' => 'done',
                'connected' => true,
                'maskedEmail' => 'a***@example.org',
                'calendar' => ['id' => $calendarId, 'name' => 'Training', 'createdByApp' => true],
                'maintenance' => 'move',
                'maintenanceFailed' => null,
                'calendarMissing' => false,
            ],
            'sync' => [
                'lastRunAt' => 100,
                'lastSuccessAt' => 90,
                'nextRunAt' => 200,
                'eventCount' => 2,
                'oldestActivityDate' => '2025-10-03',
                'backfill' => ['running' => false, 'reachedDate' => '2025-10-01'],
            ],
            'failure' => ['category' => 'SERVER_ERROR', 'since' => 80, 'count' => 3],
            'paused' => null,
            'settings' => [
                'historyDays' => 365,
                'intervalMinutes' => 60,
                'jitterPercent' => 20,
                'deletionMode' => 'mark',
                'deletionCheckDays' => 30,
            ],
        ], $this->service()->status(self::UID));
    }

    public function testEventCountReadsNoIcs(): void
    {
        $calendarId = $this->objects->addCalendar(self::UID, 'workoutcalendar', 'Training');
        $this->objects->put($calendarId, 'garmin-1.ics', sprintf(self::ICS, '1'));
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, self::ACCOUNT, 'a***@example.org', $calendarId, true));

        self::assertSame(1, $this->service()->status(self::UID)['sync']['eventCount']);
        self::assertSame(0, $this->objects->getCalls);
    }

    public function testFailureMarkersAreReported(): void
    {
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Calendar, true, self::ACCOUNT, 'a***@example.org', null, false, null, 'move', true));

        $setup = $this->service()->status(self::UID)['setup'];

        self::assertSame('move', $setup['maintenanceFailed']);
        self::assertTrue($setup['calendarMissing']);
    }

    public function testFreshPersonHasNoCalendarAndNoFailure(): void
    {
        $status = $this->service()->status(self::UID);

        self::assertSame('garmin', $status['setup']['step']);
        self::assertFalse($status['setup']['connected']);
        self::assertNull($status['setup']['calendar']);
        self::assertSame(0, $status['sync']['eventCount']);
        self::assertNull($status['failure']);
        self::assertNull($status['paused']);
    }

    public function testPausedCategoryIsReported(): void
    {
        $state = $this->users->state(self::UID);
        $state->pausedFor = 'MFA_REQUIRED';
        $this->users->saveState(self::UID, $state);

        self::assertSame('MFA_REQUIRED', $this->service()->status(self::UID)['paused']);
    }

    private function service(): StatusService
    {
        return new StatusService($this->users, new CalendarService($this->objects));
    }
}
