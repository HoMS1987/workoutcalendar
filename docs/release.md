# Release

Wie eine Version der App in den App Store von Nextcloud kommt. Die Gründe stehen in ADR 0007.

## Einmalig: Zertifikat und Zugang zum App Store

1. Schlüssel und Zertifikatsanfrage erzeugen, außerhalb des Repositories:

   ```bash
   mkdir -p ~/.nextcloud/certificates && cd ~/.nextcloud/certificates
   openssl req -nodes -newkey rsa:4096 -keyout workoutcalendar.key -out workoutcalendar.csr -subj "/CN=workoutcalendar"
   ```

2. Im Repository `nextcloud/app-certificate-requests` einen Pull Request mit der Datei
   `workoutcalendar/workoutcalendar.csr` öffnen. Das GitHub-Profil muss dafür eine E-Mail-Adresse
   zeigen. Nach der Freigabe liegt dort `workoutcalendar/workoutcalendar.crt`.
3. Auf apps.nextcloud.com anmelden und die App registrieren: Zertifikat einfügen und die Signatur
   der App-ID angeben:

   ```bash
   echo -n "workoutcalendar" | openssl dgst -sha512 -sign ~/.nextcloud/certificates/workoutcalendar.key | openssl base64
   ```

4. Unter apps.nextcloud.com/account/token das API-Token kopieren.
5. In GitHub unter *Settings → Secrets and variables → Actions* zwei Secrets anlegen:
   `APP_PRIVATE_KEY` mit dem Inhalt von `workoutcalendar.key` und `APPSTORE_TOKEN` mit dem Token.

Der Schlüssel kommt nie ins Repository. Eine Sicherung gehört an einen Ort, der nicht mit dem
Repository geteilt wird, etwa einen Passwortmanager.

## Für jede Version

1. Auf `dev` die Version in `appinfo/info.xml` anheben und mit `npm version <x.y.z> --no-git-tag-version`
   in `package.json` und `package-lock.json` übernehmen.
2. In `CHANGELOG.md` die Einträge unter `## [Unreleased]` in einen neuen Abschnitt
   `## <x.y.z> – <JJJJ-MM-TT>` verschieben. Der App Store erkennt nur diese Form, ohne eckige
   Klammern um die Version. `ReleaseMetadataTest` prüft beides.
3. Commit, Push, warten bis die Tests grün sind.
4. `dev` nach `main` mergen.
5. Auf GitHub ein Release mit dem Tag `v<x.y.z>` auf `main` veröffentlichen, als Text den Abschnitt
   aus dem Changelog.
6. Der Workflow *Release* baut, signiert, hängt `workoutcalendar-v<x.y.z>.tar.gz` an das Release und
   lädt die Version in den App Store. Nach einigen Minuten erscheint sie dort.

Eine Version mit Zusatz wie `1.1.0-beta.1` behandelt der App Store als Beta. Ihre Versionshinweise
liest er aus dem Abschnitt `## [Unreleased]`.

## Archiv von Hand prüfen

```bash
npm ci && npm run build
bash scripts/package.sh
tar -tzf build/artifacts/workoutcalendar.tar.gz
```
