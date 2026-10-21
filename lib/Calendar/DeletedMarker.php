<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Calendar;

/** Marks a stored event as "deleted in Garmin" (F-13, F-34). Applying it repeatedly changes nothing. */
final class DeletedMarker
{
    public const SUMMARY_SUFFIX = ' (in Garmin gelöscht)';

    /** Properties that are set anew when marking. */
    private const REPLACED = ['STATUS', 'X-GARMIN-DELETED'];

    public function apply(string $ics, \DateTimeImmutable $now): string
    {
        $suffix = IcsSerializer::escape(self::SUMMARY_SUFFIX);
        $result = [];
        $inEvent = false;

        foreach (IcsLines::unfold($ics) as $line) {
            $name = IcsLines::name($line);
            $isPropertyLine = str_contains($line, ':');

            if ($line === 'BEGIN:VEVENT') {
                $inEvent = true;
            } elseif ($line === 'END:VEVENT' && $inEvent) {
                $result[] = 'STATUS:CANCELLED';
                $result[] = 'X-GARMIN-DELETED:TRUE';
                $inEvent = false;
            } elseif ($inEvent && $isPropertyLine && in_array($name, self::REPLACED, true)) {
                continue;
            } elseif ($inEvent && $isPropertyLine && $name === 'DTSTAMP') {
                $line = 'DTSTAMP:' . IcsSerializer::utc($now);
            } elseif ($inEvent && $isPropertyLine && $name === 'SUMMARY' && !str_ends_with($line, $suffix)) {
                $line .= $suffix;
            }

            $result[] = $line;
        }

        return IcsLines::join($result);
    }
}
