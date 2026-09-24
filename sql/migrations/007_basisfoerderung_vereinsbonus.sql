-- ============================================================
--  Migration 007: Basisförderung um SPORTUNION Vereinsbonus und
--  Voraussetzungen-Checks erweitern
--  Athletikclub Steiermark
--
--  Was diese Migration macht:
--  1. su_antraege: Voraussetzungen des Vereinsbonus auf Vereinsebene
--     (aktives Fit-Sport-Austria-Qualitätssiegel, Beratungsgespräch)
--  2. su_antrag_positionen: Kategorie (z.B. Inklusion/Integration bei
--     sozialen Maßnahmen) und die abgehakten Voraussetzungen je
--     Fördergegenstand (JSON-Liste der Prüfpunkte)
--
--  Voraussetzung: Migration 005. Nur EINMAL ausführen
--  (ALTER TABLE bricht beim zweiten Lauf ab, das ist gewollt).
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `su_antraege`
  ADD COLUMN `vb_fit_siegel` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Mind. ein aktives Fit-Sport-Austria-Qualitätssiegel (Vereinsbonus)' AFTER `erkl_logo`,
  ADD COLUMN `vb_beratung`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Beratungsgespräch mit dem Landesverband geführt (Vereinsbonus)' AFTER `vb_fit_siegel`;

ALTER TABLE `su_antrag_positionen`
  ADD COLUMN `kategorie` VARCHAR(40) NULL COMMENT 'z.B. inklusion, integration, gender, soziale_verantwortung' AFTER `foerderart`,
  ADD COLUMN `checks`    TEXT        NULL COMMENT 'JSON-Liste der erfüllten Voraussetzungen' AFTER `platzierung`;

INSERT IGNORE INTO `migrations` (`filename`) VALUES ('007_basisfoerderung_vereinsbonus.sql');
