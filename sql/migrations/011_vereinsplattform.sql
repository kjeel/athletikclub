-- ============================================================
--  Migration 011: Vereinsplattform (Einsatzplanung, Projekte, CRM …)
--  Athletikclub Steiermark – siehe docs/AUSBAU-PLAN.md
--
--  Rein additiv: neue Tabellen, neue Spalten/Indizes, erweiterte ENUMs.
--  Es werden KEINE bestehenden Daten gelöscht oder umgeschrieben.
--  Sicher erneut ausführbar (MariaDB: IF NOT EXISTS / INSERT IGNORE).
-- ============================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------
-- 1. Partner / CRM
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `partner_organisationen` (
  `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id`   INT UNSIGNED  NOT NULL DEFAULT 1,
  `name`              VARCHAR(200)  NOT NULL,
  `kategorie`         ENUM('gemeinde','schule','kindergarten','unternehmen','sponsor','verband','sonstiges') NOT NULL DEFAULT 'sonstiges',
  `status`            ENUM('aktiv','potenziell','inaktiv') NOT NULL DEFAULT 'aktiv',
  `ansprechpartner`   VARCHAR(150)  NULL,
  `funktion`          VARCHAR(100)  NULL,
  `telefon`           VARCHAR(40)   NULL,
  `email`             VARCHAR(180)  NULL,
  `strasse`           VARCHAR(200)  NULL,
  `plz`               VARCHAR(10)   NULL,
  `ort`               VARCHAR(100)  NULL,
  `website`           VARCHAR(255)  NULL,
  `notizen`           TEXT          NULL,
  `letzter_kontakt`   DATE          NULL,
  `naechster_kontakt` DATE          NULL,
  `kooperation_id`    INT UNSIGNED  NULL COMMENT 'Verknüpfung zur bestehenden Gemeinde-Kooperation',
  `verantwortlich_id` INT UNSIGNED  NULL,
  `erstellt_von`      INT UNSIGNED  NULL,
  `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_partner_org` (`organization_id`, `kategorie`),
  INDEX `idx_partner_naechster` (`naechster_kontakt`),
  CONSTRAINT `fk_partner_verantwortlich` FOREIGN KEY (`verantwortlich_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_kontakte` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `partner_id`  INT UNSIGNED  NOT NULL,
  `datum`       DATE          NOT NULL,
  `art`         ENUM('telefon','email','treffen','sonstiges') NOT NULL DEFAULT 'telefon',
  `notiz`       TEXT          NULL,
  `user_id`     INT UNSIGNED  NULL,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_pk_partner` (`partner_id`, `datum`),
  CONSTRAINT `fk_pk_partner` FOREIGN KEY (`partner_id`) REFERENCES `partner_organisationen`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 2. Projekte
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `projekte` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED  NOT NULL DEFAULT 1,
  `name`            VARCHAR(200)  NOT NULL,
  `beschreibung`    TEXT          NULL,
  `kategorie`       VARCHAR(30)   NOT NULL DEFAULT 'sonstiges',
  `status`          ENUM('planung','aktiv','pausiert','abgeschlossen','archiviert') NOT NULL DEFAULT 'planung',
  `leitung_id`      INT UNSIGNED  NULL,
  `start_datum`     DATE          NULL,
  `end_datum`       DATE          NULL,
  `gemeinde`        VARCHAR(150)  NULL,
  `budget`          DECIMAL(10,2) NULL,
  `foerderung_id`   INT UNSIGNED  NULL COMMENT 'Standard-Förderung: Kosten werden diesem Förderbudget zugerechnet',
  `kooperation_id`  INT UNSIGNED  NULL,
  `farbe`           CHAR(7)       NULL,
  `erstellt_von`    INT UNSIGNED  NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_projekte_org` (`organization_id`, `status`),
  CONSTRAINT `fk_projekt_leitung` FOREIGN KEY (`leitung_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_projekt_foerderung` FOREIGN KEY (`foerderung_id`) REFERENCES `foerderungen`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `projekt_team` (
  `projekt_id`  INT UNSIGNED  NOT NULL,
  `user_id`     INT UNSIGNED  NOT NULL,
  `rolle`       VARCHAR(80)   NULL,
  PRIMARY KEY (`projekt_id`, `user_id`),
  CONSTRAINT `fk_pt_projekt` FOREIGN KEY (`projekt_id`) REFERENCES `projekte`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pt_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `projekt_partner` (
  `projekt_id`  INT UNSIGNED  NOT NULL,
  `partner_id`  INT UNSIGNED  NOT NULL,
  `rolle`       VARCHAR(80)   NULL,
  PRIMARY KEY (`projekt_id`, `partner_id`),
  CONSTRAINT `fk_pp_projekt` FOREIGN KEY (`projekt_id`) REFERENCES `projekte`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pp_partner` FOREIGN KEY (`partner_id`) REFERENCES `partner_organisationen`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 3. Kinder & Einwilligungen
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kinder` (
  `id`                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id`    INT UNSIGNED  NOT NULL DEFAULT 1,
  `elternteil_id`      INT UNSIGNED  NOT NULL,
  `vorname`            VARCHAR(100)  NOT NULL,
  `nachname`           VARCHAR(100)  NOT NULL,
  `geburtsdatum`       DATE          NULL,
  `telefon`            VARCHAR(40)   NULL,
  `notfall_name`       VARCHAR(150)  NULL,
  `notfall_telefon`    VARCHAR(40)   NULL,
  `notfall_beziehung`  VARCHAR(60)   NULL,
  `hinweise`           TEXT          NULL COMMENT 'Allergien, Medikamente, Besonderheiten',
  `aktiv`              TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_kinder_eltern` (`elternteil_id`),
  CONSTRAINT `fk_kind_eltern` FOREIGN KEY (`elternteil_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verlauf: jede Änderung ist ein neuer Datensatz (nachvollziehbar, nie überschrieben)
CREATE TABLE IF NOT EXISTS `einwilligungen` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED  NOT NULL DEFAULT 1,
  `kind_id`         INT UNSIGNED  NULL,
  `user_id`         INT UNSIGNED  NOT NULL COMMENT 'Person, für die bzw. von der die Einwilligung gilt',
  `typ`             ENUM('teilnahme','datenschutz','foto_video','notfallkontakt') NOT NULL,
  `erteilt`         TINYINT(1)    NOT NULL,
  `text_version`    VARCHAR(20)   NOT NULL DEFAULT '2026-09',
  `erfasst_von`     INT UNSIGNED  NULL,
  `ip_adresse`      VARCHAR(45)   NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_einw_kind` (`kind_id`, `typ`, `created_at`),
  INDEX `idx_einw_user` (`user_id`, `typ`, `created_at`),
  CONSTRAINT `fk_einw_kind` FOREIGN KEY (`kind_id`) REFERENCES `kinder`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_einw_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 4. Einsatzplanung: Serien, Einheiten, Trainer:innen, Anwesenheit
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `einheit_serien` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED  NOT NULL DEFAULT 1,
  `titel`           VARCHAR(200)  NOT NULL,
  `typ`             VARCHAR(20)   NOT NULL DEFAULT 'training',
  `projekt_id`      INT UNSIGNED  NULL,
  `kurs_id`         INT UNSIGNED  NULL,
  `ort`             VARCHAR(150)  NULL,
  `rhythmus`        ENUM('woechentlich','14taegig') NOT NULL DEFAULT 'woechentlich',
  `startzeit`       TIME          NOT NULL,
  `endzeit`         TIME          NOT NULL,
  `von`             DATE          NOT NULL,
  `bis`             DATE          NOT NULL,
  `erstellt_von`    INT UNSIGNED  NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_serien_org` (`organization_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `einheiten` (
  `id`                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id`       INT UNSIGNED  NOT NULL DEFAULT 1,
  `serie_id`              INT UNSIGNED  NULL,
  `typ`                   VARCHAR(20)   NOT NULL DEFAULT 'training' COMMENT 'kurs, training, kindergarten, schule, gemeinde, event, meeting, sonstiges',
  `titel`                 VARCHAR(200)  NOT NULL,
  `start`                 DATETIME      NOT NULL,
  `ende`                  DATETIME      NOT NULL,
  `ort`                   VARCHAR(150)  NULL,
  `projekt_id`            INT UNSIGNED  NULL,
  `kurs_id`               INT UNSIGNED  NULL,
  `status`                ENUM('geplant','durchgefuehrt','storniert') NOT NULL DEFAULT 'geplant',
  `erwartete_teilnehmer`  SMALLINT UNSIGNED NULL,
  `notiz`                 TEXT          NULL,
  `erstellt_von`          INT UNSIGNED  NULL,
  `created_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_einheiten_org_start` (`organization_id`, `start`),
  INDEX `idx_einheiten_projekt` (`projekt_id`),
  INDEX `idx_einheiten_kurs` (`kurs_id`),
  INDEX `idx_einheiten_serie` (`serie_id`),
  CONSTRAINT `fk_einheit_serie` FOREIGN KEY (`serie_id`) REFERENCES `einheit_serien`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_einheit_projekt` FOREIGN KEY (`projekt_id`) REFERENCES `projekte`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_einheit_kurs` FOREIGN KEY (`kurs_id`) REFERENCES `kurse`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `trainer_abrechnungen` (
  `id`                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id`     INT UNSIGNED  NOT NULL DEFAULT 1,
  `user_id`             INT UNSIGNED  NOT NULL,
  `jahr`                SMALLINT UNSIGNED NOT NULL,
  `monat`               TINYINT UNSIGNED NOT NULL,
  `anzahl_einheiten`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `minuten`             INT UNSIGNED  NOT NULL DEFAULT 0,
  `betrag`              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `auszahlungsart`      ENUM('honorar','prae') NOT NULL DEFAULT 'honorar',
  `status`              ENUM('entwurf','eingereicht','geprueft','freigegeben','bezahlt') NOT NULL DEFAULT 'entwurf',
  `eingereicht_am`      DATETIME      NULL,
  `geprueft_von`        INT UNSIGNED  NULL,
  `geprueft_am`         DATETIME      NULL,
  `freigegeben_von`     INT UNSIGNED  NULL,
  `freigegeben_am`      DATETIME      NULL,
  `bezahlt_am`          DATE          NULL,
  `prae_uebernommen_am` DATETIME      NULL,
  `notiz`               VARCHAR(500)  NULL,
  `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ta_monat` (`user_id`, `jahr`, `monat`),
  INDEX `idx_ta_org` (`organization_id`, `jahr`, `monat`, `status`),
  CONSTRAINT `fk_ta_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `einheit_trainer` (
  `id`                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `einheit_id`         INT UNSIGNED  NOT NULL,
  `user_id`            INT UNSIGNED  NOT NULL,
  `rolle`              ENUM('leitung','assistenz') NOT NULL DEFAULT 'leitung',
  `status`             ENUM('geplant','durchgefuehrt','storniert','zur_abrechnung','abgerechnet','bezahlt') NOT NULL DEFAULT 'geplant',
  `ist_start`          DATETIME      NULL,
  `ist_ende`           DATETIME      NULL,
  `dauer_min`          SMALLINT UNSIGNED NULL,
  `teilnehmer_anzahl`  SMALLINT UNSIGNED NULL,
  `notiz`              VARCHAR(500)  NULL,
  `honorar_modell`     ENUM('stunde','einheit') NULL,
  `honorar_satz`       DECIMAL(8,2)  NULL,
  `betrag`             DECIMAL(10,2) NULL,
  `abrechnung_id`      INT UNSIGNED  NULL,
  `bestaetigt_am`      DATETIME      NULL,
  `created_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_et_einheit_user` (`einheit_id`, `user_id`),
  INDEX `idx_et_user_status` (`user_id`, `status`),
  INDEX `idx_et_abrechnung` (`abrechnung_id`),
  CONSTRAINT `fk_et_einheit` FOREIGN KEY (`einheit_id`) REFERENCES `einheiten`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_et_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_et_abrechnung` FOREIGN KEY (`abrechnung_id`) REFERENCES `trainer_abrechnungen`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `anwesenheiten` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `einheit_id`      INT UNSIGNED  NOT NULL,
  `teilnehmer_key`  VARCHAR(40)   NOT NULL COMMENT 'u<user_id>, k<kind_id> oder g<Gast>',
  `user_id`         INT UNSIGNED  NULL,
  `kind_id`         INT UNSIGNED  NULL,
  `gast_name`       VARCHAR(150)  NULL,
  `status`          ENUM('anwesend','abwesend','entschuldigt','probetraining') NOT NULL,
  `erfasst_von`     INT UNSIGNED  NULL,
  `erfasst_am`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_anw_teilnehmer` (`einheit_id`, `teilnehmer_key`),
  INDEX `idx_anw_user` (`user_id`),
  INDEX `idx_anw_kind` (`kind_id`),
  CONSTRAINT `fk_anw_einheit` FOREIGN KEY (`einheit_id`) REFERENCES `einheiten`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_anw_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_anw_kind` FOREIGN KEY (`kind_id`) REFERENCES `kinder`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Honorar: spezifischster Treffer gewinnt (Trainer+Kurs > Trainer+Projekt > Trainer > Kurs > Projekt > Standard)
CREATE TABLE IF NOT EXISTS `honorar_saetze` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED  NOT NULL DEFAULT 1,
  `user_id`         INT UNSIGNED  NULL,
  `projekt_id`      INT UNSIGNED  NULL,
  `kurs_id`         INT UNSIGNED  NULL,
  `modell`          ENUM('stunde','einheit') NOT NULL DEFAULT 'einheit',
  `betrag`          DECIMAL(8,2)  NOT NULL,
  `gueltig_ab`      DATE          NULL,
  `gueltig_bis`     DATE          NULL,
  `notiz`           VARCHAR(255)  NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_hs_org` (`organization_id`, `user_id`),
  CONSTRAINT `fk_hs_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hs_projekt` FOREIGN KEY (`projekt_id`) REFERENCES `projekte`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hs_kurs` FOREIGN KEY (`kurs_id`) REFERENCES `kurse`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 5. Finanzen: Buchungen (Einnahmen/Ausgaben mit Zuordnung)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `buchungen` (
  `id`                     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id`        INT UNSIGNED  NOT NULL DEFAULT 1,
  `datum`                  DATE          NOT NULL,
  `art`                    ENUM('einnahme','ausgabe') NOT NULL,
  `betrag`                 DECIMAL(10,2) NOT NULL,
  `kategorie`              VARCHAR(40)   NOT NULL DEFAULT 'sonstiges',
  `beschreibung`           VARCHAR(255)  NOT NULL,
  `projekt_id`             INT UNSIGNED  NULL,
  `kurs_id`                INT UNSIGNED  NULL,
  `foerderung_id`          INT UNSIGNED  NULL,
  `trainer_abrechnung_id`  INT UNSIGNED  NULL COMMENT 'Automatisch aus Trainerabrechnung',
  `belegnummer`            VARCHAR(60)   NULL,
  `status`                 ENUM('offen','bezahlt') NOT NULL DEFAULT 'bezahlt',
  `erstellt_von`           INT UNSIGNED  NULL,
  `created_at`             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_buch_org_datum` (`organization_id`, `datum`),
  INDEX `idx_buch_projekt` (`projekt_id`),
  INDEX `idx_buch_foerderung` (`foerderung_id`),
  INDEX `idx_buch_ta` (`trainer_abrechnung_id`),
  CONSTRAINT `fk_buch_projekt` FOREIGN KEY (`projekt_id`) REFERENCES `projekte`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_buch_kurs` FOREIGN KEY (`kurs_id`) REFERENCES `kurse`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_buch_foerderung` FOREIGN KEY (`foerderung_id`) REFERENCES `foerderungen`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_buch_ta` FOREIGN KEY (`trainer_abrechnung_id`) REFERENCES `trainer_abrechnungen`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 6. Aufgaben, Qualifikationen, Ressourcen, Verträge
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `aufgaben` (
  `id`                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id`    INT UNSIGNED  NOT NULL DEFAULT 1,
  `projekt_id`         INT UNSIGNED  NULL,
  `titel`              VARCHAR(200)  NOT NULL,
  `beschreibung`       TEXT          NULL,
  `verantwortlich_id`  INT UNSIGNED  NULL,
  `prioritaet`         ENUM('niedrig','normal','hoch','kritisch') NOT NULL DEFAULT 'normal',
  `deadline`           DATE          NULL,
  `status`             ENUM('offen','in_bearbeitung','wartet','erledigt') NOT NULL DEFAULT 'offen',
  `erledigt_am`        DATETIME      NULL,
  `erstellt_von`       INT UNSIGNED  NULL,
  `created_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_aufg_org_status` (`organization_id`, `status`, `deadline`),
  INDEX `idx_aufg_verantw` (`verantwortlich_id`, `status`),
  CONSTRAINT `fk_aufg_projekt` FOREIGN KEY (`projekt_id`) REFERENCES `projekte`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aufg_verantw` FOREIGN KEY (`verantwortlich_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `trainer_qualifikationen` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED  NOT NULL DEFAULT 1,
  `user_id`         INT UNSIGNED  NOT NULL,
  `typ`             VARCHAR(40)   NOT NULL DEFAULT 'sonstiges',
  `bezeichnung`     VARCHAR(200)  NOT NULL,
  `institution`     VARCHAR(200)  NULL,
  `ausgestellt_am`  DATE          NULL,
  `gueltig_bis`     DATE          NULL COMMENT 'NULL = unbefristet',
  `notiz`           VARCHAR(500)  NULL,
  `geprueft_von`    INT UNSIGNED  NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_qual_user` (`user_id`),
  INDEX `idx_qual_ablauf` (`organization_id`, `gueltig_bis`),
  CONSTRAINT `fk_qual_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ressourcen` (
  `id`                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id`    INT UNSIGNED  NOT NULL DEFAULT 1,
  `name`               VARCHAR(150)  NOT NULL,
  `kategorie`          VARCHAR(30)   NOT NULL DEFAULT 'material',
  `anzahl`             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `zustand`            ENUM('neu','gut','gebraucht','reparatur','defekt') NOT NULL DEFAULT 'gut',
  `standort`           VARCHAR(150)  NULL,
  `verantwortlich_id`  INT UNSIGNED  NULL,
  `verfuegbar`         TINYINT(1)    NOT NULL DEFAULT 1,
  `notiz`              TEXT          NULL,
  `created_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_res_org` (`organization_id`, `kategorie`),
  CONSTRAINT `fk_res_verantw` FOREIGN KEY (`verantwortlich_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ressourcen_buchungen` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `ressource_id`  INT UNSIGNED  NOT NULL,
  `einheit_id`    INT UNSIGNED  NULL,
  `kurs_id`       INT UNSIGNED  NULL,
  `start`         DATETIME      NOT NULL,
  `ende`          DATETIME      NOT NULL,
  `menge`         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `notiz`         VARCHAR(255)  NULL,
  `erstellt_von`  INT UNSIGNED  NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_rb_res_zeit` (`ressource_id`, `start`, `ende`),
  INDEX `idx_rb_einheit` (`einheit_id`),
  CONSTRAINT `fk_rb_res` FOREIGN KEY (`ressource_id`) REFERENCES `ressourcen`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rb_einheit` FOREIGN KEY (`einheit_id`) REFERENCES `einheiten`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rb_kurs` FOREIGN KEY (`kurs_id`) REFERENCES `kurse`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vertraege` (
  `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id`   INT UNSIGNED  NOT NULL DEFAULT 1,
  `titel`             VARCHAR(200)  NOT NULL,
  `vertragsart`       VARCHAR(40)   NOT NULL DEFAULT 'sonstiges',
  `partner_id`        INT UNSIGNED  NULL,
  `user_id`           INT UNSIGNED  NULL COMMENT 'Vertrag mit Trainer:in/Mitglied',
  `projekt_id`        INT UNSIGNED  NULL,
  `beginn`            DATE          NULL,
  `ende`              DATE          NULL COMMENT 'NULL = unbefristet',
  `kuendigungsfrist`  VARCHAR(100)  NULL COMMENT 'z.B. 3 Monate zum Quartalsende',
  `kuendigung_bis`    DATE          NULL COMMENT 'Letzter Tag für eine fristgerechte Kündigung',
  `wert`              DECIMAL(10,2) NULL,
  `status`            ENUM('entwurf','aktiv','gekuendigt','beendet') NOT NULL DEFAULT 'aktiv',
  `notiz`             TEXT          NULL,
  `erstellt_von`      INT UNSIGNED  NULL,
  `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_vertr_org` (`organization_id`, `status`, `ende`),
  CONSTRAINT `fk_vertr_partner` FOREIGN KEY (`partner_id`) REFERENCES `partner_organisationen`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_vertr_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_vertr_projekt` FOREIGN KEY (`projekt_id`) REFERENCES `projekte`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 7. Benachrichtigungen, Audit-Log, Systemstatus
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `benachrichtigungen` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED  NOT NULL DEFAULT 1,
  `user_id`         INT UNSIGNED  NOT NULL,
  `typ`             VARCHAR(40)   NOT NULL,
  `titel`           VARCHAR(200)  NOT NULL,
  `text`            VARCHAR(500)  NULL,
  `link`            VARCHAR(255)  NULL,
  `schluessel`      VARCHAR(120)  NULL COMMENT 'Verhindert doppelte Erinnerungen',
  `gelesen_am`      DATETIME      NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_benachr_schluessel` (`user_id`, `schluessel`),
  INDEX `idx_benachr_user` (`user_id`, `gelesen_am`, `created_at`),
  CONSTRAINT `fk_benachr_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED  NOT NULL DEFAULT 1,
  `user_id`         INT UNSIGNED  NULL,
  `aktion`          VARCHAR(20)   NOT NULL COMMENT 'erstellt, geaendert, geloescht, status',
  `tabelle`         VARCHAR(60)   NOT NULL,
  `datensatz_id`    INT UNSIGNED  NULL,
  `beschreibung`    VARCHAR(255)  NULL,
  `alt`             MEDIUMTEXT    NULL COMMENT 'JSON der geänderten Felder vorher',
  `neu`             MEDIUMTEXT    NULL COMMENT 'JSON der geänderten Felder nachher',
  `ip_adresse`      VARCHAR(45)   NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_audit_org_zeit` (`organization_id`, `created_at`),
  INDEX `idx_audit_datensatz` (`tabelle`, `datensatz_id`),
  INDEX `idx_audit_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `system_status` (
  `schluessel`  VARCHAR(80)   NOT NULL,
  `wert`        VARCHAR(255)  NULL,
  `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`schluessel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 8. Erweiterungen bestehender Tabellen (nur hinzufügen)
-- ----------------------------------------------------------------
ALTER TABLE `kurse`
  ADD COLUMN IF NOT EXISTS `anmeldeschluss`  DATE              NULL,
  ADD COLUMN IF NOT EXISTS `min_alter`       TINYINT UNSIGNED  NULL,
  ADD COLUMN IF NOT EXISTS `max_alter`       TINYINT UNSIGNED  NULL,
  ADD COLUMN IF NOT EXISTS `voraussetzungen` TEXT              NULL,
  ADD COLUMN IF NOT EXISTS `projekt_id`      INT UNSIGNED      NULL,
  ADD INDEX IF NOT EXISTS `idx_kurse_projekt` (`projekt_id`);

-- Kinder anmelden: kind_id = 0 bedeutet „die angemeldete Person selbst“ (bisheriges Verhalten)
ALTER TABLE `kurs_anmeldungen`
  ADD COLUMN IF NOT EXISTS `kind_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `user_id`,
  ADD UNIQUE INDEX IF NOT EXISTS `uk_kurs_user_kind` (`kurs_id`, `user_id`, `kind_id`);
ALTER TABLE `kurs_anmeldungen` DROP INDEX IF EXISTS `uk_kurs_user`;

-- Förderungen: Programm, Projekt, Fristen; neue Status zusätzlich zu den bisherigen
ALTER TABLE `foerderungen`
  ADD COLUMN IF NOT EXISTS `foerderprogramm`   VARCHAR(200)  NULL AFTER `foerderstelle`,
  ADD COLUMN IF NOT EXISTS `projekt_id`        INT UNSIGNED  NULL,
  ADD COLUMN IF NOT EXISTS `abrechnungsfrist`  DATE          NULL,
  ADD COLUMN IF NOT EXISTS `betrag_ausbezahlt` DECIMAL(10,2) NULL,
  ADD INDEX IF NOT EXISTS `idx_foerderungen_projekt` (`projekt_id`),
  MODIFY COLUMN `status` ENUM('geplant','beantragt','bewilligt','abgelehnt','ausbezahlt','abgeschlossen',
                              'vorbereitung','eingereicht','in_pruefung','in_umsetzung','abgerechnet') NOT NULL DEFAULT 'geplant';

-- Dokumente: weitere Zuordnungen und Kategorien
ALTER TABLE `dokumente`
  MODIFY COLUMN `kategorie` ENUM('vereinsdokument','trainingsplan','kursinformation','protokoll','foerderansuchen','foerderbescheid',
                                 'verwendungsnachweis','kooperationsvereinbarung','rueckmeldeblatt','rechnung','anwesenheitsliste',
                                 'dokumentation','sonstiges','vertrag','qualifikation','beleg','projekt') NOT NULL DEFAULT 'sonstiges',
  ADD COLUMN IF NOT EXISTS `projekt_id`        INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `partner_id`        INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `kurs_id`           INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `vertrag_id`        INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `qualifikation_id`  INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `buchung_id`        INT UNSIGNED NULL,
  ADD INDEX IF NOT EXISTS `idx_dok_projekt` (`projekt_id`),
  ADD INDEX IF NOT EXISTS `idx_dok_partner` (`partner_id`),
  ADD INDEX IF NOT EXISTS `idx_dok_vertrag` (`vertrag_id`),
  ADD INDEX IF NOT EXISTS `idx_dok_qualifikation` (`qualifikation_id`),
  ADD INDEX IF NOT EXISTS `idx_dok_buchung` (`buchung_id`);

-- ----------------------------------------------------------------
-- 9. Berechtigungen (RBAC) für die neuen Module
-- ----------------------------------------------------------------
UPDATE `roles` SET `name` = 'Präsidium / Management' WHERE `code` = 'MANAGEMENT';
UPDATE `roles` SET `name` = 'Mitarbeiter:in / Verwaltung' WHERE `code` = 'ADMINISTRATION';

INSERT IGNORE INTO `permissions` (`code`, `beschreibung`) VALUES
  ('kalender.anzeigen',        'Alle Einheiten und Termine sehen'),
  ('kalender.erstellen',       'Einheiten/Serien planen und Trainer:innen einteilen'),
  ('kalender.bearbeiten',      'Einheiten ändern und absagen'),
  ('kalender.loeschen',        'Einheiten löschen'),
  ('anwesenheit.bearbeiten',   'Anwesenheit aller Einheiten erfassen'),
  ('abrechnung.anzeigen',      'Alle Trainerabrechnungen sehen'),
  ('abrechnung.bearbeiten',    'Trainerabrechnungen prüfen und Honorarsätze pflegen'),
  ('abrechnung.freigeben',     'Trainerabrechnungen freigeben'),
  ('abrechnung.abrechnen',     'Trainerabrechnungen als bezahlt markieren'),
  ('projekte.anzeigen',        'Alle Projekte sehen'),
  ('projekte.erstellen',       'Projekte anlegen'),
  ('projekte.bearbeiten',      'Projekte bearbeiten (inkl. Team, Finanzen)'),
  ('projekte.loeschen',        'Projekte löschen/archivieren'),
  ('aufgaben.anzeigen',        'Alle Aufgaben sehen'),
  ('aufgaben.erstellen',       'Aufgaben anlegen und zuweisen'),
  ('aufgaben.bearbeiten',      'Alle Aufgaben bearbeiten'),
  ('aufgaben.loeschen',        'Aufgaben löschen'),
  ('qualifikationen.anzeigen', 'Qualifikationen aller Trainer:innen sehen'),
  ('qualifikationen.bearbeiten','Qualifikationen aller Trainer:innen pflegen'),
  ('kinder.anzeigen',          'Kinder- und Notfalldaten aller Teilnehmenden sehen'),
  ('kinder.bearbeiten',        'Kinderdaten und Einwilligungen verwalten'),
  ('partner.anzeigen',         'Partner/CRM sehen'),
  ('partner.erstellen',        'Partner anlegen'),
  ('partner.bearbeiten',       'Partner bearbeiten'),
  ('partner.loeschen',         'Partner löschen'),
  ('ressourcen.anzeigen',      'Ressourcen sehen'),
  ('ressourcen.erstellen',     'Ressourcen anlegen und buchen'),
  ('ressourcen.bearbeiten',    'Ressourcen bearbeiten'),
  ('ressourcen.loeschen',      'Ressourcen löschen'),
  ('vertraege.anzeigen',       'Verträge sehen'),
  ('vertraege.erstellen',      'Verträge anlegen'),
  ('vertraege.bearbeiten',     'Verträge bearbeiten'),
  ('vertraege.loeschen',       'Verträge löschen'),
  ('foerderungen.anzeigen',    'Förderungen sehen'),
  ('foerderungen.bearbeiten',  'Förderungen und Belege bearbeiten'),
  ('finanzen.anzeigen',        'Finanzübersicht sehen'),
  ('finanzen.bearbeiten',      'Buchungen erfassen'),
  ('rollen.bearbeiten',        'Rollen und Berechtigungen verwalten'),
  ('audit.anzeigen',           'Änderungsprotokoll einsehen');

-- Organisations-/Super-Admin: alle Berechtigungen (auch die neuen)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.code IN ('ORGANIZATION_ADMIN', 'SUPER_ADMIN');

-- Präsidium: alles sehen, freigeben, Aufgaben und Projekte steuern
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r, `permissions` p
WHERE r.code = 'MANAGEMENT' AND (p.code LIKE '%.anzeigen' OR p.code IN ('abrechnung.freigeben','projekte.erstellen','projekte.bearbeiten',
      'aufgaben.erstellen','aufgaben.bearbeiten','foerderungen.bearbeiten','finanzen.bearbeiten'));

-- Projektleitung
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r, `permissions` p
WHERE r.code = 'PROJECT_MANAGER' AND p.code IN ('kalender.anzeigen','kalender.erstellen','kalender.bearbeiten','anwesenheit.bearbeiten',
      'projekte.anzeigen','projekte.erstellen','projekte.bearbeiten','aufgaben.anzeigen','aufgaben.erstellen','aufgaben.bearbeiten',
      'partner.anzeigen','partner.erstellen','partner.bearbeiten','ressourcen.anzeigen','ressourcen.erstellen','ressourcen.bearbeiten',
      'qualifikationen.anzeigen','kinder.anzeigen','foerderungen.anzeigen','finanzen.anzeigen','vertraege.anzeigen');

-- Mitarbeiter:in / Verwaltung
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r, `permissions` p
WHERE r.code = 'ADMINISTRATION' AND p.code IN ('kalender.anzeigen','kalender.erstellen','kalender.bearbeiten','anwesenheit.bearbeiten',
      'abrechnung.anzeigen','abrechnung.bearbeiten','aufgaben.anzeigen','aufgaben.erstellen','partner.anzeigen','partner.erstellen',
      'partner.bearbeiten','ressourcen.anzeigen','ressourcen.erstellen','ressourcen.bearbeiten','vertraege.anzeigen','vertraege.erstellen',
      'vertraege.bearbeiten','kinder.anzeigen','kinder.bearbeiten','qualifikationen.anzeigen','qualifikationen.bearbeiten',
      'foerderungen.anzeigen','finanzen.anzeigen');

-- Trainer:innen: Überblick; eigene Daten regelt die Anwendung ohne Zusatzrecht
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r, `permissions` p
WHERE r.code = 'TRAINER' AND p.code IN ('kalender.anzeigen','ressourcen.anzeigen');

INSERT IGNORE INTO `migrations` (`filename`) VALUES ('011_vereinsplattform.sql');
