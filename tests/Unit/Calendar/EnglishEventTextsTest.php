<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Calendar;

use OCA\WorkoutCalendar\Calendar\EnglishEventTexts;
use PHPUnit\Framework\TestCase;

final class EnglishEventTextsTest extends TestCase
{
    public function testThousandsSeparatorFollowsTexts(): void
    {
        self::assertSame('1,234.5', (new EnglishEventTexts())->number(1234.5, 1));
    }

    public function testTranslateReplacesPlaceholdersInOrder(): void
    {
        self::assertSame('Pace: 5:30 min/km', (new EnglishEventTexts())->translate('Pace: %s min/km', ['5:30']));
        self::assertSame('a-b', (new EnglishEventTexts())->translate('%s-%s', ['a', 'b']));
    }

    public function testTranslateWithoutParametersReturnsSource(): void
    {
        self::assertSame('Running', (new EnglishEventTexts())->translate('Running'));
    }
}
