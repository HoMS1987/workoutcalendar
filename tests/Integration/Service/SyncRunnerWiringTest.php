<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Integration\Service;

use OCA\WorkoutCalendar\CalDav\CalendarObjects;
use OCA\WorkoutCalendar\CalDav\DavCalendarObjects;
use OCA\WorkoutCalendar\Service\CurlHttpClientProvider;
use OCA\WorkoutCalendar\Service\HttpClientProvider;
use OCA\WorkoutCalendar\Service\RunOutcome;
use OCA\WorkoutCalendar\Service\SyncRunner;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Runs inside a real Nextcloud server. Without credentials the run ends before any
 * request to Garmin.
 */
final class SyncRunnerWiringTest extends TestCase
{
    private ?IUser $user = null;

    protected function tearDown(): void
    {
        $this->user?->delete();
    }

    public function testContainerResolvesRunnerAndAdapters(): void
    {
        self::assertInstanceOf(SyncRunner::class, Server::get(SyncRunner::class));
        self::assertInstanceOf(DavCalendarObjects::class, Server::get(CalendarObjects::class));
        self::assertInstanceOf(CurlHttpClientProvider::class, Server::get(HttpClientProvider::class));
    }

    public function testPersonWithoutCredentialsIsNotConnected(): void
    {
        $uid = 'wc-sync-' . bin2hex(random_bytes(4));
        $user = Server::get(IUserManager::class)->createUser($uid, 'Wc-' . bin2hex(random_bytes(16)) . '!');
        self::assertInstanceOf(IUser::class, $user);
        $this->user = $user;

        $outcome = Server::get(SyncRunner::class)->run($uid, new \DateTimeImmutable());

        self::assertSame(RunOutcome::NotConnected, $outcome);
    }
}
