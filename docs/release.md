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

## Branches

- Jede Änderung entsteht auf einem eigenen Branch (`feature/…`, `bugfix/…`, `ci/…` usw.) und kommt
  per Pull Request mit Squash-Merge nach `dev`.
- `main` enthält nur veröffentlichte Stände und wird zum Release per Fast-Forward auf `dev` gesetzt.
- Die Tests laufen für Pull Requests und für Pushes auf `dev` und `main`. Ein neuer Push auf einen
  Pull Request bricht dessen älteren Lauf ab.
- Regeln auf GitHub (*Settings → Rules → Rulesets*):
  - `dev`: Änderungen nur per Pull Request mit Squash-Merge, alle Jobs aus `tests.yml` müssen grün
    sein, kein Force-Push, kein Löschen.
  - `main`: nur lineare Historie, kein Force-Push, kein Löschen.
  - Kommt in `tests.yml` ein Job dazu oder ändert sich die Matrix (etwa eine neue PHP-Version),
    gehören die neuen Namen in die Liste der Pflicht-Checks von `dev`.

## Für jede Version

1. Von `dev` einen Branch `chore/release-<x.y.z>` abzweigen.
2. Die Version in `appinfo/info.xml` anheben und mit `npm version <x.y.z> --no-git-tag-version` in
   `package.json` und `package-lock.json` übernehmen.
3. In `CHANGELOG.md` die Einträge unter `## [Unreleased]` in einen neuen Abschnitt
   `## <x.y.z> – <JJJJ-MM-TT>` verschieben. Der App Store erkennt nur diese Form, ohne eckige
   Klammern um die Version. `ReleaseMetadataTest` prüft beides.
4. Pull Request nach `dev`, nach grünen Tests mergen.
5. `main` per Fast-Forward nachziehen: `git fetch && git push origin origin/dev:main`.
6. Auf GitHub ein Release mit dem Tag `<x.y.z>` auf `main` veröffentlichen, als Text den Abschnitt
   aus dem Changelog.
7. Der Workflow *Release* baut, signiert, hängt `workoutcalendar-<x.y.z>.tar.gz` an das Release und
   lädt die Version in den App Store. Nach einigen Minuten erscheint sie dort.

## Pre-Release (Release Candidate)

Eine Version mit Zusatz wie `1.1.0-rc.1` behandelt der App Store als Beta. Der Ablauf ist derselbe,
mit drei Unterschieden:

- Die Version heißt `<x.y.z>-rc.<n>`. Build-Metadaten (`+…`) nimmt der App Store nicht an.
- Der Changelog bekommt keinen datierten Abschnitt. Die Einträge bleiben unter `## [Unreleased]`,
  denn daraus liest der App Store die Versionshinweise. `ReleaseMetadataTest` prüft, dass der
  Abschnitt nicht leer ist.
- Das Release auf GitHub wird als *Pre-release* markiert. Der Workflow *Release* bricht ab, wenn
  Markierung und Version nicht zusammenpassen.

Nextcloud bietet Beta-Versionen aus dem App Store nur Instanzen auf dem Update-Kanal `beta` oder
`daily` an. Auf einer Instanz mit dem Kanal `stable` wird ein Release Candidate von Hand
installiert: das signierte Archiv vom GitHub-Release nach `custom_apps/` entpacken, ohne
`.git`-Ordner. Die fertige Version kommt dort danach regulär aus dem App Store, weil Nextcloud
`1.1.0` als neuer als `1.1.0-rc.1` erkennt.

## Archiv von Hand prüfen

```bash
npm ci && npm run build
bash scripts/package.sh
tar -tzf build/artifacts/workoutcalendar.tar.gz
```
