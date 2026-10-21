# Architektur

Klassische Nextcloud-App in PHP (App-ID `workoutcalendar`), siehe
[ADR 0003](adrs/0003-klassische-php-nextcloud-app.md). Anforderungen in
[anforderungen.md](anforderungen.md).

## Überblick

```
Nextcloud-Cron
    │
    ▼
SyncJob (alle 5 Minuten)  ──  CalendarMaintenanceJob (Umzug/Entfernen der Termine)
    │
    ▼
SyncRunner ──► SyncService ──► FailureLog
    │                │                       │
    ▼                ▼                       ▼
FailureNotifications   GarminClient     ActivityEventMapper ──► IcsSerializer
                       │                                              │
                       ▼                                              ▼
        Authenticator / DiTokenService            DavCalendarTarget ──► CalDavBackend (ADR 0005)
                       │
                       ▼
        LoggingHttpClient ──► CurlHttpClient ──► Garmin Connect

ApiController (JSON-API) ──► AccountService, CalendarSetupService, StatusService, SyncRunner
Einstellungsseite (Vue)  ──► ApiController
```

## Module

| Ordner | Inhalt | Stand |
|---|---|---|
| `lib/Garmin/Diagnostics` | `LoggingHttpClient`, `ResponseClassifier`, `FailureCategory`, `Redactor` (ADR 0004) | ✅ |
| `lib/Garmin/Auth` | `MobileLogin`, `WidgetLogin`, `Authenticator`, `DiTokenService`, `GarminTokens`, `TokenStore` | ✅ |
| `lib/Garmin` | `GarminClient`: Aktivitäten abrufen, Tokens erneuern, bei Bedarf neu anmelden | ✅ |
| `lib/Calendar` | `ActivityEventMapper`, `CalendarEvent` (mit Fingerabdruck), `IcsSerializer`, `IcsReader`, `DeletedMarker` | ✅ |
| `lib/Sync` | `SyncService`, `SyncSettings`, `SyncState`, `FailureLog` (gedrosseltes Fehler-Log), `CalendarTarget` | ✅ |
| `lib/CalDav` | `CalendarObjects` mit `DavCalendarObjects` (einzige Klasse mit `OCA\DAV`), `DavCalendarTarget`, `CalendarService` (ADR 0005) | ✅ |
| `lib/Garmin/Http` (auch `CurlHttpClient`) | `HttpClient`-Schnittstelle, Anfrage- und Antwortobjekte, cURL-Umsetzung | ✅ |
| `lib/AppInfo` | `Application`: Registrierung von Diensten und Benachrichtigungen; der `SyncJob` steht in `appinfo/info.xml`, der `CalendarMaintenanceJob` wird bei Bedarf eingereiht | ✅ |
| `lib/BackgroundJob` | `SyncJob`, `CalendarMaintenanceJob` | ✅ |
| `lib/Controller` | `ApiController`: JSON-API der Einstellungsseite | ✅ |
| `lib/Notification` | `Notifier`, `FailureNotifications`: Benachrichtigung bei Fehlern, die nur die Person beheben kann | ✅ |
| `lib/Service` | `SyncRunner`, `AccountService`, `CalendarSetupService`, `StatusService`, `GarminClientFactory`, `Cleanup` u. a. | ✅ |
| `lib/Storage` | `UserStore`, `SecretStore`, `UserTokenStore`, `SetupState`: Einstellungen und verschlüsselte Zugangsdaten in `IUserConfig` | ✅ |
| `lib/Settings` | `Personal`, `Section`: Abschnitt „Workout Calendar“ in den persönlichen Einstellungen | ✅ |
| `src/` | Einstellungsseite (Vue 3, `@nextcloud/vue`): Assistent und Übersicht auf der JSON-API, gebaut nach `js/` und `css/` | ✅ |

Der Kern (`lib/Garmin`, `lib/Calendar`, `lib/Sync`) nutzt keine Nextcloud-Klassen, nur PSR-3 für
das Logging. Er ist mit PHPUnit ohne Nextcloud testbar.

## Hintergrundjob und Warteschlange

- `SyncJob` läuft bei jedem Aufruf des Nextcloud-Crons (alle 5 Minuten) und bearbeitet pro Aufruf
  höchstens 10 Personen. Das Intervall des `TimedJob` ist mit 240 s bewusst kürzer als der
  Cron-Takt: Nextcloud startet ihn erst, wenn mehr als das Intervall vergangen ist, mit 300 s fiele
  jeder zweite Aufruf aus.
- Die Warteschlange ist `next_run_at` je Person in `IUserConfig`. Fällig sind Personen mit
  erreichtem `next_run_at`, die am längsten wartende zuerst.
- Personen, für die kein Lauf möglich ist (Einrichtung unvollständig, Zugangsdaten abgelehnt,
  Kalender fehlt), verlassen die Warteschlange, bis sie etwas ändern. Läuft gerade das Entfernen der
  Termine (Wartung `remove`), wird die Person nur verschoben und bleibt in der Warteschlange.
- Der Historien-Import läuft bei jedem Cron-Lauf in Etappen weiter, bis er fertig ist.
- `CalendarMaintenanceJob` zieht Termine beim Kalenderwechsel um oder entfernt sie, in Etappen à 200
  Termine. Bei Fehlern oder ohne Fortschritt versucht er es bis zu 5 Mal erneut und gibt dann auf
  und vermerkt die Aktion in `setup.maintenanceFailed`. Liegt im Zielkalender unter derselben URI ein
  fremdes Objekt, bleibt der Termin im alten Kalender und zählt nicht mehr zum Rest.
- Ein Sync-Lauf startet erst, wenn die Einrichtung abgeschlossen ist (Schritt `done`), sonst endet
  er mit `notReady`. So initialisiert kein Lauf den Zustand, bevor die Person den Zeitraum der
  Historie gewählt hat. Der Abschluss im Assistenten beginnt immer mit einem frischen `SyncState`.

### Sperre je Person

`PersonLock` hält eine exklusive Sperre (`ILockingProvider`, Schlüssel
`workoutcalendar/sync/<md5(uid)>`). Unter ihr laufen der Sync-Lauf und alle Änderungen an
Anmeldung, Abmeldung, Zurücksetzen, Kalenderwahl, Historie und Einstellungen sowie der Abschluss des
`CalendarMaintenanceJob`. Ein Sync-Lauf hält den Zustand im Speicher und speichert ihn am Ende; ohne
gemeinsame Sperre ginge eine Änderung dazwischen verloren. Hält ein Lauf die Sperre, überspringt der
Hintergrundjob die Person (`skipped`) und die API antwortet mit 409 `busy`
([ADR 0006](adrs/0006-sperre-je-person.md)).

- **Anmeldung:** Die Prüfung der Zugangsdaten bei Garmin läuft innerhalb der Sperre. Ist sie belegt,
  gibt es keine Anfrage an Garmin.
- **Sofortiger Lauf:** `POST /api/sync` und der Abschluss der Historie nutzen `SyncRunner::runNow`.
  Das stellt die Person in die Warteschlange (`next_run_at` = jetzt) und führt den Lauf in derselben
  Sperre aus. Ist sie belegt, endet der Aufruf mit `skipped` und ändert nichts.
- **Wartungsjob:** Der Abschluss (Markierung `maintenance` löschen, `maintenanceFailed` setzen)
  speichert unter der Sperre. Ist sie belegt, reiht sich der Job mit unverändertem Argument neu ein,
  das zählt nicht als Fehlversuch. Hat der Job zu diesem Zeitpunkt aufgegeben, trägt das Argument
  zusätzlich `gaveUp`, und der nächste Lauf führt nur noch den Abschluss aus, ohne weitere Termine zu
  verschieben oder zu entfernen. Beim Abmelden mit Bereinigung `events` steht `maintenance: "remove"`
  gespeichert, bevor der Job eingereiht wird.
- **Nicht wiedereintrittsfähig:** Wer die Sperre hält, ruft `PersonLock::run` für dieselbe Person
  nicht erneut auf. Der Abschluss der Historie bereitet den Zustand unter der Sperre vor, gibt sie
  frei und startet dann `runNow`.

## JSON-API

Alle Routen gehören der angemeldeten Person (`/apps/workoutcalendar/api/...`).

- **Anfragen:** Body als JSON (`Content-Type: application/json`). Zahlen stehen als JSON-Zahlen im
  Body (`"historyDays": 90`, nicht `"90"`), sonst antwortet die API mit 400.
- **Antworten:** Garmin-Fehler liefern 422 mit der Garmin-Fehlerkategorie (`{"error": "INVALID_CREDENTIALS"}`),
  ungültige Anfragen 400 (`invalidRequest`), ohne angemeldete Person 401, ein laufender Sync oder eine
  andere Änderung für dieselbe Person 409 (`{"error": "busy"}`, später erneut versuchen), alles andere
  500 (`internal`). Bei 500 loggt der Server Klasse und Meldung der Exception, bei der Anmeldung nur die
  Klasse.

| Route | Zweck |
|---|---|
| `GET /api/status` | Stand von Einrichtung und Sync |
| `POST /api/login` | Garmin-Zugangsdaten prüfen und speichern (Rate-Limit 10 pro Stunde) |
| `GET /api/calendars` | Kalender zur Auswahl |
| `POST /api/calendar` | Kalender wählen oder wechseln, mit oder ohne Umzug der Termine |
| `POST /api/history` | Historienzeitraum wählen (30, 90, 365 Tage oder komplett), schließt die Einrichtung ab |
| `PUT /api/settings` | Intervall, Streuung, Umgang mit gelöschten Aktivitäten, Prüfzeitraum; ein neues Intervall plant den nächsten Lauf neu |
| `POST /api/sync` | Sync sofort ausführen (Rate-Limit 30 pro Stunde), Ergebnis in `outcome` |
| `POST /api/logout` | Von Garmin abmelden; Bereinigung `events` oder `calendar` nur ohne laufende Wartung |
| `POST /api/reset` | Einstellungen zurücksetzen; Bereinigung wie beim Abmelden |

### Zustände für die Oberfläche

Die Einstellungsseite leitet ihre Ansicht nur aus `GET /api/status` ab. Grundregel:
**`connected=false` ⇒ Anmeldeformular, unabhängig vom Schritt.** Die Hinweise in der letzten Spalte
kommen zur jeweiligen Ansicht hinzu.

| `setup.step` | `connected` | `setup.calendar` | Wartung und Markierungen | Einstellungsseite zeigt |
|---|---|---|---|---|
| beliebig | `false` | beliebig | beliebig | Anmeldeformular; bekannte maskierte E-Mail-Adresse als Vorschlag |
| `garmin` | `false` | `null` | `maintenance: "move"` oder `"remove"` möglich (Zurücksetzen während der Wartung) | Anmeldeformular und Hinweis, dass die alten Termine noch verschoben bzw. entfernt werden |
| `garmin` | `false` | `null` | `maintenanceFailed: "move"` oder `"remove"` möglich (Wartungsjob nach dem Zurücksetzen aufgegeben) | Anmeldeformular und Hinweis, dass das Verschieben bzw. Entfernen aufgegeben hat und Termine im alten Kalender liegen können |
| `calendar` | `true` | `null` | `calendarMissing: false` | Kalenderauswahl (Assistent) |
| `calendar` | `true` | `null` | `calendarMissing: true` | Kalenderauswahl mit Hinweis, dass der bisherige Kalender fehlt |
| `calendar` | `true` | `null` | `maintenanceFailed: "move"` oder `"remove"` | Kalenderauswahl mit Hinweis, dass das Verschieben bzw. Entfernen aufgegeben hat und Termine im alten Kalender liegen können; verschwindet bei der Wahl eines Kalenders oder einem erfolgreichen Wartungsjob |
| `history` | `true` | gesetzt | – | Auswahl des Historienzeitraums (Assistent) |
| `done` | `true` | gesetzt | `maintenance: null`, `maintenanceFailed: null` | Übersicht: Sync-Stand, Einstellungen, „Jetzt synchronisieren“, Kalender wechseln, Abmelden, Zurücksetzen |
| `done` | `true` | gesetzt | `maintenance: "move"` oder `"remove"` | Übersicht mit Hinweis, dass Termine verschoben bzw. entfernt werden; Kalenderwechsel und Bereinigung beim Abmelden/Zurücksetzen gesperrt |
| `done` | `true` | gesetzt | `maintenanceFailed: "move"` oder `"remove"` | Übersicht mit Hinweis, dass das Verschieben bzw. Entfernen aufgegeben hat und Termine im alten Kalender liegen können; verschwindet bei der Wahl eines anderen Kalenders oder einem erfolgreichen Wartungsjob |

Unabhängig vom Schritt: `paused` gesetzt ⇒ Hinweis, dass neue Zugangsdaten nötig sind;
`failure` gesetzt ⇒ Fehlerhinweis mit Kategorie und Zeitpunkt; `sync.backfill.running` ⇒
Fortschritt des Historien-Imports. Eine Antwort 409 `busy` ändert nichts an der Ansicht, die Aktion
lässt sich nach dem laufenden Sync wiederholen.

## Garmin-Zugriff

1. Gespeicherte Tokens laden. Ist der Access-Token abgelaufen, per Refresh-Token erneuern.
2. Ohne Tokens oder bei abgelehntem Refresh-Token neu anmelden: erst `MobileLogin`, dann
   `WidgetLogin`. Bei falschem Passwort oder MFA gibt es keinen weiteren Versuch.
3. Aktivitäten über `connectapi.garmin.com/activitylist-service` abrufen. Bei HTTP 401 einmal
   erneuern und wiederholen.

Die Abläufe folgen `python-garminconnect` 0.3.19. Ändert Garmin etwas, ist diese Bibliothek die
erste Referenz.

## Sync-Lauf

1. **Aktuelles Fenster:** Alle Aktivitäten seit dem letzten Erfolg, mindestens aber der
   Prüfzeitraum für Löschungen (Standard 30 Tage), plus ein Tag Vorlauf wegen Zeitzonen. Neue
   Aktivitäten werden angelegt, geänderte aktualisiert. Geändert heißt: Der Fingerabdruck des
   Termins (Hash über alle Inhalte) weicht ab. Unveränderte Termine werden nicht neu geschrieben,
   sodass Kalender-Clients nichts neu laden müssen.
2. **Löschungen:** Termine im Prüfzeitraum, deren Aktivität Garmin nicht mehr liefert, werden je
   nach Einstellung behalten, markiert (`STATUS:CANCELLED`, Titelzusatz) oder gelöscht. Taucht die
   Aktivität wieder auf, wird der Termin wiederhergestellt. Scheitert der Abruf, wird nichts
   gelöscht oder markiert.
3. **Historien-Import:** höchstens 5 Seiten à 20 Aktivitäten pro Lauf, rückwärts nach Datum
   (`endDate` = ältestes Datum der vorherigen Seite). Der Fortschritt steht im `SyncState`, ein
   abgebrochener Import geht beim nächsten Lauf weiter.
4. **Nächster Lauf:** Intervall ± zufällige Streuung, gespeichert als `nextRunAt`.

Der `SyncState` pro Person hat eine feste Größe und wird als JSON gespeichert.

## Diagnose

Jede Anfrage wird mit Schritt (z. B. `widget.signin-post`), Endpunkt, Status, Dauer und
Cloudflare-Merkmalen geloggt, Fehler zusätzlich mit Kategorie und bereinigtem Antwortauszug.
Geheimnisse entfernt der `Redactor`. Fehler eines Laufs loggt der `FailureLog` gedrosselt: ausführlich
nur bei neuem oder geändertem Fehler, sonst höchstens eine Erinnerung pro Tag. Details in
[ADR 0004](adrs/0004-diagnose-logging-fuer-garmin-zugriffe.md).

## Termine

- UID `garmin-<activityId>`, dadurch keine Duplikate.
- Zeiten in UTC (`DTSTART`/`DTEND` mit `Z`), Kalender-Clients zeigen sie in lokaler Zeit an.
- ICS nach RFC 5545: CRLF, Escaping, Zeilenfaltung bei 75 Oktetten ohne zerschnittene
  UTF-8-Zeichen.
