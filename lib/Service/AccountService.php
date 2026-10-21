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

/** Anmelden, Abmelden und Zurücksetzen einer Person. Jede Änderung läuft unter der Sperre der Person. */
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
    ) {
    }

    /**
     * Prüft die Zugangsdaten bei Garmin und speichert sie erst bei Erfolg.
     *
     * @throws GarminException unverändert, dann bleibt alles Gespeicherte wie es war
     * @throws PersonBusyException wenn gerade ein Sync läuft, dann bleibt alles Gespeicherte wie es war
     */
    public function login(string $uid, string $email, string $password): SetupState
    {
        $email = trim($email);
        $credentials = new Credentials($email, $password);
        $tokens = $this->garmin->authenticator()->login($credentials);

        return $this->lock->run($uid, fn (): SetupState => $this->store($uid, $email, $credentials, $tokens));
    }

    private function store(string $uid, string $email, Credentials $credentials, GarminTokens $tokens): SetupState
    {
        $this->secrets->saveCredentials($uid, $credentials);
        $this->secrets->saveTokens($uid, $tokens);

        $previous = $this->users->setup($uid);
        $accountKey = substr(hash('sha256', strtolower($email)), 0, 16);
        $state = $previous->accountKey !== null && $previous->accountKey !== $accountKey
            ? new SyncState()
            : $this->users->state($uid);
        $state->pausedFor = null;
        $state->notifiedCategory = null;
        // Nach erfolgreicher Anmeldung zeigt der Status keinen alten Fehler mehr.
        $state->failureCategory = null;
        $state->failureStep = null;
        $state->failureMessage = null;
        $state->failureSince = null;
        $state->failureCount = 0;
        $state->failureReportedAt = null;

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
     * @throws \InvalidArgumentException bei Cleanup::Calendar für einen Kalender, den die App nicht angelegt hat
     *         und bei Cleanup::Events oder Cleanup::Calendar, solange ein Wartungsjob läuft
     * @throws PersonBusyException wenn gerade ein Sync läuft
     */
    public function logout(string $uid, Cleanup $cleanup): void
    {
        $this->lock->run($uid, fn () => $this->disconnect($uid, $cleanup));
    }

    private function disconnect(string $uid, Cleanup $cleanup): void
    {
        $setup = $this->users->setup($uid);
        if ($cleanup === Cleanup::Calendar && !$setup->calendarCreatedByApp) {
            throw new \InvalidArgumentException('Nur ein von der App angelegter Kalender kann gelöscht werden');
        }
        if ($cleanup !== Cleanup::None && $setup->maintenance !== null) {
            // WHY: Ein zweiter Wartungsjob oder das Löschen des Kalenders würde den laufenden Umzug oder das Entfernen überholen.
            throw new \InvalidArgumentException('Verschieben oder Entfernen der Termine läuft noch');
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
                    $this->jobs->add(CalendarMaintenanceJob::class, [
                        'uid' => $uid,
                        'action' => 'remove',
                        'from' => $calendarId,
                        'to' => null,
                    ]);
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

        // WHY erst jetzt: Scheitert die Bereinigung, bleibt die Person angemeldet und kann es erneut versuchen.
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

    /** Wie {@see logout()}, danach beginnt die Einrichtung mit Schritt Garmin von vorn. */
    public function reset(string $uid, Cleanup $cleanup): void
    {
        $this->lock->run($uid, function () use ($uid, $cleanup): void {
            $this->disconnect($uid, $cleanup);
            $maintenance = $this->users->setup($uid)->maintenance;
            $this->users->clear($uid);
            $this->secrets->clear($uid);
            if ($maintenance !== null) {
                // Der Wartungsjob löscht die Markierung am Ende; bis dahin darf kein Sync neue Termine anlegen
                // und keine Bereinigung den laufenden Job überholen.
                $this->users->saveSetup($uid, new SetupState(maintenance: $maintenance));
            }
        });
    }
}
