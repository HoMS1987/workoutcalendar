<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Notification;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;

/** Legt die Sync-Fehler-Benachrichtigung einer Person an oder nimmt sie zurück. Pro Person gibt es höchstens eine. */
final class FailureNotifications
{
    public function __construct(
        private readonly INotificationManager $manager,
        private readonly ITimeFactory $time,
    ) {
    }

    /** Zieht eine ältere Benachrichtigung zurück und legt eine neue mit der Kategorie an. */
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
