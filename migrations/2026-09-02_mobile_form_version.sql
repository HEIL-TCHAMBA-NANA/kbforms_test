-- ============================================================================
-- KBForms — Migration « mobile / forms.version » (02/09/2026)
-- Prérequis M1 : l'app compare la version d'un formulaire en cache à celle du
-- serveur pour savoir s'il faut re-synchroniser avant une saisie.
--   /opt/lampp/bin/mysql -u kbforms -p kbforms < migrations/2026-09-02_mobile_form_version.sql
-- ============================================================================

-- 1. Colonne compteur (démarre à 1 pour tous les formulaires existants)
ALTER TABLE `forms`
  ADD COLUMN `version` INT(11) NOT NULL DEFAULT 1
  COMMENT 'Incrémenté à chaque modification de structure (sections/questions/conditions/thème)'
  AFTER `font_size`;

-- 2. Triggers : toute modif de structure incrémente forms.version.
--    (Le thème est écrit directement sur `forms` → bump fait côté code dans
--     ThemeModel::updateTheme pour éviter la récursion de trigger.)
DELIMITER $$

DROP TRIGGER IF EXISTS `trg_questions_ai_ver`$$
CREATE TRIGGER `trg_questions_ai_ver` AFTER INSERT ON `questions`
FOR EACH ROW UPDATE `forms` SET `version` = `version` + 1 WHERE `id` = NEW.form_id$$

DROP TRIGGER IF EXISTS `trg_questions_au_ver`$$
CREATE TRIGGER `trg_questions_au_ver` AFTER UPDATE ON `questions`
FOR EACH ROW UPDATE `forms` SET `version` = `version` + 1 WHERE `id` = NEW.form_id$$

DROP TRIGGER IF EXISTS `trg_questions_ad_ver`$$
CREATE TRIGGER `trg_questions_ad_ver` AFTER DELETE ON `questions`
FOR EACH ROW UPDATE `forms` SET `version` = `version` + 1 WHERE `id` = OLD.form_id$$

DROP TRIGGER IF EXISTS `trg_sections_ai_ver`$$
CREATE TRIGGER `trg_sections_ai_ver` AFTER INSERT ON `sections`
FOR EACH ROW UPDATE `forms` SET `version` = `version` + 1 WHERE `id` = NEW.form_id$$

DROP TRIGGER IF EXISTS `trg_sections_au_ver`$$
CREATE TRIGGER `trg_sections_au_ver` AFTER UPDATE ON `sections`
FOR EACH ROW UPDATE `forms` SET `version` = `version` + 1 WHERE `id` = NEW.form_id$$

DROP TRIGGER IF EXISTS `trg_sections_ad_ver`$$
CREATE TRIGGER `trg_sections_ad_ver` AFTER DELETE ON `sections`
FOR EACH ROW UPDATE `forms` SET `version` = `version` + 1 WHERE `id` = OLD.form_id$$

DROP TRIGGER IF EXISTS `trg_conditions_ai_ver`$$
CREATE TRIGGER `trg_conditions_ai_ver` AFTER INSERT ON `conditions`
FOR EACH ROW UPDATE `forms` SET `version` = `version` + 1 WHERE `id` = NEW.form_id$$

DROP TRIGGER IF EXISTS `trg_conditions_ad_ver`$$
CREATE TRIGGER `trg_conditions_ad_ver` AFTER DELETE ON `conditions`
FOR EACH ROW UPDATE `forms` SET `version` = `version` + 1 WHERE `id` = OLD.form_id$$

DELIMITER ;
