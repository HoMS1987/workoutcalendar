<?php

declare(strict_types=1);

namespace OCA\GarminCalendar\Tests\Unit\Sync;

use OCA\GarminCalendar\Sync\DeletionMode;
use OCA\GarminCalendar\Sync\SyncSettings;
use PHPUnit\Framework\TestCase;

final class SyncSettingsTest extends TestCase
{
    public function testDefaultsMatchRequirements(): void
    {
        $settings = SyncSettings::fromArray([]);

        self::assertSame(90, $settings->historyDays);
        self::assertSame(30, $settings->intervalMinutes);
        self::assertSame(20, $settings->jitterPercent);
        self::assertSame(DeletionMode::Mark, $settings->deletionMode);
        self::assertSame(30, $settings->deletionCheckDays);
    }

    public function testCompleteHistoryIsNull(): void
    {
        self::assertNull(SyncSettings::fromArray(['historyDays' => null])->historyDays);
    }

    public function testValuesAreClampedToAllowedRanges(): void
    {
        $low = SyncSettings::fromArray(['intervalMinutes' => 1, 'jitterPercent' => -5, 'deletionCheckDays' => 1, 'historyDays' => 0]);
        $high = SyncSettings::fromArray(['intervalMinutes' => 5000, 'jitterPercent' => 90, 'deletionCheckDays' => 1000]);

        self::assertSame([15, 0, 7, 1], [$low->intervalMinutes, $low->jitterPercent, $low->deletionCheckDays, $low->historyDays]);
        self::assertSame([1440, 50, 365], [$high->intervalMinutes, $high->jitterPercent, $high->deletionCheckDays]);
    }

    public function testInvalidValuesFallBackToDefaults(): void
    {
        $settings = SyncSettings::fromArray(['intervalMinutes' => 'oft', 'deletionMode' => 'weg', 'historyDays' => 'alles']);

        self::assertSame([30, DeletionMode::Mark, 90], [$settings->intervalMinutes, $settings->deletionMode, $settings->historyDays]);
    }

    public function testDeletionModeFromString(): void
    {
        self::assertSame(DeletionMode::Delete, SyncSettings::fromArray(['deletionMode' => 'delete'])->deletionMode);
        self::assertSame(DeletionMode::Keep, SyncSettings::fromArray(['deletionMode' => 'keep'])->deletionMode);
    }

    public function testNextRunWithoutJitterIsExactlyOneInterval(): void
    {
        $settings = SyncSettings::fromArray(['jitterPercent' => 0]);

        self::assertSame(1000 + 30 * 60, $settings->nextRunAt(1000, static fn (int $min, int $max): int => $max));
    }

    public function testNextRunIsSpreadWithinJitterRange(): void
    {
        $settings = SyncSettings::fromArray(['intervalMinutes' => 30, 'jitterPercent' => 20]);

        // 30 min ± 20 % = 24 bis 36 min
        self::assertSame(1000 + 24 * 60, $settings->nextRunAt(1000, static fn (int $min, int $max): int => $min));
        self::assertSame(1000 + 36 * 60, $settings->nextRunAt(1000, static fn (int $min, int $max): int => $max));
    }
}
