# Changelog

All notable changes to this app are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the app uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## 1.1.0 – 2026-11-04

### Added

- Signing in with Garmin's personal API key, the recommended way. The setup step offers two tabs,
  "API key (recommended)" with a short guide and a link to Garmin Connect, and "Email and password".
  The key is exchanged for a token when you enter it and stored encrypted. When Garmin rejects the
  key, the sync pauses, a notification appears, and a box at the top of the overview takes the new
  key. "Sign in again" switches an existing account between the two ways, and the events stay. A key
  is valid for one year at most.
- Garmin accounts with two-step verification. The setup step asks for the code Garmin sends, and
  the overview shows a box at the top when Garmin wants a new code. A code is only requested when
  you ask for it, at most once per minute and five times per hour. "Sign in again" in the Garmin
  account section switches an existing account over.
- A pending code request is kept encrypted for at most 30 minutes and removed by a background job
  afterwards.

### Changed

- The notification for a sign-in that needs a code now says that Garmin asks for a verification
  code and that Workout Calendar is paused until you enter it in the settings.
- The code field is prepared for other ways of delivering the code, such as authenticator apps.
- The App Store entry shows the settings page with the corrected margin from 1.0.1.

## 1.0.1 – 2026-11-03

### Changed

- The App Store entry has a fuller description in English and German, links to the documentation
  and to the issue tracker for questions, and names the licence as an SPDX identifier.
- The App Store entry shows screenshots of the calendar, the settings and the history import.
- The settings page is built with @nextcloud/vue 9.14.1.

### Fixed

- The settings page keeps the same distance to the navigation and the same width as the other
  settings pages instead of starting right at the dividing line.

## 1.0.0 – 2026-11-02

First release.

### Added

- Garmin Connect activities appear as events in a Nextcloud calendar, synced in the background
  by the Nextcloud cron, without anyone being signed in.
- Every person on the instance connects their own Garmin account. The password is stored
  encrypted and only used to sign in.
- A setup wizard in the personal settings: sign in to Garmin, choose a new or an existing
  calendar, choose how far back to import.
- History import of 30 days, 90 days, 1 year or the complete Garmin history. Large imports run
  in stages over several background runs.
- Events with sport icon, sport and distance in the title, and duration, distance, pace or speed,
  heart rate, elevation and a link to Garmin Connect in the description. Activities changed in
  Garmin are updated, each activity becomes exactly one event.
- Choice of what happens to events of activities deleted in Garmin: keep them, mark them as
  cancelled or delete them, checked for a configurable number of days.
- Overview with connection state, last and next sync, last error, event count and imported
  period. Manual sync, adjustable sync interval with optional random variation.
- Changing the calendar later, optionally moving the existing events.
- Signing out of Garmin and resetting the app, each with the choice to keep or remove the
  events.
- A notification when the sync has failed for a day, and a log that names the failed step and
  error category without repeating a lasting failure.
- English and German user interface.
