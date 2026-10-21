<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Integration;

use OCA\WorkoutCalendar\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Runs inside a real Nextcloud server (see tests/Integration/bootstrap.php).
 */
final class AppBootTest extends TestCase
{
    public function testAppIsEnabledAndBootstrapResolves(): void
    {
        self::assertTrue(Server::get(IAppManager::class)->isEnabledForUser('workoutcalendar'));
        self::assertInstanceOf(Application::class, Server::get(Application::class));
    }
}
