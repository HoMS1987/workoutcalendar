# Architektur

Klassische Nextcloud-App in PHP (App-ID `workoutcalendar`), siehe
[ADR 0003](adrs/0003-klassische-php-nextcloud-app.md). Anforderungen in
[anforderungen.md](anforderungen.md).

## Überblick

```
Nextcloud-Cron
    │
    ▼
TimedJob (pro Person)                      ── geplant
    │
    ▼
SyncService ──► FailureLog
    │                       │
    ▼                       ▼
GarminClient           ActivityEventMapper ──► IcsSerializer
    │                                              │
    ▼                                              ▼
Authenticator / DiTokenService            DavCalendarTarget ──► CalDavBackend (ADR 0005)
    │
    ▼
LoggingHttpClient ──► CurlHttpClient ──► Garmin Connect           (CurlHttpClient geplant)
```

## Module

| Ordner | Inhalt | Stand |
|---|---|---|
| `lib/Garmin/Http` | `HttpClient`-Schnittstelle, Anfrage- und Antwortobjekte | ✅ |
| `lib/Garmin/Diagnostics` | `LoggingHttpClient`, `ResponseClassifier`, `FailureCategory`, `Redactor` (ADR 0004) | ✅ |
| `lib/Garmin/Auth` | `MobileLogin`, `WidgetLogin`, `Authenticator`, `DiTokenService`, `GarminTokens`, `TokenStore` | ✅ |
| `lib/Garmin` | `GarminClient`: Aktivitäten abrufen, Tokens erneuern, bei Bedarf neu anmelden | ✅ |
| `lib/Calendar` | `ActivityEventMapper`, `CalendarEvent` (mit Fingerabdruck), `IcsSerializer`, `IcsReader`, `DeletedMarker` | ✅ |
| `lib/Sync` | `SyncService`, `SyncSettings`, `SyncState`, `FailureLog` (gedrosseltes Fehler-Log), `CalendarTarget` | ✅ |
| `lib/CalDav` | `CalendarObjects` mit `DavCalendarObjects` (einzige Klasse mit `OCA\DAV`), `DavCalendarTarget`, `CalendarService` (ADR 0005) | ✅ |
| `lib/BackgroundJob`, `lib/Settings`, `lib/Service` | Nextcloud-Anbindung: Hintergrundjob, Einstellungen, verschlüsselte Ablage | geplant |

Der Kern (`lib/Garmin`, `lib/Calendar`, `lib/Sync`) nutzt keine Nextcloud-Klassen, nur PSR-3 für
das Logging. Er ist mit PHPUnit ohne Nextcloud testbar.

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
