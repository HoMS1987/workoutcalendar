<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Calendar;

/** Die Quelltexte selbst, Zahlen mit Punkt als Dezimal- und Komma als Tausendertrennzeichen. */
final class EnglishEventTexts implements EventTexts
{
    public function translate(string $text, array $parameters = []): string
    {
        return self::fill($text, $parameters);
    }

    public function number(float $value, int $decimals): string
    {
        return number_format($value, $decimals, '.', ',');
    }

    /** @param list<string> $parameters */
    public static function fill(string $text, array $parameters): string
    {
        $parameters = array_values($parameters);
        $i = 0;

        return preg_replace_callback('/%s/', static function () use ($parameters, &$i): string {
            return (string)($parameters[$i++] ?? '');
        }, $text) ?? $text;
    }
}
