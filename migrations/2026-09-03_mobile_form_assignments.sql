-- ============================================================================
-- KBForms — Migration « mobile / assignation d'enquêtes » (03/09/2026, M2 · B2)
-- Un superviseur assigne un formulaire à un enquêteur précis. Distinct de
-- form_collaborators (droits sur le formulaire) : ici c'est une TÂCHE de
-- collecte confiée à quelqu'un, avec un statut de suivi.
--   /opt/lampp/bin/mysql -u kbforms -p kbforms < migrations/2026-09-03_mobile_form_assignments.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS `form_assignments` (
  `id`                   INT(11)      NOT NULL AUTO_INCREMENT,
  `form_id`              INT(11)      NOT NULL,
  `assigned_to_user_id`  INT(11)      NOT NULL,
  `assigned_by_user_id`  INT(11)      NOT NULL,
  `status`               ENUM('pending','in_progress','done') NOT NULL DEFAULT 'pending',
  `note`                 TEXT         DEFAULT NULL COMMENT 'consigne libre du superviseur (périmètre, quota…)',
  `created_at`           DATETIME     NOT NULL DEFAULT current_timestamp(),
  `updated_at`           DATETIME     DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_form_assignee` (`form_id`, `assigned_to_user_id`),
  KEY `idx_assignment_assignee` (`assigned_to_user_id`, `status`),
  KEY `idx_assignment_form` (`form_id`),
  CONSTRAINT `fk_assignment_form`     FOREIGN KEY (`form_id`)             REFERENCES `forms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assignment_assignee` FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assignment_assigner` FOREIGN KEY (`assigned_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
