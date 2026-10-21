<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Controller;

use OCA\WorkoutCalendar\CalDav\CalendarInfo;
use OCA\WorkoutCalendar\CalDav\CalendarService;
use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Service\AccountService;
use OCA\WorkoutCalendar\Service\CalendarSetupService;
use OCA\WorkoutCalendar\Service\Cleanup;
use OCA\WorkoutCalendar\Service\PersonBusyException;
use OCA\WorkoutCalendar\Service\PersonLock;
use OCA\WorkoutCalendar\Service\StatusService;
use OCA\WorkoutCalendar\Service\SyncRunner;
use OCA\WorkoutCalendar\Storage\SetupState;
use OCA\WorkoutCalendar\Storage\SetupStep;
use OCA\WorkoutCalendar\Storage\UserStore;
use OCA\WorkoutCalendar\Sync\DeletionMode;
use OCA\WorkoutCalendar\Sync\SyncService;
use OCA\WorkoutCalendar\Sync\SyncSettings;
use OCA\WorkoutCalendar\Sync\SyncState;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * JSON-API der Einstellungsseite. Jede Route arbeitet nur mit den Daten der angemeldeten Person.
 * Fehler: Garmin → 422 mit Fehlerkategorie, ungültige Anfrage → 400, laufender Sync → 409 `busy`,
 * alles andere → 500.
 */
final class ApiController extends Controller
{
    private const HISTORY_DAYS = [30, 90, 365];

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IUserSession $session,
        private readonly AccountService $accounts,
        private readonly CalendarSetupService $calendarSetup,
        private readonly CalendarService $calendars,
        private readonly SyncRunner $runner,
        private readonly StatusService $status,
        private readonly UserStore $users,
        private readonly ITimeFactory $time,
        private readonly LoggerInterface $logger,
        private readonly PersonLock $lock,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/status')]
    public function status(): JSONResponse
    {
        return $this->handle(fn (string $uid): array => $this->status->status($uid));
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 3600)]
    #[FrontpageRoute(verb: 'POST', url: '/api/login')]
    public function login(string $email = '', string $password = ''): JSONResponse
    {
        return $this->handle(function (string $uid) use ($email, $password): array {
            if (trim($email) === '' || $password === '') {
                throw new \InvalidArgumentException('E-Mail-Adresse und Passwort sind anzugeben');
            }
            $this->accounts->login($uid, $email, $password);

            return $this->status->status($uid);
        }, logMessage: false);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/calendars')]
    public function calendars(): JSONResponse
    {
        return $this->handle(fn (string $uid): array => [
            'defaultName' => $this->calendarSetup->defaultName(),
            'calendars' => array_map(
                static fn (CalendarInfo $c): array => ['id' => $c->id, 'name' => $c->name, 'color' => $c->color],
                $this->calendars->ownCalendars($uid),
            ),
        ]);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/calendar')]
    public function calendar(?string $name = null, ?int $calendarId = null, bool $confirmed = false, bool $moveEvents = false): JSONResponse
    {
        return $this->handle(function (string $uid) use ($name, $calendarId, $confirmed, $moveEvents): array {
            $choice = $this->calendarSetup->choose($uid, $name, $calendarId, $confirmed, $moveEvents);

            return [
                'needsConfirmation' => $choice->needsConfirmation,
                'existingEvents' => $choice->existingEvents,
                'calendar' => $choice->calendar === null ? null : ['id' => $choice->calendar->id, 'name' => $choice->calendar->name],
                'status' => $this->status->status($uid),
            ];
        });
    }

    /**
     * Im Assistenten (Schritt Historie) schließt die Wahl die Einrichtung ab und führt die erste Etappe
     * sofort aus. Danach ändert sie nur den Zeitraum, ein längerer setzt den Import fort.
     *
     * @param mixed $historyDays 30, 90, 365 oder null für die komplette Historie
     */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/history')]
    public function history(mixed $historyDays = null): JSONResponse
    {
        return $this->handle(function (string $uid) use ($historyDays): array {
            // WHY: IRequest::getParam liefert für fehlende und für null-Werte den Standardwert.
            if ($historyDays === null && !array_key_exists('historyDays', $this->request->getParams())) {
                throw new \InvalidArgumentException('historyDays fehlt');
            }
            if ($historyDays !== null && !in_array($historyDays, self::HISTORY_DAYS, true)) {
                throw new \InvalidArgumentException('Ungültiger Zeitraum');
            }
            /** @var ?int $historyDays */
            $now = $this->now();
            // WHY: Die Sperre ist nicht wiedereintrittsfähig, der erste Lauf startet erst nach ihrer Freigabe.
            $finishedWizard = $this->lock->run($uid, fn (): bool => $this->applyHistory($uid, $historyDays, $now));
            if ($finishedWizard) {
                $this->runner->run($uid, $now);
            }

            return $this->status->status($uid);
        });
    }

    /** @return bool true, wenn die Wahl den Assistenten abgeschlossen hat */
    private function applyHistory(string $uid, ?int $historyDays, \DateTimeImmutable $now): bool
    {
        $setup = $this->users->setup($uid);
        if ($setup->step !== SetupStep::History && $setup->step !== SetupStep::Done) {
            throw new \InvalidArgumentException('Die Historie lässt sich erst nach der Kalenderwahl festlegen');
        }

        $settings = $this->users->settings($uid)->toArray();
        $settings['historyDays'] = $historyDays;
        $this->users->saveSettings($uid, SyncSettings::fromArray($settings));

        if ($setup->step === SetupStep::History) {
            $this->users->saveSetup($uid, new SetupState(
                SetupStep::Done,
                $setup->connected,
                $setup->accountKey,
                $setup->maskedEmail,
                $setup->calendarId,
                $setup->calendarCreatedByApp,
                $setup->maintenance,
                $setup->maintenanceFailed,
                $setup->calendarMissing,
            ));
            // WHY: Nach einem fehlenden Kalender stünde sonst der alte Zustand da (Historie fertig,
            // letzter Erfolg), der neue Kalender bekäme keinen Import. Die Pause bleibt: ohne neue
            // Zugangsdaten gibt es keinen weiteren Anmeldeversuch.
            $previous = $this->users->state($uid);
            $state = new SyncState();
            $state->pausedFor = $previous->pausedFor;
            $state->notifiedCategory = $previous->notifiedCategory;
            $state->nextRunAt = $now->getTimestamp();
            $this->users->saveState($uid, $state);

            return true;
        }

        $state = $this->users->state($uid);
        SyncService::changeHistory($state, $historyDays, $now);
        $state->nextRunAt = $now->getTimestamp();
        $this->users->saveState($uid, $state);

        return false;
    }

    /** Ändert Intervall, Streuung, Umgang mit gelöschten Aktivitäten und Prüfzeitraum. Die Historie bleibt. */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'PUT', url: '/api/settings')]
    public function updateSettings(
        mixed $intervalMinutes = null,
        mixed $jitterPercent = null,
        mixed $deletionMode = null,
        mixed $deletionCheckDays = null,
    ): JSONResponse {
        return $this->handle(function (string $uid) use ($intervalMinutes, $jitterPercent, $deletionMode, $deletionCheckDays): array {
            $changes = [];
            foreach (['intervalMinutes' => $intervalMinutes, 'jitterPercent' => $jitterPercent, 'deletionCheckDays' => $deletionCheckDays] as $key => $value) {
                if ($value === null) {
                    continue;
                }
                if (!is_int($value)) {
                    throw new \InvalidArgumentException("$key muss eine ganze Zahl sein");
                }
                $changes[$key] = $value;
            }
            if ($deletionMode !== null) {
                if (!is_string($deletionMode) || DeletionMode::tryFrom($deletionMode) === null) {
                    throw new \InvalidArgumentException('Ungültiger Umgang mit gelöschten Aktivitäten');
                }
                $changes['deletionMode'] = $deletionMode;
            }
            $this->lock->run($uid, function () use ($uid, $changes): void {
                $previous = $this->users->settings($uid);
                $settings = SyncSettings::fromArray([...$previous->toArray(), ...$changes]);
                $this->users->saveSettings($uid, $settings);
                $state = $this->users->state($uid);
                if ($settings->intervalMinutes !== $previous->intervalMinutes && $state->nextRunAt !== null) {
                    // Wer in der Warteschlange steht, bekommt den nächsten Lauf nach dem neuen Intervall.
                    $state->nextRunAt = $settings->nextRunAt($this->time->getTime());
                    $this->users->saveState($uid, $state);
                }
            });

            return $this->status->status($uid);
        });
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 3600)]
    #[FrontpageRoute(verb: 'POST', url: '/api/sync')]
    public function sync(): JSONResponse
    {
        return $this->handle(function (string $uid): array {
            $now = $this->now();
            $this->arm($uid, $now);
            $outcome = $this->runner->run($uid, $now);

            return ['outcome' => $outcome->value, 'status' => $this->status->status($uid)];
        });
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/logout')]
    public function logout(mixed $cleanup = 'none'): JSONResponse
    {
        return $this->handle(function (string $uid) use ($cleanup): array {
            $this->accounts->logout($uid, self::cleanup($cleanup));

            return $this->status->status($uid);
        });
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/reset')]
    public function reset(mixed $cleanup = 'none'): JSONResponse
    {
        return $this->handle(function (string $uid) use ($cleanup): array {
            $this->accounts->reset($uid, self::cleanup($cleanup));

            return $this->status->status($uid);
        });
    }

    /**
     * @param callable(string): array<string, mixed> $action
     * @param bool $logMessage false, wenn Meldungen Zugangsdaten enthalten könnten (Anmeldung)
     */
    private function handle(callable $action, bool $logMessage = true): JSONResponse
    {
        $user = $this->session->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'notLoggedIn'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            return new JSONResponse($action($user->getUID()));
        } catch (GarminException $e) {
            return new JSONResponse(['error' => $e->category->value], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (PersonBusyException) {
            return new JSONResponse(['error' => 'busy'], Http::STATUS_CONFLICT);
        } catch (\InvalidArgumentException) {
            return new JSONResponse(['error' => 'invalidRequest'], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            // Bei der Anmeldung nur die Klasse: Meldung und Anfrage könnten Zugangsdaten enthalten.
            $context = ['exceptionClass' => $e::class];
            if ($logMessage) {
                $context['message'] = $e->getMessage();
            }
            $this->logger->error('Anfrage der Einstellungsseite fehlgeschlagen', $context);

            return new JSONResponse(['error' => 'internal'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /** Stellt die Person sofort in die Warteschlange. */
    private function arm(string $uid, \DateTimeImmutable $now): void
    {
        $state = $this->users->state($uid);
        $state->nextRunAt = $now->getTimestamp();
        $this->users->saveState($uid, $state);
    }

    private function now(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new \DateTimeZone('UTC'));
    }

    private static function cleanup(mixed $value): Cleanup
    {
        $cleanup = is_string($value) ? Cleanup::tryFrom($value) : null;
        if ($cleanup === null) {
            throw new \InvalidArgumentException('Ungültige Angabe für cleanup');
        }

        return $cleanup;
    }
}
