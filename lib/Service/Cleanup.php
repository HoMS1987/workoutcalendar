<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

/** Was beim Abmelden oder Zurücksetzen mit den Terminen der App geschieht. */
enum Cleanup: string
{
    /** Termine und Kalender bleiben. */
    case None = 'none';
    /** Die Termine der App werden aus dem Kalender entfernt. */
    case Events = 'events';
    /** Der von der App angelegte Kalender wird gelöscht. */
    case Calendar = 'calendar';
}
