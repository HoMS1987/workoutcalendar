# Machbarkeitsanalyse: Garmin-Aktivitäten automatisch in den Nextcloud-Kalender

Stand: 2026-10-20 · Status: Recherche, noch nichts praktisch getestet

## Ziel

Jede in Garmin Connect erfasste Aktivität (Lauf, Rad, Schwimmen …) soll automatisch als Termin
in einem Nextcloud-Kalender erscheinen: Startzeit, Dauer, Typ, Distanz und eventuell Puls und
Höhenmeter in der Beschreibung.

## Kurzfazit

**Machbar, aber nicht über einen offiziellen und stabilen Weg.** Die Nextcloud-Seite ist
unproblematisch. Der Engpass ist der Datenzugriff auf Garmin Connect:

| Teil | Bewertung |
|---|---|
| Termine in Nextcloud schreiben | ✅ Problemlos (CalDAV oder interne Nextcloud-PHP-API) |
| Garmin-Daten über die offizielle API | ❌ Für Privatpersonen praktisch nicht verfügbar |
| Garmin-Daten über inoffizielle Bibliotheken | ⚠️ Funktioniert, aber fragil (Garmin blockiert aktiv) |
| Garmin-Daten über den Umweg Strava | ⚠️ Möglich, kostet aber eventuell Geld und hängt von Stravas API-Regeln ab |
| Garmin-Daten über manuellen Export | ✅ Stabil, aber nicht automatisch |

## 1. Datenquelle Garmin Connect

### 1.1 Offizielle API (Garmin Connect Developer Program / Activity API)

- Das Programm richtet sich an Unternehmen und Organisationen. Laut einer Integrationsdoku eines
  Drittanbieters ist **private Nutzung nicht vorgesehen**; man muss sich als juristische Person
  bewerben. *(Quelle ist ein Drittanbieter, nicht Garmin selbst, daher nicht verifiziert.)*
- **Neue Anträge sind seit etwa Frühjahr 2026 pausiert.** Das Antragsformular wurde entfernt.
  Garmin spricht von einer „significant redesign and modernisation“ und nennt keinen Termin für
  die Wiederöffnung (Bericht von the5krunner, 14.09.2026, und Garmin-Forum).
- Vermuteter Hintergrund: Streit mit Strava 2025 um API-Bedingungen und Attribution sowie
  Regeln zur KI-Nutzung der Daten. *(Das ist eine Vermutung des Artikels, nicht bestätigt.)*

➡️ **Für dieses Projekt nicht nutzbar.**

### 1.2 Inoffizieller Zugriff (wie die Garmin-Connect-App selbst)

Community-Bibliotheken melden sich mit Benutzername und Passwort an und nutzen die
undokumentierten REST-Endpunkte, die auch App und Website verwenden.

- **python-garminconnect** (cyberjunky, Python) ist die bekannteste und aktiv gepflegte
  Bibliothek.
  - Seit Version 0.3.0 nutzt sie nicht mehr `garth`, das inzwischen deprecated ist.
  - Sie verwendet den Mobile-SSO-Flow der offiziellen iOS-App und speichert Tokens in
    `~/.garminconnect/garmin_tokens.json` mit automatischem Refresh.
  - Sie probiert mehrere Login-Strategien aus, teils mit **TLS-Impersonation** über `curl_cffi`.
- **Wichtiges Problem: TLS-Fingerprinting.** Laut Nutzerberichten im Garmin-Forum (kein
  offizielles Garmin-Statement, nicht selbst überprüft) hat Garmin im Frühjahr 2026
  Cloudflare-TLS-Fingerprinting eingeführt. Das blockiert Clients, die sich nicht wie ein
  Browser bzw. die App „anfühlen“. Gemeldet wurde außerdem, dass **Cloud-/Hosting-IPs geblockt**
  werden (Beispiel Railway). Ein Token, das lokal funktionierte, wurde nach dem Deployment
  dort abgelehnt.
- PHP-Bibliotheken gibt es, z. B. `alexoid/laravel-garmin` (PHP ≥ 8.4, Guzzle) und ältere wie
  `noginn/garmin-connect-client` von 2013. **Vermutung, nicht getestet:** Ein normaler
  PHP-cURL/Guzzle-Client dürfte am TLS-Fingerprinting eher scheitern als die
  Python-Bibliothek mit `curl_cffi`.

**Risiken:**
- Garmin kann den Zugang jederzeit wieder brechen. Laut Forenberichten und dem Changelog der
  Bibliothek (Wechsel weg von `garth`) ist das 2026 mindestens einmal passiert.
- Die Nutzung inoffizieller Endpunkte widerspricht vermutlich den Garmin-Nutzungsbedingungen.
  *(Nicht im Detail geprüft.)* Bei rein privater Nutzung der eigenen Daten ist das Risiko
  praktisch eher gering, aber vorhanden.
- 2FA/MFA am Garmin-Konto erschwert unbeaufsichtigte Logins. Die Bibliothek unterstützt MFA,
  ein erneuter Login braucht dann aber Interaktion.
- Klartext-Zugangsdaten bzw. Tokens müssen auf dem Rechner liegen, auf dem der Sync läuft.

➡️ **Technisch der einzige Weg zur echten Automatisierung direkt aus Garmin, aber
Wartungsaufwand einplanen.**

### 1.3 Umweg über Strava

Garmin → Strava (offizielle Auto-Sync-Partnerschaft) → Strava-API → Nextcloud.

- Die Strava-API ist offiziell und stabil. Standardlimit laut offizieller Doku (nur über eine
  Such-Zusammenfassung gelesen, Seite nicht selbst geöffnet): 200 Anfragen pro 15 Minuten bzw.
  2.000 pro Tag (Nicht-Upload-Endpunkte: 100 bzw. 1.000). Das reicht für den privaten Gebrauch
  locker.
- **Aber:** Strava hat sein Entwicklerprogramm zum 01.06.2026 umgebaut. Laut einer
  Drittanalyse gibt es jetzt ein Abo von rund 11,99 $/Monat. *(Nicht offiziell verifiziert.)*
- Laut the5krunner verlangt Garmin seit Juli 2025 Attribution, wenn Garmin-Daten bei Dritten
  angezeigt werden. *(Nicht bei Garmin selbst geprüft.)* Für einen privaten Kalender ist das
  vermutlich irrelevant.

➡️ **Gute Alternative, falls der inoffizielle Garmin-Zugriff zu instabil ist.**
Voraussetzung: ein Strava-Konto und die (eventuellen) Kosten.

### 1.4 Garmin-eigene Kalenderfunktion

Garmin Connect kann einen Kalender veröffentlichen (ICS), allerdings **nur geplante Workouts für
die nächsten ~30 Tage**, keine absolvierten Aktivitäten. Laut Forenberichten aktualisiert sich
der Feed außerdem nicht zuverlässig.

➡️ **Für das Ziel ungeeignet.**

### 1.5 Manueller Export

Einzelne FIT-Dateien oder der Gesamt-Datenexport (DSGVO-Export) funktionieren weiterhin. Damit
ließe sich ein einmaliger **Import der Historie** umsetzen, aber kein laufender Sync.

➡️ **Sinnvoll als Ergänzung für den Import alter Aktivitäten.**

## 2. Zielsystem Nextcloud

Zwei Wege, Termine einzutragen:

### 2.1 Über CalDAV von außen (empfohlen für den Start)

- Jeder Nextcloud-Kalender ist per CalDAV erreichbar:
  `https://<nextcloud>/remote.php/dav/calendars/<user>/<kalender>/`
- Anmeldung mit einem **App-Passwort** (Nextcloud → Einstellungen → Sicherheit).
- Jede Aktivität wird ein `VEVENT` mit stabiler UID, z. B. `garmin-<activityId>@nextcloudgarmin`.
  So bleiben erneute Läufe idempotent: kein Duplikat, sondern Update.
- Python: Bibliothek `caldav` (oder einfacher HTTP-`PUT` einer `.ics`-Ressource).
- **Kein Nextcloud-App-Code nötig.**

### 2.2 Als echte Nextcloud-App (PHP)

- Nextcloud-Apps sind PHP (Backend) plus optional Vue.js (Frontend).
- Kalender-API: `OCP\Calendar\IManager`. Beschreibbare Kalender implementieren
  `ICreateFromString::createFromString($name, $icsData)`. Ab Nextcloud 32 gibt es zusätzlich
  `createFromStringMinimal`. *(Laut einer Quellcode-Kopie eines Drittanbieters, gegen das
  offizielle Repo zu prüfen.)*
- Periodischer Sync über Nextclouds **Background Jobs** (`OCP\BackgroundJob\TimedJob`). Diese
  laufen über den System-Cron des Nextcloud-Servers.
- Problem: Der Garmin-Zugriff müsste dann **aus PHP** und **vom Nextcloud-Server** erfolgen.
  Beide Punkte verschärfen das TLS-Fingerprinting- und IP-Block-Problem (siehe 1.2).
- Variante **ExApp (AppAPI)**: Nextcloud-Apps in Python, die in einem Docker-Container neben
  Nextcloud laufen. Damit wäre `python-garminconnect` nutzbar. Voraussetzung ist Docker plus
  AppAPI auf dem Nextcloud-Server. *(Aufwand und Eignung noch nicht im Detail geprüft.)*

## 3. Empfohlene Architektur

**Phase 1 – Prototyp (Python-Skript, kein Nextcloud-App-Code):**

```
python-garminconnect  ──►  Aktivitäten der letzten N Tage holen
        │
        ▼
  Mapping → iCalendar VEVENT (UID = garmin-<activityId>)
        │
        ▼
  CalDAV PUT  ──►  Nextcloud-Kalender „Sport“
```

- Lokal manuell starten. Das prüft am schnellsten, ob der Garmin-Login überhaupt funktioniert.
- Danach **vom Nextcloud-Server aus** testen, ob dessen IP von Cloudflare geblockt wird.
- Wenn es stabil läuft: per Cron auf dem Server bzw. NAS ausführen, **nicht** auf dem
  Windows-Rechner als Dienst.

**Phase 2 – optional echte Nextcloud-App:**
Erst wenn Phase 1 zeigt, dass der Garmin-Zugriff vom Server aus zuverlässig funktioniert.
Dann entweder:
- PHP-App mit Background Job (nur sinnvoll, wenn ein PHP-Client das TLS-Fingerprinting übersteht), oder
- ExApp in Python (nutzt den Code aus Phase 1 weiter).

**Fallback:** Garmin → Strava → Strava-API, mit derselben CalDAV-Schreiblogik.

## 4. Testergebnisse

### 2026-10-20: Garmin-Login vom Windows-Rechner (privater Anschluss)

`test_garmin.py` mit `garminconnect` 0.3.19:

- **Erster Login mit Zugangsdaten: erfolgreich**, aber erst über eine Ausweich-Strategie. Die
  beiden Mobile-Strategien (`mobile+cffi`, `mobile+requests`) bekamen HTTP 429. Laut Meldung der
  Bibliothek kann das an einem Rate-Limit oder am Client-Fingerprint liegen, nicht nur an der IP.
  Welche Strategie am Ende erfolgreich war, zeigt die Ausgabe nicht.
- **Abruf der Aktivitäten: erfolgreich.** Startzeit (lokal), Typ (`typeKey`), Dauer, Distanz,
  Name und ID sind vorhanden.
- **Zweiter Lauf mit gespeichertem Token: erfolgreich, ohne erneuten Login** und ohne 429.
  Für den regelmäßigen Sync heißt das: Der fehleranfällige Login ist nur selten nötig.
- Nebenbefund: Umlaute in Aktivitätsnamen erscheinen in der Konsolenausgabe kaputt. Ursache ist
  das Ausgabe-Encoding von Python unter Windows (cp1252), nicht Garmin. Die Daten selbst sind
  korrekt. Entscheidung: `test_garmin.py` bleibt unverändert, weil es ein Wegwerf-Skript ist.
  Der eigentliche Sync-Code legt das Encoding von Anfang an fest und bekommt dafür einen Test.

**Noch offen:** derselbe Test vom Nextcloud-Server aus, weil Garmin laut Forenberichten manche
Server-IPs blockiert.

## 5. Entscheidungen und offene Fragen

**Geklärt (2026-10-20, Angaben des Nutzers im Chat):**
- Nextcloud läuft auf einem **eigenen Server**. Cron und eigene Apps sind also möglich.
- Der Nextcloud-Account hat **2FA aktiv**. Für CalDAV wird daher ein **App-Passwort** benötigt.
- Am Garmin-Konto ist nach Sicht des Nutzers in den Einstellungen **keine 2FA aktiv**. Das zeigt
  sich spätestens beim ersten Login-Test. Unbeaufsichtigte Logins sind damit einfacher.
- Ein Strava-Konto ist vorhanden. **Der Strava-Fallback wird nur genutzt, wenn die API
  kostenlos ist.** Das ist noch offiziell zu prüfen.

**Offen:**
1. Welche Nextcloud-Version?
2. Welche Felder sollen im Termin stehen? (Titel-Schema, Distanz, Pace, Puls, Höhenmeter, Link zu
   Garmin Connect)
3. Sollen auch historische Aktivitäten importiert werden, und wenn ja, ab wann?

## 6. Lokale Werkzeuge (Windows, keine Dienste/Autostarts)

| Tool | Wofür | Status |
|---|---|---|
| Git | Versionsverwaltung | ✅ installiert (2.55) |
| Python 3.13 (python.org-Installer via winget, Benutzer-Scope) | Prototyp, `python-garminconnect`, `caldav` | ✅ installiert (3.13.15) |
| GitHub CLI `gh` | Repo auf GitHub anlegen und pushen | ✅ installiert (2.102.0), Login noch offen |
| Python-Pakete in `.venv` | `garminconnect` 0.3.19, `caldav` 3.5.0, `curl_cffi` 0.16.3 | ✅ installiert |
| PHP 8.3+ CLI + Composer | Erst in Phase 2 bei einer PHP-App | ❌ fehlt, noch nicht nötig |
| Node.js LTS | Erst in Phase 2 bei einem App-Frontend (Vue) | ❌ fehlt, noch nicht nötig |
| ~~Docker Desktop~~ | Bewusst **nicht**: installiert Hintergrunddienst und Autostart | – |

Alle aufgeführten Tools sind reine Kommandozeilenprogramme ohne Dienst oder Autostart.
Python-Abhängigkeiten kommen in eine projektlokale virtuelle Umgebung (`.venv`).

## Quellen

- [Garmin Activity API – Features](https://developer.garmin.com/gc-developer-program/activity-api/)
- [the5krunner: Garmin Freezes Developer API Months After Strava Lawsuit (14.09.2026)](https://the5krunner.com/2026/09/14/garmin-developer-api-access-paused/)
- [Garmin-Forum: Connect Developer Program access – is the program still paused?](https://forums.garmin.com/developer/connect-iq/f/discussion/440200/connect-developer-program-activity-api-access-no-response-is-the-program-still-paused)
- [Garmin-Forum: TLS fingerprinting blocks third-party clients](https://forums.garmin.com/apps-software/mobile-apps-web/f/garmin-connect-web/433892/tls-fingerprinting-blocks-third-party-clients/2023526)
- [openwearables.io: Garmin API Integration](https://openwearables.io/docs/providers/garmin-api-integration)
- [GitHub: cyberjunky/python-garminconnect](https://github.com/cyberjunky/python-garminconnect)
- [PyPI: garmin-ical-export](https://pypi.org/project/garmin-ical-export)
- [Garmin-Forum: Published Calendar not fully syncing](https://forums.garmin.com/apps-software/mobile-apps-web/f/garmin-connect-web/434250/published-calendar-not-fully-syncing-with-google-calendar-outlook-macos-calendar/2065861)
- [Packagist: alexoid/laravel-garmin](https://packagist.org/packages/alexoid/laravel-garmin)
- [Strava API Rate Limits](https://developers.strava.com/docs/rate-limits/)
- [Strava API Policy](https://www.strava.com/legal/api_policy)
- [Nextcloud Developer Manual – Calendar integration](https://docs.nextcloud.com/server/stable/developer_manual/digging_deeper/groupware/calendar.html)
- [Nextcloud Developer Manual – Calendar providers](https://docs.nextcloud.com/server/stable/developer_manual/digging_deeper/groupware/calendar_provider.html)
