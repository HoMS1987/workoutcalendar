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
use Psr\Log\LoggerInterface;

/**
 * A sync run for a person: lock against double runs, check preconditions, run `SyncService`,
 * save the state and send or withdraw the failure notification.
 */
final class SyncRunner
{
    private readonly CalendarService $calendars;
    private readonly NotificationPolicy $policy;

    public function __construct(
        private readonly UserStore $users,
        private readonly SecretStore $secrets,
        private readonly CalendarObjects $objects,
        private readonly GarminClientFactory $garmin,
        private readonly FailureNotifications $notifications,
        private readonly PersonLock $lock,
        private readonly IFactory $l10nFactory,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
    ) {
        $this->calendars = new CalendarService($objects);
        $this->policy = new NotificationPolicy();
    }

    public function run(string $uid, \DateTimeImmutable $now): RunOutcome
    {
        return $this->underLock($uid, $now, false);
    }

    /**
     * Manual sync and wizard completion: makes the person due now and runs the sync under the
     * same lock, so no other operation can change the state in between.
     * If another operation holds the lock, nothing changes and the outcome is `Skipped`.
     */
    public function runNow(string $uid, \DateTimeImmutable $now): RunOutcome
    {
        return $this->underLock($uid, $now, true);
    }

    private function underLock(string $uid, \DateTimeImmutable $now, bool $arm): RunOutcome
    {
        try {
            return $this->lock->run($uid, function () use ($uid, $now, $arm): RunOutcome {
                try {
                    if ($arm) {
                        $this->arm($uid, $now);
                    }
                    $outcome = $this->runLocked($uid, $now);
                    if ($outcome !== RunOutcome::Done && $outcome !== RunOutcome::Skipped) {
                        $this->leaveQueue($uid);
                    }

                    return $outcome;
                } catch (\Throwable $e) {
                    $this->postpone($uid, $now);

                    throw $e;
                }
            });
        } catch (PersonBusyException) {
            $this->logger->debug('Sync run skipped, another operation holds the lock', ['uid' => $uid]);

            return RunOutcome::Skipped;
        }
    }

    /** Puts the person in the queue immediately. */
    private function arm(string $uid, \DateTimeImmutable $now): void
    {
        $state = $this->users->state($uid);
        $state->nextRunAt = $now->getTimestamp();
        $this->users->saveState($uid, $state);
    }

    /** Without a possible run the person is not due. Sign-in, calendar choice and manual sync reschedule. */
    private function leaveQueue(string $uid): void
    {
        $state = $this->users->state($uid);
        $state->nextRunAt = null;
        $this->users->saveState($uid, $state);
    }

    /** Unexpected error: next attempt after the normal interval so the person does not block the queue. */
    private function postpone(string $uid, \DateTimeImmutable $now): void
    {
        try {
            $state = $this->users->state($uid);
            $state->nextRunAt = $this->users->settings($uid)->nextRunAt($now->getTimestamp());
            $this->users->saveState($uid, $state);
        } catch (\Throwable $e) {
            $this->logger->error('Next sync run could not be scheduled', ['uid' => $uid, 'exception' => $e]);
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
        if ($setup->step !== SetupStep::Done) {
            // WHY: A run before the history choice would initialize the state with the default period.
            return RunOutcome::NotReady;
        }
        if ($setup->maintenance === 'remove') {
            // Removal re-reads the app's events in every stage and would also delete freshly imported ones.
            $state->nextRunAt = $this->users->settings($uid)->nextRunAt($now->getTimestamp());
            $this->users->saveState($uid, $state);

            return RunOutcome::Skipped;
        }
        if ($setup->calendarId === null) {
            return RunOutcome::CalendarMissing;
        }
        if (!$this->calendarExists($uid, $setup->calendarId)) {
            $this->logger->info('Target calendar missing, setup returns to the calendar choice', ['uid' => $uid, 'calendarId' => $setup->calendarId]);
            $this->users->saveSetup($uid, new SetupState(
                SetupStep::Calendar,
                $setup->connected,
                $setup->accountKey,
                $setup->maskedEmail,
                null,
                $setup->calendarCreatedByApp,
                $setup->maintenance,
                $setup->maintenanceFailed,
                true,
            ));

            return RunOutcome::CalendarMissing;
        }

        $texts = new L10nEventTexts($this->l10n($uid));
        $target = new DavCalendarTarget(
            $this->objects,
            $setup->calendarId,
            $setup->accountKey,
            new IcsSerializer(),
            new IcsReader(),
            new DeletedMarker($texts),
            static fn (): \DateTimeImmutable => $now,
            $this->logger,
        );
        $sync = new SyncService(
            $this->garmin->client($uid, $credentials),
            new ActivityEventMapper($texts),
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
