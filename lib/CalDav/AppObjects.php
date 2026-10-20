<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\CalDav;

use OCA\WorkoutCalendar\Calendar\IcsLines;

/**
 * Erkennt Kalenderobjekte der App: URI `garmin-<activityId>.ics` und UID `garmin-<activityId>`.
 * Alle anderen Objekte bleiben unangetastet.
 */
final class AppObjects
{
    private function __construct()
    {
    }

    public static function uri(string $activityId): string
    {
        return "garmin-$activityId.ics";
    }

    /** Activity-ID aus einer URI der App, sonst null. */
    public static function activityId(string $uri): ?string
    {
        return preg_match('/^garmin-(\d+)\.ics$/', $uri, $m) === 1 ? $m[1] : null;
    }

    /** Prüft zusätzlich die UID, denn URI und UID eines Objekts sind voneinander unabhängig. */
    public static function isAppObject(string $uri, string $ics): bool
    {
        $id = self::activityId($uri);

        return $id !== null && self::uid($ics) === "garmin-$id";
    }

    private static function uid(string $ics): ?string
    {
        foreach (IcsLines::unfold($ics) as $line) {
            if (IcsLines::name($line) === 'UID') {
                return IcsLines::value($line);
            }
        }

        return null;
    }
}
