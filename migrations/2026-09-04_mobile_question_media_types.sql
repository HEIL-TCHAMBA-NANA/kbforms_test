-- ============================================================================
-- KBForms — Migration « types de question audio / vidéo » (04/09/2026, M2 · B5)
-- Prolonge la signature (B4) : verbatim audio, séquence vidéo courte.
-- Capture → data URI base64, stockée dans response_media (pipeline A9).
-- Web public : média joint inline à POST /responses (answers[].media).
-- Mobile : POST /responses/{id}/media (inchangé).
--   /opt/lampp/bin/mysql -u kbforms -p kbforms < migrations/2026-09-04_mobile_question_media_types.sql
-- ============================================================================

ALTER TABLE `questions`
  MODIFY `type` enum(
    'short_text','long_text','radio','checkbox','dropdown',
    'date','time','linear_scale','grid','phone','email',
    'signature','audio','video'
  ) NOT NULL;

ALTER TABLE `questions`
  ADD COLUMN `media_max_duration_s` INT(11) DEFAULT NULL
    COMMENT 'audio/video : durée maximale conseillée côté client (secondes)'
    AFTER `cascade_parent_question_id`;
