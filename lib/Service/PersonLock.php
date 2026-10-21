<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Exklusive Sperre je Person für Sync-Läufe und alle Änderungen an Anmeldung, Kalender und
 * Einstellungen. Ein Sync-Lauf hält Zustand im Speicher und speichert ihn am Ende, eine Änderung
 * dazwischen ginge sonst verloren. Nicht wiedereintrittsfähig: wer die Sperre hält, darf
 * `run()` für dieselbe Person nicht erneut aufrufen.
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
     * @throws PersonBusyException wenn ein anderer Vorgang die Sperre hält, `$fn` läuft dann nicht
     */
    public function run(string $uid, callable $fn): mixed
    {
        // WHY md5: oc_file_locks.key fasst 64 Zeichen, Benutzer-IDs können länger sein (z. B. OIDC).
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
