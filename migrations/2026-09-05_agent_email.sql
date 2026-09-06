-- Agents de terrain (M4) : adresse e-mail obligatoire, pour envoi automatique
-- des identifiants à la création et lors d'une régénération de mot de passe.
--
-- NOT NULL DEFAULT '' : les lignes existantes (agents créés avant ce lot)
-- restent valides avec un e-mail vide ; l'application impose désormais un
-- e-mail valide à toute nouvelle création.

ALTER TABLE `form_agents`
  ADD COLUMN `email` VARCHAR(255) NOT NULL DEFAULT '' AFTER `display_name`;
