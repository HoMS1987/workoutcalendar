# 3. Klassische PHP-Nextcloud-App mit eigenem Garmin-Client

Datum: 2026-10-20

## Status

Accepted

Ersetzt [ADR 0001](0001-garmin-datenzugriff-ueber-python-garminconnect.md) und
[ADR 0002](0002-prototyp-als-python-skript-mit-caldav.md).

## Kontext

Die App soll über den integrierten App Store von Nextcloud installierbar sein. Die Instanz läuft
als Helm-Chart auf Talos, AppAPI und ein Deploy-Daemon sind nicht aktiv. Eine ExApp mit Python
würde beides voraussetzen und den Betrieb deutlich aufwendiger machen.

Python aus PHP heraus aufzurufen ist für eine App-Store-App keine Option: Das Nextcloud-Image
enthält kein Python, Apps können keine Systempakete installieren, und `exec` ist auf manchen
Servern deaktiviert.

Der PHP-Test (`php-test/garmin_test.php`, Ergebnisse in [MACHBARKEIT.md](../../MACHBARKEIT.md),
Abschnitt 4) zeigt, dass die Garmin-Zugriffe von `python-garminconnect` 0.3.19 mit reinem PHP und
`ext-curl` funktionieren: Mobile- und Widget-Login, Token-Refresh und Aktivitätsabruf. Nur der
Portal-Login wird von Cloudflare geblockt. Der Test lief vom Windows-Rechner, der Test aus dem
Cluster steht noch aus.

## Entscheidung

- Die App ist eine klassische Nextcloud-App in PHP, Name „Workout Calendar“, App-ID
  `workoutcalendar`, Namespace `OCA\WorkoutCalendar`. Im App Store gab es am 2026-10-20 keine App
  mit dieser ID. Der Name ist bewusst neutral: Er enthält keine fremde Marke wie „Garmin“, lässt
  sich nicht mit der Nextcloud-App „Activity“ verwechseln und passt auch zu weiteren Datenquellen.
  „Garmin Connect“ steht in Untertitel und Beschreibung, damit die App auffindbar bleibt.
- Der Garmin-Zugriff ist ein eigener PHP-Client, der die Abläufe von `python-garminconnect`
  nachbildet. Die Python-Bibliothek dient als Referenz, wenn Garmin etwas ändert.
- Login-Reihenfolge: Mobile-Login, danach Widget-Login als Ausweichweg. Der Portal-Login entfällt.
- HTTP läuft direkt über `ext-curl` hinter einer eigenen Schnittstelle (`HttpClient`), weil genau
  dieser Weg getestet ist. Der Nextcloud-HTTP-Client (Guzzle) bleibt eine Option, falls Proxy-
  Einstellungen der Instanz nötig werden.
- Termine werden über die interne Kalender-API von Nextcloud geschrieben, nicht über CalDAV. Ein
  App-Passwort ist damit nicht nötig.
- Jeder Termin hat die UID `garmin-<activityId>`. Erneute Syncs aktualisieren statt zu duplizieren.
- Der Sync läuft als Nextcloud-Hintergrundjob (`TimedJob`), angestoßen vom Cron der Instanz.
- Garmin-Passwort und Tokens werden verschlüsselt gespeichert (`OCP\Security\ICrypto`).
- Der Kern (Garmin-Client, Abbildung auf Termine, Sync-Ablauf) hängt nicht von Nextcloud-Klassen
  ab und ist ohne Nextcloud mit PHPUnit testbar.

## Konsequenzen

- Installation über den App Store ohne AppAPI, Daemon oder Container-Image.
- Der Garmin-Client ist eigener Code. Ändert Garmin die Login-Abläufe, muss er nachgezogen werden.
  Deshalb braucht es eine gute Diagnose, siehe [ADR 0004](0004-diagnose-logging-fuer-garmin-zugriffe.md).
- Ob der Login aus dem Linux-Container der Instanz funktioniert (anderer TLS-Fingerprint, andere
  IP), war zum Zeitpunkt der Entscheidung nicht geprüft. Nachtrag 2026-10-20: Mobile-Login und
  Datenabruf funktionieren aus dem Nextcloud-Pod (MACHBARKEIT.md, Abschnitt 4).
- Das Python-Testskript und `php-test/` bleiben als Werkzeuge zur Fehlersuche im Repository, werden
  aber nicht mit der App ausgeliefert.
- Unterstützte Nextcloud-Versionen: vorerst 33 bis 35. Die Version der eigenen Instanz ist noch
  offen.
- Nachtrag 2026-10-20: Die interne Kalender-API ist `CalDavBackend` der DAV-App, siehe
  [ADR 0005](0005-calendar-ueber-caldavbackend.md).
