<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Notification;

use OCA\WorkoutCalendar\Tests\Support\GermanEventTexts;
use PHPUnit\Framework\TestCase;

/** Hält l10n/de.json, l10n/de.js und die Test-Tabelle GermanEventTexts deckungsgleich. */
final class TranslationFilesTest extends TestCase
{
    private const PLURAL_FORM = 'nplurals=2; plural=(n != 1);';

    public function testJsonContainsEveryEventText(): void
    {
        $translations = $this->json()['translations'];

        foreach ($this->eventTexts() as $source => $german) {
            self::assertArrayHasKey($source, $translations);
            self::assertSame($german, $translations[$source]);
        }
    }

    public function testJsonContainsNotificationTexts(): void
    {
        $translations = $this->json()['translations'];

        foreach ([
            'Workout Calendar',
            'Workout Calendar needs your attention',
            'Garmin rejected your email address or password. Workout Calendar is paused until you sign in again.',
            'Your Garmin account asks for a verification code. This is not supported yet, Workout Calendar is paused.',
            'Workout Calendar could not sync for a day because Garmin changed its sign-in. An app update may be needed.',
            'Workout Calendar could not reach Garmin for a day. It keeps trying.',
        ] as $source) {
            self::assertArrayHasKey($source, $translations);
            self::assertNotSame('', $translations[$source]);
        }
    }

    public function testJsonDeclaresPluralForm(): void
    {
        self::assertSame(self::PLURAL_FORM, $this->json()['pluralForm']);
    }

    public function testJavaScriptFileHoldsSameTranslations(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../../../l10n/de.js');

        self::assertSame(1, preg_match('/^OC\.L10N\.register\(\s*"workoutcalendar",\s*(\{.*\}),\s*"([^"]+)"\);\s*$/s', $js, $matches));
        $fromJs = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($this->json()['translations'], $fromJs);
        self::assertSame(self::PLURAL_FORM, $matches[2]);
    }

    /** @return array<string, string> */
    private function eventTexts(): array
    {
        $table = (new \ReflectionClass(GermanEventTexts::class))->getReflectionConstant('TABLE');
        self::assertNotFalse($table);

        return $table->getValue();
    }

    /** @return array{translations: array<string, string>, pluralForm: string} */
    private function json(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../../../l10n/de.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}
