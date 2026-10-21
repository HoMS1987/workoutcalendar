# Project structure

An overview for anyone who wants to find their way around the code: how the app is built, why it
is built that way and how a sync works. The detailed documentation in `docs/` is German; this page
links to it where it goes deeper.

## In one paragraph

Workout Calendar is a classic Nextcloud app in PHP with a Vue 3 settings page. A background job
started by the Nextcloud cron signs in to Garmin Connect for every connected person, fetches their
activities and writes one calendar event per activity into a calendar of that person. Nothing runs
outside Nextcloud: no Python, no container, no external service.

## Why it is built this way

- **Classic PHP app instead of an external app (ExApp) or a script.** Installing from the App Store
  must be enough, and the sync has to run without anyone being signed in. A PHP app gets both from
  Nextcloud itself: the background job system, per-person settings, encryption and notifications
  ([ADR 0003](adrs/0003-klassische-php-nextcloud-app.md)).
- **Own Garmin client.** Garmin offers no official API for individuals. The client in `lib/Garmin`
  follows the sign-in flow of the Python library `python-garminconnect`, which stays the reference
  when Garmin changes something. HTTP goes through `ext-curl` behind a small `HttpClient`
  interface, because exactly that path was tested against Garmin.
- **Calendar access through the DAV app's `CalDavBackend`**, not through CalDAV over HTTP. No app
  password is needed, and only one class touches Nextcloud's calendar internals
  ([ADR 0005](adrs/0005-calendar-ueber-caldavbackend.md)).
- **A core without Nextcloud classes.** `lib/Garmin`, `lib/Calendar` and `lib/Sync` depend only on
  PSR-3 logging and their own interfaces. They are tested with plain PHPUnit, without a Nextcloud
  server or a Garmin account.
- **Diagnosis built in.** Garmin can block or change its endpoints at any time. Every request is
  logged with its step, status and Cloudflare markers, failures get a category, and secrets are
  removed before anything is logged
  ([ADR 0004](adrs/0004-diagnose-logging-fuer-garmin-zugriffe.md)).

## Folders

| Folder | What is in it |
|---|---|
| `appinfo/info.xml` | App metadata, dependencies, the background job and the settings section |
| `lib/AppInfo` | `Application`: registers services and the notifier |
| `lib/BackgroundJob` | `SyncJob` (every cron run) and `CalendarMaintenanceJob` (moves or removes events in stages) |
| `lib/Service` | Glue between Nextcloud and the core: `SyncRunner`, `AccountService`, `CalendarSetupService`, `StatusService`, `PersonLock` and more |
| `lib/Garmin` | Garmin client: `Auth` (mobile and widget sign-in, token refresh), `Http` (cURL), `Diagnostics` (logging, failure categories, redaction) |
| `lib/Calendar` | Maps an activity to an event and writes and reads iCalendar (RFC 5545) |
| `lib/Sync` | The sync itself: current window, deletions, history import, throttled failure log |
| `lib/CalDav` | `DavCalendarObjects`, the only class using `OCA\DAV`, plus calendar management on top of it |
| `lib/Storage` | Per-person settings, sync state and encrypted credentials in `IUserConfig` |
| `lib/Controller` | `ApiController`: JSON API for the settings page, routes declared as attributes |
| `lib/Notification` | Notification when the sync has failed for a day |
| `lib/Settings`, `templates/` | The section in the personal settings and the page that loads the Vue app |
| `src/` | Settings page in Vue 3 with `@nextcloud/vue`: setup wizard and overview |
| `l10n/` | German translation (`de.json` and `de.js`) |
| `tests/Unit`, `tests/js` | PHPUnit and Vitest tests that need no server |
| `tests/Integration` | Tests inside a real Nextcloud server, run by CI against Nextcloud 33, 34 and 35 |
| `scripts/` | `package.sh` and `sign.sh` build and sign the App Store archive ([ADR 0007](adrs/0007-release-ueber-github-actions-in-den-app-store.md)) |
| `php-test/`, `test_garmin.py` | Scripts to check Garmin access by hand, outside of Nextcloud |
| `docs/` | Requirements, architecture, decisions (ADRs), plans and the release process, in German |

## How a sync works

```
Nextcloud cron (every 5 minutes)
  └─ SyncJob: up to 10 people whose next run is due
       └─ SyncRunner, under a per-person lock
            ├─ GarminClient: load tokens, refresh or sign in again, fetch activities
            ├─ SyncService: create, update, mark or delete events, continue the history import
            │    └─ ActivityEventMapper → IcsSerializer → DavCalendarTarget → CalDavBackend
            └─ save the sync state, schedule the next run, log or notify on failure
```

1. **Who is due.** Every person has a `next_run_at`. `SyncJob` takes the people who waited longest
   first, at most 10 per cron run, so a large instance does not block the cron. The job interval is
   240 seconds on purpose: Nextcloud starts a `TimedJob` only when strictly more than the interval
   has passed, so 300 seconds would skip every second cron run.
2. **Garmin sign-in.** Stored tokens are used first and refreshed when they expire. Without valid
   tokens the client signs in again, first like the mobile app, then through the web widget as a
   fallback. A wrong password or a required second factor stops the attempt; the person is paused
   until they sign in again on the settings page.
3. **Current window.** All activities since the last success, at least the period checked for
   deletions (30 days by default). New activities become events, changed ones are rewritten.
   "Changed" means the fingerprint over the event content differs, so unchanged events are never
   written again and calendar clients have nothing to reload.
4. **Deletions.** Events in the checked period whose activity Garmin no longer returns are kept,
   marked as cancelled or deleted, depending on the setting. If the fetch failed, nothing is deleted.
5. **History import.** Older activities are imported backwards in stages of at most 5 pages of 20
   activities per run. The progress is part of the sync state, so an interrupted import continues
   with the next run.
6. **Next run.** The interval plus an optional random variation is stored as the next run time.

Every event has the UID `garmin-<activityId>`, which is what prevents duplicates. The app only
changes, moves or deletes objects with that UID and URI; events of the person stay untouched.

## The per-person lock

A sync run keeps the person's state in memory and saves it at the end. If the person changed a
setting in between, that change would be lost. Therefore the sync run and every change from the
settings page (sign-in, sign-out, reset, calendar, history, settings) run under one exclusive lock
per person. While a sync holds it, the background job skips the person and the API answers
`409 busy`; the page then simply lets the person try again
([ADR 0006](adrs/0006-sperre-je-person.md)).

## The settings page

`src/App.vue` loads `GET /api/status` and derives the whole view from it (`src/viewState.ts`): the
sign-in form whenever the account is not connected, the wizard steps calendar and history during
setup, and the overview afterwards. While an import, a move of events or a first run is pending, the
page polls the status. All texts go through `@nextcloud/l10n`. Vite builds the page into `js/` and
`css/`, which are not checked in but are part of every release archive.

## Data and secrets

Settings and the sync state are per-person values in `IUserConfig`. The Garmin password and tokens
are encrypted with Nextcloud's `ICrypto` before they are stored, and the `Redactor` keeps them out
of the log. Signing out deletes password and tokens; resetting deletes everything the app stored for
the person.

## Tests and CI

`composer test` and `npm test` run without a server. The GitHub workflow `tests.yml` runs the unit
tests on PHP 8.2 to 8.5, the integration tests inside Nextcloud 33, 34 and 35, lint, type check and
build of the frontend, and builds and signs the release archive with a throwaway key. `release.yml`
publishes a version to the App Store when a GitHub release is published (see
[release.md](release.md)).
