# KBForms — changements serveur pour le M1 de l'app mobile

Journal des prérequis serveur (cf. `MOBILE_HANDOFF.md` §4 et le plan mobile).
Ces changements ne sont **pas** commités séparément : ils s'ajoutent à l'état de
travail courant du dépôt. Chacun est livré avec un script de test sous `tools/`.

Statut : ✅ fait & testé · 🔜 à faire

---

## ✅ A1 — `POST /auth/refresh` + table `refresh_tokens`

Session mobile hors-ligne : JWT d'accès court (1 h, inchangé) + refresh token
opaque longue durée (30 j), haché en base, **rotation à usage unique**.

**Schéma**
- `migrations/2026-09-02_mobile_refresh_tokens.sql` — nouvelle table `refresh_tokens`
  (`token_hash` CHAR(64) UNIQUE, `expires_at`, `revoked`, `created_at`, `last_used_at`).
- `kbforms.sql` — même définition ajoutée après `password_resets`.

**Code**
- `app/src/Modules/Identity/Models/RefreshTokenModel.php` *(nouveau)* —
  `issue(userId, ttlDays=30)` → `{token, expires_at}` (jeton en clair renvoyé une
  seule fois) ; `findValid(rawToken)` ; `revoke(id)` ; `revokeAllForUser(userId)` ;
  `purge()`.
- `app/src/Modules/Identity/Services/AuthService.php` —
  `+loginTokens(email,pass)`, `+issueTokens(user)`, `+redeemRefreshToken(raw)`
  (révoque l'ancien, émet la nouvelle paire), `+bundleTokens(user)` privé.
  `refresh(jwt)` (chemin Bearer) conservé pour rétro-compat.
- `app/src/Modules/Identity/Controllers/AuthController.php` —
  `login()` et `register()` renvoient désormais
  `{token, refresh_token, refresh_expires_at}` ;
  `refresh()` lit `refresh_token` dans le body JSON, sinon retombe sur
  `Authorization: Bearer <jwt>` (→ `{token}` seul).
- `app/src/Core/Router.php` — `POST /auth/refresh` (public).
- `app/public/index.php` — `require_once` du nouveau modèle.

**Contrat**
- `POST /login` / `POST /register` → `{ success, token, refresh_token, refresh_expires_at, … }`
- `POST /auth/refresh` body `{ "refresh_token": "<opaque>" }`
  → `{ success:true, token, refresh_token, refresh_expires_at }` (rotation)
  → `401 { success:false, error }` si invalide / expiré / déjà utilisé
- `POST /auth/refresh` header `Authorization: Bearer <jwt valide>` (rétro-compat)
  → `{ success:true, token }`

**Test** : `tools/test_a1_refresh.sh` (6 cas, tous verts).
Migration appliquée sur la base `kbforms` en service le 2026-09-02.

---

## ✅ A2 — Chemin d'auth mobile (bypass captcha)

L'app mobile officielle s'identifie par un secret partagé et contourne ainsi le
CAPTCHA sur `/login` et `/register`. reCAPTCHA v2 reste **actif** pour le web.

**Config**
- `app/config/mobile.php` *(gitignoré)* — `['client_secret' => '<hex 64>']`.
- `app/config/mobile.php.example` *(versionné)*.
- `.gitignore` — ajout de `app/config/mobile.php`.
- Alternative : variable d'env `KBF_MOBILE_CLIENT_SECRET`.
- **reCAPTCHA restauré** (`app/config/recaptcha.php` remis depuis `.bak-kbf-keys`).

**Code**
- `app/src/Core/MobileClient.php` *(nouveau)* — `secret()` (config/env, mémoïsé),
  `isTrusted()` (`hash_equals` sur l'en-tête `X-KBF-Client-Secret` ;
  false si aucun secret configuré).
- `app/src/Modules/Identity/Controllers/AuthController.php` — `checkCaptcha()`
  renvoie `true` d'emblée si `MobileClient::isTrusted()`.
- `app/public/index.php` — `require_once` de `MobileClient.php`.

**Contrat**
- En-tête `X-KBF-Client-Secret: <secret>` sur `POST /login` et `POST /register`
  → le CAPTCHA n'est plus exigé. Sans en-tête (ou mauvais secret) → comportement
  inchangé (CAPTCHA requis si `Recaptcha::isEnabled()`).

**Test** : `tools/test_a2_mobile_auth.sh` (4 cas, tous verts).
## ✅ A3 — `GET /me/forms`

Formulaires de l'utilisateur authentifié : possédés **+** ceux où il est
collaborateur. Query `?since=<ISO 8601>` → synchro différentielle.

- `app/src/Core/Router.php` — `GET /me/forms` (protégé).
- `FormController::listMyForms()` — `user_id` depuis le JWT, `?since` validé (`strtotime`).
- `FormService::listMyForms(userId, since?)` — casts int, réécriture d'hôte du `share_link`.
- `FormModel::getFormsForUser(userId, since?)` — `forms` UNION `form_collaborators`,
  colonne `role` (`owner`/viewer/editor/admin), sans `banner_data`.

**Contrat** : `GET /me/forms[?since=…]` (Bearer) →
`[{ id, user_id, title, description, is_published, share_link, version,
created_at, updated_at, role, response_count }]`.

**Test** : `tools/test_a3_me_forms.sh` (5 cas verts).

---

## ✅ A4 — `GET /forms/{id}/bundle`

Tout ce qu'il faut pour cacher un formulaire et le faire remplir hors-ligne, en
un appel. Accès : propriétaire ou collaborateur (`CollaborationService::canAccess`).

- `app/src/Core/Router.php` — `GET /forms/{id}/bundle` (protégé).
- `FormController::getBundle($id)` — 401 / 403 / 404 gérés.
- `FormService::getBundle(formId, userId)` — assemble `form` (`getFormById`),
  `sections` (`SectionModel::getByForm`), `questions` (`QuestionModel::getQuestionsByForm`),
  `conditions` (`ConditionModel::getByForm`), `theme` (`ThemeService::getTheme`),
  `version`.

**Contrat** : `→ { success, form, sections[], questions[], conditions[], theme, version }`.

**Test** : `tools/test_a4_bundle.sh` (4 cas verts).

---

## ✅ A5 — `forms.version` + `GET /forms/{id}/version`

Compteur de version de structure : l'app compare sa version en cache à celle du
serveur avant une saisie.

**Schéma** — `migrations/2026-09-02_mobile_form_version.sql` :
- `forms.version INT NOT NULL DEFAULT 1` (après `font_size`).
- **8 triggers** `AFTER INSERT/UPDATE/DELETE` sur `questions`, `sections`,
  `conditions` → `UPDATE forms SET version = version + 1 WHERE id = <form_id>`
  (bump aussi `forms.updated_at` → alimente le `?since=` de A3).
- `kbforms.sql` — colonne + note triggers.

**Code**
- `ThemeModel::updateTheme()` — ajoute `version = version + 1` au `SET`
  (le thème vit sur `forms` → pas de trigger sans récursion).
- `FormModel::getVersion(formId)` ; `FormService::getFormVersion(formId, userId)`
  (contrôle d'accès) ; `FormController::getFormVersion($id)`.
- `Router.php` — `GET /forms/{id}/version` (protégé).
- `version` exposé aussi par `bundle` (A4) et `/me/forms` (A3).

**Contrat** : `GET /forms/{id}/version` (Bearer) → `{ success, version:int }`.

**Test** : `tools/test_a5_version.sh` (bump vérifié sur section/question CRUD +
thème ; cohérence bundle & /me/forms ; 403 tiers). Migration appliquée.

---

## ✅ A6 — `responses.client_uuid` + idempotence + métadonnées de collecte

**Schéma** — `migrations/2026-09-02_mobile_response_collection.sql` :
`responses` += `client_uuid CHAR(36) UNIQUE`, `device_id`, `app_version`,
`gps_lat/lng` `DECIMAL(10,7)`, `gps_accuracy FLOAT`, `started_at DATETIME`,
`duration_s INT`, `mock_location TINYINT(1) DEFAULT 0`. `kbforms.sql` MAJ.
(UNIQUE sur colonne NULLABLE → réponses web existantes non affectées.)

**Code**
- `ResponseModel::createResponse(formId, userId, ipHash, opts=[])` — INSERT
  dynamique des colonnes fournies (dont `submitted_at` = horodatage appareil).
- `ResponseModel::findIdByClientUuid(uuid)`.
- `ResponseService::submitResponse(..., meta=[])` — si `client_uuid` déjà en base
  → `{ success:true, duplicate:true, response_id }` sans réinsertion ni
  re-déclenchement webhook/email.
- `ResponseController::collectMeta($data)` — extrait + normalise les dates
  (`strtotime` → `Y-m-d H:i:s`) ; passé à `submitResponse`.

**Contrat** : `POST /responses` accepte en plus `client_uuid`, `device_id`,
`app_version`, `gps_lat/lng/accuracy`, `started_at`, `duration_s`,
`mock_location`, `submitted_at`. Doublon `client_uuid` → `{ duplicate:true }`.

**Test** : `tools/test_a6_client_uuid.sh` (4 cas verts). Migration appliquée.

---

## ✅ A7 — `POST /responses` en lot

`POST /responses` accepte désormais `{ "responses": [ {…}, … ] }` (≤ 100),
**authentifié** (Bearer = le collecteur). L'envoi unitaire public est inchangé.

- `ResponseController::submitResponse()` — détecte la clé `responses` → `submitBatch()`.
- `ResponseController::submitBatch(items)` — `AuthMiddleware::authenticate()`,
  cap `BATCH_MAX = 100`, par item : `client_uuid` + `form_id` + `answers` requis,
  `try/catch` isolé, `user_id` = collecteur, pas de honeypot ni rate-limit IP.
  Webhooks/email par réponse créée (hooks `ResponseService` existants).

**Contrat** : `→ { success:true, results:[ { client_uuid, status, response_id?,
error? } ] }` avec `status ∈ created | duplicate | error`. Rejouable (un rejeu →
tous `duplicate`, zéro doublon).

**Test** : `tools/test_a7_batch.sh` (6 cas verts).

---

## ✅ A9 — Upload média + détail d'une réponse (M1.5)

Prérequis backlog M1.5 : « Upload média lié à une réponse » (#12) et
« GET /responses/{id} détaillé » (#16). Stockage en base (data URI base64,
comme `banner_data`/`image_data`/`avatar_data` existants) — pas de dossier
d'upload à configurer côté serveur.

**Schéma** — `migrations/2026-09-02_mobile_response_media.sql` : nouvelle table
`response_media(id, response_id, question_id, mime, sha256, size_bytes, data,
created_at)`. `kbforms.sql` mis à jour (même définition, après `responses`).

**Code**
- `Form/Models/ResponseMediaModel.php` *(nouveau)* — `create()`, `listForResponse()`.
- `Form/Models/ResponseModel.php` — `+getResponseById()`, `+getAnswersByResponse()`.
- `Form/Services/ResponseService.php` — `+userCanAccessResponse()` (le
  collecteur sur sa propre réponse, ou un gestionnaire du formulaire) ;
  `+getResponseDetail()` ; `+uploadMedia()` (valide la data URI, décode,
  plafond 8 Mo, hash si absent).
- `Form/Controllers/ResponseController.php` — `+getResponseDetail($id)`,
  `+uploadMedia($id)`.
- `Router.php` — `GET /responses/{id}` (protégé), `POST /responses/{id}/media` (protégé).

**Contrat**
- `GET /responses/{id}` → `{success, response, answers:[{question_id,value}],
  media:[{id,question_id,mime,sha256,size_bytes,created_at,data}]}` ; 403 si ni
  auteur ni gestionnaire du formulaire, 404 si absente.
- `POST /responses/{id}/media` body `{question_id, mime?, sha256?, data:"data:<mime>;base64,..."}`
  → `{success, media_id}` ; 400 si data invalide/trop lourde, 403/404 comme ci-dessus.

**Test** : `tools/test_a9_response_media.sh` (5 cas verts). Aucune régression A6/A7/A8.

## ✅ A8 — Audit Bearer

`tools/test_a8_bearer_audit.sh` : parse les 68 routes `protected` de `Router.php`,
vérifie pour chacune **401 sans `Authorization`** et **jamais 401 avec un Bearer
valide** (aucune dépendance cookie / `Origin`). Toutes OK. Aucun changement de code.
(Certaines routes renvoient 200 sur un id inexistant — comportement préexistant,
hors périmètre de cet audit.)
