# Anforderungen

Stand: 2026-10-20. Offene Punkte stehen am Ende und werden hier nachgetragen, sobald sie geklärt
sind.

## Ziel

Garmin-Connect-Aktivitäten erscheinen automatisch als Termine in einem Nextcloud-Kalender.

Damit wird der bisherige Weg überflüssig: Heute synchronisiert Garmin die Aktivitäten in einen
Kalender des Microsoft-Kontos, den Outlook auf dem Hauptrechner anzeigt. Künftig liegt der Kalender
in Nextcloud, Outlook bindet ihn über das vorhandene CalDAV-Plugin ein.

## Rahmenbedingungen

| ID | Rahmenbedingung |
|---|---|
| R-01 | Nextcloud läuft selbst gehostet als Helm-Chart auf einem Talos-Kubernetes-Cluster. |
| R-02 | AppAPI und Deploy-Daemon sind nicht aktiv und sollen nicht nötig sein. |
| R-03 | Das Nextcloud-Konto nutzt Zwei-Faktor-Anmeldung. |
| R-04 | Das Garmin-Konto nutzt keine Zwei-Faktor-Anmeldung. |
| R-05 | Garmin bietet Privatpersonen keine offizielle API. Der Zugriff läuft über inoffizielle Schnittstellen (ADR 0003). |
| R-06 | Strava kommt als Datenquelle nur in Frage, wenn die API kostenlos ist. |
| R-07 | Auf dem Entwicklungsrechner laufen keine Dienste und nichts startet automatisch mit dem System. |

## Funktionale Anforderungen

### Installation und Einrichtung

| ID | Anforderung | Prio |
|---|---|---|
| F-01 | Die App ist über den integrierten App Store von Nextcloud installierbar. | Muss |
| F-02 | In den persönlichen Einstellungen lassen sich Garmin-E-Mail und -Passwort hinterlegen. | Muss |
| F-03 | Eine Schaltfläche prüft die Garmin-Anmeldung und zeigt das Ergebnis an. | Soll |
| F-04 | Der Zielkalender ist einstellbar: ein neuer, von der App angelegter Kalender oder ein vorhandener. Standard: neuer Kalender „Garmin-Aktivitäten“. | Muss |
| F-05 | Jede Person der Instanz hinterlegt ihr eigenes Garmin-Konto und hat eigene Einstellungen. | Muss |
| F-06 | Nach der Installation sind alle Einstellungen mit sinnvollen Standardwerten belegt (siehe Standardwerte). | Muss |
| F-07 | Vorgemerkt: Garmin-Konten mit Two-Step Verification. Verlangt Garmin einen Code (`MFA_REQUIRED`), wird er einmalig in den Einstellungen eingegeben. Danach arbeitet der Sync nur mit dem Refresh-Token weiter. Scheitert der Refresh, zeigen die Einstellungen an, dass ein neuer Code nötig ist. Voraussetzung: Der Refresh funktioniert ohne zweiten Faktor (ungeprüft). | Kann |

### Synchronisation

| ID | Anforderung | Prio |
|---|---|---|
| F-10 | Neue Aktivitäten werden automatisch im Hintergrund übernommen, ohne dass jemand angemeldet sein muss. | Muss |
| F-11 | Jede Aktivität erzeugt genau einen Termin. Wiederholte Syncs erzeugen keine Duplikate (UID `garmin-<activityId>`). | Muss |
| F-12 | Ändert sich eine Aktivität in Garmin (z. B. Name), wird der Termin beim nächsten Sync aktualisiert. | Soll |
| F-13 | Der Umgang mit in Garmin gelöschten Aktivitäten ist einstellbar: Termin behalten, als gelöscht markieren oder löschen. Standard: markieren. Geprüft wird ein einstellbarer Zeitraum rückwirkend. | Soll |
| F-14 | Ein manueller Sync lässt sich in den Einstellungen auslösen. | Kann |
| F-15 | Das Sync-Intervall ist einstellbar, mit Untergrenze. | Muss |
| F-16 | Optional streuen die Abrufzeitpunkte zufällig um das Intervall. Ein- und ausschaltbar, Streuung einstellbar. | Soll |

### Historien-Import

| ID | Anforderung | Prio |
|---|---|---|
| F-20 | Beim ersten Sync ist einstellbar, wie weit zurück importiert wird. | Muss |
| F-21 | Eine Option importiert die komplette Garmin-Historie. | Muss |
| F-22 | Ein großer Import läuft in Etappen über mehrere Hintergrundläufe und blockiert den Nextcloud-Cron nicht. | Muss |

### Termininhalt

| ID | Anforderung | Prio |
|---|---|---|
| F-30 | Titel: Symbol, Sportart und Distanz, z. B. „🏃 Laufen – 7,6 km“. Ohne Distanz nur Symbol und Sportart. | Muss |
| F-31 | Beginn und Ende entsprechen Startzeit und Dauer der Aktivität. | Muss |
| F-32 | Beschreibung mit Aktivitätsname, Dauer, Distanz, Pace bzw. Geschwindigkeit, Herzfrequenz (Ø, max), Höhenmetern und Link zur Aktivität in Garmin Connect. Fehlende Werte werden weggelassen. | Muss |
| F-33 | Umlaute, ß und Emojis werden korrekt übernommen. | Muss |
| F-34 | Ein als gelöscht markierter Termin erhält den Status „abgesagt“ und den Titelzusatz „(in Garmin gelöscht)“. | Soll |

### Diagnose

| ID | Anforderung | Prio |
|---|---|---|
| F-40 | Sperrt oder ändert Garmin den Zugriff, zeigt das Log, welcher Schritt mit welcher Fehlerkategorie gescheitert ist (ADR 0004). | Muss |
| F-41 | Das Log zeigt, welche Login-Strategie erfolgreich war, sodass ein Ausfall des Hauptwegs auffällt. | Muss |
| F-42 | Die Einstellungen zeigen den letzten Sync-Zeitpunkt und den letzten Fehler in verständlicher Form. | Soll |
| F-43 | Ein anhaltender Fehler füllt das Log nicht: ausführlich geloggt wird nur ein neuer Fehler oder ein Wechsel von Kategorie bzw. Schritt, solange er anhält höchstens einmal täglich eine Erinnerung mit der Zahl der Fehlschläge, nach Behebung ein Eintrag „wieder erfolgreich nach X Fehlschlägen“. | Muss |

## Standardwerte

| Einstellung | Standard | Auswahl |
|---|---|---|
| Zielkalender | neuer Kalender „Garmin-Aktivitäten“ | neu oder vorhandener Kalender |
| Historien-Import beim ersten Sync | 90 Tage | 30 Tage, 90 Tage, 1 Jahr, komplette Historie |
| Sync-Intervall | 30 Minuten | 15 Minuten bis 24 Stunden |
| Zufällige Streuung | an, ±20 % | aus, 0–50 % |
| In Garmin gelöschte Aktivitäten | markieren | behalten, markieren, löschen |
| Prüfzeitraum für Löschungen | 30 Tage | 7–365 Tage |

Der Nextcloud-Cron läuft typischerweise alle 5 Minuten, Intervall und Streuung wirken daher nur in
diesem Raster. Ob gleichmäßige Abstände für Garmin überhaupt ein Erkennungsmerkmal sind, ist nicht
belegt. Die Streuung verteilt aber in jedem Fall die Abrufe mehrerer Personen einer Instanz.

## Nicht-funktionale Anforderungen

| ID | Anforderung |
|---|---|
| NF-01 | Passwörter, Tokens und Tickets erscheinen nie im Log, in Fehlermeldungen oder im Repository. |
| NF-02 | Garmin-Passwort und Tokens werden verschlüsselt gespeichert. |
| NF-03 | Die App belastet Garmin nicht unnötig: Tokens werden wiederverwendet, ein neuer Login erfolgt nur, wenn der Refresh scheitert. Nach abgelehntem Passwort folgt kein weiterer Login-Versuch. |
| NF-04 | Der Kern (Garmin-Zugriff, Termin-Abbildung, Sync-Ablauf) ist ohne Nextcloud mit PHPUnit getestet. |
| NF-05 | Unterstützt werden Nextcloud 33 bis 35 und PHP ab 8.2. |
| NF-06 | Bugfixes folgen dem TDD-Ablauf mit 5-Why-Analyse. |
| NF-07 | Oberfläche und Termintexte sind übersetzbar (Nextcloud-l10n), mitgeliefert werden Englisch und Deutsch. |

## Abgrenzung

- Keine Rückrichtung: Termine in Nextcloud ändern nichts in Garmin.
- Keine Gesundheitsdaten außer den Aktivitäten (Schlaf, Schritte, Gewicht).
- Kein Import über Strava, solange Garmin direkt funktioniert.
- Garmin-Konten mit Zwei-Faktor-Anmeldung werden vorerst nicht unterstützt (vorgemerkt als F-07).

## Offene Fragen

| ID | Frage | Stand |
|---|---|---|
| O-01 | Legt die App einen eigenen Kalender an, oder wählt man einen vorhandenen aus? | geklärt: einstellbar (F-04) |
| O-02 | Sollen Termine gelöscht werden, wenn die Aktivität in Garmin gelöscht wird? | geklärt: einstellbar, Standard markieren (F-13, F-34) |
| O-03 | Wie oft soll synchronisiert werden? | geklärt: einstellbar, mit optionaler Streuung (F-15, F-16) |
| O-04 | Nur ein Garmin-Konto für die eigene Nutzung, oder jede Person der Instanz mit eigenem Konto? | geklärt: mehrere Personen (F-05) |
| O-05 | Sprache der Oberfläche und Termine: nur Deutsch oder übersetzbar (App Store)? | geklärt: übersetzbar (NF-07) |
| O-08 | Die Standardwerte der Einstellungen sind ein Vorschlag. Passen sie so? | geklärt: bestätigt |
| O-09 | Garmin-Konten mit Two-Step Verification später unterstützen? Relevant, sobald EKG an einer Uhr aktiviert wird. | geklärt: vorgemerkt als F-07 |
| O-06 | Welche Nextcloud-Version läuft auf der Instanz? | geklärt: 35.0.2, liegt im unterstützten Bereich (NF-05) |
| O-07 | Funktioniert der Garmin-Login aus dem Nextcloud-Container im Cluster? | Test steht aus |
