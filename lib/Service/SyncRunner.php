<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

use OCA\WorkoutCalendar\AppInfo\Application;
use OCA\WorkoutCalendar\CalDav\CalendarInfo;
use OCA\WorkoutCalendar\CalDav\CalendarObjects;
use OCA\WorkoutCalendar\CalDav\CalendarService;
use OCA\WorkoutCalendar\CalDav\DavCalendarTarget;
use OCA\WorkoutCalendar\Calendar\ActivityEventMapper;
use OCA\WorkoutCalendar\Calendar\DeletedMarker;
use OCA\WorkoutCalendar\Calendar\IcsReader;
use OCA\WorkoutCalendar\Calendar\IcsSerializer;
use OCA\WorkoutCalendar\Notification\FailureNotifications;
use OCA\WorkoutCalendar\Storage\SecretStore;
use OCA\WorkoutCalendar\Storage\SetupState;
use OCA\WorkoutCalendar\Storage\SetupStep;
use OCA\WorkoutCalendar\Storage\UserStore;
use OCA\WorkoutCalendar\Sync\NotificationAction;
use OCA\WorkoutCalendar\Sync\NotificationPolicy;
use OCA\WorkoutCalendar\Sync\SyncService;
use OCA\WorkoutCalendar\Sync\SyncState;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Ein Sync-Lauf für eine Person: Sperre gegen Doppelläufe, Vorbedingungen prüfen, `SyncService`
 * ausführen, Zustand speichern und die Fehler-Benachrichtigung senden oder zurückziehen.
 */
final class SyncRunner
{
    private const LOCK_PREFIX = 'workoutcalendar/sync/';

    private readonly CalendarService $calendars;
    private readonly NotificationPolicy $policy;

    public function __construct(
        private readonly UserStore $users,
        private readonly SecretStore $secrets,
        private readonly CalendarObjects $objects,
        private readonly GarminClientFactory $garmin,
        private readonly FailureNotifications $notifications,
        private readonly ILockingProvider $locking,
        private readonly IFactory $l10nFactory,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
    ) {
        $this->calendars = new CalendarService($objects);
        $this->policy = new NotificationPolicy();
    }

    public function run(string $uid, \DateTimeImmutable $now): RunOutcome
    {
        $lock = self::LOCK_PREFIX . $uid;
        try {
            $this->locking->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (LockedException) {
            $this->logger->debug('Sync-Lauf übersprungen, ein anderer Lauf hält die Sperre', ['uid' => $uid]);

            return RunOutcome::Skipped;
        }

        try {
            return $this->runLocked($uid, $now);
        } finally {
            $this->locking->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    private function runLocked(string $uid, \DateTimeImmutable $now): RunOutcome
    {
        $credentials = $this->secrets->credentials($uid);
        if ($credentials === null) {
            return RunOutcome::NotConnected;
        }
        $state = $this->users->state($uid);
        if ($state->pausedFor !== null) {
            return RunOutcome::Paused;
        }
        $setup = $this->users->setup($uid);
        if ($setup->accountKey === null) {
            return RunOutcome::NotConnected;
        }
        if ($setup->calendarId === null) {
            return RunOutcome::CalendarMissing;
        }
        if (!$this->calendarExists($uid, $setup->calendarId)) {
            $this->logger->info('Zielkalender fehlt, Einrichtung springt zur Kalenderauswahl', ['uid' => $uid, 'calendarId' => $setup->calendarId]);
            $this->users->saveSetup($uid, new SetupState(
                SetupStep::Calendar,
                $setup->connected,
                $setup->accountKey,
                $setup->maskedEmail,
                null,
                $setup->calendarCreatedByApp,
                $setup->maintenance,
            ));

            return RunOutcome::CalendarMissing;
        }

        $target = new DavCalendarTarget(
            $this->objects,
            $setup->calendarId,
            $setup->accountKey,
            new IcsSerializer(),
            new IcsReader(),
            new DeletedMarker(),
            static fn (): \DateTimeImmutable => $now,
            $this->logger,
        );
        $sync = new SyncService(
            $this->garmin->client($uid, $credentials),
            new ActivityEventMapper(new L10nEventTexts($this->l10n($uid))),
            $target,
            $this->logger,
        );
        $sync->run($this->users->settings($uid), $state, $now);
        $this->users->saveState($uid, $state);

        $this->notify($uid, $state, $now);

        return RunOutcome::Done;
    }

    private function calendarExists(string $uid, int $calendarId): bool
    {
        $ids = array_map(static fn (CalendarInfo $c): int => $c->id, $this->calendars->ownCalendars($uid));

        return in_array($calendarId, $ids, true);
    }

    private function notify(string $uid, SyncState $state, \DateTimeImmutable $now): void
    {
        switch ($this->policy->decide($state, $now->getTimestamp())) {
            case NotificationAction::Send:
                $this->notifications->send($uid, (string)$state->failureCategory);
                $state->notifiedCategory = $state->failureCategory;
                break;
            case NotificationAction::Dismiss:
                $this->notifications->dismiss($uid);
                $state->notifiedCategory = null;
                break;
            case NotificationAction::None:
                return;
        }
        $this->users->saveState($uid, $state);
    }

    private function l10n(string $uid): IL10N
    {
        $language = $this->l10nFactory->getUserLanguage($this->userManager->get($uid));

        return $this->l10nFactory->get(Application::APP_ID, $language);
    }
}
