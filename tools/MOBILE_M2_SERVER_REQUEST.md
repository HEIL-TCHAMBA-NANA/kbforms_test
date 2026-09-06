# Demandes serveur/web-builder pour KBForms Mobile — M2

Contexte : l'app mobile (Flutter, `/home/heil/Downloads/kbgroup/KBforms-mobile/`)
a fini tout ce qui était faisable côté mobile seul pour M2. Les items
restants nécessitent des ajouts ici, côté serveur (`KBform/`) et/ou
form-builder web. Ce document liste ce qu'il faut, dans l'ordre de valeur
pour le mobile, avec les conventions déjà en place à respecter (voir
`tools/MOBILE_M1_SERVER_CHANGES.md` pour le précédent contigu de ce
travail — même méthode : une migration datée + un test `tools/test_aN_*.sh`
+ une entrée dans ce changelog par item).

**Conventions du dépôt à suivre** (déjà en place, ne pas réinventer) :
- Routes : `app/src/Core/Router.php`, `$router->add(METHOD, "/path", [$controller, "method"], protected)`. Placeholders `{id}`. Pas de préfixe `/api`.
- Auth : routes `protected=true` passent automatiquement par `AuthMiddleware::authenticate()` (JWT Bearer). Contrôleur lit `AuthMiddleware::getUser()`.
- Secret client mobile (`X-KBF-Client-Secret`, `MobileClient::isTrusted()`) : aujourd'hui seulement utilisé pour bypasser le reCAPTCHA sur `/login`/`/register` — pas un mécanisme d'auth API général, ne pas s'appuyer dessus pour ces nouveaux endpoints (JWT suffit, ils seront tous `protected=true`).
- Schéma : migration datée dans `migrations/YYYY-MM-DD_description.sql` (pas d'outil de migration, appliqué à la main) **+** report manuel du même DDL dans `kbforms.sql` (dump de bootstrap).
- Enveloppe JSON : pas 100% homogène dans le dépôt, mais pour du **nouveau** code, suivre le style `{"success": bool, ...champs à plat, "error"?: string}` (pas de wrapper `data`) — c'est le style déjà utilisé pour les endpoints mobiles existants (`FormController::getBundle`).

---

## Suivi de livraison (session serveur `kbform-9b`)

| # | Item | État | Détail |
|---|------|------|--------|
| 3 | Sync différentielle `?since=` | ✅ **livré** | déjà couvert par A1 (`GET /me/forms?since=`), revérifié `tools/test_a3_me_forms.sh` (cas 4-5 verts) le 2026-09-03. |
| 1 | Assignation d'enquêtes | ✅ **livré** 2026-09-03 | module `Assignment`, table `form_assignments`, 5 routes, `tools/test_b2_assignments.sh` (11 cas verts). Détail ci-dessous. |
| 2 | Notifications push (FCM) | ✅ **livré** 2026-09-03 | module `Notification`, table `device_tokens`, `POST`/`DELETE /me/devices`, déclenchement à l'assignation. Handshake FCM HTTP v1 testé avec la vraie clé de service (projet `kbforms-d2ba6`). Reste : delivery sur un vrai appareil (app Flutter). |
| 8b | Question `signature` | ✅ **livré** 2026-09-03 | enum `questions.type` élargi + palette form-builder + pad signature sur le formulaire public. `tools/test_b4_signature.sh` (7 cas verts). Détail ci-dessous. |
| 6 | Listes de choix en cascade | ✅ **livré** 2026-09-04 | module `ChoiceList` (2 tables, 7 routes, import) + wiring `questions.cascade_*` + `bundle`/formulaire public enrichis + **UI form-builder** (choix de liste, question parente, import collé) + **rendu public** (niveaux filtrés en direct). `tools/test_b7_cascade.sh` (12 cas verts). Détail ci-dessous. |
| 8 | Questions audio / vidéo | ✅ **livré** 2026-09-04 | enum `+audio,+video`, `questions.media_max_duration_s`, média joint **inline** à `POST /responses` (`answers[].media`) pour le web public, `POST /responses/{id}/media` inchangé pour le mobile, UI form-builder + capture publique (`<input capture>`). `tools/test_b5_audio_video.sh` (9 cas). |
| 7 | Champs calculés | ✅ **livré** 2026-09-04 | type `calculated`, `questions.calculated_expression`, validation de formule serveur (grammaire étroite), évaluateur **client** (`age()`, `round()`, `abs()`, arithmétique) — web + à réimplémenter mobile. `tools/test_b6_calculated.sh` (7 cas). |
| 5 | Groupes de questions répétables | ✅ **livré** 2026-09-04 | table `repeat_groups`, `questions.repeat_group_id`, `answers.repeat_index`, 4 routes CRUD, bornes min/max (422), `bundle`/public enrichis, `GET /responses/{id}` groupé, **export CSV** une colonne par occurrence (`Libellé [n]`), UI form-builder + bloc répétable public (ajouter/retirer). `tools/test_b8_repeat_groups.sh` (11 cas). |

**Lot M2 serveur : terminé.** Régression complète A1–A9 + B2–B8 verte. Front-end
(form-builder + formulaire public) vérifié dans un vrai Chrome headless : aucune
erreur JS, tous les nouveaux types de question rendus.

### ✅ #1 — Assignation d'enquêtes (livré 2026-09-03)

**Schéma** — `migrations/2026-09-03_mobile_form_assignments.sql` (appliqué sur la base
`kbforms` en service) + report dans `kbforms.sql` (après `form_invitations`).
Table `form_assignments` (`id`, `form_id`, `assigned_to_user_id`,
`assigned_by_user_id`, `status` enum `pending|in_progress|done`, `note`,
`created_at`, `updated_at`). `UNIQUE(form_id, assigned_to_user_id)` → un
ré-assignement fait un upsert (note rafraîchie, statut remis à `pending`).
3 FK `ON DELETE CASCADE` (form + 2× users) → purge automatique.

**Code** — nouveau module `app/src/Modules/Assignment/` :
- `Models/AssignmentModel.php` — `assign()` (upsert), `unassign()`, `listForForm()`
  (enquêteur joint), `listForUser()` (formulaire joint, formulaires supprimés exclus),
  `findById()`, `updateStatus()`.
- `Services/AssignmentService.php` — règles : **assigner / retirer / lister par
  formulaire** réservés au propriétaire **ou** à un collaborateur `admin`
  (`canManage()`) ; **changer le statut** ouvert à l'enquêteur assigné **ou** à un
  superviseur.
- `Controllers/AssignmentController.php` — enveloppe `{success, …, error?}`,
  codes `forbidden`→403 / `not_found`→404 / défaut 400, 401 si pas de Bearer.
- `app/src/Core/Router.php` — 5 routes (toutes `protected`).
- `app/public/index.php` — `require_once` du module.

**Contrat**
- `GET /me/assignments` → `{ success, assignments:[ { id, form_id, title,
  description, status, note, is_published, version, assigned_by_user_id,
  assigned_by_name, created_at, updated_at, form_updated_at } ] }` — l'app filtre
  `/me/forms` sur ces `form_id` (ou affiche tout, au choix du client).
- `GET /forms/{id}/assignments` (superviseur) → `{ success, assignments:[ { id,
  assigned_to_user_id, assignee_email, assignee_name, status, note, … } ] }` ; 403 sinon.
- `POST /forms/{id}/assignments` body `{ user_id:int, note?:string }` (superviseur)
  → `{ success, assignment_id, form_id, user_id, status:"pending", message }` ;
  403 si non-superviseur, 404 si formulaire ou utilisateur cible inconnu.
- `DELETE /forms/{id}/assignments/{userId}` (superviseur) → `{ success, removed:bool }`.
- `PATCH /assignments/{id}` body `{ status:"pending|in_progress|done" }`
  (enquêteur assigné ou superviseur) → `{ success, assignment_id, status }` ;
  400 statut invalide, 403 sinon.

**Test** : `tools/test_b2_assignments.sh` — 11 cas (assignation, vues croisées
enquêteur/superviseur/tiers, RBAC 403, statut + persistance, upsert, cascade FK,
401). Tous verts.

### ✅ #2 — Notifications push FCM (livré 2026-09-03)

**Config** — `app/config/fcm.php` *(gitignoré)* + `app/config/fcm.php.example`
*(versionné)* + `app/config/kbforms-fcm-key.json` *(gitignoré, chmod 644)* : clé
de compte de service Firebase, projet **`kbforms-d2ba6`**. Override env
`KBF_FCM_SERVICE_ACCOUNT_JSON`. Sans config valide → push désactivés, endpoints
`/me/devices` opérationnels quand même. `.gitignore` mis à jour.

**Schéma** — `migrations/2026-09-03_mobile_device_tokens.sql` (appliqué) + report
`kbforms.sql`. Table `device_tokens` (`token` VARCHAR(512) UNIQUE, `platform`
enum android/ios, `user_id` FK `ON DELETE CASCADE`, `last_seen_at`).

**Code** — nouveau module `app/src/Modules/Notification/` :
- `Models/DeviceTokenModel.php` — `upsert()` (le jeton unique est réattribué si
  un autre compte le renvoie), `deleteForUser()`, `deleteToken()` (purge d'un
  jeton mort), `tokensForUser()`.
- `Services/PushService.php` — `isConfigured()`, `registerDevice()`,
  `unregisterDevice()`, `notifyAssignment()`, `sendToUser()`. Auth : JWT RS256 →
  jeton OAuth2 (scope `firebase.messaging`, mis en cache) → `POST
  https://fcm.googleapis.com/v1/projects/<project_id>/messages:send`. Même
  principe que `GoogleSheetsService`. **Best-effort : ne lève jamais.** Un jeton
  rejeté (`UNREGISTERED` / `INVALID_ARGUMENT` / 404) est purgé automatiquement.
- `Controllers/DeviceController.php`.
- `AssignmentService::assign()` — appelle `PushService::notifyAssignment()` dans
  un `try/catch` (n'interrompt jamais l'assignation).
- `Router.php` + `index.php` — module chargé avant `Assignment`.

**Contrat**
- `POST /me/devices` body `{ fcm_token:string, platform?:"android"|"ios" }`
  → `{ success, registered:true, push_enabled:bool }` ; 400 sans jeton, 401 sans Bearer.
- `DELETE /me/devices` body `{ fcm_token:string }` → `{ success, removed:bool }`.
  ⚠️ **écart vs. la demande initiale** (`DELETE /me/devices/{token}`) : le routeur
  ne capture que `[a-zA-Z0-9]+` dans l'URL, or un jeton FCM contient `:` `_` `-`
  → jeton passé dans le corps.
- Déclenchement : à chaque `POST /forms/{id}/assignments`, l'enquêteur assigné
  reçoit une notif `{ title:"Nouvelle enquête assignée", body:<titre + note>,
  data:{ type:"assignment", form_title } }`.

**Test** : `tools/test_b3_push_devices.sh` — 7 cas : endpoints + 400/401,
réattribution de jeton, delete, **handshake FCM réel** (jeton OAuth2 obtenu avec
la clé de service), **envoi réel** vers un jeton bidon (FCM répond
`INVALID_ARGUMENT` → jeton purgé : chemin d'envoi + gestion d'erreur validés),
cascade FK. Tous verts. Seul le rendu sur un appareil physique reste à voir avec
l'app Flutter (`google-services.json` côté mobile).

### ✅ #8b — Question `signature` (livré 2026-09-03)

**Schéma** — `migrations/2026-09-03_mobile_question_signature.sql` (appliqué) +
report `kbforms.sql` : `questions.type` enum + `'signature'`. **Aucun autre
changement serveur** : la valeur d'une signature transite comme une chaîne (web :
data URI PNG dans `answers.value` ; mobile : `POST /responses/{id}/media`,
pipeline A9 inchangé). Pas de validation de type côté `ResponseService`.

**Form-builder web** — `app/public/assets/js/form-builder.js` : entrée
`{ type:'signature', label:'Signature' }` dans `BB_TYPES` + branche d'aperçu
(zone « Zone de signature »). Pas d'options avancées. `changeType()` /
`PUT /questions/{id}` gèrent le type sans cas particulier.

**Formulaire public** — `app/public/assets/html/form-public.html` : `renderQuestion`
case `signature` → `<canvas>` tactile (Pointer Events, `touch-action:none`),
bouton « Effacer », export `toDataURL('image/png')` dans `answers[qid]` à la fin
du tracé ; si déjà signé → `<img>` + « Recommencer ». Validation « obligatoire »
couverte par le contrôle générique (valeur non vide).

**Contrat** : `POST /questions` / `PUT /questions/{id}` acceptent
`"type":"signature"`. `GET /forms/{id}/bundle` et `/questions` renvoient le type.
La valeur suit le canal choisi par le client (answer texte ou média).

**Test** : `tools/test_b4_signature.sh` — 7 cas : création, persistance enum,
bundle, `/questions`, soumission web (valeur data URI stockée + relue via
`GET /responses/{id}`), parcours média mobile, conversion de type. Tous verts.

### 🛠️ #6 — Listes de choix en cascade (serveur livré 2026-09-03)

**Schéma** — `migrations/2026-09-03_mobile_cascade_lists.sql` (appliqué) + report
`kbforms.sql` :
- `choice_lists (id, form_id, name, created_at)` — FK form `ON DELETE CASCADE`.
- `choice_list_items (id, list_id, parent_item_id NULL, label, value, position)` —
  FK list + FK parent (self) `ON DELETE CASCADE` → supprimer un item purge sa
  descendance.
- `questions.cascade_list_id`, `questions.cascade_parent_question_id` (NULL = racine).

**Code** — nouveau module `app/src/Modules/ChoiceList/` (Model / Service /
Controller). Édition réservée au **propriétaire ou collaborateur editor/admin**
(`canManage`) ; lecture ouverte à tout collaborateur. Wiring `cascade_*` ajouté
dans `QuestionController` (create + update), `QuestionModel` (INSERT + SET
dynamique + SELECT de `getQuestionsByForm`). `FormService::getBundle` renvoie
désormais `choice_lists` (les listes réellement référencées par une question du
formulaire, avec tous leurs items à plat).

**Contrat**
- `GET  /forms/{id}/choice-lists` → `{ success, choice_lists:[ { id, name,
  items:[ { id, parent_item_id, label, value, position } ] } ] }`
- `POST /forms/{id}/choice-lists` `{ name }` → `{ success, list_id }`
- `PUT  /choice-lists/{id}` `{ name }` · `DELETE /choice-lists/{id}`
- `POST /choice-lists/{id}/items` `{ label, value?, parent_item_id?, position? }`
  → `{ success, item_id }` ; 400 si `parent_item_id` hors liste.
- `POST /choice-lists/{id}/import` `{ rows:[ ["Région","Département","Commune"], … ] }`
  → construit l'arbre (dédoublonné par (parent,label)), **remplace** le contenu,
  `{ success, items_created }`. Pour l'import CSV des référentiels.
- `DELETE /choice-lists/{id}/items/{itemId}` → `{ success, removed }`.
- Question `dropdown`/`radio` : champs `cascade_list_id`,
  `cascade_parent_question_id` acceptés à la création et au `PUT` (null pour
  détacher). Exposés par `/forms/{id}/questions` et le `bundle`.
- `bundle` : clé `choice_lists` (listes utilisées uniquement).

**Test** : `tools/test_b7_cascade.sh` — 11 cas : CRUD liste (+ 403 tiers),
import hiérarchique (3 lignes → 7 items, arbre vérifié Nord>Garoua>Pitoa),
ajout item racine + rejet parent hors-liste, cascade FK (supprimer « Nord » →
descendance purgée), wiring question racine + enfant, exposition dans
`/questions` et `bundle`, détachement du parent, 401, cascade à la suppression
du formulaire. Tous verts.

**UI form-builder** (`form-builder.js`) — pli « Avancé » d'une question
`dropdown`/`radio` : sélecteur de liste (+ bouton « nouvelle liste » / suppression),
sélecteur de la question parente (parmi les autres questions liées à la même
liste), zone d'import (coller des lignes `Niveau1;Niveau2;…`, séparateur `;` ou
tabulation → `POST /choice-lists/{id}/import`), résumé de la liste
(entrées / racines / niveaux). Quand une liste est liée, l'éditeur d'options
manuelles disparaît ; l'aperçu affiche « ↳ options depuis « X », filtrées par
« Y » ». `changeType()` détache la liste si on quitte dropdown/radio.

**Rendu public** (`form-public.html`) — `GET /f/{token}` renvoie `choice_lists` ;
une question en cascade se peuple depuis la liste, filtrée par l'item choisi au
niveau parent (`cascadeItemsFor()` remonte la chaîne). Tant que le parent n'est
pas répondu, le champ est désactivé (« Choisissez d'abord le niveau précédent »).
Changer un niveau réinitialise ses enfants (`setCascadeAnswer()`).

**Mobile (Flutter)** : le `bundle` porte déjà `choice_lists` + `cascade_*` sur
les questions — même logique de filtrage à réimplémenter côté app.

**Test** : `tools/test_b7_cascade.sh` — 12 cas (dont le formulaire public via
`GET /f/{token}` : `choice_lists` présent + `cascade_list_id` sur la question).

### ✅ #8 — Questions audio / vidéo (livré 2026-09-04)

**Schéma** — `migrations/2026-09-04_mobile_question_media_types.sql` (appliqué) +
`kbforms.sql` : `questions.type` `+audio,+video` ; `questions.media_max_duration_s`
(durée max conseillée côté client, informative).

**Code**
- `ResponseService` : `uploadMedia()` refactorisé → `insertMedia()` privé partagé
  (valide la data URI, décode, plafonne à 8 Mo, crée `response_media`).
- `submitResponse()` : chaque `answers[]` peut porter `media` (data URI) +
  `media_mime` → média rattaché **inline**, sans appel séparé ni authentification
  (nécessaire pour un répondant anonyme). `POST /responses/{id}/media` (A9)
  inchangé pour le collecteur mobile.
- `QuestionController`/`QuestionModel` : `media_max_duration_s` câblé comme
  `phone_default_country` (create/update/select/bundle).
- `form-builder.js` : types Audio/Vidéo dans la palette + champ « durée max ».
- `form-public.html` : `<input type="file" accept="audio/*|video/*" capture>`,
  contrôle taille (8 Mo) + durée (via `<audio>`/`<video>` metadata), aperçu,
  envoi via `answers[].media`.

**Contrat** : `POST`/`PUT /questions` acceptent `"type":"audio"|"video"` +
`media_max_duration_s`. `POST /responses` accepte `answers[i].media` (data URI
`data:<mime>;base64,…`) + `answers[i].media_mime` optionnel.

**Test** : `tools/test_b5_audio_video.sh` — 9 cas (enum, durée, bundle,
média inline créé dans `response_media`, relecture via `GET /responses/{id}`,
média invalide ignoré sans casser la soumission, pipeline mobile, conversion).

### ✅ #7 — Champs calculés (livré 2026-09-04)

**Schéma** — `migrations/2026-09-04_mobile_calculated_fields.sql` (appliqué) :
`questions.type` `+calculated` ; `questions.calculated_expression TEXT`.

**Code**
- `QuestionController::validCalcExpr()` — grammaire étroite : `{qN}`, nombres,
  `+ - * / ( )`, fonctions `age|round|abs`, parenthèses équilibrées, pas
  d'opérateur en tête/fin. Formule invalide → 400.
- `QuestionModel` : `calculated_expression` câblé (create/update/select/bundle).
- **Aucune évaluation serveur** (doit marcher hors-ligne) — le serveur stocke et
  transmet la formule ; le client calcule.
- `form-builder.js` : type « Champ calculé » + éditeur de formule + liste des
  `{qN}` référençables (insertion en un clic) + aperçu.
- `form-public.html` : évaluateur local (`calcResolve` remplace `age({q})` et
  `{qN}`, `calcEval` traite `round()`/`abs()` puis `calcArith` = shunting-yard
  `+ - * / ()` avec moins unaire). Champ affiché en lecture seule, recalculé à
  chaque changement (`refreshCalculatedDom`) et à la soumission.
  **À réimplémenter à l'identique côté Flutter.**

**Contrat** : `POST`/`PUT /questions` avec `"type":"calculated"` exigent une
`calculated_expression` valide. Exposée par `/questions` et le `bundle`.

**Test** : `tools/test_b6_calculated.sh` — 7 cas (création, `age()`, 5 formules
invalides rejetées, exposition, stockage de la valeur calculée par le client,
PUT invalide/valide, effacement au changement de type).

### ✅ #5 — Groupes de questions répétables (livré 2026-09-04)

**Schéma** — `migrations/2026-09-04_mobile_repeat_groups.sql` (appliqué) +
`kbforms.sql` :
- `repeat_groups (id, form_id, section_index, label, min_repeat, max_repeat, position)` — FK form `ON DELETE CASCADE`.
- `questions.repeat_group_id` — FK `repeat_groups` **`ON DELETE SET NULL`** (supprimer un groupe rend ses questions normales).
- `answers.repeat_index` — n° d'occurrence (0..N-1), NULL hors groupe.

**Code** — nouveau module `app/src/Modules/RepeatGroup/` (Model / Service /
Controller). Édition = propriétaire ou collaborateur editor/admin.
- `ResponseModel::addAnswer()` + `getAnswersByResponse()` portent `repeat_index`.
- `ResponseService::submitResponse()` — appelle `RepeatGroupService::validateSubmission()`
  **avant** l'insertion : compte les `repeat_index` distincts par groupe, refuse
  si `< min_repeat` (`code:repeat_min`) ou `> max_repeat` (`code:repeat_max`).
- `ResponseController` — ces codes → **HTTP 422**.
- `ResponseService::getResponseDetail()` — `answers[]` incluent `repeat_index`.
- `FormService::getBundle()` + `getPublicForm()` — clé `repeat_groups` (avec
  `question_ids` de chaque groupe).
- `QuestionController`/`QuestionModel` — `repeat_group_id` câblé.
- **Export CSV** (`ExportService` + `AnalyticsModel::getRawResponses`) — une
  colonne par occurrence : `« Libellé [1] »`, `« Libellé [2] »`…
- `form-builder.js` — bandeau « Groupe répétable » par section (créer / éditer
  bornes / supprimer) + sélecteur « Dans « … » » sous chaque champ.
- `form-public.html` — bloc répétable : N occurrences, boutons Ajouter/Retirer
  (bornés par min/max), réponses en clés composites `qid#idx`, validation par
  occurrence, décalage des réponses à la suppression d'une occurrence.

**Contrat**
- `GET  /forms/{id}/repeat-groups` → `{ success, repeat_groups:[ { id, label,
  section_index, min_repeat, max_repeat, question_ids:[…] } ] }`
- `POST /forms/{id}/repeat-groups` `{ label, section_index?, min_repeat?, max_repeat? }` → `{ success, group_id }`
- `PUT  /repeat-groups/{id}` `{ label?, min_repeat?, max_repeat?, section_index? }` (max ≥ min sinon 400)
- `DELETE /repeat-groups/{id}` → `{ success, removed }`
- `POST /questions` / `PUT /questions/{id}` : `repeat_group_id` (null pour détacher).
- `POST /responses` : `answers[i].repeat_index` (int, 0-based) pour les réponses
  d'un membre de groupe. Trop peu / trop d'occurrences → **422**.
- `bundle` : clé `repeat_groups`.

**Test** : `tools/test_b8_repeat_groups.sh` — 11 cas (CRUD + 403, wiring,
2 occurrences enregistrées, bornes min/max en 422, relecture par `repeat_index`,
export CSV `[1]`/`[2]`, `max<min` rejeté, FK SET NULL à la suppression, 401).

---

## 1. Assignation d'enquêtes (Should Have) — priorité la plus haute pour le mobile

**Pourquoi** : aujourd'hui l'enquêteur voit tous ses formulaires ; le
superviseur ne peut pas dire "toi tu fais celui-ci, sur ce périmètre".
Bloque aussi : formulaires assignés à moi, notifications push
("nouvelle assignation"), et à terme l'optimisation d'itinéraire.

**Existant à réutiliser** : le module `Collaboration`
(`app/src/Modules/Collaboration/`, tables `form_collaborators` /
`form_invitations`) gère déjà le partage de formulaire par rôle — c'est le
primitif le plus proche, mais ce n'est PAS ça : collaboration = droits sur
le formulaire (owner/viewer/editor/admin), assignation = tâche de collecte
donnée à quelqu'un. Nouvelle table nécessaire.

**Schéma** (nouvelle migration) :
```sql
CREATE TABLE form_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  form_id INT NOT NULL,
  assigned_to_user_id INT NOT NULL,
  assigned_by_user_id INT NOT NULL,
  status ENUM('pending','in_progress','done') NOT NULL DEFAULT 'pending',
  note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE CASCADE,
  KEY idx_assigned_to (assigned_to_user_id, status)
);
```
(adapter les noms de colonnes `users`/FK au schéma réel si différent — je
n'ai pas le detail exact de la table `users`.)

**Endpoints** :
- `GET /me/assignments` (protected) → liste des assignations de
  l'utilisateur connecté, avec le formulaire joint (au moins `form_id`,
  `title`, `status`). Réponse : `{"success": true, "assignments": [...]}`
- `POST /forms/{id}/assignments` (protected, réservé owner/admin du
  formulaire) → body `{"user_id": int, "note"?: string}`, crée
  l'assignation.
- `DELETE /forms/{id}/assignments/{userId}` (protected) → retire
  l'assignation.
- (optionnel mais utile) `PATCH /assignments/{id}` → changer `status`.

**Mobile consommera** : `GET /me/assignments` pour filtrer `/me/forms` à
ce qui est réellement assigné (garder `/me/forms` tel quel pour la
rétrocompatibilité — le mobile choisit côté client d'afficher tout ou
juste l'assigné).

---

## 2. Notifications push (Should Have)

**Pourquoi** : prévenir l'enquêteur d'une nouvelle assignation ou d'un
formulaire mis à jour, sans qu'il ait à ouvrir l'app.

**Dépend de** : #1 (le déclencheur principal est une nouvelle
assignation).

**Schéma** :
```sql
CREATE TABLE device_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  fcm_token VARCHAR(255) NOT NULL,
  platform ENUM('android','ios') NOT NULL DEFAULT 'android',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_token (fcm_token),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

**Endpoints** :
- `POST /me/devices` (protected) → body `{"fcm_token": string, "platform": "android"}`, upsert (remplace si déjà enregistré pour un autre user — un device peut changer de compte).
- `DELETE /me/devices/{token}` (protected) — à la déconnexion côté mobile.

**Déclenchement serveur** : envoyer via FCM (clé serveur Firebase à
configurer côté `app/config/`, comme `mobile.php`) au moment de
`POST /forms/{id}/assignments` (#1) et, si souhaité, à toute mise à jour
significative d'un formulaire assigné. Pas besoin d'un système de queue
sophistiqué pour démarrer — envoi synchrone best-effort au même endroit
que `MailService` est déjà appelé dans `ResponseService.php`.

**Mobile fournira le SDK/token** une fois l'endpoint prêt — je m'en
occuperai côté Flutter (firebase_messaging) séparément, pas besoin de rien
d'autre ici que les 2 endpoints + le déclenchement.

---

## 3. Sync différentielle (Should Have)

**Pourquoi** : aujourd'hui le mobile retélécharge tous ses formulaires à
chaque sync. Un `since` éviterait de retransférer ce qui n'a pas changé.

**Endpoint** :
- `GET /me/forms?since=<ISO8601>` (protected) — même réponse que
  `/me/forms` actuel, mais filtrée sur `forms.updated_at > since` (la
  colonne `updated_at` existe déjà sur `forms`, confirmé). Sans le
  paramètre `since`, comportement identique à aujourd'hui (rétrocompatible).

C'est le plus simple des 4 — un seul paramètre de requête optionnel sur un
endpoint qui existe déjà.

---

## 4. Conflit brouillon vs formulaire mis à jour — ⚠️ déjà fait côté mobile

Rien à faire ici, juste pour information : le mobile compare déjà
`forms.version` (colonne existante, triggers existants) contre la version
du brouillon local. Aucune action serveur requise.

---

## 5. Groupes répétables / roster (Should Have) — plus gros chantier

**Pourquoi** : répéter un bloc de questions N fois (ex. "membres du
ménage", "parcelles").

**Nécessite les deux côtés** :
- **Schéma** : `questions.repeat_group_id` (nullable, self-référence ou
  nouvelle table `repeat_groups(id, form_id, section_id, label,
  min_repeat, max_repeat)`) + les réponses doivent porter un index
  d'occurrence (`answers.repeat_index INT NULL DEFAULT NULL`).
- **Form-builder web** (`app/public/assets/js/form-builder.js`) : UI pour
  marquer une section/bloc comme répétable, définir min/max.
- Le rendu mobile suit une fois le modèle de données fixé — je m'en
  occupe une fois que la forme du JSON `bundle` inclut cette info.

**Recommandation** : le plus gros morceau des 4 restants, à faire en
dernier ou à découper (ex. d'abord juste "répéter une section entière",
pas de répétition imbriquée).

---

## 6. Listes de choix en cascade (Should Have)

**Pourquoi** : Région → département → commune, où le 2e choix filtre selon
le 1er.

**Nécessite les deux côtés** :
- **Schéma** : une table de listes hiérarchiques, ex.
  ```sql
  CREATE TABLE choice_lists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
  );
  CREATE TABLE choice_list_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    list_id INT NOT NULL,
    parent_item_id INT NULL,
    label VARCHAR(255) NOT NULL,
    value VARCHAR(255) NOT NULL,
    FOREIGN KEY (list_id) REFERENCES choice_lists(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_item_id) REFERENCES choice_list_items(id) ON DELETE CASCADE
  );
  ```
  + `questions.cascade_list_id`, `questions.cascade_parent_question_id`
  (quelle question fournit le filtre parent).
- **Form-builder web** : UI pour créer/éditer une liste hiérarchique et la
  lier à une question dropdown/radio.
- Bundle JSON (`GET /forms/{id}/bundle`) doit inclure les listes utilisées
  par le formulaire.

---

## 7. Champs calculés (Should Have)

**Pourquoi** : ex. âge depuis une date de naissance, total d'une série de
nombres.

**Nécessite les deux côtés** :
- **Schéma** : `questions.calculated_expression TEXT NULL` (l'expression,
  syntaxe à définir — recommandation : un sous-ensemble simple type
  `{q12} + {q13}` ou une syntaxe existante si le web-builder en a déjà une
  ailleurs).
- **Form-builder web** : UI pour écrire/valider l'expression en
  référençant d'autres questions du formulaire.
- Le calcul lui-même : recommandation forte — **exécuté côté mobile**
  (Dart), pas serveur, pour rester utilisable hors-ligne. Le serveur n'a
  qu'à stocker/transmettre l'expression, pas l'évaluer.

---

## 8. Questions audio / signature / vidéo (Should Have / Could Have)

**Pourquoi** : verbatim (audio), consentement (signature), au-delà de la
photo déjà supportée.

**Nécessite les deux côtés** :
- **Schéma** : `ALTER TABLE questions MODIFY type enum('short_text',
  'long_text','radio','checkbox','dropdown','date','time',
  'linear_scale','grid','phone','email','audio','signature','video')
  NOT NULL;` — migration + report dans `kbforms.sql:326`.
  Pas de colonnes supplémentaires nécessaires a priori : `response_media`
  (déjà existante, gère déjà des médias par réponse/question) peut
  probablement porter audio/vidéo tel quel — à vérifier si `mediumtext
  data` (base64) tient pour de la vidéo (probablement pas au-delà de
  quelques secondes ; à évaluer si une limite de taille/durée est
  nécessaire côté validation).
- **Form-builder web** (`form-builder.js:39-51`, tableau `BB_TYPES`) :
  ajouter les 3 entrées + les branches de preview/édition correspondantes
  (lignes ~158-190 et ~272-280).
- **Backend** (`QuestionController.php`, `QuestionModel.php`,
  `QuestionService.php`) : si signature/audio/vidéo ont des métadonnées
  propres (ex. `max_duration_s` pour audio), suivre le pattern des
  branches `if ($type === 'linear_scale')` déjà en place.
- **Mobile** : je gère la capture (audio via microphone, signature via
  canvas tactile, vidéo via caméra) et l'upload une fois le type
  disponible côté serveur/builder — pas besoin de rien de plus ici.

**Suggestion de découpage** : signature est la plus simple des 3 (pas de
capture temps réel, juste un dessin → PNG, réutilise le pipeline média
existant tel quel). Commencer par elle si tu veux livrer vite.

---

## Récapitulatif — ordre suggéré si fait un par un

1. **Sync différentielle** (#3) — le plus simple, gain immédiat.
2. **Assignation** (#1) — débloque le push et structure le travail terrain.
3. **Notifications push** (#2) — dépend de #1.
4. **Question signature** (#8, juste ce sous-type) — réutilise le pipeline média existant.
5. Le reste (#5 groupes répétables, #6 cascade, #7 calculés, #8 audio/vidéo) — plus gros, à planifier séparément.

Une fois un item livré côté serveur/web, dis-le-moi (ou laisse ce fichier
à jour avec un ✅) et je fais la partie mobile qui va avec.
