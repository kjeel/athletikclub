-- ============================================================
--  Migration 003: Gemeinde-Kooperationen (Bewegungsland Steiermark)
--  Athletikclub Steiermark
--
--  Was diese Migration macht:
--  1. kooperationen-Tabelle: ein Case pro Gemeinde-Kooperation
--     (Stammdaten Verein/Gemeinde/Dachverband aus der offiziellen
--     Kooperationsvereinbarung, läuft über mehrere Jahre)
--  2. kooperations_perioden-Tabelle: ein Jahreszyklus (z.B. 2026/27)
--     innerhalb einer Kooperation, mit Rückmeldeblatt- und
--     Rechnungsdaten
--  3. kooperations_angebote-Tabelle: die geplanten Bewegungsangebote
--     einer Periode (Rückmeldeblatt-Zeilen)
--  4. Erweitert die bestehende dokumente-Tabelle um kooperation_id,
--     damit unterschriebene Vereinbarungen, Rückmeldeblätter,
--     Rechnungen und Anwesenheitslisten im selben Dokumentenarchiv-
--     System wie Mitglieder-/Förderdokumente abgelegt werden können
--
--  Sicher erneut ausführbar (IF NOT EXISTS), bricht beim zweiten
--  Lauf bei den ALTER-Statements ab, das ist gewollt.
-- ============================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------
-- 1. Kooperationen (ein Case pro Gemeinde)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kooperationen` (
  `id`                            INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `organization_id`               INT UNSIGNED   NOT NULL DEFAULT 1,
  `gemeinde_name`                 VARCHAR(150)   NOT NULL,
  `gemeinde_adresse`               VARCHAR(255)   NULL,
  `buergermeister`                VARCHAR(150)   NULL,
  `gemeinde_ansprechpartner_name`  VARCHAR(150)   NULL,
  `gemeinde_ansprechpartner_email` VARCHAR(180)   NULL,
  `gemeinde_ansprechpartner_tel`   VARCHAR(30)    NULL,
  `verein_ansprechpartner_name`   VARCHAR(150)   NULL,
  `verein_ansprechpartner_email`  VARCHAR(180)   NULL,
  `verein_ansprechpartner_tel`    VARCHAR(30)    NULL,
  `dachverband`                   ENUM('ASKÖ','ASVÖ','SPORTUNION') NOT NULL DEFAULT 'SPORTUNION',
  `dachverband_ansprechpartner`   VARCHAR(255)   NULL DEFAULT 'Aurora Nussbaumer und Sabine Zenz, bewegungsland@sportunion-steiermark.at',
  `kooperationsbeginn`            DATE           NULL COMMENT 'Monat/Jahr, gespeichert als 1. des Monats',
  `status`                        ENUM('entwurf','vereinbarung_erstellt','unterschrift_ausstehend','aktiv','beendet') NOT NULL DEFAULT 'entwurf',
  `notizen`                       TEXT           NULL,
  `erstellt_von`                  INT UNSIGNED   NOT NULL,
  `created_at`                    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_kooperationen_org` (`organization_id`),
  INDEX `idx_kooperationen_status` (`status`),
  CONSTRAINT `fk_kooperation_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`),
  CONSTRAINT `fk_kooperation_ersteller` FOREIGN KEY (`erstellt_von`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 2. Kooperationsperioden (ein Jahr, z.B. "2026/27")
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kooperations_perioden` (
  `id`                      INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `kooperation_id`          INT UNSIGNED   NOT NULL,
  `organization_id`         INT UNSIGNED   NOT NULL DEFAULT 1,
  `bezeichnung`             VARCHAR(20)    NOT NULL COMMENT 'z.B. 2026/27',
  `zeitraum_von`            DATE           NOT NULL,
  `zeitraum_bis`            DATE           NOT NULL,
  `rueckmeldeblatt_status`  ENUM('offen','eingereicht','freigegeben') NOT NULL DEFAULT 'offen',
  `zugesagte_angebote`      VARCHAR(255)   NULL COMMENT 'Vom Dachverband ausgefüllt',
  `zugesagte_einheiten`     VARCHAR(100)   NULL,
  `zugesagtes_budget`       DECIMAL(10,2)  NULL,
  `rechnung_nr`             VARCHAR(50)    NULL,
  `rechnung_datum`          DATE           NULL,
  `rechnung_trainer_4h`     SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Anzahl Trainer-Einsätze à 4 Stunden (90€)',
  `rechnung_trainer_56h`    SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Anzahl Trainer-Einsätze à 5/6 Stunden (120€)',
  `rechnung_betrag`         DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  `rechnung_kontoname`      VARCHAR(150)   NULL,
  `rechnung_iban`           VARCHAR(50)    NULL,
  `rechnung_bic`            VARCHAR(20)    NULL,
  `rechnung_bank`           VARCHAR(150)   NULL,
  `rechnung_status`         ENUM('offen','erstellt','eingereicht','bezahlt') NOT NULL DEFAULT 'offen',
  `created_at`              DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_perioden_kooperation` (`kooperation_id`),
  INDEX `idx_perioden_org` (`organization_id`),
  CONSTRAINT `fk_periode_kooperation` FOREIGN KEY (`kooperation_id`) REFERENCES `kooperationen`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_periode_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 3. Geplante Angebote je Periode (Rückmeldeblatt-Zeilen)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kooperations_angebote` (
  `id`                   INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `periode_id`           INT UNSIGNED   NOT NULL,
  `angebotsname`         VARCHAR(150)   NOT NULL,
  `zielgruppe`           VARCHAR(150)   NULL,
  `zeitraum_von`         VARCHAR(20)    NULL,
  `zeitraum_bis`         VARCHAR(20)    NULL,
  `gruppenanzahl`        SMALLINT UNSIGNED NULL,
  `einheiten_pro_gruppe` SMALLINT UNSIGNED NULL,
  `sortierung`           SMALLINT       NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  INDEX `idx_angebote_periode` (`periode_id`),
  CONSTRAINT `fk_angebot_periode` FOREIGN KEY (`periode_id`) REFERENCES `kooperations_perioden`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 4. Dokumentenarchiv um Kooperationen erweitern
-- ----------------------------------------------------------------
ALTER TABLE `dokumente`
  MODIFY COLUMN `kategorie` ENUM('vereinsdokument','trainingsplan','kursinformation','protokoll','foerderansuchen','foerderbescheid','verwendungsnachweis','kooperationsvereinbarung','rueckmeldeblatt','rechnung','anwesenheitsliste','dokumentation','sonstiges') NOT NULL DEFAULT 'sonstiges',
  ADD COLUMN `kooperation_id` INT UNSIGNED NULL COMMENT 'Falls gesetzt: Dokument gehört zum Ordner dieser Gemeinde-Kooperation' AFTER `foerderung_id`,
  ADD INDEX `idx_dokumente_kooperation_id` (`kooperation_id`),
  ADD CONSTRAINT `fk_dokument_kooperation` FOREIGN KEY (`kooperation_id`) REFERENCES `kooperationen`(`id`) ON DELETE CASCADE;

INSERT IGNORE INTO `migrations` (`filename`) VALUES ('003_kooperationen.sql');
