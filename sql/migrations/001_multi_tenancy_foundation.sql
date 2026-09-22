-- ============================================================
--  Migration 001: Multi-Tenancy Foundation + RBAC
--  Athletikclub Steiermark – SaaS-Umbau M1
--
--  Was diese Migration macht:
--  1. organizations-Tabelle + eine Organisation ("Athletikclub Steiermark")
--  2. migrations-Tracking-Tabelle
--  3. organization_id auf allen bestehenden Tabellen (Default = 1,
--     damit bestehende Daten automatisch der einen Organisation
--     zugeordnet werden – kein Datenverlust, keine manuelle Zuordnung nötig)
--  4. RBAC: roles / permissions / role_permissions / user_roles
--  5. Seed: 7 Rollen, Basis-Permissions, Rollenzuordnung für alle
--     bestehenden Nutzer (rolle-ENUM -> passende neue Rolle)
--
--  Sicher erneut ausführbar (IF NOT EXISTS / INSERT IGNORE), FKs
--  werden nur einmal gesetzt (bricht beim zweiten Lauf mit Fehler ab,
--  das ist gewollt – zeigt an, dass die Migration schon lief).
-- ============================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------
-- 1. Organizations
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `organizations` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(200)  NOT NULL,
  `slug`        VARCHAR(80)   NOT NULL,
  `status`      ENUM('aktiv','inaktiv') NOT NULL DEFAULT 'aktiv',
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_org_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `organizations` (`id`, `name`, `slug`)
VALUES (1, 'Athletikclub Steiermark', 'athletikclub-steiermark');

-- ----------------------------------------------------------------
-- 2. Migrations-Tracking
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `migrations` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `filename`     VARCHAR(255)  NOT NULL,
  `executed_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_migration_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `migrations` (`filename`) VALUES ('000_baseline_schema.sql');

-- ----------------------------------------------------------------
-- 3. organization_id auf allen bestehenden Tabellen
--    (DEFAULT 1 -> bestehende Zeilen automatisch der einen Org zugeordnet)
-- ----------------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN `organization_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_users_org` (`organization_id`),
  ADD CONSTRAINT `fk_users_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`);

ALTER TABLE `mitglieder_profile`
  ADD COLUMN `organization_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_mitglieder_profile_org` (`organization_id`),
  ADD CONSTRAINT `fk_mitglieder_profile_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`);

ALTER TABLE `trainer_profile`
  ADD COLUMN `organization_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_trainer_profile_org` (`organization_id`),
  ADD CONSTRAINT `fk_trainer_profile_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`);

ALTER TABLE `kurse`
  ADD COLUMN `organization_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_kurse_org` (`organization_id`),
  ADD CONSTRAINT `fk_kurse_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`);

ALTER TABLE `kurs_anmeldungen`
  ADD COLUMN `organization_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_kurs_anmeldungen_org` (`organization_id`),
  ADD CONSTRAINT `fk_kurs_anmeldungen_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`);

ALTER TABLE `umsatz_eintraege`
  ADD COLUMN `organization_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_umsatz_eintraege_org` (`organization_id`),
  ADD CONSTRAINT `fk_umsatz_eintraege_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`);

ALTER TABLE `abrechnungen`
  ADD COLUMN `organization_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_abrechnungen_org` (`organization_id`),
  ADD CONSTRAINT `fk_abrechnungen_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`);

ALTER TABLE `abrechnung_positionen`
  ADD COLUMN `organization_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_abrechnung_positionen_org` (`organization_id`),
  ADD CONSTRAINT `fk_abrechnung_positionen_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`);

ALTER TABLE `dokumente`
  ADD COLUMN `organization_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_dokumente_org` (`organization_id`),
  ADD CONSTRAINT `fk_dokumente_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`);

ALTER TABLE `fortschritt_eintraege`
  ADD COLUMN `organization_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_fortschritt_eintraege_org` (`organization_id`),
  ADD CONSTRAINT `fk_fortschritt_eintraege_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`);

ALTER TABLE `kontakt_anfragen`
  ADD COLUMN `organization_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_kontakt_anfragen_org` (`organization_id`),
  ADD CONSTRAINT `fk_kontakt_anfragen_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`);

ALTER TABLE `partner`
  ADD COLUMN `organization_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_partner_org` (`organization_id`),
  ADD CONSTRAINT `fk_partner_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`);

ALTER TABLE `seiten_inhalte`
  ADD COLUMN `organization_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_seiten_inhalte_org` (`organization_id`),
  ADD CONSTRAINT `fk_seiten_inhalte_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`);

ALTER TABLE `news`
  ADD COLUMN `organization_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_news_org` (`organization_id`),
  ADD CONSTRAINT `fk_news_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`);

ALTER TABLE `aktivitaets_log`
  ADD COLUMN `organization_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_aktivitaets_log_org` (`organization_id`),
  ADD CONSTRAINT `fk_aktivitaets_log_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`);

-- news_gelesen hat keinen eigenen Primärschlüssel-Bedarf für organization_id,
-- da sie ausschließlich über news_id/user_id skopiert wird (beide bereits org-gebunden).

-- ----------------------------------------------------------------
-- 4. RBAC: Rollen, Permissions, Zuordnungen
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `roles` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(40)   NOT NULL COMMENT 'z.B. ORGANIZATION_ADMIN',
  `name`        VARCHAR(100)  NOT NULL,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `permissions` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `code`          VARCHAR(60)   NOT NULL COMMENT 'z.B. course.create',
  `beschreibung`  VARCHAR(200)  NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_permission_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_id`        INT UNSIGNED  NOT NULL,
  `permission_id`  INT UNSIGNED  NOT NULL,
  PRIMARY KEY (`role_id`, `permission_id`),
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_roles` (
  `user_id`          INT UNSIGNED  NOT NULL,
  `role_id`          INT UNSIGNED  NOT NULL,
  `organization_id`  INT UNSIGNED  NOT NULL,
  `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `role_id`, `organization_id`),
  CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ur_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: 7 Rollen lt. Pflichtenheft (nicht alle sofort mit UI verknüpft)
INSERT IGNORE INTO `roles` (`code`, `name`) VALUES
  ('SUPER_ADMIN',        'Super Admin'),
  ('ORGANIZATION_ADMIN', 'Organisations-Admin'),
  ('MANAGEMENT',         'Management'),
  ('PROJECT_MANAGER',    'Projektleitung'),
  ('ADMINISTRATION',     'Verwaltung'),
  ('TRAINER',            'Trainer*in'),
  ('CUSTOMER',           'Kunde/Mitglied');

-- Seed: Basis-Permissions (deckt den heute implementierten Funktionsumfang ab)
INSERT IGNORE INTO `permissions` (`code`, `beschreibung`) VALUES
  ('users.read',        'Nutzerliste einsehen'),
  ('users.create',      'Nutzer anlegen'),
  ('users.update',      'Nutzer bearbeiten'),
  ('users.delete',      'Nutzer deaktivieren/löschen'),
  ('course.read',       'Kurse einsehen'),
  ('course.create',     'Kurse anlegen'),
  ('course.update',     'Kurse bearbeiten/verwalten'),
  ('course.delete',     'Kurse absagen/löschen'),
  ('participant.read',  'Teilnehmer-/Mitgliederdaten einsehen'),
  ('finance.read',      'Umsatzdaten einsehen'),
  ('settlement.manage', 'Abrechnungen erstellen'),
  ('settlement.approve','Abrechnungen als ausgezahlt markieren'),
  ('content.manage',    'Seiteninhalte (CMS) bearbeiten'),
  ('contact.manage',    'Kontaktanfragen verwalten'),
  ('news.manage',       'News veröffentlichen'),
  ('documents.read',    'Dokumente herunterladen'),
  ('documents.manage',  'Dokumente hochladen/löschen');

-- Rollen-Rechte-Zuordnung
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r, `permissions` p
WHERE r.code = 'ORGANIZATION_ADMIN';

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r, `permissions` p
WHERE r.code = 'SUPER_ADMIN';

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r, `permissions` p
WHERE r.code = 'TRAINER'
  AND p.code IN ('users.create','course.read','course.create','course.update',
                 'participant.read','finance.read','documents.read','documents.manage');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r, `permissions` p
WHERE r.code = 'CUSTOMER'
  AND p.code IN ('course.read','documents.read');

-- Bestehende Nutzer den neuen Rollen zuordnen (org_id = 1, Migration der alten rolle-ENUM)
INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`, `organization_id`)
SELECT u.id, r.id, 1
FROM `users` u
JOIN `roles` r ON r.code = CASE u.rolle
  WHEN 'admin'    THEN 'ORGANIZATION_ADMIN'
  WHEN 'trainer'  THEN 'TRAINER'
  WHEN 'mitglied' THEN 'CUSTOMER'
END;

INSERT IGNORE INTO `migrations` (`filename`) VALUES ('001_multi_tenancy_foundation.sql');
