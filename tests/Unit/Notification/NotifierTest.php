<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Notification;

use OCA\WorkoutCalendar\Notification\Notifier;
use OCA\WorkoutCalendar\Tests\Support\RecordingNotification;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

final class NotifierTest extends TestCase
{
    public function testIdAndName(): void
    {
        $notifier = $this->notifier();

        self::assertSame('workoutcalendar', $notifier->getID());
        self::assertSame('Workout Calendar', $notifier->getName());
    }

    public function testInvalidCredentialsText(): void
    {
        $prepared = $this->notifier()->prepare($this->notification('INVALID_CREDENTIALS'), 'de');

        self::assertSame('Workout Calendar braucht deine Aufmerksamkeit', $prepared->getParsedSubject());
        self::assertStringContainsString('Passwort', $prepared->getParsedMessage());
        self::assertStringContainsString('neu anmeldest', $prepared->getParsedMessage());
    }

    public function testEachCategoryHasItsOwnText(): void
    {
        $messages = [];
        foreach (['INVALID_CREDENTIALS', 'MFA_REQUIRED', 'PROTOCOL_CHANGED', 'NETWORK', 'ANYTHING'] as $category) {
            $messages[$category] = $this->notifier()->prepare($this->notification($category), 'de')->getParsedMessage();
        }

        self::assertStringContainsString('Bestätigungscode', $messages['MFA_REQUIRED']);
        self::assertStringContainsString('App-Update', $messages['PROTOCOL_CHANGED']);
        self::assertStringContainsString('weiter versucht', $messages['NETWORK']);
        self::assertSame($messages['NETWORK'], $messages['ANYTHING']);
        self::assertCount(4, array_unique($messages));
    }

    public function testUntranslatedLanguageFallsBackToEnglishSource(): void
    {
        $prepared = $this->notifier(translate: false)->prepare($this->notification('MFA_REQUIRED'), 'fr');

        self::assertSame('Workout Calendar needs your attention', $prepared->getParsedSubject());
        self::assertSame(
            'Your Garmin account asks for a verification code. This is not supported yet, Workout Calendar is paused.',
            $prepared->getParsedMessage(),
        );
    }

    public function testLinkPointsToSettingsSection(): void
    {
        $prepared = $this->notifier()->prepare($this->notification('INVALID_CREDENTIALS'), 'de');

        self::assertSame('https://cloud.example/settings/user/workoutcalendar', $prepared->getLink());
    }

    public function testIconIsTheDarkAppIconAsAbsoluteUrl(): void
    {
        $prepared = $this->notifier()->prepare($this->notification('INVALID_CREDENTIALS'), 'de');

        self::assertSame('https://cloud.example/apps/workoutcalendar/img/app-dark.svg', $prepared->getIcon());
    }

    public function testUnknownSubjectThrowsUnknownNotification(): void
    {
        $this->expectException(UnknownNotificationException::class);

        $this->notifier()->prepare($this->notification('INVALID_CREDENTIALS', subject: 'other'), 'de');
    }

    public function testOtherAppThrowsUnknownNotification(): void
    {
        $this->expectException(UnknownNotificationException::class);

        $this->notifier()->prepare($this->notification('INVALID_CREDENTIALS', app: 'files'), 'de');
    }

    private function notifier(bool $translate = true): Notifier
    {
        $translations = $translate ? $this->germanTable() : [];
        $l10n = $this->createStub(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static fn (string $text, $parameters = []): string => $translations[$text] ?? $text,
        );
        $factory = $this->createMock(IFactory::class);
        $factory->method('get')->willReturnCallback(
            static function (string $app, ?string $lang = null) use ($l10n): IL10N {
                self::assertSame('workoutcalendar', $app);

                return $l10n;
            },
        );
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRouteAbsolute')->willReturnCallback(
            static function (string $route, array $arguments = []): string {
                self::assertSame('settings.PersonalSettings.index', $route);
                self::assertSame(['section' => 'workoutcalendar'], $arguments);

                return 'https://cloud.example/settings/user/workoutcalendar';
            },
        );

        $urls->method('imagePath')->willReturnCallback(
            static function (string $app, string $image): string {
                self::assertSame('workoutcalendar', $app);

                return '/apps/workoutcalendar/img/' . $image;
            },
        );
        $urls->method('getAbsoluteURL')->willReturnCallback(
            static fn (string $url): string => 'https://cloud.example' . $url,
        );

        return new Notifier($factory, $urls);
    }

    /** @return array<string, string> */
    private function germanTable(): array
    {
        $json = json_decode((string) file_get_contents(__DIR__ . '/../../../l10n/de.json'), true, 512, JSON_THROW_ON_ERROR);

        return $json['translations'];
    }

    private function notification(string $category, string $subject = 'sync_failed', string $app = 'workoutcalendar'): INotification
    {
        return (new RecordingNotification())
            ->setApp($app)
            ->setUser('anna')
            ->setSubject($subject, ['category' => $category]);
    }
}
