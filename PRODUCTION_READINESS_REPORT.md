# Athletikclub Steiermark
## Production Readiness Report

Stand: 25.09.2026 · Grundlage: Codebasis `main` (PHP 8, MariaDB, Hetzner Webhosting) · Phase 3 (Audit)

> Dieser Bericht behauptet nicht, dass das System „sicher“ ist. Er dokumentiert, **was** geprüft wurde, **wie**,
> was behoben wurde und welche Risiken bestehen bleiben. Nicht Prüfbares ist ausdrücklich gekennzeichnet.

---

### 1. Zusammenfassung

Die Plattform (≈ 36.000 Zeilen PHP, 73 Dashboard-Seiten, 16 öffentliche Seiten, 36 Include-Module, 12 Migrationen)
wurde vollständig durchsucht und in allen sicherheits- und finanzrelevanten Pfaden gezielt geprüft.

- **Gefunden:** 1 × CRITICAL, 9 × HIGH, 15 × MEDIUM, 8 × LOW (Details in Abschnitt 3).
- **Behoben:** alle CRITICAL- und HIGH-Punkte außer einer **fachlichen Entscheidung** (zwei parallele Trainer-Vergütungsmodelle, siehe 9.1),
  alle MEDIUM-Punkte mit Sicherheits- oder Finanzbezug.
- **Datenbank:** keine neue Migration nötig; alle Korrekturen sind Code-Änderungen. Bestehende Daten wurden nicht verändert.
- **Tests:** 73 Seiten × 3 Rollen ohne Fehler, 6 Kern-Workflows (39 Prüfungen) und 16 Grenzfälle bestanden, Rechte- und Spam-Tests bestanden.

Das System ist aus technischer Sicht für den Produktivbetrieb vorbereitet, **sofern die Punkte der Deployment-Checkliste
und die offenen Entscheidungen in Abschnitt 9 erledigt werden**.

---

### 2. Geprüfte Module

| Bereich | Umfang der Prüfung |
|---|---|
| Authentifizierung | Login (Sperre nach 5 Fehlversuchen/E-Mail bzw. 20/IP in 15 min), Logout, `password_hash` (bcrypt, cost 12), Passwort-Reset, Registrierung, E-Mail-Bestätigung, Session-Fixation, Timeout, Cookie-Flags |
| Autorisierung / IDOR | alle 35 Dashboard-/API-Seiten mit ID-Parametern einzeln (Lade-Abfrage + Besitz-/Rechteprüfung), alle `DELETE`/`UPDATE`-Aktionen auf Nicht-Admin-Seiten, Downloads, Exporte, globale Suche |
| Rollen & Rechte | Grundrolle (`users.rolle`: mitglied/trainer/admin) + RBAC (`roles`, `role_permissions`, 48 Rechte); Abgleich Menü ↔ Seitenschutz |
| SQL-Injection | Tokenizer-Scan aller `prepare/query/exec`-Aufrufe (121 dynamische Stellen einzeln bewertet) |
| XSS | Scan aller 1.641 Kurz-Ausgaben ohne erkennbares Escaping; Text- und URL-Felder einzeln verfolgt |
| CSRF | alle POST-Formulare (Token je Session, `hash_equals`); GET ohne Zustandsänderung (Ausnahme: QR-Check-in, nur mit geheimem 128-Bit-Code und Kursleitungsrecht) |
| Uploads/Downloads | alle 17 Upload-Stellen (PDF-Signatur + finfo, GD-Neukodierung bei Bildern, Zufallsnamen, gesperrte Verzeichnisse), Download-Handler |
| Öffentliche Bereiche | Kursportal, Buchung, Warteliste, Buchungs-/Rechnungs-Token-Seiten, Kontakt-, Mitglieds- und Trainerformular, Passwort vergessen |
| Finanzen | Rechnungsnummern, Positionen/USt, Teil-/Überzahlung, Storno, Mahnstufen, Trainerabrechnung, Provisionsabrechnung, Umsatz- und Förderkennzahlen, Rundung (bcmath) |
| Datenintegrität | alle Fremdschlüssel mit `ON DELETE CASCADE`, alle harten Löschungen von Kernobjekten |
| Automationen/Cron | Sperre, Idempotenz (zweiter Lauf erzeugt 0 Aktionen), Fehler-Logging, Cron-Schlüssel |
| E-Mail | zentrale Funktion, Header-Injection, Kodierung, Protokoll, alle Direktaufrufe |
| Konfiguration | Secrets (inkl. Git-Historie des **öffentlichen** GitHub-Repos), Debug/Fehlerausgabe, Security-Header, Verzeichnisschutz (live geprüft) |
| Performance | Renderzeit aller Seiten gegen realistische Testdaten (487 Kurse, ~4.000 Anmeldungen) |

**Nicht oder nur eingeschränkt prüfbar:** echter Mailversand und Zustellbarkeit (SPF/DKIM), Verhalten unter echter
Parallel-Last auf MariaDB (Sperren wurden per Code-Review und Einzeltests geprüft), Browser-Tests in Safari/Firefox
(geprüft: Edge/Chromium; keine browserspezifischen APIs im Einsatz), Datenbank-Zeitzone des Hetzner-Servers
(nach dem Deployment im Systemstatus ablesbar).

---

### 3. Gefundene Probleme

| # | Stufe | Problem | Status |
|---|---|---|---|
| 1 | CRITICAL | Interne Dateien direkt aufrufbar: `config/database.php`, `includes/*.php`, `vendor/*`, `composer.json` (Verzeichnisschutz griff nur auf Dateinamen) | behoben |
| 2 | HIGH | Deaktivierte Konten und entzogene Admin-Rollen wirkten erst beim nächsten Login (Sitzung behielt Rolle) | behoben |
| 3 | HIGH | Umsatzübersicht: Kursumsatz um den Preis jedes Kurses ohne Zahlung zu hoch (Testdaten: 31.875 € statt 31.442 €) | behoben |
| 4 | HIGH | Parallele Rechnungsausstellung konnte eine Lücke in der fortlaufenden Rechnungsnummer erzeugen | behoben |
| 5 | HIGH | Automations-Sperre nicht atomar → Cron und Seitenaufruf konnten gleichzeitig laufen (Gefahr doppelter Mails/Mahnungen) | behoben |
| 6 | HIGH | Herabgestufter Admin hätte seine Rechte über die RBAC-Rolle behalten; Grundrolle war nach Anlage gar nicht änderbar | behoben |
| 7 | HIGH | Löschen einer Gemeinde-Kooperation entfernte kaskadierend alle Perioden/Abrechnungsdaten und Dokumente ohne Prüfung | behoben |
| 8 | HIGH | Öffentliche Formulare schrieben Besucher-Betreff ungefiltert in die Mail-Betreffzeile; 9 Mailstellen umgingen Protokoll, Versandschalter und UTF-8 | behoben |
| 9 | HIGH | Fehlerprotokoll im Produktionsbetrieb vollständig abgeschaltet (`error_reporting(0)`) – Fehler waren nicht nachvollziehbar | behoben |
| 10 | HIGH | Zwei parallele Trainer-Vergütungsmodelle (Provision auf Kursumsatz **und** Honorar je Einheit) → Doppelzahlung möglich | **Entscheidung nötig** (Warnung eingebaut) |
| 11 | MEDIUM | Kein Sitzungs-Timeout bei Inaktivität | behoben (4 h) |
| 12 | MEDIUM | Datenbank-Zeitzone nicht an PHP (Europe/Vienna) gekoppelt → Rate-Limits/Fristen können verrutschen | behoben |
| 13 | MEDIUM | Kontakt-/Mitglieds-/Trainerformular ohne Spam- und Mengenschutz | behoben |
| 14 | MEDIUM | „Passwort vergessen“ ohne Drosselung (Mail-Flut an fremde Adressen) | behoben |
| 15 | MEDIUM | Passwort-Reset-, Einladungs- und Bestätigungs-Tokens im Klartext gespeichert | behoben (SHA-256) |
| 16 | MEDIUM | Gespeichertes XSS über Video-Links (`javascript://…` besteht `FILTER_VALIDATE_URL`) | behoben |
| 17 | MEDIUM | Übung konnte von jedem Trainer gelöscht werden (Button nur für Ersteller sichtbar) | behoben |
| 18 | MEDIUM | Leistungsdaten-Export (mit Geburtsdatum) aller Mitglieder für jeden Trainer | behoben (sonst nur eigene) |
| 19 | MEDIUM | Projektleitung konnte beliebige Förderung als Standard-Förderung setzen und Kurse aus fremden Projekten übernehmen | behoben |
| 20 | MEDIUM | Selbst-Abmeldung im Dashboard umging die Stornofrist des Buchungsportals | behoben |
| 21 | MEDIUM | Überzahlung einer Rechnung wurde stillschweigend akzeptiert (negativer offener Betrag) | behoben |
| 22 | MEDIUM | Provisionsabrechnung: zwischen Lesen und Markieren eingehende Zahlungen wurden als abgerechnet markiert, ohne im Betrag zu sein | behoben (Stichzeitpunkt) |
| 23 | MEDIUM | Nachträglich bestätigte Einheiten nach abgeschlossener Monatsabrechnung wären nie abgerechnet worden (Fund aus Phase 2, bestätigt) | behoben |
| 24 | MEDIUM | Download-Handler: Dateiname ungefiltert im Header, kein Pfad-Check, immer `application/pdf` | behoben |
| 25 | MEDIUM | Förderung mit Dokumenten löschbar (Bescheide/Nachweise wären kaskadierend gelöscht worden) | behoben |
| 26 | LOW | `e(null)` führte bei leeren DB-Feldern zu Seitenabbruch (TypeError) | behoben |
| 27 | LOW | Gleichzeitiger Doppelscan beim Check-in → Fehlerseite | behoben |
| 28 | LOW | Unzulässiger `<Directory>`-Block in `.htaccess` (hätte bei aktivem mod_php7 einen 500er ausgelöst) | behoben |
| 29 | LOW | Kritische Aktionen mit allgemeinen Rückfragen („Wirklich ausführen?“); Deaktivieren ohne Rückfrage | behoben für: Nutzer deaktivieren, Rolle ändern/entziehen, Kurs absagen, Rechnung stornieren, Projekt archivieren, Einheit absagen, Buchung stornieren, Förderung/Kooperation löschen |
| 30 | LOW | Umsatz-/Fördersummen im Altmodul mit Fließkomma statt bcmath | behoben |
| 31 | LOW | Upload-Logik an 3 Stellen dupliziert (gleich sicher wie die zentrale Funktion) | offen (Wartbarkeit) |
| 32 | LOW | Rund 40 weitere Rückfragen noch allgemein formuliert (unkritische Aktionen) | offen |
| 33 | LOW | Sicherheitsereignisse wurden nicht gesondert protokolliert | behoben (`securityLog`) |

---

### 4. Behobene Probleme – wesentliche Dateien

| Datei | Änderung |
|---|---|
| `.htaccess` | Sperre für `config/ includes/ sql/ vendor/ docs/ keys/ uploads/ logs/`, `*.md`, `composer.*`, versteckte Dateien (außer `.well-known`); HSTS; ungültigen Block entfernt |
| `config/config.php` | Produktions-Fehlerlogging nach `logs/php-error.log` (gesperrt), neutrale Fehlerseite statt technischer Details |
| `includes/auth.php` | Sitzungsprüfung (Timeout, Kontostatus, Rollen live), DB-Zeitzone, `securityLog`, `bestaetigen()`, Spam-Schutz für Formulare, `tokenHash()`, nullsicheres `e()` |
| `includes/rechnungen.php` | Zeilensperre vor Nummernvergabe, Überzahlungsschutz, transaktionssicheres Zurücknehmen |
| `includes/automation.php` | atomare Sperre (`GET_LOCK`) |
| `includes/checkin.php` | idempotenter Doppelscan |
| `includes/kommunikation.php` | optionale Antwortadresse (Reply-To) |
| `dashboard/admin/nutzerverwaltung.php` | Grundrolle änderbar inkl. RBAC-Abgleich, Audit-Log für Rolle/Status/Anlage, Rückfrage beim Deaktivieren |
| `dashboard/admin/umsatz.php`, `umsatz-trainer.php` | Rechenfehler Kursumsatz, bcmath, ausbezahlter statt bewilligter Förderbetrag |
| `dashboard/admin/abrechnungen.php` | Stichzeitpunkt, Doppelmodell-Warnung |
| `dashboard/admin/kooperation-detail.php`, `foerderung-detail.php` | Löschsperre bei vorhandenen Perioden/Dokumenten/Buchungen |
| `api/dokument-download.php` | Pfadprüfung, Typ-Positivliste, sicherer Dateiname, Organisationsfilter |
| `pages/kontakt.php`, `mitglied-werden.php`, `trainer-werden.php` | Honeypot + 5 Anfragen/Stunde/IP, zentrale Mailfunktion |
| `auth/*` | gehashte Tokens (alte Links bleiben gültig), Drosselung „Passwort vergessen“, zentrale Mailfunktion |
| `dashboard/uebungen.php`, `trainingsplan.php` | nur http(s)-Videolinks, Löschrecht serverseitig |
| `dashboard/ld-export.php`, `projekt.php`, `kurs-detail.php`, `einheit-planen.php`, `rollen.php`, `mitglieder.php` | Rechte-/Fristkorrekturen, konkrete Rückfragen |
| `dashboard/admin/systemstatus.php` | Zeitabgleich DB/PHP |

---

### 5. Datenbankänderungen

**Keine neuen Migrationen in Phase 3.** Alle Korrekturen sind Code-Änderungen; bestehende Datensätze wurden nicht verändert.

Befunde zur Struktur (dokumentiert, nicht geändert):
- 32 Statusfelder sind `ENUM` → Statuswerte sind datenbankseitig einheitlich; Anzeige-Texte kommen aus zentralen Konstanten
  (`ANMELDUNG_STATUS`, `RECHNUNG_STATUS`, `PROJEKT_STATUS`, `FOERDER_STATUS`, `TA_STATUS`, `ET_STATUS` …). Einziges Textfeld:
  `automationen.letzter_status` (nur von der Engine geschrieben).
- Viele Fremdschlüssel auf `users` sind `ON DELETE CASCADE` (u. a. Trainerabrechnungen, Einsätze, Einwilligungen). Das ist
  unkritisch, weil **Nutzer nirgends hart gelöscht werden** (nur deaktiviert bzw. anonymisiert). Ein künftiges „Nutzer löschen“
  darf nicht ohne vorherige Anpassung dieser Fremdschlüssel gebaut werden.
- Offene Kurs-Rechnungen bei Kursabsage bleiben offen (bewusst: Storno ist eine kaufmännische Entscheidung).

---

### 6. Security-Verbesserungen

- Verzeichnis- und Dateischutz auf Webserver-Ebene (live geprüft vor/nach Deployment).
- Sofortige Wirkung von Deaktivierung und Rollenentzug; 4 h Inaktivitäts-Timeout.
- Tokens nur noch als SHA-256-Hash in der Datenbank.
- Spam-/Mengenschutz auf allen öffentlichen Formularen, Drosselung Passwort-Reset (Login-Sperre bestand bereits).
- Keine technischen Fehlermeldungen im Browser; Details im geschützten Log. Zugangsdaten werden im Systemstatus maskiert.
- Sicherheitsereignisse (CSRF-Fehler, verweigerte Downloads, Honeypot, Rate-Limit) im Aktivitätsprotokoll mit Präfix `security:`.
- Secrets: kein echtes Passwort im Repository oder dessen Historie (geprüft ohne Ausgabe der Werte). `config/database.php` ist
  von Git ausgeschlossen.

---

### 7. Performance-Verbesserungen

Messung aller 73 Seiten gegen realistische Datenmenge: alle zwischen ~300 ms (inkl. PHP-Start im Testaufbau), keine N+1-Ausreißer.
Deshalb bewusst **keine** Umbauten. Zusätzliche Indizes für Kennzahlen/Trainer-Zähler/Aufgaben wurden bereits mit Migration 012
eingespielt. Pagination ist in den großen Listen (Kursportal, Rechnungen, Audit-Log, Automations-Protokoll) vorhanden;
Mitglieder-/Nutzerlisten laden vollständig – bei der aktuellen Größe unkritisch, ab etwa 2.000 Personen nachrüsten.

---

### 8. UX-Verbesserungen

- Konkrete Rückfragen mit Namen bei allen kritischen Aktionen (siehe Punkt 29).
- Grundrolle direkt in der Nutzerverwaltung änderbar (inkl. Hinweis, dass sich die eigene Rolle nicht ändern lässt).
- Verständliche Meldungen statt „Ungültige Anfrage (CSRF)“ und statt technischer Fehlerseiten.
- Löschsperren erklären, was stattdessen zu tun ist („Status auf ‚Beendet‘ setzen“).

---

### 9. Noch offene Punkte

1. **Vergütungsmodell (Entscheidung Präsidium, HIGH):** Es gibt die Provisionsabrechnung (80 % vom Kursumsatz, „Kursabrechnungen“)
   und die Honorar-Abrechnung je Einheit („Trainerabrechnungen“). Für denselben Kurs ist beides möglich. Bis zur Entscheidung warnt
   das System bei Überschneidung. Empfehlung: pro Kurs genau ein Modell festlegen.
2. **Öffentliches GitHub-Repository:** Es enthält keine Zugangsdaten, aber den vollständigen Quellcode (inkl. ZVR-Zahl und Vereinsadresse, die ohnehin öffentlich sind). Empfehlung: Repository auf „privat“ stellen.
3. **Trainer sehen alle Mitglieder** mit Telefon/Ort sowie persönliche Mitgliedsdokumente; alle Trainer können alle Trainings-/Ernährungspläne bearbeiten und löschen. Das ist aktuelles Konzept – bitte fachlich bestätigen oder auf „eigene Kursteilnehmer“ einschränken lassen.
4. **Content-Security-Policy** fehlt (Inline-Skripte und CDN-Bibliotheken müssten erst inventarisiert werden).
5. **Zwei-Faktor-Anmeldung** für Admin-Konten ist nicht vorhanden (Empfehlung bei Finanz- und Kinderdaten).
6. **Zustellbarkeit der E-Mails:** SPF/DKIM/DMARC für die Absenderdomain beim Hoster prüfen (nicht aus dem Code prüfbar).
7. **Doppelte Upload-Logik** (3 Stellen) und ~40 allgemeine Rückfragen bei unkritischen Aktionen (LOW).
8. **Übungen** dürfen weiterhin von allen Trainern bearbeitet werden (geteilte Bibliothek); nur Löschen ist beschränkt.

---

### 10. Externe Abhängigkeiten

| Abhängigkeit | Zweck | Hinweis |
|---|---|---|
| PHP ≥ 8.1 mit `pdo_mysql`, `mbstring`, `bcmath`, `gd`, `fileinfo`, `zlib` | Laufzeit | im Systemstatus geprüft |
| MariaDB/MySQL | Datenbank | `GET_LOCK` für die Automations-Sperre |
| TCPDF (Composer, `vendor/`) | PDFs | nur serverseitig, per `.htaccess` gesperrt |
| PHP `mail()` des Hosters | E-Mail | Zustellbarkeit hängt von SPF/DKIM ab |
| Google Fonts, jsDelivr (feather-icons, pdf.js nicht produktiv) | Schriften/Icons | externe Aufrufe aus dem Browser (Datenschutzerklärung) |
| Hetzner Cron | Automationen | ohne Cron: Rückfall beim Seitenaufruf (max. alle 10 min) |

---

### 11. Empfohlene Serverkonfiguration

- PHP 8.2/8.3, `display_errors=Off`, `log_errors=On` (wird zusätzlich im Code gesetzt), `session.cookie_secure=On` (bei HTTPS automatisch).
- `upload_max_filesize` ≥ 10 M, `post_max_size` ≥ 12 M (Systemstatus zeigte lokal 2 M – **auf dem Server prüfen**).
- `memory_limit` ≥ 128 M (PDF-Erzeugung).
- HTTPS erzwungen (bereits über `.htaccess`), HSTS aktiv (180 Tage, ohne Subdomains).
- Cron: `https://aci-stmk.at/cron.php?key=…` alle 15 Minuten.
- Verzeichnis `logs/` muss für PHP beschreibbar sein (wird automatisch angelegt und gesperrt).

---

### 12. Backup-Empfehlungen

| Was | Wie | Häufigkeit |
|---|---|---|
| Datenbank `dxr26y_db0` | Hetzner-Backup (konsoleH) **und** zusätzlich `mysqldump --single-transaction --routines` bzw. phpMyAdmin-Export | täglich, 30 Tage aufbewahren; vor jedem Deployment manuell |
| Uploads (`uploads/pdfs`, `uploads/images`) | per FTP/rsync sichern | wöchentlich + vor Deployments |
| Konfiguration (`config/database.php`, `config/config.php`, `.htaccess`) | verschlüsselt außerhalb des Servers ablegen | bei jeder Änderung |
| Fehlerprotokoll `logs/` | nicht sichern nötig; bei Bedarf für Fehleranalyse | – |

Wiederherstellung **nur manuell** und in eine leere Test-Datenbank zuerst prüfen. Eine automatische Restore-Funktion ist bewusst nicht eingebaut.
Aufbewahrungspflicht: Rechnungen/Buchungen 7 Jahre (BAO § 132) – Backups entsprechend lange revisionssicher aufheben.

---

### 13. Deployment-Checkliste

Siehe `DEPLOYMENT_CHECKLIST.md`.

---

### 14. Testergebnisse

| Test | Ergebnis |
|---|---|
| Regression: 73 Dashboard-/Admin-Seiten × 3 Rollen (Admin, Trainer, Mitglied) | 0 neue Fehler (verbleibend nur SQLite-spezifische Abweichungen des Testaufbaus: `FIELD()`, `UNION … ORDER BY` – unter MariaDB gültig) |
| Öffentliche Seiten (Start, Kursportal, Formulare, Impressum, Datenschutz) | 0 Fehler |
| Workflow 1–6 (Kurs, Warteliste, Projekt+Förderung, Event, Rechnung, Mahnung) | 39/39 bestanden |
| Grenzfälle (0 Teilnehmer, exakt voll, Doppelanmeldung, Doppel-Ausstellen, Überzahlung, Teil-/Rückzahlung, Stornofrist, Automation 2×, Sonderzeichen/Apostroph/Umlaute, CSV-Formeln) | 16/16 bestanden |
| Rechte: direkte URL-Aufrufe (Mitglied → Admin-Seiten, Trainer → fremde Kurse/Abrechnungen/Projekte, Exporte) | alle abgewiesen |
| Rollenänderung inkl. RBAC-Abgleich und Selbstschutz | bestanden |
| Spam-Schutz (Honeypot, 6. Anfrage/Stunde) | bestanden |
| Live-Prüfung sensibler Pfade (vor Deployment) | Befund 1 bestätigt → nach Deployment erneut prüfen |
| Datenschutz (Auskunft, Anonymisierung, Selbstauskunft) | bestanden (Phase 2, unverändert) |
