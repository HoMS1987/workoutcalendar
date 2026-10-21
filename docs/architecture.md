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
Einstellungsseite (Vue)  ──► ApiController                                     (geplant)
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
| `lib/AppInfo` | `Application`: Registrierung von Diensten, Hintergrundjobs und Benachrichtigungen | ✅ |
| `lib/BackgroundJob` | `SyncJob`, `CalendarMaintenanceJob` | ✅ |
| `lib/Controller` | `ApiController`: JSON-API der Einstellungsseite | ✅ |
| `lib/Notification` | `Notifier`, `FailureNotifications`: Benachrichtigung bei Fehlern, die nur die Person beheben kann | ✅ |
| `lib/Service` | `SyncRunner`, `AccountService`, `CalendarSetupService`, `StatusService`, `GarminClientFactory`, `Cleanup` u. a. | ✅ |
| `lib/Storage` | `UserStore`, `SecretStore`, `UserTokenStore`, `SetupState`: Einstellungen und verschlüsselte Zugangsdaten in `IUserConfig` | ✅ |
| Einstellungsseite (Vue) | Frontend für die JSON-API | geplant |

Der Kern (`lib/Garmin`, `lib/Calendar`, `lib/Sync`) nutzt keine Nextcloud-Klassen, nur PSR-3 für
das Logging. Er ist mit PHPUnit ohne Nextcloud testbar.

## Hintergrundjob und Warteschlange

- `SyncJob` läuft alle 5 Minuten und bearbeitet pro Aufruf höchstens 10 Personen.
- Die Warteschlange ist `next_run_at` je Person in `IUserConfig`. Fällig sind Personen mit
  erreichtem `next_run_at`, die am längsten wartende zuerst.
- Personen, für die kein Lauf möglich ist (Einrichtung unvollständig, Zugangsdaten abgelehnt,
  Kalender fehlt), verlassen die Warteschlange, bis sie etwas ändern.
- Der Historien-Import läuft bei jedem Cron-Lauf in Etappen weiter, bis er fertig ist.
- `CalendarMaintenanceJob` zieht Termine beim Kalenderwechsel um oder entfernt sie, in Etappen à 200
  Termine. Bei Fehlern oder ohne Fortschritt versucht er es bis zu 5 Mal erneut und gibt dann auf.

## JSON-API

Alle Routen gehören der angemeldeten Person (`/apps/workoutcalendar/api/...`). Garmin-Fehler
liefern 422 mit Fehlerkategorie, ungültige Anfragen 400, alles andere 500.

| Route | Zweck |
|---|---|
| `GET /api/status` | Stand von Einrichtung und Sync |
| `POST /api/login` | Garmin-Zugangsdaten prüfen und speichern (Rate-Limit 10 pro Stunde) |
| `GET /api/calendars` | Kalender zur Auswahl |
| `POST /api/calendar` | Kalender wählen oder wechseln, mit oder ohne Umzug der Termine |
| `POST /api/history` | Historienzeitraum wählen (30, 90, 365 Tage oder komplett), schließt die Einrichtung ab |
| `PUT /api/settings` | Intervall, Streuung, Umgang mit gelöschten Aktivitäten, Prüfzeitraum |
| `POST /api/sync` | Sync sofort ausführen |
| `POST /api/logout` | Von Garmin abmelden |
| `POST /api/reset` | Einstellungen zurücksetzen |

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
