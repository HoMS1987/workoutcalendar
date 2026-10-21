<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Support;

use OCA\WorkoutCalendar\Calendar\EnglishEventTexts;
use OCA\WorkoutCalendar\Calendar\EventTexts;

/** German texts for the tests, English to German table as in l10n/de.json. */
final class GermanEventTexts implements EventTexts
{
    private const TABLE = [
        'Running' => 'Laufen',
        'Trail running' => 'Trailrunning',
        'Treadmill running' => 'Laufband',
        'Track running' => 'Bahnlauf',
        'Indoor running' => 'Indoor-Laufen',
        'Walking' => 'Gehen',
        'Hiking' => 'Wandern',
        'Cycling' => 'Radfahren',
        'Road cycling' => 'Rennrad',
        'Gravel cycling' => 'Gravel',
        'Mountain biking' => 'Mountainbike',
        'Indoor cycling' => 'Indoor-Radfahren',
        'Virtual ride' => 'Virtuelle Radfahrt',
        'E-bike' => 'E-Bike',
        'E-mountain bike' => 'E-Mountainbike',
        'Swimming' => 'Schwimmen',
        'Open water swimming' => 'Freiwasserschwimmen',
        'Strength training' => 'Krafttraining',
        'Yoga' => 'Yoga',
        'Pilates' => 'Pilates',
        'Cardio' => 'Cardio',
        'Elliptical' => 'Crosstrainer',
        'Indoor rowing' => 'Rudergerät',
        'Rowing' => 'Rudern',
        'Duration: %s' => 'Dauer: %s',
        'Distance: %s' => 'Distanz: %s',
        'Pace: %s min/km' => 'Pace: %s min/km',
        'Pace: %s min/100 m' => 'Pace: %s min/100 m',
        'Speed: avg %s km/h' => 'Geschwindigkeit: Ø %s km/h',
        'Heart rate: avg %s bpm' => 'Herzfrequenz: Ø %s bpm',
        ', max %s bpm' => ', max %s bpm',
        'Elevation gain: ↑ %s m' => 'Höhenmeter: ↑ %s m',
    ];

    public function translate(string $text, array $parameters = []): string
    {
        return EnglishEventTexts::fill(self::TABLE[$text] ?? $text, $parameters);
    }

    public function number(float $value, int $decimals): string
    {
        return number_format($value, $decimals, ',', '.');
    }
}
