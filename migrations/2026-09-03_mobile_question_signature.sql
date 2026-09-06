-- ============================================================================
-- KBForms — Migration « type de question : signature » (03/09/2026, M2 · B4)
-- Nouveau sous-type `signature` : le répondant trace une signature (→ PNG).
-- Web : la valeur est un data URI stocké dans answers.value.
-- Mobile : capture sur canvas tactile, envoi via POST /responses/{id}/media
--          (pipeline média A9, inchangé).
--   /opt/lampp/bin/mysql -u kbforms -p kbforms < migrations/2026-09-03_mobile_question_signature.sql
-- ============================================================================

ALTER TABLE `questions`
  MODIFY `type` enum(
    'short_text','long_text','radio','checkbox','dropdown',
    'date','time','linear_scale','grid','phone','email',
    'signature'
  ) NOT NULL;
