<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Calendar;

/** Translatable texts and number format of the calendar events. Source texts are English. */
interface EventTexts
{
    /**
     * Translates a source text and replaces the %s placeholders in order.
     *
     * @param list<string> $parameters
     */
    public function translate(string $text, array $parameters = []): string;

    /** Formats a number with the language's separators. */
    public function number(float $value, int $decimals): string;
}
