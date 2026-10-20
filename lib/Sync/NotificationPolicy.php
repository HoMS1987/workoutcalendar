<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Sync;

use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;

/**
 * Entscheidet, ob zum Sync-Zustand eine Benachrichtigung gesendet oder entfernt wird.
 * Reine Regel: verändert den Zustand nicht, der Aufrufer setzt notifiedCategory danach.
 */
final class NotificationPolicy
{
    /** Wartezeit seit Fehlerbeginn, bevor andere Kategorien als abgelehntes Passwort / Verifizierungscode melden. */
    public const DELAY_SECONDS = 86_400;

    public function decide(SyncState $state, int $now): NotificationAction
    {
        $category = $state->failureCategory;
        if ($category === null) {
            return $state->notifiedCategory !== null ? NotificationAction::Dismiss : NotificationAction::None;
        }
        if ($state->notifiedCategory === $category) {
            return NotificationAction::None;
        }
        if ($this->isImmediate($category)) {
            return NotificationAction::Send;
        }
        $since = $state->failureSince ?? $now;

        return $now - $since >= self::DELAY_SECONDS ? NotificationAction::Send : NotificationAction::None;
    }

    private function isImmediate(string $category): bool
    {
        return $category === FailureCategory::InvalidCredentials->value
            || $category === FailureCategory::MfaRequired->value;
    }
}
