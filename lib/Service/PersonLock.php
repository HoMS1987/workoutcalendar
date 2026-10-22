<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Exclusive lock per person for sync runs and all changes to sign-in, calendar and
 * settings. A sync run keeps state in memory and saves it at the end, so a change
 * in between would otherwise be lost (ADR 0006). Not reentrant: whoever holds the lock must not
 * call `run()` for the same person again. A lock left behind by a killed process expires with the
 * locking provider's TTL (`filelocking.ttl`, one hour by default).
 */
final class PersonLock
{
    private const PREFIX = 'workoutcalendar/sync/';

    public function __construct(
        private readonly ILockingProvider $locking,
    ) {
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     * @throws PersonBusyException if another operation holds the lock; `$fn` does not run then
     */
    public function run(string $uid, callable $fn): mixed
    {
        // WHY md5: oc_file_locks.key holds 64 characters, user IDs can be longer (e.g. OIDC).
        $key = self::PREFIX . md5($uid);
        try {
            $this->locking->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (LockedException) {
            throw new PersonBusyException();
        }

        try {
            return $fn();
        } finally {
            $this->locking->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }
}
