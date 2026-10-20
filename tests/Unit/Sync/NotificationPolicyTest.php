<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Sync;

use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Sync\NotificationAction;
use OCA\WorkoutCalendar\Sync\NotificationPolicy;
use OCA\WorkoutCalendar\Sync\SyncState;
use PHPUnit\Framework\TestCase;

final class NotificationPolicyTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private NotificationPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new NotificationPolicy();
    }

    private static function failing(FailureCategory $category, int $since, ?FailureCategory $notified = null): SyncState
    {
        $state = new SyncState();
        $state->failureCategory = $category->value;
        $state->failureSince = $since;
        $state->failureCount = 1;
        $state->notifiedCategory = $notified?->value;

        return $state;
    }

    public function testInvalidCredentialsNotifyImmediately(): void
    {
        $state = self::failing(FailureCategory::InvalidCredentials, self::NOW);

        self::assertSame(NotificationAction::Send, $this->policy->decide($state, self::NOW));
    }

    public function testMfaRequiredNotifiesImmediately(): void
    {
        $state = self::failing(FailureCategory::MfaRequired, self::NOW);

        self::assertSame(NotificationAction::Send, $this->policy->decide($state, self::NOW));
    }

    public function testBlockedNotifiesAfterOneDay(): void
    {
        self::assertSame(
            NotificationAction::None,
            $this->policy->decide(self::failing(FailureCategory::Blocked, self::NOW - 86_399), self::NOW),
        );
        self::assertSame(
            NotificationAction::Send,
            $this->policy->decide(self::failing(FailureCategory::Blocked, self::NOW - 86_400), self::NOW),
        );
    }

    public function testProtocolChangedNotifiesAfterOneDay(): void
    {
        self::assertSame(
            NotificationAction::None,
            $this->policy->decide(self::failing(FailureCategory::ProtocolChanged, self::NOW - 86_399), self::NOW),
        );
        self::assertSame(
            NotificationAction::Send,
            $this->policy->decide(self::failing(FailureCategory::ProtocolChanged, self::NOW - 86_400), self::NOW),
        );
    }

    public function testSameFailureIsNotSentTwice(): void
    {
        $state = self::failing(FailureCategory::InvalidCredentials, self::NOW - 90_000, FailureCategory::InvalidCredentials);

        self::assertSame(NotificationAction::None, $this->policy->decide($state, self::NOW));
    }

    public function testChangedCategorySendsAgain(): void
    {
        $state = self::failing(FailureCategory::InvalidCredentials, self::NOW, FailureCategory::Network);

        self::assertSame(NotificationAction::Send, $this->policy->decide($state, self::NOW));
    }

    public function testRecoveryDismisses(): void
    {
        $state = new SyncState();
        $state->notifiedCategory = FailureCategory::Blocked->value;

        self::assertSame(NotificationAction::Dismiss, $this->policy->decide($state, self::NOW));
    }

    public function testNoFailureNoNotification(): void
    {
        self::assertSame(NotificationAction::None, $this->policy->decide(new SyncState(), self::NOW));
    }

    public function testDecideDoesNotModifyState(): void
    {
        $state = self::failing(FailureCategory::InvalidCredentials, self::NOW);
        $before = $state->toArray();

        $this->policy->decide($state, self::NOW);

        self::assertSame($before, $state->toArray());
    }
}
