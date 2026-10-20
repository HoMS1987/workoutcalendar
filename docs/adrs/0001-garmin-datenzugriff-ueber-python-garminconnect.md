# 1. Garmin-Datenzugriff über python-garminconnect

Datum: 2026-10-20

## Status

Accepted

## Kontext

Die Aktivitäten müssen automatisch aus Garmin Connect gelesen werden. Die Optionen sind in
[MACHBARKEIT.md](../../MACHBARKEIT.md) bewertet:

- Die offizielle Garmin-API (Connect Developer Program) ist für Privatpersonen nicht vorgesehen.
  Neue Anträge sind seit Frühjahr 2026 pausiert.
- Der Garmin-eigene Kalender-Export enthält nur geplante Workouts.
- Der Umweg über Strava ist offiziell möglich. Laut einer Drittquelle kostet die Strava-API seit
  Juni 2026 aber Geld. Das ist noch nicht offiziell geprüft.
- `python-garminconnect` nutzt den Login-Weg der Garmin-App und ist aktiv gepflegt (Version
  0.3.19). Es setzt aber auf inoffizielle Schnittstellen.

## Entscheidung

Der Datenzugriff erfolgt über die Python-Bibliothek `python-garminconnect`.

Strava kommt nur als Ausweg in Frage, falls die Strava-API für die private Nutzung kostenlos ist.
Andernfalls scheidet Strava aus.

## Konsequenzen

- Positiv: Kein Antrag bei Garmin nötig, keine Kosten, direkter Zugriff auf alle Aktivitätsdaten.
- Negativ: Garmin kann die Schnittstellen jederzeit ändern oder sperren. Das ist 2026 laut
  Forenberichten mindestens einmal passiert. Updates der Bibliothek müssen zeitnah nachgezogen
  werden.
- Negativ: Garmin-Zugangsdaten bzw. Tokens müssen auf dem Rechner liegen, auf dem der Sync läuft.
- Negativ: Die Nutzung inoffizieller Schnittstellen widerspricht vermutlich den
  Garmin-Nutzungsbedingungen (nicht im Detail geprüft).
- Die Sprache für den Garmin-Teil ist damit Python.
