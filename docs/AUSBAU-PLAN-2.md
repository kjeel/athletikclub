# Ausbaustufe 2 – Betriebsplattform (Phasen A–K)

## Phase A – Bestandsanalyse (Ergebnis)

| Bereich | Vorhanden | Wird erweitert |
|---|---|---|
| Personen | `users` (Konto, Rolle), `mitglieder_profile` (Geburtsdatum, Telefon, Adresse), `kinder`, `einwilligungen` | Gastbuchungen legen **kein** zweites Personenregister an: Konto ohne Passwort (Aktivierung über „Passwort vergessen“), bestehende E-Mail wird wiederverwendet |
| Kurse & Anmeldung | `kurse`, `kurs_anmeldungen` (+ Kind, Warteliste, Nachrücken in `includes/kursanmeldung.php`) | Status `angefragt` / `nicht_erschienen`, Token (Bestätigung, Selbst-Storno, QR-Check-in), `kurse.oeffentlich`, `kurse.bild`, `kurse.art` (Kurs/Event) |
| Termine | `einheiten`, `einheit_trainer`, `anwesenheiten` | Check-in schreibt `anwesenheiten` |
| Benachrichtigung | `benachrichtigungen` + `benachrichtigen()`, `mail()` verstreut in Formularen | zentrale Kanäle (intern, E-Mail) mit Protokoll, Vorlagen mit `{{variablen}}` |
| Finanzen | `buchungen`, Kursbeiträge aus bezahlten Anmeldungen, `trainer_abrechnungen` | `rechnungen`, `rechnung_positionen`, `zahlungen`, `mahnungen`, Nummernkreis |
| Stammdaten Verein | `prae_einstellungen` (Name, ZVR, Adresse, IBAN, BIC) | bleibt die Quelle; weitere Einstellungen in `einstellungen` (Schlüssel/Wert), zentral über `verein()` / `einstellung()` |
| Fälligkeiten | `plattformFaelligkeiten()` (1× täglich beim Dashboard-Aufruf) | wird Teil der Automatisierungs-Engine (Trigger → Bedingung → Aktion) mit Protokoll |
| Projekte | Team, Aufgaben, Budget, Buchungen, Dokumente, Partner | **Events = Projekt (Kategorie Veranstaltung) + Anmelde-Kurs (`art = event`) + Einheit**: Team/Helfer, Aufgaben, Budget, Einnahmen/Ausgaben, Dokumente, Ressourcen und Buchungslogik werden wiederverwendet |
| Trainer-Bewerbung | `pages/trainer-werden.php` → `kontakt_anfragen` | zusätzlich Onboarding-Datensatz; Checkliste mit automatischen Prüfungen (Qualifikationen, Trainervereinbarung, Bankdaten, Konto) |
| Rechte | RBAC (`darf()`), Rollen-UI | neue Rechte: kommunikation, rechnungen, events, onboarding, management, einstellungen, automatisierungen, export |
| Statistik | `includes/statistik.php`, Finanzen, Handlungsbedarf | Management-Dashboard mit Zeitraum + Vorperiode |

Befund während der Analyse (sofort behoben): Geburtsdatum/Telefon liegen in `mitglieder_profile`, nicht in `users` – drei Abfragen der letzten Ausbaustufe waren betroffen.

## Architekturentscheidungen

1. **Öffentliche Buchung** (`/kurse`): Platz wird sofort transaktional vergeben (Status `angefragt`), Bestätigung per E-Mail-Link innerhalb von 48 h → `angemeldet`; unbestätigte Anfragen verfallen automatisch und die Warteliste rückt nach. Eingeloggte Mitglieder buchen wie bisher direkt.
2. **Events** nutzen Projekte + Kurslogik statt eigener Parallelstrukturen.
3. **Rechnungen**: Entwurf ohne Nummer → „Ausstellen“ vergibt fortlaufende Nummer (Nummernkreis je Jahr, gesperrt per Transaktion) → danach unveränderlich; Korrektur nur über Stornorechnung. Zahlung einer Kursrechnung markiert die Anmeldung als bezahlt; sonstige Zahlungen erzeugen eine Einnahme-Buchung (keine Doppelzählung).
4. **Automatisierung**: ein Runner (`includes/automation.php`), aufgerufen über `cron.php?key=…` (echter Cron) und als Rückfall höchstens alle 10 Minuten beim Seitenaufruf. Finanzielle Aktionen werden nur **vorbereitet** (Abrechnungsentwürfe, Mahnungen), Versand/Freigabe je nach Einstellung.
5. **QR-Check-in**: zufälliger 128-Bit-Token je Anmeldung, QR enthält nur die URL mit Token; Einlösen nur durch eingeloggte Kursleitung/Planer:innen.
6. **Exporte**: CSV, XLSX (eigener schlanker Writer ohne Zusatzbibliothek), PDF über das vorhandene TCPDF.
7. **Navigation**: Sidebar aus einer zentralen Menüdefinition mit aufklappbaren Gruppen, rechteabhängig.

## Migration 012 (additiv)

Neue Tabellen: `einstellungen`, `nachricht_vorlagen`, `nachrichten`, `mail_log`, `rechnungen`, `rechnung_positionen`, `zahlungen`, `mahnungen`, `nummernkreise`, `onboarding_punkte`, `onboarding`, `onboarding_status`, `automationen`, `automation_log`.
Erweiterungen: `kurse` (oeffentlich, bild, art, kurzbeschreibung), `kurs_anmeldungen` (Status angefragt/nicht_erschienen, token, quelle, bestaetigt_am, erinnert_am, storniert_am), neue Rechte.

## Phasen

A Analyse & Plan · B Buchungsportal · C Kommunikation · D Rechnungen/Zahlungen/Mahnwesen · E Onboarding · F Events & Check-in · G Automatisierung · H Management · I Dokumente/Exporte/Suche · J Mobile & UX/Navigation · K Security/Datenschutz/Performance/Workflowtests
