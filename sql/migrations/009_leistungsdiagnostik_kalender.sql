-- ============================================================
--  Migration 009: Leistungsdiagnostik & Terminkalender
--  Athletikclub Steiermark
--
--  1. ld_tests: Testkatalog (Protokoll, Einheit, „höher/niedriger ist besser“)
--  2. ld_sitzungen: Testungen je Mitglied (geplant oder durchgeführt)
--  3. ld_ergebnisse: Messwerte je Testung und Test (NULL = noch nicht gemessen)
--  4. termine: Kalendertermine (optional wiederkehrend, Sichtbarkeit)
--  5. kalender_abos: persönlicher Abo-Link für Handy-/Outlook-Kalender
--
--  Sicher erneut ausführbar (IF NOT EXISTS / INSERT IGNORE).
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ld_tests` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED  NOT NULL DEFAULT 1,
  `name`            VARCHAR(120)  NOT NULL,
  `kategorie`       VARCHAR(30)   NOT NULL DEFAULT 'kraft',
  `einheit`         VARCHAR(20)   NOT NULL,
  `richtung`        ENUM('hoeher','niedriger','neutral') NOT NULL DEFAULT 'hoeher' COMMENT 'Welche Richtung ist eine Verbesserung',
  `dezimalen`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `beschreibung`    TEXT          NULL COMMENT 'Testprotokoll / Durchführung',
  `aktiv`           TINYINT(1)    NOT NULL DEFAULT 1,
  `sortierung`      SMALLINT      NOT NULL DEFAULT 0,
  `erstellt_von`    INT UNSIGNED  NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ld_test_name` (`organization_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ld_sitzungen` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED  NOT NULL DEFAULT 1,
  `mitglied_id`     INT UNSIGNED  NOT NULL,
  `trainer_id`      INT UNSIGNED  NOT NULL,
  `datum`           DATE          NOT NULL,
  `uhrzeit`         TIME          NULL,
  `ort`             VARCHAR(150)  NULL,
  `bedingungen`     VARCHAR(255)  NULL COMMENT 'z.B. nach 10 min Aufwärmen, Halle, Hallenschuhe',
  `notiz`           TEXT          NULL,
  `status`          ENUM('geplant','durchgefuehrt') NOT NULL DEFAULT 'geplant',
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_lds_org_datum` (`organization_id`, `datum`),
  INDEX `idx_lds_mitglied` (`mitglied_id`),
  CONSTRAINT `fk_lds_mitglied` FOREIGN KEY (`mitglied_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lds_trainer` FOREIGN KEY (`trainer_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ld_ergebnisse` (
  `id`          INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `sitzung_id`  INT UNSIGNED   NOT NULL,
  `test_id`     INT UNSIGNED   NOT NULL,
  `wert`        DECIMAL(10,3)  NULL COMMENT 'NULL = eingeplant, noch nicht gemessen',
  `notiz`       VARCHAR(255)   NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ld_sitzung_test` (`sitzung_id`, `test_id`),
  INDEX `idx_lde_test` (`test_id`),
  CONSTRAINT `fk_lde_sitzung` FOREIGN KEY (`sitzung_id`) REFERENCES `ld_sitzungen`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lde_test` FOREIGN KEY (`test_id`) REFERENCES `ld_tests`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `termine` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id`  INT UNSIGNED  NOT NULL DEFAULT 1,
  `titel`            VARCHAR(150)  NOT NULL,
  `typ`              VARCHAR(20)   NOT NULL DEFAULT 'termin',
  `start`            DATETIME      NOT NULL,
  `ende`             DATETIME      NOT NULL,
  `ganztags`         TINYINT(1)    NOT NULL DEFAULT 0,
  `ort`              VARCHAR(150)  NULL,
  `beschreibung`     TEXT          NULL,
  `sichtbar`         ENUM('privat','trainer','alle') NOT NULL DEFAULT 'trainer' COMMENT 'privat = nur ich, trainer = alle Trainer:innen, alle = auch Mitglieder',
  `mitglied_id`      INT UNSIGNED  NULL COMMENT 'Termin mit einem bestimmten Mitglied (sieht ihn dann auch)',
  `wiederholung`     ENUM('keine','woechentlich','14taegig','monatlich') NOT NULL DEFAULT 'keine',
  `wiederholung_bis` DATE          NULL,
  `erstellt_von`     INT UNSIGNED  NOT NULL,
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_termine_org_start` (`organization_id`, `start`),
  CONSTRAINT `fk_termine_mitglied` FOREIGN KEY (`mitglied_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_termine_ersteller` FOREIGN KEY (`erstellt_von`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kalender_abos` (
  `user_id`     INT UNSIGNED  NOT NULL,
  `token`       CHAR(64)      NOT NULL,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uk_kalender_token` (`token`),
  CONSTRAINT `fk_kalender_abo_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- Startbestand Testkatalog
-- ----------------------------------------------------------------
INSERT IGNORE INTO `ld_tests` (`organization_id`, `name`, `kategorie`, `einheit`, `richtung`, `dezimalen`, `sortierung`, `beschreibung`) VALUES
(1, 'Körpergewicht', 'koerper', 'kg', 'neutral', 1, 10, 'Morgens, nüchtern, nach dem Toilettengang, in leichter Sportkleidung – immer gleiche Waage und Tageszeit.'),
(1, 'Körpergröße', 'koerper', 'cm', 'neutral', 1, 20, 'Ohne Schuhe, Fersen, Gesäß und Hinterkopf an der Wand, Blick geradeaus.'),
(1, 'Körperfettanteil', 'koerper', '%', 'niedriger', 1, 30, 'Immer gleiche Methode (z.B. Bioimpedanz-Waage oder Caliper), gleiche Tageszeit, nicht direkt nach Training oder Mahlzeit.'),
(1, 'Taillenumfang', 'koerper', 'cm', 'niedriger', 1, 40, 'Maßband waagrecht auf Nabelhöhe, stehend, entspannt nach normaler Ausatmung.'),
(1, 'Ruhepuls', 'koerper', 'bpm', 'niedriger', 0, 50, 'Morgens nach dem Aufwachen im Liegen, 60 Sekunden zählen oder Pulsuhr.'),
(1, 'Klimmzüge max.', 'kraft', 'Wdh.', 'hoeher', 0, 110, 'Obergriff schulterbreit, aus dem gestreckten Hang bis Kinn über die Stange, ohne Schwung und Kipping. Nur saubere Wiederholungen zählen.'),
(1, 'Liegestütz max.', 'kraft', 'Wdh.', 'hoeher', 0, 120, 'Körper gestreckt, Brust bis eine Faustbreite über den Boden, volle Streckung oben. Ohne Pause am Stück.'),
(1, 'Dips max.', 'kraft', 'Wdh.', 'hoeher', 0, 130, 'Am Barren bis ca. 90° im Ellbogen absenken, oben voll strecken, ohne Schwung.'),
(1, 'Liegestütz in 60 s', 'kraft', 'Wdh.', 'hoeher', 0, 140, 'Wie Liegestütz max., aber maximale saubere Wiederholungen in 60 Sekunden.'),
(1, 'Kniebeugen in 60 s', 'kraft', 'Wdh.', 'hoeher', 0, 150, 'Oberschenkel mindestens parallel zum Boden, oben volle Streckung, Arme frei.'),
(1, 'Unterarmstütz (Plank)', 'kraft', 's', 'hoeher', 0, 160, 'Unterarme schulterbreit, Körper gerade. Abbruch, sobald die Hüfte absinkt oder deutlich nach oben geht.'),
(1, 'Wandsitz', 'kraft', 's', 'hoeher', 0, 170, 'Rücken an der Wand, Knie und Hüfte 90°, Arme frei. Abbruch beim Hochrutschen.'),
(1, 'Handkraft (Dynamometer)', 'kraft', 'kg', 'hoeher', 1, 180, 'Stehend, Arm gestreckt neben dem Körper, 3 Versuche je Hand mit 30 s Pause – bester Wert der stärkeren Hand.'),
(1, 'Kniebeuge 1RM', 'kraft', 'kg', 'hoeher', 1, 190, 'Nur mit sicherer Technik und Sicherung. Alternativ aus 3–5RM geschätzt (Brzycki) – Methode in der Notiz festhalten.'),
(1, 'Counter Movement Jump', 'schnellkraft', 'cm', 'hoeher', 1, 210, 'Hände an der Hüfte, schnelle Ausholbewegung und maximaler Sprung. Sprungmatte oder App, bester von 3 Versuchen.'),
(1, 'Standweitsprung', 'schnellkraft', 'cm', 'hoeher', 0, 220, 'Beidbeiniger Absprung hinter der Linie, Messung bis zur hintersten Ferse. Bester von 3 Versuchen.'),
(1, 'Medizinballstoß (2 kg)', 'schnellkraft', 'm', 'hoeher', 2, 230, 'Im Sitz mit dem Rücken an der Wand beidhändig von der Brust stoßen. Bester von 3 Versuchen.'),
(1, 'Sprint 10 m', 'schnelligkeit', 's', 'niedriger', 2, 310, 'Hochstart 30 cm hinter der Linie, Lichtschranke oder immer gleiche Handstoppung. Bester von 2 Läufen.'),
(1, 'Sprint 20 m', 'schnelligkeit', 's', 'niedriger', 2, 320, 'Wie Sprint 10 m, Zeitnahme bei 20 m.'),
(1, 'Sprint 30 m', 'schnelligkeit', 's', 'niedriger', 2, 330, 'Wie Sprint 10 m, Zeitnahme bei 30 m.'),
(1, 'T-Test (Agilität)', 'schnelligkeit', 's', 'niedriger', 2, 340, 'Standard-T-Aufbau (10 m vor, 5 m seitlich, 10 m seitlich, 5 m zurück, 10 m rückwärts), Hütchen mit der Hand berühren.'),
(1, 'Pendellauf 4 × 10 m', 'schnelligkeit', 's', 'niedriger', 2, 350, 'Vier Strecken zu 10 m mit Wendungen, Linie jeweils mit dem Fuß überschreiten.'),
(1, 'Cooper-Test (12 min)', 'ausdauer', 'm', 'hoeher', 0, 410, 'Möglichst weite Strecke in 12 Minuten auf der Laufbahn oder vermessener Runde.'),
(1, 'Shuttle-Run (Beep-Test)', 'ausdauer', 'Stufe', 'hoeher', 1, 420, '20-m-Pendellauf nach Signalton mit steigendem Tempo; erreichte Stufe (z.B. 8,5 = Stufe 8, Bahn 5).'),
(1, '6-Minuten-Lauf', 'ausdauer', 'm', 'hoeher', 0, 430, 'Für Kinder und Einsteiger:innen: möglichst weite Strecke in 6 Minuten, Gehpausen erlaubt.'),
(1, 'VO2max', 'ausdauer', 'ml/kg/min', 'hoeher', 1, 440, 'Aus Labortest, Sportuhr-Schätzung oder berechnet (Cooper) – Methode in der Notiz angeben und nicht mischen.'),
(1, 'Sit-and-Reach', 'beweglichkeit', 'cm', 'hoeher', 1, 510, 'Langsitz, Beine gestreckt, langsam nach vorne schieben und 2 s halten. Fußsohle = 0 cm, darüber hinaus positiv.'),
(1, 'Rumpfbeuge stehend', 'beweglichkeit', 'cm', 'hoeher', 1, 520, 'Auf einer Bank stehend mit gestreckten Knien nach unten beugen. Bankoberkante = 0 cm, darunter positiv.'),
(1, 'Schulterbeweglichkeit (Stabtest)', 'beweglichkeit', 'cm', 'niedriger', 0, 530, 'Stab mit gestreckten Armen über den Kopf nach hinten führen; kleinste mögliche Griffbreite.'),
(1, 'Einbeinstand Augen zu', 'koordination', 's', 'hoeher', 0, 610, 'Barfuß, Hände an der Hüfte, Augen geschlossen, maximal 60 s. Bessere Seite.'),
(1, 'Y-Balance (Composite)', 'koordination', '%', 'hoeher', 1, 620, 'Reichweiten nach vorne, hinten-innen und hinten-außen, im Verhältnis zur Beinlänge.'),
(1, 'Seitliches Hin- und Herspringen', 'koordination', 'Sprünge', 'hoeher', 0, 630, 'Beidbeinig über eine Mittellinie, 2 × 15 Sekunden, Summe der gültigen Sprünge (Deutscher Motorik-Test).'),
(1, 'Handstand frei', 'skills', 's', 'hoeher', 0, 710, 'Freier Handstand ohne Wand, Zeit ab stabiler Position bis zum Verlassen.'),
(1, 'L-Sit', 'skills', 's', 'hoeher', 0, 720, 'Am Boden oder an Parallettes, Beine gestreckt mindestens waagrecht.'),
(1, 'Hollow Body Hold', 'skills', 's', 'hoeher', 0, 730, 'Lendenwirbelsäule am Boden, Arme und Beine gestreckt knapp über dem Boden. Abbruch beim Hohlkreuz.'),
(1, 'Muscle-ups max.', 'skills', 'Wdh.', 'hoeher', 0, 740, 'An Reckstange oder Ringen, sauber ohne übermäßigen Kip.'),
(1, 'Toes to Bar max.', 'skills', 'Wdh.', 'hoeher', 0, 750, 'Im Hang Zehen an die Stange, kontrolliert zurück.');

INSERT IGNORE INTO `migrations` (`filename`) VALUES ('009_leistungsdiagnostik_kalender.sql');
