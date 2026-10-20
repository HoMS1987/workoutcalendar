<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Sync;

use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Sync\FailureLog;
use OCA\WorkoutCalendar\Sync\SyncState;
use OCA\WorkoutCalendar\Tests\Support\MemoryLogger;
use PHPUnit\Framework\TestCase;

final class FailureLogTest extends TestCase
{
    private const T0 = 1_800_000_000;
    private const HOUR = 3600;

    private MemoryLogger $logger;
    private FailureLog $log;
    private SyncState $state;

    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
        $this->log = new FailureLog($this->logger);
        $this->state = new SyncState();
    }

    private static function blocked(): GarminException
    {
        return new GarminException(FailureCategory::Blocked, 'api.activities', 'HTTP 429', 429, 'Too Many Requests');
    }

    /** @return list<string> */
    private function levels(): array
    {
        return array_map(static fn (array $r): string => $r['level'], $this->logger->records);
    }

    public function testFirstFailureIsLoggedInFull(): void
    {
        $this->log->recordFailure($this->state, self::blocked()->withAttempts(['mobile' => 'BLOCKED @ mobile.login']), self::T0);

        self::assertSame(['warning'], $this->levels());
        $context = $this->logger->records[0]['context'];
        self::assertSame('BLOCKED', $context['category']);
        self::assertSame('api.activities', $context['step']);
        self::assertSame(429, $context['status']);
        self::assertSame('Too Many Requests', $context['excerpt']);
        self::assertSame(['mobile' => 'BLOCKED @ mobile.login'], $context['attempts']);
        self::assertSame(1, $this->state->failureCount);
        self::assertSame(self::T0, $this->state->failureSince);
        self::assertSame('HTTP 429', $this->state->failureMessage);
    }

    public function testRepeatedFailureWithinADayIsOnlyDebug(): void
    {
        $this->log->recordFailure($this->state, self::blocked(), self::T0);
        $this->log->recordFailure($this->state, self::blocked(), self::T0 + 23 * self::HOUR);

        self::assertSame(['warning', 'debug'], $this->levels());
        self::assertSame(2, $this->state->failureCount);
    }

    public function testOngoingFailureIsRemindedOncePerDay(): void
    {
        $this->log->recordFailure($this->state, self::blocked(), self::T0);
        $this->log->recordFailure($this->state, self::blocked(), self::T0 + 12 * self::HOUR);
        $this->log->recordFailure($this->state, self::blocked(), self::T0 + 24 * self::HOUR);
        $this->log->recordFailure($this->state, self::blocked(), self::T0 + 30 * self::HOUR);

        self::assertSame(['warning', 'debug', 'warning', 'debug'], $this->levels());
        $reminder = $this->logger->records[2];
        self::assertStringContainsString('hält an', $reminder['message']);
        self::assertSame(3, $reminder['context']['failedRuns']);
        self::assertSame(self::T0, $reminder['context']['since']);
    }

    public function testChangedFailureIsLoggedInFullAgain(): void
    {
        $this->log->recordFailure($this->state, self::blocked(), self::T0);
        $this->log->recordFailure(
            $this->state,
            new GarminException(FailureCategory::ProtocolChanged, 'widget.signin-page', 'Kein CSRF-Token'),
            self::T0 + self::HOUR,
        );

        self::assertSame(['warning', 'error'], $this->levels());
        self::assertSame(1, $this->state->failureCount);
        self::assertSame('PROTOCOL_CHANGED', $this->state->failureCategory);
        self::assertSame(self::T0 + self::HOUR, $this->state->failureSince);
    }

    public function testFailuresNeedingActionAreErrors(): void
    {
        $this->log->recordFailure($this->state, new GarminException(FailureCategory::InvalidCredentials, 'mobile.login', 'abgelehnt'), self::T0);

        self::assertSame(['error'], $this->levels());
    }

    public function testRecoveryIsLoggedWithNumberOfFailedRuns(): void
    {
        $this->log->recordFailure($this->state, self::blocked(), self::T0);
        $this->log->recordFailure($this->state, self::blocked(), self::T0 + self::HOUR);
        $this->log->recordSuccess($this->state, self::T0 + 2 * self::HOUR);

        $info = $this->logger->byLevel('info');
        self::assertCount(1, $info);
        self::assertSame(2, $info[0]['context']['failedRuns']);
        self::assertSame('BLOCKED', $info[0]['context']['category']);
        self::assertSame(0, $this->state->failureCount);
        self::assertNull($this->state->failureCategory);
        self::assertNull($this->state->failureMessage);
    }

    public function testSuccessWithoutPriorFailureLogsNothing(): void
    {
        $this->log->recordSuccess($this->state, self::T0);

        self::assertSame([], $this->logger->records);
    }

    public function testSuccessKeepsNotifiedCategory(): void
    {
        $this->state->notifiedCategory = 'INVALID_CREDENTIALS';

        $this->log->recordSuccess($this->state, self::T0);

        self::assertSame('INVALID_CREDENTIALS', $this->state->notifiedCategory);
    }
}
