<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Service;

use OCA\WorkoutCalendar\Service\L10nEventTexts;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class L10nEventTextsTest extends TestCase
{
    public function testTranslatesWithParameters(): void
    {
        $l = $this->createMock(IL10N::class);
        $l->expects(self::once())->method('t')->with('Distance: %s', ['5,00 km'])->willReturn('Distanz: 5,00 km');

        self::assertSame('Distanz: 5,00 km', (new L10nEventTexts($l))->translate('Distance: %s', ['5,00 km']));
    }

    public function testGermanUsesCommaAsDecimalSeparator(): void
    {
        self::assertSame('1.234,6', (new L10nEventTexts($this->language('de')))->number(1234.56, 1));
        self::assertSame('1.234,6', (new L10nEventTexts($this->language('de_DE')))->number(1234.56, 1));
    }

    public function testOtherLanguagesUsePointAsDecimalSeparator(): void
    {
        self::assertSame('1,234.6', (new L10nEventTexts($this->language('en')))->number(1234.56, 1));
    }

    private function language(string $code): IL10N
    {
        $l = $this->createStub(IL10N::class);
        $l->method('getLanguageCode')->willReturn($code);

        return $l;
    }
}
