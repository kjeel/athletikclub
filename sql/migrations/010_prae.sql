-- ============================================================
--  Migration 010: PRAE-Abwicklung (pauschale Reiseaufwandsentschädigung)
--  Athletikclub Steiermark – § 3 Abs. 1 Z 16c EStG, § 49 Abs. 3 Z 28 ASVG
--
--  1. prae_einstellungen: Vereinsdaten für L19 (ZVR, Steuernummer, Adresse) und SEPA
--  2. prae_empfaenger: Sportler:innen, Trainer:innen, Übungsleiter:innen, Schiedsrichter:innen
--  3. prae_einsaetze: Einsatztage (Training, Wettkampf, Fortbildung) – max. einer je Person und Tag
--  4. prae_abrechnungen: Monatsabrechnung je Person (Entwurf → freigegeben → ausbezahlt)
--  5. prae_meldungen: L19-Übermittlungen an das Finanzamt über ELDA (inkl. Korrektur/Storno)
--
--  Sicher erneut ausführbar (IF NOT EXISTS).
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `prae_einstellungen` (
  `organization_id`  INT UNSIGNED  NOT NULL,
  `vereinsname`      VARCHAR(200)  NOT NULL,
  `zvr`              CHAR(10)      NOT NULL,
  `steuernummer`     CHAR(9)       NULL COMMENT '9-stellig ohne Trennzeichen, z.B. 681234567',
  `strasse`          VARCHAR(200)  NULL,
  `plz`              VARCHAR(10)   NULL,
  `ort`              VARCHAR(100)  NULL,
  `land`             CHAR(2)       NOT NULL DEFAULT 'AT',
  `tagessatz`        DECIMAL(8,2)  NOT NULL DEFAULT 40.00 COMMENT 'Standard-Betrag je Einsatztag',
  `iban`             VARCHAR(34)   NULL COMMENT 'Vereinskonto für SEPA-Sammelüberweisung',
  `bic`              VARCHAR(11)   NULL,
  `verantwortlich`   VARCHAR(150)  NULL COMMENT 'Unterzeichnende Person (Obmann/Kassier)',
  `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`organization_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `prae_empfaenger` (
  `id`                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id`     INT UNSIGNED  NOT NULL DEFAULT 1,
  `user_id`             INT UNSIGNED  NULL COMMENT 'Verknüpftes Benutzerkonto (Trainer:in/Mitglied)',
  `rolle`               VARCHAR(20)   NOT NULL DEFAULT 'trainer',
  `vorname`             VARCHAR(100)  NOT NULL,
  `nachname`            VARCHAR(100)  NOT NULL,
  `svnr`                CHAR(10)      NULL COMMENT 'Sozialversicherungsnummer laut e-card',
  `geburtsdatum`        DATE          NULL,
  `strasse`             VARCHAR(200)  NULL,
  `plz`                 VARCHAR(10)   NULL,
  `ort`                 VARCHAR(100)  NULL,
  `land`                CHAR(2)       NOT NULL DEFAULT 'AT',
  `email`               VARCHAR(180)  NULL,
  `iban`                VARCHAR(34)   NULL,
  `tagessatz`           DECIMAL(8,2)  NULL COMMENT 'NULL = Standard aus den Einstellungen',
  `hauptberuf`          VARCHAR(150)  NULL COMMENT 'z.B. Angestellte:r, Student:in, Pension',
  `kein_hauptberuf`     TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'Bestätigt: Tätigkeit ist nicht Hauptberuf/Haupteinnahmequelle',
  `andere_bezuege`      TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'Erhält vom Verein Lohn/Gehalt → L16 statt L19',
  `aktiv`               TINYINT(1)    NOT NULL DEFAULT 1,
  `notiz`               TEXT          NULL,
  `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_praee_org` (`organization_id`),
  UNIQUE KEY `uk_praee_user` (`organization_id`, `user_id`),
  CONSTRAINT `fk_praee_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `prae_abrechnungen` (
  `id`                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id`    INT UNSIGNED  NOT NULL DEFAULT 1,
  `empfaenger_id`      INT UNSIGNED  NOT NULL,
  `jahr`               SMALLINT UNSIGNED NOT NULL,
  `monat`              TINYINT UNSIGNED NOT NULL,
  `einsatztage`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `betrag_gesamt`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `betrag_steuerfrei`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `betrag_ueberschuss` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Über 120 €/Tag bzw. 720 €/Monat – steuerpflichtig',
  `status`             ENUM('entwurf','freigegeben','ausbezahlt') NOT NULL DEFAULT 'entwurf',
  `zahlungsart`        ENUM('ueberweisung','bar') NOT NULL DEFAULT 'ueberweisung',
  `ausgezahlt_am`      DATE          NULL,
  `bestaetigt_am`      DATETIME      NULL COMMENT 'Bestätigung durch Empfänger:in im System',
  `bestaetigt_ip`      VARCHAR(45)   NULL,
  `notiz`              VARCHAR(500)  NULL,
  `erstellt_von`       INT UNSIGNED  NULL,
  `freigegeben_von`    INT UNSIGNED  NULL,
  `created_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_praea_monat` (`empfaenger_id`, `jahr`, `monat`),
  INDEX `idx_praea_org_jahr` (`organization_id`, `jahr`, `monat`),
  CONSTRAINT `fk_praea_empf` FOREIGN KEY (`empfaenger_id`) REFERENCES `prae_empfaenger`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `prae_einsaetze` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED  NOT NULL DEFAULT 1,
  `empfaenger_id`   INT UNSIGNED  NOT NULL,
  `datum`           DATE          NOT NULL,
  `art`             ENUM('training','wettkampf','fortbildung') NOT NULL DEFAULT 'training',
  `beschreibung`    VARCHAR(255)  NULL COMMENT 'z.B. Kurs, Gruppe, Ort',
  `kurs_id`         INT UNSIGNED  NULL,
  `betrag`          DECIMAL(8,2)  NOT NULL,
  `abrechnung_id`   INT UNSIGNED  NULL,
  `erfasst_von`     INT UNSIGNED  NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_praeeins_tag` (`empfaenger_id`, `datum`),
  INDEX `idx_praeeins_org_datum` (`organization_id`, `datum`),
  CONSTRAINT `fk_praeeins_empf` FOREIGN KEY (`empfaenger_id`) REFERENCES `prae_empfaenger`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_praeeins_abr` FOREIGN KEY (`abrechnung_id`) REFERENCES `prae_abrechnungen`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `prae_meldungen` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED  NOT NULL DEFAULT 1,
  `empfaenger_id`   INT UNSIGNED  NOT NULL,
  `jahr`            SMALLINT UNSIGNED NOT NULL,
  `refnr`           VARCHAR(100)  NOT NULL COMMENT 'Bleibt für Korrektur/Storno gleich',
  `typ`             ENUM('meldung','korrektur','storno') NOT NULL DEFAULT 'meldung',
  `blz`             DATE          NOT NULL,
  `elz`             DATE          NOT NULL,
  `betrag`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `xml`             MEDIUMTEXT    NOT NULL,
  `status`          ENUM('erstellt','uebermittelt') NOT NULL DEFAULT 'erstellt',
  `uebermittelt_am` DATETIME      NULL,
  `erstellt_von`    INT UNSIGNED  NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_praem_org_jahr` (`organization_id`, `jahr`),
  INDEX `idx_praem_refnr` (`refnr`),
  CONSTRAINT `fk_praem_empf` FOREIGN KEY (`empfaenger_id`) REFERENCES `prae_empfaenger`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vereinsdaten vorbelegen (ZVR des Athletikclub Steiermark, in der Anwendung änderbar)
INSERT IGNORE INTO `prae_einstellungen` (`organization_id`, `vereinsname`, `zvr`, `strasse`, `plz`, `ort`, `land`)
VALUES (1, 'Athletikclub Steiermark', '1545056798', 'Sankt Georgen an der Stiefing 14', '8413', 'Sankt Georgen an der Stiefing', 'AT');

INSERT IGNORE INTO `migrations` (`filename`) VALUES ('010_prae.sql');
