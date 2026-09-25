# Go-Live-Checkliste – Athletikclub Steiermark

Stand: 25.09.2026 · System: https://aci-stmk.at · Grundlage: Phase 1–3 abgeschlossen, Stand live seit dem Deployment vom 25.09.2026

Diese Liste ist für die Vereinsleitung gedacht. Alles, was hier abgehakt wird, muss **im echten System** geprüft werden –
lokale Tests ersetzen das nicht. Reihenfolge einhalten: erst Teil A, dann B, dann C.

---

## Teil A – Offene Punkte aus Phase 3 (Einstufung)

### BLOCKER – vor dem Go-Live erledigen

| # | Punkt | Wer | Status |
|---|---|---|---|
| B1 | **Datenschutzerklärung aktualisieren.** Sie beschreibt die neuen Verarbeitungen noch nicht vollständig: Online-Kursbuchung inkl. Kinderdaten und Notfallkontakten, Einwilligungen (Foto/Video), Rechnungen/Zahlungen/Mahnungen und 7 Jahre Aufbewahrung (BAO § 132), E-Mail-Versand und Mail-Protokoll, Trainer-Onboarding (Qualifikationen, Bankdaten), Anwesenheit/QR-Check-in, Protokolle (IP-Adressen 90 Tage), Auskunft/Anonymisierung über das Mitgliederkonto, Hetzner als Auftragsverarbeiter, externe Dienste (Google Fonts, jsDelivr). Rechtliche Formulierung durch den Verein bzw. Beratung. | Vereinsleitung | [ ] |
| B2 | **Vereinsstammdaten, Bankverbindung und Rechnungsangaben eintragen** (Einstellungen). Ohne IBAN, Adresse und Steuerhinweis sind Rechnungen und Mahnungen unvollständig. Den Steuerhinweis (z. B. Umsatzsteuerbefreiung) mit der Steuerberatung abstimmen. | Vereinsleitung | [ ] |
| B3 | **E-Mail-Versand nachweislich funktionsfähig** (Testnachricht kommt an, auch nicht im Spam). Ohne funktionierende Mails funktionieren Buchungsbestätigung, Passwort-Reset und Einladungen nicht. | Vereinsleitung + Hoster | [ ] |
| B4 | **Datenbank-Backup erstellt und einmal testweise wiederhergestellt** (siehe Teil B, Abschnitt 12). | Vereinsleitung | [ ] |
| B5 | **Mindestens zwei persönliche Admin-Konten** mit eigenem Passwort; das Installations-Konto `admin@athletikclub-steiermark.at` deaktivieren, falls es nicht persönlich genutzt wird. | Vereinsleitung | [ ] |

Technische Blocker aus dem Code: **keine offen.** In dieser Vorbereitung zusätzlich behoben: Links in E-Mails können nicht mehr auf fremde Domains zeigen (Host-Absicherung), die wirkungslose Option „Angemeldet bleiben“ ist entfernt, und der irreführende Passwort-Hinweis im Installationsskript ist entfernt.

### WICHTIG – zeitnah nach dem Start (innerhalb weniger Wochen)

| # | Punkt | Status |
|---|---|---|
| W1 | **Vergütungsmodell festlegen:** Provision auf Kursumsatz („Kursabrechnungen“) **oder** Honorar je Einheit („Trainerabrechnungen“) – pro Kurs genau eines. Bis dahin warnt das System bei Überschneidung. | [ ] |
| W2 | **GitHub-Repository auf „privat“ stellen** (enthält keine Passwörter, aber den gesamten Quellcode). | [ ] |
| W3 | **Trainer-Rechte fachlich bestätigen:** Trainer sehen derzeit alle Mitglieder (Telefon/Ort) und deren persönliche Dokumente und dürfen alle Trainingspläne bearbeiten. Bei Bedarf einschränken lassen. | [ ] |
| W4 | **Google Fonts lokal einbinden** (derzeit Abruf von Google-Servern beim Seitenaufruf – datenschutzrechtlich umstritten). Kleine technische Änderung, auf Wunsch sofort umsetzbar. | [ ] |
| W5 | **SPF/DKIM/DMARC** für die Absenderdomain beim Hoster prüfen (Zustellbarkeit). | [ ] |
| W6 | **Impressum:** Nach Mediengesetz den Vorstand (vertretungsbefugte Organe) ergänzen. | [ ] |
| W7 | **Honorarsätze** je Trainer:in hinterlegen, sonst werden bestätigte Einheiten ohne Betrag abgerechnet. | [ ] |

### SPÄTER – verhindert den Go-Live nicht

- Zwei-Faktor-Anmeldung für Admin-Konten
- Content-Security-Policy (zusätzlicher Browserschutz)
- Seitenweise Anzeige der Mitglieder-/Nutzerlisten (ab ca. 2.000 Personen)
- Doppelte Upload-Logik zusammenführen, restliche allgemeine Rückfragen konkretisieren
- README aktualisieren (beschreibt noch den Stand der ersten Version)

---

## Teil B – Einrichtung Schritt für Schritt

### 1. Datenbank
- [ ] Anmelden bei konsoleH → phpMyAdmin → Datenbank `dxr26y_db0` öffnen
- [ ] Im Dashboard: **Administration → Systemstatus** → „Migrationen 12 / 12 eingespielt“ (grün)
- [ ] Im Systemstatus: **„Uhrzeit DB / PHP“** zeigt zweimal dieselbe Uhrzeit (sonst Hoster fragen oder melden)
- [ ] Datenbankgröße notiert: ______

### 2. Migrationen
- [ ] Keine offene Migration (Systemstatus zeigt keine gelbe Warnung „Nicht eingespielte Migrationen“)
- [ ] Regel für die Zukunft: neue Migrationen **immer vor** dem Code-Upload einspielen, vorher Backup

### 3. Admin-Konto
- [ ] Persönliches Admin-Konto: Anmeldung funktioniert
- [ ] Zweites Admin-Konto (Vertretung) angelegt: **Administration → Nutzerverwaltung → „Nutzer anlegen & einladen“**, Rolle „Admin“
- [ ] Einladungsmail angekommen, Passwort über den Link gesetzt
- [ ] Installations-Konto `admin@athletikclub-steiermark.at` in der Nutzerverwaltung deaktiviert (falls ungenutzt)
- [ ] Passwörter: mindestens 12 Zeichen, nicht wiederverwendet

### 4. Rollen
- [ ] **Nutzerverwaltung:** Grundrolle jeder Person korrekt (Mitglied / Trainer / Admin). Die Rolle lässt sich direkt in der Liste ändern.
- [ ] **Administration → Rollen & Rechte:** Zusatzrollen vergeben (z. B. „Management“ für das Präsidium, „Administration“ für die Geschäftsstelle, „Projektleitung“)

### 5. Berechtigungen
- [ ] Rechte-Matrix in **Rollen & Rechte** durchsehen: Wer darf Rechnungen ausstellen, Zahlungen erfassen, Abrechnungen freigeben, Personendaten exportieren?
- [ ] Test: Mit einem Trainer-Konto die Adresse `https://aci-stmk.at/dashboard/admin/nutzerverwaltung.php` direkt aufrufen → muss auf das Dashboard umleiten

### 6. Vereinsstammdaten
- [ ] **Administration → Einstellungen → Vereinsdaten:** Name, ZVR, Steuernummer (falls vorhanden), Adresse, verantwortliche Person
- [ ] **Kontakt & Logo:** Kontakt-E-Mail, Telefon, Website; Logo als PNG/JPG (erscheint auf PDFs)

### 7. E-Mail
- [ ] **Einstellungen → E-Mail:** Versand aktiv, Absenderadresse = existierendes Postfach der Vereinsdomain, Absendername
- [ ] „Testnachricht an mich senden“ → kommt an (Posteingang, **nicht** Spam)
- [ ] **Kommunikation → E-Mail-Protokoll:** Eintrag „gesendet“ vorhanden
- [ ] Hoster: SPF/DKIM für die Absenderdomain aktiv (W5)

### 8. Cronjobs / Automationen
- [ ] **Administration → Automatisierungen:** Cron-Schlüssel erzeugen und die angezeigte Adresse kopieren
- [ ] konsoleH → Cronjobs: Aufruf `https://aci-stmk.at/cron.php?key=…` **alle 15 Minuten**
- [ ] Nach 20 Minuten: Systemstatus → „Letzter Cron-Aufruf“ vor wenigen Minuten, „Automationen mit Fehler: 0“
- [ ] Automatisierungen einzeln prüfen: aktiv/inaktiv nach Wunsch (z. B. Mahnungen nur vorbereiten)
- [ ] Hinweis: Beim ersten Lauf rücken Wartelisten-Personen in Kurse mit freien Plätzen nach und bekommen Nachricht

### 9. Datei-Uploads
- [ ] Systemstatus → „Upload-Verzeichnis beschreibbar“ (grün)
- [ ] Systemstatus → PHP-Umgebung: `upload_max_filesize` mindestens **10M** (sonst beim Hoster erhöhen)
- [ ] Test: Unter **Dokumente** ein PDF hochladen und wieder herunterladen
- [ ] Test: Adresse `https://aci-stmk.at/uploads/pdfs/` im Browser → „Zugriff verweigert“ (403)

### 10. Backups
- [ ] konsoleH: automatische Datenbank-Backups aktiv (Aufbewahrung notieren: ____ Tage)
- [ ] Zusätzlich wöchentlicher Export: phpMyAdmin → Exportieren → SQL → Datei extern speichern (verschlüsselter Speicher)
- [ ] Uploads-Ordner (`uploads/`) per FTP gesichert
- [ ] Datei `config/database.php` sicher außerhalb des Servers abgelegt
- [ ] Rechnungsdaten: Backups 7 Jahre aufbewahren (BAO)

### 11. HTTPS
- [ ] `http://aci-stmk.at` → leitet auf `https://aci-stmk.at` um
- [ ] `https://www.aci-stmk.at` → leitet auf `https://aci-stmk.at` um
- [ ] Schloss-Symbol im Browser, Zertifikat gültig (Ablaufdatum notieren: ______)

### 12. Wiederherstellung aus Backup (einmal üben!)
- [ ] In konsoleH eine **zweite, leere Test-Datenbank** anlegen (niemals in die Live-Datenbank importieren!)
- [ ] Letzten Export dort importieren (phpMyAdmin → Importieren)
- [ ] Stichprobe: Anzahl Nutzer, Kurse, Rechnungen stimmt mit der Live-Datenbank überein
- [ ] Test-Datenbank danach wieder löschen
- [ ] Ergebnis und Dauer notieren: ______

### 13. Rechnungsdaten
- [ ] **Einstellungen → Rechnungen & Mahnwesen:** Präfix (z. B. RE), Zahlungsziel, Steuerhinweis, Fußzeile
- [ ] Mahnfristen (Erinnerung / 1. / 2. Mahnung) festgelegt
- [ ] Entscheidung: Mahnungen automatisch versenden oder nur vorbereiten (empfohlen am Anfang: nur vorbereiten)

### 14. Bankdaten
- [ ] **Einstellungen → Bankverbindung:** IBAN und BIC (das System prüft die Prüfziffer)
- [ ] Probe-PDF einer Rechnung zeigt die richtige IBAN

### 15. Datenschutz
- [ ] Datenschutzerklärung aktualisiert und online (B1)
- [ ] **Administration → Datenschutz:** Aufbewahrungsfristen gelesen, Verantwortliche Person für Auskunftsanfragen bestimmt
- [ ] Ablauf für Auskunfts-/Löschanfragen festgelegt (Auskunft als JSON/Excel, Löschung = Anonymisierung)
- [ ] Monatliche „Bereinigung nach Fristen“ in den Kalender eingetragen

### 16. Öffentliche Kursanmeldung
- [ ] Mindestens einen Kurs mit „Öffentliche Anmeldung im Kursportal“ angelegt
- [ ] `https://aci-stmk.at/kurse` zeigt den Kurs
- [ ] Stornofrist und Bestätigungsfrist in den Einstellungen (Kurse & Fristen) geprüft

### 17. Trainerzugänge
- [ ] Alle aktiven Trainer:innen haben ein Konto (Nutzerverwaltung oder über Trainer-Onboarding)
- [ ] Honorarsätze hinterlegt (**Finanzen → Trainerabrechnungen → Honorarsätze → „Satz hinzufügen“**)
- [ ] Qualifikationen (inkl. Erste Hilfe) eingetragen
- [ ] Trainer-Handbuch (`TRAINER_HANDBOOK.md`) verteilt

### 18. Testbuchung (mit eigener, zweiter E-Mail-Adresse)
- [ ] Testkurs (öffentlich, 1 Platz) anlegen
- [ ] Ohne Anmeldung über `/kurse` buchen → Bestätigungsmail → Link klicken → Status „Bestätigt“
- [ ] Zweite Buchung → Warteliste; erste stornieren → zweite rückt nach, Mail kommt
- [ ] Testkurs danach absagen

### 19. Testrechnung
- [ ] **Finanzen → Rechnungen → „+ Neue Rechnung“** an die eigene Adresse, 1 Position 1,00 €
- [ ] „Prüfen & ausstellen“ → Nummer vergeben, PDF korrekt (Logo, Adresse, IBAN, Steuerhinweis)
- [ ] „Per E-Mail senden“ → Mail mit Link kommt an
- [ ] Zahlung 0,50 € → Status offen; Zahlung 0,50 € → bezahlt
- [ ] Hinweis: Ausgestellte Rechnungen können nicht gelöscht werden – eine Testrechnung wird mit „Stornorechnung erstellen“ neutralisiert. Die Nummer bleibt verbraucht (das ist gesetzlich korrekt).

### 20. Testabrechnung (Trainer)
- [ ] Testeinheit mit einem Trainer anlegen (Kalender → Einheit planen), Zeit in der Vergangenheit
- [ ] Als Trainer: **Zeiterfassung → „Wie geplant bestätigen“**
- [ ] Als Trainer: „Monat zur Abrechnung einreichen“
- [ ] Als Admin: **Trainerabrechnungen** → „Als geprüft markieren“ → „Freigeben“ → Kostenbuchung im Projekt/Finanzen sichtbar
- [ ] „Als bezahlt markieren“ erst nach tatsächlicher Überweisung

### 21. Test-E-Mail
- [ ] siehe Punkt 7 – zusätzlich **Kommunikation → Nachricht senden** an „Person“ (sich selbst) mit Kanal E-Mail

---

## Teil C – Go-Live-Tag

- [ ] Frisches Backup (Datenbank + Uploads)
- [ ] Systemstatus: alles grün, keine Fehler
- [ ] Testdaten (Testkurs, Testrechnung = storniert, Testeinheit) entfernt bzw. abgesagt
- [ ] Kursportal-Link auf Website/Social Media veröffentlicht
- [ ] Trainer:innen informiert und eingeladen
- [ ] In der ersten Woche täglich: Systemstatus, **Übersicht → Handlungsbedarf**, E-Mail-Protokoll
