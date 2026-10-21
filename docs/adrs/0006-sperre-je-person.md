# 6. Sperre je Person für Sync-Lauf und Änderungen

Datum: 2026-10-21

## Status

Accepted

## Kontext

Ein Sync-Lauf liest `SyncState` und `SetupState` zu Beginn, arbeitet mehrere Sekunden bis Minuten
mit Garmin und dem Kalender und speichert den Zustand am Ende. Gleichzeitig kann die Person über die
Einstellungsseite den Zustand ändern: anmelden, abmelden, zurücksetzen, den Kalender wechseln, die
Historie wählen, Einstellungen ändern oder sofort synchronisieren. Dazu kommt der
`CalendarMaintenanceJob`, der beim Abschluss die Markierung `maintenance` löscht.

Ohne Abstimmung zwischen diesen Wegen gehen Änderungen verloren:

- Ein Lauf speichert am Ende seinen alten Zustand und überschreibt eine Abmeldung, einen
  Kalenderwechsel oder neue Zugangsdaten, die währenddessen gespeichert wurden.
- Ein manueller Sync liest den Zustand, um die Person in die Warteschlange zu stellen. Ändert ein
  anderer Vorgang den Zustand, bevor der manuelle Sync den gelesenen Zustand wieder speichert,
  überschreibt dieses Speichern die Änderung.
- Eine Anmeldung prüft die Zugangsdaten bei Garmin, während ein Lauf mit den alten Zugangsdaten
  Tokens erneuert, und beide speichern nacheinander.
- Der Wartungsjob schließt ab, während eine Abmeldung die Einrichtung speichert, und eine der beiden
  Änderungen an `SetupState` geht verloren.

## Entscheidung

- `PersonLock` hält je Person eine exklusive Sperre über `ILockingProvider` mit dem Schlüssel
  `workoutcalendar/sync/<md5(uid)>`. Der Hash hält den Schlüssel unter den 64 Zeichen der Spalte
  `oc_file_locks.key`.
- Unter der Sperre laufen der Sync-Lauf, alle Änderungen über die JSON-API (Anmeldung einschließlich
  der Prüfung bei Garmin, Abmeldung, Zurücksetzen, Kalenderwahl, Historie, Einstellungen) und der
  Abschluss des `CalendarMaintenanceJob`.
- Es wird nicht gewartet. Ist die Sperre belegt, antwortet die API mit 409 `{"error": "busy"}`, der
  Hintergrundjob überspringt die Person (`skipped`), und der Wartungsjob reiht sich mit
  unverändertem Argument neu ein, ohne dass das als Fehlversuch zählt. Hat er bereits aufgegeben,
  bleibt das endgültig: Der neue Lauf führt nur noch den Abschluss aus.
- Ein sofortiger Lauf (`POST /api/sync`, Abschluss der Historie) nutzt `SyncRunner::runNow`: Einreihen
  und Lauf geschehen in derselben Sperre.
- Die Sperre ist nicht wiedereintrittsfähig. Wer sie hält, ruft `PersonLock::run` für dieselbe Person
  nicht erneut auf. Wege, die nach einer Änderung einen Lauf starten, geben die Sperre vorher frei.

## Konsequenzen

- Zwischen dem Lesen und dem Speichern eines Vorgangs ändert kein anderer Vorgang den Zustand der
  Person. Verlorene Änderungen durch parallele Läufe sind ausgeschlossen.
- Während eines Sync-Laufs lehnt die API Änderungen mit 409 ab. Die Einstellungsseite ändert dann
  ihre Ansicht nicht, die Aktion lässt sich nach dem Lauf wiederholen. Eine Anmeldung wiederholt die
  Seite nie automatisch.
- Die Anmeldung hält die Sperre für die Dauer der Garmin-Anfragen. In dieser Zeit überspringt der
  Hintergrundjob die Person.
- Fehlende Wiedereintrittsfähigkeit verlangt Disziplin im Code: ein verschachtelter Aufruf endet mit
  `PersonBusyException`. Die Unit-Tests nutzen deshalb eine Speicher-Sperre, die sich genauso verhält.
- Die Sperre hängt am Locking-Backend von Nextcloud (Datenbank, Redis oder Memcache). Sperren, die ein
  abgebrochener Prozess hinterlässt, laufen über dessen TTL ab.
