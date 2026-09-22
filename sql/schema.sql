-- ============================================================
--  Athletikclub Steiermark – Datenbankschema
--  Kompatibel mit MySQL / MariaDB (Hetzner Webhosting L)
-- ============================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;
SET collation_connection = 'utf8mb4_unicode_ci';

-- ============================================================
-- Benutzer
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id`               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `vorname`          VARCHAR(80)      NOT NULL,
  `nachname`         VARCHAR(80)      NOT NULL,
  `email`            VARCHAR(180)     NOT NULL UNIQUE,
  `passwort_hash`    VARCHAR(255)     NOT NULL,
  `rolle`            ENUM('admin','trainer','mitglied') NOT NULL DEFAULT 'mitglied',
  `email_verified`   TINYINT(1)       NOT NULL DEFAULT 0,
  `verify_token`     VARCHAR(64)      NULL,
  `reset_token`      VARCHAR(64)      NULL,
  `reset_token_exp`  DATETIME         NULL,
  `aktiv`            TINYINT(1)       NOT NULL DEFAULT 1,
  `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_email` (`email`),
  INDEX `idx_rolle` (`rolle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Mitglieder-Profil (1:1 zu users)
-- ============================================================
CREATE TABLE IF NOT EXISTS `mitglieder_profile` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED  NOT NULL,
  `geburtsdatum`    DATE          NULL,
  `telefon`         VARCHAR(30)   NULL,
  `strasse`         VARCHAR(120)  NULL,
  `plz`             VARCHAR(10)   NULL,
  `ort`             VARCHAR(80)   NULL,
  `land`            VARCHAR(60)   NULL DEFAULT 'Österreich',
  `sportarten`      VARCHAR(500)  NULL COMMENT 'Komma-getrennte Liste',
  `mitglied_seit`   DATE          NULL,
  `mitgliedsstatus` ENUM('aktiv','inaktiv','ausstehend') NOT NULL DEFAULT 'ausstehend',
  `notizen`         TEXT          NULL COMMENT 'Interne Notizen (Admin/Trainer)',
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_id` (`user_id`),
  CONSTRAINT `fk_mitglied_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Trainer-Profil (1:1 zu users mit Rolle = trainer/admin)
-- ============================================================
CREATE TABLE IF NOT EXISTS `trainer_profile` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`          INT UNSIGNED  NOT NULL,
  `qualifikationen`  TEXT          NULL,
  `bio`              TEXT          NULL,
  `sportarten`       VARCHAR(500)  NULL COMMENT 'Komma-getrennte Liste',
  `foto_path`        VARCHAR(255)  NULL,
  `lizenz_nr`        VARCHAR(80)   NULL,
  `erstellt_von`     INT UNSIGNED  NULL COMMENT 'Admin der den Trainer ernannt hat',
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_trainer_user_id` (`user_id`),
  CONSTRAINT `fk_trainer_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_trainer_erstellt_von` FOREIGN KEY (`erstellt_von`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Kurse / Trainingseinheiten
-- ============================================================
CREATE TABLE IF NOT EXISTS `kurse` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `titel`            VARCHAR(200)  NOT NULL,
  `beschreibung`     TEXT          NULL,
  `trainer_id`       INT UNSIGNED  NULL,
  `sportart`         VARCHAR(80)   NULL,
  `ort`              VARCHAR(150)  NULL,
  `start_datum`      DATETIME      NOT NULL,
  `end_datum`        DATETIME      NOT NULL,
  `max_teilnehmer`   SMALLINT      NULL COMMENT 'NULL = unlimitiert',
  `preis`            DECIMAL(8,2)  NULL DEFAULT 0.00,
  `status`           ENUM('geplant','aktiv','abgesagt','abgeschlossen') NOT NULL DEFAULT 'geplant',
  `bild_path`        VARCHAR(255)  NULL,
  `erstellt_von`     INT UNSIGNED  NOT NULL,
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_trainer_id` (`trainer_id`),
  INDEX `idx_status` (`status`),
  CONSTRAINT `fk_kurs_trainer` FOREIGN KEY (`trainer_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_kurs_erstellt_von` FOREIGN KEY (`erstellt_von`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Kursanmeldungen
-- ============================================================
CREATE TABLE IF NOT EXISTS `kurs_anmeldungen` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `kurs_id`        INT UNSIGNED  NOT NULL,
  `user_id`        INT UNSIGNED  NOT NULL,
  `status`         ENUM('angemeldet','warteliste','storniert','teilgenommen') NOT NULL DEFAULT 'angemeldet',
  `bezahlt`        TINYINT(1)    NOT NULL DEFAULT 0,
  `bezahlt_am`     DATETIME      NULL,
  `angemeldet_am`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notiz`          VARCHAR(500)  NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_kurs_user` (`kurs_id`, `user_id`),
  CONSTRAINT `fk_anmeldung_kurs` FOREIGN KEY (`kurs_id`) REFERENCES `kurse`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_anmeldung_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Umsatz (manuelle Einträge, z.B. Einzeltraining außerhalb des Kurssystems)
-- ============================================================
CREATE TABLE IF NOT EXISTS `umsatz_eintraege` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `trainer_id`      INT UNSIGNED  NOT NULL,
  `beschreibung`    VARCHAR(255)  NOT NULL,
  `betrag`          DECIMAL(10,2) NOT NULL,
  `leistungsdatum`  DATE          NOT NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_trainer_id` (`trainer_id`),
  CONSTRAINT `fk_umsatz_trainer` FOREIGN KEY (`trainer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Dokumente / PDFs
-- ============================================================
CREATE TABLE IF NOT EXISTS `dokumente` (
  `id`               INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `titel`            VARCHAR(200)   NOT NULL,
  `beschreibung`     VARCHAR(500)   NULL,
  `datei_name`       VARCHAR(255)   NOT NULL COMMENT 'Originaldateiname',
  `datei_pfad`       VARCHAR(500)   NOT NULL COMMENT 'Pfad im uploads/pdfs/ Ordner',
  `datei_groesse`    INT UNSIGNED   NOT NULL COMMENT 'Größe in Bytes',
  `mime_type`        VARCHAR(80)    NOT NULL DEFAULT 'application/pdf',
  `kategorie`        ENUM('vereinsdokument','trainingsplan','kursinformation','protokoll','sonstiges') NOT NULL DEFAULT 'sonstiges',
  `sichtbar_fuer`    ENUM('alle','mitglieder','trainer','admin') NOT NULL DEFAULT 'mitglieder',
  `hochgeladen_von`  INT UNSIGNED   NOT NULL,
  `mitglied_id`      INT UNSIGNED   NULL COMMENT 'Falls gesetzt: Dokument ist Teil des Dokumentenarchivs dieses Mitglieds',
  `downloads`        INT UNSIGNED   NOT NULL DEFAULT 0,
  `created_at`       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_kategorie` (`kategorie`),
  INDEX `idx_sichtbar_fuer` (`sichtbar_fuer`),
  INDEX `idx_mitglied_id` (`mitglied_id`),
  CONSTRAINT `fk_dokument_user` FOREIGN KEY (`hochgeladen_von`) REFERENCES `users`(`id`),
  CONSTRAINT `fk_dokument_mitglied` FOREIGN KEY (`mitglied_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Fortschrittseinträge (Trainer dokumentieren Mitglieder-Fortschritt)
-- ============================================================
CREATE TABLE IF NOT EXISTS `fortschritt_eintraege` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED  NOT NULL COMMENT 'Mitglied, auf das sich der Eintrag bezieht',
  `trainer_id`  INT UNSIGNED  NOT NULL COMMENT 'Autor des Eintrags',
  `eintrag`     TEXT          NOT NULL,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_user_id` (`user_id`),
  CONSTRAINT `fk_fortschritt_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fortschritt_trainer` FOREIGN KEY (`trainer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Kontaktanfragen
-- ============================================================
CREATE TABLE IF NOT EXISTS `kontakt_anfragen` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(120)  NOT NULL,
  `email`       VARCHAR(180)  NOT NULL,
  `betreff`     VARCHAR(200)  NULL,
  `nachricht`   TEXT          NOT NULL,
  `gelesen`     TINYINT(1)    NOT NULL DEFAULT 0,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Partner
-- ============================================================
CREATE TABLE IF NOT EXISTS `partner` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(150)  NOT NULL,
  `logo_path`    VARCHAR(255)  NULL,
  `website_url`  VARCHAR(300)  NULL,
  `beschreibung` TEXT          NULL,
  `sortierung`   SMALLINT      NOT NULL DEFAULT 0,
  `aktiv`        TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- CMS – Seiteninhalte
-- ============================================================
CREATE TABLE IF NOT EXISTS `seiten_inhalte` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `seiten_slug`      VARCHAR(80)   NOT NULL UNIQUE,
  `titel`            VARCHAR(200)  NOT NULL,
  `untertitel`       VARCHAR(300)  NULL,
  `inhalt`           LONGTEXT      NOT NULL,
  `meta_description` VARCHAR(300)  NULL,
  `aktualisiert_am`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `aktualisiert_von` INT UNSIGNED  NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`seiten_slug`),
  CONSTRAINT `fk_seite_user` FOREIGN KEY (`aktualisiert_von`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Aktivitätslog (Admin-Audit-Trail)
-- ============================================================
CREATE TABLE IF NOT EXISTS `aktivitaets_log` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED  NULL,
  `aktion`      VARCHAR(100)  NOT NULL,
  `details`     VARCHAR(500)  NULL,
  `ip_adresse`  VARCHAR(45)   NULL,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_user_id` (`user_id`),
  CONSTRAINT `fk_log_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Initiale Daten: Standard-Admin-Account
-- Passwort: AdminACI2025! (muss nach Erstinstallation geändert werden!)
-- ============================================================
INSERT IGNORE INTO `users` (`vorname`, `nachname`, `email`, `passwort_hash`, `rolle`, `email_verified`, `aktiv`)
VALUES (
  'Admin',
  'ACI',
  'admin@athletikclub-steiermark.at',
  '$2y$12$8K9XLvQ.6TqZmKjN3a7lce5DhQzYpWuT1UrRnMO0f8sZPdAHgRiuC',
  'admin',
  1,
  1
);

-- Initiale CMS-Seiten
INSERT IGNORE INTO `seiten_inhalte` (`seiten_slug`, `titel`, `untertitel`, `inhalt`, `meta_description`) VALUES
('vision',   'Unsere Vision',   'Der Athletikclub für alle',    '<p>Unsere Vision ist es, einen inklusiven Sportverein zu schaffen...</p>',  'Die Vision des Athletikclubs Steiermark.'),
('mission',  'Unsere Mission',  'Was uns antreibt',             '<p>Wir fördern ganzheitliches Training für alle Leistungsniveaus...</p>',   'Die Mission des ACI Steiermark.'),
('leitbild', 'Unser Leitbild',  'Werte & Grundsätze',          '<p>Unsere Werte: Gemeinschaft, Leistung, Respekt, Gesundheit...</p>',       'Das Leitbild des Athletikclubs für Individualsportarten.');
