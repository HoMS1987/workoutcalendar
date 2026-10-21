<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Service;

use OCA\WorkoutCalendar\Garmin\Auth\Credentials;
use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Garmin\Http\HttpClient;
use OCA\WorkoutCalendar\Notification\FailureNotifications;
use OCA\WorkoutCalendar\Service\GarminClientFactory;
use OCA\WorkoutCalendar\Service\HttpClientProvider;
use OCA\WorkoutCalendar\Service\PersonLock;
use OCA\WorkoutCalendar\Service\RunOutcome;
use OCA\WorkoutCalendar\Service\SyncRunner;
use OCA\WorkoutCalendar\Storage\SecretStore;
use OCA\WorkoutCalendar\Storage\SetupState;
use OCA\WorkoutCalendar\Storage\SetupStep;
use OCA\WorkoutCalendar\Storage\UserStore;
use OCA\WorkoutCalendar\Sync\SyncState;
use OCA\WorkoutCalendar\Tests\Support\FakeHttpClient;
use OCA\WorkoutCalendar\Tests\Support\InMemoryConfigMocks;
use OCA\WorkoutCalendar\Tests\Support\Jwt;
use OCA\WorkoutCalendar\Tests\Support\MemoryCalendarObjects;
use OCA\WorkoutCalendar\Tests\Support\MemoryLogger;
use OCA\WorkoutCalendar\Tests\Support\RecordingNotification;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

final class SyncRunnerTest extends TestCase
{
    use InMemoryConfigMocks;

    private const UID = 'anna';
    private const ACCOUNT = '0123456789abcdef';
    /** md5('anna'), die Spalte oc_file_locks.key fasst 64 Zeichen. */
    private const LOCK = 'workoutcalendar/sync/a70f9e38ff015afaa9ab0aacabee2e13';

    /** @var array<string, array<string, string|int>> */
    private array $store = [];
    private UserStore $users;
    private SecretStore $secrets;
    private MemoryCalendarObjects $objects;
    private FakeHttpClient $http;
    private MemoryLogger $logger;
    private \DateTimeImmutable $now;
    private int $calendarId;
    /** @var list<string> */
    private array $lockCalls = [];
    /** @var list<string> */
    private array $notificationCalls = [];
    private ?\Throwable $lockFailure = null;

    protected function setUp(): void
    {
        $config = $this->memoryUserConfig($this->store);
        $this->logger = new MemoryLogger();
        $this->users = new UserStore($config);
        $this->secrets = new SecretStore($config, $this->fakeCrypto(), $this->logger);
        $this->objects = new MemoryCalendarObjects();
        $this->http = new FakeHttpClient();
        $this->now = new \DateTimeImmutable('2026-10-20 12:00:00', new \DateTimeZone('UTC'));
        $this->calendarId = $this->objects->addCalendar(self::UID, 'workoutcalendar', 'Training', null, ['VEVENT']);
    }

    public function testRunsSyncAndStoresState(): void
    {
        $this->connect();
        $this->http->queue(
            FakeHttpClient::json(200, [self::activity(101, '2026-10-19 07:00:00')]),
            FakeHttpClient::json(200, []),
        );

        $outcome = $this->runner()->run(self::UID, $this->now);

        self::assertSame(RunOutcome::Done, $outcome);
        self::assertArrayHasKey('garmin-101.ics', $this->objects->objects[$this->calendarId]);
        self::assertStringContainsString('X-GARMIN-ACCOUNT:' . self::ACCOUNT, $this->objects->objects[$this->calendarId]['garmin-101.ics']);
        $state = $this->users->state(self::UID);
        self::assertSame($this->now->getTimestamp(), $state->lastSuccessAt);
        self::assertNotNull($state->nextRunAt);
        self::assertSame(['acquire:' . self::LOCK, 'release:' . self::LOCK], $this->lockCalls);
        self::assertSame([], $this->notificationCalls);
    }

    public function testRunWaitsWhileRemovalIsRunning(): void
    {
        $this->connect();
        $setup = $this->users->setup(self::UID);
        $this->users->saveSetup(self::UID, new SetupState($setup->step, true, self::ACCOUNT, $setup->maskedEmail, $this->calendarId, true, 'remove'));
        $this->queued($this->users->state(self::UID));
        $writes = $this->objects->writes;

        self::assertSame(RunOutcome::Skipped, $this->runner()->run(self::UID, $this->now));

        self::assertSame([], $this->http->requests);
        self::assertSame($writes, $this->objects->writes);
        $nextRunAt = $this->users->state(self::UID)->nextRunAt;
        self::assertNotNull($nextRunAt);
        self::assertGreaterThanOrEqual($this->now->getTimestamp() + 24 * 60, $nextRunAt);
        self::assertLessThanOrEqual($this->now->getTimestamp() + 36 * 60, $nextRunAt);
    }

    public function testRunContinuesDuringMove(): void
    {
        $this->connect();
        $setup = $this->users->setup(self::UID);
        $this->users->saveSetup(self::UID, new SetupState($setup->step, true, self::ACCOUNT, $setup->maskedEmail, $this->calendarId, true, 'move'));
        $this->http->queue(FakeHttpClient::json(200, []), FakeHttpClient::json(200, []));

        self::assertSame(RunOutcome::Done, $this->runner()->run(self::UID, $this->now));

        self::assertNotSame([], $this->http->requests);
    }

    public function testLockedRunIsSkipped(): void
    {
        $this->connect();
        $this->lockFailure = new LockedException(self::LOCK);

        $outcome = $this->runner()->run(self::UID, $this->now);

        self::assertSame(RunOutcome::Skipped, $outcome);
        self::assertSame([], $this->http->requests);
        self::assertSame(['acquire:' . self::LOCK], $this->lockCalls);
    }

    public function testRunNowArmsAndRunsUnderOneLock(): void
    {
        $this->connect();
        $this->http->queue(
            FakeHttpClient::json(200, [self::activity(101, '2026-10-19 07:00:00')]),
            FakeHttpClient::json(200, []),
        );

        $outcome = $this->runner()->runNow(self::UID, $this->now);

        self::assertSame(RunOutcome::Done, $outcome);
        self::assertArrayHasKey('garmin-101.ics', $this->objects->objects[$this->calendarId]);
        self::assertSame(['acquire:' . self::LOCK, 'release:' . self::LOCK], $this->lockCalls);
        // Standard: 30 Minuten ± 20 %.
        $nextRunAt = $this->users->state(self::UID)->nextRunAt;
        self::assertNotNull($nextRunAt);
        self::assertGreaterThanOrEqual($this->now->getTimestamp() + 24 * 60, $nextRunAt);
        self::assertLessThanOrEqual($this->now->getTimestamp() + 36 * 60, $nextRunAt);
    }

    public function testRunNowWhileBusyChangesNothing(): void
    {
        $this->connect();
        $before = $this->store;
        $this->lockFailure = new LockedException(self::LOCK);

        self::assertSame(RunOutcome::Skipped, $this->runner()->runNow(self::UID, $this->now));

        self::assertSame($before, $this->store);
        self::assertSame([], $this->http->requests);
        self::assertSame(['acquire:' . self::LOCK], $this->lockCalls);
    }

    public function testPausedUserIsNotContacted(): void
    {
        $this->connect();
        $state = $this->users->state(self::UID);
        $state->pausedFor = 'INVALID_CREDENTIALS';
        $this->users->saveState(self::UID, $state);

        $outcome = $this->runner()->run(self::UID, $this->now);

        self::assertSame(RunOutcome::Paused, $outcome);
        self::assertSame([], $this->http->requests);
        self::assertSame([], $this->notificationCalls);
    }

    public function testMissingCredentialsMeanNotConnected(): void
    {
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, self::ACCOUNT, 'a***@b.de', $this->calendarId));

        $outcome = $this->runner()->run(self::UID, $this->now);

        self::assertSame(RunOutcome::NotConnected, $outcome);
        self::assertSame([], $this->http->requests);
        self::assertSame(['acquire:' . self::LOCK, 'release:' . self::LOCK], $this->lockCalls);
    }

    public function testDeletedCalendarIsReported(): void
    {
        $this->connect();
        $this->objects->deleteCalendar($this->calendarId);

        $outcome = $this->runner()->run(self::UID, $this->now);

        self::assertSame(RunOutcome::CalendarMissing, $outcome);
        $setup = $this->users->setup(self::UID);
        self::assertNull($setup->calendarId);
        self::assertSame(SetupStep::Calendar, $setup->step);
        self::assertSame(self::ACCOUNT, $setup->accountKey);
        self::assertSame([], $this->http->requests);
    }

    public function testDeletedCalendarSetsMarkerAndKeepsOtherFields(): void
    {
        $this->connect();
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, self::ACCOUNT, 'a***@b.de', $this->calendarId, true, 'move', 'remove'));
        $this->objects->deleteCalendar($this->calendarId);

        self::assertSame(RunOutcome::CalendarMissing, $this->runner()->run(self::UID, $this->now));

        $setup = $this->users->setup(self::UID);
        self::assertTrue($setup->calendarMissing);
        self::assertSame('move', $setup->maintenance);
        self::assertSame('remove', $setup->maintenanceFailed);
    }

    public function testUnfinishedSetupIsNotReadyAndLeavesQueue(): void
    {
        $this->connect();
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::History, true, self::ACCOUNT, 'a***@b.de', $this->calendarId, true));
        $this->queued($this->users->state(self::UID));

        self::assertSame(RunOutcome::NotReady, $this->runner()->run(self::UID, $this->now));

        self::assertSame([], $this->http->requests);
        self::assertFalse($this->users->state(self::UID)->initialized);
        self::assertNotContains(self::UID, $this->users->dueUsers($this->now->getTimestamp(), 10));
    }

    public function testFailureSendsNotificationOnce(): void
    {
        $this->connect(withTokens: false);
        $rejected = FakeHttpClient::json(200, ['responseStatus' => ['type' => 'INVALID_USERNAME_PASSWORD']]);
        $this->http->queue($rejected, $rejected);

        self::assertSame(RunOutcome::Done, $this->runner()->run(self::UID, $this->now));
        $state = $this->users->state(self::UID);
        self::assertSame('INVALID_CREDENTIALS', $state->pausedFor);
        self::assertSame('INVALID_CREDENTIALS', $state->notifiedCategory);

        // Neue Zugangsdaten heben die Pause auf, Garmin lehnt sie wieder ab.
        $state->pausedFor = null;
        $this->users->saveState(self::UID, $state);
        self::assertSame(RunOutcome::Done, $this->runner()->run(self::UID, $this->now->modify('+30 minutes')));

        self::assertSame(RunOutcome::Paused, $this->runner()->run(self::UID, $this->now->modify('+60 minutes')));
        self::assertSame(['notify:' . self::UID . ':INVALID_CREDENTIALS'], array_values(array_filter(
            $this->notificationCalls,
            static fn (string $call): bool => str_starts_with($call, 'notify:'),
        )));
    }

    public function testRecoveryDismissesNotification(): void
    {
        $this->connect();
        $state = $this->users->state(self::UID);
        $state->initialized = true;
        $state->backfillDone = true;
        $state->lastSuccessAt = $this->now->getTimestamp() - 3 * 86_400;
        $state->failureCategory = 'SERVER_ERROR';
        $state->failureStep = 'api.activities';
        $state->failureSince = $this->now->getTimestamp() - 2 * 86_400;
        $state->failureCount = 5;
        $state->notifiedCategory = 'SERVER_ERROR';
        $this->users->saveState(self::UID, $state);
        $this->http->queue(FakeHttpClient::json(200, []));

        $outcome = $this->runner()->run(self::UID, $this->now);

        self::assertSame(RunOutcome::Done, $outcome);
        self::assertSame(['markProcessed:' . self::UID], $this->notificationCalls);
        $state = $this->users->state(self::UID);
        self::assertNull($state->failureCategory);
        self::assertNull($state->notifiedCategory);
    }

    public function testLockIsReleasedAfterException(): void
    {
        $this->connect();
        $this->http->queue(new \RuntimeException('kaputt'));

        try {
            $this->runner()->run(self::UID, $this->now);
            self::fail('Exception erwartet');
        } catch (\RuntimeException $e) {
            self::assertSame('kaputt', $e->getMessage());
        }

        self::assertSame(['acquire:' . self::LOCK, 'release:' . self::LOCK], $this->lockCalls);
    }

    public function testPausedPersonLeavesQueue(): void
    {
        $this->connect();
        $state = $this->users->state(self::UID);
        $state->pausedFor = 'MFA_REQUIRED';
        $this->queued($state);

        self::assertSame(RunOutcome::Paused, $this->runner()->run(self::UID, $this->now));

        self::assertNotContains(self::UID, $this->users->dueUsers($this->now->getTimestamp(), 10));
        self::assertNull($this->users->state(self::UID)->nextRunAt);
    }

    public function testNotConnectedPersonLeavesQueue(): void
    {
        $this->queued($this->users->state(self::UID));

        self::assertSame(RunOutcome::NotConnected, $this->runner()->run(self::UID, $this->now));

        self::assertNotContains(self::UID, $this->users->dueUsers($this->now->getTimestamp(), 10));
    }

    public function testMissingCalendarLeavesQueue(): void
    {
        $this->connect();
        $this->objects->deleteCalendar($this->calendarId);
        $this->queued($this->users->state(self::UID));

        self::assertSame(RunOutcome::CalendarMissing, $this->runner()->run(self::UID, $this->now));

        self::assertNotContains(self::UID, $this->users->dueUsers($this->now->getTimestamp(), 10));
    }

    public function testSkippedRunKeepsQueuePosition(): void
    {
        $this->connect();
        $this->queued($this->users->state(self::UID));
        $this->lockFailure = new LockedException(self::LOCK);

        self::assertSame(RunOutcome::Skipped, $this->runner()->run(self::UID, $this->now));

        self::assertSame([self::UID], $this->users->dueUsers($this->now->getTimestamp(), 10));
    }

    public function testUnexpectedExceptionPostponesNextRun(): void
    {
        $this->connect();
        $this->queued($this->users->state(self::UID));
        $this->http->queue(new \RuntimeException('kaputt'));

        try {
            $this->runner()->run(self::UID, $this->now);
            self::fail('Exception erwartet');
        } catch (\RuntimeException) {
        }

        // Standard: 30 Minuten ± 20 %.
        $nextRunAt = $this->users->state(self::UID)->nextRunAt;
        self::assertNotNull($nextRunAt);
        self::assertGreaterThanOrEqual($this->now->getTimestamp() + 24 * 60, $nextRunAt);
        self::assertLessThanOrEqual($this->now->getTimestamp() + 36 * 60, $nextRunAt);
        self::assertNotContains(self::UID, $this->users->dueUsers($this->now->getTimestamp(), 10));
        self::assertSame(['acquire:' . self::LOCK, 'release:' . self::LOCK], $this->lockCalls);
    }

    public function testLockKeyFitsDatabaseColumn(): void
    {
        $long = str_repeat('f', 64);
        $runner = $this->runner();

        $runner->run($long, $this->now);
        $runner->run($long, $this->now);
        $runner->run(self::UID, $this->now);

        $keys = array_values(array_unique(array_map(
            static fn (string $call): string => substr($call, strlen('acquire:')),
            array_filter($this->lockCalls, static fn (string $call): bool => str_starts_with($call, 'acquire:')),
        )));
        self::assertCount(2, $keys);
        foreach ($keys as $key) {
            self::assertLessThanOrEqual(64, strlen($key));
            self::assertStringStartsWith('workoutcalendar/sync/', $key);
        }
    }

    public function testUsesLanguageOfPerson(): void
    {
        $this->connect();
        $this->http->queue(
            FakeHttpClient::json(200, [self::activity(101, '2026-10-19 07:00:00')]),
            FakeHttpClient::json(200, []),
        );

        $this->runner()->run(self::UID, $this->now);

        $ics = $this->objects->objects[$this->calendarId]['garmin-101.ics'];
        self::assertStringContainsString('Laufen', $ics);
        // ICS maskiert das Komma.
        self::assertStringContainsString('Distanz: 5\,00 km', $ics);
    }

    /** Person steht mit fälligem Lauf in der Warteschlange. */
    private function queued(SyncState $state): void
    {
        $state->nextRunAt = $this->now->getTimestamp() - 60;
        $this->users->saveState(self::UID, $state);
        self::assertSame([self::UID], $this->users->dueUsers($this->now->getTimestamp(), 10));
    }

    private function connect(bool $withTokens = true): void
    {
        $this->secrets->saveCredentials(self::UID, new Credentials('a@b.de', 'geheim'));
        if ($withTokens) {
            $this->secrets->saveTokens(self::UID, new GarminTokens(Jwt::make(['exp' => time() + 3600]), 'r', 'C'));
        }
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, self::ACCOUNT, 'a***@b.de', $this->calendarId, true));
    }

    private function runner(): SyncRunner
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

        return new SyncRunner(
            $this->users,
            $this->secrets,
            $this->objects,
            new GarminClientFactory($provider, $this->secrets, $this->logger),
            new FailureNotifications($this->notificationManager(), $this->createStub(ITimeFactory::class)),
            new PersonLock($this->locking()),
            $this->l10nFactory(),
            $this->userManager(),
            $this->logger,
        );
    }

    private function locking(): ILockingProvider
    {
        $locking = $this->createMock(ILockingProvider::class);
        $locking->method('acquireLock')->willReturnCallback(function (string $path, int $type): void {
            self::assertSame(ILockingProvider::LOCK_EXCLUSIVE, $type);
            $this->lockCalls[] = 'acquire:' . $path;
            if ($this->lockFailure !== null) {
                throw $this->lockFailure;
            }
        });
        $locking->method('releaseLock')->willReturnCallback(function (string $path, int $type): void {
            self::assertSame(ILockingProvider::LOCK_EXCLUSIVE, $type);
            $this->lockCalls[] = 'release:' . $path;
        });

        return $locking;
    }

    private function notificationManager(): IManager
    {
        $manager = $this->createMock(IManager::class);
        $manager->method('createNotification')->willReturnCallback(static fn (): INotification => new RecordingNotification());
        $manager->method('markProcessed')->willReturnCallback(function (INotification $n): void {
            $this->notificationCalls[] = 'markProcessed:' . $n->getUser();
        });
        $manager->method('notify')->willReturnCallback(function (INotification $n): void {
            $this->notificationCalls[] = 'notify:' . $n->getUser() . ':' . $n->getSubjectParameters()['category'];
        });

        return $manager;
    }

    private function userManager(): IUserManager
    {
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn(self::UID);
        $manager = $this->createMock(IUserManager::class);
        $manager->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $uid === self::UID ? $user : null);

        return $manager;
    }

    private function l10nFactory(): IFactory
    {
        $l = $this->createStub(IL10N::class);
        $l->method('t')->willReturnCallback(static function (string $text, $parameters = []): string {
            $table = ['Running' => 'Laufen', 'Distance: %s' => 'Distanz: %s'];

            return vsprintf($table[$text] ?? $text, (array)$parameters);
        });
        $l->method('getLanguageCode')->willReturn('de');
        $factory = $this->createMock(IFactory::class);
        $factory->method('getUserLanguage')->willReturnCallback(
            static fn (?IUser $user = null): string => $user?->getUID() === self::UID ? 'de' : 'en',
        );
        $factory->method('get')->willReturnCallback(static function ($app, $lang = null) use ($l): IL10N {
            self::assertSame('workoutcalendar', $app);
            self::assertSame('de', $lang);

            return $l;
        });

        return $factory;
    }

    /** @return array<string, mixed> */
    private static function activity(int $id, string $start): array
    {
        return [
            'activityId' => $id,
            'activityName' => 'Abendlauf',
            'startTimeGMT' => $start,
            'startTimeLocal' => $start,
            'duration' => 1800.0,
            'distance' => 5000.0,
            'activityType' => ['typeKey' => 'running'],
        ];
    }
}
