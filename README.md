# NextCloudGarmin

Nextcloud-App `garmincalendar`: trägt Aktivitäten aus Garmin Connect automatisch als Termine in
einen Nextcloud-Kalender ein.

Status: **in Entwicklung.** Garmin-Zugriff, Diagnose-Logging und die Abbildung auf Kalendertermine
sind umgesetzt und getestet. Sync-Ablauf, Hintergrundjob und Einstellungen folgen.

- Anforderungen: [docs/anforderungen.md](docs/anforderungen.md)
- Architektur: [docs/architecture.md](docs/architecture.md)
- Entscheidungen: [docs/adrs/](docs/adrs/)
- Machbarkeit und Testergebnisse: [MACHBARKEIT.md](MACHBARKEIT.md)

## Haftungsausschluss

Die Nutzung erfolgt auf eigene Gefahr. Garmin bietet Privatpersonen keine offizielle API, die App
nutzt dieselben inoffiziellen Schnittstellen wie die Garmin-Connect-App. Garmin kann sie jederzeit
ändern oder sperren, im ungünstigen Fall auch das Konto einschränken. Das Projekt steht in keiner
Verbindung zu Garmin.

## Voraussetzungen

- Nextcloud 33 bis 35 mit aktivierter Kalender-App
- PHP 8.2 oder neuer mit `ext-curl`
- Garmin-Connect-Konto ohne Zwei-Faktor-Anmeldung

Für die Entwicklung zusätzlich Composer. Für die Diagnose-Skripte optional Python 3.13.

## Setup für die Entwicklung

```powershell
composer install
composer test
```

Die Tests brauchen weder Nextcloud noch einen Garmin-Zugang.

## Beispiele

### Tests ausführen

```powershell
composer test                                  # alle Tests
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
- Ob der Login aus einem Server-Container funktioniert, hängt von IP und TLS-Fingerprint ab und
  ist noch nicht geprüft.
- Garmin-Konten mit Zwei-Faktor-Anmeldung werden nicht unterstützt.
- Die App ist noch nicht installierbar, die Nextcloud-Anbindung fehlt.
