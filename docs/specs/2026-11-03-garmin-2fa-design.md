# Entwurf: Garmin-Konten mit Zwei-Faktor-Anmeldung

Stand: 2026-11-03. Setzt F-07 aus [anforderungen.md](../anforderungen.md) um. Grundlage sind der
Entwurf [2026-10-20-nextcloud-anbindung-design.md](2026-10-20-nextcloud-anbindung-design.md), die
ADRs 0001 und 0004 und der Quelltext von python-garminconnect 0.3.20
(`garminconnect/client/sso.py`, README-Abschnitt „MFA in a web app or service“).

## Ziel und Hintergrund

Heute kann ein Garmin-Konto mit Zwei-Faktor-Anmeldung (Garmin: „Two-Step Verification“) die App nicht
nutzen. Der Login endet mit `MFA_REQUIRED`, der Sync pausiert, und die Benachrichtigung sagt, dass
das nicht unterstützt wird. Garmin schickt den Code per E-Mail oder SMS (in der Kontoverwaltung von
Garmin geprüft, auch beides). Laut Forenberichten wird die Zwei-Faktor-Anmeldung mit der EKG-Funktion
mancher Uhren Pflicht.

Nach dieser Version gibt eine Person mit Zwei-Faktor-Anmeldung den Code beim Einrichten einmal ein.
Danach läuft der Sync mit dem Refresh-Token weiter. Wird irgendwann doch wieder ein Code nötig,
meldet sich die App über die Glocke, und die Person gibt ihn in den Einstellungen ein.

Version: 1.1.0. Getestet wird vorher als `1.1.0-rc.1` auf der Instanz des Autors, mit eingeschalteter
Zwei-Faktor-Anmeldung an seinem Garmin-Konto.

## Ziele

- Einrichtung mit Code im Schritt „Garmin“ des Assistenten.
- Erneute Code-Eingabe auf der Übersicht, angestoßen von der Benachrichtigung.
- Ein bestehendes, verbundenes Konto lässt sich auf Zwei-Faktor-Anmeldung umstellen, ohne den
  Assistenten noch einmal zu durchlaufen.
- Ein Code wird nur angefordert, wenn die Person es selbst auslöst (mit einer Ausnahme, siehe
  „Umstellen von selbst“).

- Die Code-Eingabe hängt nicht an E-Mail und SMS. Bietet Garmin später Authenticator-Apps an,
  funktioniert sie auch dafür, siehe „Andere Wege für den Code“.

## Nicht-Ziele

- Zwei-Faktor-Anmeldung über den zweiten Login-Weg (`WidgetLogin`). Siehe „Entscheidungen des
  Entwurfs“.
- Ein Code-Eingabefeld in der Benachrichtigung selbst. Die Benachrichtigung führt nur zur
  Einstellungsseite.

## Anti-Ziele

- Die App fordert im Hintergrund wiederholt Codes an und schickt der Person ungefragt Mails oder SMS.
- Mehrere Login-Versuche hintereinander, die das Garmin-Konto sperren könnten.
- Garmin-Sitzungsdaten oder Codes landen unverschlüsselt in der Datenbank oder im Log.

## Was Garmin erwartet

Aus python-garminconnect 0.3.20, nicht am echten Konto geprüft:

1. Der normale Login (`POST /mobile/api/login`, wie heute in `MobileLogin`) antwortet bei einem Konto
   mit Zwei-Faktor-Anmeldung mit `responseStatus.type = MFA_REQUIRED`.
   `customerMfaInfo.mfaLastMethodUsed` nennt den Weg (`email` oder `sms`). Ob schon diese Antwort
   den Code verschickt, ist nicht belegt. Die Bibliothek fordert ihn auf diesem Weg nicht eigens an.
2. Der Code geht in derselben SSO-Sitzung (dieselben Cookies, dieselben Query-Parameter
   `clientId`, `locale`, `service` und Header) an `POST /mobile/api/mfa/verifyCode` mit dem JSON
   `{"mfaMethod": <Weg>, "mfaVerificationCode": <Code>, "rememberMyBrowser": true,
   "reconsentList": [], "mfaSetup": false}`. Die Bibliothek versucht bei Misserfolg zusätzlich
   `/portal/api/mfa/verifyCode` mit den Portal-Parametern.
3. Bei Erfolg kommt `responseStatus.type = SUCCESSFUL` mit `serviceTicketId`. Ab hier läuft alles
   wie heute: `DiTokenService::exchangeTicket`.
4. Laut README erneuern sich die Tokens danach unbegrenzt, solange das Refresh-Token gültig ist. Wie
   lange es gilt, ist nicht bekannt.
5. Ein falscher Code lässt die SSO-Sitzung bestehen, ein zweiter Versuch ist möglich. Wie lange der
   Code gilt, steht in der Bibliothek nicht.

## Verhalten

### Einrichten

- Im Schritt „Garmin“ gibt die Person wie heute E-Mail und Passwort ein und klickt „Anmelden und
  weiter“.
- Antwortet Garmin mit `MFA_REQUIRED`, ersetzt an derselben Stelle ein Codefeld das Formular.
  Darüber: „Garmin hat dir einen Code per E-Mail geschickt.“ (oder „per SMS“, je nach
  `mfaLastMethodUsed`; fehlt der Wert: „per E-Mail oder SMS“; andere Werte siehe „Andere Wege für
  den Code“).
- Darunter ein Hinweis, sinngemäß: „Dein Garmin-Konto nutzt die Zwei-Faktor-Anmeldung. Läuft die
  Anmeldung bei Garmin irgendwann ab, pausiert Workout Calendar und meldet sich über die Glocke.
  Einen neuen Code fordert die App nur an, wenn du es selbst tust.“
- Knöpfe: „Bestätigen“ und „Neuen Code anfordern“. „Zurück“ führt zum Formular mit E-Mail und
  Passwort.
- Nach einem richtigen Code werden Zugangsdaten und Tokens gespeichert wie heute nach einem
  erfolgreichen Login, und der Assistent geht zum Kalender weiter.
- Bei einem falschen Code bleibt das Feld stehen, mit einer Fehlermeldung.

### Erneute Code-Eingabe (Variante A)

- Ausgangslage: Das Konto ist als „nutzt Zwei-Faktor-Anmeldung“ bekannt, Garmin lehnt das
  Refresh-Token ab.
- Der Sync versucht **keine** Anmeldung mit Passwort. Er pausiert sofort (`pausedFor =
  MFA_REQUIRED`) und legt die Benachrichtigung an. Ihr Text sagt, dass Garmin einen Code möchte, und
  ein Klick führt zu den persönlichen Einstellungen von Workout Calendar.
- Auf der Übersicht steht ganz oben ein Hinweiskasten: „Garmin möchte einen Bestätigungscode. Der
  Sync ist pausiert, bis du ihn eingibst.“ mit dem Knopf „Code anfordern“. Die übrige Übersicht
  bleibt darunter sichtbar.
- „Code anfordern“ meldet sich mit dem gespeicherten Passwort an. Antwortet Garmin mit
  `MFA_REQUIRED`, erscheinen im selben Kasten die Zeile, wohin der Code ging, das Codefeld,
  „Bestätigen“ und „Neuen Code anfordern“.
- Antwortet Garmin beim Anfordern direkt mit `SUCCESSFUL` (etwa wegen „Browser merken“), sind die
  Tokens neu, und der Kasten verschwindet ohne Code.
- Nach einem richtigen Code verschwinden Kasten und Benachrichtigung, die Pause endet, und der
  nächste Sync läuft wie geplant.
- Lehnt Garmin dabei das Passwort ab, gilt der heutige Weg für `INVALID_CREDENTIALS`.

### Umstellen eines bestehenden Kontos

Zwei Wege, beide gewünscht:

- **Von selbst:** Die Person schaltet die Zwei-Faktor-Anmeldung bei Garmin ein. Die App läuft mit den
  vorhandenen Tokens weiter. Lehnt Garmin das Refresh-Token ab, meldet sich die App wie heute mit
  Passwort an, weil sie von der Zwei-Faktor-Anmeldung noch nichts weiß. Das Ergebnis
  `MFA_REQUIRED` merkt sie sich, sie pausiert und benachrichtigt. Ab dann gilt Variante A. Bei diesem
  einen Versuch schickt Garmin vermutlich ungefragt einen Code. Das ist die einzige Ausnahme vom
  Ziel „nur auf Knopfdruck“.
- **Selbst angestoßen:** Im Abschnitt „Garmin-Konto“ kommt der Knopf „Neu anmelden“ hinzu. Er meldet
  sich sofort mit dem gespeicherten Passwort an. Bei `MFA_REQUIRED` erscheint oben der Kasten aus
  Variante A, schon im Zustand nach „Code anfordern“. Kalender, Einstellungen und Termine bleiben
  unberührt. Bei `SUCCESSFUL` werden die Tokens ersetzt, und eine kurze Meldung bestätigt es.

### Andere Wege für den Code

Heute bietet Garmin laut Kontoverwaltung nur E-Mail und SMS an. Authenticator-Apps erzeugen
ebenfalls einen sechsstelligen Code, deshalb ist die Eingabe von Anfang an für weitere Wege
ausgelegt:

- Den Wert aus `mfaLastMethodUsed` reicht die App unverändert als `mfaMethod` an `verifyCode`
  zurück. Sie prüft ihn nicht gegen eine feste Liste.
- Das Codefeld nimmt sechs Ziffern an. Leerzeichen beim Einfügen werden entfernt. Weitere
  Annahmen über den Code gibt es nicht.
- Nur der Text hängt am Weg: „per E-Mail“ und „per SMS“ für `email` und `sms`. Bei einem Wert,
  der nach einer App aussieht (etwa `totp` oder `authenticator`), heißt es „Gib den Code aus
  deiner Authenticator-App ein“, und „Neuen Code anfordern“ entfällt, weil die App jederzeit einen
  neuen Code zeigt. Bei jedem anderen unbekannten Wert gilt der allgemeine Text „Gib den Code ein,
  den Garmin dir geschickt hat“.
- Bei einem App-Weg verschickt „Code anfordern“ nichts. Der Knopf startet nur die Anmeldung und
  heißt dann „Anmeldung starten“. Die Grenzen pro Minute und Stunde gelten trotzdem, weil jeder
  Klick ein Login mit Passwort ist.
- Ein unbekannter Weg wird einmal ins Log geschrieben (nur der Name des Wegs, nie der Code), damit
  ein neuer Wert von Garmin auffällt.

Welche Werte Garmin für eine App senden würde, ist nicht bekannt. `totp` und `authenticator` sind
geraten. Getestet wird mit aufgezeichneten Antworten, deren Wert ausgedacht ist.

### Konten ohne Zwei-Faktor-Anmeldung

Verhalten wie bisher. Die Markierung „nutzt Zwei-Faktor-Anmeldung“ wird gelöscht, sobald ein Login
mit Passwort ohne Code `SUCCESSFUL` ist. Damit gilt ein Konto, bei dem die Person die
Zwei-Faktor-Anmeldung wieder abschaltet, nach „Neu anmelden“ wieder als normales Konto.

## Zustand und Speicherung

- **Markierung pro Person:** „nutzt Zwei-Faktor-Anmeldung“ in den Benutzerdaten der App (neben dem
  `SyncState`). Gesetzt bei jedem `MFA_REQUIRED`, gelöscht wie oben beschrieben.
- **Laufende Code-Anfrage:** Zwischen „Code anfordern“ und „Bestätigen“ liegen zwei getrennte
  Anfragen an Nextcloud. Gespeichert werden die SSO-Cookies (über die Cookie-Liste von curl), die
  Query-Parameter, der Weg (`email`/`sms`), die Login-Strategie und der Zeitpunkt. Verschlüsselt wie
  die Zugangsdaten über `SecretStore`. Gelöscht nach erfolgreichem Code, nach „Zurück“, beim
  Abmelden und Zurücksetzen und spätestens nach 30 Minuten.
- **„Browser merken“:** Setzt Garmin nach dem Code ein Cookie, das später einen Code erspart, wird es
  verschlüsselt aufgehoben und bei jedem Login mit Passwort mitgeschickt. Ob es so ein Cookie gibt,
  ist offen und wird im RC-Test geprüft.
- Codes werden nie gespeichert und nie geloggt. Der `Redactor` bekommt den Code und die Cookie-Werte
  als zu schwärzende Werte.

## Schnittstellen

Neue Routen im `ApiController`, alle `NoAdminRequired`, unter der Sperre der Person:

- `POST /api/login` bleibt, antwortet bei `MFA_REQUIRED` aber mit dem Zustand „Code nötig“ und dem
  Weg statt mit einem Fehler.
- `POST /api/mfa/request`: neue Anmeldung mit dem gespeicherten Passwort (Variante A und „Neu
  anmelden“). Rate-Limit wie `/api/login`.
- `POST /api/mfa/verify` mit `code`. Rate-Limit, damit sich Codes nicht durchprobieren lassen.
- `POST /api/mfa/cancel` löscht die laufende Code-Anfrage.
- `GET /api/status` liefert zusätzlich: Konto nutzt Zwei-Faktor-Anmeldung, Code-Anfrage läuft (mit
  Weg) und ob ein Code nötig ist.

## Entscheidungen des Entwurfs

Vom Autor festgelegt:

- Benachrichtigung über die Glocke, Klick führt in die Einstellungen.
- Code nur auf Knopfdruck, Hinweis darauf beim Einrichten.
- Eingabe ganz oben auf der Übersicht (Variante A), der Kasten verschwindet nach Erfolg.
- Codefeld beim Einrichten an derselben Stelle im Schritt „Garmin“.
- Bei bekanntem Zwei-Faktor-Konto keine automatische Anmeldung mit Passwort im Hintergrund.
- Die Code-Eingabe ist auch für Authenticator-Apps ausgelegt.
- Umstellen von selbst und über „Neu anmelden“.

Eigene Entscheidungen, änderbar:

- Zwei-Faktor-Anmeldung nur über den Login-Weg der iOS-App (`MobileLogin`). Antwortet der zweite Weg
  (`WidgetLogin`) mit einem Code-Formular, bleibt es bei der heutigen Meldung. Grund: Die
  Bibliothek braucht dort einen eigenen Ablauf mit CSRF-Token und eigener Code-Anforderung, und der
  Login der iOS-App funktioniert auf der Instanz des Autors.
- Die Code-Anfrage gilt höchstens 30 Minuten.
- Fallback auf `/portal/api/mfa/verifyCode` wie in der Bibliothek.
- Neue Texte auf Englisch und Deutsch wie alle anderen.
- **Code anfordern** ist jedes Mal ein vollständiger Login mit Passwort und beginnt eine neue
  SSO-Sitzung, so wie in der Bibliothek. Einen eigenen Aufruf zum erneuten Senden gibt es auf dem
  Weg der iOS-App nicht. Schutz gegen Kontosperre: höchstens ein Anfordern pro Minute und fünf pro
  Stunde und Person; der Knopf zeigt die Wartezeit. Zeigt der RC-Test, dass Garmin beim Login keinen
  Code verschickt, wird der Weg zum Anfordern neu untersucht, bevor 1.1.0 erscheint.
- **„Neu anmelden“ mit `MFA_REQUIRED` bei noch gültigen Tokens:** Der Sync pausiert nicht und läuft
  mit den alten Tokens weiter. Der Kasten zeigt die Code-Eingabe. Läuft die Code-Anfrage ab oder
  bricht die Person ab, verschwindet der Kasten, weil nichts kaputt ist. Die Markierung bleibt.
- **Markierung und „Browser merken“:** Die Markierung wird nur gelöscht, wenn ein Login, den die
  Person selbst anstößt (Einrichten oder „Neu anmelden“), ohne Code `SUCCESSFUL` ist und dabei kein
  gemerktes Cookie mitgeschickt wurde. Ein Login, der nur dank des Cookies ohne Code durchgeht,
  löscht sie nicht. Bei markiertem Konto gibt es keinen Login mit Passwort im Hintergrund, auch
  nicht mit gemerktem Cookie (Festlegung des Autors: die App versucht es nie mehr selbst).
- **Code aus dem Versuch „von selbst“:** Die SSO-Sitzung dieses Versuchs wird als Code-Anfrage
  aufgehoben. Öffnet die Person die Einstellungen innerhalb der 30 Minuten, zeigt der Kasten gleich
  das Codefeld („Garmin hat dir beim letzten Versuch einen Code geschickt“) und „Neuen Code
  anfordern“. Danach zeigt er „Code anfordern“.
- **`MFA_REQUIRED` vom Login-Weg `WidgetLogin`:** setzt die Markierung und pausiert wie beim Weg der
  iOS-App. „Code anfordern“ nutzt immer `MobileLogin`. Scheitert das, zeigt der Kasten den Fehler.
- **Bestehende Installationen von 1.0.x**, die mit `pausedFor = MFA_REQUIRED` pausieren, gelten ohne
  Migration als markiert: Die Markierung wird beim Lesen auch aus diesem Zustand abgeleitet.

## Dem Builder überlassen

- Aufteilung auf Klassen, etwa ein eigener Dienst für die Code-Anfrage neben `AccountService`.
- Form des gespeicherten Zustands und Name der Markierung.
- Genaue Texte im Rahmen der Beschreibungen oben.
- Wie der Hinweiskasten in die vorhandenen Komponenten passt (`StatusHints`, `OverviewPage`).

## Tests

- Unit-Tests mit aufgezeichneten Antworten (wie die bestehenden Tests von `MobileLogin`): Login mit
  `MFA_REQUIRED` samt Weg (auch ein unbekannter Weg wie `totp`), Code richtig, Code falsch, Fallback-Endpunkt, Ablauf nach 30 Minuten,
  keine automatische Anmeldung bei markiertem Konto, Markierung löschen.
- Frontend-Tests für Codefeld im Assistenten, Hinweiskasten und „Neu anmelden“.
- Integrationstests in Nextcloud für die neuen Routen.
- Test am echten Konto mit `1.1.0-rc.1`:
  1. Zwei-Faktor-Anmeldung am Konto einschalten, „Neu anmelden“, Code eingeben.
  2. Gespeicherte Tokens auf der Instanz löschen (Passwort bleibt), Verhalten des nächsten Syncs
     prüfen: Pause und Benachrichtigung ohne Mail oder SMS.
  3. Variante A durchspielen: Code anfordern, falschen Code, richtigen Code.
  4. Beobachten, ob ein Login mit Passwort später ohne Code durchgeht („Browser merken“).
  5. Den Sync danach weiterlaufen lassen.
- 1.1.0 erscheint, wenn die Schritte 1 bis 3 funktionieren und der Sync danach zwei Wochen ohne
  Fehler läuft. Diese Schwelle ist ein Vorschlag, der Autor hat sie noch nicht ausdrücklich
  festgelegt.

## Begleitende Doku

- ADR 0008 für das Speichern der Garmin-Sitzung zwischen den zwei Schritten.
- F-07 in `anforderungen.md` auf „Muss“ für 1.1.0 und auf das neue Verhalten ändern.
- Notifier-Text für `MFA_REQUIRED` anpassen.
- README: Garmin-Konten mit Zwei-Faktor-Anmeldung aus „Limitations“ und „Requirements“ nehmen;
  info.xml-Beschreibung entsprechend.
