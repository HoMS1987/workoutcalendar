# 8. Garmin-Sitzung zwischen zwei Anfragen

Datum: 2026-11-03

## Status

Accepted

## Kontext

Garmin-Konten mit Zwei-Faktor-Anmeldung (F-07) brauchen zwei getrennte Anfragen an Nextcloud:
„Code anfordern“ meldet sich mit dem Passwort an, „Bestätigen“ schickt den Code. Garmin erwartet den
Code in derselben SSO-Sitzung, also mit denselben Cookies und denselben Query-Parametern wie der
Login. Zwischen den beiden Anfragen läuft in PHP kein Prozess weiter, die Sitzung muss also
gespeichert werden. Cookies sind Sitzungsdaten, mit denen sich jemand als die Person bei Garmin
ausweisen kann. Zugangsdaten und Tokens liegen bereits verschlüsselt im `SecretStore`
([ADR 0003](0003-klassische-php-nextcloud-app.md)), Codes und Geheimnisse dürfen nie ins Log
([ADR 0004](0004-diagnose-logging-fuer-garmin-zugriffe.md)).

## Entscheidung

- Die laufende Code-Anfrage steht verschlüsselt im `SecretStore` unter dem Schlüssel `mfa_pending`:
  SSO-Cookies (Cookie-Liste von curl), Query-Parameter, Weg (`mfaLastMethodUsed`, unverändert),
  Login-Strategie (Mobile oder Portal), Herkunft (`MfaOrigin`: Einrichtung, „Code anfordern“ oder
  Versuch im Hintergrund) und Zeitpunkt.
- Bei der Einrichtung fehlen die Zugangsdaten noch im `SecretStore`. Dort gehören E-Mail und Passwort
  zur Code-Anfrage und werden nach einem richtigen Code als Zugangsdaten gespeichert.
- Eine Code-Anfrage gilt höchstens 30 Minuten (`PendingMfa::LIFETIME_SECONDS`). Beim Lesen gilt eine
  abgelaufene Anfrage als nicht vorhanden. Der `MfaCleanupJob` löscht abgelaufene Einträge, auch von
  Personen, die die Einstellungen nicht mehr öffnen.
- Gelöscht wird die Anfrage außerdem nach einem richtigen Code, bei „Zurück“ und „Abbrechen“
  (`POST /api/mfa/cancel`), beim Abmelden und beim Zurücksetzen.
- Der Code selbst wird nie gespeichert und nie geloggt. Der `Redactor` schwärzt Code und
  Cookie-Werte.
- Setzt Garmin nach dem Code ein Cookie („Browser merken“), wird es verschlüsselt unter `sso_cookies`
  aufgehoben, als Teil der Anmeldung nach einem erfolgreichen Code, und bei jedem Login mit Passwort
  mitgeschickt.
- Die Markierung „Konto nutzt Zwei-Faktor-Anmeldung“ (`mfaAccount` und `mfaMethod` im `SyncState`)
  liegt unverschlüsselt bei den Benutzerdaten, weil sie keine Geheimnisse enthält. Bei markiertem
  Konto gibt es keinen Login mit Passwort im Hintergrund. Der Sync pausiert mit `MFA_REQUIRED` und
  benachrichtigt.
- Wer wie oft einen Code anfordern darf, steht in `mfa_requests` (Zeitpunkte, Benutzerdaten):
  höchstens einmal pro Minute und fünfmal pro Stunde je Person (`MfaRequestLimit`), weil jedes
  Anfordern ein vollständiger Login mit Passwort ist.

## Konsequenzen

- Verschlüsselte Sitzungsdaten liegen kurz in der Datenbank, höchstens 30 Minuten und nur, solange
  die Person einen Code erwartet. Wer den Schlüssel der Instanz hat, kann sie lesen, das gilt schon
  für die Zugangsdaten.
- Gemerkte Cookies bleiben länger gespeichert, bis die Person sich abmeldet, zurücksetzt oder ein anderes Garmin-Konto anmeldet. Sie
  ersparen unter Umständen einen Code, ob Garmin so ein Cookie setzt, ist noch offen.
- Bei markiertem Konto meldet sich die App nie selbst mit dem Passwort an. Läuft das Refresh-Token
  ab, pausiert der Sync, bis die Person einen Code eingibt. Dafür verschickt die App keine Codes
  ungefragt, und Garmin sieht keine wiederholten Logins.
- Ein 1.0.x-Zustand mit `pausedFor = MFA_REQUIRED` gilt ohne Migration als markiert.
- Neue Geheimnisse in der Sitzung müssen im `Redactor` ergänzt werden.
- Der einzige Login ohne Zutun der Person betrifft ein noch nicht markiertes Konto: Der Sync meldet
  sich einmal mit Passwort an, merkt sich `MFA_REQUIRED` und hebt die Sitzung dieses Versuchs als
  Code-Anfrage auf.
