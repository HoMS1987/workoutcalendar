<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Service;

use OCA\WorkoutCalendar\Service\PersonBusyException;
use OCA\WorkoutCalendar\Service\PersonLock;
use OCA\WorkoutCalendar\Tests\Support\MemoryLockingProvider;
use PHPUnit\Framework\TestCase;

final class PersonLockTest extends TestCase
{
    /** md5('anna'), the column oc_file_locks.key holds 64 characters. */
    private const LOCK = 'workoutcalendar/sync/a70f9e38ff015afaa9ab0aacabee2e13';

    public function testRunsCallbackUnderLockAndReturnsResult(): void
    {
        $locking = new MemoryLockingProvider();
        $lock = new PersonLock($locking);

        $result = $lock->run('anna', function () use ($locking): string {
            self::assertSame([self::LOCK], $locking->held());

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame([], $locking->held());
    }

    public function testHeldLockThrowsBusyWithoutCallingBack(): void
    {
        $locking = new MemoryLockingProvider();
        $locking->hold(self::LOCK);
        $called = false;

        try {
            (new PersonLock($locking))->run('anna', function () use (&$called): void {
                $called = true;
            });
            self::fail('PersonBusyException expected');
        } catch (PersonBusyException) {
        }

        self::assertFalse($called);
        self::assertSame([self::LOCK], $locking->held());
    }

    public function testLockIsReleasedAfterException(): void
    {
        $locking = new MemoryLockingProvider();

        try {
            (new PersonLock($locking))->run('anna', static function (): void {
                throw new \RuntimeException('broken');
            });
            self::fail('RuntimeException expected');
        } catch (\RuntimeException $e) {
            self::assertSame('broken', $e->getMessage());
        }

        self::assertSame([], $locking->held());
    }

    public function testBusyIsNoInvalidRequest(): void
    {
        self::assertNotInstanceOf(\InvalidArgumentException::class, new PersonBusyException());
    }
}
