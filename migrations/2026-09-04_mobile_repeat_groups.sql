-- ============================================================================
-- KBForms — Migration « groupes de questions répétables » (04/09/2026, M2 · B8)
-- Un bloc de questions répété N fois (ménage → membres, ferme → parcelles).
--  - repeat_groups        : le bloc (label, min/max occurrences), dans une section
--  - questions.repeat_group_id : la question appartient à ce bloc
--  - answers.repeat_index : n° d'occurrence (0..N-1) ; NULL = hors bloc
--   /opt/lampp/bin/mysql -u kbforms -p kbforms < migrations/2026-09-04_mobile_repeat_groups.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS `repeat_groups` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `form_id`    INT(11)      NOT NULL,
  `section_index` INT(11)   NOT NULL DEFAULT 0 COMMENT 'section (position ordinale) qui contient le bloc',
  `label`      VARCHAR(160) NOT NULL,
  `min_repeat` INT(11)      NOT NULL DEFAULT 0,
  `max_repeat` INT(11)      DEFAULT NULL COMMENT 'NULL = pas de plafond',
  `position`   INT(11)      NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_repeat_group_form` (`form_id`),
  CONSTRAINT `fk_repeat_group_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `questions`
  ADD COLUMN `repeat_group_id` INT(11) DEFAULT NULL AFTER `calculated_expression`,
  ADD KEY `idx_question_repeat_group` (`repeat_group_id`),
  ADD CONSTRAINT `fk_question_repeat_group`
    FOREIGN KEY (`repeat_group_id`) REFERENCES `repeat_groups` (`id`) ON DELETE SET NULL;

ALTER TABLE `answers`
  ADD COLUMN `repeat_index` INT(11) DEFAULT NULL AFTER `value`;
