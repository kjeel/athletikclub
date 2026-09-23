-- ============================================================
--  Migration 002: Fördermanagement
--  Athletikclub Steiermark
--
--  Was diese Migration macht:
--  1. foerderungen-Tabelle (Förderansuchen/-zusagen von Land,
--     Bund, Verband, Gemeinde etc. mit vollem Status-Workflow)
--  2. Erweitert die bestehende dokumente-Tabelle um foerderung_id,
--     damit Förderansuchen/Bescheide/Verwendungsnachweise im
--     selben Dokumentenarchiv-System wie Mitglieder-Dokumente
--     verwaltet werden können
--
--  Sicher erneut ausführbar (IF NOT EXISTS), bricht beim zweiten
--  Lauf bei den ALTER-Statements ab, das ist gewollt.
-- ============================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------
-- 1. Förderungen
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `foerderungen` (
  `id`                       INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `organization_id`          INT UNSIGNED   NOT NULL DEFAULT 1,
  `titel`                    VARCHAR(200)   NOT NULL,
  `foerderstelle`             VARCHAR(200)   NOT NULL COMMENT 'z.B. Land Steiermark, Sportunion, Gemeinde',
  `beschreibung`             TEXT           NULL,
  `betrag_beantragt`         DECIMAL(10,2)  NULL,
  `betrag_bewilligt`         DECIMAL(10,2)  NULL,
  `status`                   ENUM('geplant','beantragt','bewilligt','abgelehnt','ausbezahlt','abgeschlossen') NOT NULL DEFAULT 'geplant',
  `einreichfrist`            DATE           NULL,
  `bewilligungsdatum`        DATE           NULL,
  `nachweisfrist`            DATE           NULL COMMENT 'Frist für Verwendungsnachweis',
  `ausbezahlt_am`            DATE           NULL,
  `ansprechpartner_name`     VARCHAR(150)   NULL,
  `ansprechpartner_email`    VARCHAR(180)   NULL,
  `ansprechpartner_telefon`  VARCHAR(30)    NULL,
  `notizen`                  TEXT           NULL COMMENT 'Interner Verlauf/Kommentare',
  `erstellt_von`             INT UNSIGNED   NOT NULL,
  `created_at`               DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_foerderungen_org` (`organization_id`),
  INDEX `idx_foerderungen_status` (`status`),
  CONSTRAINT `fk_foerderung_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`),
  CONSTRAINT `fk_foerderung_ersteller` FOREIGN KEY (`erstellt_von`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 2. Dokumentenarchiv um Förderungen erweitern
-- ----------------------------------------------------------------
ALTER TABLE `dokumente`
  MODIFY COLUMN `kategorie` ENUM('vereinsdokument','trainingsplan','kursinformation','protokoll','foerderansuchen','foerderbescheid','verwendungsnachweis','sonstiges') NOT NULL DEFAULT 'sonstiges',
  ADD COLUMN `foerderung_id` INT UNSIGNED NULL COMMENT 'Falls gesetzt: Dokument gehört zum Dokumentenarchiv dieser Förderung' AFTER `mitglied_id`,
  ADD INDEX `idx_dokumente_foerderung_id` (`foerderung_id`),
  ADD CONSTRAINT `fk_dokument_foerderung` FOREIGN KEY (`foerderung_id`) REFERENCES `foerderungen`(`id`) ON DELETE CASCADE;

INSERT IGNORE INTO `migrations` (`filename`) VALUES ('002_foerdermanagement.sql');
