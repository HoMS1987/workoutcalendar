<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Notification;

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/** Setzt Titel, Text und Link der Sync-Fehler-Benachrichtigung in der Sprache der Person. */
final class Notifier implements INotifier
{
    public const APP_ID = 'workoutcalendar';
    public const SUBJECT_SYNC_FAILED = 'sync_failed';

    public function __construct(
        private readonly IFactory $factory,
        private readonly IURLGenerator $urlGenerator,
    ) {
    }

    public function getID(): string
    {
        return self::APP_ID;
    }

    public function getName(): string
    {
        return $this->factory->get(self::APP_ID)->t('Workout Calendar');
    }

    public function prepare(INotification $notification, string $languageCode): INotification
    {
        if ($notification->getApp() !== self::APP_ID || $notification->getSubject() !== self::SUBJECT_SYNC_FAILED) {
            throw new UnknownNotificationException();
        }

        $l = $this->factory->get(self::APP_ID, $languageCode);
        $category = (string) ($notification->getSubjectParameters()['category'] ?? '');

        $notification
            ->setParsedSubject($l->t('Workout Calendar needs your attention'))
            ->setParsedMessage(match ($category) {
                'INVALID_CREDENTIALS' => $l->t('Garmin rejected your email address or password. Workout Calendar is paused until you sign in again.'),
                'MFA_REQUIRED' => $l->t('Your Garmin account asks for a verification code. This is not supported yet, Workout Calendar is paused.'),
                'PROTOCOL_CHANGED' => $l->t('Workout Calendar could not sync for a day because Garmin changed its sign-in. An app update may be needed.'),
                default => $l->t('Workout Calendar could not reach Garmin for a day. It keeps trying.'),
            })
            ->setIcon($this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->imagePath(self::APP_ID, 'app-dark.svg'),
            ))
            ->setLink($this->urlGenerator->linkToRouteAbsolute(
                'settings.PersonalSettings.index',
                ['section' => self::APP_ID],
            ));

        return $notification;
    }
}
