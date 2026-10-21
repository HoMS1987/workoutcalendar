# Workout Calendar

Nextcloud-App (`workoutcalendar`), die Aktivitäten aus Garmin Connect automatisch als Termine in
einen Nextcloud-Kalender einträgt.

Status: **in Entwicklung.** Backend fertig und gegen Nextcloud 33 bis 35 getestet, die
Einstellungsseite folgt.

- Anforderungen: [docs/anforderungen.md](docs/anforderungen.md)
- Architektur: [docs/architecture.md](docs/architecture.md)
- Entscheidungen: [docs/adrs/](docs/adrs/)
- Machbarkeit und Testergebnisse: [MACHBARKEIT.md](MACHBARKEIT.md)

## Haftungsausschluss

Die Nutzung erfolgt auf eigene Gefahr. Garmin bietet Privatpersonen keine offizielle API, die App
nutzt dieselben inoffiziellen Schnittstellen wie die Garmin-Connect-App. Garmin kann sie jederzeit
ändern oder sperren, im ungünstigen Fall auch das Konto einschränken. Das Projekt steht in keiner
Verbindung zu Garmin.

## Lizenz

[AGPL-3.0-or-later](LICENSE). Die Software wird ohne Gewährleistung bereitgestellt, siehe
Abschnitte 15 und 16 der Lizenz.

## Voraussetzungen

- Nextcloud 33 bis 35 mit aktivierter Kalender-App
- PHP 8.2 oder neuer mit `ext-curl`
- Garmin-Connect-Konto ohne Zwei-Faktor-Anmeldung

Für die Entwicklung zusätzlich Composer und Node.js 24 mit npm 11. Für die Diagnose-Skripte optional Python 3.13.

## Setup für die Entwicklung

```powershell
composer install
composer test
npm ci
npm run build
```

Die Unit-Tests brauchen weder Nextcloud noch einen Garmin-Zugang.

Die Einstellungsseite ist eine Vue-3-Oberfläche in `src/`. `npm run build` schreibt sie nach `js/`
und `css/` (nicht eingecheckt, das Bauen ist Teil jedes Releases). `npm run lint` und `npm test`
prüfen den Quelltext, `npm run watch` baut bei Änderungen neu.

Die Integrationstests (`tests/Integration`) laufen in einem echten Nextcloud-Server. Normalerweise
führt sie die GitHub-Action `tests.yml` gegen Nextcloud 33, 34 und 35 aus. Sie lassen sich auch in
einem Server-Checkout starten: App nach `server/apps/workoutcalendar` legen, den Server installieren
(`php occ maintenance:install`), die App aktivieren und in der App `composer test:integration`
aufrufen.

## Beispiele

### Tests ausführen

```powershell
composer test                                  # alle Unit-Tests
composer test:integration                      # nur innerhalb eines Nextcloud-Servers
vendor\bin\phpunit tests\Unit\Calendar         # nur die Termin-Abbildung
```

### Garmin-Zugriff von Hand prüfen

Die Skripte in `php-test/` und `test_garmin.py` prüfen die Garmin-Zugriffe außerhalb von
Nextcloud. Sie lesen die Zugangsdaten aus `.env`:

```powershell
Copy-Item .env.example .env   # danach GARMIN_EMAIL und GARMIN_PASSWORD eintragen
```

`.env` und die Token-Ablage `.garminconnect/` sind in `.gitignore` eingetragen.

```powershell
php-test\run.ps1 -Mode login-mobile    # Login wie die iOS-App, Tokens nach .garminconnect/php_tokens.json
php-test\run.ps1 -Mode login-widget    # Login über das SSO-Widget
php-test\run.ps1 -Mode refresh         # Token erneuern
php-test\run.ps1 -Mode api -PhpToken   # Aktivitäten mit dem PHP-Token abrufen
```

Die Python-Variante nutzt `python-garminconnect`:

```powershell
python -m venv .venv
.venv\Scripts\python.exe -m pip install -r requirements.txt
.venv\Scripts\python.exe test_garmin.py
```

## Limitationen

- Der Garmin-Zugriff läuft über inoffizielle Schnittstellen und kann jederzeit ausfallen.
- Ob der Login aus einem Server-Container funktioniert, hängt von IP und TLS-Fingerprint ab. Auf
  der eigenen Instanz (Talos-Cluster) funktioniert er, auf anderen Servern kann Garmin anders
  entscheiden.
- Garmin-Konten mit Zwei-Faktor-Anmeldung werden nicht unterstützt.
- Die App ist über die Oberfläche noch nicht nutzbar, weil die Einstellungsseite fehlt. Die
  Einrichtung läuft bisher nur über die JSON-API.
