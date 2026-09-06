-- ============================================================================
-- KBForms — Migration V1 « durcissement » (31/08/2026)
-- À exécuter sur une base EXISTANTE. Une installation fraîche depuis
-- kbforms.sql contient déjà ces colonnes.
--   mysql -u kbforms -p kbforms < migrations/2026-08-31_v1_hardening.sql
-- Idempotent-ish : relancer échouera sur "Duplicate column" — sans danger.
-- ============================================================================

-- Thème 2 — Gouvernance des données
ALTER TABLE `forms`
  ADD COLUMN `response_retention_days` INT(11) DEFAULT NULL
  COMMENT 'Purge des réponses au-delà de N jours (NULL = illimité)'
  AFTER `confirmation_message`;

-- Thème 3 — Anti-spam sur la soumission publique
ALTER TABLE `forms`
  ADD COLUMN `require_captcha` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Exiger un CAPTCHA sur le formulaire public'
  AFTER `response_retention_days`;

ALTER TABLE `responses`
  ADD COLUMN `ip_hash` VARCHAR(64) DEFAULT NULL
  COMMENT 'SHA-256 salé de l''IP (rate-limit, jamais l''IP en clair)'
  AFTER `user_id`;
ALTER TABLE `responses`
  ADD INDEX `idx_form_ip_time` (`form_id`, `ip_hash`, `submitted_at`);

-- Thème 4 — Notifications email
ALTER TABLE `forms`
  ADD COLUMN `notify_emails` VARCHAR(1000) DEFAULT NULL
  COMMENT 'Adresses notifiées par email à chaque nouvelle réponse (séparées par virgule)'
  AFTER `require_captcha`;

-- Semaines précédentes (si pas déjà présent) — avatars, invitations, reset MDP
-- ALTER TABLE `users` ADD COLUMN `avatar_data` MEDIUMTEXT DEFAULT NULL AFTER `job_title`;
-- (voir kbforms.sql pour password_resets et form_invitations)
