<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

use OCA\WorkoutCalendar\CalDav\CalendarInfo;
use OCA\WorkoutCalendar\CalDav\CalendarService;
use OCA\WorkoutCalendar\Storage\UserStore;

/**
 * Stand einer Person für die Einstellungsseite. Zeiten sind Unix-Sekunden. Enthält nur die maskierte
 * E-Mail-Adresse, nie die Adresse selbst, Passwort, Tokens oder den Kontoschlüssel.
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

    /** Nur eigene Kalender, ein gelöschter oder fremder Kalender zählt nicht. */
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
