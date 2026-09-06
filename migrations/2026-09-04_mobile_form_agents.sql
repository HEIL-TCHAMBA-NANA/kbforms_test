-- ============================================================================
-- KBForms — Migration « agents de terrain scopés à une enquête » (04/09/2026, M4 · C1)
-- Une entreprise crée des agents pour UNE enquête précise (recensement, etc.),
-- sans compte KBForms permanent. Identifiant + mot de passe générés par le
-- système, valables tant que le formulaire reste publié et l'agent non révoqué.
--   /opt/lampp/bin/mysql -u kbforms -p kbforms < migrations/2026-09-04_mobile_form_agents.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS `form_agents` (
  `id`                 INT(11)      NOT NULL AUTO_INCREMENT,
  `form_id`            INT(11)      NOT NULL,
  `identifiant`        VARCHAR(32)  NOT NULL COMMENT 'unique globalement — la connexion agent ne précise pas le formulaire',
  `password_hash`      VARCHAR(255) NOT NULL,
  `display_name`       VARCHAR(255) NOT NULL COMMENT 'nom donné par l''entreprise pour reconnaître l''agent (ex. "Jean Dupont")',
  `is_revoked`         TINYINT(1)   NOT NULL DEFAULT 0,
  `created_by_user_id` INT(11)      NOT NULL,
  `last_login_at`      DATETIME     DEFAULT NULL,
  `created_at`         TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_form_agents_identifiant` (`identifiant`),
  KEY `idx_form_agents_form` (`form_id`),
  CONSTRAINT `fk_form_agents_form`    FOREIGN KEY (`form_id`)            REFERENCES `forms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_form_agents_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `responses`
  ADD COLUMN `agent_id` INT(11) DEFAULT NULL COMMENT 'M4 : réponse collectée par un agent de terrain (exclusif de user_id en pratique)' AFTER `user_id`,
  ADD KEY `idx_responses_agent` (`agent_id`),
  ADD CONSTRAINT `fk_responses_agent` FOREIGN KEY (`agent_id`) REFERENCES `form_agents` (`id`) ON DELETE SET NULL;
