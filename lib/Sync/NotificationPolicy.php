<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Sync;

use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;

/**
 * Decides whether a notification is sent or removed for the sync state.
 * Pure rule: does not change the state; the caller sets notifiedCategory afterwards.
 */
final class NotificationPolicy
{
    /** Wait since the error began before categories other than rejected password / verification code are reported. */
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
