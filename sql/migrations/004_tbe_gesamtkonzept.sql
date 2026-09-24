-- ============================================================
--  Migration 004: TBE-Gesamtkonzept (Tägliche Bewegungseinheit)
--  Athletikclub Steiermark
--
--  Was diese Migration macht:
--  1. tbe_konzepte-Tabelle: ein Gesamtkonzept pro Förderjahr
--     (z.B. Schuljahr 2026/27), das als Bericht eingereicht wird
--     und danach den bewilligten Budgetrahmen erhält
--  2. tbe_projekte-Tabelle: die einzelnen Projekte an Schulen und
--     Kindergärten innerhalb eines Konzepts (Stunden, Gruppen,
--     Budgetzuteilung)
--
--  Sicher erneut ausführbar (IF NOT EXISTS).
-- ============================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------
-- 1. Gesamtkonzepte (ein Förderjahr)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tbe_konzepte` (
  `id`                  INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `organization_id`     INT UNSIGNED   NOT NULL DEFAULT 1,
  `bezeichnung`         VARCHAR(20)    NOT NULL COMMENT 'z.B. 2026/27',
  `zeitraum_von`        DATE           NOT NULL,
  `zeitraum_bis`        DATE           NOT NULL,
  `konzeptbeschreibung` TEXT           NULL COMMENT 'Ziele und Inhalte, erscheint im Bericht',
  `stundensatz`         DECIMAL(10,2)  NULL COMMENT 'Kosten pro Trainer:innen-Stunde für die Kostenschätzung',
  `status`              ENUM('entwurf','eingereicht','bewilligt','abgeschlossen') NOT NULL DEFAULT 'entwurf',
  `eingereicht_am`      DATE           NULL,
  `budget_rahmen`       DECIMAL(10,2)  NULL COMMENT 'Bewilligter Budgetrahmen',
  `budget_bewilligt_am` DATE           NULL,
  `notizen`             TEXT           NULL,
  `erstellt_von`        INT UNSIGNED   NOT NULL,
  `created_at`          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_tbe_konzepte_org` (`organization_id`),
  CONSTRAINT `fk_tbe_konzept_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`),
  CONSTRAINT `fk_tbe_konzept_ersteller` FOREIGN KEY (`erstellt_von`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 2. Projekte je Konzept (eine Schule / ein Kindergarten + Angebot)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tbe_projekte` (
  `id`                  INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `konzept_id`          INT UNSIGNED   NOT NULL,
  `einrichtung`         VARCHAR(150)   NOT NULL COMMENT 'Name der Schule / des Kindergartens',
  `einrichtungstyp`     ENUM('kindergarten','volksschule','mittelschule','sonstige') NOT NULL DEFAULT 'volksschule',
  `ort`                 VARCHAR(150)   NULL,
  `ansprechperson`      VARCHAR(150)   NULL,
  `bewegungsangebot`    VARCHAR(150)   NOT NULL COMMENT 'z.B. Calisthenics, Koordination',
  `zielgruppe`          VARCHAR(150)   NULL COMMENT 'z.B. 1.–4. Klasse, 4–6 Jahre',
  `anzahl_gruppen`      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `einheiten_pro_woche` DECIMAL(4,1)   NOT NULL DEFAULT 1.0 COMMENT 'je Gruppe',
  `dauer_minuten`       SMALLINT UNSIGNED NOT NULL DEFAULT 50 COMMENT 'Dauer einer Einheit',
  `anzahl_wochen`       SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  `trainer`             VARCHAR(150)   NULL,
  `beschreibung`        TEXT           NULL,
  `budget_zugeteilt`    DECIMAL(10,2)  NULL COMMENT 'Anteil am bewilligten Budgetrahmen',
  `sortierung`          SMALLINT       NOT NULL DEFAULT 0,
  `created_at`          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_tbe_projekte_konzept` (`konzept_id`),
  CONSTRAINT `fk_tbe_projekt_konzept` FOREIGN KEY (`konzept_id`) REFERENCES `tbe_konzepte`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `migrations` (`filename`) VALUES ('004_tbe_gesamtkonzept.sql');
