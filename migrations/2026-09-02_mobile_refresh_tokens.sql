-- ============================================================================
-- KBForms — Migration « mobile / refresh tokens » (02/09/2026)
-- Prérequis M1 de l'app mobile : session hors-ligne (JWT d'accès court + refresh
-- token longue durée, rotation à usage unique).
--   mysql -u kbforms -p kbforms < migrations/2026-09-02_mobile_refresh_tokens.sql
-- Idempotent : CREATE TABLE IF NOT EXISTS.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `refresh_tokens` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`      INT(11)      NOT NULL,
  `token_hash`   CHAR(64)     NOT NULL COMMENT 'SHA-256 hex du jeton opaque (jamais le jeton en clair)',
  `expires_at`   DATETIME     NOT NULL,
  `revoked`      TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`   DATETIME     NOT NULL DEFAULT current_timestamp(),
  `last_used_at` DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_refresh_token_hash` (`token_hash`),
  KEY `idx_refresh_user` (`user_id`),
  KEY `idx_refresh_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
