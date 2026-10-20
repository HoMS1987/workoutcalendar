<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

use OCA\WorkoutCalendar\Calendar\EventTexts;
use OCP\IL10N;

/** Termintexte in der Sprache der Person über die Übersetzungen der App. */
final class L10nEventTexts implements EventTexts
{
    public function __construct(private readonly IL10N $l)
    {
    }

    public function translate(string $text, array $parameters = []): string
    {
        return $this->l->t($text, $parameters);
    }

    public function number(float $value, int $decimals): string
    {
        return str_starts_with($this->l->getLanguageCode(), 'de')
            ? number_format($value, $decimals, ',', '.')
            : number_format($value, $decimals, '.', ',');
    }
}
