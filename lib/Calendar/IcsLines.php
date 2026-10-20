<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Calendar;

/** Entfalten und Falten von iCalendar-Zeilen nach RFC 5545, UTF-8-sicher. */
final class IcsLines
{
    private const MAX_LINE_OCTETS = 75;

    private function __construct()
    {
    }

    /**
     * Logische Zeilen eines ICS-Texts mit CRLF- oder LF-Zeilenenden, Fortsetzungszeilen zusammengefügt.
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

    /** Faltet nach 75 Oktetten, ohne ein UTF-8-Zeichen zu zerschneiden. */
    public static function fold(string $line): string
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

    /** Name einer Eigenschaft in Großbuchstaben, ohne Parameter, z. B. "SUMMARY" für "SUMMARY;LANGUAGE=de:…". */
    public static function name(string $line): string
    {
        return strtoupper((string)preg_replace('/[;:].*$/s', '', $line));
    }

    /** Wert einer Eigenschaft (alles nach dem ersten Doppelpunkt). */
    public static function value(string $line): string
    {
        $pos = strpos($line, ':');

        return $pos === false ? '' : substr($line, $pos + 1);
    }
}
