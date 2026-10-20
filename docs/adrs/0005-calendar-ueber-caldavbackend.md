# 5. Kalender und Termine über `CalDavBackend`

Datum: 2026-10-20

## Status

Accepted

Präzisiert [ADR 0003](0003-klassische-php-nextcloud-app.md) („interne Kalender-API von Nextcloud“).

## Kontext

ADR 0003 sieht vor, Termine über die interne Kalender-API von Nextcloud zu schreiben statt über
CalDAV. Die öffentliche Schnittstelle `OCP\Calendar` reicht dafür in Nextcloud 33 nicht aus: Sie
kann weder Kalender anlegen noch Termine oder Kalender löschen. Die App muss aber einen eigenen
Kalender anlegen, Termine löschen (Löschmodus „Löschen“), Termine zwischen Kalendern verschieben und
einen Kalender beim Abmelden entfernen können.

## Entscheidung

- Kalender und Termine laufen über `OCA\DAV\CalDAV\CalDavBackend` der DAV-App, so wie es auch
  `integration_google` tut (`lib/Service/GoogleCalendarAPIService.php`, geprüft am 2026-10-20).
- Das Backend steckt hinter der eigenen Schnittstelle `CalendarObjects` (`lib/CalDav`). Die einzige
  Implementierung mit Nextcloud-Bezug ist `DavCalendarObjects`, nur sie verwendet `OCA\DAV` und
  Sabre. `DavCalendarTarget` (Zielkalender für den Sync) und `CalendarService` (Kalenderverwaltung)
  arbeiten nur gegen `CalendarObjects` und sind mit einer Speicher-Implementierung unit-testbar.
- Eigene Kalender sind die mit Eigentümer `principals/users/<uid>`, nicht im Papierkorb und mit
  VEVENT. Mit der Person geteilte Kalender und der Geburtstagskalender zählen nicht dazu.
- Neue Kalender unterstützen nur VEVENT. Kalender und Termine werden in den Papierkorb verschoben,
  nicht endgültig gelöscht. Liegt derselbe Termin schon im Papierkorb, wird er endgültig gelöscht.
- Die App ändert, verschiebt oder löscht nur Objekte mit URI `garmin-<activityId>.ics` und UID
  `garmin-<activityId>`. Der Abgleich im Löschzeitraum berücksichtigt zusätzlich nur Termine mit
  der Konto-Kennung `X-GARMIN-ACCOUNT` des eigenen Garmin-Kontos.

## Konsequenzen

- Alle nötigen Operationen sind ohne App-Passwort und ohne CalDAV-Anfragen über HTTP möglich.
- `CalDavBackend` ist keine stabile Schnittstelle und kann sich mit jeder Nextcloud-Version ändern.
  Die Integrationstests gegen Nextcloud 33 bis 35 fangen solche Änderungen ab.
- Ändert sich das Backend, ist nur `DavCalendarObjects` betroffen. Der Rest der App bleibt
  unverändert.
- Die Abfrage nach Zeitraum liefert alle Termine, die den Zeitraum berühren. Den Beginn prüft
  `DavCalendarTarget` selbst.
