<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Calendar;

use OCA\WorkoutCalendar\Calendar\CalendarEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CalendarEventTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fields(): array
    {
        $utc = new \DateTimeZone('UTC');

        return [
            'uid' => 'garmin-42',
            'summary' => '🏃 Laufen – 7,6 km',
            'description' => "Dauer: 42:10 min\nDistanz: 7,60 km",
            'startUtc' => new \DateTimeImmutable('2026-10-19 05:12:03', $utc),
            'endUtc' => new \DateTimeImmutable('2026-10-19 05:54:13', $utc),
            'url' => 'https://connect.garmin.com/modern/activity/42',
            'activityId' => '42',
            'categories' => ['Garmin', 'Laufen'],
        ];
    }

    /** @param array<string, mixed> $overrides */
    private static function event(array $overrides = []): CalendarEvent
    {
        return new CalendarEvent(...array_merge(self::fields(), $overrides));
    }

    public function testSameContentGivesSameFingerprint(): void
    {
        self::assertSame(self::event()->fingerprint(), self::event()->fingerprint());
    }

    public function testFingerprintIsIndependentOfTimezoneRepresentation(): void
    {
        $berlin = new \DateTimeZone('Europe/Berlin');
        $event = self::event([
            'startUtc' => new \DateTimeImmutable('2026-10-19 07:12:03', $berlin),
            'endUtc' => new \DateTimeImmutable('2026-10-19 07:54:13', $berlin),
        ]);

        self::assertSame(self::event()->fingerprint(), $event->fingerprint());
    }

    public function testFingerprintIsCompactSha256(): void
    {
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', self::event()->fingerprint());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function changes(): iterable
    {
        $utc = new \DateTimeZone('UTC');
        yield 'uid' => [['uid' => 'garmin-43']];
        yield 'summary' => [['summary' => '🏃 Laufen – 7,7 km']];
        yield 'description' => [['description' => 'Dauer: 42:11 min']];
        yield 'start' => [['startUtc' => new \DateTimeImmutable('2026-10-19 05:12:04', $utc)]];
        yield 'end' => [['endUtc' => new \DateTimeImmutable('2026-10-19 05:54:14', $utc)]];
        yield 'url' => [['url' => 'https://connect.garmin.com/modern/activity/43']];
        yield 'activityId' => [['activityId' => '43']];
        yield 'categories' => [['categories' => ['Garmin']]];
        yield 'Kategorien-Grenze' => [['categories' => ['GarminLaufen']]];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('changes')]
    public function testEveryFieldChangesFingerprint(array $override): void
    {
        self::assertNotSame(self::event()->fingerprint(), self::event($override)->fingerprint());
    }
}
