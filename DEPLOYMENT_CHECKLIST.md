# Deployment-Checkliste – Athletikclub Steiermark

Für jedes Deployment auf `aci-stmk.at` (Hetzner Webhosting, Upload per FTPS nach `public_html/`).
`git push` allein deployt **nicht**.

## Vor dem Deployment

- [ ] Datenbank-Backup erstellt (phpMyAdmin → Exportieren, oder Hetzner-Backup) und Datei außerhalb des Servers abgelegt
- [ ] Uploads gesichert (`uploads/pdfs`, `uploads/images`)
- [ ] `config/database.php` **nicht** überschreiben (liegt nur auf dem Server bzw. lokal, nicht im Repository)
- [ ] Neue Migrationen (`sql/migrations/NNN_*.sql`) zuerst auf einer Kopie bzw. lokalen Test-DB geprüft
- [ ] Migrationen **vor** dem Code-Upload in phpMyAdmin ausgeführt (Reihenfolge der Nummern)
- [ ] `APP_ENV` in `config/config.php` steht auf `production`
- [ ] Lokale Tests: Regression (alle Seiten × 3 Rollen), Workflowtests, Grenzfälle grün

## Deployment

- [ ] Nur geänderte Dateien hochladen (`git diff --name-only <letzter-deploy>..HEAD`, ohne `docs/`, `sql/`, `*.md`)
- [ ] `.htaccess` mit hochgeladen, wenn geändert – danach **sofort** Startseite und Login prüfen (bei 500er: vorherige `.htaccess` aus Git zurückspielen)

## Nach dem Deployment

- [ ] HTTPS aktiv, `http://` und `www.` leiten auf `https://aci-stmk.at` um
- [ ] Gesperrt (403/404): `/config/database.php`, `/includes/auth.php`, `/vendor/autoload.php`, `/composer.json`, `/sql/…`, `/uploads/…`, `/logs/…`, `/.env`
- [ ] Startseite, `/kurse`, Kontaktformular laden ohne Fehler
- [ ] Login getestet (Admin, Trainer, Mitglied); falsches Passwort 6× → Sperrhinweis
- [ ] „Passwort vergessen“ getestet (Mail kommt an, Link funktioniert, neues Passwort gilt)
- [ ] Systemstatus: alle Migrationen eingespielt, **Uhrzeit DB / PHP** gleich, E-Mail „Versand bereit“, keine Fehler
- [ ] Einstellungen → „Testnachricht an mich senden“ kommt an (auch Spam-Ordner prüfen)
- [ ] Cron eingerichtet (`cron.php?key=…`, alle 15 min) und im Systemstatus „Letzter Cron-Aufruf“ aktuell
- [ ] Öffentliche Kursanmeldung getestet (Bestätigungslink, Warteliste bei vollem Testkurs)
- [ ] Rechnung getestet: Entwurf → Ausstellen (Nummer) → PDF → Teilzahlung → Restzahlung → bezahlt
- [ ] Trainerabrechnung getestet: Einheit bestätigen → Monatsabrechnung → eingereicht → freigegeben → Kostenbuchung im Projekt
- [ ] Berechtigungen getestet: als Trainer und als Mitglied eine Admin-URL direkt aufrufen → Weiterleitung
- [ ] Debug aus: Fehlerseite zeigt nur „Es ist ein Fehler aufgetreten.“ (keine SQL-/Pfadangaben)
- [ ] `logs/php-error.log` auf neue Einträge geprüft (FTP; Datei ist per Web nicht erreichbar)

## Regelmäßig

- [ ] Backup-Wiederherstellung einmal pro Quartal in eine Test-Datenbank geprüft
- [ ] Datenschutz → „Bereinigung nach Fristen“ monatlich
- [ ] Systemstatus wöchentlich (fehlgeschlagene Automationen, Speicher, Mail-Fehler)
- [ ] PHP-Version und `vendor/` (TCPDF) aktuell halten
