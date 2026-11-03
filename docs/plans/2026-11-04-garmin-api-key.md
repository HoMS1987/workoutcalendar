# Anmeldung mit dem persönlichen API-Schlüssel – Umsetzungsplan

> **Für ausführende Agenten:** Pflicht-Skill superpowers:subagent-driven-development (empfohlen)
> oder superpowers:executing-plans, Aufgabe für Aufgabe. Schritte nutzen Checkboxen (`- [ ]`).

**Ziel:** Die App verbindet sich wahlweise mit dem persönlichen API-Schlüssel von Garmin
(empfohlen) oder wie bisher mit E-Mail, Passwort und Code. Ergebnis ist `1.1.0-rc.2`.

**Architektur:**
- `GarminClient` holt Tokens künftig über einen `TokenProvider`.
  - `PasswordTokenProvider` enthält die heutige Logik: Refresh, Anmeldung mit Passwort, Sperre für 2FA-Konten.
  - `ApiKeyTokenProvider` tauscht den Schlüssel bei `personal.connectapi.garmin.com` gegen ein Token.
- Der Schlüssel liegt verschlüsselt im `SecretStore`.
- Pro Person ist genau ein Weg aktiv. Das Speichern des einen löscht die Daten des anderen.
- In der Oberfläche bietet ein Umschalter mit zwei Reitern beide Wege an, im Assistenten und hinter „Neu anmelden“.

**Technik:** PHP 8.2+, Nextcloud 33–35, PHPUnit 11, Vue 3, `@nextcloud/vue` 9, Vitest.

**Entwurf:** [docs/specs/2026-11-04-garmin-api-key-design.md](../specs/2026-11-04-garmin-api-key-design.md),
aufbauend auf [docs/specs/2026-11-03-garmin-2fa-design.md](../specs/2026-11-03-garmin-2fa-design.md).

## Globale Vorgaben

- Arbeit auf `feature/garmin-api-key`, ein Commit pro Aufgabe, am Ende ein PR nach `dev`. Der
  PR-Titel und die Squash-Betreffzeile beginnen mit `[FEATURE]`. Gemergt wird mit
  `--author-email 49567220+HoMS1987@users.noreply.github.com --subject … --body-file …`.
- Commits sind englisch und haben das Format `[TYPE] Description`. Die letzte Zeile lautet
  `Assisted-by: ClaudeCode:claude-opus-5-5`. Keine Co-Authored-By-Zeile. Die Nachricht kommt aus
  einer Datei (`git commit -F`).
- Kommentare im Code sind englisch. Texte der Oberfläche stehen englisch in
  `t('workoutcalendar', …)`, die deutschen in `l10n/de.json` und `l10n/de.js`. Anrede „du“,
  Umlaute direkt.
- Tests zuerst (TDD). `vendor/bin/phpunit`, `npm run lint`, `npm run typecheck` und `npm test`
  sind vor jedem Commit grün.
- Der Schlüssel erscheint nie im Log, im Status, in einer Fehlermeldung oder unverschlüsselt in der
  Datenbank. Der `Redactor` maskiert ihn als bekannten Wert und schwärzt `ApiKey <…>` wie `Bearer`.
- Endpunkte:
  - Tausch: `POST https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token`
    mit `Authorization: ApiKey <Schlüssel>` und `Accept: */*`. Die Antwort enthält `access_token`
    (JWT, Claim `exp`).
  - Aktivitäten: `GET https://personal.connectapi.garmin.com/activitylist-service/activities/search/activities`
    mit `Authorization: Bearer <access_token>`.
- Die App holt ein neues Token, sobald das alte in weniger als 3600 Sekunden abläuft. Ein Refresh-Token
  gibt es nicht.
- Nur 401 und 403 beim Tausch bedeuten `API_KEY_REJECTED`. Das pausiert und benachrichtigt sofort.
  Alles andere wird klassifiziert wie bisher (`BLOCKED`, `SERVER_ERROR`, `NETWORK`,
  `PROTOCOL_CHANGED`) und pausiert nicht.
- Link der Anleitung: `https://connect.garmin.com/app/settings/personalApiToken`, öffnet in einem
  neuen Tab.
- Die `accountKey` eines bestehenden Kontos ändert sich bei keinem Wechsel des Anmeldewegs
  (Entscheidung zur Kennung, siehe unten).

## Entscheidung zur Kennung (Abweichung vom Entwurf)

Der Entwurf sagt, beim Weg zurück zu E-Mail und Passwort übernehme die App `hash(E-Mail)` als neue
`accountKey`, die Termine bekämen die Markierung beim nächsten Schreiben neu. Das stimmt nicht:
`DavCalendarTarget::inRange` wertet nur Termine mit der aktuellen `accountKey` für die Löschbehandlung
aus, und unveränderte Termine werden nie neu geschrieben. Deshalb gilt:

- `accountKey` bleibt bei jedem Wechsel des Anmeldewegs gleich.
- Neues Feld `SetupState::$identity` (`?string`), nur zur Erkennung eines Kontowechsels:
  - Bei E-Mail und Passwort ist es `email:` plus `AccountService::accountKey($email)`.
  - Beim Schlüssel ist es `garmin:` plus der Hash der Garmin-Kennung, sonst `null`.
  - Fehlt `identity` (Installationen vor 1.1.0), gilt `email:` plus der vorhandene `accountKey`.
- Ein Kontowechsel liegt nur vor, wenn alte und neue `identity` dasselbe Präfix haben und sich
  unterscheiden. Dann gilt das heutige Verhalten: neuer Sync-Zustand und neue `accountKey`. Bei
  unterschiedlichem Präfix oder `null` gilt das Konto als dasselbe.
- Beim ersten Einrichten mit Schlüssel und ohne vorhandene `accountKey` ist die `accountKey` der Hash
  der Garmin-Kennung oder, wenn es keine gibt, 16 zufällige Hex-Zeichen.

## Schwerpunkte für Reviews

1. **Eingefügter Schlüssel mit Leerzeichen oder Zeilenumbruch** wird getrimmt. Ein leerer Schlüssel
   ergibt 400 ohne Anfrage an Garmin. Tests in Aufgabe 4 (Server) und 6 (Feld).
2. **Doppelklick auf „Verbinden“ oder „Speichern“** schickt nur eine Anfrage. Tests in Aufgabe 6
   und 7.
3. **Garmin ist beim Tausch gestört** (429, 5xx, Netzwerk): keine Pause, eine Meldung im Assistenten,
   nichts gespeichert, der bisherige Weg bleibt erhalten. Tests in Aufgabe 1 und 4.
4. **Umstellen auf den Schlüssel während einer offenen Code-Anfrage oder einer Pause wegen
   `MFA_REQUIRED`:**
   - Anfrage, Cookies, Markierung und Pause sind danach weg.
   - Der 2FA-Kasten verschwindet.
   - Der nächste Sync läuft.

   Tests in Aufgabe 4 und 7.
5. **Der Schlüssel erscheint in keiner Ausgabe:** Log, Status, Fehlerantwort und Ausnahme. Tests in
   Aufgabe 1, 4 und 5.

## Dateistruktur

| Datei | Aufgabe |
|-------|---------|
| `lib/Garmin/Auth/ApiKeyTokenService.php` (neu), `lib/Garmin/GarminConstants.php`, `Diagnostics/FailureCategory.php`, `Diagnostics/Redactor.php` | Schlüssel gegen Token tauschen, Garmin-Kennung lesen |
| `lib/Garmin/Auth/TokenProvider.php`, `PasswordTokenProvider.php`, `ApiKeyTokenProvider.php` (neu), `lib/Garmin/GarminClient.php`, `lib/Service/GarminClientFactory.php` | Tokens je Anmeldeweg |
| `lib/Storage/ApiKeyRecord.php` (neu), `lib/Storage/SecretStore.php`, `lib/Storage/SetupState.php` | Schlüssel speichern, `identity` |
| `lib/Service/AccountService.php`, `lib/Service/SyncRunner.php`, `lib/Sync/SyncService.php`, `lib/Sync/NotificationPolicy.php`, `lib/Notification/Notifier.php` | Verbinden, Umstellen, Sync und Pause |
| `lib/Controller/ApiController.php`, `lib/Service/StatusService.php` | Route und Status |
| `src/components/signin/ApiKeyForm.vue`, `PasswordSignIn.vue`, `SignInMethods.vue` (neu), `src/components/wizard/StepGarmin.vue` | Anmeldewege mit Umschalter |
| `src/components/overview/ApiKeyBox.vue` (neu), `OverviewPage.vue`, `AccountSection.vue`, `OverviewTiles.vue`, `src/components/wizard/StepDone.vue`, `src/viewState.ts` | Kasten, „Neu anmelden“, Anzeige |
| `docs/adrs/0009-…`, `docs/anforderungen.md`, `docs/architecture.md`, `README.md`, `CHANGELOG.md`, `appinfo/info.xml` | Doku und Store-Beschreibung |

### Aufgabe 1: Schlüssel gegen ein Token tauschen

**Dateien:**
- Neu: `lib/Garmin/Auth/ApiKeyTokenService.php`
- Ändern: `lib/Garmin/GarminConstants.php`, `lib/Garmin/Diagnostics/FailureCategory.php`,
  `lib/Garmin/Diagnostics/Redactor.php`
- Test: `tests/Unit/Garmin/Auth/ApiKeyTokenServiceTest.php` (neu),
  `tests/Unit/Garmin/Diagnostics/RedactorTest.php`

**Schnittstellen:**
- Erzeugt:
  - `GarminConstants::PERSONAL_API = 'https://personal.connectapi.garmin.com'`
  - `FailureCategory::ApiKeyRejected = 'API_KEY_REJECTED'`
  - `ApiKeyTokenService::__construct(HttpClient $http, Redactor $redactor)`
  - `ApiKeyTokenService::exchange(string $apiKey): GarminTokens`. Schritt `apikey.token`. Das Token
    ist `GarminTokens($access, null, 'personal-api')`.
  - `static ApiKeyTokenService::garminUserId(GarminTokens $tokens): ?string` liest den ersten
    vorhandenen nicht leeren String-Claim aus `['garmin_guid', 'userProfilePK', 'user_id', 'sub']`.
    Die Claim-Namen sind geraten, der RC-Test prüft sie.

- [ ] **Schritt 1: Tests schreiben**
  - `testExchangeSendsTheKeyAsApiKeyHeader`: URL, Methode POST, Header `Authorization: ApiKey k-1`,
    `Accept: */*`, Ergebnis `accessToken` aus `access_token`.
  - `testRejectedKeyIsApiKeyRejected` als Data-Provider mit 401 und 403 →
    `FailureCategory::ApiKeyRejected`.
  - `testOtherFailuresKeepTheirCategory`: 429 → `Blocked`, 503 → `ServerError`, 404 →
    `ProtocolChanged`, `NetworkException` → `Network`.
  - `testResponseWithoutAccessTokenIsProtocolChanged`.
  - `testKeyNeverAppearsInTheException`: Nachricht und Ausschnitt einer 401-Antwort, die den
    Schlüssel enthält.
  - `testGarminUserIdReadsTheFirstKnownClaim` und `testGarminUserIdWithoutClaimsIsNull`.
  - `RedactorTest::testMasksApiKeyHeader`: `ApiKey abc.def-1` → `ApiKey ***`.
- [ ] **Schritt 2:** `vendor/bin/phpunit --filter 'ApiKeyTokenServiceTest|RedactorTest'` → FAIL
- [ ] **Schritt 3: Umsetzen.** Antworten wie in `ResponseFailures` behandeln, 401 und 403 zuerst auf
  `ApiKeyRejected` abbilden. Im Redactor `ApiKey` neben `Bearer|Basic` in das Header-Muster aufnehmen.
- [ ] **Schritt 4:** `vendor/bin/phpunit` → PASS
- [ ] **Schritt 5: Commit** `[FEATURE] Personal API key is exchanged for an access token`

### Aufgabe 2: Tokens je Anmeldeweg

**Dateien:**
- Neu: `lib/Garmin/Auth/TokenProvider.php`, `PasswordTokenProvider.php`, `ApiKeyTokenProvider.php`
- Ändern: `lib/Garmin/GarminClient.php`, `lib/Service/GarminClientFactory.php`
- Test: `tests/Unit/Garmin/GarminClientTest.php`, `tests/Unit/Garmin/Auth/ApiKeyTokenProviderTest.php`
  (neu), `tests/Unit/Service/GarminClientFactoryTest.php`

**Schnittstellen:**
- Verbraucht: `ApiKeyTokenService` (Aufgabe 1)
- Erzeugt:
  - `interface TokenProvider { public function current(): GarminTokens; public function renew(GarminTokens $rejected): GarminTokens; }`
    Beide Methoden werfen `GarminException`.
  - `PasswordTokenProvider` mit den heutigen `validTokens`, `renew` und `login` aus `GarminClient`,
    unverändert im Verhalten, auch `passwordLogin = false` → `login.skipped`.
  - `ApiKeyTokenProvider::__construct(ApiKeyTokenService $service, TokenStore $store, string $apiKey, ?callable $now = null)`.
    `current()` tauscht, wenn kein Token da ist oder `expiresAt() - now < 3600`. `renew()` tauscht
    immer. Beides speichert.
  - `GarminClient::__construct(HttpClient $http, TokenProvider $tokens, LoggerInterface $logger, string $apiBase, array $knownSecrets = [])`,
    `activities()` unverändert im Verhalten, Adresse `$apiBase . ACTIVITIES_PATH`.
  - `GarminClientFactory::client(...)` (Passwort) bleibt mit `GarminConstants::CONNECT_API`.
  - `GarminClientFactory::apiKeyClient(string $uid, string $apiKey): GarminClient` nutzt
    `GarminConstants::PERSONAL_API`, den Schlüssel als bekannten Wert.
  - `GarminClientFactory::apiKeyTokens(string $apiKey): ApiKeyTokenService`

- [ ] **Schritt 1: Tests schreiben**
  - Die bestehenden `GarminClientTest`-Fälle laufen über `PasswordTokenProvider` weiter, ohne
    geänderte Erwartungen.
  - `ApiKeyTokenProviderTest`:
    - `testUsesAStoredTokenThatLastsMoreThanAnHour`
    - `testExchangesWhenTheTokenExpiresWithinAnHour`, mit `exp` = now + 3599
    - `testExchangesWithoutStoredToken`
    - `testRenewAlwaysExchanges`
  - `GarminClientTest::testApiKeyClientFetchesFromThePersonalApi`: URL beginnt mit
    `https://personal.connectapi.garmin.com/activitylist-service/`, Bearer-Token aus dem Tausch.
  - `testApiKeyClientRetriesOnceWithANewTokenAfter401`
- [ ] **Schritt 2:** gefilterte Tests → FAIL
- [ ] **Schritt 3: Umsetzen.** Die Logik aus `GarminClient` in `PasswordTokenProvider` verschieben,
  nicht duplizieren.
- [ ] **Schritt 4:** `vendor/bin/phpunit` → PASS
- [ ] **Schritt 5: Commit** `[REFACTOR] Garmin client takes its tokens from a provider per sign-in method`

### Aufgabe 3: Schlüssel und Kennung speichern

**Dateien:**
- Neu: `lib/Storage/ApiKeyRecord.php`
- Ändern: `lib/Storage/SecretStore.php`, `lib/Storage/SetupState.php`
- Test: `tests/Unit/Storage/SecretStoreTest.php`, `tests/Unit/Storage/UserStoreTest.php`,
  `tests/Integration/Storage/StorageRoundTripTest.php`

**Schnittstellen:**
- Erzeugt:
  - `final class ApiKeyRecord { __construct(public readonly string $key, public readonly int $enteredAt); toArray(); static fromArray(array): self }`,
    `fromArray` wirft `\InvalidArgumentException`.
  - `SecretStore::apiKey(string $uid): ?ApiKeyRecord`, `saveApiKey(string $uid, ApiKeyRecord $record): void`,
    `deleteApiKey(string $uid): void` mit Schlüssel `api_key`.
  - `SecretStore::deleteCredentials(string $uid): void` löscht Zugangsdaten und Tokens.
  - `clear()` löscht auch `api_key`.
  - `SetupState::$identity` (`?string`) als letzter Konstruktorparameter mit Standardwert `null`, in
    `toArray`/`fromArray`.

- [ ] **Schritt 1: Tests schreiben:**
  - `testApiKeyRoundTripsEncrypted`: der Rohwert enthält den Schlüssel nicht.
  - `testClearRemovesTheApiKey`
  - `testDeleteCredentialsKeepsTheApiKey`
  - `testUnreadableApiKeyIsNull`
  - `testSetupStateKeepsIdentity`
  - ein Durchlauf im Integrationstest
- [ ] **Schritt 2:** gefilterte Tests → FAIL
- [ ] **Schritt 3: Umsetzen** nach dem Muster von `pendingMfa`.
- [ ] **Schritt 4:** `vendor/bin/phpunit` → PASS
- [ ] **Schritt 5: Commit** `[FEATURE] Personal API key and account identity are stored per person`

### Aufgabe 4: Verbinden, Umstellen und Sync mit Schlüssel

**Dateien:**
- Ändern: `lib/Service/AccountService.php`, `lib/Service/SyncRunner.php`, `lib/Sync/SyncService.php`,
  `lib/Sync/NotificationPolicy.php`, `lib/Notification/Notifier.php`, `l10n/de.json`, `l10n/de.js`
- Test: `tests/Unit/Service/AccountServiceTest.php`, `SyncRunnerTest.php`,
  `tests/Unit/Sync/SyncServiceTest.php`, `NotificationPolicyTest.php`,
  `tests/Unit/Notification/NotifierTest.php`

**Schnittstellen:**
- Verbraucht: Aufgaben 1 bis 3
- Erzeugt:
  - `AccountService::connectApiKey(string $uid, string $apiKey): SetupState`
    - läuft unter der Sperre und trimmt den Schlüssel; ist er leer, folgt `\InvalidArgumentException`
      ohne Anfrage an Garmin
    - tauscht den Schlüssel, und erst nach Erfolg passiert Folgendes:
      - `deleteCredentials` (Zugangsdaten und alte Tokens), `deleteSsoCookies`, `deletePendingMfa`
      - danach `saveApiKey(new ApiKeyRecord($key, now))` und die neuen Tokens speichern
      - `mfaAccount = false`, `mfaMethod = null`
      - Pause und Fehlerfelder zurücksetzen, Benachrichtigung entfernen
    - Kennung, `accountKey`, Schritt und `nextRunAt` richten sich nach „Entscheidung zur Kennung“
      und `completeLogin`
    - `maskedEmail = null`
  - `completeLogin` (Passwort) löscht den Schlüssel, behält die `accountKey` und setzt `identity`.
    Ein Kontowechsel liegt nur unter der Regel aus „Entscheidung zur Kennung“ vor.
  - `SyncRunner::runLocked`: verbunden, wenn Zugangsdaten oder Schlüssel gespeichert sind. Mit
    Schlüssel nutzt es `apiKeyClient`, sonst `client(..., !isMfaAccount())`.
  - `SyncService::fail` pausiert bei `ApiKeyRejected` (`pausedFor = 'API_KEY_REJECTED'`).
  - `NotificationPolicy` behandelt `API_KEY_REJECTED` sofort.
  - Notifier-Text: „Your Garmin API key has expired or was deleted. Workout Calendar is paused until
    you enter a new one in the settings.“, deutsch: „Dein API-Schlüssel für Garmin ist abgelaufen oder
    wurde gelöscht. Workout Calendar ist pausiert, bis du in den Einstellungen einen neuen eingibst.“

- [ ] **Schritt 1: Tests schreiben**
  - **Einrichten und Umstellen:**
    - `testConnectApiKeyOnSetupContinuesAtTheCalendar`: Schritt `calendar`, `accountKey` aus der
      Garmin-Kennung, `identity` mit `garmin:`
    - `testConnectApiKeyWithoutGarminIdUsesARandomAccountKey`: 16 Hex-Zeichen
    - `testSwitchToApiKeyKeepsAccountKeyCalendarAndEvents`
    - `testSwitchToApiKeyDeletesPasswordCookiesPendingRequestAndFlag`: Schwerpunkt 4, auch nach einer
      Pause wegen `MFA_REQUIRED`, die danach endet
    - `testSwitchBackToPasswordDeletesTheKeyAndKeepsAccountKey`
    - `testOtherGarminIdIsAnAccountSwitch`: gleiches Präfix, andere Kennung → neuer Sync-Zustand
    - `testDifferentIdentityKindsCountAsTheSameAccount`
    - `testLegacySetupWithoutIdentityComparesWithTheAccountKey`
  - **Fehler und Eingabe:**
    - `testRejectedKeyKeepsEverythingAsItWas`
    - `testGarminOutageKeepsEverythingAsItWas`: Schwerpunkt 3
    - `testKeyIsTrimmedAndAnEmptyKeyNeverReachesGarmin`: Schwerpunkt 1
  - **Sync und Benachrichtigung:**
    - `SyncRunnerTest::testApiKeyAccountSyncsWithoutCredentials`
    - `testRejectedApiKeyPausesAndNotifies`
    - `NotificationPolicyTest::testApiKeyRejectedIsImmediate`
    - `NotifierTest::testApiKeyRejectedLeadsToTheSettings`
- [ ] **Schritt 2:** gefilterte Tests → FAIL
- [ ] **Schritt 3: Umsetzen.** Den gemeinsamen Teil von `completeLogin` und `connectApiKey` (Pause
  und Fehler zurücksetzen, Schritt bestimmen, `SetupState` bauen, Benachrichtigung) in eine private
  Methode ziehen, nicht kopieren.
- [ ] **Schritt 4:** `vendor/bin/phpunit` → PASS
- [ ] **Schritt 5: Commit** `[FEATURE] Accounts connect and sync with the personal API key`

### Aufgabe 5: Route und Status

**Dateien:**
- Ändern: `lib/Controller/ApiController.php`, `lib/Service/StatusService.php`
- Test: `tests/Unit/Controller/ApiControllerTest.php`, `tests/Unit/Service/StatusServiceTest.php`,
  `tests/Integration/Controller/ApiControllerTest.php`

**Schnittstellen:**
- Erzeugt (Aufgabe 6 und 7 verlassen sich darauf):
  - `POST /api/apikey` mit `apiKey` → Status
    - `#[NoAdminRequired]`, `#[UserRateLimit(limit: 10, period: 3600)]`, `logMessage: false`
    - `GarminException` → 422 `{ error: <Kategorie> }`, also auch `API_KEY_REJECTED`
  - Status `setup` bekommt `authMethod: 'apiKey' | 'password' | null` und `apiKeyEnteredAt: ?int`.
    Der Schlüssel selbst steht nie darin.

- [ ] **Schritt 1: Tests schreiben:**
  - `testApiKeyRouteReturnsStatus`
  - `testRejectedApiKeyIs422`
  - `testEmptyApiKeyIs400`
  - `testApiKeyRouteIsRateLimited` (Reflection)
  - `testApiKeyNeverInLogOrResponse`
  - `StatusServiceTest::testStatusNamesTheAuthMethodWithoutTheKey`
  - Integration: `testApiKeyWithoutValueIs400`
- [ ] **Schritt 2:** gefilterte Tests → FAIL
- [ ] **Schritt 3: Umsetzen.**
- [ ] **Schritt 4:** `vendor/bin/phpunit` → PASS
- [ ] **Schritt 5: Commit** `[FEATURE] API route and status for the personal API key`

### Aufgabe 6: Anmeldewege mit Umschalter im Assistenten

**Dateien:**
- Neu: `src/components/signin/ApiKeyForm.vue`, `src/components/signin/PasswordSignIn.vue`,
  `src/components/signin/SignInMethods.vue`
- Ändern: `src/components/wizard/StepGarmin.vue`, `src/types.ts`, `src/api.ts`, `src/messages.ts`,
  `tests/js/fixtures.ts`, `l10n/de.json`, `l10n/de.js`
- Test: `tests/js/signin/apiKeyForm.test.ts`, `signInMethods.test.ts` (neu),
  `tests/js/wizard/stepGarmin.test.ts`, `tests/js/api.test.ts`, `tests/js/messages.test.ts`

**Schnittstellen:**
- Verbraucht: Route und Status aus Aufgabe 5
- Erzeugt:
  - `connectApiKey(apiKey: string): Promise<Status>`
  - `Status['setup']` mit `authMethod` und `apiKeyEnteredAt`
  - `loginError('API_KEY_REJECTED')` → „Garmin did not accept this API key. Please check it or create
    a new one.“
  - `ApiKeyForm.vue`
    - Props: `submitLabel: string`
    - Emit: `status`
    - Inhalt:
      - die Anleitung in drei Schritten aus dem Entwurf („Einrichten“) mit dem Link aus den globalen
        Vorgaben
      - das Feld (`autocomplete="off"`)
      - der Hinweis „A key is valid for one year at most. When it expires, Workout Calendar lets you
        know through a notification, and you enter a new one.“
      - Fehlerkarte und Knopf
    - Trimmt den Schlüssel, schickt einen leeren nicht und sperrt den Knopf, solange die Anfrage läuft.
  - `PasswordSignIn.vue`: der heutige Inhalt von `StepGarmin` (Formular, Code-Schritt, 2FA-Hinweis,
    „Back“), unverändert im Verhalten, Props und Emits wie heute bei `StepGarmin`.
  - `SignInMethods.vue`
    - zwei Reiter „API key (recommended)“ und „Email and password“
    - Props: `initial?: 'apiKey' | 'password'` (Standard `apiKey`), `submitLabel`, alles für
      `PasswordSignIn`
    - Emit: `status`
    - Bei einer laufenden Code-Anfrage mit Herkunft `setup` ist der Reiter „Email and password“ aktiv.
  - `StepGarmin.vue`: Überschrift plus `SignInMethods` mit `submitLabel` „Connect and continue“.

- [ ] **Schritt 1: Tests schreiben**
  - `apiKeyForm.test.ts`:
    - `showsTheGuideWithTheGarminLink`: `href` exakt, `target="_blank"`, `rel` mit `noopener`
    - `trimsAPastedKey`: `'  k-1\n'` → `connectApiKey('k-1')`
    - `sendsNothingForAnEmptyKey`
    - `sendsOnlyOneRequestOnDoubleClick`
    - `showsTheRejectedText`
  - `signInMethods.test.ts`:
    - `apiKeyTabIsPreselected`
    - `switchesToThePasswordForm`
    - `pendingSetupCodeOpensThePasswordTab`
  - Die `stepGarmin`-Tests laufen über `PasswordSignIn` unverändert weiter.
- [ ] **Schritt 2:** `npm test` → FAIL
- [ ] **Schritt 3: Umsetzen.** Reiter mit `NcCheckboxRadioSwitch type="button"` oder zwei `NcButton`
  mit `aria-pressed`. Die Wahl liegt beim Builder, muss aber mit der Tastatur bedienbar sein.
- [ ] **Schritt 4:** `npm run lint && npm run typecheck && npm test && npm run build` → PASS
- [ ] **Schritt 5: Commit** `[FEATURE] Setup offers the personal API key and email and password`

### Aufgabe 7: Übersicht: Kasten, „Neu anmelden“ und Anzeige

**Dateien:**
- Neu: `src/components/overview/ApiKeyBox.vue`
- Ändern: `src/viewState.ts`, `src/components/overview/OverviewPage.vue`, `AccountSection.vue`,
  `OverviewTiles.vue`, `src/components/wizard/StepDone.vue`, `src/messages.ts`, `l10n/de.json`,
  `l10n/de.js`
- Test: `tests/js/viewState.test.ts`, `tests/js/overview/apiKeyBox.test.ts` (neu),
  `accountSection.test.ts`, `overviewTiles.test.ts`, `overviewPage.test.ts`, `tests/js/wizard/stepDone.test.ts`

**Schnittstellen:**
- Verbraucht: `ApiKeyForm`, `SignInMethods` (Aufgabe 6), `showMfaBox` (vorhanden)
- Erzeugt:
  - `showApiKeyBox(status: Status): boolean`: wahr bei `paused === 'API_KEY_REJECTED'`. Den
    `paused`-Hinweis gibt es dann nicht zusätzlich.
  - `ApiKeyBox.vue`: `NcNoteCard type="warning"` als erstes Element der Übersicht. Inhalt: der Text der
    Benachrichtigung und `ApiKeyForm` mit `submitLabel` „Save“.
  - `AccountSection`:
    - „Sign in again“ öffnet `SignInMethods` im Abschnitt (`initial = 'apiKey'`).
    - Ist ein Passwort aktiv, steht darüber zusätzlich der bisherige Knopf „Sign in with the saved
      password“, mit dem heutigen Verhalten von `requestMfaCode`.
    - „Cancel“ schließt die Ansicht ohne Änderung.
    - Nach Erfolg schließt sie sich und zeigt „Signed in to Garmin again.“
  - Mit `authMethod === 'apiKey'` zeigen Kachel, Abschnitt und `StepDone` „Connected with API key“ und
    „entered on {date}“ statt der E-Mail.

- [ ] **Schritt 1: Tests schreiben**
  - `viewState.test.ts`: `showApiKeyBox` für `API_KEY_REJECTED`, für andere Pausen und ohne Pause;
    ohne zusätzlichen `paused`-Hinweis
  - `apiKeyBox.test.ts`:
    - `showsTheTextAndTheForm`
    - `savingEmitsTheStatus`
    - `saveSendsOnlyOnce`
  - `accountSection.test.ts`:
    - `signInAgainOffersBothMethodsWithApiKeyFirst`
    - `savedPasswordButtonOnlyForPasswordAccounts`
    - `cancelChangesNothing`
    - `switchToApiKeyHidesTheMfaBox`: Schwerpunkt 4, die Statusantwort ohne Pause blendet den Kasten
      aus
  - `overviewTiles.test.ts` und `stepDone.test.ts`: `showsConnectedWithApiKeyAndTheDate`
  - `overviewPage.test.ts`: `apiKeyBoxIsTheFirstElement`
- [ ] **Schritt 2:** `npm test` → FAIL
- [ ] **Schritt 3: Umsetzen.**
- [ ] **Schritt 4:** `npm run lint && npm run typecheck && npm test && npm run build` → PASS
- [ ] **Schritt 5: Commit** `[FEATURE] Overview takes a new API key and switches sign-in methods`

### Aufgabe 8: Doku und Store-Beschreibung

**Dateien:**
- Neu: `docs/adrs/0009-persoenlicher-api-schluessel.md`
- Ändern: `docs/anforderungen.md` (F-07), `docs/architecture.md`, `README.md`, `CHANGELOG.md`
  (`[Unreleased]`), `appinfo/info.xml` (Beschreibung en und de), `docs/specs/2026-11-04-garmin-api-key-design.md`
  (Abschnitt „Kontokennung“ an die „Entscheidung zur Kennung“ anpassen)

- [ ] **Schritt 1:** ADR 0009 im Format von `template.md`, Status Accepted.
  - Kontext: offizielle Personal API neben der inoffiziellen Anmeldung.
  - Entscheidung: Schlüssel bevorzugt, Passwort als Alternative, ein aktiver Weg pro Person,
    `accountKey` stabil, `identity` für den Kontowechsel.
  - Konsequenzen: jährlicher Schlüsselwechsel, Nutzungsbedingungen für persönliche Nutzung, Antworten
    am echten Konto noch ungeprüft.
- [ ] **Schritt 2:** Die übrigen Dokumente nachziehen:
  - `architecture.md`: Route, Status-Felder, Schlüssel `api_key`, Kasten.
  - README: Requirements (Schlüssel empfohlen, keine 2FA-Einschränkung mehr), Anleitung in Kurzform,
    Limitations (der Schlüssel läuft nach höchstens einem Jahr ab).
  - CHANGELOG: Added.
  - `info.xml`: Funktionsliste und Voraussetzungen mit beiden Wegen, ohne „without two-factor
    authentication“. Ein Pre-Release ändert den Store-Eintrag nicht (`docs/release.md`).
  - Das XSD-Schema muss gültig bleiben (`tests/Unit/ReleaseMetadataTest`).
- [ ] **Schritt 3:** `vendor/bin/phpunit` → PASS
- [ ] **Schritt 4: Commit** `[DOCS] Personal API key in ADR 0009, requirements, architecture, README and store description`

### Aufgabe 9: PR, `1.1.0-rc.2` und Test am echten Konto

Diese Aufgabe führt der Koordinator aus. Vor jedem Schritt, der nach außen wirkt, fragt er nach.

- [ ] **Schritt 1:** Gesamtreview des Branches, Funde beheben.
- [ ] **Schritt 2:** PR `[FEATURE] Sign in with Garmin's personal API key` nach `dev`. Nach grünen
  Checks Squash-Merge mit `--author-email` und `--subject`, Autor prüfen.
- [ ] **Schritt 3:** Branch `chore/release-1.1.0-rc.2` mit Version `1.1.0-rc.2`, PR, Merge, `main` per
  Fast-Forward.
- [ ] **Schritt 4:** GitHub-Pre-Release `1.1.0-rc.2` mit den Einträgen aus `[Unreleased]`.
- [ ] **Schritt 5:** Archiv in den Pod (Sicherung von `custom_apps/workoutcalendar` nach `/tmp`),
  `occ upgrade`, `occ app:list`, `occ integrity:check-app`.
- [ ] **Schritt 6:** Test am echten Konto: die Schritte 1 bis 4 aus dem Entwurf („Tests“). Ergebnisse
  festhalten, offene Punkte nachtragen: Antwort der neuen Adresse, Status bei gelöschtem Schlüssel,
  Claims des Tokens.
