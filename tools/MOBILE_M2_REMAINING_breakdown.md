# M2 — chantiers restants (#5–#8) : découpage

> **⚠️ CE DOCUMENT EST OBSOLÈTE — les 4 chantiers sont livrés (2026-09-04).**
> Voir `MOBILE_M2_SERVER_REQUEST.md` (blocs « #5 », « #6 », « #7 », « #8 ») pour
> ce qui a réellement été fait et les tests. Le contenu ci-dessous est conservé
> comme trace de la planification initiale.
>
> Reste éventuel (Could Have, non requis) : rate-limit mobile par appareil,
> journal de synchronisation serveur. Côté mobile Flutter : réimplémenter
> l'évaluateur des champs calculés et le filtrage cascade / bloc répétable.

---

# M2 — chantiers restants (#5–#8) : découpage

Les 4 items restants de `MOBILE_M2_SERVER_REQUEST.md` touchent **tous** le
form-builder web (nouvelle UI de conception) en plus du schéma. Ils ne se
livrent pas d'un bloc. Ordre conseillé et découpage ci-dessous.

Convention inchangée : 1 migration datée + report `kbforms.sql` + test `tools/` +
case ✅ dans `MOBILE_M2_SERVER_REQUEST.md`. Enveloppe JSON `{success, …, error?}`.
Évaluation côté mobile (Dart) quand un calcul/rendu peut rester hors-ligne.

---

## Priorité 1 — #8 audio / vidéo  (le plus petit des 4)

Prolonge B4 (signature) : mêmes points, un `enum` plus large + le pipeline média
A9 déjà en place.

- **Schéma** : `questions.type` += `'audio'`, `'video'`.
  Option : `questions.media_max_duration_s INT NULL` (limite de durée).
- **Backend** : si `media_max_duration_s` ajouté, le porter dans
  `QuestionController` (branche `if ($type === 'audio' || 'video')`, comme
  `linear_scale`) + `QuestionModel`. `ResponseService::uploadMedia()` :
  vérifier que `MAX_MEDIA_BYTES` (8 Mo) suffit ou le relever par type ; la vidéo
  dépasse vite → **imposer une durée courte côté client** plutôt qu'augmenter la
  limite base64 (MEDIUMTEXT = 16 Mo dur).
- **Form-builder** : `BB_TYPES` += 2 entrées + aperçu + (option) champ « durée max ».
- **Public web** : `MediaRecorder` (micro / caméra) → blob → base64 →
  `answers[qid]` ou upload média. Fallback `<input type=file accept=audio/*>`.
- **Mobile** : capture native, upload via `POST /responses/{id}/media`.
- **Test** : `tools/test_b5_audio_video.sh` — création, enum, bundle, upload
  média (audio+vidéo), limite de taille rejetée.

**Effort** : S. **Risque** : taille des blobs — trancher la politique durée/taille avant de coder.

---

## Priorité 2 — #7 champs calculés

Pas de capture : une expression stockée, évaluée à l'affichage.

- **Schéma** : `questions.calculated_expression TEXT NULL`.
  Syntaxe : sous-ensemble simple, réf. par id de question — `{q12} + {q13}`,
  `age({q4})`, `{q5} * 0.2`. Pas de syntaxe existante ailleurs dans le repo → à
  définir ici, documentée dans le `.example` du builder.
- **Backend** : stockage/transmission uniquement. **Ne pas évaluer côté serveur**
  (doit marcher hors-ligne). `bundle` renvoie déjà toutes les questions →
  l'expression passe telle quelle.
- **Form-builder** : éditeur d'expression avec liste des questions référençables +
  validation syntaxique (parenthèses, ids connus) ; aperçu « lecture seule ».
- **Public web + mobile** : un mini-évaluateur partagé (même grammaire des deux
  côtés). Champ affiché non éditable, recalculé à chaque changement d'une
  question source. Réutiliser le pattern d'`scheduleEvaluate()` du public web.
- **Test** : `tools/test_b6_calculated.sh` — création avec expression, bundle
  contient l'expression, expression invalide rejetée (400) ; l'évaluation
  elle-même est testée côté client.

**Effort** : M. **Risque** : divergence des deux évaluateurs → spéc grammaire commune + jeu de cas partagé.

---

## ~~Priorité 3 — #6 listes de choix en cascade~~  →  ✅ LIVRÉ (2026-09-04, B7)

Serveur + form-builder + rendu public, `tools/test_b7_cascade.sh` (12 cas).
Voir `MOBILE_M2_SERVER_REQUEST.md` § « #6 ». **Reste uniquement la reprise côté
app Flutter** (le `bundle` porte déjà `choice_lists` + `cascade_*`).

---
### (spéc d'origine, conservée pour référence)

Région → département → commune : le 2ᵉ choix dépend du 1ᵉʳ.

- **Schéma** :
  ```sql
  CREATE TABLE choice_lists (
    id INT AUTO_INCREMENT PRIMARY KEY, form_id INT NOT NULL, name VARCHAR(100) NOT NULL,
    CONSTRAINT fk_choice_list_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
  );
  CREATE TABLE choice_list_items (
    id INT AUTO_INCREMENT PRIMARY KEY, list_id INT NOT NULL, parent_item_id INT NULL,
    label VARCHAR(255) NOT NULL, value VARCHAR(255) NOT NULL,
    CONSTRAINT fk_cli_list FOREIGN KEY (list_id) REFERENCES choice_lists(id) ON DELETE CASCADE,
    CONSTRAINT fk_cli_parent FOREIGN KEY (parent_item_id) REFERENCES choice_list_items(id) ON DELETE CASCADE
  );
  ```
  + `questions.cascade_list_id INT NULL`, `questions.cascade_parent_question_id INT NULL`.
- **Backend** : CRUD listes (`/forms/{id}/choice-lists` …), et **`bundle`
  enrichi** : inclure les listes utilisées par le formulaire (`choice_lists` +
  items) pour que le mobile filtre hors-ligne.
- **Form-builder** : éditeur d'arbre (liste hiérarchique) + liaison d'une
  question `dropdown`/`radio` à une liste + désignation de la question parente.
- **Public web + mobile** : au changement du parent, filtrer les items par
  `parent_item_id`.
- **Test** : `tools/test_b7_cascade.sh` — CRUD liste, bundle contient la liste,
  filtrage par parent.

**Effort** : L. **Risque** : import en masse des référentiels (communes…) — prévoir un import CSV de liste.

---

## Priorité 4 — #5 groupes de questions répétables (roster)

Répéter un bloc N fois (« membres du ménage », « parcelles »).

- **Schéma** :
  - `repeat_groups (id, form_id, section_id NULL, label, min_repeat INT DEFAULT 0, max_repeat INT NULL)`
    (+ FK form/section `ON DELETE CASCADE`).
  - `questions.repeat_group_id INT NULL` — la question appartient au bloc répétable.
  - `answers.repeat_index INT NULL DEFAULT NULL` — n° d'occurrence (0..N-1).
- **Backend** :
  - `bundle` : exposer les `repeat_groups` + le `repeat_group_id` des questions.
  - `ResponseService` : accepter des réponses portant `repeat_index` ; contrôle
    `min_repeat` à la soumission ; `validateRequiredFields` par occurrence.
  - `GET /responses/{id}` : regrouper les réponses par `repeat_index`.
  - Analytics / export CSV : décider de l'aplatissement (`champ_1`, `champ_2`…
    ou lignes filles). **Impact analytics à cadrer avant.**
- **Form-builder** : marquer une section (ou un bloc) comme répétable + min/max.
- **Public web + mobile** : bouton « + Ajouter », réplication du bloc, suppression
  d'une occurrence.
- **Test** : `tools/test_b8_repeat.sh` — bundle, soumission multi-occurrences,
  min_repeat rejeté, relecture groupée, export.

**Effort** : XL. **Risque** : le plus structurant — touche saisie, validation,
`GET /responses/{id}`, analytics et export. **Commencer par « répéter une
section entière », sans imbrication.** À découper en sous-lots (schéma+bundle →
soumission → relecture → analytics/export).

---

## Récapitulatif

| Item | Effort | Bloque quoi | Sous-lots ? |
|---|---|---|---|
| #8 audio/vidéo | S | — | non |
| #7 calculés | M | — | grammaire d'abord |
| ~~#6 cascade~~ | ~~L~~ | — | ✅ **livré** (B7, 2026-09-04) |
| #5 répétables | XL | analytics, export, relecture | 4 sous-lots |

**Reste après B7 : #8, #7, #5** (ordre conseillé du plus petit au plus gros).

Rien ici n'est requis pour le **MVP terrain (M1)** ni pour le socle M2 déjà livré
(B1–B4). À planifier quand l'app Flutter aura consommé B1–B4 et qu'un besoin
terrain concret le justifie.
