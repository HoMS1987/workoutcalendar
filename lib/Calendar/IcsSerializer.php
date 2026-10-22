<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Calendar;

/** Produces an iCalendar object (RFC 5545) with exactly one VEVENT. */
final class IcsSerializer
{
    private const PRODID = '-//HoMS1987//workoutcalendar//DE';

    public function serialize(CalendarEvent $event, \DateTimeImmutable $now, ?string $accountKey = null): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . self::PRODID,
            'BEGIN:VEVENT',
            'UID:' . self::escape($event->uid),
            'DTSTAMP:' . self::utc($now),
            'DTSTART:' . self::utc($event->startUtc),
            'DTEND:' . self::utc($event->endUtc),
            'SUMMARY:' . self::escape($event->summary),
            'DESCRIPTION:' . self::escape($event->description),
            // URL has the value type URI, not TEXT, so it is not escaped.
            'URL:' . $event->url,
            'CATEGORIES:' . implode(',', array_map(self::escape(...), $event->categories)),
            'X-GARMIN-ACTIVITY-ID:' . self::escape($event->activityId),
            'X-GARMIN-FINGERPRINT:' . $event->fingerprint(),
            ...($accountKey === null ? [] : ['X-GARMIN-ACCOUNT:' . self::escape($accountKey)]),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return IcsLines::join($lines);
    }

    public static function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /** Escapes a TEXT value (RFC 5545, 3.3.11). */
    public static function escape(string $text): string
    {
        $text = str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $text);

        return str_replace(["\r\n", "\r", "\n"], '\\n', $text);
    }
}
