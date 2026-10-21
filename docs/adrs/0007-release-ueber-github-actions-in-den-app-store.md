# 7. Release über GitHub Actions in den App Store

Datum: 2026-10-21

## Status

Accepted

## Kontext

Die App soll über den App Store von Nextcloud installierbar sein (F-01). Der App Store verlangt
ein tar.gz-Archiv mit genau einem Ordner `workoutcalendar`, das er über eine öffentliche
HTTPS-Adresse lädt, eine SHA-512-Signatur über das Archiv und eine Code-Signatur
(`appinfo/signature.json`), die Nextcloud bei der Installation prüft. Beide Signaturen entstehen
mit dem privaten Schlüssel zu einem Zertifikat, das Nextcloud über das Repository
`nextcloud/app-certificate-requests` ausstellt. Die Versionshinweise liest der App Store aus
`CHANGELOG.md`: aus der ersten Überschrift `## <Version>`, die der Version in `info.xml` entspricht.

Die Vorlage `appstore-build-publish.yml` aus `nextcloud/.github` läuft nur in der Organisation
`nextcloud-releases` und baut über krankerl oder ein Makefile.

## Entscheidung

- Ein eigener Workflow `.github/workflows/release.yml` nach dem Muster der Vorlage startet, sobald
  ein GitHub-Release veröffentlicht wird. Er prüft, dass das Tag `v` plus die Version aus
  `info.xml` ist, baut die Oberfläche, packt mit `scripts/package.sh`, signiert mit
  `scripts/sign.sh` (`occ integrity:sign-app` aus einem Checkout von `nextcloud/server`, danach die
  Signatur über das Archiv), hängt das Archiv an das Release und meldet es über die REST-API beim
  App Store an.
- `scripts/package.sh` legt nur die Laufzeitdateien ins Archiv und bricht ab, wenn die Oberfläche
  fehlt, eine CSS-Datei aus einem älteren Build übrig ist oder das Archiv größer als 20 MB wird.
  Der Job `release-archive` der Tests geht bei jedem Push denselben Weg mit einem Wegwerf-Schlüssel
  und prüft die Signatur, damit ein Fehler im Ablauf nicht erst beim Release auffällt.
- Der private Schlüssel liegt nur außerhalb des Repositories beim Entwickler und als Secret
  `APP_PRIVATE_KEY` in GitHub, das Token für den App Store als `APPSTORE_TOKEN`. Der Workflow
  schreibt den Schlüssel nur in das temporäre Verzeichnis des Runners und löscht ihn nach dem
  Signieren.
- Ein Test (`ReleaseMetadataTest`) prüft, dass `package.json`, `package-lock.json` und `info.xml`
  dieselbe Version tragen und `CHANGELOG.md` einen datierten Eintrag dafür hat.

## Konsequenzen

- Ein Release besteht aus Version anheben, Changelog ergänzen, `dev` nach `main` mergen und auf
  GitHub ein Release mit dem passenden Tag veröffentlichen. Alles Weitere läuft automatisch.
- Das Repository muss öffentlich sein, sonst kann der App Store das Archiv nicht laden.
- Ohne Freigabe des Zertifikats durch Nextcloud kann kein Release erscheinen.
- Wer den Schlüssel hat, kann Versionen der App veröffentlichen. Geht er verloren oder wird er
  bekannt, muss über `nextcloud/app-certificate-requests` ein neues Zertifikat beantragt werden.
- `occ` braucht die PHP-Erweiterung `posix`, unter Windows lässt sich das Signieren deshalb nicht
  lokal ausprobieren. Der Job `release-archive` deckt das ab.
