# NextCloudGarmin

Trägt Aktivitäten aus Garmin Connect automatisch als Termine in einen Nextcloud-Kalender ein.

Status: **Machbarkeitsphase.** Bisher gibt es nur ein Testskript, das den Garmin-Login und das
Abrufen der letzten Aktivitäten prüft. Hintergründe und Bewertung der Zugriffswege stehen in
[MACHBARKEIT.md](MACHBARKEIT.md), die Entscheidungen in [docs/adrs/](docs/adrs/).

## Voraussetzungen

- Windows mit Python 3.13 (oder neuer)
- Garmin-Connect-Konto ohne Zwei-Faktor-Anmeldung
- Später: Nextcloud mit App-Passwort für CalDAV

## Setup

```powershell
python -m venv .venv
.venv\Scripts\python.exe -m pip install -r requirements.txt
Copy-Item .env.example .env   # danach GARMIN_EMAIL und GARMIN_PASSWORD eintragen
```

`.env` und die Token-Ablage `.garminconnect/` sind in `.gitignore` eingetragen und landen nicht
im Repository.

## Beispiel

```powershell
.venv\Scripts\python.exe test_garmin.py
```

Bei Erfolg erscheinen die letzten fünf Aktivitäten mit Startzeit, Typ, Dauer, Distanz, Name und
ID. Nach dem ersten Login liegen Tokens in `.garminconnect/`. Das Passwort in `.env` ist danach
nicht mehr nötig.

## Limitationen

- Der Zugriff auf Garmin Connect läuft über inoffizielle Schnittstellen
  ([python-garminconnect](https://github.com/cyberjunky/python-garminconnect)). Garmin kann sie
  jederzeit ändern oder sperren. Details in [MACHBARKEIT.md](MACHBARKEIT.md).
- Die offizielle Garmin-API steht Privatpersonen derzeit nicht zur Verfügung.
- Der Kalender-Sync nach Nextcloud ist noch nicht umgesetzt.
