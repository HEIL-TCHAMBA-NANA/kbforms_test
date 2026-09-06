-- ============================================================================
-- KBForms — Migration « listes de choix en cascade » (03/09/2026, M2 · B7)
-- Ex. Région → Département → Commune : le choix d'un niveau filtre le suivant.
-- Une question dropdown/radio est liée à une liste hiérarchique et, si elle
-- n'est pas racine, à la question qui fournit son filtre parent.
--   /opt/lampp/bin/mysql -u kbforms -p kbforms < migrations/2026-09-03_mobile_cascade_lists.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS `choice_lists` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `form_id`    INT(11)      NOT NULL,
  `name`       VARCHAR(120) NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_choice_list_form` (`form_id`),
  CONSTRAINT `fk_choice_list_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `choice_list_items` (
  `id`             INT(11)      NOT NULL AUTO_INCREMENT,
  `list_id`        INT(11)      NOT NULL,
  `parent_item_id` INT(11)      DEFAULT NULL COMMENT 'NULL = niveau racine',
  `label`          VARCHAR(255) NOT NULL,
  `value`          VARCHAR(255) NOT NULL,
  `position`       INT(11)      NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_cli_list` (`list_id`),
  KEY `idx_cli_parent` (`parent_item_id`),
  CONSTRAINT `fk_cli_list`   FOREIGN KEY (`list_id`)        REFERENCES `choice_lists` (`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_cli_parent` FOREIGN KEY (`parent_item_id`) REFERENCES `choice_list_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `questions`
  ADD COLUMN `cascade_list_id`            INT(11) DEFAULT NULL AFTER `phone_default_country`,
  ADD COLUMN `cascade_parent_question_id` INT(11) DEFAULT NULL AFTER `cascade_list_id`;
