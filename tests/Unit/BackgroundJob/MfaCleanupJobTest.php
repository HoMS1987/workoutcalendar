<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\BackgroundJob;

use OCA\WorkoutCalendar\BackgroundJob\MfaCleanupJob;
use OCA\WorkoutCalendar\Garmin\Auth\MfaChallenge;
use OCA\WorkoutCalendar\Service\PersonLock;
use OCA\WorkoutCalendar\Storage\MfaOrigin;
use OCA\WorkoutCalendar\Storage\PendingMfa;
use OCA\WorkoutCalendar\Storage\SecretStore;
use OCA\WorkoutCalendar\Tests\Support\InMemoryConfigMocks;
use OCA\WorkoutCalendar\Tests\Support\MemoryLockingProvider;
use OCA\WorkoutCalendar\Tests\Support\MemoryLogger;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;

final class MfaCleanupJobTest extends TestCase
{
    use InMemoryConfigMocks;

    private const UID = 'anna';
    private const NOW = 1_792_497_600;

    /** @var array<string, array<string, string|int>> */
    private array $store = [];
    private SecretStore $secrets;
    private MemoryLockingProvider $locking;
    private int $now = self::NOW;
    /** @var list<array{string, int, mixed}> */
    private array $scheduled = [];

    protected function setUp(): void
    {
        $this->secrets = new SecretStore($this->memoryUserConfig($this->store), $this->fakeCrypto(), new MemoryLogger());
        $this->locking = new MemoryLockingProvider();
    }

    public function testDeletesOnlyAnExpiredRequest(): void
    {
        $this->secrets->savePendingMfa(self::UID, new PendingMfa(new MfaChallenge('email', ['c1']), MfaOrigin::Relogin, self::NOW));
        $job = $this->job();

        // A newer request replaced the one the job was scheduled for: it stays.
        $this->now = self::NOW + PendingMfa::LIFETIME_SECONDS - 1;
        $this->execute($job, ['uid' => self::UID]);
        self::assertArrayHasKey('mfa_pending', $this->store[self::UID]);

        $this->now = self::NOW + PendingMfa::LIFETIME_SECONDS;
        $this->execute($job, ['uid' => self::UID]);
        self::assertArrayNotHasKey('mfa_pending', $this->store[self::UID] ?? []);
        self::assertSame([], $this->locking->held());
    }

    public function testBusyPersonIsRetriedLater(): void
    {
        $this->secrets->savePendingMfa(self::UID, new PendingMfa(new MfaChallenge('email', ['c1']), MfaOrigin::Relogin, self::NOW - 3600));
        $this->locking->hold('workoutcalendar/sync/' . md5(self::UID));

        $this->execute($this->job(), ['uid' => self::UID]);

        self::assertArrayHasKey('mfa_pending', $this->store[self::UID]);
        self::assertSame([[MfaCleanupJob::class, self::NOW + MfaCleanupJob::RETRY_SECONDS, ['uid' => self::UID]]], $this->scheduled);
    }

    public function testMalformedArgumentDoesNothing(): void
    {
        $job = $this->job();

        $this->execute($job, null);
        $this->execute($job, ['uid' => '']);
        $this->execute($job, ['uid' => 5]);

        self::assertSame([], $this->store);
    }

    private function job(): MfaCleanupJob
    {
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);
        $jobList = $this->createMock(IJobList::class);
        $jobList->method('scheduleAfter')->willReturnCallback(function (string $job, int $runAfter, mixed $argument = null): void {
            $this->scheduled[] = [$job, $runAfter, $argument];
        });

        return new MfaCleanupJob($time, $this->secrets, new PersonLock($this->locking), $jobList, new MemoryLogger());
    }

    /** `start()` needs a running server (logger), so the test goes straight to the protected `run()`. */
    private function execute(MfaCleanupJob $job, mixed $argument): void
    {
        (new \ReflectionMethod($job, 'run'))->invoke($job, $argument);
    }
}
