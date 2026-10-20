<?php

declare(strict_types=1);

namespace OCA\GarminCalendar\Calendar;

/** Erzeugt ein iCalendar-Objekt (RFC 5545) mit genau einem VEVENT. */
final class IcsSerializer
{
    private const PRODID = '-//HoMS1987//garmincalendar//DE';
    private const MAX_LINE_OCTETS = 75;

    public function serialize(CalendarEvent $event, \DateTimeImmutable $now): string
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
            'URL:' . $event->url,
            'CATEGORIES:' . implode(',', array_map(self::escape(...), $event->categories)),
            'X-GARMIN-ACTIVITY-ID:' . self::escape($event->activityId),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode('', array_map(static fn (string $line): string => self::fold($line) . "\r\n", $lines));
    }

    private static function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    private static function escape(string $text): string
    {
        $text = str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $text);

        return str_replace(["\r\n", "\r", "\n"], '\\n', $text);
    }

    /** Faltet nach 75 Oktetten, ohne ein UTF-8-Zeichen zu zerschneiden. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= self::MAX_LINE_OCTETS) {
            return $line;
        }
        $parts = [];
        $current = '';
        // Fortsetzungszeilen beginnen mit einem Leerzeichen, das zählt mit.
        $limit = self::MAX_LINE_OCTETS;
        foreach (mb_str_split($line, 1, 'UTF-8') as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $parts[] = $current;
                $current = '';
                $limit = self::MAX_LINE_OCTETS - 1;
            }
            $current .= $char;
        }
        $parts[] = $current;

        return implode("\r\n ", $parts);
    }
}
