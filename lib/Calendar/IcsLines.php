<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Calendar;

/** Unfolding and folding of iCalendar lines per RFC 5545, UTF-8 safe. */
final class IcsLines
{
    private const MAX_LINE_OCTETS = 75;

    private function __construct()
    {
    }

    /**
     * Logical lines of an ICS text with CRLF or LF line endings, continuation lines joined.
     *
     * @return list<string>
     */
    public static function unfold(string $ics): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $ics);
        $text = preg_replace('/\n[ \t]/', '', $text) ?? $text;

        return array_values(array_filter(explode("\n", $text), static fn (string $line): bool => $line !== ''));
    }

    /** @param list<string> $lines */
    public static function join(array $lines): string
    {
        return implode('', array_map(static fn (string $line): string => self::fold($line) . "\r\n", $lines));
    }

    /** Folds after 75 octets without splitting a UTF-8 character. */
    public static function fold(string $line): string
    {
        if (strlen($line) <= self::MAX_LINE_OCTETS) {
            return $line;
        }
        $parts = [];
        $current = '';
        // Continuation lines start with a space, which counts.
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

    /** Name of a property in upper case, without parameters, e.g. "SUMMARY" for "SUMMARY;LANGUAGE=de:…". */
    public static function name(string $line): string
    {
        return strtoupper((string)preg_replace('/[;:].*$/s', '', $line));
    }

    /** Value of a property (everything after the first colon). */
    public static function value(string $line): string
    {
        $pos = strpos($line, ':');

        return $pos === false ? '' : substr($line, $pos + 1);
    }
}
