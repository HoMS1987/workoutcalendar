# 2. Prototyp als Python-Skript mit CalDAV statt Nextcloud-App

Datum: 2026-10-20

## Status

Proposed

## Kontext

Ursprüngliches Ziel ist eine Nextcloud-App. Nextcloud-Apps sind in PHP geschrieben. Für PHP gibt
es keinen vergleichbar gepflegten Garmin-Client wie `python-garminconnect` (siehe ADR 0001).
Vermutlich, aber nicht getestet, scheitert ein normaler PHP-HTTP-Client außerdem eher an Garmins
TLS-Fingerprinting.

Offen ist zudem, ob der Garmin-Login vom eigenen Nextcloud-Server aus funktioniert. Laut
Forenberichten blockiert Garmin manche Server- bzw. Hosting-IP-Adressen.

## Entscheidung

Phase 1 ist ein eigenständiges Python-Skript:

1. Aktivitäten über `python-garminconnect` abrufen.
2. Jede Aktivität in ein iCalendar-`VEVENT` mit stabiler UID `garmin-<activityId>` umwandeln.
   Erneute Läufe aktualisieren damit bestehende Termine, statt Duplikate anzulegen.
3. Per CalDAV mit einem Nextcloud-App-Passwort in einen eigenen Kalender schreiben.

Erst wenn Phase 1 auch vom Nextcloud-Server aus zuverlässig läuft, wird über eine echte
Nextcloud-App entschieden (PHP-App oder ExApp in Python über AppAPI).

## Konsequenzen

- Positiv: Schnellster Weg, die Machbarkeit zu prüfen. Kein Nextcloud-App-Gerüst nötig.
- Positiv: Der Code lässt sich später in einer ExApp weiterverwenden.
- Negativ: Ohne Nextcloud-App gibt es keine Oberfläche in Nextcloud. Konfiguriert wird über
  Dateien.
- Negativ: Der regelmäßige Lauf braucht einen Kubernetes-CronJob im Talos-Cluster, auf dem auch
  Nextcloud läuft. Dafür ist ein Container-Image nötig. Auf dem Windows-Rechner soll bewusst
  kein Dienst und kein Autostart laufen.
