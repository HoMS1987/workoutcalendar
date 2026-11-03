# Entwurf: Anmeldung mit dem persönlichen API-Schlüssel von Garmin

Stand: 2026-11-04. Ergänzt den Entwurf
[2026-11-03-garmin-2fa-design.md](2026-11-03-garmin-2fa-design.md) und F-07 aus
[anforderungen.md](../anforderungen.md). Grundlage sind die Seiten zur Garmin Connect Personal API
(developer.garmin.com/gc-developer-program/personal/, Hauptseite und FAQ) und Garmins eigener Client
`garmin/connect-personal-api-python` (veröffentlicht am 2026-10-20, Apache-2.0).

## Ziel und Hintergrund

Garmin bietet seit Kurzem einen persönlichen API-Schlüssel an. Jede Person erzeugt ihn selbst in
Garmin Connect und tauscht ihn gegen ein Zugriffstoken. Damit holt die App die Aktivitäten über eine
offizielle Schnittstelle, ohne Passwort, ohne Bestätigungscode und ohne das Risiko, dass wiederholte
Anmeldungen das Konto sperren.

Der API-Schlüssel wird der empfohlene Weg. Die bisherige Anmeldung mit E-Mail, Passwort und
gegebenenfalls Code (Version 1.0 und der 2FA-Entwurf) bleibt als Alternative.

Version: beide Funktionen erscheinen zusammen in 1.1.0. Getestet wird vorher als `1.1.0-rc.2` auf
der Instanz des Autors. `1.1.0-rc.1` enthält nur die Zwei-Faktor-Anmeldung.

## Ziele

- Einrichten mit API-Schlüssel im Schritt „Garmin“ des Assistenten, mit kurzer Anleitung und Link.
- Ein abgelaufener oder gelöschter Schlüssel pausiert den Sync, meldet sich über die Glocke, und ein
  Kasten oben auf der Übersicht nimmt den neuen Schlüssel an.
- Ein bestehendes Konto lässt sich über „Neu anmelden“ auf den Schlüssel umstellen, und umgekehrt.
- Wer keinen Schlüssel nutzen will, meldet sich wie bisher mit E-Mail und Passwort an.

## Nicht-Ziele

- Weitere Daten der Personal API (Schlaf, Schritte, Herzfrequenz-Verlauf usw.).
- Das Ablaufdatum des Schlüssels abfragen oder vorab warnen. Garmin liefert es in keiner Antwort,
  die App kennt nur den Tag, an dem der Schlüssel eingetragen wurde.
- Den Schlüssel in der App erzeugen. Das geht nur in Garmin Connect im Browser.

## Anti-Ziele

- Der Schlüssel landet unverschlüsselt in der Datenbank, im Log oder in einer Antwort an die Seite.
- Nach der Umstellung liegt noch ein Passwort gespeichert, das niemand mehr braucht.
- Die App fragt Garmin bei abgelehntem Schlüssel immer wieder an.

## Was Garmin bietet

Aus den Garmin-Seiten und Garmins Client, nicht am echten Konto geprüft:

1. Den Schlüssel erzeugt die Person in Garmin Connect unter
   `https://connect.garmin.com/app/settings/personalApiToken`: „Create Key“, Nutzungsbedingungen
   annehmen, Laufzeit wählen (30, 90 oder 180 Tage oder ein Jahr), „Create“, Schlüssel kopieren. Garmin
   zeigt ihn nur einmal. Eine Woche vor dem Ablauf schickt Garmin eine Mail. Ein gelöschter Schlüssel
   macht auch alle damit geholten Zugriffstokens ungültig.
2. Tausch gegen ein Zugriffstoken:
   `POST https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token`
   mit Header `Authorization: ApiKey <Schlüssel>` und `Accept: */*`. Die Antwort enthält
   `access_token`, ein JWT. Es gilt 24 Stunden (Ablauf im Claim `exp`) oder bis der Schlüssel abläuft.
   Ein Refresh-Token gibt es nicht. Garmins Client holt ein neues Token, sobald das alte in weniger als
   einer Stunde abläuft.
3. Aktivitäten:
   `GET https://personal.connectapi.garmin.com/activitylist-service/activities/search/activities` mit
   `Authorization: Bearer <access_token>` und den Parametern `start`, `limit`, `startDate`, `endDate`.
   Das ist derselbe Pfad, den die App heute über `connectapi.garmin.com` abfragt. Ob die Antwort gleich
   aussieht, ist nicht belegt.
4. Laut FAQ ist die API „for personal use“ und „not intended for commercial applications serving
   multiple end users“. In der App trägt jede Person ihren eigenen Schlüssel für ihr eigenes Konto ein.
   Die eigentlichen Nutzungsbedingungen zeigt Garmin erst beim Erzeugen des Schlüssels.
5. Zu Rate-Limits und zu den Antworten bei abgelaufenem Schlüssel steht nichts in den Quellen.

## Verhalten

### Einrichten (Variante A)

- Im Schritt „Garmin“ steht oben ein Umschalter mit zwei Reitern: „API-Schlüssel (empfohlen)“,
  vorausgewählt, und „E-Mail und Passwort“.
- Reiter „API-Schlüssel“:
  - Anleitung in drei Schritten:
    1. In Garmin Connect „Einstellungen → Personal API Key“ öffnen. Der Text ist ein Link auf die
       Seite aus „Was Garmin bietet“, Punkt 1, in einem neuen Tab.
    2. „Create Key“ wählen, die Nutzungsbedingungen lesen und annehmen.
    3. Als Laufzeit „1 Jahr“ wählen und den Schlüssel kopieren. Garmin zeigt ihn nur einmal.
  - Ein Feld zum Einfügen des Schlüssels.
  - Ein Hinweis: „Ein Schlüssel gilt höchstens ein Jahr. Läuft er ab, meldet sich Workout Calendar
    über die Glocke, und du trägst einen neuen ein.“
  - Der Knopf „Verbinden und weiter“ tauscht den Schlüssel sofort gegen ein Token. Klappt das, wird
    der Schlüssel gespeichert, und der Assistent geht zum Kalender weiter. Lehnt Garmin ab, bleibt das
    Feld stehen, mit einer Fehlermeldung.
- Reiter „E-Mail und Passwort“: das heutige Formular samt Code-Schritt aus dem 2FA-Entwurf,
  unverändert.

### Sync mit Schlüssel

- Die App holt mit dem gespeicherten Schlüssel ein Token und verwendet es, bis es in weniger als einer
  Stunde abläuft. Dann holt sie ein neues.
- Kein Passwort, kein Code, keine SSO-Anmeldung.
- Lehnt Garmin den Schlüssel beim Tausch ab, pausiert der Sync sofort und die Glocke meldet sich. Text
  sinngemäß: „Dein API-Schlüssel für Garmin ist abgelaufen oder wurde gelöscht. Workout Calendar ist
  pausiert, bis du einen neuen eingibst.“ Ein Klick führt in die Einstellungen.
- Lehnt Garmin nur das Token bei der Aktivitätsabfrage ab, holt die App einmal ein neues Token und
  versucht es erneut, wie heute beim Zugriffstoken.

### Abgelaufener Schlüssel

- Oben auf der Übersicht steht ein Kasten nach dem Muster des 2FA-Kastens: der Hinweis aus der
  Benachrichtigung, die Anleitung mit Link, ein Feld für den neuen Schlüssel und „Speichern“.
- Klappt der Tausch, ersetzt der neue Schlüssel den alten, Kasten und Benachrichtigung verschwinden,
  die Pause endet, und der nächste Sync läuft wie geplant.
- Der Kasten erscheint nur, solange der Sync wegen des Schlüssels pausiert.

### Umstellen über „Neu anmelden“

- „Neu anmelden“ im Abschnitt „Garmin-Konto“ öffnet dieselbe Ansicht wie der Schritt „Garmin“, mit
  beiden Reitern, „API-Schlüssel“ vorausgewählt.
- Mit Schlüssel: Nach erfolgreichem Tausch wird der Schlüssel gespeichert, und Passwort, Tokens,
  SSO-Cookies, eine laufende Code-Anfrage und die Markierung „nutzt Zwei-Faktor-Anmeldung“ werden
  gelöscht. Kalender, Einstellungen und Termine bleiben.
- Mit E-Mail und Passwort: Ablauf wie im 2FA-Entwurf. Nach Erfolg wird der Schlüssel gelöscht.
- Beim Umstellen gilt das Konto als dasselbe Garmin-Konto. Die vorhandenen Termine bleiben ihm
  zugeordnet. Ausnahme: Beim Weg zurück auf E-Mail und Passwort entscheidet wie bisher die E-Mail
  über einen Kontowechsel (siehe „Kontokennung“).
- Abbrechen lässt alles, wie es war.

### Anzeige des Kontos

- Mit Schlüssel zeigen Kachel und Abschnitt „Garmin-Konto“ „Verbunden mit API-Schlüssel“ statt der
  maskierten E-Mail, dazu „eingetragen am <Datum>“.

## Zustand und Speicherung

- Neuer geheimer Wert `api_key` im `SecretStore`: Schlüssel und Datum des Eintragens, verschlüsselt.
  Das Zugriffstoken liegt wie heute unter `tokens`.
- Pro Person ist genau ein Weg aktiv: Schlüssel oder E-Mail und Passwort. Die Speicherung erzwingt
  das: Beim Speichern des einen werden die Daten des anderen Wegs gelöscht.
- `clear()` beim Abmelden und Zurücksetzen löscht auch den Schlüssel.
- Der Schlüssel wird nie geloggt und nie an die Seite zurückgegeben. Der `Redactor` bekommt ihn als zu
  schwärzenden Wert, und `ApiKey`-Header werden geschwärzt wie `Bearer`.

### Kontokennung

Heute kennzeichnet `accountKey` die Termine der App und erkennt einen Kontowechsel. Er ist ein Hash
der E-Mail-Adresse. Mit Schlüssel kennt die App die E-Mail nicht.

- Beim Umstellen eines bestehenden Kontos auf den Schlüssel bleibt der vorhandene `accountKey`.
- Beim Einrichten mit Schlüssel ohne vorhandenen `accountKey` braucht die App eine stabile Kennung
  des Garmin-Kontos. Woher sie kommt, ist dem Builder überlassen: ein Claim im JWT des Zugriffstokens
  oder ein Feld aus `/userprofile-service/userprofile/settings`. Beides ist nicht belegt und wird im
  RC-Test geprüft. Gibt es keine stabile Kennung, bekommt die Person eine zufällige, die beim
  Schlüsselwechsel bleibt.
- Ein neuer Schlüssel für dasselbe Konto (Ablauf, Umstellung) behält den `accountKey`.
- Gibt es eine stabile Garmin-Kennung, speichert die App sie zusätzlich als `garminUserId`. Kommt ein
  neuer Schlüssel mit einer anderen Kennung, gilt das als Kontowechsel wie heute bei einer anderen
  E-Mail. Ohne stabile Kennung erkennt die App einen fremden Schlüssel nicht, dann landen dessen
  Aktivitäten im selben Kalender. Das ist hingenommen.
- Beim Weg vom Schlüssel zurück zu E-Mail und Passwort übernimmt die App `hash(E-Mail)` als neuen
  `accountKey`, ohne den Sync-Zustand zurückzusetzen, wenn der bisherige `accountKey` nicht aus einer
  E-Mail stammt. Die Termine bekommen ihre Markierung beim nächsten Schreiben neu. Stammt er aus einer
  E-Mail, entscheidet wie bisher der Vergleich.

### Verbunden ohne Passwort

- „Verbunden“ heißt künftig: Zugangsdaten **oder** Schlüssel gespeichert. Das gilt für Sync, Status,
  Wartungsjob und alle Stellen, die heute `SecretStore::credentials()` als Zeichen für eine Verbindung
  lesen.
- Mit Schlüssel ist `maskedEmail` im Status `null`. Die Seite entscheidet anhand des aktiven Wegs, was
  sie zeigt.

### Andere Fehler beim Tausch

- Nur 401 und 403 beim Tausch gelten als abgelehnter Schlüssel. 429, 5xx, Netzwerkfehler und andere
  Antworten behandelt der Sync wie heute als vorübergehend (`BLOCKED`, `SERVER_ERROR`, `NETWORK`,
  sonst `PROTOCOL_CHANGED`), ohne Pause. Im Assistenten erscheint die passende Meldung, und nichts
  wird gespeichert.

### Antwort der neuen Adresse

- Weicht die Aktivitätsliste der Personal API im RC-Test von der heutigen ab, wird der Mapper vor
  1.1.0 angepasst. Ein Rückfall auf die alte Adresse mit dem Token der Personal API ist nicht
  vorgesehen.

## Schnittstellen

- `POST /api/apikey` mit `apiKey`: Einrichten, Umstellen und neuer Schlüssel nach Ablauf. Tauscht ihn,
  speichert ihn und antwortet mit dem Status. Rate-Limit wie `/api/login`. Fehler: 422 mit
  `API_KEY_REJECTED`, wenn Garmin ablehnt.
- `GET /api/status` liefert zusätzlich den aktiven Weg (`apiKey` oder `password`) und das Datum des
  Eintragens. Der Schlüssel selbst nie.
- `POST /api/login` und die Routen aus dem 2FA-Entwurf bleiben für den Weg mit Passwort.
- Neue Fehlerkategorie `API_KEY_REJECTED` (pausiert und benachrichtigt sofort, wie
  `INVALID_CREDENTIALS`).

## Entscheidungen des Entwurfs

Vom Autor festgelegt:

- API-Schlüssel als empfohlener Weg, E-Mail und Passwort mit 2FA als Alternative.
- Anleitung mit Link und Hinweis auf die Laufzeit von höchstens einem Jahr direkt in der App.
- Abgelaufener Schlüssel: Benachrichtigung über die Glocke und Kasten mit Feld für den neuen
  Schlüssel.
- Umstellen über „Neu anmelden“, dort alle Wege, Schlüssel bevorzugt. Das Passwort wird bei der
  Umstellung gelöscht.
- Darstellung als Umschalter mit zwei Reitern (Variante A).
- Beide Funktionen kommen in 1.1.0, getestet als `1.1.0-rc.2`.

Eigene Entscheidungen, änderbar:

- Der Rückweg vom Schlüssel zu E-Mail und Passwort geht genauso und löscht den Schlüssel.
- Beim Umstellen gilt das Konto als dasselbe, die Termine bleiben.
- Empfohlene Laufzeit in der Anleitung: „1 Jahr“.
- Keine Warnung vor dem Ablauf, nur das Datum des Eintragens in der Übersicht.
- Ein neues Token, sobald das alte in weniger als einer Stunde abläuft, wie in Garmins Client.
- Der Assistent prüft den Schlüssel sofort beim Eintragen, nicht erst beim ersten Sync.
- Kontokennung, `garminUserId` und der Weg zurück zu E-Mail und Passwort wie unter „Kontokennung“.
- „Verbunden“ heißt Zugangsdaten oder Schlüssel. Mit Schlüssel ist `maskedEmail` leer.
- Nur 401 und 403 beim Tausch pausieren, alles andere gilt als vorübergehend.
- Weicht die Antwort der neuen Adresse ab, wird der Mapper angepasst, kein Rückfall auf die alte
  Adresse.

## Dem Builder überlassen

- Aufteilung auf Klassen. Naheliegend ist ein eigener Weg für Tokens neben `MobileLogin` und
  `DiTokenService`, den `GarminClient` statt der Anmeldung mit Passwort nutzt.
- Woher die Kontokennung beim Einrichten mit Schlüssel kommt (siehe „Kontokennung“).
- Wie der Umschalter in `StepGarmin` und die Ansicht hinter „Neu anmelden“ zusammenpassen.
- Genaue Texte im Rahmen der Beschreibungen oben.

## Tests

- Unit-Tests mit aufgezeichneten Antworten:
  - Tausch erfolgreich, Tausch abgelehnt (401 und 403).
  - Token läuft in weniger als einer Stunde ab und wird erneuert.
  - Aktivitätsabfrage über die neue Adresse.
  - Pause und Benachrichtigung bei abgelehntem Schlüssel.
  - Umstellen in beide Richtungen löscht die Daten des anderen Wegs.
  - Der Schlüssel taucht nie im Log, im Status oder in Fehlermeldungen auf.
- Frontend-Tests für Umschalter, Anleitung, Kasten bei abgelaufenem Schlüssel und Umstellen.
- Integrationstests für die neue Route.
- Test am echten Konto mit `1.1.0-rc.2`:
  1. Schlüssel erzeugen und über „Neu anmelden“ umstellen. Prüfen, dass das Passwort gelöscht ist und
     die Termine bleiben.
  2. Einen Sync abwarten und die Termine vergleichen. Prüfen, dass die Antwort der neuen Adresse
     passt.
  3. Den Schlüssel in Garmin Connect löschen. Prüfen, dass Pause, Benachrichtigung und Kasten
     erscheinen, dann einen neuen Schlüssel eintragen.
  4. Festhalten, welchen Status Garmin bei einem gelöschten Schlüssel liefert und woher die
     Kontokennung kommt.

## Begleitende Doku

- ADR 0009 für die Anmeldung mit API-Schlüssel neben der inoffiziellen Anmeldung.
- F-07 in `anforderungen.md`, `architecture.md` (Route, Schlüssel, Zustand), README (Requirements,
  Limitations, Installation), CHANGELOG.
- `info.xml`-Beschreibung für 1.1.0: beide Wege, API-Schlüssel empfohlen.
