-- ============================================================================
-- KBForms — Migration « mobile / jetons d'appareil FCM » (03/09/2026, M2 · B3)
-- Enregistrement des jetons Firebase Cloud Messaging pour notifier un
-- enquêteur (nouvelle assignation, formulaire mis à jour…).
--   /opt/lampp/bin/mysql -u kbforms -p kbforms < migrations/2026-09-03_mobile_device_tokens.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS `device_tokens` (
  `id`           INT(11)       NOT NULL AUTO_INCREMENT,
  `user_id`      INT(11)       NOT NULL,
  `token`        VARCHAR(512)  NOT NULL COMMENT 'jeton d''enregistrement FCM',
  `platform`     ENUM('android','ios') NOT NULL DEFAULT 'android',
  `created_at`   DATETIME      NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` DATETIME      NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device_token` (`token`),
  KEY `idx_device_user` (`user_id`),
  CONSTRAINT `fk_device_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
