<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Sync;

use OCA\WorkoutCalendar\Sync\SyncState;
use PHPUnit\Framework\TestCase;

final class SyncStateTest extends TestCase
{
    public function testEmptyArrayGivesFreshState(): void
    {
        $state = SyncState::fromArray([]);

        self::assertFalse($state->initialized);
        self::assertNull($state->lastSuccessAt);
        self::assertSame(0, $state->failureCount);
    }

    public function testRoundTripThroughArray(): void
    {
        $state = new SyncState();
        $state->initialized = true;
        $state->lastSuccessAt = 1_800_000_000;
        $state->lastRunAt = 1_800_000_100;
        $state->nextRunAt = 1_800_001_900;
        $state->backfillBefore = '2026-09-20';
        $state->backfillUntil = null;
        $state->backfillDone = false;
        $state->failureCategory = 'BLOCKED';
        $state->failureStep = 'mobile.login';
        $state->failureMessage = 'HTTP 429';
        $state->failureSince = 1_799_000_000;
        $state->failureCount = 3;
        $state->failureReportedAt = 1_799_000_000;

        self::assertEquals($state, SyncState::fromArray(json_decode(json_encode($state->toArray()), true)));
    }

    public function testStateRoundTripKeepsNewFields(): void
    {
        $state = new SyncState();
        $state->pausedFor = 'INVALID_CREDENTIALS';
        $state->oldestActivityDate = '2026-09-15';
        $state->notifiedCategory = 'MFA_REQUIRED';

        $copy = SyncState::fromArray(json_decode(json_encode($state->toArray()), true));

        self::assertSame('INVALID_CREDENTIALS', $copy->pausedFor);
        self::assertSame('2026-09-15', $copy->oldestActivityDate);
        self::assertSame('MFA_REQUIRED', $copy->notifiedCategory);
    }

    public function testPausedForMfaCountsAsMfaAccount(): void
    {
        $state = new SyncState();
        self::assertFalse($state->isMfaAccount());

        $state->pausedFor = 'MFA_REQUIRED';
        self::assertTrue($state->isMfaAccount());

        $state->pausedFor = 'INVALID_CREDENTIALS';
        self::assertFalse($state->isMfaAccount());

        $state->mfaAccount = true;
        self::assertTrue($state->isMfaAccount());
    }

    public function testMfaFieldsRoundTrip(): void
    {
        $state = new SyncState();
        $state->mfaAccount = true;
        $state->mfaMethod = 'email';

        $copy = SyncState::fromArray(json_decode(json_encode($state->toArray()), true));

        self::assertTrue($copy->mfaAccount);
        self::assertSame('email', $copy->mfaMethod);
        self::assertEquals($state, $copy);
    }

    public function testIgnoresUnknownAndInvalidValues(): void
    {
        $state = SyncState::fromArray(['initialized' => 'yes', 'failureCount' => 'many', 'unknown' => 1]);

        self::assertFalse($state->initialized);
        self::assertSame(0, $state->failureCount);
    }

    public function testRestartImportKeepsOnlyTheAccountState(): void
    {
        $state = new SyncState();
        $state->initialized = true;
        $state->lastSuccessAt = 100;
        $state->nextRunAt = 200;
        $state->backfillBefore = '2020-01-01';
        $state->backfillDone = true;
        $state->oldestActivityDate = '2012-04-29';
        $state->failureCategory = 'MFA_REQUIRED';
        $state->failureCount = 3;
        $state->pausedFor = 'MFA_REQUIRED';
        $state->notifiedCategory = 'MFA_REQUIRED';
        $state->mfaMethod = 'sms';

        $fresh = $state->restartImport();

        $expected = new SyncState();
        $expected->pausedFor = 'MFA_REQUIRED';
        $expected->notifiedCategory = 'MFA_REQUIRED';
        // Derived from the pause of a 1.0.x install, so the flag survives once the pause ends.
        $expected->mfaAccount = true;
        $expected->mfaMethod = 'sms';
        self::assertEquals($expected, $fresh);
        self::assertTrue($state->initialized, 'the original state stays unchanged');
    }
}
