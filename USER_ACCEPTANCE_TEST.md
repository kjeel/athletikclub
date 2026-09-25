# User Acceptance Test – Athletikclub Steiermark

Durchführung im echten System https://aci-stmk.at. Für die Tests werden benötigt:

- **A** = eigenes Admin-Konto
- **T** = Test-Trainerkonto (eigene zweite E-Mail-Adresse, z. B. `vorname+trainer@…`)
- **M** = Test-Mitgliedskonto (dritte E-Mail-Adresse, z. B. `vorname+mitglied@…`)
- ein Smartphone für die mobilen Tests

Viele Mailanbieter stellen `+`-Adressen an das normale Postfach zu. Testdaten am Ende absagen bzw. stornieren (siehe GO_LIVE_CHECKLIST Teil C).
Reihenfolge einhalten – spätere Tests bauen auf früheren auf.

---

### TEST 01 – Login und Logout
**Voraussetzung:** Konto A.
**Schritte:**
1. `https://aci-stmk.at/auth/login.php` öffnen, mit A anmelden.
2. Oben rechts auf die Initialen → „Abmelden“.
3. Erneut anmelden, dabei absichtlich 6× ein falsches Passwort mit einer **Test**-Adresse eingeben.

**Erwartetes Ergebnis:** Nach 1 Dashboard mit „Übersicht“. Nach 2 zurück auf der Website. Nach 3 erscheint der Hinweis „Zu viele fehlgeschlagene Anmeldeversuche …“.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 02 – Passwort vergessen
**Voraussetzung:** Konto M existiert (nach TEST 04).
**Schritte:**
1. Login-Seite → „Passwort vergessen“ → E-Mail von M eingeben.
2. Link aus der Mail öffnen, neues Passwort setzen.
3. Mit dem neuen Passwort anmelden.

**Erwartetes Ergebnis:** Mail kommt innerhalb weniger Minuten; Link funktioniert einmal; Anmeldung klappt.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 03 – Rollen und Berechtigungen (direkte Adressen)
**Voraussetzung:** Konten T und M.
**Schritte:**
1. Als M anmelden und direkt `https://aci-stmk.at/dashboard/admin/nutzerverwaltung.php` aufrufen.
2. Als M `https://aci-stmk.at/dashboard/mitglieder.php` aufrufen.
3. Als T `https://aci-stmk.at/dashboard/admin/rechnungen.php` aufrufen.
4. Als T in der Seitenleiste prüfen, dass es keine Gruppen „Finanzen“ und „Administration“ gibt.

**Erwartetes Ergebnis:** 1–3 leiten jeweils auf das Dashboard um, es werden keine Daten angezeigt. Die Seitenleiste zeigt nur erlaubte Bereiche.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 04 – Mitglied anlegen
**Voraussetzung:** Konto A.
**Schritte:**
1. **Sportbetrieb → Mitglieder** → Formular „Mitglied anlegen & einladen“ mit Adresse M ausfüllen.
2. Einladungsmail bei M öffnen, Passwort setzen, anmelden.

**Erwartetes Ergebnis:** Mitglied erscheint in der Liste; Mail kommt an; M sieht das Mitglieder-Dashboard (Kalender, Kurse, Kinder & Einwilligungen, Meine Rechnungen …).

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 05 – Trainer anlegen und Rolle ändern
**Voraussetzung:** Konto A.
**Schritte:**
1. **Administration → Nutzerverwaltung** → „Nutzer anlegen & einladen“ mit Adresse T, Rolle Trainer.
2. In der Liste bei T die Rolle testweise auf „Mitglied“ und wieder auf „Trainer“ stellen.
3. **Administration → Audit-Log** öffnen.

**Erwartetes Ergebnis:** Einladungsmail kommt; Rollenwechsel mit Rückfrage und Erfolgsmeldung; im Audit-Log zwei Einträge „Grundrolle von …“.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 06 – Trainer-Onboarding
**Voraussetzung:** keine.
**Schritte:**
1. Ohne Anmeldung: Website → „Trainer werden“ → Formular mit einer vierten Testadresse absenden.
2. Als A: **Trainer → Trainer-Onboarding** → neue Bewerbung öffnen.
3. Status mit „Weiter: …“ bis „Freigabe“ durchschalten, Checklistenpunkte abhaken, „Konto anlegen & einladen“.

**Erwartetes Ergebnis:** Bestätigungsmail an die Bewerbungsadresse; Bewerbung erscheint mit Fortschrittsbalken; „Aktiv“ ist erst möglich, wenn Konto und Pflichtpunkte erledigt sind; Einladungsmail kommt.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 07 – Projekt anlegen
**Voraussetzung:** Konto A.
**Schritte:**
1. **Organisation → Projekte** → „Projekt anlegen“: Name „UAT Projekt“, Budget 500.
2. Im Projekt: Tab „Team“ → T hinzufügen; Tab „Aufgaben“ → Aufgabe für T anlegen.

**Erwartetes Ergebnis:** Projekt erscheint; T bekommt Benachrichtigungen (Glocke); die Aufgabe erscheint bei T unter „Aufgaben“ und in „Heute“.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 08 – Kurs erstellen
**Voraussetzung:** Konto A, Projekt aus TEST 07.
**Schritte:**
1. **„+ Neu“ → Kurs**.
2. Titel „UAT Kurs“, Start in 3 Tagen, Ende in 30 Tagen, **max. 1 Teilnehmer:in**, Preis 10, Projekt „UAT Projekt“, „Öffentliche Anmeldung“ anhaken, speichern.

**Erwartetes Ergebnis:** Kurs ist in **Sportbetrieb → Kurse** sichtbar; die Kursseite zeigt 0 / 1 Plätze.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 09 – Trainer zuweisen
**Voraussetzung:** TEST 08.
**Schritte:**
1. Kurs → „Bearbeiten“ → Trainer:in T auswählen → speichern.
2. Als T anmelden → **Sportbetrieb → Kurse** („meine“).

**Erwartetes Ergebnis:** T sieht den Kurs, kann ihn öffnen und sieht die (noch leere) Teilnehmerliste.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 10 – Termin erstellen
**Voraussetzung:** TEST 09.
**Schritte:**
1. Kurs → „+ Einheiten planen“: wöchentlich, 3 Termine, T als Leitung.
2. **Allgemein → Kalender** öffnen (als A und als T).

**Erwartetes Ergebnis:** Drei Termine im Kalender, bei T mit Hinweis auf die Einteilung; Kursseite listet die Termine.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 11 – Öffentliche Kursanmeldung
**Voraussetzung:** TEST 08 (öffentlich, 1 Platz).
**Schritte:**
1. In einem privaten Browserfenster (nicht angemeldet) `https://aci-stmk.at/kurse` öffnen.
2. „UAT Kurs“ → Anmeldung für sich selbst mit einer fünften Testadresse ausfüllen, Datenschutz bestätigen.
3. Bestätigungsmail öffnen → Link „Anmeldung bestätigen“.

**Erwartetes Ergebnis:** Nach 2 Hinweis „bitte per E-Mail bestätigen“, Kurs zeigt 1 / 1 (Platz reserviert); nach 3 Status „Bestätigt“ mit QR-Code.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 12 – Eltern/Kind
**Voraussetzung:** Konto M.
**Schritte:**
1. Als M: **Allgemein → Kinder & Einwilligungen → „+ Kind hinzufügen“** mit Notfallkontakt und Foto-Einwilligung.
2. Einen anderen (nicht vollen) Kurs öffnen → Anmeldung für das Kind.
3. Als T (Kursleitung) die Teilnehmerliste bzw. „Heute“ öffnen.

**Erwartetes Ergebnis:** Kind ist angemeldet; T sieht den Namen des Kindes, den Notfallkontakt und die Hinweise; die Einwilligung ist unter **Administration → Datenschutz → Einwilligungsprotokoll** sichtbar.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 13 – Warteliste
**Voraussetzung:** TEST 11 (Kurs voll).
**Schritte:**
1. Als M den „UAT Kurs“ öffnen → „Anmelden“.

**Erwartetes Ergebnis:** Meldung „Der Kurs ist voll – du stehst auf der Warteliste (Platz 1)“; Mail „Warteliste“ kommt.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 14 – Stornierung und Nachrücken
**Voraussetzung:** TEST 13.
**Schritte:**
1. Buchungsmail aus TEST 11 öffnen → Link zur Buchung → „Anmeldung stornieren“ (Rückfrage bestätigen).
2. Als M die Benachrichtigungen und das Postfach prüfen.

**Erwartetes Ergebnis:** Storno bestätigt; M rückt automatisch nach und bekommt „Platz frei“; der Kurs zeigt weiterhin 1 / 1 (keine Überbuchung).

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 15 – Anwesenheit
**Voraussetzung:** Ein Termin von TEST 10 liegt in der Vergangenheit (zum Testen im Kalender einen Termin auf heute früh verschieben).
**Schritte:**
1. Als T: Termin öffnen → Anwesenheit: M „anwesend“ → „Anwesenheit speichern“.

**Erwartetes Ergebnis:** Erfolgsmeldung, Status wird gespeichert und in der Kursübersicht angezeigt.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 16 – Trainer bestätigt Einheit
**Voraussetzung:** TEST 15, Honorarsatz für T hinterlegt (**Trainerabrechnungen → „Satz hinzufügen“**).
**Schritte:**
1. Als T: **Trainer → Zeiterfassung** → bei der Einheit „Wie geplant bestätigen“.

**Erwartetes Ergebnis:** Einheit „Durchgeführt“ mit Dauer und Honorarbetrag.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 17 – Trainerabrechnung
**Voraussetzung:** TEST 16.
**Schritte:**
1. Als T: Zeiterfassung → „Monat zur Abrechnung einreichen“.
2. Als A: **Finanzen → Trainerabrechnungen** → Abrechnung öffnen → „Als geprüft markieren“ → „Freigeben“.
3. **Finanzen → Finanzen** und das Projekt „UAT Projekt“ (Tab Finanzen) öffnen.
4. „Als bezahlt markieren“.

**Erwartetes Ergebnis:** Status läuft Eingereicht → Geprüft → Freigegeben → Bezahlt; T bekommt die Nachricht „Abrechnung freigegeben“; die Honorarkosten erscheinen als Ausgabe im Projekt.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 18 – Rechnung erstellen und ausstellen
**Voraussetzung:** Bankdaten und Rechnungsdaten in den Einstellungen.
**Schritte:**
1. **Finanzen → Rechnungen → „+ Neue Rechnung“**, Empfänger M, Position „UAT“ 1 × 20,00.
2. „Prüfen & ausstellen“, PDF öffnen.
3. „Per E-Mail senden“.

**Erwartetes Ergebnis:** Fortlaufende Nummer (z. B. RE-2026-0001); PDF mit Logo, Vereinsdaten, IBAN, Zahlungsziel; M bekommt eine Mail mit Link; M sieht die Rechnung unter „Meine Rechnungen“.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 19 – Teilzahlung
**Voraussetzung:** TEST 18.
**Schritte:**
1. Rechnung öffnen → „Zahlung erfassen“ 5,00 €.
2. Testweise 30,00 € erfassen.

**Erwartetes Ergebnis:** Nach 1 Status „offen“, offener Betrag 15,00 €. Nach 2 Fehlermeldung „Der Betrag übersteigt den offenen Betrag …“.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 20 – Vollständige Zahlung
**Voraussetzung:** TEST 19.
**Schritte:**
1. „Zahlung erfassen“ 15,00 €.
2. Danach „Stornorechnung erstellen“ (Testdaten neutralisieren).

**Erwartetes Ergebnis:** Status „bezahlt“. Die Stornorechnung bekommt eine eigene Nummer, das Original bleibt unverändert sichtbar.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 21 – Förderung
**Voraussetzung:** Konto A, Projekt aus TEST 07.
**Schritte:**
1. **Förderungen → Fördermanagement → Förderung anlegen**: bewilligt 300 €, Projekt „UAT Projekt“.
2. In der Förderung „Kosten erfassen“ 100 € mit Beleg-PDF.
3. „Verwendungsnachweis (PDF)“ öffnen.

**Erwartetes Ergebnis:** Verbraucht 100 €, Rest 200 €; Beleg herunterladbar; der Nachweis listet die Kosten.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 22 – Event
**Voraussetzung:** Konto A.
**Schritte:**
1. **Sportbetrieb → Events → Event anlegen** (heute, 10 Plätze, Preis 0, öffentlich).
2. In einem privaten Fenster über `/kurse` anmelden (Testadresse) und bestätigen.
3. Im Event „Eventbericht (PDF)“ öffnen.

**Erwartetes Ergebnis:** Projekt, Anmeldung und Kalendertermin sind automatisch verknüpft; der Bericht zeigt Anmeldungen und Finanzen.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 23 – QR-Check-in
**Voraussetzung:** TEST 22, Smartphone mit Konto A oder T angemeldet.
**Schritte:**
1. Buchungsseite der Testanmeldung auf dem Laptop öffnen (QR-Code).
2. QR-Code mit der Handy-Kamera scannen → Link öffnen.
3. Denselben QR-Code ein zweites Mal scannen.

**Erwartetes Ergebnis:** Beim ersten Scan „Eingecheckt um … Uhr.“, die Anwesenheit ist gesetzt. Beim zweiten Scan „Bereits um … Uhr eingecheckt.“

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 24 – Kommunikation
**Voraussetzung:** Kurs mit Teilnehmenden (TEST 11/14).
**Schritte:**
1. **Organisation → Kommunikation** → Empfänger „Kurs: UAT Kurs“, Vorlage oder freier Text mit `{{vorname}}`.
2. „Empfänger prüfen“ → „Senden“.
3. Tab „Verlauf“.

**Erwartetes Ergebnis:** Vorschau zeigt die Empfänger; die Mail kommt mit eingesetztem Vornamen an; der Verlauf listet die Nachricht.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 25 – E-Mail-Versand
**Voraussetzung:** Konto A.
**Schritte:**
1. **Administration → Einstellungen → E-Mail → „Testnachricht an mich senden“**.
2. **Kommunikation → E-Mail-Protokoll**.

**Erwartetes Ergebnis:** Die Mail kommt im Posteingang an (nicht im Spam), Umlaute sind korrekt, Absender ist der Vereinsname. Das Protokoll zeigt „gesendet“.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 26 – Automatisierungen
**Voraussetzung:** Cron eingerichtet.
**Schritte:**
1. **Administration → Automatisierungen** → Protokoll ansehen.
2. „Alle jetzt ausführen“ zweimal hintereinander klicken.
3. **Administration → Systemstatus**.

**Erwartetes Ergebnis:** Der zweite Lauf erzeugt keine doppelten Aktionen (keine doppelten Mails); der Systemstatus zeigt „Letzter Cron-Aufruf“ aktuell und 0 Fehler.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 27 – Dokumente
**Voraussetzung:** Konto A, ein PDF.
**Schritte:**
1. **Allgemein → Dokumente** → PDF hochladen, sichtbar für „Mitglieder“.
2. Als M herunterladen.
3. Eine JPG-Datei umbenennen in `.pdf` und hochladen.

**Erwartetes Ergebnis:** Upload und Download funktionieren; die gefälschte Datei wird mit „Nur echte PDF-Dateien sind erlaubt“ abgewiesen.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 28 – Exporte
**Voraussetzung:** Kurs mit Teilnehmenden.
**Schritte:**
1. **Organisation → Dokumente & Exporte** → Teilnehmerliste „UAT Kurs“ als PDF, dann als Excel.
2. Kursübersicht und Buchungsjournal als Excel.
3. Als T: dieselbe Seite öffnen.

**Erwartetes Ergebnis:** PDF im Vereinsdesign; Excel öffnet ohne Warnung mit korrekten Umlauten und Beträgen. T sieht nur seine Kurse und keine Finanzberichte.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 29 – Globale Suche
**Voraussetzung:** Testdaten vorhanden.
**Schritte:**
1. Als A oben „Suchen …“ → „UAT“ eingeben.
2. Als T dasselbe.
3. Suche mit Sonderzeichen: `O'Brien`, `%`, `ä`.

**Erwartetes Ergebnis:** A findet Kurs, Projekt, Aufgabe und Rechnung. T findet keine Rechnungen/Finanzen. Sonderzeichen führen zu keinem Fehler.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 30 – Mobile Traineransicht
**Voraussetzung:** Smartphone, Konto T, Termin heute.
**Schritte:**
1. Als T am Handy anmelden → Menü (☰) → **Heute**.
2. Buttons „Route“, „Check-in“, „Anwesenheit“, „Bestätigen“ antippen.
3. Teilnehmerliste aufklappen.

**Erwartetes Ergebnis:** Kein seitliches Scrollen; alle Buttons sind gut antippbar; die Route öffnet die Karten-App; Teilnehmende mit Notfallhinweisen sind sichtbar.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 31 – Datenschutz-Auskunft
**Voraussetzung:** Konto M.
**Schritte:**
1. Als M: **Mein Profil → „Datenauskunft“** → als JSON herunterladen.

**Erwartetes Ergebnis:** Die Datei enthält Profil, Kinder, Anmeldungen und Einwilligungen, aber **kein** Passwort und keine Tokens.

**Status:** [ ] bestanden [ ] fehlgeschlagen

### TEST 32 – Management und Systemstatus
**Voraussetzung:** Konto A.
**Schritte:**
1. **Management → Management-Cockpit**, Zeitraum „Monat“.
2. **Administration → Systemstatus**.

**Erwartetes Ergebnis:** Die Kennzahlen stimmen mit den Testaktionen überein (Honorar, Einnahmen); der Systemstatus ist überwiegend grün, „Uhrzeit DB / PHP“ ist gleich.

**Status:** [ ] bestanden [ ] fehlgeschlagen

---

**Ergebnis gesamt:** ____ von 32 bestanden · Datum: ________ · geprüft von: ________
Fehlgeschlagene Tests bitte mit Test-Nummer, Uhrzeit und Screenshot melden.
