<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\BackgroundJob;

use OCA\WorkoutCalendar\BackgroundJob\SyncJob;
use OCA\WorkoutCalendar\Garmin\Http\HttpClient;
use OCA\WorkoutCalendar\Notification\FailureNotifications;
use OCA\WorkoutCalendar\Service\GarminClientFactory;
use OCA\WorkoutCalendar\Service\HttpClientProvider;
use OCA\WorkoutCalendar\Service\PersonLock;
use OCA\WorkoutCalendar\Service\SyncRunner;
use OCA\WorkoutCalendar\Storage\SecretStore;
use OCA\WorkoutCalendar\Storage\UserStore;
use OCA\WorkoutCalendar\Tests\Support\FakeHttpClient;
use OCA\WorkoutCalendar\Tests\Support\InMemoryConfigMocks;
use OCA\WorkoutCalendar\Tests\Support\MemoryCalendarObjects;
use OCA\WorkoutCalendar\Tests\Support\MemoryLogger;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Lock\ILockingProvider;
use OCP\Notification\IManager;
use PHPUnit\Framework\TestCase;

/**
 * The `SyncRunner` is final and runs for real here, with fakes behind it. People without credentials
 * end with `NotConnected` before any request and leave the queue in the process.
 */
final class SyncJobTest extends TestCase
{
    use InMemoryConfigMocks;

    private const NOW = 1_800_000_000;

    /** @var array<string, array<string, string|int>> */
    private array $store = [];
    private UserStore $users;
    private MemoryLogger $logger;
    /** @var list<string> */
    private array $locked = [];
    private ?string $failingUid = null;
    /** @var list<string> */
    private array $known = [];

    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
        $this->users = new UserStore($this->memoryUserConfig($this->store));
    }

    public function testIsTimeSensitive(): void
    {
        self::assertTrue($this->job()->isTimeSensitive());
    }

    /**
     * Nextcloud's system cron fires every 5 minutes and `TimedJob` only starts when strictly more
     * than the interval has passed. The tick exactly 300 s after the last run must still start the job.
     */
    public function testStartsOnEveryFiveMinuteCronTick(): void
    {
        self::assertTrue($this->startsAfter(300));
        self::assertTrue($this->startsAfter(270), 'a tick delayed by the previous cron run');
    }

    public function testDoesNotStartTwiceWithinOneTick(): void
    {
        self::assertFalse($this->startsAfter(60));
    }

    public function testRunsOnlyDueUsersUpToLimit(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->due('user' . $i, self::NOW - 1000 + $i);
        }
        $this->due('later', self::NOW + 600);

        $this->execute($this->job(), null);

        self::assertCount(10, $this->locked);
        self::assertSame(['user11', 'user12'], $this->users->dueUsers(self::NOW, 20));
        self::assertSame(self::NOW + 600, $this->users->state('later')->nextRunAt);
    }

    public function testFailingUserDoesNotStopOthers(): void
    {
        $this->due('anna', self::NOW - 300);
        $this->due('bad', self::NOW - 200);
        $this->due('carl', self::NOW - 100);
        $this->failingUid = 'bad';

        $this->execute($this->job(), null);

        self::assertSame(['anna', 'carl'], $this->locked);
        self::assertSame(['bad'], $this->users->dueUsers(self::NOW, 10));
        $errors = $this->logger->byLevel('error');
        self::assertCount(1, $errors);
        self::assertSame('bad', $errors[0]['context']['uid']);
        self::assertSame(\RuntimeException::class, $errors[0]['context']['exception']);
    }

    private function due(string $uid, int $nextRunAt): void
    {
        $this->known[] = $uid;
        $state = $this->users->state($uid);
        $state->nextRunAt = $nextRunAt;
        $this->users->saveState($uid, $state);
    }

    private function job(): SyncJob
    {
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);
        $time->method('getDateTime')->willReturn(new \DateTime('@' . self::NOW));

        return new SyncJob($time, $this->users, $this->runner(), $this->logger);
    }

    private function runner(): SyncRunner
    {
        $http = new FakeHttpClient();
        $provider = new class ($http) implements HttpClientProvider {
            public function __construct(private readonly HttpClient $http)
            {
            }

            public function create(): HttpClient
            {
                return $this->http;
            }
        };
        $secrets = new SecretStore($this->memoryUserConfig($this->store), $this->fakeCrypto(), $this->logger);
        $locking = $this->createMock(ILockingProvider::class);
        $locking->method('acquireLock')->willReturnCallback(function (string $path): void {
            foreach ($this->known as $uid) {
                if ($path === 'workoutcalendar/sync/' . md5($uid)) {
                    if ($uid === $this->failingUid) {
                        throw new \RuntimeException('Lock not available');
                    }
                    $this->locked[] = $uid;
                }
            }
        });

        return new SyncRunner(
            $this->users,
            $secrets,
            new MemoryCalendarObjects(),
            new GarminClientFactory($provider, $secrets, $this->logger),
            new FailureNotifications($this->createStub(IManager::class), $this->createStub(ITimeFactory::class)),
            new PersonLock($locking),
            $this->createStub(IFactory::class),
            $this->createStub(IUserManager::class),
            $this->logger,
        );
    }

    /**
     * `Job::start()` first calls `IJobList::setLastRun()` and only then needs a running server, so a
     * job list that throws there tells whether `TimedJob::start()` let the job through.
     */
    private function startsAfter(int $secondsSinceLastRun): bool
    {
        $job = $this->job();
        $job->setLastRun(self::NOW - $secondsSinceLastRun);
        $jobList = $this->createStub(IJobList::class);
        $jobList->method('setLastRun')->willThrowException(new \LogicException('started'));

        try {
            $job->start($jobList);
        } catch (\LogicException) {
            return true;
        }

        return false;
    }

    /** `start()` needs a running server (logger), so the test goes straight to the protected `run()`. */
    private function execute(SyncJob $job, mixed $argument): void
    {
        (new \ReflectionMethod($job, 'run'))->invoke($job, $argument);
    }
}
