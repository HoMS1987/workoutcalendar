<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

use OCA\WorkoutCalendar\CalDav\CalendarInfo;
use OCA\WorkoutCalendar\CalDav\CalendarService;
use OCA\WorkoutCalendar\Storage\UserStore;

/**
 * A person's state for the settings page. Times are Unix seconds. Contains only the masked
 * email address, never the address itself, the password, tokens or the account key.
 */
final class StatusService
{
    public function __construct(
        private readonly UserStore $users,
        private readonly CalendarService $calendars,
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
                ],
            ],
            'failure' => $state->failureCategory === null ? null : [
                'category' => $state->failureCategory,
                'since' => $state->failureSince,
                'count' => $state->failureCount,
            ],
            'paused' => $state->pausedFor,
            'settings' => $this->users->settings($uid)->toArray(),
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
