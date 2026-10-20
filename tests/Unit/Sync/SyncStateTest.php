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

    public function testIgnoresUnknownAndInvalidValues(): void
    {
        $state = SyncState::fromArray(['initialized' => 'ja', 'failureCount' => 'viele', 'unbekannt' => 1]);

        self::assertFalse($state->initialized);
        self::assertSame(0, $state->failureCount);
    }
}
