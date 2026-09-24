-- ============================================================
--  Migration 006: TBE-Gesamtkonzept auf Fix/Flex-Modell umstellen
--  Athletikclub Steiermark
--
--  Was diese Migration macht:
--  1. tbe_projekte: Modell je Projekt (TBE Fix = Bewegungscoach-
--     Stunden, Säule 2 / TBE Flex bzw. Flex-S = flexible Einheiten,
--     Säule 3), Daten für die Kooperationsvereinbarung (Kennzahl,
--     Klassen, Kinder), geplante Flex-Einheiten und Checkliste der
--     Teilnahmevoraussetzungen
--  2. tbe_konzepte: Voraussetzungen auf Vereinsebene
--     (Kinderangebot, Fit-Sport-Austria-Qualitätssiegel)
--
--  Voraussetzung: Migration 004. Nur EINMAL ausführen
--  (ALTER TABLE bricht beim zweiten Lauf ab, das ist gewollt).
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `tbe_projekte`
  ADD COLUMN `modell`            ENUM('fix','flex','flex_s') NOT NULL DEFAULT 'fix' AFTER `konzept_id`,
  ADD COLUMN `kennzahl`          VARCHAR(20)    NULL COMMENT 'Schul-/Einrichtungskennzahl' AFTER `ort`,
  ADD COLUMN `klassen_gesamt`    SMALLINT UNSIGNED NULL COMMENT 'Gesamtanzahl Klassen/Gruppen der Einrichtung' AFTER `zielgruppe`,
  ADD COLUMN `anzahl_kinder`     SMALLINT UNSIGNED NULL AFTER `klassen_gesamt`,
  ADD COLUMN `flex_einheiten`    SMALLINT UNSIGNED NULL COMMENT 'Geplante FLEX-Einheiten gesamt' AFTER `anzahl_wochen`,
  ADD COLUMN `chk_kooperation`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Kooperationsvereinbarung unterschrieben',
  ADD COLUMN `chk_schulforum`    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Schulforum-Beschluss (VS, Bewegungscoach-Stunden)',
  ADD COLUMN `chk_qualifikation` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Qualifikation Coach/ÜL erfüllt',
  ADD COLUMN `chk_haftpflicht`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Haftpflichtversicherung aufrecht';

ALTER TABLE `tbe_konzepte`
  ADD COLUMN `chk_kinderangebot` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Verein hat ein Kinderangebot' AFTER `stundensatz`,
  ADD COLUMN `chk_fit_siegel`    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Kinder-/Jugendangebot mit Fit-Sport-Austria-Qualitätssiegel (Pflicht für Flex)' AFTER `chk_kinderangebot`;

INSERT IGNORE INTO `migrations` (`filename`) VALUES ('006_tbe_fix_flex.sql');
