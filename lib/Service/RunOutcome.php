<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

/** Ergebnis eines Sync-Laufs für eine Person. */
enum RunOutcome: string
{
    /** Garmin wurde abgefragt, der Zustand ist gespeichert (auch bei Fehlern von Garmin). */
    case Done = 'done';
    /** Ein anderer Lauf für die Person hält die Sperre. */
    case Skipped = 'skipped';
    /** Angehalten nach abgelehntem Passwort oder Verifizierungscode, bis neue Zugangsdaten vorliegen. */
    case Paused = 'paused';
    case NotConnected = 'notConnected';
    /** Der Zielkalender fehlt oder ist nicht mehr eigener Kalender der Person. */
    case CalendarMissing = 'calendarMissing';
    /** Die Einrichtung ist noch nicht abgeschlossen, die Person hat den Zeitraum der Historie noch nicht gewählt. */
    case NotReady = 'notReady';
}
