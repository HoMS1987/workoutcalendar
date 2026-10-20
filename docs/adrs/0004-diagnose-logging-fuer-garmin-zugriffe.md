# 4. Diagnose-Logging für Garmin-Zugriffe

Datum: 2026-10-20

## Status

Accepted

## Kontext

Der Garmin-Zugriff läuft über inoffizielle Schnittstellen (ADR 0003). Garmin kann sie jederzeit
sperren oder ändern, etwa durch Cloudflare-Regeln, neue Login-Abläufe oder geänderte JSON-Felder.
Wenn der Sync dann ausfällt, muss aus dem Log hervorgehen, was sich geändert hat und an welcher
Stelle, damit die App gezielt angepasst werden kann. Gleichzeitig dürfen Passwörter, Tokens und
Service-Tickets nie im Log landen.

## Entscheidung

- Alle Garmin-Anfragen laufen über einen protokollierenden HTTP-Client (Decorator um `HttpClient`).
  Jede Anfrage wird auf Stufe `debug` mit Schritt, Methode, Host und Pfad (ohne Query), Status,
  Dauer und Cloudflare-Merkmalen (`cf-ray`, `cf-mitigated`, `server: cloudflare`) geloggt.
- Fehler werden in Kategorien eingeteilt (`FailureCategory`):
  - `BLOCKED`: 403 oder 429, Cloudflare-Challenge
  - `INVALID_CREDENTIALS`: Garmin lehnt Benutzername oder Passwort ab
  - `MFA_REQUIRED`: Garmin verlangt einen zweiten Faktor
  - `PROTOCOL_CHANGED`: erwartete Bestandteile fehlen (CSRF-Token, Ticket, JSON-Felder, unbekannter
    `responseStatus`)
  - `TOKEN_REJECTED`: Access- oder Refresh-Token wird nicht mehr akzeptiert
  - `SERVER_ERROR`: 5xx von Garmin
  - `NETWORK`: keine Verbindung, Timeout, TLS-Fehler
- Einzelne fehlgeschlagene Login-Versuche und Netzwerkfehler werden auf Stufe `debug` geloggt. Der
  Sync-Ablauf loggt den Fehler eines Laufs gedrosselt (siehe unten) mit Kategorie, Schritt, Status,
  einem bereinigten Auszug der Antwort (höchstens 300 Zeichen) und der Zusammenfassung aller
  Login-Versuche je Strategie. Stufe `error` gilt für Fehler, die ein Eingreifen brauchen
  (`INVALID_CREDENTIALS`, `MFA_REQUIRED`, `PROTOCOL_CHANGED`), sonst `warning`.
- Die erfolgreiche Login-Strategie wird auf Stufe `info` geloggt. So fällt auf, wenn plötzlich nur
  noch der Ausweichweg funktioniert.
- Alles, was ins Log geht, läuft durch einen `Redactor`. Er entfernt Passwörter, Tokens, JWTs,
  Service-Tickets, CSRF-Werte, `Authorization`-Header und E-Mail-Adressen. Die Bereinigung ist mit
  Unit-Tests abgesichert.
- Geloggt wird über PSR-3 (`Psr\Log\LoggerInterface`), in Nextcloud also ins Nextcloud-Log mit der
  App-ID `workoutcalendar`.
- Später zeigt die App den letzten Sync-Status samt Kategorie in den persönlichen Einstellungen an.
- Wiederholte Fehler werden gedrosselt: Der Sync-Ablauf merkt sich pro Person Kategorie, Schritt,
  Beginn und Anzahl des aktuellen Fehlers. Ausführlich (`warning`/`error`) geloggt wird nur ein
  neuer Fehler oder ein Wechsel von Kategorie oder Schritt. Hält derselbe Fehler an, folgt höchstens
  einmal täglich eine kurze Erinnerung mit der Zahl der Fehlschläge. Nach Behebung folgt ein
  `info`-Eintrag mit der Zahl der Fehlschläge.
- Die Größe der Logdatei begrenzt Nextcloud (`log_rotate_size`) bzw. die Container-Plattform. Die
  App legt keine eigenen Logdateien oder Log-Tabellen an.

## Konsequenzen

- Eine Sperre oder Änderung bei Garmin lässt sich aus dem Log einer Kategorie und einem Schritt
  zuordnen, ohne den Fehler lokal nachstellen zu müssen.
- Mit Log-Stufe `debug` entsteht ein vollständiger Ablauf jeder Anmeldung, vergleichbar mit der
  Ausgabe von `php-test/garmin_test.php`.
- Bei einer dauerhaften Sperre entstehen pro Person nur ein ausführlicher Eintrag und danach eine
  Erinnerung pro Tag, statt einer Warnung bei jedem Sync-Lauf. Das Detail-Log pro Anfrage
  erscheint nur bei Log-Stufe `debug`.
- Der Redactor ist sicherheitsrelevant. Neue Felder mit Geheimnissen müssen dort ergänzt werden.
- Die Erkennung von Cloudflare stützt sich auf heutige Merkmale (Header, Seitentitel). Ändern sich
  diese, landet ein Fehler eventuell unter `PROTOCOL_CHANGED` statt `BLOCKED`. Die Rohdaten (Status,
  Header-Merkmale, Auszug) stehen trotzdem im Log.
