<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Notification;

use OCA\WorkoutCalendar\Notification\FailureNotifications;
use OCA\WorkoutCalendar\Tests\Support\RecordingNotification;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

final class FailureNotificationsTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];
    private ?INotification $sent = null;

    public function testSendWithdrawsOldNotificationBeforeNotifying(): void
    {
        (new FailureNotifications($this->manager(), $this->time()))->send('anna', 'MFA_REQUIRED');

        self::assertSame(['markProcessed:anna', 'notify:anna'], $this->calls);
    }

    public function testSendFillsNotification(): void
    {
        $when = new \DateTime('2026-10-20 10:00:00');

        (new FailureNotifications($this->manager(), $this->time($when)))->send('anna', 'MFA_REQUIRED');

        self::assertNotNull($this->sent);
        self::assertSame('workoutcalendar', $this->sent->getApp());
        self::assertSame('anna', $this->sent->getUser());
        self::assertSame('sync', $this->sent->getObjectType());
        self::assertSame('anna', $this->sent->getObjectId());
        self::assertSame('sync_failed', $this->sent->getSubject());
        self::assertSame(['category' => 'MFA_REQUIRED'], $this->sent->getSubjectParameters());
        self::assertSame($when, $this->sent->getDateTime());
    }

    public function testDismissOnlyMarksProcessed(): void
    {
        (new FailureNotifications($this->manager(), $this->time()))->dismiss('anna');

        self::assertSame(['markProcessed:anna'], $this->calls);
    }

    private function time(?\DateTime $when = null): ITimeFactory
    {
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getDateTime')->willReturn($when ?? new \DateTime('2026-10-20 10:00:00'));

        return $time;
    }

    private function manager(): IManager
    {
        $manager = $this->createMock(IManager::class);
        $manager->method('createNotification')->willReturnCallback(
            static fn (): INotification => new RecordingNotification(),
        );
        $manager->method('markProcessed')->willReturnCallback(function (INotification $n): void {
            self::assertSame('workoutcalendar', $n->getApp());
            self::assertSame('sync', $n->getObjectType());
            self::assertSame($n->getUser(), $n->getObjectId());
            $this->calls[] = 'markProcessed:' . $n->getUser();
        });
        $manager->method('notify')->willReturnCallback(function (INotification $n): void {
            $this->calls[] = 'notify:' . $n->getUser();
            $this->sent = $n;
        });

        return $manager;
    }
}
