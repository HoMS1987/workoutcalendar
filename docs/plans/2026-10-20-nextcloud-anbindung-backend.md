# Nextcloud-Anbindung, Teil A: Backend – Umsetzungsplan

Schritte nutzen Checkboxen (`- [ ]`) zum Abhaken.

**Ziel:** Die App läuft als Nextcloud-App: Sie speichert Zugangsdaten und Einstellungen pro Person
verschlüsselt, synchronisiert im Hintergrund in einen eigenen oder vorhandenen Kalender, meldet
Fehler per Benachrichtigung und bietet eine JSON-API, auf der die Oberfläche (Teil B) aufsetzt.

**Architektur:** Der Kern (`lib/Garmin`, `lib/Calendar`, `lib/Sync`) bleibt frei von
Nextcloud-Klassen und bekommt einige Erweiterungen. Die Anbindung liegt in `lib/AppInfo`,
`lib/Storage`, `lib/CalDav`, `lib/Service`, `lib/BackgroundJob`, `lib/Notification` und
`lib/Controller`. Nextcloud-Teile, die sich ohne Server nicht prüfen lassen, sind dünne Klassen
hinter eigenen Schnittstellen und werden in GitHub Actions gegen Nextcloud 33, 34 und 35 getestet.

**Technik:** PHP 8.2+, `ext-curl`, Nextcloud OCP 33 (`IUserConfig`, `ICrypto`, `TimedJob`,
`QueuedJob`, `INotifier`, `ILockingProvider`, `IL10N`), `OCA\DAV\CalDAV\CalDavBackend`, PHPUnit 11,
GitHub Actions, Renovate.

**Entwurf:** [docs/specs/2026-10-20-nextcloud-anbindung-design.md](../specs/2026-10-20-nextcloud-anbindung-design.md)

## Globale Vorgaben

- Nextcloud 33 bis 35, PHP ab 8.2 (NF-05). `info.xml` bleibt bei `min-version="33" max-version="35"`.
- Der Kern importiert keine Klasse aus `OCP\` oder `OCA\DAV\` (NF-04). Erlaubt bleibt nur PSR-3.
- Passwörter, Tokens und Tickets erscheinen nie im Log, in Fehlermeldungen, API-Antworten oder
  Benachrichtigungen (NF-01). Zugangsdaten und Tokens werden mit `ICrypto` verschlüsselt (NF-02).
- Nach abgelehntem Passwort oder verlangtem Code folgt kein weiterer Login-Versuch, bis die Person
  neue Zugangsdaten eingibt (NF-03).
- Die App ändert, verschiebt oder löscht nur Kalenderobjekte mit der URI `garmin-<activityId>.ics`
  und der UID `garmin-<activityId>`.
- Löschen von Terminen oder Kalendern nur auf ausdrückliche Anfrage über die API (Teil B fragt nach).
- Quelltexte für Übersetzungen sind Englisch, mitgeliefert wird Deutsch (`l10n/de.json`, `l10n/de.js`).
- Tests: neue Funktionen testgetrieben. Testnamen englisch in camelCase (`testXyz`), wie im Bestand.
- Commits: `[TYPE] Beschreibung` mit dem Ergebnis, Nachricht über `git commit -F <datei>`, ohne
  Co-Authored-By-Zeile und ohne KI-Hinweise. Lokale Git-Identität `HoMS1987` mit noreply-Adresse.
- Kalenderobjekte der App tragen `X-GARMIN-ACCOUNT:<accountKey>`,
  `accountKey = substr(hash('sha256', strtolower(trim($email))), 0, 16)`.
- App-Konfiguration pro Person liegt in `IUserConfig` unter der App-ID `workoutcalendar`.

## Schwerpunkte für Reviews

1. **Zwei Läufe gleichzeitig** (Cron und „Jetzt synchronisieren“) für dieselbe Person: Der zweite
   Lauf überspringt, statt doppelte Termine oder einen kaputten `SyncState` zu erzeugen. Test in
   Task 11.
2. **Kalender wurde außerhalb der App gelöscht** (z. B. in der Kalender-App): Der Sync meldet einen
   verständlichen Zustand (`calendarMissing`) und legt nichts woanders an. Test in Task 11.
3. **Anderes Garmin-Konto nach dem Abmelden:** Termine des alten Kontos werden im Löschabgleich nie
   markiert oder gelöscht. Test in Task 9 (Filter) und Task 13 (Zurücksetzen des Zustands).
4. **Entschlüsselung scheitert** (z. B. geänderter `secret` in `config.php`): Die App behandelt die
   Person als abgemeldet, statt bei jedem Cron-Lauf eine Ausnahme zu werfen. Test in Task 8.
5. **Kalender mit vielen fremden Terminen:** Umzug und Entfernen fassen nur Objekte mit
   `garmin-`-URI an, eigene Termine bleiben unberührt. Test in Task 9 und Task 12.

## Dateistruktur

| Datei | Aufgabe |
|---|---|
| `lib/Calendar/EventTexts.php` | Schnittstelle für Texte und Zahlenformat der Termine (Kern) |
| `lib/Calendar/EnglishEventTexts.php` | Standard ohne Übersetzung (Kern) |
| `lib/Sync/NotificationPolicy.php`, `lib/Sync/NotificationAction.php` | wann benachrichtigt bzw. zurückgezogen wird (Kern) |
| `lib/Garmin/Http/CurlHttpClient.php` | `HttpClient` über `ext-curl` mit Cookies je Instanz |
| `lib/AppInfo/Application.php` | `IBootstrap`, Registrierung von Diensten und Notifier |
| `lib/Storage/UserStore.php` | Einstellungen, `SyncState`, `SetupState`, `next_run_at` pro Person |
| `lib/Storage/SetupState.php`, `lib/Storage/SetupStep.php` | Stand der Einrichtung |
| `lib/Storage/SecretStore.php` | verschlüsselte Zugangsdaten und Tokens |
| `lib/Storage/UserTokenStore.php` | `TokenStore` für eine Person |
| `lib/CalDav/CalendarObjects.php` | schmale Schnittstelle auf ein Kalender-Backend |
| `lib/CalDav/DavCalendarObjects.php` | Umsetzung mit `CalDavBackend` (nur Integrationstest) |
| `lib/CalDav/DavCalendarTarget.php` | `CalendarTarget` für einen Kalender und ein Konto |
| `lib/CalDav/CalendarService.php` | eigene Kalender auflisten, anlegen, löschen, Termine zählen, verschieben, entfernen |
| `lib/Service/EmailMask.php` | `a***@example.org` |
| `lib/Service/GarminClientFactory.php` | baut `GarminClient` und `Authenticator` pro Person |
| `lib/Service/SyncRunner.php` | ein Lauf für eine Person: Sperre, Laden, `SyncService`, Speichern, Benachrichtigung |
| `lib/Service/AccountService.php` | anmelden, abmelden, zurücksetzen |
| `lib/Service/CalendarSetupService.php` | Kalender wählen und wechseln |
| `lib/Service/StatusService.php` | Überblick für die API |
| `lib/Service/L10nEventTexts.php` | `EventTexts` über `IL10N` |
| `lib/Notification/Notifier.php`, `lib/Notification/FailureNotifications.php` | Benachrichtigungen |
| `lib/BackgroundJob/SyncJob.php` | `TimedJob` alle 5 Minuten |
| `lib/BackgroundJob/CalendarMaintenanceJob.php` | `QueuedJob` für Umzug und Entfernen in Etappen |
| `lib/Controller/ApiController.php` | JSON-API für Teil B |
| `l10n/de.json`, `l10n/de.js` | deutsche Übersetzung |
| `tests/Integration/…`, `phpunit.integration.xml` | Tests gegen echte Nextcloud |
| `.github/workflows/tests.yml`, `renovate.json` | CI und Abhängigkeiten |
| `docs/adrs/0005-calendar-ueber-caldavbackend.md` | Entscheidung zu `CalDavBackend` |

### Task 1: Übersetzbare Termintexte im Kern

**Files:**
- Create: `lib/Calendar/EventTexts.php`, `lib/Calendar/EnglishEventTexts.php`, `tests/Support/GermanEventTexts.php`
- Modify: `lib/Calendar/ActivityEventMapper.php`
- Test: `tests/Unit/Calendar/ActivityEventMapperTest.php`, `tests/Unit/Calendar/EnglishEventTextsTest.php`

**Interfaces:**
- Produces:
  - `interface EventTexts { public function translate(string $text, array $parameters = []): string; public function number(float $value, int $decimals): string; }`.
    `translate` ersetzt `%s` in Reihenfolge wie `IL10N::t`.
  - `final class EnglishEventTexts implements EventTexts`: gibt den Quelltext zurück, Zahlen mit `.` als
    Dezimal- und `,` als Tausendertrennzeichen.
  - `ActivityEventMapper::__construct(EventTexts $texts = new EnglishEventTexts())`.
  - `tests/Support/GermanEventTexts`: Tabelle Englisch → Deutsch für alle Quelltexte, Zahlen mit `,`
    und `.`, damit die bisherigen Erwartungen gleich bleiben.

Quelltexte (Englisch, Deutsch wie bisher im Mapper): Sportarten aus `TYPES` (`Running` → `Laufen`,
`Trail running` → `Trailrunning`, …, `Rowing` → `Rudern`), `Duration: %s`, `Distance: %s`,
`Pace: %s min/km`, `Pace: %s min/100 m`, `Speed: avg %s km/h` → `Geschwindigkeit: Ø %s km/h`,
`Heart rate: avg %s bpm` → `Herzfrequenz: Ø %s bpm`, `, max %s bpm`, `Elevation gain: ↑ %s m` →
`Höhenmeter: ↑ %s m`. `Garmin Connect: <url>` und die Kategorie `Garmin` bleiben unübersetzt. Die
Einheiten `km`, `m`, `h`, `min` bleiben unübersetzt.

- [ ] **Step 1: Bestehende Mapper-Tests auf `new ActivityEventMapper(new GermanEventTexts())` umstellen**

Alle Erwartungen bleiben unverändert (z. B. `🏃 Laufen – 7,6 km`). Das ist eine Anpassung an die
neue Signatur, kein Ändern von Erwartungen.

- [ ] **Step 2: Neue Tests schreiben**

```php
public function testEnglishTextsAreDefault(): void
{
    $event = (new ActivityEventMapper())->map(self::run());   // 7.6 km Lauf aus dem Bestand
    self::assertSame('🏃 Running – 7.6 km', $event->summary);
    self::assertStringContainsString('Duration: ', $event->description);
}

public function testThousandsSeparatorFollowsTexts(): void   // EnglishEventTextsTest
{
    self::assertSame('1,234.5', (new EnglishEventTexts())->number(1234.5, 1));
}
```

- [ ] **Step 3: Tests laufen lassen**

Run: `vendor/bin/phpunit tests/Unit/Calendar`
Expected: FAIL, `EventTexts` bzw. `EnglishEventTexts` nicht gefunden.

- [ ] **Step 4: `EventTexts`, `EnglishEventTexts`, `GermanEventTexts` anlegen und den Mapper umstellen**

`TYPES` enthält danach englische Namen. Alle festen Texte und `number_format` laufen über `$texts`.

- [ ] **Step 5: Alle Tests laufen lassen**

Run: `composer test`
Expected: PASS (159 bisherige plus neue Tests).

- [ ] **Step 6: Commit**

`[FEATURE] Termintexte sind übersetzbar, der Kern liefert Englisch als Quelltext`

### Task 2: Pause nach abgelehntem Passwort, schneller Historien-Import, ältestes Datum

**Files:**
- Modify: `lib/Sync/SyncState.php`, `lib/Sync/SyncService.php`, `lib/Sync/SyncSettings.php`, `lib/Sync/FailureLog.php`
- Test: `tests/Unit/Sync/SyncServiceTest.php`, `tests/Unit/Sync/SyncStateTest.php`, `tests/Unit/Sync/FailureLogTest.php`

**Interfaces:**
- Produces:
  - `SyncState::$pausedFor` (`?string`, Wert einer `FailureCategory`), `SyncState::$oldestActivityDate`
    (`?string`, `Y-m-d`, lokales Startdatum), `SyncState::$notifiedCategory` (`?string`). Alle drei in
    `fromArray`/`toArray`.
  - `SyncSettings::BACKFILL_NEXT_RUN_SECONDS = 60`.
  - `FailureLog::recordSuccess` lässt `notifiedCategory` unverändert (die Benachrichtigung zieht
    Task 11 zurück).

- [ ] **Step 1: Failing Tests schreiben**

```php
public function testInvalidCredentialsPauseTheSync(): void
// Login liefert INVALID_CREDENTIALS → $state->pausedFor === 'INVALID_CREDENTIALS'

public function testMfaRequiredPausesTheSync(): void
// → $state->pausedFor === 'MFA_REQUIRED'

public function testNetworkErrorDoesNotPause(): void
// → $state->pausedFor === null

public function testRunningBackfillSchedulesNextRunSoon(): void
// Historie nicht fertig nach dem Lauf → $state->nextRunAt === NOW + 60

public function testFinishedBackfillUsesInterval(): void
// Historie fertig → nextRunAt liegt zwischen NOW + 1440 s und NOW + 2160 s (30 min ± 20 %)

public function testOldestActivityDateTracksImportedActivities(): void
// Aktivitäten vom 2026-10-01 und 2026-09-15 → $state->oldestActivityDate === '2026-09-15';
// ein späterer Lauf mit 2026-10-18 lässt '2026-09-15' stehen

public function testStateRoundTripKeepsNewFields(): void   // SyncStateTest
public function testSuccessKeepsNotifiedCategory(): void   // FailureLogTest
```

- [ ] **Step 2: Tests laufen lassen**

Run: `vendor/bin/phpunit tests/Unit/Sync`
Expected: FAIL bei den neuen Tests.

- [ ] **Step 3: Umsetzen**

`SyncService::fail` setzt `pausedFor` für `InvalidCredentials` und `MfaRequired`. `run` setzt
`nextRunAt = now + BACKFILL_NEXT_RUN_SECONDS`, wenn nach `continueBackfill` `backfillDone` falsch
ist. `upsert` führt `oldestActivityDate` als Minimum über `localDate`. `SyncService::run` prüft
`pausedFor` nicht selbst, das übernimmt `SyncRunner` (Task 11).

- [ ] **Step 4: Tests laufen lassen**

Run: `composer test`
Expected: PASS.

- [ ] **Step 5: Commit**

`[FEATURE] Sync pausiert nach abgelehntem Passwort, Historien-Import läuft bei jedem Cron-Lauf weiter`

### Task 3: Historien-Zeitraum nachträglich erweitern

**Files:**
- Modify: `lib/Sync/SyncService.php`
- Test: `tests/Unit/Sync/SyncServiceTest.php`

**Interfaces:**
- Produces: `SyncService::changeHistory(SyncState $state, ?int $historyDays, \DateTimeImmutable $now): void`
  (`null` = komplette Historie). Ohne `initialized` tut sie nichts. Ein kürzerer Zeitraum ändert nichts.
  Ein längerer setzt `backfillUntil` auf das neue Datum bzw. `null` und `backfillDone = false`, es sei
  denn, das neue Datum liegt nicht vor `backfillBefore`. Dann setzt sie `nextRunAt = now + 60`.

- [ ] **Step 1: Failing Tests schreiben**

```php
public function testLongerHistoryContinuesBackfill(): void
// Import 90 Tage fertig (backfillDone, backfillUntil 2026-07-22) → changeHistory(365)
// → backfillUntil === '2025-10-20', backfillDone === false; nächster run() fragt
//   startDate=2025-10-20 und endDate=<backfillBefore> ab

public function testFullHistoryClearsLowerBound(): void
// changeHistory(null) → backfillUntil === null, backfillDone === false

public function testShorterHistoryChangesNothing(): void
// Import 365 Tage → changeHistory(30) → Zustand unverändert, kein Termin gelöscht

public function testHistoryChangeBeforeFirstRunIsIgnored(): void
```

- [ ] **Step 2: Test laufen lassen** – Run: `vendor/bin/phpunit tests/Unit/Sync/SyncServiceTest.php` – Expected: FAIL.
- [ ] **Step 3: `changeHistory` umsetzen**
- [ ] **Step 4: Tests laufen lassen** – Run: `composer test` – Expected: PASS.
- [ ] **Step 5: Commit**

`[FEATURE] Ein längerer Historien-Zeitraum importiert den Rest nach, ein kürzerer löscht nichts`

### Task 4: Regel für Benachrichtigungen

**Files:**
- Create: `lib/Sync/NotificationPolicy.php`, `lib/Sync/NotificationAction.php`
- Test: `tests/Unit/Sync/NotificationPolicyTest.php`

**Interfaces:**
- Produces:
  - `enum NotificationAction { case None; case Send; case Dismiss; }`
  - `final class NotificationPolicy { public const DELAY_SECONDS = 86_400; public function decide(SyncState $state, int $now): NotificationAction; }`
  - Sofort: `INVALID_CREDENTIALS`, `MFA_REQUIRED`. Nach `DELAY_SECONDS` seit `failureSince`: alle
    anderen Kategorien. `Send` nur, wenn `notifiedCategory !== failureCategory`. `Dismiss`, wenn kein
    Fehler vorliegt und `notifiedCategory !== null`. Der Aufrufer setzt `notifiedCategory` danach.

- [ ] **Step 1: Failing Tests schreiben**

```php
public function testInvalidCredentialsNotifyImmediately(): void      // → Send
public function testBlockedNotifiesAfterOneDay(): void              // since=NOW-86399 → None; NOW-86400 → Send
public function testProtocolChangedNotifiesAfterOneDay(): void
public function testSameFailureIsNotSentTwice(): void               // notifiedCategory == failureCategory → None
public function testChangedCategorySendsAgain(): void               // notified NETWORK, jetzt INVALID_CREDENTIALS → Send
public function testRecoveryDismisses(): void                       // kein Fehler, notifiedCategory gesetzt → Dismiss
public function testNoFailureNoNotification(): void                 // → None
```

- [ ] **Step 2: Tests laufen lassen** – Run: `vendor/bin/phpunit tests/Unit/Sync/NotificationPolicyTest.php` – Expected: FAIL.
- [ ] **Step 3: Umsetzen**
- [ ] **Step 4: Tests laufen lassen** – Run: `composer test` – Expected: PASS.
- [ ] **Step 5: Commit**

`[FEATURE] Benachrichtigung sofort bei abgelehntem Passwort, bei anderen Fehlern erst nach 24 Stunden`

### Task 5: Garmin-Konto im Termin vermerken

**Files:**
- Modify: `lib/Calendar/IcsSerializer.php`, `lib/Calendar/IcsReader.php`
- Test: `tests/Unit/Calendar/IcsSerializerTest.php`, `tests/Unit/Calendar/IcsReaderTest.php`

**Interfaces:**
- Produces:
  - `IcsSerializer::serialize(CalendarEvent $event, \DateTimeImmutable $now, ?string $accountKey = null): string`
    schreibt bei gesetztem Schlüssel `X-GARMIN-ACCOUNT:<accountKey>` direkt nach `X-GARMIN-FINGERPRINT`.
  - `IcsReader::accountKey(string $ics): ?string`.
  - Der Fingerabdruck ändert sich dadurch nicht.

- [ ] **Step 1: Failing Tests schreiben**

```php
public function testAccountKeyIsWritten(): void      // enthält "X-GARMIN-ACCOUNT:0123456789abcdef\r\n"
public function testNoAccountKeyNoLine(): void       // ohne Schlüssel keine X-GARMIN-ACCOUNT-Zeile
public function testReaderReturnsAccountKey(): void  // round trip
public function testReaderWithoutAccountKey(): void  // → null
```

- [ ] **Step 2–4:** Tests rot, umsetzen, `composer test` grün.
- [ ] **Step 5: Commit**

`[FEATURE] Termine vermerken das Garmin-Konto, damit ein Kontowechsel fremde Termine nicht anfasst`

### Task 6: `CurlHttpClient`

**Files:**
- Create: `lib/Garmin/Http/CurlHttpClient.php`, `tests/Support/http-fixture.php` (Router für `php -S`)
- Test: `tests/Unit/Garmin/Http/CurlHttpClientTest.php`

**Interfaces:**
- Produces: `final class CurlHttpClient implements HttpClient { public function __construct(int $timeoutSeconds = 30) }`.
  Ein Handle pro Instanz mit `CURLOPT_COOKIEFILE = ''` (Cookies nur im Speicher), Redirects folgen
  (`CURLOPT_FOLLOWLOCATION`, höchstens 5), `effectiveUrl` aus `CURLINFO_EFFECTIVE_URL`. Header als
  `array<string, string>` (letzter Wert gewinnt). curl-Fehler → `NetworkException` mit curl-Meldung.
  `GarminClientFactory` (Task 11) erzeugt pro Lauf eine neue Instanz, damit Cookies nie zwischen
  Personen geteilt werden.

Der Test startet `php -S 127.0.0.1:<freier Port> tests/Support/http-fixture.php` in
`setUpBeforeClass` über `proc_open` und beendet ihn in `tearDownAfterClass`. Der Router kennt
`/set-cookie`, `/echo` (gibt Methode, Header und Body als JSON zurück), `/redirect` → `/echo` und
`/status/503`.

- [ ] **Step 1: Failing Tests schreiben**

```php
public function testPostSendsBodyAndHeaders(): void      // /echo: method POST, body 'a=1', Header X-Test
public function testCookiesAreKeptWithinInstance(): void // /set-cookie, dann /echo enthält Cookie
public function testCookiesAreNotSharedBetweenInstances(): void
public function testRedirectIsFollowed(): void           // effectiveUrl endet auf /echo
public function testStatusIsReturnedNotThrown(): void    // /status/503 → status 503
public function testConnectionErrorThrowsNetworkException(): void // Port ohne Server
```

- [ ] **Step 2–4:** rot, umsetzen, `vendor/bin/phpunit tests/Unit/Garmin/Http` grün.
- [ ] **Step 5: Commit**

`[FEATURE] CurlHttpClient für den Garmin-Zugriff mit Cookies je Instanz`

### Task 7: App-Gerüst, Integrationstests und CI

**Files:**
- Create: `lib/AppInfo/Application.php`, `phpunit.integration.xml`, `tests/Integration/bootstrap.php`,
  `tests/Integration/AppBootTest.php`, `.github/workflows/tests.yml`, `renovate.json`
- Modify: `appinfo/info.xml`, `composer.json`, `README.md` (Abschnitt Tests)

**Interfaces:**
- Produces:
  - `Application extends App implements IBootstrap`, `APP_ID = 'workoutcalendar'`. `register()`
    registriert später Notifier und Dienste, `boot()` bleibt leer.
  - `composer test` = Unit-Tests wie bisher. `composer test:integration` =
    `phpunit -c phpunit.integration.xml`.
  - `tests/Integration/bootstrap.php`: lädt `../../../lib/base.php` (die App liegt in
    `server/apps/workoutcalendar`), danach `vendor/autoload.php` und `\OC_App::loadApp('workoutcalendar')`.

`info.xml` bekommt den Hintergrundjob erst mit Task 12.

Workflow `tests.yml`, ausgelöst bei Push und Pull Request:
- Job `unit`: Matrix PHP 8.2, 8.3, 8.4, 8.5, `composer install`, `composer test`.
- Job `integration`: Matrix `{server: stable33, php: 8.2}`, `{server: stable34, php: 8.4}`,
  `{server: stable35, php: 8.5}`. Server mit Submodulen nach `server/` auschecken, App nach
  `server/apps/workoutcalendar`, `php occ maintenance:install --database sqlite --admin-user admin
  --admin-pass admin`, `php occ app:enable workoutcalendar`, `composer install` in der App,
  `composer test:integration`.
- Actions mit fester Version (Tag), Renovate hält sie aktuell.

`renovate.json`:
- `extends: ["config:recommended"]`.
- `packageRules`: `nextcloud/ocp` → `"enabled": false` (bleibt auf 33). `@nextcloud/vue` und `vue` mit
  `matchUpdateTypes: ["major"]` → `"automerge": false`, `"dependencyDashboardApproval": true`.
- Gruppe `github-actions` für alle Actions.

- [ ] **Step 1: Failing Integrationstest schreiben**

```php
public function testAppIsEnabledAndBootstrapResolves(): void
{
    self::assertTrue(\OCP\Server::get(\OCP\App\IAppManager::class)->isEnabledForUser('workoutcalendar'));
    self::assertInstanceOf(Application::class, \OCP\Server::get(Application::class));
}
```

- [ ] **Step 2: Workflow und Gerüst anlegen, auf `dev` pushen**

Run: `git push origin dev`, danach `gh run watch`.
Expected: `unit` grün in allen vier PHP-Versionen, `integration` grün für stable33, stable34 und
stable35.

- [ ] **Step 3: Commit (vor dem Push)**

`[CI] Unit-Tests für PHP 8.2 bis 8.5 und Integrationstests gegen Nextcloud 33 bis 35, Renovate für Abhängigkeiten`

### Task 8: Speicher pro Person

**Files:**
- Create: `lib/Storage/SetupStep.php`, `lib/Storage/SetupState.php`, `lib/Storage/UserStore.php`,
  `lib/Storage/SecretStore.php`, `lib/Storage/UserTokenStore.php`, `lib/Service/EmailMask.php`
- Test: `tests/Unit/Storage/UserStoreTest.php`, `tests/Unit/Storage/SecretStoreTest.php`,
  `tests/Unit/Service/EmailMaskTest.php`, `tests/Integration/Storage/StorageRoundTripTest.php`

**Interfaces:**
- Consumes: `SyncSettings::fromArray`, `SyncState::fromArray/toArray`, `GarminTokens::fromArray/toArray`, `Credentials`.
- Produces:
  - `enum SetupStep: string { case Garmin = 'garmin'; case Calendar = 'calendar'; case History = 'history'; case Done = 'done'; }`
  - `final class SetupState` mit `SetupStep $step = SetupStep::Garmin`, `bool $connected = false`,
    `?string $accountKey`, `?string $maskedEmail`, `?int $calendarId`, `bool $calendarCreatedByApp = false`,
    `?string $maintenance` (`'move'|'remove'|null`), dazu `fromArray`/`toArray`.
  - `final class UserStore`, Konstruktor `(IUserConfig $config)`, Schlüssel `settings`, `state`, `setup`
    (JSON) und `next_run_at` (int):
    `settings(string $uid): SyncSettings`, `saveSettings(string $uid, SyncSettings $s): void`,
    `state(string $uid): SyncState`, `saveState(string $uid, SyncState $s): void` (schreibt
    `next_run_at` mit), `setup(string $uid): SetupState`, `saveSetup(string $uid, SetupState $s): void`,
    `dueUsers(int $now, int $limit): list<string>` (aus `getValuesByUsers`, `next_run_at <= now`,
    älteste zuerst), `clear(string $uid): void` (alle Schlüssel der App für die Person).
    `SyncSettings` braucht dafür `toArray(): array`.
  - `final class SecretStore`, Konstruktor `(IUserConfig $config, ICrypto $crypto, LoggerInterface $logger)`,
    Schlüssel `credentials` und `tokens`:
    `credentials(string $uid): ?Credentials`, `saveCredentials(string $uid, Credentials $c): void`,
    `tokens(string $uid): ?GarminTokens`, `saveTokens(string $uid, GarminTokens $t): void`,
    `clear(string $uid): void`. Scheitert `decrypt`, liefert die Methode `null` und loggt eine Warnung
    ohne Inhalt.
  - `final class UserTokenStore implements TokenStore`, Konstruktor `(SecretStore $secrets, string $uid)`.
  - `EmailMask::mask(string $email): string`.

- [ ] **Step 1: Failing Unit-Tests schreiben** (mit PHPUnit-Mocks für `IUserConfig` und `ICrypto`)

```php
public function testDefaultsWhenNothingStored(): void          // settings() == new SyncSettings()
public function testStateRoundTripWritesNextRunAt(): void       // setValueInt('…','next_run_at', 1234)
public function testDueUsersAreOldestFirstAndLimited(): void    // {a:300,b:100,c:900}, now=500, limit=1 → ['b']
public function testSecretsAreStoredEncrypted(): void           // gespeicherter Wert enthält das Passwort nicht
public function testUndecryptableSecretsReadAsMissing(): void   // decrypt wirft → credentials() === null, Warnung ohne Wert
public function testMaskKeepsFirstLetterAndDomain(): void       // 'anna@example.org' → 'a***@example.org'
public function testMaskWithoutAt(): void                        // 'anna' → '***'
```

- [ ] **Step 2: Integrationstest schreiben**

```php
public function testSecretsSurviveRealConfigAndCrypto(): void
// echte IUserConfig/ICrypto: saveCredentials → credentials() liefert dieselben Werte;
// Rohwert in IUserConfig enthält weder E-Mail noch Passwort
```

- [ ] **Step 3: Tests laufen lassen** – Run: `composer test` – Expected: FAIL.
- [ ] **Step 4: Umsetzen**
- [ ] **Step 5: Tests laufen lassen** – Run: `composer test` lokal, Integration in CI – Expected: PASS.
- [ ] **Step 6: Commit**

`[FEATURE] Einstellungen, Sync-Zustand und verschlüsselte Zugangsdaten pro Person`

### Task 9: Kalender über `CalDavBackend`

**Files:**
- Create: `lib/CalDav/CalendarObjects.php`, `lib/CalDav/DavCalendarObjects.php`,
  `lib/CalDav/DavCalendarTarget.php`, `lib/CalDav/CalendarService.php`, `lib/CalDav/CalendarInfo.php`,
  `tests/Support/MemoryCalendarObjects.php`, `docs/adrs/0005-calendar-ueber-caldavbackend.md`
- Modify: `docs/architecture.md` (Modultabelle), `docs/adrs/0003-klassische-php-nextcloud-app.md` (Verweis auf 0005)
- Test: `tests/Unit/CalDav/DavCalendarTargetTest.php`, `tests/Unit/CalDav/CalendarServiceTest.php`,
  `tests/Integration/CalDav/DavCalendarObjectsTest.php`

**Interfaces:**
- Produces:
  - `final class CalendarInfo { int $id; string $uri; string $name; ?string $color; }`
  - `interface CalendarObjects`:
    `ownCalendars(string $uid): list<CalendarInfo>` (nur `principals/users/<uid>`, nicht im Papierkorb,
    mit VEVENT),
    `createCalendar(string $uid, string $uri, string $name): int`,
    `deleteCalendar(int $calendarId): void` (in den Papierkorb, `forceDeletePermanently = false`),
    `uris(int $calendarId): list<string>`,
    `urisInRange(int $calendarId, \DateTimeImmutable $from, \DateTimeImmutable $to): list<string>`
    (`calendarQuery` mit VEVENT-Zeitbereich),
    `get(int $calendarId, array $uris): array<string, string>` (URI → ICS),
    `create(int $calendarId, string $uri, string $ics): void`,
    `update(int $calendarId, string $uri, string $ics): void`,
    `delete(int $calendarId, string $uri): void`.
  - `DavCalendarObjects implements CalendarObjects`, Konstruktor `(CalDavBackend $backend)`. Die
    einzige Klasse mit `OCA\DAV`.
  - `DavCalendarTarget implements CalendarTarget`, Konstruktor
    `(CalendarObjects $objects, int $calendarId, string $accountKey, IcsSerializer $serializer, IcsReader $reader, DeletedMarker $marker, \Closure $now)`.
    URI `garmin-<activityId>.ics`. `inRange` liefert nur Objekte mit `garmin-`-URI und passendem
    `X-GARMIN-ACCOUNT`.
  - `CalendarService`, Konstruktor `(CalendarObjects $objects)`:
    `ownCalendars(string $uid): list<CalendarInfo>`,
    `findByName(string $uid, string $name): ?CalendarInfo` (getrimmt, ohne Groß/klein),
    `createCalendar(string $uid, string $name): CalendarInfo` (URI `workoutcalendar`, bei Belegung `workoutcalendar-2`, …),
    `deleteCalendar(int $calendarId): void`,
    `countAll(int $calendarId): int`, `countAppEvents(int $calendarId): int`,
    `moveAppEvents(int $from, int $to, int $limit): int` (Rest),
    `removeAppEvents(int $calendarId, int $limit): int` (Rest).
    Beim Verschieben gilt: Gibt es die URI im Ziel schon, wird nur die Quelle gelöscht.

- [ ] **Step 1: Failing Unit-Tests mit `MemoryCalendarObjects` schreiben**

```php
// DavCalendarTargetTest
public function testCreateWritesUriUidAndAccount(): void       // URI 'garmin-42.ics', X-GARMIN-ACCOUNT gesetzt
public function testFindReturnsFingerprintAndMark(): void
public function testInRangeIgnoresForeignEvents(): void        // eigener Termin ohne garmin-URI fehlt
public function testInRangeIgnoresOtherAccount(): void         // garmin-7.ics mit anderem Konto fehlt
public function testMarkDeletedAndDelete(): void

// CalendarServiceTest
public function testFindByNameIgnoresCaseAndSpaces(): void     // ' garmin-aktivitäten ' findet 'Garmin-Aktivitäten'
public function testCreateCalendarPicksFreeUri(): void         // 'workoutcalendar' belegt → 'workoutcalendar-2'
public function testCountAppEventsSkipsForeignEvents(): void   // 3 garmin-, 2 fremde → 3; countAll → 5
public function testMoveKeepsForeignEventsAndReportsRest(): void // 5 garmin-, limit 2 → Rückgabe 3, fremde bleiben im alten Kalender
public function testMoveDropsSourceWhenTargetHasUri(): void
public function testRemoveOnlyTouchesAppEvents(): void
```

- [ ] **Step 2: Failing Integrationstest schreiben**

```php
public function testRoundTripAgainstRealBackend(): void
// Testperson anlegen, createCalendar, create/get/update/urisInRange/delete, ownCalendars enthält
// den Kalender, ein mit der Person geteilter fremder Kalender fehlt in ownCalendars, deleteCalendar
```

- [ ] **Step 3: Tests laufen lassen** – Run: `composer test` – Expected: FAIL.
- [ ] **Step 4: Umsetzen**
- [ ] **Step 5: ADR 0005 schreiben**

Status Accepted. Kontext: `OCP\Calendar` 33 kann weder Kalender anlegen noch Termine oder Kalender
löschen. Entscheidung: `CalDavBackend` hinter `CalendarObjects`, wie `integration_google`
(`lib/Service/GoogleCalendarAPIService.php`, geprüft am 2026-10-20). Konsequenzen: keine stabile
Schnittstelle, Integrationstests gegen 33 bis 35 fangen Änderungen ab, nur `DavCalendarObjects` ist
betroffen.

- [ ] **Step 6: Tests laufen lassen** – lokal `composer test`, Integration in CI – Expected: PASS.
- [ ] **Step 7: Commit**

`[FEATURE] Kalender und Termine über CalDavBackend, nur Objekte der App werden angefasst`

### Task 10: Benachrichtigungen und deutsche Übersetzung

**Files:**
- Create: `lib/Notification/Notifier.php`, `lib/Notification/FailureNotifications.php`, `l10n/de.json`, `l10n/de.js`
- Modify: `lib/AppInfo/Application.php` (`registerNotifierService(Notifier::class)`)
- Test: `tests/Unit/Notification/NotifierTest.php`, `tests/Integration/Notification/FailureNotificationsTest.php`

**Interfaces:**
- Produces:
  - `FailureNotifications`, Konstruktor `(INotificationManager $manager, ITimeFactory $time)`:
    `send(string $uid, string $category): void` (zieht vorher eine alte zurück),
    `dismiss(string $uid): void`. App `workoutcalendar`, Objekt `sync`/`$uid`, Subject `sync_failed`,
    Parameter `['category' => $category]`.
  - `Notifier implements INotifier`, `getID() = 'workoutcalendar'`, `prepare()` setzt Text und Link
    `settings.PersonalSettings.index` mit `section = workoutcalendar`. Texte:

| Kategorie | Englisch (Quelltext) |
|---|---|
| `INVALID_CREDENTIALS` | `Garmin rejected your email address or password. Workout Calendar is paused until you sign in again.` |
| `MFA_REQUIRED` | `Your Garmin account asks for a verification code. This is not supported yet, Workout Calendar is paused.` |
| `PROTOCOL_CHANGED` | `Workout Calendar could not sync for a day because Garmin changed its sign-in. An app update may be needed.` |
| sonst | `Workout Calendar could not reach Garmin for a day. It keeps trying.` |

  Titel: `Workout Calendar needs your attention`. Deutsche Texte in `l10n/de.json` sinngemäß wie im
  Entwurf. `de.json` enthält außerdem alle Quelltexte aus Task 1.

- [ ] **Step 1: Failing Tests schreiben**

```php
public function testInvalidCredentialsText(): void       // de: Subject enthält 'Passwort'
public function testLinkPointsToSettingsSection(): void
public function testUnknownSubjectThrowsUnknownNotification(): void
// Integration:
public function testSendReplacesAndDismissRemoves(): void // zweimal send → eine Benachrichtigung; dismiss → keine
```

- [ ] **Step 2–4:** rot, umsetzen, `composer test` lokal und Integration in CI grün.
- [ ] **Step 5: Commit**

`[FEATURE] Benachrichtigung bei Sync-Fehlern, Termine und Meldungen auf Deutsch`

### Task 11: Ein Sync-Lauf für eine Person

**Files:**
- Create: `lib/Service/HttpClientProvider.php`, `lib/Service/CurlHttpClientProvider.php`,
  `lib/Service/GarminClientFactory.php`, `lib/Service/SyncRunner.php`, `lib/Service/RunOutcome.php`,
  `lib/Service/L10nEventTexts.php`
- Test: `tests/Unit/Service/SyncRunnerTest.php`

**Interfaces:**
- Consumes: `UserStore`, `SecretStore`, `UserTokenStore`, `CalendarService`, `DavCalendarTarget`,
  `SyncService::run`, `NotificationPolicy`, `FailureNotifications` (Task 10).
- Produces:
  - `interface HttpClientProvider { public function create(): HttpClient; }` mit
    `CurlHttpClientProvider` als Standard, in `Application` registriert. Integrationstests ersetzen
    ihn im Container durch einen Fake, damit sie nie mit Garmin sprechen.
  - `GarminClientFactory`, Konstruktor `(HttpClientProvider $http, SecretStore $secrets, LoggerInterface $logger)`:
    `client(string $uid, Credentials $c): GarminClient` und `authenticator(): Authenticator`.
    Strategien `MobileLogin`, dann `WidgetLogin`, HTTP über `LoggingHttpClient($http->create())`,
    Tokens über `UserTokenStore`.
  - `enum RunOutcome: string { case Done = 'done'; case Skipped = 'skipped'; case Paused = 'paused'; case NotConnected = 'notConnected'; case CalendarMissing = 'calendarMissing'; }`
  - `SyncRunner::run(string $uid, \DateTimeImmutable $now): RunOutcome`:
    Sperre `ILockingProvider::acquireLock('workoutcalendar/sync/' . $uid, LOCK_EXCLUSIVE)`, bei
    `LockedException` → `Skipped`. Ohne Zugangsdaten → `NotConnected`. `pausedFor` gesetzt → `Paused`.
    Kalender fehlt in `ownCalendars` → `CalendarMissing`, `SetupState::calendarId = null`, `step = Calendar`.
    Sonst `SyncService::run`, `saveState`, danach `NotificationPolicy::decide` und Senden bzw.
    Zurückziehen, `notifiedCategory` setzen und erneut speichern. Sperre in `finally` freigeben.
  - `L10nEventTexts implements EventTexts`, Konstruktor `(IL10N $l)`, Zahlen mit dem
    Dezimaltrennzeichen der Sprache (`de` → `,` und `.`, sonst `.` und `,`).
    `SyncRunner` holt `IL10N` über `IFactory::get('workoutcalendar', $lang)` mit der Sprache der Person.

- [ ] **Step 1: Failing Tests schreiben** (Fakes für `UserStore`, `SecretStore`, `CalendarObjects`, `HttpClient`, Mock für `ILockingProvider`)

```php
public function testRunsSyncAndStoresState(): void            // Termine angelegt, state.lastSuccessAt gesetzt, Done
public function testLockedRunIsSkipped(): void                // acquireLock wirft LockedException → Skipped, keine HTTP-Anfrage
public function testPausedUserIsNotContacted(): void          // pausedFor gesetzt → Paused, keine HTTP-Anfrage
public function testMissingCredentialsMeanNotConnected(): void
public function testDeletedCalendarIsReported(): void         // calendarId zeigt ins Leere → CalendarMissing, setup.step === Calendar
public function testFailureSendsNotificationOnce(): void      // zwei Läufe mit INVALID_CREDENTIALS → genau ein send
public function testRecoveryDismissesNotification(): void
public function testLockIsReleasedAfterException(): void
```

- [ ] **Step 2–4:** rot, umsetzen, `composer test` grün.
- [ ] **Step 5: Commit**

`[FEATURE] Sync-Lauf pro Person mit Sperre gegen Doppelläufe und Erkennung gelöschter Kalender`

### Task 12: Hintergrundjobs

**Files:**
- Create: `lib/BackgroundJob/SyncJob.php`, `lib/BackgroundJob/CalendarMaintenanceJob.php`
- Modify: `appinfo/info.xml` (`<background-jobs><job>OCA\WorkoutCalendar\BackgroundJob\SyncJob</job></background-jobs>`)
- Test: `tests/Unit/BackgroundJob/SyncJobTest.php`, `tests/Unit/BackgroundJob/CalendarMaintenanceJobTest.php`

**Interfaces:**
- Consumes: `UserStore::dueUsers`, `SyncRunner::run`, `CalendarService::moveAppEvents/removeAppEvents`.
- Produces:
  - `SyncJob extends TimedJob`: `setInterval(300)`, `setTimeSensitivity(IJob::TIME_SENSITIVE)`,
    `MAX_USERS_PER_RUN = 10`. Arbeitet `dueUsers(now, 10)` ab. Eine Ausnahme bei einer Person
    wird geloggt und hält die anderen nicht auf.
  - `CalendarMaintenanceJob extends QueuedJob`, Argument
    `['uid' => string, 'action' => 'move'|'remove', 'from' => int, 'to' => ?int]`, `BATCH = 200`.
    Ist danach noch etwas übrig, reiht er sich erneut ein. Wenn nichts mehr übrig ist, setzt er
    `SetupState::maintenance = null`.

- [ ] **Step 1: Failing Tests schreiben**

```php
public function testRunsOnlyDueUsersUpToLimit(): void        // 12 fällig → 10 Läufe
public function testFailingUserDoesNotStopOthers(): void
public function testMoveRequeuesUntilDone(): void            // 450 Termine → 3 Läufe, add() zweimal
public function testRemoveClearsMaintenanceWhenDone(): void
```

- [ ] **Step 2–4:** rot, umsetzen, `composer test` grün.
- [ ] **Step 5: Commit**

`[FEATURE] Hintergrundjob alle 5 Minuten für fällige Personen, Umzug und Entfernen in Etappen`

### Task 13: Anmelden, Abmelden, Zurücksetzen

**Files:**
- Create: `lib/Service/AccountService.php`, `lib/Service/Cleanup.php`
- Test: `tests/Unit/Service/AccountServiceTest.php`

**Interfaces:**
- Consumes: `GarminClientFactory::authenticator`, `SecretStore`, `UserStore`, `CalendarService`, `IJobList`, `FailureNotifications`.
- Produces:
  - `enum Cleanup: string { case None = 'none'; case Events = 'events'; case Calendar = 'calendar'; }`
  - `AccountService::login(string $uid, string $email, string $password): SetupState` wirft
    `GarminException` unverändert weiter. Bei Erfolg: Zugangsdaten und Tokens speichern,
    `pausedFor = null`, Benachrichtigung zurückziehen, `connected = true`, `maskedEmail` setzen.
    Neuer `accountKey` ≠ alter → `SyncState` neu. `step`: `Calendar`, wenn `calendarId === null`,
    sonst `Done` (oder bleibt beim bisherigen Assistentenschritt). `next_run_at = now`.
  - `logout(string $uid, Cleanup $cleanup): void`: Geheimnisse löschen, `connected = false`.
    `Events` → `maintenance = 'remove'`, Job einreihen, `SyncState` neu.
    `Calendar` → nur bei `calendarCreatedByApp`, sonst `\InvalidArgumentException`. Kalender löschen,
    `calendarId = null`, `SyncState` neu.
  - `reset(string $uid, Cleanup $cleanup): void`: wie `logout`, danach `UserStore::clear` und
    `SecretStore::clear`. Der nächste Status ist `step = Garmin`.

- [ ] **Step 1: Failing Tests schreiben**

```php
public function testLoginStoresSecretsAndMovesToCalendarStep(): void
public function testRejectedLoginStoresNothing(): void            // INVALID_CREDENTIALS → keine Geheimnisse gespeichert
public function testLoginClearsPause(): void
public function testOtherAccountStartsFreshState(): void          // anderer accountKey → SyncState neu, Einstellungen bleiben
public function testSameAccountKeepsState(): void
public function testReloginWithExistingCalendarSkipsWizard(): void // calendarId gesetzt → step Done
public function testLogoutKeepsEventsByDefault(): void
public function testLogoutWithEventsQueuesRemoval(): void
public function testLogoutCalendarOnlyForOwnCalendar(): void      // calendarCreatedByApp false → InvalidArgumentException
public function testResetStartsWizardFromBeginning(): void
```

- [ ] **Step 2–4:** rot, umsetzen, `composer test` grün.
- [ ] **Step 5: Commit**

`[FEATURE] Anmelden, Abmelden und Zurücksetzen, Termine bleiben ohne ausdrückliche Wahl stehen`

### Task 14: Kalender wählen und wechseln

**Files:**
- Create: `lib/Service/CalendarSetupService.php`, `lib/Service/CalendarChoice.php`
- Test: `tests/Unit/Service/CalendarSetupServiceTest.php`

**Interfaces:**
- Produces:
  - `CalendarChoice` (Rückgabe): `bool $needsConfirmation`, `?CalendarInfo $calendar`, `int $existingEvents`.
  - `CalendarSetupService::choose(string $uid, ?string $newName, ?int $calendarId, bool $confirmed, bool $moveEvents): CalendarChoice`
    - `newName` gesetzt, kein eigener Kalender mit diesem Namen → anlegen, `calendarCreatedByApp = true`,
      `needsConfirmation = false`.
    - `newName` passt zu vorhandenem Kalender oder `calendarId` gesetzt und nicht bestätigt →
      `needsConfirmation = true`, `existingEvents = countAll`, nichts gespeichert.
    - bestätigt → `calendarCreatedByApp = false`.
    - Ein `calendarId`, der nicht zu `ownCalendars` gehört → `\InvalidArgumentException`.
    - Im Assistenten (`step = Calendar`) → `step = History`.
    - Wechsel nach der Einrichtung: `moveEvents` → `maintenance = 'move'`, Job einreihen, Zustand
      bleibt. Ohne `moveEvents` → `SyncState` neu (die Historie wird im eingestellten Zeitraum neu
      importiert).
  - Standardname: `l->t('Garmin activities')`, Deutsch `Garmin-Aktivitäten`.

- [ ] **Step 1: Failing Tests schreiben**

```php
public function testNewCalendarIsCreatedWithoutQuestion(): void
public function testExistingNameNeedsConfirmation(): void      // 'Garmin-Aktivitäten' existiert → needsConfirmation, nichts angelegt
public function testExistingCalendarNeedsConfirmation(): void   // existingEvents === 37
public function testForeignCalendarIdIsRejected(): void
public function testChangeWithMoveQueuesJob(): void
public function testChangeWithoutMoveRestartsHistory(): void    // state.initialized === false
```

- [ ] **Step 2–4:** rot, umsetzen, `composer test` grün.
- [ ] **Step 5: Commit**

`[FEATURE] Kalender wählen mit Nachfrage bei vorhandenem Kalender, Wechsel mit oder ohne Umzug`

### Task 15: JSON-API

**Files:**
- Create: `lib/Controller/ApiController.php`, `lib/Service/StatusService.php`
- Test: `tests/Unit/Service/StatusServiceTest.php`, `tests/Integration/Controller/ApiControllerTest.php`

**Interfaces:**
- Consumes: `AccountService`, `CalendarSetupService`, `SyncRunner`, `SyncService::changeHistory`, `UserStore`, `CalendarService`.
- Produces: Controller mit `#[NoAdminRequired]` und `#[FrontpageRoute]`, Antworten als `JSONResponse`:

| Route | Body | Antwort |
|---|---|---|
| `GET /api/status` | – | Status (siehe unten) |
| `POST /api/login` | `email`, `password` | Status; Fehler 422 `{"error": "<FailureCategory>"}`, `#[UserRateLimit(limit: 10, period: 3600)]` |
| `GET /api/calendars` | – | `{"defaultName": "…", "calendars": [{"id","name","color"}]}` |
| `POST /api/calendar` | `name?`, `calendarId?`, `confirmed`, `moveEvents` | `{"needsConfirmation", "existingEvents", "status"}` |
| `POST /api/history` | `historyDays` (`30`, `90`, `365`, `null`) | Status. Im Assistenten: speichert, `step = Done`, führt sofort `SyncRunner::run` aus. Danach: `changeHistory` |
| `PUT /api/settings` | `intervalMinutes`, `jitterPercent`, `deletionMode`, `deletionCheckDays` | Status, Grenzen wie `SyncSettings::fromArray` |
| `POST /api/sync` | – | `{"outcome": "<RunOutcome>", "status"}` |
| `POST /api/logout` | `cleanup` | Status |
| `POST /api/reset` | `cleanup` | Status |

  Status (`StatusService::status(string $uid): array`):
  `{"setup": {"step","connected","maskedEmail","calendar": {"id","name","createdByApp"}|null,"maintenance"},
    "sync": {"lastRunAt","lastSuccessAt","nextRunAt","eventCount","oldestActivityDate","backfill": {"running","reachedDate"}},
    "failure": {"category","since","count"}|null, "paused": "<category>"|null, "settings": {…}}`.
  Zeiten als Unix-Sekunden. Kein Feld enthält E-Mail im Klartext, Passwort oder Token.

- [ ] **Step 1: Failing Tests schreiben**

```php
// StatusServiceTest
public function testStatusNeverContainsSecrets(): void      // json_encode(status) enthält weder E-Mail noch Passwort noch Token
public function testBackfillProgress(): void                // backfillDone false → running true, reachedDate = backfillBefore
// ApiControllerTest (Integration, Garmin-Zugriff über Fake-HttpClient im Container ersetzt)
public function testLoginErrorReturnsCategory(): void       // 422 {"error":"INVALID_CREDENTIALS"}
public function testWizardFlow(): void                      // login → calendar(name) → history(90) → status.setup.step === 'done', Termine im Kalender
public function testSettingsAreClamped(): void              // intervalMinutes 5 → 15
public function testOtherUsersDataIsNotReachable(): void    // calendarId einer anderen Person → 400
```

- [ ] **Step 2–4:** rot, umsetzen, `composer test` lokal und Integration in CI grün.
- [ ] **Step 5: Commit**

`[FEATURE] JSON-API für Einrichtung, Einstellungen, Sync, Abmelden und Zurücksetzen`

### Task 16: Doku nachziehen

**Files:**
- Modify: `docs/architecture.md` (Überblick und Module, „geplant“ entfernen), `README.md` (Status, Tests,
  Limitationen), `docs/anforderungen.md` (nichts außer Verweisen, falls nötig)

- [ ] **Step 1: Texte anpassen**

Architektur: neue Module mit Stand ✅, `CurlHttpClient` nicht mehr „geplant“. README: Status „Backend
fertig, Oberfläche folgt“, `composer test:integration` und der CI-Workflow im Setup-Abschnitt.

- [ ] **Step 2: Prüfen**

Run: `grep -n "geplant" docs/architecture.md`
Expected: nur noch die Einstellungsseite (Teil B).

- [ ] **Step 3: Commit**

`[DOCS] Architektur und README beschreiben die fertige Nextcloud-Anbindung im Backend`
