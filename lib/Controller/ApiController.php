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
 * JSON API of the settings page. Every route works only with the signed-in person's data.
 * Errors: no signed-in person → 401 `notLoggedIn`, Garmin → 422 with failure category, invalid
 * request → 400, running sync → 409 `busy`, anything else → 500.
 */
final class ApiController extends Controller
{
    /** Same choices as the wizard and the settings page (StepHistory.vue), besides null for the complete history. */
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
    // Every attempt reaches Garmin, and repeated failed sign-ins could lock the Garmin account.
    #[UserRateLimit(limit: 10, period: 3600)]
    #[FrontpageRoute(verb: 'POST', url: '/api/login')]
    public function login(string $email = '', string $password = ''): JSONResponse
    {
        return $this->handle(function (string $uid) use ($email, $password): array {
            if (trim($email) === '' || $password === '') {
                throw new \InvalidArgumentException('Email address and password are required');
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
     * In the wizard (history step) the choice completes the setup and runs the first stage
     * immediately. Afterwards it only changes the period; a longer one resumes the import.
     *
     * @param mixed $historyDays 30, 90, 365 or null for the complete history
     */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/history')]
    public function history(mixed $historyDays = null): JSONResponse
    {
        return $this->handle(function (string $uid) use ($historyDays): array {
            // WHY: IRequest::getParam returns the default value for missing and for null values.
            if ($historyDays === null && !array_key_exists('historyDays', $this->request->getParams())) {
                throw new \InvalidArgumentException('historyDays is missing');
            }
            if ($historyDays !== null && !in_array($historyDays, self::HISTORY_DAYS, true)) {
                throw new \InvalidArgumentException('Invalid history period');
            }
            /** @var ?int $historyDays */
            $now = $this->now();
            // WHY: The lock is not reentrant; the first run starts only after it has been released.
            $finishedWizard = $this->lock->run($uid, fn (): bool => $this->applyHistory($uid, $historyDays, $now));
            if ($finishedWizard) {
                $this->runner->runNow($uid, $now);
            }

            return $this->status->status($uid);
        });
    }

    /** @return bool true if the choice completed the wizard */
    private function applyHistory(string $uid, ?int $historyDays, \DateTimeImmutable $now): bool
    {
        $setup = $this->users->setup($uid);
        if ($setup->step !== SetupStep::History && $setup->step !== SetupStep::Done) {
            throw new \InvalidArgumentException('History can only be set after the calendar choice');
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
            // WHY: After a missing calendar the old state would otherwise remain (history done,
            // last success), and the new calendar would get no import. The pause stays: without new
            // credentials there is no further sign-in attempt.
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

    /** Changes interval, jitter, handling of deleted activities and check period. The history stays. */
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
                    throw new \InvalidArgumentException("$key must be an integer");
                }
                $changes[$key] = $value;
            }
            if ($deletionMode !== null) {
                if (!is_string($deletionMode) || DeletionMode::tryFrom($deletionMode) === null) {
                    throw new \InvalidArgumentException('Invalid handling of deleted activities');
                }
                $changes['deletionMode'] = $deletionMode;
            }
            $this->lock->run($uid, function () use ($uid, $changes): void {
                $previous = $this->users->settings($uid);
                $settings = SyncSettings::fromArray([...$previous->toArray(), ...$changes]);
                $this->users->saveSettings($uid, $settings);
                $state = $this->users->state($uid);
                if ($settings->intervalMinutes !== $previous->intervalMinutes && $state->nextRunAt !== null) {
                    // Anyone in the queue gets the next run after the new interval.
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
            $outcome = $this->runner->runNow($uid, $this->now());

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
     * @param bool $logMessage false if messages could contain credentials (sign-in)
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
            // For sign-in only the class: message and request could contain credentials.
            $context = ['exceptionClass' => $e::class];
            if ($logMessage) {
                $context['message'] = $e->getMessage();
            }
            $this->logger->error('Settings page request failed', $context);

            return new JSONResponse(['error' => 'internal'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    private function now(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new \DateTimeZone('UTC'));
    }

    private static function cleanup(mixed $value): Cleanup
    {
        $cleanup = is_string($value) ? Cleanup::tryFrom($value) : null;
        if ($cleanup === null) {
            throw new \InvalidArgumentException('Invalid value for cleanup');
        }

        return $cleanup;
    }
}
