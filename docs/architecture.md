# Architektur

Klassische Nextcloud-App in PHP (App-ID `garmincalendar`), siehe
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
SyncService                                ── geplant
    │                       │
    ▼                       ▼
GarminClient           ActivityEventMapper ──► IcsSerializer
    │                                              │
    ▼                                              ▼
Authenticator / DiTokenService            Nextcloud-Kalender-API  ── geplant
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
| `lib/Calendar` | `ActivityEventMapper`, `CalendarEvent`, `IcsSerializer` | ✅ |
| `lib/Sync` | Sync-Ablauf, Historien-Import in Etappen, Umgang mit gelöschten Aktivitäten | geplant |
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

## Diagnose

Jede Anfrage wird mit Schritt (z. B. `widget.signin-post`), Endpunkt, Status, Dauer und
Cloudflare-Merkmalen geloggt, Fehler zusätzlich mit Kategorie und bereinigtem Antwortauszug.
Geheimnisse entfernt der `Redactor`. Details in
[ADR 0004](adrs/0004-diagnose-logging-fuer-garmin-zugriffe.md).

## Termine

- UID `garmin-<activityId>`, dadurch keine Duplikate.
- Zeiten in UTC (`DTSTART`/`DTEND` mit `Z`), Kalender-Clients zeigen sie in lokaler Zeit an.
- ICS nach RFC 5545: CRLF, Escaping, Zeilenfaltung bei 75 Oktetten ohne zerschnittene
  UTF-8-Zeichen.
