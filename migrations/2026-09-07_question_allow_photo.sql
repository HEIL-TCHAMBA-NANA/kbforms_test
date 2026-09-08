-- M5 (mobile) — pièce jointe photo générique, opt-in par question.
--
-- Avant : l'app mobile affichait une icône caméra sur toutes les questions
-- (crash observé sur certains OEM). Désormais l'icône n'apparaît que si la
-- question porte allow_photo = 1, choisi explicitement à la création.
--
-- NOT NULL DEFAULT 0 : toutes les questions existantes et nouvelles sont
-- opt-out par défaut — aucun changement de comportement sans activation.

ALTER TABLE `questions`
  ADD COLUMN `allow_photo` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'M5 : autorise la capture/jointure de photos sur cette question au remplissage (opt-in)'
    AFTER `media_max_duration_s`;
