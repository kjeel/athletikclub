-- ============================================================
--  Migration 005: Basisförderung SPORTUNION Steiermark
--  Athletikclub Steiermark
--
--  Was diese Migration macht:
--  1. su_antraege-Tabelle: ein Förderansuchen pro Kalenderjahr
--     (Förderzeitraum = Kalenderjahr laut Abrechnungsrichtlinien)
--     mit Vereinsdaten, statutarischen Vertretern und Status
--  2. su_antrag_positionen-Tabelle: die einzelnen Fördergegenstände
--     (Geräte, Aus-/Fortbildung, Jugendarbeit, Bau, ...) mit
--     Beschreibung und Finanzierungsplan
--  3. su_antrag_kosten-Tabelle: Kostenaufstellung je Position
--
--  Grundlage: Förderrichtlinien 2023, Finanzielle Zuschüsse und
--  Abrechnungsrichtlinien der SPORTUNION Steiermark.
--  Sicher erneut ausführbar (IF NOT EXISTS).
-- ============================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------
-- 1. Förderansuchen (ein Kalenderjahr)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `su_antraege` (
  `id`                       INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `organization_id`          INT UNSIGNED   NOT NULL DEFAULT 1,
  `jahr`                     SMALLINT UNSIGNED NOT NULL COMMENT 'Förderzeitraum = Kalenderjahr',
  `titel`                    VARCHAR(150)   NOT NULL,
  `status`                   ENUM('entwurf','eingereicht','zugesagt','teilzusage','abgelehnt','abgerechnet') NOT NULL DEFAULT 'entwurf',
  `sportarten`               VARCHAR(255)   NULL,
  `mitglieder_gesamt`        SMALLINT UNSIGNED NULL,
  `mitglieder_jugend`        SMALLINT UNSIGNED NULL COMMENT 'unter 19 Jahren',
  `vereinsbeschreibung`      TEXT           NULL,
  `obmann_name`              VARCHAR(150)   NULL,
  `vertreter2_funktion`      ENUM('kassier','schriftfuehrer') NOT NULL DEFAULT 'kassier',
  `vertreter2_name`          VARCHAR(150)   NULL,
  `kontakt_name`             VARCHAR(150)   NULL,
  `kontakt_email`            VARCHAR(180)   NULL,
  `kontakt_telefon`          VARCHAR(30)    NULL,
  `kontoinhaber`             VARCHAR(150)   NULL,
  `iban`                     VARCHAR(50)    NULL,
  `bic`                      VARCHAR(20)    NULL,
  `bank`                     VARCHAR(150)   NULL,
  `erkl_mitglied`            TINYINT(1)     NOT NULL DEFAULT 0 COMMENT 'Mitglied SPORTUNION Stmk, kein Rechtsstreit',
  `erkl_landesumlage`        TINYINT(1)     NOT NULL DEFAULT 0 COMMENT 'Keine offenen Verbindlichkeiten (Landesumlage etc.)',
  `erkl_fairplay`            TINYINT(1)     NOT NULL DEFAULT 0 COMMENT 'Fair-Play, Anti-Doping, Ehrenkodex',
  `erkl_richtlinien`         TINYINT(1)     NOT NULL DEFAULT 0 COMMENT 'Förder- und Abrechnungsrichtlinien anerkannt',
  `erkl_logo`                TINYINT(1)     NOT NULL DEFAULT 0 COMMENT 'Hinweis auf Förderung / Logo in Öffentlichkeitsarbeit',
  `eingereicht_am`           DATE           NULL,
  `zusage_datum`             DATE           NULL,
  `abgerechnet_am`           DATE           NULL,
  `notizen`                  TEXT           NULL,
  `erstellt_von`             INT UNSIGNED   NOT NULL,
  `created_at`               DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_su_antraege_org` (`organization_id`),
  CONSTRAINT `fk_su_antrag_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`),
  CONSTRAINT `fk_su_antrag_ersteller` FOREIGN KEY (`erstellt_von`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 2. Fördergegenstände je Ansuchen
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `su_antrag_positionen` (
  `id`                       INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `antrag_id`                INT UNSIGNED   NOT NULL,
  `foerderart`               VARCHAR(30)    NOT NULL COMMENT 'Schlüssel aus SU_FOERDERARTEN (includes/basisfoerderung.php)',
  `titel`                    VARCHAR(200)   NOT NULL,
  `beschreibung`             TEXT           NULL COMMENT 'Klare Beschreibung des Fördergegenstandes',
  `nutzen`                   TEXT           NULL COMMENT 'Ziel und Nutzen für den Verein / die Mitglieder',
  `massnahme_von`            DATE           NULL,
  `massnahme_bis`            DATE           NULL,
  `anzahl_personen`          SMALLINT UNSIGNED NULL COMMENT 'Teilnehmer:innen bzw. Absolvent:innen',
  `ausbildungsstufe`         ENUM('uebungsleiter','kampfrichter','instruktor','trainer') NULL,
  `mit_uebernachtung`        TINYINT(1)     NOT NULL DEFAULT 0,
  `wettkampf`                VARCHAR(200)   NULL COMMENT 'Meisterschaft / Veranstaltung / Ausbildung (Name, Ort)',
  `platzierung`              VARCHAR(100)   NULL,
  `eigenmittel`              DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  `andere_foerderungen`      DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  `andere_foerderungen_text` VARCHAR(255)   NULL COMMENT 'z.B. Gemeinde, Land, Fachverband',
  `betrag_beantragt`         DECIMAL(10,2)  NULL COMMENT 'Leer = automatisch berechneter Richtwert',
  `betrag_zugesagt`          DECIMAL(10,2)  NULL,
  `sortierung`               SMALLINT       NOT NULL DEFAULT 0,
  `created_at`               DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_su_positionen_antrag` (`antrag_id`),
  CONSTRAINT `fk_su_position_antrag` FOREIGN KEY (`antrag_id`) REFERENCES `su_antraege`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 3. Kostenaufstellung je Fördergegenstand
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `su_antrag_kosten` (
  `id`            INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `position_id`   INT UNSIGNED   NOT NULL,
  `bezeichnung`   VARCHAR(200)   NOT NULL,
  `menge`         DECIMAL(8,2)   NOT NULL DEFAULT 1.00,
  `einzelpreis`   DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  `anbieter`      VARCHAR(150)   NULL COMMENT 'Lieferant / Kostenvoranschlag',
  `sortierung`    SMALLINT       NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  INDEX `idx_su_kosten_position` (`position_id`),
  CONSTRAINT `fk_su_kosten_position` FOREIGN KEY (`position_id`) REFERENCES `su_antrag_positionen`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `migrations` (`filename`) VALUES ('005_basisfoerderung.sql');
