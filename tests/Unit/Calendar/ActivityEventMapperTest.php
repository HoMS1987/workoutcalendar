<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Calendar;

use OCA\WorkoutCalendar\Calendar\ActivityEventMapper;
use OCA\WorkoutCalendar\Tests\Support\GermanEventTexts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ActivityEventMapperTest extends TestCase
{
    /** Erfundene Beispielaktivität, keine echten Nutzerdaten. */
    private static function activity(array $overrides = []): array
    {
        return array_replace([
            'activityId' => 1234567890,
            'activityName' => 'Morgenrunde am See',
            'startTimeGMT' => '2026-10-19 05:12:03',
            'startTimeLocal' => '2026-10-19 07:12:03',
            'duration' => 2530.4,
            'distance' => 7600.0,
            'averageSpeed' => 3.0039525,
            'averageHR' => 148.0,
            'maxHR' => 171.0,
            'elevationGain' => 85.2,
            'activityType' => ['typeKey' => 'running'],
        ], $overrides);
    }

    private static function mapper(): ActivityEventMapper
    {
        return new ActivityEventMapper(new GermanEventTexts());
    }

    public function testRunTitleAndUid(): void
    {
        $event = self::mapper()->map(self::activity());

        self::assertSame('garmin-1234567890', $event->uid);
        self::assertSame('1234567890', $event->activityId);
        self::assertSame('🏃 Laufen – 7,6 km', $event->summary);
    }

    public function testStartAndEndInUtc(): void
    {
        $event = self::mapper()->map(self::activity());

        self::assertSame('2026-10-19T05:12:03+00:00', $event->startUtc->format(DATE_ATOM));
        self::assertSame('2026-10-19T05:54:13+00:00', $event->endUtc->format(DATE_ATOM));
        self::assertSame('UTC', $event->startUtc->getTimezone()->getName());
    }

    public function testRunDescription(): void
    {
        $event = self::mapper()->map(self::activity());

        self::assertSame(
            "Morgenrunde am See\n"
            . "Dauer: 42:10 min\n"
            . "Distanz: 7,60 km\n"
            . "Pace: 5:33 min/km\n"
            . "Herzfrequenz: Ø 148 bpm, max 171 bpm\n"
            . "Höhenmeter: ↑ 85 m\n"
            . 'Garmin Connect: https://connect.garmin.com/modern/activity/1234567890',
            $event->description,
        );
        self::assertSame('https://connect.garmin.com/modern/activity/1234567890', $event->url);
    }

    public function testLongDurationInHours(): void
    {
        $event = self::mapper()->map(self::activity(['duration' => 3723.0]));

        self::assertStringContainsString('Dauer: 1:02:03 h', $event->description);
    }

    public function testCyclingShowsSpeed(): void
    {
        $event = self::mapper()->map(self::activity([
            'activityType' => ['typeKey' => 'road_biking'],
            'distance' => 42195.0,
            'averageSpeed' => 7.5,
        ]));

        self::assertSame('🚴 Rennrad – 42,2 km', $event->summary);
        self::assertStringContainsString('Geschwindigkeit: Ø 27,0 km/h', $event->description);
        self::assertStringNotContainsString('Pace', $event->description);
    }

    public function testSwimmingInMetersWithPacePer100m(): void
    {
        $event = self::mapper()->map(self::activity([
            'activityType' => ['typeKey' => 'lap_swimming'],
            'distance' => 1500.0,
            'duration' => 1800.0,
            'averageSpeed' => 0.8333,
        ]));

        self::assertSame('🏊 Schwimmen – 1.500 m', $event->summary);
        self::assertStringContainsString('Distanz: 1.500 m', $event->description);
        self::assertStringContainsString('Pace: 2:00 min/100 m', $event->description);
    }

    public function testWithoutDistanceOnlyName(): void
    {
        $event = self::mapper()->map(self::activity([
            'activityType' => ['typeKey' => 'strength_training'],
            'distance' => 0.0,
            'averageSpeed' => null,
            'elevationGain' => null,
        ]));

        self::assertSame('🏋️ Krafttraining', $event->summary);
        self::assertStringNotContainsString('Distanz', $event->description);
        self::assertStringNotContainsString('Pace', $event->description);
        self::assertStringNotContainsString('Höhenmeter', $event->description);
    }

    public function testMissingOptionalValuesAreOmitted(): void
    {
        $activity = self::activity();
        unset($activity['averageHR'], $activity['maxHR'], $activity['elevationGain'], $activity['activityName']);

        $event = self::mapper()->map($activity);

        self::assertStringNotContainsString('Herzfrequenz', $event->description);
        self::assertStringNotContainsString('Höhenmeter', $event->description);
        self::assertStringStartsWith('Dauer:', $event->description);
    }

    public function testOnlyAverageHeartRate(): void
    {
        $event = self::mapper()->map(self::activity(['maxHR' => null]));

        self::assertStringContainsString("Herzfrequenz: Ø 148 bpm\n", $event->description);
    }

    public function testUnknownTypeUsesActivityName(): void
    {
        $event = self::mapper()->map(self::activity(['activityType' => ['typeKey' => 'paragliding'], 'activityName' => 'Flug über die Alpen']));

        self::assertSame('🏅 Flug über die Alpen – 7,6 km', $event->summary);
    }

    public function testUnknownTypeWithoutNameUsesTypeKey(): void
    {
        $event = self::mapper()->map(self::activity(['activityType' => ['typeKey' => 'paragliding'], 'activityName' => '', 'distance' => null]));

        self::assertSame('🏅 paragliding', $event->summary);
    }

    /** @return iterable<string, array{string, string}> */
    public static function typeTitles(): iterable
    {
        yield 'trail_running' => ['trail_running', '🏃 Trailrunning'];
        yield 'treadmill_running' => ['treadmill_running', '🏃 Laufband'];
        yield 'cycling' => ['cycling', '🚴 Radfahren'];
        yield 'mountain_biking' => ['mountain_biking', '🚵 Mountainbike'];
        yield 'indoor_cycling' => ['indoor_cycling', '🚴 Indoor-Radfahren'];
        yield 'walking' => ['walking', '🚶 Gehen'];
        yield 'hiking' => ['hiking', '🥾 Wandern'];
        yield 'open_water_swimming' => ['open_water_swimming', '🏊 Freiwasserschwimmen'];
        yield 'yoga' => ['yoga', '🧘 Yoga'];
        yield 'indoor_cardio' => ['indoor_cardio', '💪 Cardio'];
    }

    #[DataProvider('typeTitles')]
    public function testTypeTitles(string $typeKey, string $title): void
    {
        $event = self::mapper()->map(self::activity(['activityType' => ['typeKey' => $typeKey], 'distance' => null]));

        self::assertSame($title, $event->summary);
    }

    public function testCategoriesContainGarminAndType(): void
    {
        self::assertSame(['Garmin', 'Laufen'], self::mapper()->map(self::activity())->categories);
    }

    /** @return iterable<string, array{string}> */
    public static function requiredFields(): iterable
    {
        yield 'activityId' => ['activityId'];
        yield 'startTimeGMT' => ['startTimeGMT'];
    }

    #[DataProvider('requiredFields')]
    public function testMissingRequiredFieldThrows(string $field): void
    {
        $activity = self::activity();
        unset($activity[$field]);

        $this->expectException(\InvalidArgumentException::class);

        self::mapper()->map($activity);
    }

    public function testInvalidStartTimeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::mapper()->map(self::activity(['startTimeGMT' => 'gestern']));
    }

    public function testEnglishTextsAreDefault(): void
    {
        $event = (new ActivityEventMapper())->map(self::activity());

        self::assertSame('🏃 Running – 7.6 km', $event->summary);
        self::assertStringContainsString('Duration: ', $event->description);
    }
}
