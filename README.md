# Workout Calendar

Nextcloud app (`workoutcalendar`) that automatically adds activities from Garmin Connect as events
to a Nextcloud calendar.

The app is tested against Nextcloud 33 to 35 and runs on the author's own instance.

How the app is built, why and how a sync works is described in English in
[docs/project-structure.md](docs/project-structure.md). The rest of the project documentation is
written in German:

- Requirements: [docs/anforderungen.md](docs/anforderungen.md)
- Architecture: [docs/architecture.md](docs/architecture.md)
- Decisions: [docs/adrs/](docs/adrs/)
- Release process: [docs/release.md](docs/release.md)
- Feasibility and test results: [MACHBARKEIT.md](MACHBARKEIT.md)

The changes per version are listed in [CHANGELOG.md](CHANGELOG.md).

## Disclaimer

Use at your own risk. Signing in with email and password uses the same unofficial
interfaces as the Garmin Connect app; the API key uses Garmin's personal API, which is meant for personal use. Garmin can change or block them at any time, and in
the worst case restrict the account. The project is not affiliated with Garmin.

## Support

The app is free. If it is useful to you and you would like to support its development, you can do
so via [GitHub Sponsors](https://github.com/sponsors/HoMS1987).

## License

[AGPL-3.0-or-later](LICENSE). The software is provided without warranty, see sections 15 and 16 of
the license.

## Requirements

- Nextcloud 33 to 35 with the Calendar app enabled
- PHP 8.2 or newer with `ext-curl`
- Garmin Connect account. Signing in with Garmin's personal API key is recommended (from 1.1.0);
  email and password also work, and two-step verification (email or SMS code) is supported

For development, additionally Composer and Node.js 24 with npm 11. Python 3.13 is optional for the
diagnostic scripts.

## Installation

1. Install and enable the app from the [App Store](https://apps.nextcloud.com/apps/workoutcalendar),
   under *Apps* in your Nextcloud.
2. Make sure background jobs run via system cron (`php occ background:cron`), ideally every
   5 minutes.

Each person then opens *Personal settings → Workout Calendar* and signs in to Garmin there.

### Connect with an API key

The API key is the recommended way from 1.1.0. It needs no password and no verification code.

1. In the setup step "Garmin" (or under "Sign in again" in the Garmin account section), stay on the
   tab "API key (recommended)".
2. Open [Garmin Connect → Settings → Personal API Key](https://connect.garmin.com/app/settings/personalApiToken),
   choose "Create Key" and accept Garmin's terms.
3. Choose "1 year" as the duration and copy the key. Garmin shows it only once.
4. Paste the key into Workout Calendar and choose "Connect and continue". The app exchanges it for
   a token right away and stores the key encrypted.

When the key expires or is deleted, the sync pauses, a notification appears, and a box at the top
of the overview takes the new key. "Sign in again" switches an existing account between the API key
and email and password; the events stay.

### Installing from source

1. Build the settings page: `npm ci` and `npm run build`.
2. Copy `appinfo`, `lib`, `templates`, `img`, `js`, `css`, `l10n` and `LICENSE` into a folder
   `workoutcalendar` in one of the server's app directories (usually `custom_apps`). The files
   belong to the web server user. `vendor`, `node_modules` and the sources in `src/` are not
   needed at runtime.
3. Enable the app: `php occ app:enable workoutcalendar`.

## Development setup

```powershell
composer install
composer test
npm ci
npm run build
```

The unit tests need neither Nextcloud nor a Garmin account.

The settings page is a Vue 3 interface in `src/`. `npm run build` writes it to `js/` and `css/`
(not checked in; building is part of every release). `npm run lint` and `npm test` check the
sources, `npm run watch` rebuilds on changes.

The integration tests (`tests/Integration`) run inside a real Nextcloud server. Usually the GitHub
Action `tests.yml` runs them against Nextcloud 33, 34 and 35. They can also be started in a server
checkout: put the app into `server/apps/workoutcalendar`, install the server
(`php occ maintenance:install`), enable the app and run `composer test:integration` inside the app.

## Examples

### Running tests

```powershell
composer test                                  # all unit tests
composer test:integration                      # only inside a Nextcloud server
vendor\bin\phpunit tests\Unit\Calendar         # event mapping only
npm test                                       # frontend tests
```

### Checking Garmin access by hand

The scripts in `php-test/` and `test_garmin.py` check Garmin access outside of Nextcloud. They
read the credentials from `.env`:

```powershell
Copy-Item .env.example .env   # then fill in GARMIN_EMAIL and GARMIN_PASSWORD
```

`.env` and the token store `.garminconnect/` are listed in `.gitignore`.

```powershell
php-test\run.ps1 -Mode login-mobile    # login like the iOS app, tokens go to .garminconnect/php_tokens.json
php-test\run.ps1 -Mode login-widget    # login via the SSO widget
php-test\run.ps1 -Mode refresh         # refresh the token
php-test\run.ps1 -Mode api -PhpToken   # fetch activities with the PHP token
```

The Python variant uses `python-garminconnect`:

```powershell
python -m venv .venv
.venv\Scripts\python.exe -m pip install -r requirements.txt
.venv\Scripts\python.exe test_garmin.py
```

## Limitations

- Signing in with email and password runs over unofficial interfaces and can break at any time. The API key uses Garmin's official personal API.
- An API key is valid for one year at most. Workout Calendar does not know the expiry date, only the day you entered the key.
- The responses of the personal API have not been verified on a real account yet: the activity list, the status for a deleted key, and the claims in the token. Garmin's terms beyond its FAQ ("for personal use") are not verified either.
- Whether the login works from a server container depends on IP address and TLS fingerprint. It
  works on the author's own instance (Talos cluster); Garmin may decide differently for other
  servers.
- With two-step verification (from 1.1.0), Workout Calendar only requests a code when you ask
  for it. After the code the sync continues with the refresh token (according to
  python-garminconnect, not yet verified on a real account; how long the refresh token stays valid
  is unknown). If Garmin rejects the saved sign-in, the sync pauses, a notification appears, and you
  enter a new code in the settings. Authenticator apps are provided for but untested, since
  Garmin currently offers email and SMS.
- New activities appear with a delay of up to the configured interval plus the cron period.
