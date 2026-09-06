-- ============================================================================
-- KBForms — Migration « mobile / médias de réponse » (02/09/2026, M1.5)
-- Upload d'une photo prise sur une question, rattachée à une réponse.
-- Stockage en base (comme banner_data/image_data/avatar_data existants),
-- data URI base64, pas de fichier sur disque — cohérent avec le reste du
-- backend (pas de configuration de dossier d'upload / permissions à gérer).
--   /opt/lampp/bin/mysql -u kbforms -p kbforms < migrations/2026-09-02_mobile_response_media.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS `response_media` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `response_id` INT(11)      NOT NULL,
  `question_id` INT(11)      NOT NULL,
  `mime`        VARCHAR(50)  DEFAULT NULL,
  `sha256`      CHAR(64)     DEFAULT NULL COMMENT 'empreinte calculée côté client, pour vérifier l''intégrité',
  `size_bytes`  INT(11)      DEFAULT NULL COMMENT 'taille décodée, informatif',
  `data`        MEDIUMTEXT   NOT NULL COMMENT 'data URI base64 (data:image/...;base64,....)',
  `created_at`  DATETIME     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_response_media_response` (`response_id`),
  KEY `idx_response_media_question` (`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
