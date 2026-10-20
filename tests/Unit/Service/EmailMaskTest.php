<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Service;

use OCA\WorkoutCalendar\Service\EmailMask;
use PHPUnit\Framework\TestCase;

final class EmailMaskTest extends TestCase
{
    public function testMaskKeepsFirstLetterAndDomain(): void
    {
        self::assertSame('a***@example.org', EmailMask::mask('anna@example.org'));
    }

    public function testMaskWithoutAt(): void
    {
        self::assertSame('***', EmailMask::mask('anna'));
        self::assertSame('***', EmailMask::mask(''));
    }

    public function testMaskWithEmptyLocalPart(): void
    {
        self::assertSame('***', EmailMask::mask('@example.org'));
    }

    public function testMaskKeepsMultibyteLetter(): void
    {
        self::assertSame('ä***@example.org', EmailMask::mask('änna@example.org'));
    }
}
