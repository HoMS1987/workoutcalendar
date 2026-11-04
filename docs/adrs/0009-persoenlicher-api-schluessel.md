# 9. Persönlicher API-Schlüssel von Garmin

Datum: 2026-11-04

## Status

Accepted

## Kontext

Garmin bietet seit Kurzem die Connect Personal API an. Jede Person erzeugt in Garmin Connect selbst
einen Schlüssel und tauscht ihn gegen ein Zugriffstoken. Bisher meldet sich die App über die
inoffiziellen Endpunkte der Garmin-Connect-App an ([ADR 0001](0001-garmin-datenzugriff-ueber-python-garminconnect.md),
[ADR 0003](0003-klassische-php-nextcloud-app.md)), mit E-Mail, Passwort und bei Bedarf Code
([ADR 0008](0008-garmin-sitzung-zwischen-zwei-anfragen.md)). Wiederholte Anmeldungen können das Konto
sperren, und der Code-Schritt ist umständlich. Mit dem Schlüssel entfallen Passwort, Code und
SSO-Anmeldung.

Grundlage sind die Seiten und die FAQ von Garmin sowie Garmins Client
`garmin/connect-personal-api-python`. Am echten Konto ist nichts davon geprüft.

## Entscheidung

- Der API-Schlüssel ist der empfohlene Weg, E-Mail und Passwort (mit Code) bleibt als Alternative.
  Der Assistent zeigt beide Wege als Reiter, der Schlüssel ist vorausgewählt.
- Pro Person ist genau ein Weg aktiv. Beim Speichern des einen werden die Daten des anderen gelöscht
  (Passwort, Tokens, SSO-Cookies, Code-Anfrage beziehungsweise Schlüssel).
- Der Schlüssel liegt verschlüsselt im `SecretStore` unter `api_key` (Schlüssel und Datum des
  Eintragens). Er erscheint nie im Log, im Status oder in einer Fehlermeldung. Der `Redactor`
  schwärzt ihn als bekannten Wert und `ApiKey`-Header wie `Bearer`.
- Der Tausch gegen ein Zugriffstoken (`ApiKeyTokenService`) geht über
  `POST https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token`
  mit `Authorization: ApiKey <Schlüssel>`. Ein Refresh-Token gibt es nicht. Ein `TokenProvider` je Weg
  (`ApiKeyTokenProvider`, `PasswordTokenProvider`) liefert dem `GarminClient` das Token. Der
  Schlüsselweg holt ein neues, sobald das alte in weniger als 3600 Sekunden abläuft.
- Als abgelehnter Schlüssel (`API_KEY_REJECTED`) gelten beim Tausch 401, 403 ohne Cloudflare-Challenge
  und 404 mit `"error":"NotFoundException"` (gelöschter Schlüssel). Das pausiert den
  Sync und benachrichtigt sofort. Alles andere wird wie bisher klassifiziert und pausiert nicht.
- `POST /api/apikey` richtet ein, stellt um und nimmt einen neuen Schlüssel nach Ablauf an. Der
  Schlüssel wird beim Eintragen sofort getauscht und erst danach gespeichert.
- Kontokennung: `accountKey` bleibt bei jedem Wechsel des Anmeldewegs gleich, damit die Termine
  der App ihrem Konto zugeordnet bleiben. Das Feld `SetupState::$identity` dient nur dazu, einen
  Kontowechsel zu erkennen. Es trägt ein Präfix: `email:` plus Hash der E-Mail, `garmin:` plus Hash
  der Garmin-Kennung oder `apikey:` ohne bekannte Kennung. Ein Kontowechsel liegt nur vor, wenn alte
  und neue `identity` dasselbe Präfix haben und sich unterscheiden. Bei einem anderen Präfix gilt das
  Konto als dasselbe. Ohne `identity` (Installationen vor 1.1.0) gilt `email:` plus der vorhandene
  `accountKey`.

Der Entwurf sah vor, beim Weg zurück zu E-Mail und Passwort `hash(E-Mail)` als neue `accountKey` zu
übernehmen. Das trägt nicht: `DavCalendarTarget::inRange` wertet für die Löschbehandlung nur
Termine mit der aktuellen `accountKey` aus, und unveränderte Termine werden nie neu geschrieben.
Eine geänderte `accountKey` würde die vorhandenen Termine aus der Löschbehandlung nehmen.

## Konsequenzen

- Kein Passwort, kein Code und keine SSO-Anmeldung mehr auf dem empfohlenen Weg, und kein Risiko,
  dass wiederholte Anmeldungen das Konto sperren.
- Der Schlüssel gilt höchstens ein Jahr. Danach braucht die Person einen neuen, den die App über
  Glocke und Hinweiskasten annimmt. Die App kennt das Ablaufdatum nicht, nur den Tag des Eintragens.
- Die Personal API ist laut Garmin-FAQ für die persönliche Nutzung gedacht. Jede Person trägt ihren
  eigenen Schlüssel für ihr eigenes Konto ein. Die Nutzungsbedingungen zeigt Garmin erst beim
  Erzeugen des Schlüssels, sie sind hier nicht geprüft.
- Der Weg E-Mail und Passwort bleibt inoffiziell und kann jederzeit brechen.
- Die Kontokennung ist ein Präfix-Vergleich. Ohne stabile Garmin-Kennung erkennt die App einen
  fremden Schlüssel nicht, dessen Aktivitäten landen dann im selben Kalender.

### Noch ungeprüft

Am 2026-11-04 mit `php-test/apikey_test.php` an einem echten Konto geprüft:

- Der Token-Endpunkt antwortet mit dem JWT als reinem Text, nicht mit JSON. `1.1.0-rc.2` hatte JSON
  erwartet und jede Anmeldung mit `PROTOCOL_CHANGED` abgelehnt.
- Das Token enthält `garmin_guid` (eine GUID). Die Kontokennung ist damit `garmin:`.
- Die Aktivitätsliste der Personal API liefert dieselben Felder wie die inoffizielle Schnittstelle,
  etwa `startTimeLocal` und `activityType.typeKey`.
- Ein ungültiger Schlüssel bekommt 401 mit `cf-ray` und `server: cloudflare`, aber ohne
  `cf-mitigated`. Deshalb zählt nur eine Cloudflare-Challenge als Sperre.
- Ein gelöschter Schlüssel bekommt beim Tausch 404 mit
  `{"message":"Personal API Key with ID … does not exist.","error":"NotFoundException"}`. Die ID ist
  nicht der Schlüssel. `1.1.0-rc.3` wertete das als `PROTOCOL_CHANGED`.
- Ein schon geholtes Zugriffstoken gilt nach dem Löschen weiter, anders als die FAQ sagt: Ein Sync
  direkt danach war erfolgreich. Die App bemerkt einen gelöschten Schlüssel deshalb erst beim
  nächsten Tausch, also bis zu 23 Stunden später.

Offen sind:

- Welchen Status Garmin bei einem abgelaufenen Schlüssel liefert. Angenommen sind 401, 403 oder 404
  wie beim ungültigen und beim gelöschten Schlüssel.
- Ob Garmin Rate-Limits setzt. In den Quellen steht dazu nichts.
- Die Nutzungsbedingungen von Garmin über die FAQ hinaus.
