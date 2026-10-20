<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Calendar;

/** Bildet eine Aktivität aus der Garmin-Aktivitätsliste auf einen Kalendertermin ab. */
final class ActivityEventMapper
{
    private const ACTIVITY_URL = 'https://connect.garmin.com/modern/activity/';

    private const PACE_PER_KM = 'pace_km';
    private const PACE_PER_100M = 'pace_100m';
    private const SPEED = 'speed';

    /**
     * typeKey => [Emoji, Name, Metrik]. Die typeKeys stammen aus der Garmin-Aktivitätsliste,
     * die Liste ist nicht vollständig. Unbekannte Typen fallen auf den Aktivitätsnamen zurück.
     */
    private const TYPES = [
        'running' => ['🏃', 'Laufen', self::PACE_PER_KM],
        'trail_running' => ['🏃', 'Trailrunning', self::PACE_PER_KM],
        'treadmill_running' => ['🏃', 'Laufband', self::PACE_PER_KM],
        'track_running' => ['🏃', 'Bahnlauf', self::PACE_PER_KM],
        'indoor_running' => ['🏃', 'Indoor-Laufen', self::PACE_PER_KM],
        'walking' => ['🚶', 'Gehen', self::PACE_PER_KM],
        'hiking' => ['🥾', 'Wandern', self::PACE_PER_KM],
        'cycling' => ['🚴', 'Radfahren', self::SPEED],
        'road_biking' => ['🚴', 'Rennrad', self::SPEED],
        'gravel_cycling' => ['🚴', 'Gravel', self::SPEED],
        'mountain_biking' => ['🚵', 'Mountainbike', self::SPEED],
        'indoor_cycling' => ['🚴', 'Indoor-Radfahren', self::SPEED],
        'virtual_ride' => ['🚴', 'Virtuelle Radfahrt', self::SPEED],
        'e_bike_fitness' => ['🚴', 'E-Bike', self::SPEED],
        'e_bike_mountain' => ['🚵', 'E-Mountainbike', self::SPEED],
        'lap_swimming' => ['🏊', 'Schwimmen', self::PACE_PER_100M],
        'open_water_swimming' => ['🏊', 'Freiwasserschwimmen', self::PACE_PER_100M],
        'strength_training' => ['🏋️', 'Krafttraining', null],
        'yoga' => ['🧘', 'Yoga', null],
        'pilates' => ['🧘', 'Pilates', null],
        'indoor_cardio' => ['💪', 'Cardio', null],
        'elliptical' => ['💪', 'Crosstrainer', null],
        'indoor_rowing' => ['🚣', 'Rudergerät', null],
        'rowing' => ['🚣', 'Rudern', self::SPEED],
    ];

    /** @param array<string, mixed> $activity */
    public function map(array $activity): CalendarEvent
    {
        $id = $activity['activityId'] ?? null;
        if (!is_int($id) && !(is_string($id) && $id !== '')) {
            throw new \InvalidArgumentException('Aktivität ohne activityId');
        }
        $id = (string)$id;
        $start = $this->parseStart($activity['startTimeGMT'] ?? null, $id);

        $typeKey = (string)($activity['activityType']['typeKey'] ?? '');
        $name = trim((string)($activity['activityName'] ?? ''));
        [$emoji, $typeName, $metric] = self::TYPES[$typeKey] ?? ['🏅', null, null];

        $duration = self::positive($activity['duration'] ?? null);
        $distance = self::positive($activity['distance'] ?? null);
        $swim = $metric === self::PACE_PER_100M;

        $summary = $emoji . ' ' . ($typeName ?? ($name !== '' ? $name : $typeKey));
        if ($distance !== null) {
            $summary .= ' – ' . self::formatDistance($distance, $swim, 1);
        }

        $url = self::ACTIVITY_URL . $id;
        $lines = [];
        if ($name !== '') {
            $lines[] = $name;
        }
        if ($duration !== null) {
            $lines[] = 'Dauer: ' . self::formatDuration($duration);
        }
        if ($distance !== null) {
            $lines[] = 'Distanz: ' . self::formatDistance($distance, $swim, 2);
        }
        $speed = self::positive($activity['averageSpeed'] ?? null)
            ?? ($distance !== null && $duration !== null ? $distance / $duration : null);
        if ($speed !== null && $distance !== null && $metric !== null) {
            $lines[] = match ($metric) {
                self::PACE_PER_KM => 'Pace: ' . self::formatPace(1000 / $speed) . ' min/km',
                self::PACE_PER_100M => 'Pace: ' . self::formatPace(100 / $speed) . ' min/100 m',
                self::SPEED => 'Geschwindigkeit: Ø ' . number_format($speed * 3.6, 1, ',', '.') . ' km/h',
            };
        }
        $avgHr = self::positive($activity['averageHR'] ?? null);
        if ($avgHr !== null) {
            $maxHr = self::positive($activity['maxHR'] ?? null);
            $lines[] = 'Herzfrequenz: Ø ' . round($avgHr) . ' bpm' . ($maxHr !== null ? ', max ' . round($maxHr) . ' bpm' : '');
        }
        $elevation = self::positive($activity['elevationGain'] ?? null);
        if ($elevation !== null) {
            $lines[] = 'Höhenmeter: ↑ ' . number_format($elevation, 0, ',', '.') . ' m';
        }
        $lines[] = 'Garmin Connect: ' . $url;

        $end = $start->modify('+' . (int)round($duration ?? 0) . ' seconds');

        return new CalendarEvent(
            'garmin-' . $id,
            $summary,
            implode("\n", $lines),
            $start,
            $end,
            $url,
            $id,
            $typeName !== null ? ['Garmin', $typeName] : ['Garmin'],
        );
    }

    private function parseStart(mixed $value, string $id): \DateTimeImmutable
    {
        $start = is_string($value)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'))
            : false;
        if ($start === false) {
            throw new \InvalidArgumentException("Aktivität $id ohne gültige startTimeGMT");
        }

        return $start;
    }

    private static function positive(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && $value > 0 ? (float)$value : null;
    }

    private static function formatDistance(float $meters, bool $inMeters, int $decimals): string
    {
        return $inMeters
            ? number_format($meters, 0, ',', '.') . ' m'
            : number_format($meters / 1000, $decimals, ',', '.') . ' km';
    }

    private static function formatDuration(float $seconds): string
    {
        $total = (int)round($seconds);
        $h = intdiv($total, 3600);
        $m = intdiv($total % 3600, 60);
        $s = $total % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d h', $h, $m, $s) : sprintf('%d:%02d min', $m, $s);
    }

    private static function formatPace(float $secondsPerUnit): string
    {
        $total = (int)round($secondsPerUnit);

        return sprintf('%d:%02d', intdiv($total, 60), $total % 60);
    }
}
