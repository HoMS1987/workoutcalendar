<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

use OCA\WorkoutCalendar\CalDav\CalendarInfo;
use OCA\WorkoutCalendar\CalDav\CalendarService;
use OCA\WorkoutCalendar\Storage\SetupStep;
use OCA\WorkoutCalendar\Storage\SecretStore;
use OCA\WorkoutCalendar\Storage\UserStore;
use OCA\WorkoutCalendar\Sync\SyncState;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * A person's state for the settings page. Times are Unix seconds. Contains only the masked
 * email address, never the address itself, the password, tokens or the account key.
 */
final class StatusService
{
    public function __construct(
        private readonly UserStore $users,
        private readonly CalendarService $calendars,
        private readonly SecretStore $secrets,
        private readonly ITimeFactory $time,
    ) {
    }

    /** @return array<string, mixed> */
    public function status(string $uid): array
    {
        $setup = $this->users->setup($uid);
        $state = $this->users->state($uid);
        $calendar = $setup->calendarId === null ? null : $this->ownCalendar($uid, $setup->calendarId);

        return [
            'setup' => [
                'step' => $setup->step->value,
                'connected' => $setup->connected,
                'maskedEmail' => $setup->maskedEmail,
                'calendar' => $setup->calendarId === null ? null : [
                    'id' => $setup->calendarId,
                    'name' => $calendar?->name,
                    'createdByApp' => $setup->calendarCreatedByApp,
                ],
                'maintenance' => $setup->maintenance,
                'maintenanceFailed' => $setup->maintenanceFailed,
                'calendarMissing' => $setup->calendarMissing,
            ],
            'sync' => [
                'lastRunAt' => $state->lastRunAt,
                'lastSuccessAt' => $state->lastSuccessAt,
                'nextRunAt' => $state->nextRunAt,
                'eventCount' => $calendar === null ? 0 : $this->calendars->countAppEventsByUri($calendar->id),
                'oldestActivityDate' => $state->oldestActivityDate,
                'backfill' => [
                    'running' => $state->initialized && !$state->backfillDone,
                    'reachedDate' => $state->backfillBefore,
                    // WHY initialized: every run sets it first, and a fresh state (calendar change without
                    // moving, other account) clears it. So the import waits for its first run.
                    'pending' => !$state->initialized
                        && $setup->connected
                        && $setup->step === SetupStep::Done
                        && $calendar !== null
                        && !$setup->calendarMissing
                        && $state->pausedFor === null,
                ],
            ],
            'failure' => $state->failureCategory === null ? null : [
                'category' => $state->failureCategory,
                'since' => $state->failureSince,
                'count' => $state->failureCount,
            ],
            'paused' => $state->pausedFor,
            'mfa' => $this->mfa($uid, $state),
            'settings' => $this->users->settings($uid)->toArray(),
        ];
    }

    /**
     * Two-step verification state. Only origin, method and expiry of a pending request, never the
     * challenge, its cookies or the credentials of a setup request.
     *
     * @return array{account: bool, method: ?string, pending: ?array{origin: string, method: string, expiresAt: int}, nextRequestAt: ?int}
     */
    private function mfa(string $uid, SyncState $state): array
    {
        $now = $this->time->getTime();
        $pending = $this->secrets->pendingMfa($uid, $now);

        return [
            'account' => $state->isMfaAccount(),
            'method' => $state->mfaMethod,
            'pending' => $pending === null ? null : [
                'origin' => $pending->origin->value,
                'method' => $pending->challenge->method,
                'expiresAt' => $pending->expiresAt(),
            ],
            'nextRequestAt' => MfaRequestLimit::nextAllowedAt($this->users->mfaRequests($uid), $now),
        ];
    }

    /** Own calendars only; a deleted or foreign calendar does not count. */
    private function ownCalendar(string $uid, int $calendarId): ?CalendarInfo
    {
        foreach ($this->calendars->ownCalendars($uid) as $calendar) {
            if ($calendar->id === $calendarId) {
                return $calendar;
            }
        }

        return null;
    }
}
