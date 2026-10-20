<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Integration\BackgroundJob;

use OCA\WorkoutCalendar\BackgroundJob\SyncJob;
use OCP\BackgroundJob\IJobList;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/** Läuft innerhalb eines echten Nextcloud-Servers. */
final class SyncJobRegistrationTest extends TestCase
{
    public function testSyncJobIsRegisteredAfterEnable(): void
    {
        self::assertTrue(Server::get(IJobList::class)->has(SyncJob::class, null));
    }
}
