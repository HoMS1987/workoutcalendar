# Entwurf: Nextcloud-Anbindung

Stand: 2026-10-20. Grundlage sind [anforderungen.md](../anforderungen.md),
[architecture.md](../architecture.md) und die ADRs 0003 und 0004. Die Entwürfe der Oberfläche liegen
als private Seite vor (Einrichtung und Einstellungsseite), dieses Dokument beschreibt sie vollständig
in Textform.

## Ziel und Hintergrund

Der Kern der App ist fertig und ohne Nextcloud getestet: Garmin-Client, Abbildung auf Termine und
Sync-Ablauf (`lib/Garmin`, `lib/Calendar`, `lib/Sync`). Es fehlt alles, was die App in Nextcloud
installierbar und benutzbar macht. Nach diesem Schritt kann eine Person der Instanz die App aus dem
App Store installieren, sich in den persönlichen Einstellungen mit Garmin verbinden, einen Kalender
wählen, die Historie importieren lassen, und danach erscheinen neue Aktivitäten ohne weiteres Zutun
im Kalender.

Der Test aus dem Cluster (O-07) hat gezeigt, dass Garmin-Login und Datenabruf aus dem Nextcloud-Pod
funktionieren. Damit steht der Weg aus ADR 0003 nicht mehr in Frage.

## Ziele

- Geführte Einrichtung beim ersten Öffnen, danach eine übersichtliche Einstellungsseite.
- Automatischer Sync im Hintergrund für jede Person mit eigenem Garmin-Konto (F-05, F-10).
- Verständliche Rückmeldung bei Problemen, auch ohne dass jemand die Einstellungen öffnet.
- Oberfläche und Termintexte auf Englisch und Deutsch (NF-07).

## Nicht-Ziele

- Garmin-Konten mit Two-Step Verification (F-07 bleibt vorgemerkt). Die Oberfläche meldet den Fall,
  bietet aber noch kein Feld für den Code.
- Einstellungen für Admins. Jede Person verwaltet nur ihr eigenes Konto.
- Weitere Datenquellen wie Strava.
- Hinweise zu bestimmten Kalender-Clients (z. B. Outlook). Die App ist für alle Nextcloud-Instanzen
  gedacht.

## Was das Ergebnis unbrauchbar machen würde

- Eigene Termine in einem vorhandenen Kalender werden verändert oder gelöscht.
- Ein Löschen ohne ausdrückliche Bestätigung.
- Passwort, Tokens oder Tickets erscheinen im Log, in Fehlermeldungen, in der Oberfläche oder in
  Benachrichtigungen (NF-01).
- Garmin wird unnötig oft angefragt, etwa durch Login-Versuche nach abgelehntem Passwort (NF-03).
- Ein Import blockiert den Nextcloud-Cron oder eine Seite lädt minutenlang.

## Rahmenbedingungen

- Nextcloud 33 bis 35, PHP ab 8.2 (NF-05). Die eigene Instanz läuft mit Nextcloud 35.0.2 und PHP 8.5.
- Klassische PHP-App ohne AppAPI (ADR 0003), App-ID `workoutcalendar`, Namespace
  `OCA\WorkoutCalendar`.
- Der Kern bleibt frei von Nextcloud-Klassen (NF-04). Die Nextcloud-Anbindung implementiert seine
  Schnittstellen (`HttpClient`, `TokenStore`, `CalendarTarget`) und ruft `SyncService` auf.
- Neue Funktionen entstehen testgetrieben, Bugfixes mit 5-Why-Analyse (NF-06).
- Auf dem Entwicklungsrechner laufen keine Dienste und nichts startet automatisch (R-07).

## Oberfläche

Die App hat einen eigenen Bereich in den persönlichen Einstellungen („Workout Calendar“). Solange die
Einrichtung nicht abgeschlossen ist, zeigt der Bereich einen Assistenten mit vier Schritten, danach die
Einstellungsseite. Alle Schritte haben einen kurzen erklärenden Text.

### Schritt 1: Garmin

- Felder für E-Mail-Adresse und Passwort, Knopf „Anmelden und weiter“.
- Während der Anmeldung ist der Knopf gesperrt, die Seite zeigt „Anmeldung bei Garmin läuft …“. Die
  Anmeldung dauert einige Sekunden, weil der Client wie die Garmin-App Pausen einlegt.
- Bei Erfolg geht es direkt zu Schritt 2.
- Bei Misserfolg bleibt die Seite in Schritt 1 und zeigt eine Meldung je Fehlerkategorie (ADR 0004).
  Einen automatischen zweiten Versuch gibt es nicht.

| Kategorie | Meldung (sinngemäß) |
|---|---|
| `INVALID_CREDENTIALS` | Garmin hat E-Mail oder Passwort abgelehnt. Bitte prüfen und erneut versuchen. |
| `MFA_REQUIRED` | Dein Garmin-Konto verlangt einen Bestätigungscode. Das wird noch nicht unterstützt. |
| `BLOCKED` | Garmin blockiert die Anmeldung gerade. Bitte später erneut versuchen. |
| `PROTOCOL_CHANGED` | Die Anmeldung bei Garmin hat sich offenbar geändert. Details stehen im Nextcloud-Log. |
| `SERVER_ERROR`, `NETWORK` | Garmin ist gerade nicht erreichbar. Bitte später erneut versuchen. |

### Schritt 2: Kalender

- Einleitung: Jede Aktivität wird ein Termin mit Sportart, Dauer und Distanz. Am einfachsten ist ein
  eigener Kalender, den man getrennt von den anderen ein- und ausblenden kann.
- Ein Auswahlfeld. Vorausgewählt ist „Neuer Kalender ‚Garmin-Aktivitäten‘“. Der Name lässt sich
  direkt im Feld überschreiben. Darunter, als eigene Gruppe, die vorhandenen beschreibbaren Kalender
  der Person.
- Neuer Kalender: „Weiter“ legt ihn ohne Nachfrage an.
- Vorhandener Kalender: Nachfrage mit der Zahl der Termine, die schon darin stehen, dem Hinweis, dass
  eigene Termine unverändert bleiben, und dem Hinweis, dass die App beim Abmelden nur ihre eigenen
  Termine entfernen kann, nicht den Kalender. Knöpfe „Anderen wählen“ und „‚Name‘ verwenden“.
- Gibt es bereits einen Kalender mit dem gewählten Namen (auch „Garmin-Aktivitäten“), gilt er als
  vorhandener Kalender und die Nachfrage erscheint. Es entstehen keine zwei Kalender mit gleichem
  Namen.
- Die Farbe des neuen Kalenders legt Nextcloud fest, sie lässt sich später in der Kalender-App ändern.
- „Zurück“ führt zu Schritt 1, ohne abzumelden.

### Schritt 3: Historie

- Einleitung: Neue Aktivitäten kommen ab jetzt automatisch dazu, hier geht es nur um die bisherigen.
  Den Zeitraum kann man später erweitern, ein kürzerer löscht nichts.
- Vier Auswahlkarten mit je einem Satz zu Zweck und ungefährer Dauer: 30 Tage, 90 Tage (vorausgewählt,
  „Empfohlen“), 1 Jahr, komplette Historie.
- „Import starten“ führt die erste Etappe sofort aus, damit gleich Termine erscheinen. Die weiteren
  Etappen laufen im Hintergrund.
- Während des Imports zeigt der Schritt einen Fortschritt: Zahl der Aktivitäten im Kalender und das
  erreichte Datum („340 Aktivitäten, zurück bis März 2023“), dazu, wann die nächste Etappe kommt.
  „Weiter“ ist jederzeit möglich, der Import läuft weiter.
- Beim ersten Import holt die App bei jedem Cron-Lauf der Instanz eine Etappe (typisch alle
  5 Minuten), nicht nur im normalen Sync-Intervall. Danach gilt wieder das Intervall.

### Schritt 4: Fertig

- Zusammenfassung: Garmin-Konto (maskiert), Kalender, gewählte Historie und ob der Import noch läuft.
- Satz: „Neue Aktivitäten erscheinen etwa alle 30 Minuten im Kalender.“ (mit dem eingestellten
  Intervall).
- Knopf „Zu den Einstellungen“.

### Einstellungsseite

Von oben nach unten:

1. **Überblick:** Verbindung („Verbunden“ mit maskierter E-Mail-Adresse), Zahl der Aktivitäten im
   Kalender und seit wann, letzter und nächster Sync, Knopf „Jetzt synchronisieren“ (F-14). Läuft ein
   Historien-Import, steht hier sein Fortschritt. Gibt es einen aktuellen Fehler, ersetzt eine rote
   oder gelbe Markierung mit verständlichem Text das „Verbunden“ (F-42).
2. **Synchronisation:** Abstand zwischen zwei Abrufen (15 Minuten bis 24 Stunden, F-15) und Zeitraum
   der Historie (F-20, F-21), jeweils mit einem Satz Erklärung.
3. **Kalender:** der aktuelle Kalender mit „Ändern“. Beim Wechsel fragt die Seite, ob die bisherigen
   Termine der App in den neuen Kalender umziehen sollen. Bei „nein“ bleiben sie im alten Kalender,
   werden aber nicht mehr gepflegt. Der neue Kalender wird dann wie bei der Einrichtung befüllt,
   inklusive Historien-Import für den eingestellten Zeitraum.
4. **Erweitert** (eingeklappt): zufällige Streuung (F-16), Umgang mit in Garmin gelöschten
   Aktivitäten und Prüfzeitraum (F-13), letzter Fehler mit Zeitpunkt, Kategorie und Zahl der
   Fehlschläge.
5. **Garmin-Konto** (eingeklappt): „Abmelden“ und „App zurücksetzen“.

### Abmelden

- Abmelden löscht Passwort und Tokens und beendet den Sync. Die Termine bleiben.
- Im selben Dialog werden zwei Optionen angeboten, jeweils erst nach Bestätigung mit der Zahl der
  betroffenen Termine:
  - die Termine der App entfernen,
  - den ganzen Kalender löschen. Nur angeboten, wenn die App den Kalender selbst angelegt hat.
- Bei erneuter Anmeldung geht es mit dem bisherigen Kalender und den bisherigen Einstellungen weiter,
  ohne Assistent.

### Zurücksetzen

- „App zurücksetzen“ geht weiter als Abmelden: Die App löscht alles, was sie für die Person
  gespeichert hat, also Zugangsdaten, Tokens, Einstellungen, Sync-Zustand und die Zuordnung zum
  Kalender. Beim nächsten Öffnen startet der Assistent bei Schritt 1.
- Wie beim Abmelden werden nach Bestätigung zusätzlich angeboten: die Termine der App entfernen oder
  den von der App angelegten Kalender löschen. Ohne diese Wahl bleiben die Termine stehen und werden
  nicht mehr gepflegt.
- Der Bestätigungsdialog sagt ausdrücklich, dass sich das nicht rückgängig machen lässt.

## Benachrichtigungen

Bei Fehlern, die ein Eingreifen der Person brauchen, schickt die App eine Nextcloud-Benachrichtigung
(Glocke oben rechts) mit Link zu den Einstellungen.

- Anlass: `INVALID_CREDENTIALS` und `MFA_REQUIRED`. Bei `BLOCKED`, `SERVER_ERROR` und `NETWORK` erst,
  wenn der Fehler länger als 24 Stunden anhält. `PROTOCOL_CHANGED` ebenfalls nach 24 Stunden, mit dem
  Hinweis, dass ein Update der App nötig sein kann.
- Eine Benachrichtigung je Fehlerfall, keine Wiederholung bei jedem Lauf. Sie verschwindet, sobald der
  Sync wieder klappt oder die Person sich neu anmeldet.
- Der Text enthält keine Geheimnisse und keine Antwortauszüge von Garmin.

Nach abgelehntem Passwort pausiert der Sync, bis die Person das Passwort neu eingibt (NF-03).

## Technische Umsetzung

### Garmin-Zugriff

- `CurlHttpClient` implementiert `HttpClient` über `ext-curl`, mit eigenem Cookie-Speicher je
  Login-Vorgang. Proxy-Einstellungen der Instanz werden vorerst nicht berücksichtigt (ADR 0003).
- `TokenStore` und die Zugangsdaten werden pro Person verschlüsselt gespeichert (NF-02). Zulässig ist
  `ICrypto` oder `IUserConfig` mit `FLAG_SENSITIVE` (ab Nextcloud 32). Entscheidung beim Bau, siehe
  unten.

### Kalender

- Termine schreibt die App über das CalDAV-Backend der Dav-App (`OCA\DAV\CalDAV\CalDavBackend`):
  Kalender anlegen und löschen, Termine anlegen, ändern, lesen und löschen. Die öffentliche API
  `OCP\Calendar` kann in Version 33 zwar Termine anlegen und suchen, aber weder Kalender anlegen noch
  Termine oder Kalender löschen. Nextclouds eigene App `integration_google` nutzt `CalDavBackend`
  ebenfalls (geprüft am 2026-10-20 in `lib/Service/GoogleCalendarAPIService.php`). Diese Abweichung
  von „interne Kalender-API“ in ADR 0003 wird als neue ADR 0005 festgehalten.
- Die Anbindung steckt in einer Klasse hinter `CalendarTarget`. Ändert Nextcloud das Backend, ist nur
  diese Klasse betroffen.
- Termine der App erkennt man an der UID `garmin-<activityId>`. Nur solche Termine werden geändert,
  verschoben oder gelöscht.

### Hintergrundjob

- Ein `TimedJob` läuft bei jedem Cron-Lauf und arbeitet alle Personen ab, deren `nextRunAt` erreicht
  ist. Pro Person läuft ein `SyncService::run`.
- Solange ein Historien-Import läuft, ist die Person bei jedem Cron-Lauf fällig. Danach gilt das
  Intervall mit Streuung (`SyncSettings::nextRunAt`).
- Ein Lauf darf den Cron nicht blockieren. Die Etappengröße (5 Seiten à 20) bleibt, bei vielen Personen
  begrenzt der Job die Zahl der Personen pro Lauf. Der Rest kommt beim nächsten Cron-Lauf dran.
- „Jetzt synchronisieren“ und die erste Import-Etappe laufen direkt in der Anfrage der
  Einstellungsseite.

### Einstellungsseite

- Vue mit den Nextcloud-Bausteinen (`@nextcloud/vue`), z. B. ein Auswahlfeld mit eigener Eingabe,
  Dialoge, Fortschrittsbalken. Dafür wird Node.js LTS auf dem Entwicklungsrechner per winget
  installiert (vom Nutzer freigegeben, kein Dienst).
- Die Seite spricht über einen eigenen Controller mit JSON-Antworten. Der Fortschritt des Imports
  wird abgefragt, solange die Seite offen ist.
- Gebaute Dateien (`js/`) gehören in das Release-Paket. Ob sie auch im Repository liegen oder nur im
  Release-Workflow entstehen, wird mit dem Release-Prozess entschieden.

### Übersetzungen

- Alle Texte der Oberfläche, der Benachrichtigungen und der Termine (Sportarten, Titel, Beschreibung)
  laufen über Nextcloud-l10n (`IL10N` bzw. `t()` im Frontend). Mitgeliefert werden Englisch und
  Deutsch (NF-07).
- Termintexte entstehen in der Sprache der Person, der der Kalender gehört.

## Tests

- Der Kern wird wie bisher ohne Nextcloud mit PHPUnit getestet.
- Die Nextcloud-Anbindung bekommt Integrationstests, die GitHub Actions bei jedem Push gegen eine
  frische Nextcloud 33, 34 und 35 ausführen. Diese Tests sprechen nie mit Garmin, der Garmin-Zugriff
  wird dort durch einen Ersatz simuliert.
- Der abschließende Test von Hand läuft auf der eigenen Instanz (Nextcloud 35.0.2) mit dem echten
  Garmin-Konto und einem eigenen Testkalender. Dazu wird die App dort von Hand installiert.

## Abhängigkeiten aktuell halten

- Renovate (die GitHub-App ist bereits im Einsatz) mit einer `renovate.json` im Repository, eingerichtet
  zusammen mit dem CI-Aufbau.
- Erfasst werden die Entwicklungs-Pakete von Composer, die npm-Pakete und die GitHub Actions.
- `nextcloud/ocp` bleibt auf der kleinsten unterstützten Nextcloud-Version (33). Renovate hebt sie
  nicht an, das geschieht nur mit einer Änderung der unterstützten Versionen.
- Neue Hauptversionen von `@nextcloud/vue` und `vue` schlägt Renovate nur vor, sie werden nie
  automatisch übernommen, weil sie zur Nextcloud-Version passen müssen.
- Zur Laufzeit hat die App keine eigenen Composer-Pakete. Bibliotheken wie `psr/log` liefert Nextcloud.

## Festlegungen nach der Durchsicht

Diese Punkte hat die Durchsicht des Entwurfs aufgeworfen. Sie sind so abgestimmt.

- **Nur eigene Kalender.** Zur Auswahl stehen nur Kalender, die der Person selbst gehören, keine
  geteilten. Damit entfallen Rechteprüfungen bei jedem Lauf und Kollisionen der UIDs zwischen Personen.
  Termintexte entstehen in der Sprache der Person.
- **Kalender beim Abmelden gelöscht:** Bei erneuter Anmeldung startet der Assistent ab Schritt 2.
- **Nur Termine beim Abmelden entfernt:** Bei erneuter Anmeldung läuft der Historien-Import mit dem
  eingestellten Zeitraum neu.
- **Anderes Garmin-Konto:** Meldet sich die Person mit einer anderen E-Mail-Adresse an, beginnt der
  Sync-Zustand von vorn, und der Löschabgleich betrachtet nur Termine, die das neue Konto angelegt
  hat. Termine des alten Kontos werden nie automatisch markiert oder gelöscht.
- **Umzug beim Kalenderwechsel** läuft in Etappen im Hintergrund, mit Fortschritt im Überblick, nicht
  in der Anfrage der Seite. Steht im Zielkalender schon ein Termin mit derselben UID, gewinnt der
  aktuelle Stand aus Garmin.
- **Pause nach `INVALID_CREDENTIALS` und `MFA_REQUIRED`:** Der Sync pausiert, bis neue Zugangsdaten
  eingegeben werden.
- **Gleichzeitige Läufe** (Cron und „Jetzt synchronisieren“) werden pro Person verhindert.
- **Umfang:** Dieser Schritt endet bei einer App, die sich auf einer Instanz von Hand installieren
  lässt. App-Store-Konto, Signatur und Release-Workflow gehören zum Release-Prozess und folgen
  danach.
- **Änderungen am Kern sind erlaubt**, solange er frei von Nextcloud-Klassen bleibt. Nötig sind
  mindestens eine Übersetzungs-Schnittstelle für den `ActivityEventMapper`, das spätere Erweitern
  des Historien-Zeitraums im `SyncService` und ein Zustand für die Pause nach abgelehntem Passwort.

## Dem Bau überlassen

- Speicherort und Format der Einstellungen und des `SyncState` pro Person (z. B. `IUserConfig`) und ob
  Zugangsdaten über `ICrypto` oder `FLAG_SENSITIVE` verschlüsselt werden.
- Schnitt der Controller-Routen und der Vue-Komponenten.
- Wie viele Personen ein Cron-Lauf höchstens abarbeitet.
- Ob Garmin vorab die Gesamtzahl der Aktivitäten liefert. Wenn ja, zeigt der Fortschritt einen echten
  Prozentbalken, sonst nur Anzahl und erreichtes Datum.
- Die genauen Texte, solange sie dem Sinn dieses Dokuments folgen.
- Aufteilung in Etappen und Reihenfolge der Umsetzung im Plan.
