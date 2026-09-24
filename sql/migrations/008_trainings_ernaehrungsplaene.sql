-- ============================================================
--  Migration 008: Trainings- und Ernährungspläne
--  Athletikclub Steiermark
--
--  Was diese Migration macht:
--  1. uebungen: Übungsbibliothek des Vereins (mit Startbestand)
--  2. trainingsplaene / trainingsplan_einheiten / trainingsplan_uebungen:
--     Trainingspläne der Trainer:innen für Mitglieder (oder als Vorlage,
--     wenn mitglied_id NULL), gegliedert in Einheiten mit Übungen und
--     Belastungsparametern (Sätze, Wiederholungen, Last, RPE, Tempo, Pause)
--  3. trainingsplan_protokoll: Mitglieder haken absolvierte Einheiten ab
--  4. ernaehrungsplaene / ernaehrungsplan_mahlzeiten: Ernährungspläne für
--     gesunde Personen mit Energie-/Makro-Richtwerten und Mahlzeiten
--
--  Sicher erneut ausführbar (IF NOT EXISTS / INSERT IGNORE).
-- ============================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------
-- 1. Übungsbibliothek
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `uebungen` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED  NOT NULL DEFAULT 1,
  `name`            VARCHAR(120)  NOT NULL,
  `kategorie`       ENUM('aufwaermen','kraft','calisthenics','mobilitaet','ausdauer','koordination','cooldown','sonstiges') NOT NULL DEFAULT 'kraft',
  `muskelgruppe`    VARCHAR(120)  NULL,
  `equipment`       VARCHAR(120)  NULL,
  `beschreibung`    TEXT          NULL COMMENT 'Ausführung und Coaching-Hinweise',
  `video_url`       VARCHAR(255)  NULL,
  `erstellt_von`    INT UNSIGNED  NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_uebung_name` (`organization_id`, `name`),
  INDEX `idx_uebungen_kategorie` (`kategorie`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 2. Trainingspläne
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `trainingsplaene` (
  `id`                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id`     INT UNSIGNED  NOT NULL DEFAULT 1,
  `mitglied_id`         INT UNSIGNED  NULL COMMENT 'NULL = Vorlage',
  `trainer_id`          INT UNSIGNED  NOT NULL,
  `titel`               VARCHAR(150)  NOT NULL,
  `ziel`                VARCHAR(30)   NOT NULL DEFAULT 'allgemeine_fitness',
  `niveau`              ENUM('einsteiger','fortgeschritten','profi') NOT NULL DEFAULT 'einsteiger',
  `start_datum`         DATE          NULL,
  `dauer_wochen`        TINYINT UNSIGNED NOT NULL DEFAULT 6,
  `einheiten_pro_woche` TINYINT UNSIGNED NOT NULL DEFAULT 3,
  `status`              ENUM('entwurf','aktiv','abgeschlossen') NOT NULL DEFAULT 'entwurf',
  `gesundheit_checks`   TEXT          NULL COMMENT 'JSON: mit „nein“ beantwortete Gesundheitsfragen',
  `gesundheit_notiz`    TEXT          NULL COMMENT 'Einschränkungen, Verletzungen, ärztliche Freigabe',
  `hinweise`            TEXT          NULL COMMENT 'Allgemeine Hinweise für das Mitglied (Progression, Aufwärmen …)',
  `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_tp_org` (`organization_id`),
  INDEX `idx_tp_mitglied` (`mitglied_id`),
  CONSTRAINT `fk_tp_mitglied` FOREIGN KEY (`mitglied_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tp_trainer` FOREIGN KEY (`trainer_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `trainingsplan_einheiten` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `plan_id`     INT UNSIGNED  NOT NULL,
  `name`        VARCHAR(120)  NOT NULL COMMENT 'z.B. Tag A – Oberkörper Zug',
  `wochentag`   TINYINT UNSIGNED NULL COMMENT '1 = Montag … 7 = Sonntag',
  `aufwaermen`  TEXT          NULL,
  `notiz`       TEXT          NULL,
  `sortierung`  SMALLINT      NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  INDEX `idx_tpe_plan` (`plan_id`),
  CONSTRAINT `fk_tpe_plan` FOREIGN KEY (`plan_id`) REFERENCES `trainingsplaene`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `trainingsplan_uebungen` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `einheit_id`     INT UNSIGNED  NOT NULL,
  `uebung_id`      INT UNSIGNED  NULL COMMENT 'Verweis auf die Bibliothek (optional)',
  `uebung_name`    VARCHAR(120)  NOT NULL,
  `saetze`         TINYINT UNSIGNED NULL,
  `wiederholungen` VARCHAR(30)   NULL COMMENT 'z.B. 8–12, 5, 30 s, max.',
  `last`           VARCHAR(40)   NULL COMMENT 'z.B. 20 kg, Körpergewicht, 70 % 1RM, Band grün',
  `rpe`            DECIMAL(3,1)  NULL COMMENT 'Belastungsempfinden 1–10',
  `tempo`          VARCHAR(12)   NULL COMMENT 'z.B. 3-1-1-0',
  `pause_sek`      SMALLINT UNSIGNED NULL,
  `notiz`          VARCHAR(255)  NULL,
  `sortierung`     SMALLINT      NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  INDEX `idx_tpu_einheit` (`einheit_id`),
  CONSTRAINT `fk_tpu_einheit` FOREIGN KEY (`einheit_id`) REFERENCES `trainingsplan_einheiten`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tpu_uebung` FOREIGN KEY (`uebung_id`) REFERENCES `uebungen`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 3. Trainingsprotokoll der Mitglieder
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `trainingsplan_protokoll` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `plan_id`     INT UNSIGNED  NOT NULL,
  `einheit_id`  INT UNSIGNED  NOT NULL,
  `user_id`     INT UNSIGNED  NOT NULL,
  `datum`       DATE          NOT NULL,
  `rpe`         DECIMAL(3,1)  NULL COMMENT 'Wie anstrengend war die Einheit (1–10)',
  `notiz`       VARCHAR(500)  NULL,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_tpp_plan` (`plan_id`),
  CONSTRAINT `fk_tpp_plan` FOREIGN KEY (`plan_id`) REFERENCES `trainingsplaene`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tpp_einheit` FOREIGN KEY (`einheit_id`) REFERENCES `trainingsplan_einheiten`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tpp_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 4. Ernährungspläne (nur für gesunde Personen – siehe Gesundheits-Check)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ernaehrungsplaene` (
  `id`                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `organization_id`    INT UNSIGNED  NOT NULL DEFAULT 1,
  `mitglied_id`        INT UNSIGNED  NULL COMMENT 'NULL = Vorlage',
  `trainer_id`         INT UNSIGNED  NOT NULL,
  `titel`              VARCHAR(150)  NOT NULL,
  `ziel`               ENUM('abnehmen','halten','aufbauen','leistung') NOT NULL DEFAULT 'halten',
  `geschlecht`         ENUM('w','m') NULL,
  `alter_jahre`        TINYINT UNSIGNED NULL,
  `groesse_cm`         SMALLINT UNSIGNED NULL,
  `gewicht_kg`         DECIMAL(5,1)  NULL,
  `pal`                DECIMAL(3,2)  NOT NULL DEFAULT 1.55 COMMENT 'Aktivitätsfaktor',
  `sport_stunden_woche` DECIMAL(4,1) NOT NULL DEFAULT 3.0,
  `kalorien_ziel`      SMALLINT UNSIGNED NULL COMMENT 'leer = berechneter Richtwert',
  `protein_g_kg`       DECIMAL(3,1)  NULL COMMENT 'leer = Empfehlung nach Trainingsumfang',
  `fett_prozent`       TINYINT UNSIGNED NOT NULL DEFAULT 30,
  `start_datum`        DATE          NULL,
  `status`             ENUM('entwurf','aktiv','abgeschlossen') NOT NULL DEFAULT 'entwurf',
  `gesundheit_checks`  TEXT          NULL COMMENT 'JSON: bestätigte Ausschlusskriterien',
  `hinweise`           TEXT          NULL,
  `created_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_ep_org` (`organization_id`),
  INDEX `idx_ep_mitglied` (`mitglied_id`),
  CONSTRAINT `fk_ep_mitglied` FOREIGN KEY (`mitglied_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ep_trainer` FOREIGN KEY (`trainer_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ernaehrungsplan_mahlzeiten` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `plan_id`     INT UNSIGNED  NOT NULL,
  `tagtyp`      ENUM('alle','training','ruhe') NOT NULL DEFAULT 'alle',
  `mahlzeit`    VARCHAR(60)   NOT NULL COMMENT 'z.B. Frühstück, Snack vor dem Training',
  `uhrzeit`     VARCHAR(10)   NULL,
  `inhalt`      TEXT          NOT NULL COMMENT 'Lebensmittel, Mengen, Rezeptidee',
  `kcal`        SMALLINT UNSIGNED NULL,
  `protein_g`   SMALLINT UNSIGNED NULL,
  `kh_g`        SMALLINT UNSIGNED NULL,
  `fett_g`      SMALLINT UNSIGNED NULL,
  `sortierung`  SMALLINT      NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  INDEX `idx_epm_plan` (`plan_id`),
  CONSTRAINT `fk_epm_plan` FOREIGN KEY (`plan_id`) REFERENCES `ernaehrungsplaene`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 5. Startbestand der Übungsbibliothek (Schwerpunkt Calisthenics & Athletik)
-- ----------------------------------------------------------------
INSERT IGNORE INTO `uebungen` (`organization_id`, `name`, `kategorie`, `muskelgruppe`, `equipment`, `beschreibung`) VALUES
(1, 'Mobilisation Schulter & Hüfte', 'aufwaermen', 'Ganzkörper', '–', 'Armkreisen, Hüftkreisen, Cat-Cow, Weltgrößte Dehnung – je 30–45 s.'),
(1, 'Jumping Jacks', 'aufwaermen', 'Ganzkörper', '–', 'Locker und rhythmisch zum Anheben der Körpertemperatur.'),
(1, 'Skipping', 'aufwaermen', 'Beine, Koordination', '–', 'Kniehebelauf am Stand, aufrechter Oberkörper, aktive Armarbeit.'),
(1, 'Scapula Pull-ups', 'aufwaermen', 'Schulterblattmuskulatur', 'Reckstange', 'Im aktiven Hang nur die Schulterblätter nach unten/hinten ziehen, Arme gestreckt.'),
(1, 'Liegestütz', 'calisthenics', 'Brust, Trizeps, vordere Schulter', '–', 'Körper als Brett, Ellbogen ca. 45° zum Körper, Brust bis knapp über den Boden.'),
(1, 'Liegestütz erhöht (Hände auf Box)', 'calisthenics', 'Brust, Trizeps', 'Box/Bank', 'Regression des Liegestützes – je höher die Hände, desto leichter.'),
(1, 'Diamond Push-ups', 'calisthenics', 'Trizeps, Brust', '–', 'Hände eng unter der Brust, Ellbogen nah am Körper.'),
(1, 'Pike Push-ups', 'calisthenics', 'Schulter, Trizeps', '–', 'Hüfte hoch, Kopf Richtung Boden vor die Hände – Vorstufe zum Handstanddrücken.'),
(1, 'Klimmzug', 'calisthenics', 'Latissimus, Bizeps, oberer Rücken', 'Reckstange', 'Aus dem aktiven Hang Kinn über die Stange ziehen, kontrolliert ablassen.'),
(1, 'Negative Klimmzüge', 'calisthenics', 'Latissimus, Bizeps', 'Reckstange', 'Oben starten, 3–5 s kontrolliert ablassen – Aufbau für den ersten Klimmzug.'),
(1, 'Australian Pull-ups (Rudern am Reck)', 'calisthenics', 'Oberer Rücken, Bizeps', 'Tiefe Stange/Ringe', 'Körper gestreckt, Brust zur Stange ziehen; je flacher, desto schwerer.'),
(1, 'Dips', 'calisthenics', 'Trizeps, Brust, Schulter', 'Barren', 'Schultern tief halten, bis ca. 90° im Ellbogen absenken.'),
(1, 'Bank-Dips', 'calisthenics', 'Trizeps', 'Bank', 'Einsteigervariante; Hüfte nah an der Bank.'),
(1, 'Muscle-up', 'calisthenics', 'Rücken, Brust, Trizeps', 'Reckstange/Ringe', 'Explosiver Zug mit Übergang in die Stützposition – nur mit sicheren Klimmzügen und Dips.'),
(1, 'Hanging Knee Raises', 'calisthenics', 'Bauch, Hüftbeuger', 'Reckstange', 'Im aktiven Hang Knie zur Brust ziehen, ohne Schwung.'),
(1, 'Hanging Leg Raises', 'calisthenics', 'Bauch, Hüftbeuger', 'Reckstange', 'Gestreckte Beine bis 90° oder zur Stange heben.'),
(1, 'L-Sit', 'calisthenics', 'Bauch, Hüftbeuger, Trizeps', 'Parallettes/Boden', 'Stütz mit gestreckten Beinen parallel zum Boden halten.'),
(1, 'Handstand an der Wand', 'calisthenics', 'Schulter, Rumpf', 'Wand', 'Bauch zur Wand, Körper gestreckt, Schultern aktiv zu den Ohren.'),
(1, 'Pistol Squat (Regression auf Box)', 'calisthenics', 'Beine, Gesäß', 'Box', 'Einbeinige Kniebeuge bis auf die Box, Knie über der Fußspitze.'),
(1, 'Kniebeuge', 'kraft', 'Oberschenkel, Gesäß', '–', 'Hüftbreiter Stand, Gewicht auf ganzem Fuß, Oberkörper aufrecht, tief so weit technisch sauber.'),
(1, 'Goblet Squat', 'kraft', 'Oberschenkel, Gesäß', 'Kettlebell/Kurzhantel', 'Gewicht vor der Brust, Ellbogen zwischen die Knie.'),
(1, 'Ausfallschritt', 'kraft', 'Oberschenkel, Gesäß', '–', 'Großer Schritt, hinteres Knie knapp über den Boden, Oberkörper aufrecht.'),
(1, 'Bulgarian Split Squat', 'kraft', 'Oberschenkel, Gesäß', 'Bank', 'Hinterer Fuß auf der Bank, vorderes Bein arbeitet.'),
(1, 'Kreuzheben rumänisch', 'kraft', 'Hintere Oberschenkel, Gesäß, Rücken', 'Langhantel/Kurzhanteln', 'Hüfte nach hinten schieben, Rücken neutral, Hantel nah am Körper.'),
(1, 'Hip Thrust', 'kraft', 'Gesäß', 'Bank', 'Schulterblätter auf der Bank, Hüfte bis zur Streckung heben.'),
(1, 'Kettlebell Swing', 'kraft', 'Gesäß, hintere Kette', 'Kettlebell', 'Explosive Hüftstreckung, Arme führen nur.'),
(1, 'Wadenheben', 'kraft', 'Waden', 'Stufe', 'Volle Bewegungsamplitude, oben kurz halten.'),
(1, 'Plank (Unterarmstütz)', 'kraft', 'Rumpf', '–', 'Körper als Brett, Gesäß und Bauch angespannt.'),
(1, 'Seitstütz', 'kraft', 'Seitliche Rumpfmuskulatur', '–', 'Hüfte hoch, Körper in einer Linie.'),
(1, 'Hollow Body Hold', 'calisthenics', 'Rumpf', '–', 'Lendenwirbelsäule am Boden, Arme und Beine gestreckt knapp über dem Boden.'),
(1, 'Dead Bug', 'kraft', 'Rumpf', '–', 'Gegengleich Arm und Bein strecken, Rücken bleibt am Boden.'),
(1, 'Superman / Back Extension', 'kraft', 'Rückenstrecker', '–', 'In Bauchlage Arme und Beine leicht abheben, Blick zum Boden.'),
(1, 'Burpees', 'ausdauer', 'Ganzkörper', '–', 'Liegestützposition – Sprung zurück in die Hocke – Strecksprung.'),
(1, 'Mountain Climbers', 'ausdauer', 'Rumpf, Ganzkörper', '–', 'Im Liegestütz abwechselnd Knie zur Brust ziehen.'),
(1, 'Seilspringen', 'ausdauer', 'Waden, Ausdauer', 'Springseil', 'Kleine Sprünge aus dem Fußgelenk.'),
(1, 'Intervalllauf', 'ausdauer', 'Ausdauer', '–', 'z.B. 30 s zügig / 30 s locker.'),
(1, 'Einbeinstand mit Augen zu', 'koordination', 'Gleichgewicht', '–', 'Stabil stehen, Fußgewölbe aktiv.'),
(1, 'Koordinationsleiter', 'koordination', 'Schnelligkeit, Koordination', 'Koordinationsleiter', 'Verschiedene Schrittfolgen, Qualität vor Tempo.'),
(1, 'Tischtennis Beinarbeit', 'koordination', 'Beinarbeit', 'Tischtennisplatte', 'Seitliche Schritte und Ausfallschritte in Spielposition.'),
(1, 'Hüftbeuger-Dehnung', 'mobilitaet', 'Hüftbeuger', '–', 'Halbkniestand, Becken aufrichten, Hüfte nach vorne.'),
(1, 'Brustwirbelsäulen-Rotation', 'mobilitaet', 'Brustwirbelsäule', '–', 'Seitlage, oberen Arm aufdrehen, Blick folgt der Hand.'),
(1, 'Deep Squat Hold', 'mobilitaet', 'Hüfte, Sprunggelenk', '–', 'Tiefe Hocke halten, Fersen am Boden.'),
(1, 'Handgelenk-Mobilisation', 'mobilitaet', 'Handgelenke', '–', 'Vor Stütz- und Handstandtraining: Kreisen, Dehnen in alle Richtungen.'),
(1, 'Auslaufen & Dehnen', 'cooldown', 'Ganzkörper', '–', '5–10 min lockere Bewegung und statisches Dehnen der beanspruchten Muskulatur.');

INSERT IGNORE INTO `migrations` (`filename`) VALUES ('008_trainings_ernaehrungsplaene.sql');
