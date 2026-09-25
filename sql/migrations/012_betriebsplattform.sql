-- ============================================================
--  Migration 012: Betriebsplattform (Buchungsportal, Kommunikation,
--  Rechnungen, Onboarding, Events, Check-in, Automatisierung)
--  Athletikclub Steiermark – siehe docs/AUSBAU-PLAN-2.md
--
--  Rein additiv, keine bestehenden Daten werden gelöscht.
--  Sicher erneut ausführbar (IF NOT EXISTS / INSERT IGNORE).
-- ============================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------
-- 1. Zentrale Einstellungen (Vereinsstammdaten bleiben in prae_einstellungen)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `einstellungen` (
  `organization_id` INT UNSIGNED  NOT NULL DEFAULT 1,
  `schluessel`      VARCHAR(80)   NOT NULL,
  `wert`            TEXT          NULL,
  `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`organization_id`, `schluessel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 2. Öffentliches Buchungsportal
-- ----------------------------------------------------------------
ALTER TABLE `kurse`
  ADD COLUMN IF NOT EXISTS `oeffentlich`        TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'Im öffentlichen Kursportal buchbar',
  ADD COLUMN IF NOT EXISTS `art`                ENUM('kurs','event') NOT NULL DEFAULT 'kurs',
  ADD COLUMN IF NOT EXISTS `kurzbeschreibung`   VARCHAR(300)  NULL,
  ADD COLUMN IF NOT EXISTS `bild`               VARCHAR(255)  NULL COMMENT 'Dateiname in uploads/images',
  ADD COLUMN IF NOT EXISTS `storno_frist_std`   SMALLINT UNSIGNED NULL COMMENT 'Selbst-Storno bis X Stunden vor Beginn (NULL = Standard)',
  ADD INDEX IF NOT EXISTS `idx_kurse_oeffentlich` (`organization_id`, `oeffentlich`, `status`, `start_datum`);

ALTER TABLE `kurs_anmeldungen`
  MODIFY COLUMN `status` ENUM('angemeldet','warteliste','storniert','teilgenommen','angefragt','nicht_erschienen') NOT NULL DEFAULT 'angemeldet',
  ADD COLUMN IF NOT EXISTS `token`          CHAR(32)      NULL COMMENT 'Zufallstoken: Bestätigung und Selbst-Storno (Buchungslink)',
  ADD COLUMN IF NOT EXISTS `checkin_code`   CHAR(32)      NULL COMMENT 'Eigener Zufallscode nur für den QR-Check-in',
  ADD COLUMN IF NOT EXISTS `quelle`         ENUM('dashboard','oeffentlich') NOT NULL DEFAULT 'dashboard',
  ADD COLUMN IF NOT EXISTS `anfrage_bis`    DATETIME      NULL COMMENT 'Unbestätigte öffentliche Anfrage verfällt danach',
  ADD COLUMN IF NOT EXISTS `bestaetigt_am`  DATETIME      NULL,
  ADD COLUMN IF NOT EXISTS `storniert_am`   DATETIME      NULL,
  ADD COLUMN IF NOT EXISTS `eingecheckt_am` DATETIME      NULL,
  ADD UNIQUE INDEX IF NOT EXISTS `uk_ka_token` (`token`),
  ADD UNIQUE INDEX IF NOT EXISTS `uk_ka_checkin` (`checkin_code`),
  ADD INDEX IF NOT EXISTS `idx_ka_kurs_status` (`kurs_id`, `status`),
  ADD INDEX IF NOT EXISTS `idx_ka_user` (`user_id`, `status`);

-- ----------------------------------------------------------------
-- 3. Kommunikation: Vorlagen, Versandaufträge, Mail-Protokoll
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nachricht_vorlagen` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED  NOT NULL DEFAULT 1,
  `code`            VARCHAR(60)   NOT NULL,
  `name`            VARCHAR(150)  NOT NULL,
  `betreff`         VARCHAR(200)  NOT NULL,
  `text`            TEXT          NOT NULL,
  `kanal`           ENUM('intern','email','beide') NOT NULL DEFAULT 'beide',
  `aktiv`           TINYINT(1)    NOT NULL DEFAULT 1,
  `system`          TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'Wird von Abläufen verwendet – nicht löschbar',
  `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_vorlage_code` (`organization_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nachrichten` (
  `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id`   INT UNSIGNED  NOT NULL DEFAULT 1,
  `betreff`           VARCHAR(200)  NOT NULL,
  `text`              TEXT          NOT NULL,
  `kanaele`           VARCHAR(40)   NOT NULL DEFAULT 'intern',
  `empfaenger_typ`    VARCHAR(30)   NOT NULL,
  `empfaenger_ref`    INT UNSIGNED  NULL,
  `empfaenger_anzahl` INT UNSIGNED  NOT NULL DEFAULT 0,
  `mails_gesendet`    INT UNSIGNED  NOT NULL DEFAULT 0,
  `vorlage_id`        INT UNSIGNED  NULL,
  `erstellt_von`      INT UNSIGNED  NULL,
  `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_nachr_org` (`organization_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mail_log` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED  NOT NULL DEFAULT 1,
  `user_id`         INT UNSIGNED  NULL,
  `email`           VARCHAR(180)  NOT NULL,
  `betreff`         VARCHAR(200)  NOT NULL,
  `status`          ENUM('gesendet','fehler','deaktiviert') NOT NULL,
  `fehler`          VARCHAR(500)  NULL,
  `bezug`           VARCHAR(80)   NULL COMMENT 'z.B. nachricht:5, rechnung:3, erinnerung:12',
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_mail_org_zeit` (`organization_id`, `created_at`),
  INDEX `idx_mail_bezug` (`bezug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 4. Rechnungen, Zahlungen, Mahnwesen
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nummernkreise` (
  `organization_id` INT UNSIGNED  NOT NULL DEFAULT 1,
  `kreis`           VARCHAR(30)   NOT NULL,
  `jahr`            SMALLINT UNSIGNED NOT NULL,
  `letzte_nr`       INT UNSIGNED  NOT NULL DEFAULT 0,
  PRIMARY KEY (`organization_id`, `kreis`, `jahr`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rechnungen` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED  NOT NULL DEFAULT 1,
  `nummer`          VARCHAR(30)   NULL COMMENT 'Wird erst beim Ausstellen vergeben',
  `zugriff_token`   CHAR(32)      NULL COMMENT 'Link für Empfänger:innen ohne Login',
  `typ`             ENUM('rechnung','storno') NOT NULL DEFAULT 'rechnung',
  `status`          ENUM('entwurf','offen','bezahlt','storniert') NOT NULL DEFAULT 'entwurf',
  `storno_zu_id`    INT UNSIGNED  NULL COMMENT 'Bei Stornorechnung: die stornierte Rechnung',
  `user_id`         INT UNSIGNED  NULL,
  `partner_id`      INT UNSIGNED  NULL,
  `kurs_id`         INT UNSIGNED  NULL,
  `projekt_id`      INT UNSIGNED  NULL,
  `anmeldung_id`    INT UNSIGNED  NULL,
  `empf_name`       VARCHAR(200)  NOT NULL COMMENT 'Empfängeranschrift zum Ausstellungszeitpunkt (Belegpflicht)',
  `empf_zusatz`     VARCHAR(200)  NULL,
  `empf_strasse`    VARCHAR(200)  NULL,
  `empf_plz`        VARCHAR(10)   NULL,
  `empf_ort`        VARCHAR(100)  NULL,
  `empf_land`       VARCHAR(60)   NULL,
  `empf_email`      VARCHAR(180)  NULL,
  `empf_uid`        VARCHAR(30)   NULL,
  `rechnungsdatum`  DATE          NULL,
  `leistung_von`    DATE          NULL,
  `leistung_bis`    DATE          NULL,
  `faellig_am`      DATE          NULL,
  `betrag_netto`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `betrag_ust`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `betrag_brutto`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `text_oben`       TEXT          NULL,
  `text_unten`      TEXT          NULL,
  `steuerhinweis`   VARCHAR(300)  NULL,
  `mahnstufe`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `ausgestellt_am`  DATETIME      NULL,
  `ausgestellt_von` INT UNSIGNED  NULL,
  `erstellt_von`    INT UNSIGNED  NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rechnung_nummer` (`organization_id`, `nummer`),
  UNIQUE KEY `uk_rechnung_token` (`zugriff_token`),
  INDEX `idx_re_status` (`organization_id`, `status`, `faellig_am`),
  INDEX `idx_re_user` (`user_id`),
  INDEX `idx_re_partner` (`partner_id`),
  INDEX `idx_re_projekt` (`projekt_id`),
  INDEX `idx_re_anmeldung` (`anmeldung_id`),
  CONSTRAINT `fk_re_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_re_partner` FOREIGN KEY (`partner_id`) REFERENCES `partner_organisationen`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_re_kurs` FOREIGN KEY (`kurs_id`) REFERENCES `kurse`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_re_projekt` FOREIGN KEY (`projekt_id`) REFERENCES `projekte`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rechnung_positionen` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `rechnung_id`   INT UNSIGNED  NOT NULL,
  `pos`           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `beschreibung`  VARCHAR(300)  NOT NULL,
  `menge`         DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `einheit`       VARCHAR(20)   NULL,
  `einzelpreis`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `ust_satz`      DECIMAL(4,2)  NOT NULL DEFAULT 0.00,
  `betrag`        DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Menge × Einzelpreis (netto)',
  `anmeldung_id`  INT UNSIGNED  NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_rp_rechnung` (`rechnung_id`, `pos`),
  CONSTRAINT `fk_rp_rechnung` FOREIGN KEY (`rechnung_id`) REFERENCES `rechnungen`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `zahlungen` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED  NOT NULL DEFAULT 1,
  `rechnung_id`     INT UNSIGNED  NOT NULL,
  `betrag`          DECIMAL(10,2) NOT NULL,
  `datum`           DATE          NOT NULL,
  `zahlungsart`     ENUM('ueberweisung','bar','karte','sonstiges') NOT NULL DEFAULT 'ueberweisung',
  `referenz`        VARCHAR(120)  NULL,
  `buchung_id`      INT UNSIGNED  NULL COMMENT 'Erzeugte Einnahme-Buchung (nicht bei Kursbeiträgen)',
  `erfasst_von`     INT UNSIGNED  NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_za_rechnung` (`rechnung_id`),
  INDEX `idx_za_org_datum` (`organization_id`, `datum`),
  CONSTRAINT `fk_za_rechnung` FOREIGN KEY (`rechnung_id`) REFERENCES `rechnungen`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mahnungen` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `rechnung_id`     INT UNSIGNED  NOT NULL,
  `stufe`           TINYINT UNSIGNED NOT NULL COMMENT '1 = Zahlungserinnerung, 2 = 1. Mahnung, 3 = 2. Mahnung',
  `status`          ENUM('vorbereitet','versendet','verworfen') NOT NULL DEFAULT 'vorbereitet',
  `offener_betrag`  DECIMAL(10,2) NOT NULL,
  `kanal`           VARCHAR(20)   NULL,
  `erstellt_von`    INT UNSIGNED  NULL COMMENT 'NULL = Automatisierung',
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `versendet_am`    DATETIME      NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_mahnung_stufe` (`rechnung_id`, `stufe`),
  CONSTRAINT `fk_ma_rechnung` FOREIGN KEY (`rechnung_id`) REFERENCES `rechnungen`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 5. Trainer-Onboarding
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `onboarding_punkte` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED  NOT NULL DEFAULT 1,
  `titel`           VARCHAR(150)  NOT NULL,
  `beschreibung`    VARCHAR(300)  NULL,
  `phase`           VARCHAR(20)   NOT NULL DEFAULT 'stammdaten',
  `pflicht`         TINYINT(1)    NOT NULL DEFAULT 1,
  `auto_pruefung`   VARCHAR(40)   NULL COMMENT 'konto, bankdaten, erste_hilfe, qualifikation, vereinbarung, datenschutz',
  `reihenfolge`     SMALLINT      NOT NULL DEFAULT 0,
  `aktiv`           TINYINT(1)    NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  INDEX `idx_obp_org` (`organization_id`, `aktiv`, `reihenfolge`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `onboarding` (
  `id`                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id`     INT UNSIGNED  NOT NULL DEFAULT 1,
  `user_id`             INT UNSIGNED  NULL COMMENT 'Konto, sobald angelegt',
  `vorname`             VARCHAR(80)   NOT NULL,
  `nachname`            VARCHAR(80)   NOT NULL,
  `email`               VARCHAR(180)  NOT NULL,
  `telefon`             VARCHAR(40)   NULL,
  `schwerpunkt`         VARCHAR(200)  NULL,
  `nachricht`           TEXT          NULL,
  `status`              ENUM('bewerbung','pruefung','aufnahme','stammdaten','qualifikationen','dokumente','vereinbarung','einschulung','freigabe','aktiv','abgelehnt','zurueckgezogen') NOT NULL DEFAULT 'bewerbung',
  `betreuer_id`         INT UNSIGNED  NULL,
  `kontakt_anfrage_id`  INT UNSIGNED  NULL,
  `einschulung_am`      DATE          NULL,
  `notiz`               TEXT          NULL,
  `abgeschlossen_am`    DATETIME      NULL,
  `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_ob_org_status` (`organization_id`, `status`),
  INDEX `idx_ob_user` (`user_id`),
  CONSTRAINT `fk_ob_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `onboarding_status` (
  `onboarding_id`  INT UNSIGNED  NOT NULL,
  `punkt_id`       INT UNSIGNED  NOT NULL,
  `status`         ENUM('offen','erledigt','nicht_erforderlich') NOT NULL DEFAULT 'offen',
  `erledigt_am`    DATETIME      NULL,
  `erledigt_von`   INT UNSIGNED  NULL,
  `notiz`          VARCHAR(300)  NULL,
  PRIMARY KEY (`onboarding_id`, `punkt_id`),
  CONSTRAINT `fk_obs_onboarding` FOREIGN KEY (`onboarding_id`) REFERENCES `onboarding`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_obs_punkt` FOREIGN KEY (`punkt_id`) REFERENCES `onboarding_punkte`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 6. Automatisierungs-Engine
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `automationen` (
  `id`                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id`       INT UNSIGNED  NOT NULL DEFAULT 1,
  `code`                  VARCHAR(60)   NOT NULL,
  `aktiv`                 TINYINT(1)    NOT NULL DEFAULT 1,
  `intervall_min`         INT UNSIGNED  NOT NULL DEFAULT 60,
  `parameter`             TEXT          NULL COMMENT 'JSON',
  `letzte_ausfuehrung`    DATETIME      NULL,
  `naechste_ausfuehrung`  DATETIME      NULL,
  `letzter_status`        VARCHAR(20)   NULL,
  `letzter_fehler`        TEXT          NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_automation` (`organization_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `automation_log` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED  NOT NULL DEFAULT 1,
  `automation`      VARCHAR(60)   NOT NULL,
  `ausloeser`       VARCHAR(120)  NULL,
  `datensatz`       VARCHAR(80)   NULL,
  `aktion`          VARCHAR(255)  NULL,
  `ergebnis`        ENUM('ok','uebersprungen','fehler') NOT NULL DEFAULT 'ok',
  `fehler`          TEXT          NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_al_org_zeit` (`organization_id`, `created_at`),
  INDEX `idx_al_automation` (`automation`, `created_at`),
  INDEX `idx_al_datensatz` (`automation`, `datensatz`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 7. Leistungsindizes für häufige Abfragen
-- ----------------------------------------------------------------
ALTER TABLE `aktivitaets_log` ADD INDEX IF NOT EXISTS `idx_log_aktion_zeit` (`aktion`, `created_at`);
ALTER TABLE `einheit_trainer` ADD INDEX IF NOT EXISTS `idx_et_einheit_status` (`einheit_id`, `status`);

-- ----------------------------------------------------------------
-- 8. Standardwerte: Onboarding-Checkliste, Nachrichtenvorlagen
-- ----------------------------------------------------------------
INSERT INTO `onboarding_punkte` (`organization_id`, `titel`, `beschreibung`, `phase`, `pflicht`, `auto_pruefung`, `reihenfolge`)
SELECT 1, t.titel, t.beschr, t.phase, t.pflicht, t.auto, t.rf FROM (
  SELECT 'Persönliche Daten' AS titel, 'Name, Geburtsdatum, Adresse' AS beschr, 'stammdaten' AS phase, 1 AS pflicht, NULL AS auto, 10 AS rf UNION ALL
  SELECT 'Kontaktdaten', 'E-Mail und Telefonnummer', 'stammdaten', 1, NULL, 20 UNION ALL
  SELECT 'Bankdaten', 'IBAN für Honorar/PRAE (PRAE-Empfängerdaten)', 'stammdaten', 1, 'bankdaten', 30 UNION ALL
  SELECT 'Qualifikationen', 'Mindestens eine gültige Trainerqualifikation', 'qualifikationen', 1, 'qualifikation', 40 UNION ALL
  SELECT 'Erste Hilfe', 'Gültiger Erste-Hilfe-Nachweis', 'qualifikationen', 1, 'erste_hilfe', 50 UNION ALL
  SELECT 'Trainervereinbarung', 'Unterzeichnete Trainer:innen-Vereinbarung (Verträge)', 'vereinbarung', 1, 'vereinbarung', 60 UNION ALL
  SELECT 'Datenschutz', 'Datenschutz- und Verschwiegenheitserklärung', 'dokumente', 1, NULL, 70 UNION ALL
  SELECT 'Einschulung', 'Einschulung in Abläufe, Sicherheit und System', 'einschulung', 1, NULL, 80 UNION ALL
  SELECT 'Systemzugang', 'Dashboard-Konto angelegt und aktiviert', 'freigabe', 1, 'konto', 90
) t WHERE NOT EXISTS (SELECT 1 FROM `onboarding_punkte` WHERE `organization_id` = 1);

INSERT IGNORE INTO `nachricht_vorlagen` (`organization_id`, `code`, `name`, `betreff`, `text`, `kanal`, `system`) VALUES
 (1, 'anmeldung_anfrage', 'Kursanmeldung – bitte bestätigen', 'Bitte bestätige deine Anmeldung: {{kurs}}',
  'Hallo {{vorname}},\n\ndanke für deine Anmeldung zu „{{kurs}}“ ({{person}}).\nBitte bestätige sie innerhalb von 48 Stunden über diesen Link:\n{{link}}\n\nBeginn: {{datum}}, {{uhrzeit}} Uhr · {{ort}}\n\nSportliche Grüße\n{{verein}}', 'email', 1),
 (1, 'anmeldung_bestaetigt', 'Kursanmeldung bestätigt', 'Anmeldung bestätigt: {{kurs}}',
  'Hallo {{vorname}},\n\n{{person}} ist für „{{kurs}}“ fix angemeldet.\nBeginn: {{datum}}, {{uhrzeit}} Uhr · {{ort}}\nTrainer:in: {{trainer}}\n\nDeine Buchung (Check-in-QR-Code, Stornierung): {{link}}\n\nSportliche Grüße\n{{verein}}', 'beide', 1),
 (1, 'warteliste', 'Auf der Warteliste', 'Warteliste: {{kurs}}',
  'Hallo {{vorname}},\n\n„{{kurs}}“ ist derzeit ausgebucht. {{person}} steht auf der Warteliste (Platz {{position}}).\nWird ein Platz frei, rückst du automatisch nach und bekommst eine Nachricht.\n\nDeine Buchung: {{link}}\n\n{{verein}}', 'beide', 1),
 (1, 'warteliste_platz_frei', 'Wartelistenplatz verfügbar', 'Platz frei: {{kurs}}',
  'Hallo {{vorname}},\n\ngute Nachricht: Für „{{kurs}}“ ist ein Platz frei geworden – {{person}} ist jetzt fix angemeldet.\nBeginn: {{datum}}, {{uhrzeit}} Uhr · {{ort}}\n\nFalls du doch nicht teilnehmen kannst, storniere bitte hier: {{link}}\n\n{{verein}}', 'beide', 1),
 (1, 'storno_bestaetigt', 'Stornierung bestätigt', 'Stornierung: {{kurs}}',
  'Hallo {{vorname}},\n\ndie Anmeldung von {{person}} zu „{{kurs}}“ wurde storniert.\n\n{{verein}}', 'beide', 1),
 (1, 'kurs_abgesagt', 'Kursabsage', 'Abgesagt: {{kurs}}',
  'Hallo {{vorname}},\n\nleider müssen wir „{{kurs}}“ ({{datum}}) absagen. Wir melden uns mit Alternativen.\n\n{{verein}}', 'beide', 1),
 (1, 'termin_geaendert', 'Terminänderung', 'Terminänderung: {{kurs}}',
  'Hallo {{vorname}},\n\nder Termin „{{kurs}}“ hat sich geändert: {{datum}}, {{uhrzeit}} Uhr · {{ort}}.\n\n{{verein}}', 'beide', 1),
 (1, 'kurs_erinnerung', 'Kurs-Erinnerung', 'Erinnerung: {{kurs}} am {{datum}}',
  'Hallo {{vorname}},\n\nkurze Erinnerung: „{{kurs}}“ findet am {{datum}} um {{uhrzeit}} Uhr statt.\nOrt: {{ort}}\nTrainer:in: {{trainer}}\n{{hinweise}}\n\nBis bald!\n{{verein}}', 'beide', 1),
 (1, 'trainer_zuweisung', 'Trainer-Zuweisung', 'Neuer Einsatz: {{kurs}}',
  'Hallo {{vorname}},\n\ndu bist für „{{kurs}}“ am {{datum}} um {{uhrzeit}} Uhr eingeteilt ({{ort}}).\n\n{{verein}}', 'beide', 1),
 (1, 'abrechnung_freigegeben', 'Abrechnung freigegeben', 'Deine Abrechnung {{zeitraum}} ist freigegeben',
  'Hallo {{vorname}},\n\ndeine Abrechnung für {{zeitraum}} über {{betrag}} wurde freigegeben.\n\n{{verein}}', 'beide', 1),
 (1, 'zahlungserinnerung', 'Zahlungserinnerung', 'Zahlungserinnerung zu Rechnung {{rechnung}}',
  'Hallo {{vorname}},\n\nzu unserer Rechnung {{rechnung}} vom {{rechnungsdatum}} ist noch ein Betrag von {{betrag}} offen (fällig seit {{faellig}}).\nFalls du bereits bezahlt hast, betrachte diese Nachricht bitte als gegenstandslos.\n\nBankverbindung: {{iban}} · Verwendungszweck: {{rechnung}}\n\n{{verein}}', 'email', 1),
 (1, 'mahnung_1', '1. Mahnung', '1. Mahnung zu Rechnung {{rechnung}}',
  'Hallo {{vorname}},\n\ntrotz unserer Erinnerung ist zu Rechnung {{rechnung}} noch {{betrag}} offen. Bitte überweise den Betrag innerhalb von 14 Tagen.\n\nBankverbindung: {{iban}} · Verwendungszweck: {{rechnung}}\n\n{{verein}}', 'email', 1),
 (1, 'mahnung_2', '2. Mahnung', '2. Mahnung zu Rechnung {{rechnung}}',
  'Hallo {{vorname}},\n\nzu Rechnung {{rechnung}} ist weiterhin {{betrag}} offen. Bitte melde dich bei uns, falls es Probleme gibt.\n\nBankverbindung: {{iban}} · Verwendungszweck: {{rechnung}}\n\n{{verein}}', 'email', 1),
 (1, 'rechnung_versand', 'Rechnung', 'Rechnung {{rechnung}}',
  'Hallo {{vorname}},\n\nanbei die Rechnung {{rechnung}} über {{betrag}}, zahlbar bis {{faellig}}.\nOnline ansehen: {{link}}\n\nBankverbindung: {{iban}} · Verwendungszweck: {{rechnung}}\n\n{{verein}}', 'email', 1),
 (1, 'event_anmeldung', 'Event-Anmeldung', 'Anmeldung bestätigt: {{kurs}}',
  'Hallo {{vorname}},\n\n{{person}} ist für das Event „{{kurs}}“ am {{datum}} um {{uhrzeit}} Uhr angemeldet ({{ort}}).\nDein Check-in-Code: {{link}}\n\n{{verein}}', 'beide', 1),
 (1, 'bewerbung_eingang', 'Bewerbung erhalten', 'Danke für deine Bewerbung beim {{verein}}',
  'Hallo {{vorname}},\n\ndanke für dein Interesse, als Trainer:in bei uns mitzuwirken! Wir haben deine Bewerbung erhalten und melden uns in Kürze bei dir.\n\nSportliche Grüße\n{{verein}}', 'email', 1),
 (1, 'konto_einladung', 'Konto-Einladung', 'Dein Zugang zum {{verein}}',
  'Hallo {{vorname}},\n\nfür dich wurde ein Konto im Dashboard des {{verein}} angelegt.\nLege dein Passwort über diesen Link fest (7 Tage gültig):\n{{link}}\n\nSportliche Grüße\n{{verein}}', 'email', 1),
 (1, 'einheit_bestaetigen', 'Einheit bestätigen', 'Bitte Einheit bestätigen: {{kurs}}',
  'Hallo {{vorname}},\n\nbitte bestätige die Einheit „{{kurs}}“ vom {{datum}} (Dauer, Teilnehmende, Anwesenheit): {{link}}\n\n{{verein}}', 'intern', 1);

-- ----------------------------------------------------------------
-- 9. Berechtigungen
-- ----------------------------------------------------------------
INSERT IGNORE INTO `permissions` (`code`, `beschreibung`) VALUES
  ('kommunikation.senden',         'Nachrichten an Gruppen senden'),
  ('vorlagen.bearbeiten',          'Nachrichtenvorlagen pflegen'),
  ('rechnungen.anzeigen',          'Rechnungen und Zahlungen sehen'),
  ('rechnungen.bearbeiten',        'Rechnungsentwürfe erstellen und bearbeiten'),
  ('rechnungen.ausstellen',        'Rechnungen ausstellen, stornieren, Mahnungen versenden'),
  ('zahlungen.erfassen',           'Zahlungen erfassen'),
  ('events.anzeigen',              'Events sehen'),
  ('events.bearbeiten',            'Events anlegen und verwalten'),
  ('onboarding.anzeigen',          'Trainer-Onboarding sehen'),
  ('onboarding.bearbeiten',        'Trainer-Onboarding bearbeiten und Checklisten pflegen'),
  ('management.anzeigen',          'Management-/Präsidiumsbereich'),
  ('einstellungen.bearbeiten',     'Zentrale Einstellungen bearbeiten'),
  ('automatisierungen.bearbeiten', 'Automatisierungen steuern'),
  ('export.personen',              'Personenbezogene Listen exportieren'),
  ('datenschutz.bearbeiten',       'Datenauskunft und Anonymisierung'),
  ('system.anzeigen',              'Systemstatus einsehen');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.code IN ('ORGANIZATION_ADMIN', 'SUPER_ADMIN');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r, `permissions` p
WHERE r.code = 'MANAGEMENT' AND p.code IN ('management.anzeigen','rechnungen.anzeigen','events.anzeigen','onboarding.anzeigen','kommunikation.senden');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r, `permissions` p
WHERE r.code = 'ADMINISTRATION' AND p.code IN ('kommunikation.senden','vorlagen.bearbeiten','rechnungen.anzeigen','rechnungen.bearbeiten',
      'rechnungen.ausstellen','zahlungen.erfassen','events.anzeigen','events.bearbeiten','onboarding.anzeigen','onboarding.bearbeiten','export.personen');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r, `permissions` p
WHERE r.code = 'PROJECT_MANAGER' AND p.code IN ('events.anzeigen','events.bearbeiten','kommunikation.senden');

INSERT IGNORE INTO `migrations` (`filename`) VALUES ('012_betriebsplattform.sql');
