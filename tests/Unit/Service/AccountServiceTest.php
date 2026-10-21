<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Service;

use OCA\WorkoutCalendar\BackgroundJob\CalendarMaintenanceJob;
use OCA\WorkoutCalendar\CalDav\CalendarService;
use OCA\WorkoutCalendar\Garmin\Auth\Credentials;
use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Garmin\Http\HttpClient;
use OCA\WorkoutCalendar\Notification\FailureNotifications;
use OCA\WorkoutCalendar\Service\AccountService;
use OCA\WorkoutCalendar\Service\Cleanup;
use OCA\WorkoutCalendar\Service\GarminClientFactory;
use OCA\WorkoutCalendar\Service\HttpClientProvider;
use OCA\WorkoutCalendar\Service\PersonBusyException;
use OCA\WorkoutCalendar\Service\PersonLock;
use OCA\WorkoutCalendar\Storage\SecretStore;
use OCA\WorkoutCalendar\Storage\SetupState;
use OCA\WorkoutCalendar\Storage\SetupStep;
use OCA\WorkoutCalendar\Storage\UserStore;
use OCA\WorkoutCalendar\Sync\SyncSettings;
use OCA\WorkoutCalendar\Sync\SyncState;
use OCA\WorkoutCalendar\Tests\Support\FakeHttpClient;
use OCA\WorkoutCalendar\Tests\Support\InMemoryConfigMocks;
use OCA\WorkoutCalendar\Tests\Support\Jwt;
use OCA\WorkoutCalendar\Tests\Support\MemoryCalendarObjects;
use OCA\WorkoutCalendar\Tests\Support\MemoryLockingProvider;
use OCA\WorkoutCalendar\Tests\Support\MemoryLogger;
use OCA\WorkoutCalendar\Tests\Support\RecordingNotification;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

final class AccountServiceTest extends TestCase
{
    use InMemoryConfigMocks;

    private const UID = 'anna';
    private const NOW = 1_792_497_600;

    /** @var array<string, array<string, string|int>> */
    private array $store = [];
    private UserStore $users;
    private SecretStore $secrets;
    private MemoryCalendarObjects $objects;
    private FakeHttpClient $http;
    private MemoryLogger $logger;
    private MemoryLockingProvider $locking;
    private int $calendarId;
    /** @var list<array{string, mixed}> */
    private array $jobs = [];
    /** @var list<string> */
    private array $notificationCalls = [];
    /** @var ?\Closure(): void */
    private ?\Closure $onJobAdded = null;

    protected function setUp(): void
    {
        $config = $this->memoryUserConfig($this->store);
        $this->logger = new MemoryLogger();
        $this->users = new UserStore($config);
        $this->secrets = new SecretStore($config, $this->fakeCrypto(), $this->logger);
        $this->objects = new MemoryCalendarObjects();
        $this->http = new FakeHttpClient();
        $this->locking = new MemoryLockingProvider();
        $this->calendarId = $this->objects->addCalendar(self::UID, 'workoutcalendar', 'Training', null, ['VEVENT']);
    }

    public function testLoginStoresSecretsAndMovesToCalendarStep(): void
    {
        $this->queueSuccessfulLogin();

        $setup = $this->service()->login(self::UID, 'Anna@Example.de', 'geheim');

        self::assertSame(SetupStep::Calendar, $setup->step);
        self::assertTrue($setup->connected);
        self::assertSame('A***@Example.de', $setup->maskedEmail);
        self::assertSame(substr(hash('sha256', 'anna@example.de'), 0, 16), $setup->accountKey);
        self::assertEquals($setup, $this->users->setup(self::UID));
        $credentials = $this->secrets->credentials(self::UID);
        self::assertSame('Anna@Example.de', $credentials?->email);
        self::assertSame('geheim', $credentials->password);
        self::assertSame('C', $this->secrets->tokens(self::UID)?->clientId);
    }

    public function testLoginTrimsEmailEverywhere(): void
    {
        $this->queueSuccessfulLogin();

        $setup = $this->service()->login(self::UID, '  Anna@Example.de ', 'geheim');

        self::assertSame('Anna@Example.de', $this->secrets->credentials(self::UID)?->email);
        self::assertSame('A***@Example.de', $setup->maskedEmail);
        self::assertSame(substr(hash('sha256', 'anna@example.de'), 0, 16), $setup->accountKey);
    }

    public function testLoginInWizardDoesNotArmQueue(): void
    {
        $this->queueSuccessfulLogin();

        $this->service()->login(self::UID, 'a@b.de', 'geheim');

        self::assertNull($this->users->state(self::UID)->nextRunAt);
        self::assertSame([], $this->users->dueUsers(self::NOW + 100, 10));
    }

    public function testRejectedLoginStoresNothing(): void
    {
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, false, 'abc', 'x***@b.de', $this->calendarId));
        $before = $this->store;
        $this->http->queue(FakeHttpClient::json(200, ['responseStatus' => ['type' => 'INVALID_USERNAME_PASSWORD']]));

        try {
            $this->service()->login(self::UID, 'a@b.de', 'falsch');
            self::fail('GarminException erwartet');
        } catch (GarminException $e) {
            self::assertSame(FailureCategory::InvalidCredentials, $e->category);
        }

        self::assertSame($before, $this->store);
        self::assertNull($this->secrets->credentials(self::UID));
        self::assertNull($this->secrets->tokens(self::UID));
        self::assertSame([], $this->notificationCalls);
    }

    public function testLoginClearsPause(): void
    {
        $this->connected();
        $state = new SyncState();
        $state->pausedFor = 'INVALID_CREDENTIALS';
        $state->notifiedCategory = 'INVALID_CREDENTIALS';
        $this->users->saveState(self::UID, $state);
        $this->queueSuccessfulLogin();

        $this->service()->login(self::UID, 'a@b.de', 'neu');

        self::assertNull($this->users->state(self::UID)->pausedFor);
        self::assertNull($this->users->state(self::UID)->notifiedCategory);
        self::assertSame(['markProcessed:' . self::UID], $this->notificationCalls);
    }

    public function testLoginClearsStaleFailure(): void
    {
        $this->connected();
        $state = new SyncState();
        $state->initialized = true;
        $state->failureCategory = 'SERVER_ERROR';
        $state->failureStep = 'api.activities';
        $state->failureMessage = 'HTTP 500';
        $state->failureSince = 5;
        $state->failureCount = 3;
        $state->failureReportedAt = 6;
        $this->users->saveState(self::UID, $state);
        $this->queueSuccessfulLogin();

        $this->service()->login(self::UID, 'a@b.de', 'geheim');

        $state = $this->users->state(self::UID);
        self::assertTrue($state->initialized);
        self::assertNull($state->failureCategory);
        self::assertNull($state->failureStep);
        self::assertNull($state->failureMessage);
        self::assertNull($state->failureSince);
        self::assertSame(0, $state->failureCount);
        self::assertNull($state->failureReportedAt);
    }

    public function testOtherAccountStartsFreshState(): void
    {
        $this->connected();
        $this->users->saveSettings(self::UID, new SyncSettings());
        $settings = $this->users->settings(self::UID)->toArray();
        $state = new SyncState();
        $state->initialized = true;
        $state->lastSuccessAt = 5;
        $state->backfillDone = true;
        $this->users->saveState(self::UID, $state);
        $this->queueSuccessfulLogin();

        $this->service()->login(self::UID, 'andere@b.de', 'geheim');

        $fresh = $this->users->state(self::UID);
        self::assertFalse($fresh->initialized);
        self::assertNull($fresh->lastSuccessAt);
        self::assertFalse($fresh->backfillDone);
        self::assertSame($settings, $this->users->settings(self::UID)->toArray());
        self::assertSame(substr(hash('sha256', 'andere@b.de'), 0, 16), $this->users->setup(self::UID)->accountKey);
    }

    public function testSameAccountKeepsState(): void
    {
        $this->connected();
        $state = new SyncState();
        $state->initialized = true;
        $state->lastSuccessAt = 5;
        $this->users->saveState(self::UID, $state);
        $this->queueSuccessfulLogin();

        $this->service()->login(self::UID, ' A@B.de ', 'geheim');

        $kept = $this->users->state(self::UID);
        self::assertTrue($kept->initialized);
        self::assertSame(5, $kept->lastSuccessAt);
    }

    public function testReloginWithExistingCalendarSkipsWizard(): void
    {
        $this->connected();
        $this->queueSuccessfulLogin();

        $setup = $this->service()->login(self::UID, 'a@b.de', 'geheim');

        self::assertSame(SetupStep::Done, $setup->step);
        self::assertSame($this->calendarId, $setup->calendarId);
    }

    public function testReloginWithExistingCalendarArmsQueue(): void
    {
        $this->connected();
        $this->queueSuccessfulLogin();

        $this->service()->login(self::UID, 'a@b.de', 'geheim');

        self::assertSame(self::NOW, $this->users->state(self::UID)->nextRunAt);
        self::assertSame([self::UID], $this->users->dueUsers(self::NOW, 10));
    }

    public function testReloginKeepsHistoryStep(): void
    {
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::History, false, $this->key(), null, $this->calendarId));
        $this->queueSuccessfulLogin();

        $setup = $this->service()->login(self::UID, 'a@b.de', 'geheim');

        self::assertSame(SetupStep::History, $setup->step);
        self::assertNull($this->users->state(self::UID)->nextRunAt);
    }

    public function testLogoutKeepsEventsByDefault(): void
    {
        $this->connected();
        $this->secrets->saveCredentials(self::UID, new Credentials('a@b.de', 'geheim'));
        $this->secrets->saveTokens(self::UID, new GarminTokens(Jwt::make([]), 'r', 'C'));
        $state = new SyncState();
        $state->initialized = true;
        $state->nextRunAt = self::NOW;
        $this->users->saveState(self::UID, $state);
        $this->objects->put($this->calendarId, 'garmin-1.ics', 'x');

        $this->service()->logout(self::UID, Cleanup::None);

        self::assertNull($this->secrets->credentials(self::UID));
        self::assertNull($this->secrets->tokens(self::UID));
        $setup = $this->users->setup(self::UID);
        self::assertFalse($setup->connected);
        self::assertSame($this->calendarId, $setup->calendarId);
        self::assertNull($setup->maintenance);
        self::assertTrue($this->users->state(self::UID)->initialized);
        self::assertNull($this->users->state(self::UID)->nextRunAt);
        self::assertSame([], $this->users->dueUsers(self::NOW + 100, 10));
        self::assertSame([], $this->jobs);
        self::assertArrayHasKey('garmin-1.ics', $this->objects->objects[$this->calendarId]);
    }

    public function testLogoutWithEventsQueuesRemoval(): void
    {
        $this->connected();
        $state = new SyncState();
        $state->initialized = true;
        $state->nextRunAt = self::NOW;
        $this->users->saveState(self::UID, $state);

        $this->service()->logout(self::UID, Cleanup::Events);

        self::assertSame('remove', $this->users->setup(self::UID)->maintenance);
        self::assertFalse($this->users->setup(self::UID)->connected);
        self::assertSame(
            [[CalendarMaintenanceJob::class, ['uid' => self::UID, 'action' => 'remove', 'from' => $this->calendarId, 'to' => null]]],
            $this->jobs,
        );
        self::assertEquals(new SyncState(), $this->users->state(self::UID));
    }

    public function testLogoutWithEventsAndNoCalendarQueuesNothing(): void
    {
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Calendar, true, $this->key()));

        $this->service()->logout(self::UID, Cleanup::Events);

        self::assertSame([], $this->jobs);
    }

    public function testLogoutCalendarOnlyForOwnCalendar(): void
    {
        $this->connected(createdByApp: false);
        $this->secrets->saveCredentials(self::UID, new Credentials('a@b.de', 'geheim'));

        try {
            $this->service()->logout(self::UID, Cleanup::Calendar);
            self::fail('InvalidArgumentException erwartet');
        } catch (\InvalidArgumentException) {
        }

        self::assertNotNull($this->secrets->credentials(self::UID));
        self::assertTrue($this->users->setup(self::UID)->connected);
        self::assertFalse($this->objects->calendars[$this->calendarId]['trashed']);
    }

    public function testLogoutCalendarDeletesCalendarCreatedByApp(): void
    {
        $this->connected(createdByApp: true);
        $state = new SyncState();
        $state->initialized = true;
        $this->users->saveState(self::UID, $state);

        $this->service()->logout(self::UID, Cleanup::Calendar);

        self::assertTrue($this->objects->calendars[$this->calendarId]['trashed']);
        $setup = $this->users->setup(self::UID);
        self::assertNull($setup->calendarId);
        self::assertFalse($setup->calendarCreatedByApp);
        self::assertFalse($setup->connected);
        self::assertEquals(new SyncState(), $this->users->state(self::UID));
    }

    public function testFailedCalendarCleanupKeepsConnection(): void
    {
        $this->connected(createdByApp: true);
        $this->secrets->saveCredentials(self::UID, new Credentials('a@b.de', 'geheim'));
        $this->objects->deleteCalendarFailure = new \RuntimeException('CalDAV unreachable');

        try {
            $this->service()->logout(self::UID, Cleanup::Calendar);
            self::fail('RuntimeException erwartet');
        } catch (\RuntimeException) {
        }

        self::assertNotNull($this->secrets->credentials(self::UID));
        self::assertTrue($this->users->setup(self::UID)->connected);
        self::assertSame($this->calendarId, $this->users->setup(self::UID)->calendarId);
        self::assertSame([], $this->notificationCalls);
    }

    public function testLogoutKeepsFailureMarkers(): void
    {
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, $this->key(), 'a***@b.de', $this->calendarId, true, null, 'move', true));

        $this->service()->logout(self::UID, Cleanup::None);

        self::assertSame('move', $this->users->setup(self::UID)->maintenanceFailed);
        self::assertTrue($this->users->setup(self::UID)->calendarMissing);
    }

    public function testLoginKeepsFailureMarkers(): void
    {
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Calendar, false, $this->key(), 'a***@b.de', null, false, null, 'remove', true));
        $this->queueSuccessfulLogin();

        $setup = $this->service()->login(self::UID, 'a@b.de', 'geheim');

        self::assertSame('remove', $setup->maintenanceFailed);
        self::assertTrue($setup->calendarMissing);
    }

    public function testResetStartsWizardFromBeginning(): void
    {
        $this->connected(createdByApp: true);
        $this->secrets->saveCredentials(self::UID, new Credentials('a@b.de', 'geheim'));
        $this->users->saveSettings(self::UID, new SyncSettings());
        $state = new SyncState();
        $state->nextRunAt = self::NOW;
        $this->users->saveState(self::UID, $state);

        $this->service()->reset(self::UID, Cleanup::Events);

        self::assertSame(['setup'], array_keys($this->store[self::UID] ?? []), 'nur die Entfernen-Markierung bleibt');
        self::assertSame(SetupStep::Garmin, $this->users->setup(self::UID)->step);
        self::assertFalse($this->users->setup(self::UID)->connected);
        self::assertNull($this->secrets->credentials(self::UID));
        self::assertSame([], $this->users->dueUsers(self::NOW + 100, 10));
        self::assertSame(
            [[CalendarMaintenanceJob::class, ['uid' => self::UID, 'action' => 'remove', 'from' => $this->calendarId, 'to' => null]]],
            $this->jobs,
        );
    }

    public function testResetWithEventsKeepsRemovalMarker(): void
    {
        $this->connected(createdByApp: true);

        $this->service()->reset(self::UID, Cleanup::Events);

        $setup = $this->users->setup(self::UID);
        self::assertSame('remove', $setup->maintenance);
        self::assertSame(SetupStep::Garmin, $setup->step);
        self::assertFalse($setup->connected);
        self::assertNull($setup->calendarId);
        self::assertNull($this->users->state(self::UID)->nextRunAt);
        self::assertNull($this->secrets->credentials(self::UID));
    }

    public function testResetCalendarOnForeignCalendarChangesNothing(): void
    {
        $this->connected(createdByApp: false);
        $this->secrets->saveCredentials(self::UID, new Credentials('a@b.de', 'geheim'));
        $before = $this->store;

        try {
            $this->service()->reset(self::UID, Cleanup::Calendar);
            self::fail('InvalidArgumentException erwartet');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame($before, $this->store);
        self::assertFalse($this->objects->calendars[$this->calendarId]['trashed']);
    }

    public function testLogoutAndResetDismissNotification(): void
    {
        $this->connected();

        $state = new SyncState();
        $state->notifiedCategory = 'SERVER_ERROR';
        $this->users->saveState(self::UID, $state);

        $this->service()->logout(self::UID, Cleanup::None);
        self::assertNull($this->users->state(self::UID)->notifiedCategory);
        $this->service()->reset(self::UID, Cleanup::None);

        self::assertSame(['markProcessed:' . self::UID, 'markProcessed:' . self::UID], $this->notificationCalls);
    }

    public function testCleanupDuringMaintenanceIsRejected(): void
    {
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, $this->key(), 'a***@b.de', $this->calendarId, true, 'move'));
        $this->secrets->saveCredentials(self::UID, new Credentials('a@b.de', 'geheim'));
        $before = $this->store;
        $service = $this->service();

        foreach ([
            fn () => $service->logout(self::UID, Cleanup::Events),
            fn () => $service->logout(self::UID, Cleanup::Calendar),
            fn () => $service->reset(self::UID, Cleanup::Events),
            fn () => $service->reset(self::UID, Cleanup::Calendar),
        ] as $call) {
            try {
                $call();
                self::fail('InvalidArgumentException erwartet');
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertSame($before, $this->store);
        self::assertSame([], $this->jobs);
        self::assertFalse($this->objects->calendars[$this->calendarId]['trashed']);
        self::assertSame([], $this->notificationCalls);
    }

    public function testResetWithoutCleanupKeepsMaintenanceMarker(): void
    {
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, $this->key(), 'a***@b.de', $this->calendarId, true, 'move'));

        $this->service()->reset(self::UID, Cleanup::None);

        $setup = $this->users->setup(self::UID);
        self::assertSame('move', $setup->maintenance);
        self::assertSame(SetupStep::Garmin, $setup->step);
        self::assertFalse($setup->connected);
        self::assertNull($setup->calendarId);
        self::assertNull($setup->accountKey);
    }

    public function testLifecycleWaitsForRunningSync(): void
    {
        $this->connected();
        $this->secrets->saveCredentials(self::UID, new Credentials('a@b.de', 'geheim'));
        $before = $this->store;
        $this->locking->hold('workoutcalendar/sync/' . md5(self::UID));
        $service = $this->service();

        foreach ([
            fn () => $service->logout(self::UID, Cleanup::None),
            fn () => $service->reset(self::UID, Cleanup::Calendar),
        ] as $call) {
            try {
                $call();
                self::fail('PersonBusyException erwartet');
            } catch (PersonBusyException) {
            }
        }

        self::assertSame($before, $this->store);
        self::assertFalse($this->objects->calendars[$this->calendarId]['trashed']);
        self::assertSame([], $this->jobs);
        self::assertSame([], $this->notificationCalls);
    }

    public function testLoginWaitsForRunningSyncAndStoresNothing(): void
    {
        $this->connected();
        $before = $this->store;
        $this->locking->hold('workoutcalendar/sync/' . md5(self::UID));
        $this->queueSuccessfulLogin();

        try {
            $this->service()->login(self::UID, 'a@b.de', 'geheim');
            self::fail('PersonBusyException erwartet');
        } catch (PersonBusyException) {
        }

        self::assertSame($before, $this->store);
        self::assertNull($this->secrets->credentials(self::UID));
    }

    public function testLoginDuringRunDoesNotContactGarmin(): void
    {
        $this->connected();
        $this->locking->hold('workoutcalendar/sync/' . md5(self::UID));
        $this->queueSuccessfulLogin();

        try {
            $this->service()->login(self::UID, 'a@b.de', 'geheim');
            self::fail('PersonBusyException erwartet');
        } catch (PersonBusyException) {
        }

        self::assertSame([], $this->http->requests);
    }

    public function testLogoutSavesRemovalMarkerBeforeQueueing(): void
    {
        $this->connected();
        $markerWhenQueued = [];
        $this->onJobAdded = function () use (&$markerWhenQueued): void {
            $markerWhenQueued[] = $this->users->setup(self::UID)->maintenance;
        };

        $this->service()->logout(self::UID, Cleanup::Events);

        self::assertSame(['remove'], $markerWhenQueued);
        self::assertSame('remove', $this->users->setup(self::UID)->maintenance);
    }

    public function testFailedQueueingRestoresSetup(): void
    {
        $this->connected();
        $this->secrets->saveCredentials(self::UID, new Credentials('a@b.de', 'geheim'));
        $before = $this->store;
        $this->onJobAdded = static function (): void {
            throw new \RuntimeException('Database unreachable');
        };

        try {
            $this->service()->logout(self::UID, Cleanup::Events);
            self::fail('RuntimeException erwartet');
        } catch (\RuntimeException) {
        }

        self::assertSame($before, $this->store);
    }

    public function testLifecycleReleasesLock(): void
    {
        $this->connected();
        $this->queueSuccessfulLogin();
        $service = $this->service();

        $service->login(self::UID, 'a@b.de', 'geheim');
        $service->logout(self::UID, Cleanup::None);
        $service->reset(self::UID, Cleanup::None);

        self::assertSame([], $this->locking->held());
    }

    private function key(): string
    {
        return substr(hash('sha256', 'a@b.de'), 0, 16);
    }

    private function connected(bool $createdByApp = true): void
    {
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, $this->key(), 'a***@b.de', $this->calendarId, $createdByApp));
    }

    private function queueSuccessfulLogin(): void
    {
        $this->http->queue(
            FakeHttpClient::json(200, ['responseStatus' => ['type' => 'SUCCESSFUL'], 'serviceTicketId' => 'ST-42']),
            FakeHttpClient::json(200, ['access_token' => Jwt::make(['client_id' => 'C']), 'refresh_token' => 'r']),
        );
    }

    private function service(): AccountService
    {
        $http = $this->http;
        $provider = new class ($http) implements HttpClientProvider {
            public function __construct(private readonly HttpClient $http)
            {
            }

            public function create(): HttpClient
            {
                return $this->http;
            }
        };
        $jobList = $this->createMock(IJobList::class);
        $jobList->method('add')->willReturnCallback(function (string $job, $argument = null): void {
            $this->jobs[] = [$job, $argument];
            if ($this->onJobAdded !== null) {
                ($this->onJobAdded)();
            }
        });
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        return new AccountService(
            new GarminClientFactory($provider, $this->secrets, $this->logger),
            $this->secrets,
            $this->users,
            new CalendarService($this->objects),
            $jobList,
            new FailureNotifications($this->notificationManager(), $time),
            $time,
            new PersonLock($this->locking),
        );
    }

    private function notificationManager(): IManager
    {
        $manager = $this->createMock(IManager::class);
        $manager->method('createNotification')->willReturnCallback(static fn (): INotification => new RecordingNotification());
        $manager->method('markProcessed')->willReturnCallback(function (INotification $n): void {
            $this->notificationCalls[] = 'markProcessed:' . $n->getUser();
        });

        return $manager;
    }
}
