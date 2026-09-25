# Admin-Handbuch – Athletikclub Steiermark

Kurze Anleitung für Präsidium, Geschäftsstelle und Administration.
Adresse: **https://aci-stmk.at/dashboard** · Menü links (am Handy über ☰), oben: Suche, **+ Neu**, Glocke (Benachrichtigungen).

---

## 1. Täglicher Betrieb (5 Minuten)

1. **Übersicht** öffnen → Box **Handlungsbedarf** abarbeiten (rot = dringend). Dort stehen offene Anmeldungen, überfällige Rechnungen, ablaufende Qualifikationen, Förderfristen, einzureichende Abrechnungen und fehlgeschlagene Automationen.
2. **Glocke** oben rechts: neue Benachrichtigungen lesen.
3. Einmal pro Woche: **Administration → Systemstatus** (alles grün?) und **Kommunikation → E-Mail-Protokoll** (Fehler?).

Die Automationen laufen im Hintergrund (Erinnerungen, Warteliste, Mahnungen vorbereiten, Fristen). Ihr Protokoll steht unter **Administration → Automatisierungen**.

## 2. Neue Trainer:innen

**Weg A – Bewerbung über die Website („Trainer werden“):**
1. **Trainer → Trainer-Onboarding** → Bewerbung öffnen.
2. Schritt für Schritt mit **„Weiter: …“** durch den Ablauf (Prüfung → Aufnahme → Stammdaten → Qualifikationen → Dokumente → Vereinbarung → Einschulung → Freigabe).
3. Checkliste abhaken. Grüne Punkte prüft das System selbst (z. B. gültige Erste-Hilfe-Qualifikation, Vereinbarung als Vertrag, Konto).
4. **„Konto anlegen & einladen“** → die Person bekommt eine Mail zum Passwort setzen.
5. Status **Aktiv** ist erst möglich, wenn Konto und alle Pflichtpunkte erledigt sind.

**Weg B – direkt:** **Administration → Nutzerverwaltung → „Nutzer anlegen & einladen“**, Rolle „Trainer“.

**Danach immer:**
- Honorarsatz anlegen: **Finanzen → Trainerabrechnungen → Honorarsätze → „Satz hinzufügen“** (pro Stunde oder pro Einheit, optional pro Kurs/Projekt).
- Qualifikationen erfassen (**Trainer → Qualifikationen**, Person auswählen).
- Trainer-Handbuch weitergeben.

## 3. Neue Mitglieder

- **Selbstregistrierung:** Die Person registriert sich und bestätigt ihre E-Mail. Danach erscheint sie unter **Sportbetrieb → Mitglieder** mit Status „ausstehend“ → **„Freigeben“**.
- **Durch euch:** **Mitglieder → „Mitglied anlegen & einladen“**.
- **Kinder** trägt der Elternteil selbst unter **Kinder & Einwilligungen** ein (mit Notfallkontakt und Einwilligungen).
- **Mitgliedsantrag über die Website** kommt als Anfrage unter **Administration → Seiteninhalte** (Kontaktanfragen) an.

## 4. Neue Kurse

1. **+ Neu → Kurs**: Titel, Zeitraum, Ort, max. Teilnehmende, Preis, Altersgrenzen, Trainer:in, Projekt.
2. **„Öffentliche Anmeldung im Kursportal“** anhaken, wenn Externe über `aci-stmk.at/kurse` buchen sollen.
3. Im Kurs **„+ Einheiten planen“** → Serie (wöchentlich/14-tägig) mit Trainer:in anlegen.
4. **Laufend:** Im Kurs Teilnehmende, Warteliste und Zahlungen sehen. **„Rechnungen erzeugen“** erstellt Rechnungen für alle Angemeldeten.
5. **Kurs absagen:** Status „Abgesagt“. Alle Angemeldeten und die Warteliste werden automatisch informiert.

Öffentliche Buchungen müssen per E-Mail-Link bestätigt werden (Standard 48 h), sonst verfällt der Platz und die Warteliste rückt nach.

## 5. Projekte

- **Organisation → Projekte → „Projekt anlegen“** mit Leitung und Budget.
- Tabs im Projekt: Aufgaben, Termine, Kurse, Team, Finanzen, Förderungen, Dokumente, Partner.
- Trainerkosten landen automatisch im Projekt, sobald die Trainerabrechnung **freigegeben** ist.
- Beenden: Status „Abgeschlossen“, später **„Projekt archivieren“**. Daten bleiben erhalten, es wird nichts gelöscht.

## 6. Abrechnungen (Trainer:innen)

Ablauf pro Monat:
1. Trainer:innen bestätigen ihre Einheiten (**Zeiterfassung**) und reichen den Monat ein.
2. **Finanzen → Trainerabrechnungen** → Monat wählen → Abrechnung öffnen.
3. Prüfen (Einsätze, Beträge; fehlende Honorare mit **„Einsätze ohne Honorar nachberechnen“** nachtragen) → **„Als geprüft markieren“** → **„Freigeben“**. Die Freigabe erzeugt die Kostenbuchungen.
4. Nach der Überweisung: **„Als bezahlt markieren“**.
5. PRAE-Empfänger:innen: **„In PRAE-Abrechnung übernehmen“**, danach **Finanzen → PRAE-Abrechnung**.

Die eigene Abrechnung darf man nicht selbst freigeben. Später bestätigte Einheiten kommen automatisch in die nächste offene Monatsabrechnung.
**Achtung:** Die ältere **Kursabrechnung** (Provision auf Kursumsatz) nicht zusätzlich für dieselben Kurse verwenden (Doppelzahlung). Das System warnt in diesem Fall.

## 7. Rechnungen

1. **Finanzen → Rechnungen → „+ Neue Rechnung“** (oder im Kurs „Rechnungen erzeugen“).
2. Entwurf prüfen → **„Prüfen & ausstellen“** vergibt die fortlaufende Nummer. Danach ist die Rechnung nicht mehr änderbar.
3. **„Per E-Mail senden“** → die Empfänger:innen bekommen einen Link zu Rechnung und PDF.
4. Geldeingang: **„Zahlung erfassen“** (Teilzahlungen möglich; mehr als offen geht nicht).
5. Überfällig: Das System bereitet Zahlungserinnerung und Mahnungen vor (jede Stufe nur einmal). Du versendest sie selbst, außer „automatisch versenden“ ist eingeschaltet.
6. Fehler in einer ausgestellten Rechnung: **„Stornorechnung erstellen“** und eine neue Rechnung ausstellen. Rechnungen werden nie gelöscht (Aufbewahrung 7 Jahre).

## 8. Förderungen

1. **Förderungen → Fördermanagement → „Förderung anlegen“** (Programm, Förderstelle, Fristen, Betrag).
2. Status mitführen: beantragt → bewilligt (bewilligten Betrag eintragen) → in Umsetzung → abgerechnet → abgeschlossen.
3. Mit einem Projekt verknüpfen. Trainerkosten des Projekts zählen dann automatisch als verbrauchtes Förderbudget.
4. Weitere Kosten mit Beleg: **„Kosten erfassen“**. Geldeingang: **„Auszahlung verbuchen“**.
5. **„Verwendungsnachweis (PDF)“** und **„Kostenliste (CSV)“** für die Abrechnung bei der Förderstelle.
6. Fristen erscheinen rechtzeitig im Handlungsbedarf und in der Glocke.

## 9. Events

1. **Sportbetrieb → Events → „Event anlegen“**. Das legt automatisch ein Projekt (Budget, Team, Aufgaben), die Anmeldung und den Kalendertermin an.
2. Helfer:innen im Projekt-Team eintragen, Aufgaben verteilen, Material unter Ressourcen buchen.
3. Am Eventtag: **Check-in** (QR-Code aus der Bestätigungsmail scannen oder Liste antippen).
4. Einnahmen und Ausgaben im Projekt (Tab Finanzen) buchen.
5. Danach: **Eventbericht (PDF)**.

## 10. Probleme und Fehler

| Problem | Vorgehen |
|---|---|
| Jemand kommt nicht ins Konto | „Passwort vergessen“ nutzen; in der Nutzerverwaltung prüfen, ob das Konto aktiv ist; bei Mitgliedern „Freigeben“ |
| 5 falsche Passwörter | 15 Minuten warten oder Passwort zurücksetzen |
| Mails kommen nicht an | Kommunikation → E-Mail-Protokoll; Einstellungen → Testnachricht; Spam-Ordner; Hoster (SPF/DKIM) |
| Erinnerungen/Mahnungen passieren nicht | Systemstatus → „Letzter Cron-Aufruf“; Automatisierungen → Protokoll |
| „Es ist ein Fehler aufgetreten.“ | Uhrzeit und Seite notieren und an die technische Betreuung melden. Die Details stehen im Fehlerprotokoll (`logs/php-error.log` am Server, nur per FTP) und im Systemstatus unter „Letzte Fehler“ |
| Falsche Daten eingegeben | Das Audit-Log (Administration) zeigt, wer wann was geändert hat |
| Datenauskunft/Löschwunsch | Administration → Datenschutz → Person suchen → Auskunft (JSON/Excel) oder Anonymisierung (unwiderruflich, Rechnungen bleiben erhalten) |

## 11. Backups

- **Täglich automatisch:** Hetzner-Backups (konsoleH) – Einstellung einmal prüfen.
- **Wöchentlich zusätzlich:** phpMyAdmin → Datenbank `dxr26y_db0` → **Exportieren** → Datei verschlüsselt extern speichern.
- **Vor jeder Änderung am System** (Update, Migration): Export + Uploads-Ordner sichern.
- **Einmal pro Quartal üben:** Export in eine leere Test-Datenbank importieren und Stichproben vergleichen. Niemals ungeprüft in die Live-Datenbank importieren.
- Rechnungsdaten müssen 7 Jahre verfügbar bleiben.

## 12. Benutzer und Berechtigungen

- **Grundrolle** (Nutzerverwaltung, direkt in der Liste änderbar):
  - **Mitglied** – eigene Kurse, Kinder, Pläne, Rechnungen
  - **Trainer** – Trainerbereich, eigene Kurse, Zeiterfassung, Projekte als Team
  - **Admin** – alles
- **Zusatzrollen** (Administration → Rollen & Rechte): z. B. „Management“ (Cockpit, Berichte), „Administration“ (Rechnungen, Zahlungen, Kommunikation, Events, Onboarding), „Projektleitung“ (Events). Die Rechte-Matrix legt fest, wer was darf.
- **Deaktivieren statt löschen:** Nutzerverwaltung → „Deaktivieren“. Die Person kann sich sofort (spätestens nach 1 Minute) nicht mehr anmelden, ihre Daten bleiben erhalten.
- Die eigene Rolle kann man nicht ändern (es bleibt immer ein Admin).
- Nach 4 Stunden ohne Aktivität meldet das System automatisch ab.
- Alle Rollen- und Statusänderungen stehen im **Audit-Log**.
