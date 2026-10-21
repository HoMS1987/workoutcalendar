<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Notification;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;

/** Creates a person's sync failure notification or withdraws it. There is at most one per person. */
final class FailureNotifications
{
    public function __construct(
        private readonly INotificationManager $manager,
        private readonly ITimeFactory $time,
    ) {
    }

    /** Withdraws an older notification and creates a new one with the category. */
    public function send(string $uid, string $category): void
    {
        $this->manager->markProcessed($this->template($uid));

        $this->manager->notify(
            $this->template($uid)
                ->setDateTime($this->time->getDateTime())
                ->setSubject(Notifier::SUBJECT_SYNC_FAILED, ['category' => $category]),
        );
    }

    public function dismiss(string $uid): void
    {
        $this->manager->markProcessed($this->template($uid));
    }

    private function template(string $uid): INotification
    {
        return $this->manager->createNotification()
            ->setApp(Notifier::APP_ID)
            ->setUser($uid)
            ->setObject('sync', $uid);
    }
}
