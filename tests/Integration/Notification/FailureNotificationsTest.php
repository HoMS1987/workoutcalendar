<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Integration\Notification;

use OCA\WorkoutCalendar\Notification\FailureNotifications;
use OCP\Notification\IManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Runs inside a real Nextcloud server. The "notifications" app is not part of the
 * server checkout, so stored notifications cannot be read back.
 */
final class FailureNotificationsTest extends TestCase
{
    public function testRegisteredNotifierPreparesGermanText(): void
    {
        $manager = Server::get(IManager::class);
        $notification = $manager->createNotification()
            ->setApp('workoutcalendar')
            ->setUser('anna')
            ->setDateTime(new \DateTime())
            ->setObject('sync', 'anna')
            ->setSubject('sync_failed', ['category' => 'INVALID_CREDENTIALS']);

        $prepared = $manager->prepare($notification, 'de');

        self::assertSame('Workout Calendar braucht deine Aufmerksamkeit', $prepared->getParsedSubject());
        self::assertStringContainsString('Passwort', $prepared->getParsedMessage());
        self::assertStringContainsString('workoutcalendar', $prepared->getLink());
    }

    public function testSendAndDismissWithRealManager(): void
    {
        $notifications = Server::get(FailureNotifications::class);

        $notifications->send('anna', 'INVALID_CREDENTIALS');
        $notifications->send('anna', 'INVALID_CREDENTIALS');
        $notifications->dismiss('anna');

        $this->addToAssertionCount(1);
    }
}
