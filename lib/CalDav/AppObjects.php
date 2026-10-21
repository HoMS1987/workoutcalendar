<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\CalDav;

use OCA\WorkoutCalendar\Calendar\IcsLines;

/**
 * Recognizes the app's calendar objects: URI `garmin-<activityId>.ics` and UID `garmin-<activityId>`.
 * All other objects are left untouched.
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

    /** Activity ID from an app URI, otherwise null. */
    public static function activityId(string $uri): ?string
    {
        return preg_match('/^garmin-(\d+)\.ics$/', $uri, $m) === 1 ? $m[1] : null;
    }

    /** Also checks the UID, because the URI and UID of an object are independent of each other. */
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
