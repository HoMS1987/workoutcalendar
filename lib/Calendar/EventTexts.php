<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Calendar;

/** Übersetzbare Texte und Zahlenformat der Kalendertermine. Quelltexte sind Englisch. */
interface EventTexts
{
    /**
     * Übersetzt einen Quelltext und ersetzt die %s-Platzhalter der Reihe nach.
     *
     * @param list<string> $parameters
     */
    public function translate(string $text, array $parameters = []): string;

    /** Formatiert eine Zahl mit den Trennzeichen der Sprache. */
    public function number(float $value, int $decimals): string;
}
