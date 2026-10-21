# Nextcloud-Anbindung, Teil B: Oberfläche – Umsetzungsplan

Schritte nutzen Checkboxen (`- [ ]`) zum Abhaken.

**Ziel:** Die App bekommt ihre persönliche Einstellungsseite mit Einrichtungsassistent und
Übersicht, wie im Entwurf beschrieben. Vorher werden die offenen Nacharbeiten aus Teil A erledigt.
Am Ende steht ein Test von Hand auf der eigenen Instanz.

**Architektur:** Die Seite ist eine Vue-3-Anwendung mit `@nextcloud/vue` 9, gebaut mit
`@nextcloud/vite-config` (Aufbau wie `nextcloud/app_template`). Sie spricht nur mit der JSON-API aus
Teil A und leitet ihre Ansicht allein aus `GET /api/status` ab (Tabelle „Zustände für die
Oberfläche“ in `docs/architecture.md`). Die Ableitung ist eine reine Funktion und wird ohne DOM
getestet, die Komponenten mit `@vue/test-utils`.

**Technik:** Node.js 24 LTS (winget, kein Dienst), npm 11, Vue 3, TypeScript, `@nextcloud/vue` 9,
`@nextcloud/axios`, `@nextcloud/router`, `@nextcloud/l10n`, `@nextcloud/dialogs`, Vite, Vitest,
`@vue/test-utils`, `happy-dom`, ESLint mit `@nextcloud/eslint-config`.

**Entwurf:** [docs/specs/2026-10-20-nextcloud-anbindung-design.md](../specs/2026-10-20-nextcloud-anbindung-design.md)
**Teil A:** [docs/plans/2026-10-20-nextcloud-anbindung-backend.md](2026-10-20-nextcloud-anbindung-backend.md)

## Globale Vorgaben

- Alle Vorgaben aus Teil A gelten weiter (NF-01 bis NF-07, nur eigene Objekte, keine Geheimnisse in
  Ausgaben, PHP ab 8.2, Nextcloud 33 bis 35).
- Commit-Nachrichten und PRs auf **Englisch**, Format `[TYPE] Description`, bei Bugfixes Body mit
  `Cause:`, `5-Why:`, `Test:`. Keine Co-Authored-By-Zeile, keine KI-Hinweise.
- Texte der Oberfläche: englische Quelltexte über `t('workoutcalendar', …)`, deutsche Übersetzung in
  `l10n/de.json` und `l10n/de.js`. Anrede „du“. Kein Hinweis auf Outlook oder andere Clients.
- Die Seite ruft nur die Routen aus `docs/architecture.md` auf. Bodies als JSON, Zahlen als
  JSON-Zahlen.
- 409 `busy` ändert nichts an der Ansicht. Die Seite zeigt einen kurzen Hinweis und wiederholt die
  Anmeldung nie automatisch.
- Löschen von Terminen oder Kalendern nur nach einem Bestätigungsdialog in der Seite, der die Zahl
  der betroffenen Termine nennt (`sync.eventCount`). Zurücksetzen sagt ausdrücklich, dass es sich
  nicht rückgängig machen lässt.
- Gebaute Dateien (`js/`) stehen nicht im Repository (`.gitignore`). Sie entstehen lokal für den Test
  von Hand und später im Release-Workflow.
- Node.js wird nur als Kommandozeilenprogramm installiert, ohne Dienst und ohne Autostart (R-07).

## Schwerpunkte für Reviews

1. **Doppelklick auf eine Aktion** (Anmelden, Kalender wählen, Historie wählen, Abmelden): Der Knopf
   ist während der Anfrage gesperrt, es geht nur eine Anfrage raus. Test in Task 5 und 6.
2. **Seite bleibt offen, während Import oder Umzug laufen:** Die Seite fragt den Status höchstens
   alle 10 Sekunden ab und hört damit auf, sobald nichts mehr läuft oder die Seite verlassen wird.
   Test in Task 6.
3. **Status mit unerwarteten Werten** (unbekannte Fehlerkategorie, `calendar.name` null nach
   gelöschtem Kalender): Die Seite zeigt einen allgemeinen Text statt leerer Stellen oder Fehler.
   Test in Task 4.
4. **Langsame Garmin-Anmeldung** (mehrere Sekunden): Die Seite zeigt „Anmeldung bei Garmin läuft …“
   und bleibt bedienbar. Test in Task 5.
5. **Sitzung abgelaufen (401) oder Netzwerkfehler:** verständlicher Hinweis statt einer leeren Seite.
   Test in Task 4.

## Dateistruktur

| Datei | Aufgabe |
|---|---|
| `lib/Service/SyncRunner.php`, `lib/Controller/ApiController.php`, `lib/Service/AccountService.php`, `lib/BackgroundJob/CalendarMaintenanceJob.php` | Nacharbeiten aus Teil A |
| `docs/adrs/0006-sperre-je-person.md` | Entscheidung zur Sperre je Person |
| `lib/Settings/Personal.php`, `lib/Settings/Section.php` | persönlicher Einstellungsbereich |
| `templates/settings-personal.php` | lädt Skript und Stil, Container `#workoutcalendar-settings` |
| `img/app.svg`, `img/app-dark.svg` | eigenes, neutrales Symbol (Kalenderblatt mit Laufsymbol, keine fremde Marke) |
| `package.json`, `package-lock.json`, `vite.config.ts`, `tsconfig.json`, `.eslintrc.cjs`, `.nvmrc`, `vitest.config.ts` | Frontend-Gerüst |
| `src/settings.ts` | Einstieg, hängt `App.vue` ein |
| `src/api.ts` | Aufrufe der JSON-API, Fehler als `ApiError` |
| `src/types.ts` | Typen von Status und Antworten |
| `src/viewState.ts` | reine Ableitung der Ansicht aus dem Status |
| `src/messages.ts` | Texte zu Fehlerkategorien und Hinweisen |
| `src/App.vue` | wählt Assistent oder Übersicht, lädt und pollt den Status |
| `src/components/wizard/*.vue` | `StepGarmin`, `StepCalendar`, `StepHistory`, `StepDone`, `WizardSteps` |
| `src/components/overview/*.vue` | `OverviewTiles`, `SyncSettings`, `CalendarSection`, `AdvancedSettings`, `AccountSection`, `StatusHints` |
| `src/components/dialogs/*.vue` | `ConfirmCalendarDialog`, `MoveEventsDialog`, `LogoutDialog`, `ResetDialog` |
| `tests/js/**` | Vitest-Tests |
| `.github/workflows/tests.yml` | neuer Job `frontend` |

### Task 1: Nacharbeiten an der Sperre je Person

**Files:**
- Modify: `lib/Service/SyncRunner.php`, `lib/Controller/ApiController.php`, `lib/Service/AccountService.php`, `lib/BackgroundJob/CalendarMaintenanceJob.php`, `docs/architecture.md`
- Create: `docs/adrs/0006-sperre-je-person.md`
- Test: `tests/Unit/Service/SyncRunnerTest.php`, `tests/Unit/Service/AccountServiceTest.php`, `tests/Unit/BackgroundJob/CalendarMaintenanceJobTest.php`, `tests/Unit/Controller/ApiControllerTest.php`

**Interfaces:**
- Produces:
  - `SyncRunner::runNow(string $uid, \DateTimeImmutable $now): RunOutcome`: stellt die Person unter
    der Sperre in die Warteschlange (`nextRunAt = now`) und führt den Lauf in derselben Sperre aus.
    `POST /api/sync` und der Abschluss der Historie nutzen nur noch `runNow`, `ApiController::arm`
    entfällt.
  - `AccountService::login` prüft die Zugangsdaten bei Garmin innerhalb von `PersonLock::run`. Ist
    die Sperre belegt, gibt es keine Anfrage an Garmin.
  - `CalendarMaintenanceJob::finish` speichert unter `PersonLock::run`. Ist die Sperre belegt, reiht
    sich der Job mit unverändertem Argument neu ein (zählt nicht als Fehlversuch).
  - `AccountService::logout(Cleanup::Events)` speichert `SetupState` mit `maintenance = 'remove'`,
    bevor der Job eingereiht wird.
  - ADR 0006 (Nygard-Format, Deutsch): Kontext Wettläufe zwischen Sync-Lauf und Änderungen, Entscheidung
    `PersonLock` mit 409 `busy`, nicht wiedereintrittsfähig, Konsequenzen.
  - `docs/architecture.md`: Formulierung zu `maintenanceFailed` wird „verschwindet bei der Wahl eines
    anderen Kalenders oder einem erfolgreichen Wartungsjob“, und die Zustandstabelle bekommt die Zeile
    `maintenanceFailed` in den Schritten `garmin` und `calendar`.

- [ ] **Step 1: Failing Tests schreiben**

```php
public function testRunNowArmsAndRunsUnderOneLock(): void           // acquireLock genau einmal, nextRunAt danach nach Intervall
public function testLoginDuringRunDoesNotContactGarmin(): void      // Sperre belegt → PersonBusyException, FakeHttpClient ohne Anfrage
public function testFinishRequeuesWhenPersonIsBusy(): void          // add() mit gleichem Argument, maintenance bleibt gesetzt
public function testLogoutSavesRemovalMarkerBeforeQueueing(): void  // Reihenfolge: saveSetup vor IJobList::add
public function testSyncRouteUsesRunNow(): void                     // ApiControllerTest
```

- [ ] **Step 2: Tests laufen lassen** – Run: `composer test` – Expected: FAIL bei den neuen Tests.
- [ ] **Step 3: Umsetzen, ADR 0006 und Doku schreiben**
- [ ] **Step 4: Tests laufen lassen** – Run: `composer test`, danach Push und `gh run watch` – Expected: PASS, CI grün.
- [ ] **Step 5: Commit**

`[BUGFIX] Manual sync, login and maintenance finish run under the per-person lock`
(Body mit `Cause:`, `5-Why:`, `Test:`), dazu `[DOCS] ADR 0006 records the per-person lock`.

### Task 2: Node.js, Frontend-Gerüst und Einstellungsbereich

**Files:**
- Create: `package.json`, `package-lock.json`, `vite.config.ts`, `vitest.config.ts`, `tsconfig.json`, `.eslintrc.cjs`, `.nvmrc`, `src/settings.ts`, `src/App.vue` (Platzhalter „Workout Calendar“), `lib/Settings/Personal.php`, `lib/Settings/Section.php`, `templates/settings-personal.php`, `img/app.svg`, `img/app-dark.svg`, `tests/js/smoke.test.ts`, `tests/Integration/Settings/SettingsRegistrationTest.php`
- Modify: `appinfo/info.xml`, `.gitignore`, `.github/workflows/tests.yml`, `lib/Notification/Notifier.php`, `README.md`, `renovate.json`

**Interfaces:**
- Produces:
  - Node.js per `winget install --id OpenJS.NodeJS.LTS --exact` (vom Nutzer freigegeben, kein
    Dienst). `.nvmrc` = `24`, `package.json` `engines` `node ^24`, `npm ^11.3`, `"type": "module"`.
  - Skripte: `build` (`vite build`), `dev`, `watch`, `lint` (`eslint src tests/js`), `test` (`vitest run`).
  - `vite.config.ts`: `createAppConfig({ settings: resolve(join('src', 'settings.ts')) }, { createEmptyCSSEntryPoints: true, extractLicenseInformation: true, thirdPartyLicense: false })`.
    Ergebnis `js/workoutcalendar-settings.mjs` und `css/workoutcalendar-settings.css`.
  - `templates/settings-personal.php`: `Util::addScript(APP_ID, APP_ID . '-settings')`,
    `Util::addStyle(APP_ID, APP_ID . '-settings')`, `<div id="workoutcalendar-settings"></div>`.
  - `Section implements IIconSection`: `getID() = 'workoutcalendar'`, Name `t('Workout Calendar')`,
    Priorität 80, Symbol `imagePath('workoutcalendar', 'app-dark.svg')`.
  - `Personal implements ISettings`: `getForm()` → `TemplateResponse('workoutcalendar', 'settings-personal', [], '')`,
    `getSection() = 'workoutcalendar'`, `getPriority() = 10`.
  - `info.xml`: `<settings><personal>OCA\WorkoutCalendar\Settings\Personal</personal><personal-section>OCA\WorkoutCalendar\Settings\Section</personal-section></settings>`
    an der vom Schema verlangten Stelle, Version `0.1.2`.
  - `Notifier::prepare` setzt jetzt `setIcon(urlGenerator->getAbsoluteURL(imagePath('workoutcalendar', 'app-dark.svg')))`.
  - `.gitignore`: `js/`, `css/` (gebaut), `node_modules/` bleibt.
  - Workflow: neuer Job `frontend` (Node aus `.nvmrc`, `npm ci`, `npm run lint`, `npm test`, `npm run build`).
  - `renovate.json`: npm wird erfasst, die Regel für Hauptversionen von `@nextcloud/vue` und `vue`
    gilt bereits.

- [ ] **Step 1: Node.js installieren und prüfen**

Run: `winget install --id OpenJS.NodeJS.LTS --exact --accept-package-agreements --disable-interactivity`, danach PATH neu laden, `node -v`, `npm -v`
Expected: `v24.x`, `11.x`. Kein Dienst angelegt (`Get-Service *node*` leer).

- [ ] **Step 2: Failing Tests schreiben**

```ts
// tests/js/smoke.test.ts
it('mounts the settings app into its container', () => { /* App.vue zeigt „Workout Calendar“ */ })
```

```php
// tests/Integration/Settings/SettingsRegistrationTest.php
public function testPersonalSectionAndFormAreRegistered(): void
// ISettingsManager::getPersonalSections() enthält id 'workoutcalendar',
// getPersonalSettings('workoutcalendar') enthält Personal
```

- [ ] **Step 3: Gerüst, Einstellungsbereich und Symbol anlegen**
- [ ] **Step 4: Prüfen** – Run: `npm run lint && npm test && npm run build`, `composer test`, Push, `gh run watch` – Expected: alles grün, `js/workoutcalendar-settings.mjs` gebaut, CI mit Job `frontend` grün.
- [ ] **Step 5: Commit**

`[BUILD] Vue settings scaffold with personal settings section and app icon`

### Task 3: API-Zugriff und Typen

**Files:**
- Create: `src/types.ts`, `src/api.ts`
- Test: `tests/js/api.test.ts`

**Interfaces:**
- Produces:
  - `types.ts`: `Status` genau nach `StatusService::status` (`setup`, `sync`, `failure`, `paused`,
    `settings`), `SetupStep = 'garmin' | 'calendar' | 'history' | 'done'`,
    `Cleanup = 'none' | 'events' | 'calendar'`, `RunOutcome = 'done' | 'skipped' | 'paused' | 'notConnected' | 'calendarMissing' | 'notReady'`,
    `CalendarOption { id: number; name: string; color: string | null }`.
  - `class ApiError extends Error { status: number; code: string }` (`code` aus `error`, sonst `'network'`).
  - `api.ts` mit `@nextcloud/axios` und `generateUrl('/apps/workoutcalendar/api/…')`:
    `getStatus(): Promise<Status>`, `login(email, password): Promise<Status>`,
    `getCalendars(): Promise<{ defaultName: string; calendars: CalendarOption[] }>`,
    `chooseCalendar(input: { name?: string; calendarId?: number; confirmed: boolean; moveEvents: boolean }): Promise<{ needsConfirmation: boolean; existingEvents: number; calendar: { id: number; name: string } | null; status: Status }>`,
    `chooseHistory(historyDays: 30 | 90 | 365 | null): Promise<Status>`,
    `updateSettings(s: { intervalMinutes: number; jitterPercent: number; deletionMode: 'keep' | 'mark' | 'delete'; deletionCheckDays: number }): Promise<Status>`,
    `syncNow(): Promise<{ outcome: RunOutcome; status: Status }>`,
    `logout(cleanup: Cleanup): Promise<Status>`, `reset(cleanup: Cleanup): Promise<Status>`.

- [ ] **Step 1: Failing Tests schreiben** (axios gemockt)

```ts
it('sends numbers as JSON numbers', ...)          // chooseHistory(90) → Body { historyDays: 90 }
it('maps 422 to ApiError with the category', ...) // code 'INVALID_CREDENTIALS', status 422
it('maps 409 to busy', ...)                       // code 'busy'
it('maps a network failure to code network', ...)
it('uses the app routes', ...)                    // URL endet auf /apps/workoutcalendar/api/status
```

- [ ] **Step 2–4:** rot, umsetzen, `npm test` grün.
- [ ] **Step 5: Commit**

`[FEATURE] Typed client for the settings JSON API`

### Task 4: Ansicht aus dem Status ableiten, Texte

**Files:**
- Create: `src/viewState.ts`, `src/messages.ts`
- Test: `tests/js/viewState.test.ts`, `tests/js/messages.test.ts`

**Interfaces:**
- Produces:
  - `type View = 'login' | 'calendar' | 'history' | 'done' | 'overview'` und
    `interface ViewState { view: View; hints: Hint[]; locks: { calendarChange: boolean; cleanup: boolean }; polling: boolean }`.
  - `deriveView(status: Status, wizardFinished: boolean): ViewState`. Regeln exakt nach der Tabelle in
    `docs/architecture.md`: `connected=false` ⇒ `login`; `calendar` ⇒ `calendar`; `history` ⇒
    `history`; `done` ⇒ `done`, solange die Person den Assistenten in dieser Sitzung gerade beendet
    hat (`wizardFinished`), sonst `overview`.
  - `Hint = 'paused' | 'failure' | 'calendarMissing' | 'maintenanceMove' | 'maintenanceRemove' | 'maintenanceFailedMove' | 'maintenanceFailedRemove' | 'backfill'`.
  - `locks.calendarChange` und `locks.cleanup` sind wahr, solange `maintenance` gesetzt ist.
  - `polling` ist wahr bei `backfill.running` oder gesetzter `maintenance`.
  - `messages.ts`: `loginError(code: string): string` mit den Texten aus dem Entwurf
    (Tabelle unter „Schritt 1“) und einem allgemeinen Text für unbekannte Codes;
    `apiErrorText(e: ApiError): string` für `busy` („Gerade läuft ein Sync. Bitte versuche es gleich noch einmal.“),
    `notLoggedIn`/401 („Deine Sitzung ist abgelaufen. Bitte lade die Seite neu.“), `network` und `internal`;
    `hintText(h: Hint, status: Status): string`.

- [ ] **Step 1: Failing Tests schreiben**

Für jede Zeile der Zustandstabelle ein Test (`it.each`), dazu:

```ts
it('falls back to a generic text for unknown failure categories', ...)
it('shows the calendar hint without a name when calendar.name is null', ...)
it('polls only while backfill or maintenance runs', ...)
```

- [ ] **Step 2–4:** rot, umsetzen, `npm test` grün.
- [ ] **Step 5: Commit**

`[FEATURE] Settings view is derived from the status according to the documented state table`

### Task 5: Assistent

**Files:**
- Create: `src/components/wizard/WizardSteps.vue`, `StepGarmin.vue`, `StepCalendar.vue`, `StepHistory.vue`, `StepDone.vue`, `src/components/dialogs/ConfirmCalendarDialog.vue`
- Modify: `src/App.vue`
- Test: `tests/js/wizard/*.test.ts`

**Interfaces:**
- Consumes: `api.ts`, `deriveView`, `messages.ts`.
- Produces (Verhalten nach Entwurf, Abschnitte „Schritt 1“ bis „Schritt 4“):
  - `WizardSteps`: vier Stufen „Garmin“, „Kalender“, „Historie“, „Fertig“, aktuelle hervorgehoben.
  - `StepGarmin`: `NcTextField` E-Mail, `NcPasswordField`, Knopf „Anmelden und weiter“. Während der
    Anfrage gesperrt mit „Anmeldung bei Garmin läuft …“. Fehler über `loginError`. Bei 409 Hinweis,
    keine Wiederholung.
  - `StepCalendar`: `NcSelect` mit `taggable`, vorausgewählt „Neuer Kalender ‚<defaultName>‘“, eigene
    Gruppe „Vorhandene Kalender“. Eingabe eines Namens legt eine neue Option an. „Weiter“ ruft
    `chooseCalendar`; bei `needsConfirmation` öffnet `ConfirmCalendarDialog` mit `existingEvents`
    („In ‚Sport‘ stehen schon 37 Termine …“), „Anderen wählen“ und „‚Sport‘ verwenden“. „Zurück“
    führt zur Anmeldung ohne Abmelden (nur Ansicht).
  - `StepHistory`: vier Auswahlkarten (`NcCheckboxRadioSwitch type="radio"`, eigene Karte) 30 Tage,
    90 Tage (vorausgewählt, „Empfohlen“), 1 Jahr, komplette Historie, je ein Satz wie im Entwurf.
    „Import starten“ ruft `chooseHistory` und zeigt danach den Fortschritt (Zahl, erreichtes Datum,
    „nächste Etappe in etwa 5 Minuten“). „Weiter“ ist jederzeit möglich.
  - `StepDone`: Zusammenfassung (maskierte E-Mail, Kalender, Historie und ob der Import läuft), Satz
    „Neue Aktivitäten erscheinen etwa alle <Intervall> Minuten im Kalender.“, Knopf „Zu den
    Einstellungen“.

- [ ] **Step 1: Failing Tests schreiben**

```ts
it('disables the login button while the request runs and sends one request', ...)   // Doppelklick
it('shows the waiting text during a slow login', ...)
it('shows the Garmin error text for INVALID_CREDENTIALS', ...)
it('does not retry the login after 409', ...)
it('asks before using an existing calendar and shows its event count', ...)
it('creates a calendar with the typed name', ...)
it('preselects 90 days and sends null for the complete history', ...)
it('can continue while the import runs', ...)
```

- [ ] **Step 2–4:** rot, umsetzen, `npm test` grün.
- [ ] **Step 5: Commit**

`[FEATURE] Setup wizard for Garmin sign-in, calendar and history`

### Task 6: Übersicht und Dialoge

**Files:**
- Create: `src/components/overview/OverviewTiles.vue`, `SyncSettings.vue`, `CalendarSection.vue`, `AdvancedSettings.vue`, `AccountSection.vue`, `StatusHints.vue`, `src/components/dialogs/MoveEventsDialog.vue`, `LogoutDialog.vue`, `ResetDialog.vue`, `src/usePolling.ts`
- Modify: `src/App.vue`
- Test: `tests/js/overview/*.test.ts`, `tests/js/usePolling.test.ts`

**Interfaces:**
- Produces (Verhalten nach Entwurf, Abschnitte „Einstellungsseite“, „Abmelden“, „Zurücksetzen“):
  - `OverviewTiles`: Verbindung, „Im Kalender“ (`eventCount`, „seit <Monat Jahr>“ aus
    `oldestActivityDate`), letzter und nächster Sync (relativ), Knopf „Jetzt synchronisieren“.
  - `StatusHints`: Hinweise aus `ViewState.hints` mit `NcNoteCard` (Fehler rot, Wartung gelb).
  - `SyncSettings`: Abstand (15, 30, 60 Minuten, 2, 6, 12, 24 Stunden) und Historie (30, 90, 365 Tage,
    komplett) mit je einem Satz Erklärung.
  - `CalendarSection`: aktueller Kalender, „Ändern“ öffnet die Auswahl wie im Assistenten und danach
    `MoveEventsDialog` („Sollen die bisherigen Termine in den neuen Kalender umziehen?“). Gesperrt
    bei `locks.calendarChange` mit Hinweis.
  - `AdvancedSettings` (eingeklappt, `NcAppSettingsSection` oder eigene Klappe): Streuung an/aus und
    Prozent (0–50), Umgang mit gelöschten Aktivitäten (behalten, markieren, löschen), Prüfzeitraum
    (7–365 Tage), letzter Fehler mit Zeitpunkt, Kategorie und Zahl der Fehlschläge.
  - `AccountSection` (eingeklappt): „Abmelden“ → `LogoutDialog`, „App zurücksetzen“ → `ResetDialog`.
    Beide bieten „Termine der App entfernen (<eventCount>)“ und, nur wenn `createdByApp`, „Kalender
    ‚<Name>‘ löschen“. Bereinigungen sind bei `locks.cleanup` gesperrt. `ResetDialog` sagt, dass sich
    das nicht rückgängig machen lässt.
  - `usePolling(fn, active: Ref<boolean>, intervalMs = 10_000)`: ruft `fn` nur, solange `active`
    wahr ist, stoppt beim Unmount, nie zwei Abrufe gleichzeitig.
  - Nach jeder Aktion übernimmt die Seite den Status aus der Antwort.

- [ ] **Step 1: Failing Tests schreiben**

```ts
it('polls every 10 seconds only while active and stops on unmount', ...)    // Fake-Timer
it('never runs two polls at once', ...)
it('offers calendar deletion only for a calendar created by the app', ...)
it('shows the number of affected events in the logout dialog', ...)
it('says that reset cannot be undone', ...)
it('locks calendar change and cleanup while maintenance runs', ...)
it('asks whether to move events when changing the calendar', ...)
it('sends the clamped settings as numbers', ...)
it('shows busy without changing the view', ...)
```

- [ ] **Step 2–4:** rot, umsetzen, `npm test` grün.
- [ ] **Step 5: Commit**

`[FEATURE] Settings overview with sync status, settings, calendar change, sign-out and reset`

### Task 7: Übersetzung der Oberfläche

**Files:**
- Modify: `l10n/de.json`, `l10n/de.js`
- Create: `tests/js/l10n.test.ts`

**Interfaces:**
- Produces: Deutsch für alle `t('workoutcalendar', …)`- und `n(…)`-Aufrufe in `src/` und für die neuen
  PHP-Texte (Abschnittsname). Der Test liest alle Quelltexte aus `src/**/*.{ts,vue}` per regulärem
  Ausdruck und prüft, dass jeder in `de.json` steht und `de.js` dieselben Übersetzungen enthält.

- [ ] **Step 1: Failing Test schreiben** – `it('has a German translation for every UI string', ...)`
- [ ] **Step 2–4:** rot, Übersetzungen ergänzen (Anrede „du“, Umlaute direkt), `npm test` und `composer test` grün.
- [ ] **Step 5: Commit**

`[FEATURE] German translation of the settings page`

### Task 8: Test von Hand auf der eigenen Instanz und Doku

**Files:**
- Modify: `docs/architecture.md` (Frontend ✅), `README.md` (Installation von Hand, Status), `MACHBARKEIT.md` (Testergebnis)

Dieser Task braucht Freigaben des Nutzers: Schreiben in den Produktiv-Pod, Aktivieren der App und
Anmeldung mit dem echten Garmin-Konto. Jeder Schritt mit `kubectl` läuft aus Git Bash mit
`MSYS_NO_PATHCONV=1`; Pfade im Pod vorher mit `echo` prüfen.

- [ ] **Step 1: Paket bauen**

Run: `npm ci && npm run build`, dann ein Archiv ohne `node_modules`, `vendor`, `tests`, `src`,
`.git`, `php-test`, `.env`, `.garminconnect` (Liste vorher zeigen).
Expected: Archiv mit `appinfo`, `lib`, `templates`, `img`, `js`, `css`, `l10n`.

- [ ] **Step 2: Nach Freigabe in den Pod kopieren und aktivieren**

Ziel `custom_apps/workoutcalendar` des Nextcloud-Pods, danach `php occ app:enable workoutcalendar`
und `php occ background-job:list | grep WorkoutCalendar` (falls der Befehl in 35 fehlt: Hintergrundjob
in der Admin-Übersicht prüfen).
Expected: App aktiviert, `SyncJob` registriert.

- [ ] **Step 3: Durchlauf mit dem echten Konto, gemeinsam mit dem Nutzer**

Assistent komplett (Anmeldung, neuer Kalender „Garmin-Aktivitäten“, 90 Tage), Übersicht prüfen,
„Jetzt synchronisieren“, Termine in der Kalender-App ansehen (Titel „🏃 Laufen – 7,6 km“, Umlaute),
Cron abwarten, Benachrichtigung bei falschem Passwort (absichtlich falsches Passwort eintragen),
Abmelden ohne Bereinigung.
Expected: wie im Entwurf beschrieben; Abweichungen als Issue oder Bugfix mit 5-Why.

- [ ] **Step 4: Doku nachziehen** – Ergebnis in `MACHBARKEIT.md`, Status und Installation in `README.md`.
- [ ] **Step 5: Commit**

`[DOCS] Manual test on the own instance and installation notes`
