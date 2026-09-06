# SEC-1 — Contrôle d'accès aux formulaires (correctif IDOR) — 2026-09-05

## Le problème

Le cœur historique de l'API ne vérifiait **pas** que le formulaire visé
appartenait à l'utilisateur du jeton. N'importe quel compte enregistré
pouvait, en devinant un id numérique :

- lire / modifier / **supprimer** le formulaire d'un autre utilisateur
  (`GET|PUT|DELETE /forms/{id}`, `POST /forms/{id}/publish`…) ;
- lire ses **réponses** confidentielles, son export CSV, ses analytics
  (`GET /forms/{id}/responses`, `/export/csv`, `/analytics`) ;
- modifier sa structure (`POST /questions`, `PUT /questions/{id}`,
  `/sections/{id}`, `/conditions/{id}`, thème…) ;
- **s'ajouter lui-même comme collaborateur admin** sur le formulaire d'un
  tiers (`POST /forms/{id}/collaborators` avec `{"user_id": <soi>, "role":"admin"}`),
  contournant le flux d'invitation par e-mail ;
- brancher / débrancher ses webhooks et son intégration Google Sheets.

Les modules récents (agents M4, assignations M2, listes de choix, groupes
répétables, et — depuis D1 — le détail/édition/suppression d'une réponse)
faisaient déjà leur propre contrôle. Ce correctif unifie tout le reste.

Reproduit puis re-vérifié en conditions réelles : `tools/test_e1_form_access.sh`.

## La correction

### 1. Garde-fou centralisé : `Core\FormGuard`

Trois niveaux, alignés sur les rôles collaborateur (`viewer` / `editor` /
`admin`) + le propriétaire :

| Niveau | Qui | Pour quoi |
|---|---|---|
| `member` | propriétaire **ou** n'importe quel collaborateur | lecture (formulaire, réponses, analytics, export, questions, sections, conditions, thème, liste des collaborateurs, version) |
| `edit`   | propriétaire **ou** collaborateur `editor`/`admin` | structure (créer/éditer/supprimer questions, sections, conditions ; modifier le thème) |
| `admin`  | propriétaire **ou** collaborateur `admin` | cycle de vie & réglages (PUT/DELETE formulaire, publier, message de confirmation, partage, collaborateurs, invitations, webhooks, Google Sheets, purge des réponses) |

### 2. Application

- **Router** (`Core\Router`) : nouveau 6ᵉ paramètre `formScope` sur
  `add()`. Quand il est posé sur une route `/forms/{id}/...` protégée, le
  dispatch extrait l'id de formulaire (1er paramètre d'URL) et applique
  `FormGuard` avant d'appeler le contrôleur → **403** sinon. Couvre toutes
  les routes dont l'id de formulaire est dans le chemin.
- **Contrôleurs** pour les routes dont l'URL porte un id de sous-ressource
  (pas de formulaire dans le chemin) : `QuestionController`
  (`POST /questions`, `PUT|DELETE /questions/{id}`, `PUT /questions/reorder`),
  `SectionController` (`PUT|DELETE /sections/{id}`, `DELETE /conditions/{id}`),
  `WebhookController` (`DELETE /webhooks/{id}`, `PUT /webhooks/{id}/toggle`).
  Chacun résout le formulaire parent (`QuestionModel::formIdOf`,
  `SectionModel::getById`, `ConditionModel::formIdOf`,
  `WebhookModel::getById`) puis applique `FormGuard`.
- **`FormController`** : `createForm`, `duplicateForm`, `importJson`,
  `importCsv` n'acceptent plus un `user_id` dans le corps — la ressource
  créée appartient à l'utilisateur authentifié. `listForms($userId)`
  (`GET /users/{id}/forms`) refuse (403) si `{id}` n'est pas l'appelant.

### 3. Non concerné (déjà protégé, inchangé)

`GET /forms/{id}/bundle` (contrôle dans le service + jeton agent),
`/forms/{id}/validate` et `/logic/evaluate` (publics, remplissage),
assignations, agents, listes de choix, groupes répétables,
`GET|PUT|DELETE /responses/{id}` (D1).

## Vérifications

- **`tools/test_e1_form_access.sh`** (nouveau) : un tiers → 403 sur ~30
  endpoints (dont la voie d'escalade « s'auto-ajouter admin ») et aucune
  donnée exposée ; viewer → lecture OK / écriture 403 ; editor → structure
  OK / suppression + collaborateurs 403 ; admin + propriétaire → accès
  complet.
- **Régression complète** : 19 suites (`test_a1`…`test_e1`) vertes.
- **Chrome headless (propriétaire)** : `form-builder`, `form-settings`,
  `form-responses`, `form-analytics` se chargent sans erreur — les
  garde-fous ne cassent aucun parcours légitime.

## Fichiers

Backend : `app/src/Core/FormGuard.php` (nouveau), `app/src/Core/Router.php`,
`app/public/index.php` (require), `app/src/Modules/Form/Controllers/FormController.php`,
`app/src/Modules/Form/Controllers/QuestionController.php`,
`app/src/Modules/Form/Models/QuestionModel.php`,
`app/src/Modules/Section/Controllers/SectionController.php`,
`app/src/Modules/LogicEngine/Models/ConditionModel.php`,
`app/src/Modules/Webhook/Controllers/WebhookController.php`.
Tests : `tools/test_e1_form_access.sh` (nouveau).

## Reste hors périmètre

`RoleController` (`POST /roles`, `/permissions`, `/roles/assign`…) n'a
toujours aucun garde-fou serveur `account_type` — déjà signalé dans
`tools/WEB_UI_GAPS_AUDIT.md`, à traiter dans un lot dédié.
