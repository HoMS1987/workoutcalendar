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
 * Ein Sync-Lauf für eine Person: Sperre gegen Doppelläufe, Vorbedingungen prüfen, `SyncService`
 * ausführen, Zustand speichern und die Fehler-Benachrichtigung senden oder zurückziehen.
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
     * Manueller Sync und Abschluss des Assistenten: stellt die Person in die Warteschlange und führt
     * den Lauf in derselben Sperre aus, damit kein anderer Vorgang dazwischen den Zustand ändert.
     * Ist die Sperre belegt, bleibt alles unverändert.
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
            $this->logger->debug('Sync-Lauf übersprungen, ein anderer Vorgang hält die Sperre', ['uid' => $uid]);

            return RunOutcome::Skipped;
        }
    }

    /** Stellt die Person sofort in die Warteschlange. */
    private function arm(string $uid, \DateTimeImmutable $now): void
    {
        $state = $this->users->state($uid);
        $state->nextRunAt = $now->getTimestamp();
        $this->users->saveState($uid, $state);
    }

    /** Ohne möglichen Lauf ist die Person nicht fällig. Anmeldung, Kalenderwahl und manueller Sync planen neu. */
    private function leaveQueue(string $uid): void
    {
        $state = $this->users->state($uid);
        $state->nextRunAt = null;
        $this->users->saveState($uid, $state);
    }

    /** Unerwarteter Fehler: nächster Versuch nach dem normalen Intervall, damit die Person die Warteschlange nicht blockiert. */
    private function postpone(string $uid, \DateTimeImmutable $now): void
    {
        try {
            $state = $this->users->state($uid);
            $state->nextRunAt = $this->users->settings($uid)->nextRunAt($now->getTimestamp());
            $this->users->saveState($uid, $state);
        } catch (\Throwable $e) {
            $this->logger->error('Nächster Sync-Lauf ließ sich nicht planen', ['uid' => $uid, 'exception' => $e]);
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
            // WHY: Ein Lauf vor der Wahl der Historie würde den Zustand mit dem Standardzeitraum initialisieren.
            return RunOutcome::NotReady;
        }
        if ($setup->maintenance === 'remove') {
            // Das Entfernen liest die Termine der App in jeder Etappe neu ein und würde frisch importierte mitlöschen.
            $state->nextRunAt = $this->users->settings($uid)->nextRunAt($now->getTimestamp());
            $this->users->saveState($uid, $state);

            return RunOutcome::Skipped;
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
                $setup->maintenanceFailed,
                true,
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
