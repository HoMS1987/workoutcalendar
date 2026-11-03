<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Service;

use OCA\WorkoutCalendar\BackgroundJob\CalendarMaintenanceJob;
use OCA\WorkoutCalendar\BackgroundJob\MfaCleanupJob;
use OCA\WorkoutCalendar\CalDav\CalendarService;
use OCA\WorkoutCalendar\Garmin\Auth\Credentials;
use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Garmin\Auth\MfaChallenge;
use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Garmin\Http\HttpClient;
use OCA\WorkoutCalendar\Garmin\Http\HttpResponse;
use OCA\WorkoutCalendar\Garmin\Http\NetworkException;
use OCA\WorkoutCalendar\Notification\FailureNotifications;
use OCA\WorkoutCalendar\Service\AccountService;
use OCA\WorkoutCalendar\Service\Cleanup;
use OCA\WorkoutCalendar\Service\GarminClientFactory;
use OCA\WorkoutCalendar\Service\HttpClientProvider;
use OCA\WorkoutCalendar\Service\LoginOutcome;
use OCA\WorkoutCalendar\Service\PendingMfaSaver;
use OCA\WorkoutCalendar\Service\PersonBusyException;
use OCA\WorkoutCalendar\Service\PersonLock;
use OCA\WorkoutCalendar\Storage\ApiKeyRecord;
use OCA\WorkoutCalendar\Storage\MfaOrigin;
use OCA\WorkoutCalendar\Storage\PendingMfa;
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
    private const API_KEY = 'personal-key-0123456789';

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
    /** @var list<array{string, int, mixed}> */
    private array $scheduled = [];
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

        $setup = $this->signIn('Anna@Example.de', 'secret');

        self::assertSame(SetupStep::Calendar, $setup->step);
        self::assertTrue($setup->connected);
        self::assertSame('A***@Example.de', $setup->maskedEmail);
        self::assertSame(substr(hash('sha256', 'anna@example.de'), 0, 16), $setup->accountKey);
        self::assertEquals($setup, $this->users->setup(self::UID));
        $credentials = $this->secrets->credentials(self::UID);
        self::assertSame('Anna@Example.de', $credentials?->email);
        self::assertSame('secret', $credentials->password);
        self::assertSame('C', $this->secrets->tokens(self::UID)?->clientId);
    }

    public function testLoginTrimsEmailEverywhere(): void
    {
        $this->queueSuccessfulLogin();

        $setup = $this->signIn('  Anna@Example.de ', 'secret');

        self::assertSame('Anna@Example.de', $this->secrets->credentials(self::UID)?->email);
        self::assertSame('A***@Example.de', $setup->maskedEmail);
        self::assertSame(substr(hash('sha256', 'anna@example.de'), 0, 16), $setup->accountKey);
    }

    public function testLoginInWizardDoesNotArmQueue(): void
    {
        $this->queueSuccessfulLogin();

        $this->service()->login(self::UID, 'a@b.de', 'secret');

        self::assertNull($this->users->state(self::UID)->nextRunAt);
        self::assertSame([], $this->users->dueUsers(self::NOW + 100, 10));
    }

    public function testRejectedLoginStoresNothing(): void
    {
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, false, 'abc', 'x***@b.de', $this->calendarId));
        $before = $this->store;
        $this->http->queue(FakeHttpClient::json(200, ['responseStatus' => ['type' => 'INVALID_USERNAME_PASSWORD']]));

        try {
            $this->service()->login(self::UID, 'a@b.de', 'wrong');
            self::fail('GarminException expected');
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

        $this->service()->login(self::UID, 'a@b.de', 'new');

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

        $this->service()->login(self::UID, 'a@b.de', 'secret');

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

        $this->service()->login(self::UID, 'other@b.de', 'secret');

        $fresh = $this->users->state(self::UID);
        self::assertFalse($fresh->initialized);
        self::assertNull($fresh->lastSuccessAt);
        self::assertFalse($fresh->backfillDone);
        self::assertSame($settings, $this->users->settings(self::UID)->toArray());
        self::assertSame(substr(hash('sha256', 'other@b.de'), 0, 16), $this->users->setup(self::UID)->accountKey);
    }

    public function testOtherAccountDropsTheRememberedCookiesOfTheOldOne(): void
    {
        $this->connected();
        $this->secrets->saveSsoCookies(self::UID, ['old-account-browser']);
        $this->queueSuccessfulLogin();

        $this->service()->login(self::UID, 'other@b.de', 'secret');

        self::assertSame([], $this->secrets->ssoCookies(self::UID));
    }

    public function testSameAccountKeepsTheRememberedCookies(): void
    {
        $this->connected();
        $this->secrets->saveSsoCookies(self::UID, ['browser']);
        $this->queueSuccessfulLogin();

        $this->service()->login(self::UID, 'a@b.de', 'secret');

        self::assertSame(['browser'], $this->secrets->ssoCookies(self::UID));
    }

    public function testSameAccountKeepsState(): void
    {
        $this->connected();
        $state = new SyncState();
        $state->initialized = true;
        $state->lastSuccessAt = 5;
        $this->users->saveState(self::UID, $state);
        $this->queueSuccessfulLogin();

        $this->service()->login(self::UID, ' A@B.de ', 'secret');

        $kept = $this->users->state(self::UID);
        self::assertTrue($kept->initialized);
        self::assertSame(5, $kept->lastSuccessAt);
    }

    public function testReloginWithExistingCalendarSkipsWizard(): void
    {
        $this->connected();
        $this->queueSuccessfulLogin();

        $setup = $this->signIn('a@b.de', 'secret');

        self::assertSame(SetupStep::Done, $setup->step);
        self::assertSame($this->calendarId, $setup->calendarId);
    }

    public function testReloginWithExistingCalendarArmsQueue(): void
    {
        $this->connected();
        $this->queueSuccessfulLogin();

        $this->service()->login(self::UID, 'a@b.de', 'secret');

        self::assertSame(self::NOW, $this->users->state(self::UID)->nextRunAt);
        self::assertSame([self::UID], $this->users->dueUsers(self::NOW, 10));
    }

    public function testReloginKeepsHistoryStep(): void
    {
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::History, false, $this->key(), null, $this->calendarId));
        $this->queueSuccessfulLogin();

        $setup = $this->signIn('a@b.de', 'secret');

        self::assertSame(SetupStep::History, $setup->step);
        self::assertNull($this->users->state(self::UID)->nextRunAt);
    }

    public function testLogoutKeepsEventsByDefault(): void
    {
        $this->connected();
        $this->secrets->saveCredentials(self::UID, new Credentials('a@b.de', 'secret'));
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
        $this->secrets->saveCredentials(self::UID, new Credentials('a@b.de', 'secret'));

        try {
            $this->service()->logout(self::UID, Cleanup::Calendar);
            self::fail('InvalidArgumentException expected');
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
        $this->secrets->saveCredentials(self::UID, new Credentials('a@b.de', 'secret'));
        $this->objects->deleteCalendarFailure = new \RuntimeException('CalDAV unreachable');

        try {
            $this->service()->logout(self::UID, Cleanup::Calendar);
            self::fail('RuntimeException expected');
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

        $setup = $this->signIn('a@b.de', 'secret');

        self::assertSame('remove', $setup->maintenanceFailed);
        self::assertTrue($setup->calendarMissing);
    }

    public function testResetStartsWizardFromBeginning(): void
    {
        $this->connected(createdByApp: true);
        $this->secrets->saveCredentials(self::UID, new Credentials('a@b.de', 'secret'));
        $this->users->saveSettings(self::UID, new SyncSettings());
        $state = new SyncState();
        $state->nextRunAt = self::NOW;
        $this->users->saveState(self::UID, $state);

        $this->service()->reset(self::UID, Cleanup::Events);

        self::assertSame(['setup'], array_keys($this->store[self::UID] ?? []), 'only the removal marker remains');
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
        $this->secrets->saveCredentials(self::UID, new Credentials('a@b.de', 'secret'));
        $before = $this->store;

        try {
            $this->service()->reset(self::UID, Cleanup::Calendar);
            self::fail('InvalidArgumentException expected');
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
        $this->secrets->saveCredentials(self::UID, new Credentials('a@b.de', 'secret'));
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
                self::fail('InvalidArgumentException expected');
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
        $this->secrets->saveCredentials(self::UID, new Credentials('a@b.de', 'secret'));
        $before = $this->store;
        $this->locking->hold('workoutcalendar/sync/' . md5(self::UID));
        $service = $this->service();

        foreach ([
            fn () => $service->logout(self::UID, Cleanup::None),
            fn () => $service->reset(self::UID, Cleanup::Calendar),
        ] as $call) {
            try {
                $call();
                self::fail('PersonBusyException expected');
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
            $this->service()->login(self::UID, 'a@b.de', 'secret');
            self::fail('PersonBusyException expected');
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
            $this->service()->login(self::UID, 'a@b.de', 'secret');
            self::fail('PersonBusyException expected');
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
        $this->secrets->saveCredentials(self::UID, new Credentials('a@b.de', 'secret'));
        $before = $this->store;
        $this->onJobAdded = static function (): void {
            throw new \RuntimeException('Database unreachable');
        };

        try {
            $this->service()->logout(self::UID, Cleanup::Events);
            self::fail('RuntimeException expected');
        } catch (\RuntimeException) {
        }

        self::assertSame($before, $this->store);
    }

    public function testFailedRestoreKeepsOriginalException(): void
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
        $this->connected();
        $this->onJobAdded = static function () use (&$failSetupWrites): void {
            $failSetupWrites = true;

            throw new \RuntimeException('Database unreachable');
        };

        try {
            $this->service()->logout(self::UID, Cleanup::Events);
            self::fail('RuntimeException expected');
        } catch (\RuntimeException $e) {
            self::assertSame('Database unreachable', $e->getMessage());
        }

        $errors = $this->logger->byLevel('error');
        self::assertCount(1, $errors);
        self::assertSame(\RuntimeException::class, $errors[0]['context']['exception'] ?? null);
        self::assertSame([], $this->locking->held());
    }

    public function testLifecycleReleasesLock(): void
    {
        $this->connected();
        $this->queueSuccessfulLogin();
        $service = $this->service();

        $service->login(self::UID, 'a@b.de', 'secret');
        $service->logout(self::UID, Cleanup::None);
        $service->reset(self::UID, Cleanup::None);

        self::assertSame([], $this->locking->held());
    }

    public function testLoginWithMfaKeepsCredentialsOnlyInThePendingRequest(): void
    {
        $this->http->cookies = ['sso-session'];
        $this->http->queue(FakeHttpClient::json(200, [
            'responseStatus' => ['type' => 'MFA_REQUIRED'],
            'customerMfaInfo' => ['mfaLastMethodUsed' => 'email'],
        ]));
        $before = $this->users->setup(self::UID);

        $outcome = $this->service()->login(self::UID, ' Anna@Example.de ', 'secret');

        self::assertSame(LoginOutcome::CodeRequired, $outcome);
        self::assertNull($this->secrets->credentials(self::UID));
        self::assertNull($this->secrets->tokens(self::UID));
        self::assertEquals($before, $this->users->setup(self::UID));
        $pending = $this->secrets->pendingMfa(self::UID, self::NOW);
        self::assertNotNull($pending);
        self::assertSame(MfaOrigin::Setup, $pending->origin);
        self::assertSame('Anna@Example.de', $pending->credentials?->email);
        self::assertSame('secret', $pending->credentials->password);
        self::assertSame(['sso-session'], $pending->challenge->cookies);
        self::assertSame('email', $pending->challenge->method);
        self::assertSame([[MfaCleanupJob::class, self::NOW + 1800, ['uid' => self::UID]]], $this->scheduled);
        self::assertTrue($this->users->state(self::UID)->mfaAccount);
        self::assertSame([], $this->notificationCalls);
    }

    public function testCodeSentByTheSetupLoginCountsForTheRequestLimit(): void
    {
        $this->users->saveMfaRequests(self::UID, [self::NOW - 600]);
        $this->http->queue(FakeHttpClient::json(200, [
            'responseStatus' => ['type' => 'MFA_REQUIRED'],
            'customerMfaInfo' => ['mfaLastMethodUsed' => 'email'],
        ]));

        $this->service()->login(self::UID, 'a@b.de', 'secret');

        self::assertSame([self::NOW - 600, self::NOW], $this->users->mfaRequests(self::UID));
    }

    public function testSetupLoginWithoutCodeDoesNotCountForTheRequestLimit(): void
    {
        $this->http->queue(
            FakeHttpClient::json(200, ['responseStatus' => ['type' => 'SUCCESSFUL'], 'serviceTicketId' => 'ST-1']),
            FakeHttpClient::json(200, ['access_token' => Jwt::make(['client_id' => 'C']), 'refresh_token' => 'r']),
        );

        $this->service()->login(self::UID, 'a@b.de', 'secret');

        self::assertSame([], $this->users->mfaRequests(self::UID));
    }

    public function testLoginWithMfaForAnotherAccountLeavesTheFlagOfTheCurrentOne(): void
    {
        $this->connected();
        $this->http->queue(FakeHttpClient::json(200, ['responseStatus' => ['type' => 'MFA_REQUIRED']]));

        self::assertSame(LoginOutcome::CodeRequired, $this->service()->login(self::UID, 'other@b.de', 'secret'));

        self::assertFalse($this->users->state(self::UID)->mfaAccount);
        self::assertNotNull($this->secrets->pendingMfa(self::UID, self::NOW));
    }

    public function testLoginWithoutCodeClearsTheMfaFlag(): void
    {
        $this->connected();
        $state = new SyncState();
        $state->mfaAccount = true;
        $state->mfaMethod = 'email';
        $this->users->saveState(self::UID, $state);
        $this->queueSuccessfulLogin();

        self::assertSame(LoginOutcome::SignedIn, $this->service()->login(self::UID, 'a@b.de', 'secret'));

        $state = $this->users->state(self::UID);
        self::assertFalse($state->mfaAccount);
        self::assertNull($state->mfaMethod);
    }

    public function testCompleteLoginKeepsTheMfaFlagOfTheSameAccount(): void
    {
        $this->connected();
        $state = new SyncState();
        $state->mfaAccount = true;
        $state->mfaMethod = 'sms';
        $this->users->saveState(self::UID, $state);

        $this->service()->completeLogin(self::UID, 'a@b.de', new Credentials('a@b.de', 'secret'), new GarminTokens(Jwt::make([]), 'r', 'C'));

        $state = $this->users->state(self::UID);
        self::assertTrue($state->mfaAccount);
        self::assertSame('sms', $state->mfaMethod);
    }

    public function testCompleteLoginKeepsTheDerivedFlagOfA10Install(): void
    {
        $this->connected();
        $state = new SyncState();
        $state->pausedFor = 'MFA_REQUIRED';
        $state->mfaAccount = false;
        $this->users->saveState(self::UID, $state);

        $this->service()->completeLogin(self::UID, 'a@b.de', new Credentials('a@b.de', 'secret'), new GarminTokens(Jwt::make([]), 'r', 'C'));

        $state = $this->users->state(self::UID);
        self::assertNull($state->pausedFor);
        self::assertTrue($state->mfaAccount);
    }

    public function testWidgetMfaWithoutChallengeStillFails(): void
    {
        $before = $this->store;
        $page = static fn (string $html): HttpResponse => new HttpResponse(200, ['content-type' => 'text/html'], $html);
        $this->http->queue(
            // Mobile login is blocked (not final), so the widget login runs and shows the MFA page.
            new HttpResponse(429, [], 'Too many requests'),
            $page('<html>embed</html>'),
            $page('<html><form><input type="hidden" name="_csrf" value="csrf-123"/></form></html>'),
            $page('<html><title>Enter MFA code for login</title></html>'),
        );

        try {
            $this->service()->login(self::UID, 'a@b.de', 'secret');
            self::fail('GarminException expected');
        } catch (GarminException $e) {
            self::assertSame(FailureCategory::MfaRequired, $e->category);
            self::assertNull($e->mfaChallenge);
        }

        self::assertSame(['mobile.login', 'widget.embed', 'widget.signin-page', 'widget.signin-post'], $this->http->steps());
        self::assertSame($before, $this->store);
        self::assertSame([], $this->scheduled);
    }

    public function testConnectApiKeyOnSetupContinuesAtTheCalendar(): void
    {
        $this->queueKeyExchange('garmin-123');

        $setup = $this->service()->connectApiKey(self::UID, self::API_KEY);

        self::assertSame(SetupStep::Calendar, $setup->step);
        self::assertTrue($setup->connected);
        self::assertSame(AccountService::accountKey('garmin-123'), $setup->accountKey);
        self::assertSame('garmin:' . AccountService::accountKey('garmin-123'), $setup->identity);
        self::assertNull($setup->maskedEmail);
        self::assertEquals($setup, $this->users->setup(self::UID));
        self::assertEquals(new ApiKeyRecord(self::API_KEY, self::NOW), $this->secrets->apiKey(self::UID));
        self::assertSame('personal-api', $this->secrets->tokens(self::UID)?->clientId);
        self::assertNull($this->secrets->credentials(self::UID));
        self::assertNull($this->users->state(self::UID)->nextRunAt);
        self::assertSame(['apikey.token'], $this->http->steps());
    }

    public function testConnectApiKeyWithoutGarminIdUsesARandomAccountKey(): void
    {
        $this->queueKeyExchange(null);

        $setup = $this->service()->connectApiKey(self::UID, self::API_KEY);

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string)$setup->accountKey);
        self::assertNull($setup->identity);
        self::assertSame(SetupStep::Calendar, $setup->step);
    }

    public function testSwitchToApiKeyKeepsAccountKeyCalendarAndEvents(): void
    {
        $this->connected();
        $state = new SyncState();
        $state->initialized = true;
        $state->lastSuccessAt = 5;
        $this->users->saveState(self::UID, $state);
        $this->objects->put($this->calendarId, 'garmin-1.ics', 'x');
        $this->queueKeyExchange('garmin-123');

        $setup = $this->service()->connectApiKey(self::UID, self::API_KEY);

        self::assertSame($this->key(), $setup->accountKey);
        self::assertSame($this->calendarId, $setup->calendarId);
        self::assertTrue($setup->calendarCreatedByApp);
        self::assertSame(SetupStep::Done, $setup->step);
        self::assertSame('garmin:' . AccountService::accountKey('garmin-123'), $setup->identity);
        $kept = $this->users->state(self::UID);
        self::assertTrue($kept->initialized);
        self::assertSame(5, $kept->lastSuccessAt);
        self::assertSame(self::NOW, $kept->nextRunAt);
        self::assertArrayHasKey('garmin-1.ics', $this->objects->objects[$this->calendarId]);
    }

    public function testSwitchToApiKeyDeletesPasswordCookiesPendingRequestAndFlag(): void
    {
        $this->connected();
        $this->secrets->saveCredentials(self::UID, new Credentials('a@b.de', 'secret'));
        $this->secrets->saveTokens(self::UID, new GarminTokens(Jwt::make([]), 'r', 'C'));
        $this->secrets->saveSsoCookies(self::UID, ['browser']);
        $this->secrets->savePendingMfa(self::UID, new PendingMfa(new MfaChallenge('email', ['sso']), MfaOrigin::Sync, self::NOW));
        $state = new SyncState();
        $state->mfaAccount = true;
        $state->mfaMethod = 'email';
        $state->pausedFor = 'MFA_REQUIRED';
        $state->notifiedCategory = 'MFA_REQUIRED';
        $state->failureCategory = 'MFA_REQUIRED';
        $state->failureStep = 'login.skipped';
        $state->failureCount = 2;
        $this->users->saveState(self::UID, $state);
        $this->queueKeyExchange('garmin-123');

        $this->service()->connectApiKey(self::UID, self::API_KEY);

        self::assertNull($this->secrets->credentials(self::UID));
        self::assertSame('personal-api', $this->secrets->tokens(self::UID)?->clientId);
        self::assertSame([], $this->secrets->ssoCookies(self::UID));
        self::assertNull($this->secrets->pendingMfa(self::UID, self::NOW));
        $state = $this->users->state(self::UID);
        self::assertFalse($state->mfaAccount);
        self::assertFalse($state->isMfaAccount());
        self::assertNull($state->mfaMethod);
        self::assertNull($state->pausedFor);
        self::assertNull($state->notifiedCategory);
        self::assertNull($state->failureCategory);
        self::assertNull($state->failureStep);
        self::assertSame(0, $state->failureCount);
        self::assertSame(self::NOW, $state->nextRunAt);
        self::assertSame(['markProcessed:' . self::UID], $this->notificationCalls);
    }

    public function testSwitchBackToPasswordDeletesTheKeyAndKeepsAccountKey(): void
    {
        $garminKey = AccountService::accountKey('garmin-123');
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, $garminKey, null, $this->calendarId, true, identity: 'garmin:' . $garminKey));
        $this->secrets->saveApiKey(self::UID, new ApiKeyRecord(self::API_KEY, self::NOW - 100));
        $this->secrets->saveTokens(self::UID, new GarminTokens(Jwt::make([]), null, 'personal-api'));
        $state = new SyncState();
        $state->initialized = true;
        $state->lastSuccessAt = 5;
        $this->users->saveState(self::UID, $state);
        $this->queueSuccessfulLogin();

        $setup = $this->signIn('a@b.de', 'secret');

        self::assertNull($this->secrets->apiKey(self::UID));
        self::assertSame('C', $this->secrets->tokens(self::UID)?->clientId);
        self::assertSame('secret', $this->secrets->credentials(self::UID)?->password);
        self::assertSame($garminKey, $setup->accountKey);
        self::assertSame('email:' . $this->key(), $setup->identity);
        self::assertSame('a***@b.de', $setup->maskedEmail);
        self::assertSame(SetupStep::Done, $setup->step);
        self::assertTrue($this->users->state(self::UID)->initialized);
        self::assertSame(5, $this->users->state(self::UID)->lastSuccessAt);
    }

    public function testOtherGarminIdIsAnAccountSwitch(): void
    {
        $garminKey = AccountService::accountKey('garmin-1');
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, $garminKey, null, $this->calendarId, true, identity: 'garmin:' . $garminKey));
        $state = new SyncState();
        $state->initialized = true;
        $state->lastSuccessAt = 5;
        $this->users->saveState(self::UID, $state);
        $this->queueKeyExchange('garmin-2');

        $setup = $this->service()->connectApiKey(self::UID, self::API_KEY);

        self::assertSame(AccountService::accountKey('garmin-2'), $setup->accountKey);
        self::assertSame('garmin:' . AccountService::accountKey('garmin-2'), $setup->identity);
        $fresh = $this->users->state(self::UID);
        self::assertFalse($fresh->initialized);
        self::assertNull($fresh->lastSuccessAt);
    }

    public function testSameGarminIdKeepsTheAccount(): void
    {
        $garminKey = AccountService::accountKey('garmin-1');
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, $garminKey, null, $this->calendarId, true, identity: 'garmin:' . $garminKey));
        $state = new SyncState();
        $state->initialized = true;
        $this->users->saveState(self::UID, $state);
        $this->queueKeyExchange('garmin-1');

        $setup = $this->service()->connectApiKey(self::UID, self::API_KEY);

        self::assertSame($garminKey, $setup->accountKey);
        self::assertTrue($this->users->state(self::UID)->initialized);
    }

    public function testDifferentIdentityKindsCountAsTheSameAccount(): void
    {
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, $this->key(), 'a***@b.de', $this->calendarId, true, identity: 'email:' . $this->key()));
        $state = new SyncState();
        $state->initialized = true;
        $state->lastSuccessAt = 5;
        $this->users->saveState(self::UID, $state);
        $this->queueKeyExchange('garmin-123');

        $this->service()->connectApiKey(self::UID, self::API_KEY);

        self::assertSame($this->key(), $this->users->setup(self::UID)->accountKey);
        self::assertSame(5, $this->users->state(self::UID)->lastSuccessAt);

        // Back to a password whose email differs from the first one: the identity kinds differ, same account.
        $this->queueSuccessfulLogin();
        $setup = $this->signIn('other@b.de', 'secret');

        self::assertSame($this->key(), $setup->accountKey);
        self::assertSame('email:' . AccountService::accountKey('other@b.de'), $setup->identity);
        self::assertSame(5, $this->users->state(self::UID)->lastSuccessAt);
    }

    public function testLegacySetupWithoutIdentityComparesWithTheAccountKey(): void
    {
        $legacy = new SetupState(SetupStep::Done, true, $this->key(), 'a***@b.de', $this->calendarId, true);
        $state = new SyncState();
        $state->initialized = true;
        $state->lastSuccessAt = 5;

        $this->users->saveSetup(self::UID, $legacy);
        $this->users->saveState(self::UID, $state);
        $this->queueSuccessfulLogin();
        $setup = $this->signIn('a@b.de', 'secret');
        self::assertSame($this->key(), $setup->accountKey);
        self::assertSame('email:' . $this->key(), $setup->identity);
        self::assertSame(5, $this->users->state(self::UID)->lastSuccessAt);

        $this->users->saveSetup(self::UID, $legacy);
        $this->users->saveState(self::UID, $state);
        $this->queueSuccessfulLogin();
        $setup = $this->signIn('other@b.de', 'secret');
        self::assertSame(AccountService::accountKey('other@b.de'), $setup->accountKey);
        self::assertNull($this->users->state(self::UID)->lastSuccessAt);
    }

    public function testKeyAccountWithoutGarminIdKeepsItsAccountKeyOnPasswordLogin(): void
    {
        $this->queueKeyExchange(null);
        $random = $this->service()->connectApiKey(self::UID, self::API_KEY)->accountKey;
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, $random, null, $this->calendarId, true));
        $state = new SyncState();
        $state->initialized = true;
        $state->lastSuccessAt = 5;
        $this->users->saveState(self::UID, $state);
        $this->queueSuccessfulLogin();

        $setup = $this->signIn('a@b.de', 'secret');

        self::assertSame($random, $setup->accountKey);
        self::assertSame(5, $this->users->state(self::UID)->lastSuccessAt);
    }

    public function testRejectedKeyKeepsEverythingAsItWas(): void
    {
        $this->connected();
        $this->secrets->saveCredentials(self::UID, new Credentials('a@b.de', 'secret'));
        $this->secrets->saveTokens(self::UID, new GarminTokens(Jwt::make([]), 'r', 'C'));
        $this->secrets->saveSsoCookies(self::UID, ['browser']);
        $before = $this->store;
        $this->http->queue(new HttpResponse(401, [], 'Unauthorized ' . self::API_KEY));

        try {
            $this->service()->connectApiKey(self::UID, self::API_KEY);
            self::fail('GarminException expected');
        } catch (GarminException $e) {
            self::assertSame(FailureCategory::ApiKeyRejected, $e->category);
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }

        self::assertSame($before, $this->store);
        self::assertSame([], $this->notificationCalls);
        self::assertStringNotContainsString(self::API_KEY, $this->logger->dump());
    }

    public function testGarminOutageKeepsEverythingAsItWas(): void
    {
        $this->connected();
        $this->secrets->saveCredentials(self::UID, new Credentials('a@b.de', 'secret'));
        $this->secrets->saveTokens(self::UID, new GarminTokens(Jwt::make([]), 'r', 'C'));
        $before = $this->store;
        $service = $this->service();

        foreach ([
            [new HttpResponse(429, [], 'Too many requests'), FailureCategory::Blocked],
            [new HttpResponse(503, [], 'Unavailable'), FailureCategory::ServerError],
            [new NetworkException('Could not connect with ' . self::API_KEY), FailureCategory::Network],
        ] as [$response, $category]) {
            $this->http->queue($response);
            try {
                $service->connectApiKey(self::UID, self::API_KEY);
                self::fail('GarminException expected');
            } catch (GarminException $e) {
                self::assertSame($category, $e->category);
            }
        }

        self::assertSame($before, $this->store);
        self::assertSame([], $this->notificationCalls);
        self::assertStringNotContainsString(self::API_KEY, $this->logger->dump());
    }

    public function testKeyIsTrimmedAndAnEmptyKeyNeverReachesGarmin(): void
    {
        $this->queueKeyExchange('garmin-123');

        $this->service()->connectApiKey(self::UID, " \t" . self::API_KEY . "\r\n");

        self::assertSame(self::API_KEY, $this->secrets->apiKey(self::UID)?->key);
        self::assertSame('ApiKey ' . self::API_KEY, $this->http->requests[0]->headers['Authorization'] ?? null);

        $before = $this->store;
        try {
            $this->service()->connectApiKey(self::UID, " \n\t ");
            self::fail('InvalidArgumentException expected');
        } catch (\InvalidArgumentException) {
        }

        self::assertCount(1, $this->http->requests);
        self::assertSame($before, $this->store);
    }

    public function testConnectApiKeyWaitsForRunningSync(): void
    {
        $this->connected();
        $before = $this->store;
        $this->locking->hold('workoutcalendar/sync/' . md5(self::UID));
        $this->queueKeyExchange('garmin-123');

        try {
            $this->service()->connectApiKey(self::UID, self::API_KEY);
            self::fail('PersonBusyException expected');
        } catch (PersonBusyException) {
        }

        self::assertSame([], $this->http->requests);
        self::assertSame($before, $this->store);
    }

    private function queueKeyExchange(?string $garminId): void
    {
        $claims = ['exp' => self::NOW + 86_400];
        if ($garminId !== null) {
            $claims['garmin_guid'] = $garminId;
        }
        $this->http->queue(FakeHttpClient::json(200, ['access_token' => Jwt::make($claims)]));
    }

    private function signIn(string $email, string $password): SetupState
    {
        self::assertSame(LoginOutcome::SignedIn, $this->service()->login(self::UID, $email, $password));

        return $this->users->setup(self::UID);
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
        $jobList->method('scheduleAfter')->willReturnCallback(function (string $job, int $runAfter, mixed $argument = null): void {
            $this->scheduled[] = [$job, $runAfter, $argument];
        });
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        return new AccountService(
            (new GarminClientFactory($provider, $this->secrets, $this->logger))->withWidgetSleep(static function (): void {
            }),
            $this->secrets,
            $this->users,
            new CalendarService($this->objects),
            $jobList,
            new FailureNotifications($this->notificationManager(), $time),
            $time,
            new PersonLock($this->locking),
            $this->logger,
            new PendingMfaSaver($this->secrets, $jobList, $this->logger, $this->users),
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
