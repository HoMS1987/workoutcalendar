<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

use OCA\WorkoutCalendar\BackgroundJob\CalendarMaintenanceJob;
use OCA\WorkoutCalendar\CalDav\CalendarService;
use OCA\WorkoutCalendar\Garmin\Auth\Credentials;
use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Notification\FailureNotifications;
use OCA\WorkoutCalendar\Storage\SecretStore;
use OCA\WorkoutCalendar\Storage\SetupState;
use OCA\WorkoutCalendar\Storage\SetupStep;
use OCA\WorkoutCalendar\Storage\UserStore;
use OCA\WorkoutCalendar\Sync\SyncState;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/** Signing in, signing out and resetting a person. Every change runs under the person's lock. */
final class AccountService
{
    public function __construct(
        private readonly GarminClientFactory $garmin,
        private readonly SecretStore $secrets,
        private readonly UserStore $users,
        private readonly CalendarService $calendars,
        private readonly IJobList $jobs,
        private readonly FailureNotifications $notifications,
        private readonly ITimeFactory $time,
        private readonly PersonLock $lock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Checks the credentials with Garmin and saves them only on success.
     *
     * @throws GarminException unchanged, in which case everything stored stays as it was
     * @throws PersonBusyException if a sync run or another change holds the person's lock; then there is
     *         no request to Garmin and everything stored stays as it was
     */
    public function login(string $uid, string $email, string $password): SetupState
    {
        $email = trim($email);
        $credentials = new Credentials($email, $password);

        // WHY Garmin inside the lock: otherwise a second operation checks in parallel and overtakes on save.
        return $this->lock->run($uid, function () use ($uid, $email, $credentials): SetupState {
            $tokens = $this->garmin->authenticator()->login($credentials);

            return $this->store($uid, $email, $credentials, $tokens);
        });
    }

    private function store(string $uid, string $email, Credentials $credentials, GarminTokens $tokens): SetupState
    {
        $this->secrets->saveCredentials($uid, $credentials);
        $this->secrets->saveTokens($uid, $tokens);

        $previous = $this->users->setup($uid);
        // Stable ID of the Garmin account, derived from the email address: marks the app's events and detects a switch of account.
        $accountKey = substr(hash('sha256', strtolower($email)), 0, 16);
        // Another account starts over: the window and the history import of the old one do not apply.
        $state = $previous->accountKey !== null && $previous->accountKey !== $accountKey
            ? new SyncState()
            : $this->users->state($uid);
        $state->pausedFor = null;
        $state->notifiedCategory = null;
        // After a successful sign-in the status no longer shows an old error.
        $state->failureCategory = null;
        $state->failureStep = null;
        $state->failureMessage = null;
        $state->failureSince = null;
        $state->failureCount = 0;
        $state->failureReportedAt = null;

        // Signing in again keeps the setup position; without a calendar it continues at the calendar choice.
        $step = match (true) {
            $previous->calendarId === null => SetupStep::Calendar,
            $previous->step === SetupStep::History => SetupStep::History,
            default => SetupStep::Done,
        };
        $state->nextRunAt = $step === SetupStep::Done ? $this->time->getTime() : null;

        $setup = new SetupState(
            $step,
            true,
            $accountKey,
            EmailMask::mask($email),
            $previous->calendarId,
            $previous->calendarCreatedByApp,
            $previous->maintenance,
            $previous->maintenanceFailed,
            $previous->calendarMissing,
        );
        $this->users->saveSetup($uid, $setup);
        $this->users->saveState($uid, $state);
        $this->notifications->dismiss($uid);

        return $setup;
    }

    /**
     * @throws \InvalidArgumentException for Cleanup::Calendar on a calendar the app did not create
     *         and for Cleanup::Events or Cleanup::Calendar while a maintenance job is running
     * @throws PersonBusyException if a sync run or another change holds the person's lock
     */
    public function logout(string $uid, Cleanup $cleanup): void
    {
        $this->lock->run($uid, fn () => $this->disconnect($uid, $cleanup));
    }

    private function disconnect(string $uid, Cleanup $cleanup): void
    {
        $setup = $this->users->setup($uid);
        if ($cleanup === Cleanup::Calendar && !$setup->calendarCreatedByApp) {
            throw new \InvalidArgumentException('Only a calendar created by the app can be deleted');
        }
        if ($cleanup !== Cleanup::None && $setup->maintenance !== null) {
            // WHY: A second maintenance job or deleting the calendar would overtake the running move or removal.
            throw new \InvalidArgumentException('Moving or removing the events is still running');
        }

        $state = $this->users->state($uid);
        $state->notifiedCategory = null;
        $calendarId = $setup->calendarId;
        $maintenance = $setup->maintenance;
        $createdByApp = $setup->calendarCreatedByApp;

        switch ($cleanup) {
            case Cleanup::None:
                $state->nextRunAt = null;
                break;
            case Cleanup::Events:
                if ($calendarId !== null) {
                    $maintenance = 'remove';
                    // WHY save first: The job can start immediately. This way the marker is set before
                    // there is a job that clears it on completion.
                    $this->users->saveSetup($uid, new SetupState(
                        $setup->step,
                        $setup->connected,
                        $setup->accountKey,
                        $setup->maskedEmail,
                        $calendarId,
                        $createdByApp,
                        $maintenance,
                        $setup->maintenanceFailed,
                        $setup->calendarMissing,
                    ));
                    try {
                        $this->jobs->add(CalendarMaintenanceJob::class, [
                            'uid' => $uid,
                            'action' => 'remove',
                            'from' => $calendarId,
                            'to' => null,
                        ]);
                    } catch (\Throwable $e) {
                        // Without a job nobody would clear the marker, and sync and cleanup would stay locked.
                        try {
                            $this->users->saveSetup($uid, $setup);
                        } catch (\Throwable $restore) {
                            // WHY: The caller maps the original exception; a failed restore must not replace it.
                            $this->logger->error('Restoring the setup after a failed queueing failed', [
                                'uid' => $uid,
                                'exception' => $restore::class,
                                'message' => $restore->getMessage(),
                            ]);
                        }

                        throw $e;
                    }
                }
                $state = new SyncState();
                break;
            case Cleanup::Calendar:
                if ($calendarId !== null) {
                    $this->calendars->deleteCalendar($calendarId);
                }
                $calendarId = null;
                $createdByApp = false;
                $state = new SyncState();
                break;
        }

        // WHY only now: If the cleanup fails, the person stays signed in and can try again.
        $this->secrets->clear($uid);
        $this->notifications->dismiss($uid);

        $this->users->saveSetup($uid, new SetupState(
            $setup->step,
            false,
            $setup->accountKey,
            $setup->maskedEmail,
            $calendarId,
            $createdByApp,
            $maintenance,
            $setup->maintenanceFailed,
            $setup->calendarMissing,
        ));
        $this->users->saveState($uid, $state);
    }

    /** Like {@see logout()}, afterwards the setup starts over at the Garmin step. */
    public function reset(string $uid, Cleanup $cleanup): void
    {
        $this->lock->run($uid, function () use ($uid, $cleanup): void {
            $this->disconnect($uid, $cleanup);
            $maintenance = $this->users->setup($uid)->maintenance;
            $this->users->clear($uid);
            $this->secrets->clear($uid);
            if ($maintenance !== null) {
                // The maintenance job clears the marker at the end; until then no sync may create new events
                // and no cleanup may overtake the running job.
                $this->users->saveSetup($uid, new SetupState(maintenance: $maintenance));
            }
        });
    }
}
