-- ============================================================================
-- KBForms — Migration « mobile / collecte de réponses » (02/09/2026)
-- Prérequis M1 : idempotence des envois (client_uuid) + métadonnées de collecte
-- terrain (device, version app, GPS, durée, localisation simulée).
--   /opt/lampp/bin/mysql -u kbforms -p kbforms < migrations/2026-09-02_mobile_response_collection.sql
-- Idempotent-ish : relancer échoue sur "Duplicate column" — sans danger.
-- ============================================================================

ALTER TABLE `responses`
  ADD COLUMN `client_uuid`   CHAR(36)      DEFAULT NULL COMMENT 'UUID v4 généré sur l''appareil (idempotence des envois)' AFTER `id`,
  ADD COLUMN `device_id`     VARCHAR(64)   DEFAULT NULL COMMENT 'Identifiant d''installation de l''app' AFTER `ip_hash`,
  ADD COLUMN `app_version`   VARCHAR(20)   DEFAULT NULL AFTER `device_id`,
  ADD COLUMN `gps_lat`       DECIMAL(10,7) DEFAULT NULL AFTER `app_version`,
  ADD COLUMN `gps_lng`       DECIMAL(10,7) DEFAULT NULL AFTER `gps_lat`,
  ADD COLUMN `gps_accuracy`  FLOAT         DEFAULT NULL COMMENT 'Précision GPS en mètres' AFTER `gps_lng`,
  ADD COLUMN `started_at`    DATETIME      DEFAULT NULL COMMENT 'Ouverture de la réponse (horloge appareil)' AFTER `gps_accuracy`,
  ADD COLUMN `duration_s`    INT(11)       DEFAULT NULL COMMENT 'Durée de remplissage en secondes' AFTER `started_at`,
  ADD COLUMN `mock_location` TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'Position simulée détectée (remontée, non bloquant en M1)' AFTER `duration_s`,
  ADD UNIQUE KEY `uq_responses_client_uuid` (`client_uuid`);
-- NB : UNIQUE sur colonne NULLABLE → MySQL autorise plusieurs NULL,
--      les réponses web existantes (client_uuid NULL) ne sont pas affectées.
