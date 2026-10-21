# Changelog

All notable changes to this app are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the app uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## 1.0.0 – 2026-10-21

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
