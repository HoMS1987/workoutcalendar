<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Support;

use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/** Exklusive Sperren im Speicher, nicht wiedereintrittsfähig wie die Sperren von Nextcloud. */
final class MemoryLockingProvider implements ILockingProvider
{
    /** @var array<string, true> */
    private array $locks = [];

    /** Simuliert einen anderen Lauf, der die Sperre hält. */
    public function hold(string $path): void
    {
        $this->locks[$path] = true;
    }

    /** @return list<string> */
    public function held(): array
    {
        return array_keys($this->locks);
    }

    public function isLocked(string $path, int $type): bool
    {
        return isset($this->locks[$path]);
    }

    public function acquireLock(string $path, int $type, ?string $readablePath = null): void
    {
        if (isset($this->locks[$path])) {
            throw new LockedException($path);
        }
        $this->locks[$path] = true;
    }

    public function releaseLock(string $path, int $type): void
    {
        unset($this->locks[$path]);
    }

    public function changeLock(string $path, int $targetType): void
    {
    }

    public function releaseAll(): void
    {
        $this->locks = [];
    }
}
