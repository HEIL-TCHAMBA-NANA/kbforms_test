-- ============================================================================
-- KBForms — Migration « champs calculés » (04/09/2026, M2 · B6)
-- Nouveau type `calculated` : champ en lecture seule dont la valeur est une
-- expression référençant d'autres questions ({q12} + {q13}, age({q4})…).
-- Évaluation CÔTÉ CLIENT (web + mobile) → reste utilisable hors-ligne.
-- Le serveur ne fait que stocker / transmettre l'expression.
--   /opt/lampp/bin/mysql -u kbforms -p kbforms < migrations/2026-09-04_mobile_calculated_fields.sql
-- ============================================================================

ALTER TABLE `questions`
  MODIFY `type` enum(
    'short_text','long_text','radio','checkbox','dropdown',
    'date','time','linear_scale','grid','phone','email',
    'signature','audio','video','calculated'
  ) NOT NULL;

ALTER TABLE `questions`
  ADD COLUMN `calculated_expression` TEXT DEFAULT NULL
    COMMENT 'type calculated : formule, ex. {q12} + {q13} ou age({q4})'
    AFTER `media_max_duration_s`;
