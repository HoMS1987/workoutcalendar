<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

use OCA\WorkoutCalendar\BackgroundJob\CalendarMaintenanceJob;
use OCA\WorkoutCalendar\CalDav\CalendarInfo;
use OCA\WorkoutCalendar\CalDav\CalendarService;
use OCA\WorkoutCalendar\Storage\SetupState;
use OCA\WorkoutCalendar\Storage\SetupStep;
use OCA\WorkoutCalendar\Storage\UserStore;
use OCA\WorkoutCalendar\Sync\SyncState;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IL10N;

/** Choosing a calendar (wizard) and switching it (after the setup). */
final class CalendarSetupService
{
    public function __construct(
        private readonly CalendarService $calendars,
        private readonly UserStore $users,
        private readonly IJobList $jobs,
        private readonly ITimeFactory $time,
        private readonly IL10N $l,
        private readonly PersonLock $lock,
    ) {
    }

    /** Suggested name for a new calendar. */
    public function defaultName(): string
    {
        return $this->l->t('Garmin activities');
    }

    /**
     * Creates a calendar or picks an existing one. An existing calendar is adopted only after
     * confirmation; until then everything stays unchanged.
     *
     * @throws \InvalidArgumentException for an invalid input, a foreign calendar or a missing sign-in
     * @throws PersonBusyException if a sync is running
     */
    public function choose(string $uid, ?string $newName, ?int $calendarId, bool $confirmed, bool $moveEvents): CalendarChoice
    {
        return $this->lock->run($uid, fn (): CalendarChoice => $this->chooseLocked($uid, $newName, $calendarId, $confirmed, $moveEvents));
    }

    private function chooseLocked(string $uid, ?string $newName, ?int $calendarId, bool $confirmed, bool $moveEvents): CalendarChoice
    {
        if (($newName === null) === ($calendarId === null)) {
            throw new \InvalidArgumentException('Either a name or a calendar is required');
        }
        $name = $newName === null ? null : trim($newName);
        if ($name === '') {
            throw new \InvalidArgumentException('Calendar name must not be empty');
        }
        $setup = $this->users->setup($uid);
        if ($setup->step === SetupStep::Garmin) {
            throw new \InvalidArgumentException('Sign in to Garmin first');
        }

        if ($setup->step === SetupStep::Done && $setup->maintenance !== null) {
            throw new \InvalidArgumentException('Calendar change is still running');
        }

        $existing = null;
        if ($calendarId !== null) {
            $existing = $this->ownCalendar($uid, $calendarId);
            if ($existing === null) {
                throw new \InvalidArgumentException('Unknown calendar');
            }
        } elseif ($name !== null) {
            $existing = $this->calendars->findByName($uid, $name);
        }

        if ($existing === null) {
            $calendar = $this->calendars->createCalendar($uid, (string)$name);
            $this->apply($uid, $setup, $calendar, true, $moveEvents);

            return new CalendarChoice(false, $calendar);
        }

        if ($setup->step !== SetupStep::Calendar && $setup->calendarId === $existing->id) {
            return new CalendarChoice(false, $existing);
        }
        if (!$confirmed) {
            return new CalendarChoice(true, $existing, $this->calendars->countAll($existing->id));
        }
        $this->apply($uid, $setup, $existing, false, $moveEvents);

        return new CalendarChoice(false, $existing);
    }

    private function ownCalendar(string $uid, int $calendarId): ?CalendarInfo
    {
        foreach ($this->calendars->ownCalendars($uid) as $calendar) {
            if ($calendar->id === $calendarId) {
                return $calendar;
            }
        }

        return null;
    }

    private function apply(string $uid, SetupState $setup, CalendarInfo $calendar, bool $createdByApp, bool $moveEvents): void
    {
        $wizard = $setup->step === SetupStep::Calendar || $setup->step === SetupStep::History;
        $previous = $setup->calendarId;
        $maintenance = $setup->maintenance;
        $state = $this->users->state($uid);

        if (!$wizard) {
            if ($previous !== null && $moveEvents) {
                $maintenance = 'move';
                $this->jobs->add(CalendarMaintenanceJob::class, [
                    'uid' => $uid,
                    'action' => 'move',
                    'from' => $previous,
                    'to' => $calendar->id,
                ]);
            } else {
                $state = new SyncState();
            }
            $state->nextRunAt = $this->time->getTime();
        }

        $this->users->saveSetup($uid, new SetupState(
            $wizard ? SetupStep::History : $setup->step,
            $setup->connected,
            $setup->accountKey,
            $setup->maskedEmail,
            $calendar->id,
            $createdByApp,
            $maintenance,
            // A newly chosen calendar ends the hints about abandoned maintenance and a missing calendar.
            null,
            false,
        ));
        if (!$wizard) {
            $this->users->saveState($uid, $state);
        }
    }
}
