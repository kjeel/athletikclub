# Ausbau zur Vereinsplattform – Implementierungsplan

Stand: 24.09.2026 · Grundlage: Analyse der bestehenden Codebasis (PHP 8, PDO/MariaDB, Migrationen 001–010).

## 1. Bestandsaufnahme

| Bereich | Vorhanden | Lücke |
|---|---|---|
| Kalender | `termine` (inkl. Wiederholung, Sichtbarkeit), Kurse, Testungen, Monats-/Listenansicht, iCal-Abo | Tag/Woche, Einheiten mit Trainer:innen, Projekte, Überschneidungsprüfung |
| Kurse | `kurse` (ein Block mit Start/Ende), `kurs_anmeldungen` inkl. Warteliste | Einzelne Einheiten, Anmeldeschluss, Alter, Voraussetzungen, Nachrücken |
| Anwesenheit | nur Status „teilgenommen“ je Kursanmeldung | Anwesenheit je Einheit |
| Zeiterfassung / Honorar | `umsatz_eintraege`, Provisionsabrechnung (`abrechnungen`), PRAE (`prae_*`) | Honorar je Einheit/Stunde, Bestätigung durch Trainer:innen, Monatsabrechnung |
| Projekte / Aufgaben | – (nur TBE-Projekte, Kooperationen) | Neues Hauptmodul |
| Qualifikationen | Freitext `trainer_profile.qualifikationen` | Strukturiert mit Ablaufdatum |
| Eltern/Kinder | – | Neu |
| Partner/CRM | `kooperationen` (Gemeinden), `partner` (Website) | Allgemeines CRM |
| Ressourcen, Verträge | – | Neu |
| Förderungen | `foerderungen` + Dokumente | Programm, Projekt, Budget/Verbrauch, Belege |
| Rollen | `users.rolle` + RBAC-Tabellen (`roles`, `permissions`, `user_roles`, `can()`) ungenutzt | Berechtigungen je Modul/Aktion |
| Audit | `aktivitaets_log` (Aktion + Text) | Datensatz, alter/neuer Wert, Suche |

## 2. Kernentscheidung: „Einheit“ als zentrales Objekt

Neue Tabelle **`einheiten`** – jede konkrete, planbare Durchführung (Kurseinheit, Training, Kindergarten, Schule, Gemeindeprojekt, Event, Meeting, Sonstiges) mit Start/Ende, Ort, Status und optionalem **Projekt** und **Kurs**. Serien (`einheit_serien`) erzeugen die einzelnen Einheiten, damit jede Einheit eigene Anwesenheit, Bestätigung und Abrechnung hat.

```
Projekt → Kurs → Einheiten (Serie) → Trainer:innen (einheit_trainer)
       → Teilnehmende (Kursanmeldungen, Kinder) → Anwesenheit
       → Bestätigung „durchgeführt“ (Dauer, Teilnehmerzahl) → Honorar (honorar_saetze)
       → Trainerabrechnung (Monat) → Buchung „Trainerkosten“ (Projekt/Kurs/Förderung)
       → Förderbudget „verbraucht“ → Dashboard / Statistik / Benachrichtigungen
```

Bestehendes bleibt unverändert nutzbar: `termine` bleibt für persönliche Termine, Kurse ohne Einheiten erscheinen weiter als Block, die Provisionsabrechnung und die PRAE-Abwicklung bleiben bestehen. Trainerabrechnungen können per Knopf als PRAE-Einsatztage übernommen werden.

## 3. Migration 011 (additiv, keine Daten werden gelöscht)

Neue Tabellen: `projekte`, `projekt_team`, `projekt_partner`, `einheit_serien`, `einheiten`, `einheit_trainer`, `anwesenheiten`, `honorar_saetze`, `trainer_abrechnungen`, `buchungen`, `aufgaben`, `trainer_qualifikationen`, `kinder`, `einwilligungen`, `partner_organisationen`, `partner_kontakte`, `ressourcen`, `ressourcen_buchungen`, `vertraege`, `benachrichtigungen`, `audit_log`, `system_status`.

Erweiterungen: `kurse` (+ Anmeldeschluss, Alter, Voraussetzungen, Projekt), `kurs_anmeldungen` (+ `kind_id`, eindeutiger Schlüssel um Kind erweitert), `foerderungen` (+ Programm, Projekt, Abrechnungsfrist, neue Status), `dokumente` (+ Projekt, Partner, Kurs, Vertrag, Qualifikation, Buchung), neue Berechtigungen + Rollenzuordnung.

## 4. Phasen

1. Analyse ✔ · 2. Plan ✔ · 3. Migration 011
4. Kern: Kalender (Tag/Woche/Monat, Einheiten, Serien, Überschneidungen), Anwesenheit, Zeiterfassung, Trainerabrechnung, Projekte, Aufgaben, Qualifikationen
5. Kursanmeldung/Warteliste, Eltern/Kinder, Partner/CRM, Ressourcen, Verträge, Förder-Erweiterung, Benachrichtigungen
6. Dashboard 2.0, Finanzen, modulübergreifende Statistik
7. Rollen/Berechtigungen (Admin-Oberfläche), Audit-Log, Security-Härtung, Tests

## 5. Sicherheitsleitlinien

Prepared Statements, `requireCsrf()` bei jedem POST, serverseitige Berechtigungsprüfung über `darf()` (Admin behält Vollzugriff, Trainer:innen sehen nur eigene Daten), Uploads mit Inhaltsprüfung (finfo + Signatur), Audit-Einträge mit altem/neuem Wert bei sensiblen Änderungen.
