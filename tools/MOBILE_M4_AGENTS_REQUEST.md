## Complément M4b — identifiants agent envoyés par e-mail + régénération (2026-09-05)

Demande utilisateur : on ne pouvait plus revoir les identifiants d'un agent
après création, et rien n'était envoyé à l'agent. Livré côté web :

| Volet | État | Détail |
|---|---|---|
| Schéma : `form_agents.email` | ✅ | `migrations/2026-09-05_agent_email.sql` (appliqué) + report `kbforms.sql`. `NOT NULL DEFAULT ''` — les agents créés avant ce lot restent valides. |
| E-mail **obligatoire** à la création | ✅ | `POST /forms/{id}/agents` exige désormais `email` (format validé, sinon `400 code=invalid_email`). Les identifiants sont envoyés automatiquement à cette adresse (`MailService`, même transport que les invitations collaborateur). |
| `POST /forms/{id}/agents/{agentId}/regenerate-password` | ✅ | Nouveau. Propriétaire/admin uniquement. Génère un nouveau mot de passe, l'affiche **une fois**, le renvoie par e-mail ; l'ancien cesse immédiatement de fonctionner. Le mot de passe en clair n'est jamais stocké — « revoir » = régénérer. |
| `GET /forms/{id}/agents` expose `email` | ✅ | Affiché dans la liste de l'onglet « Agents de terrain ». |
| Réponse de `create` / `regenerate-password` | ✅ | `{ ..., email, email_sent: bool, email_error: ?string }` — l'échec d'envoi ne bloque pas la création, il est signalé dans la modale. |
| UI (`form-settings.html`) | ✅ | Champ e-mail dans « Ajouter un agent », e-mail affiché par ligne, bouton 🔑 « Régénérer le mot de passe ». Modale adaptée (création vs régénération) + confirmation d'envoi. Vérifié en Chrome headless : 0 erreur JS. |
| Tests | ✅ | `tools/test_c1_agents.sh` étendu : e-mail requis (400 sans / invalide), e-mail renvoyé + listé, régénération (propriétaire OK, editor/tiers 403, ancien mdp KO, nouveau mdp OK). 18 suites de régression vertes. |

**Note pour la session mobile** : rien à changer côté mobile pour ce
complément — l'agent reçoit ses identifiants par e-mail et se connecte comme
avant (`POST /agent-login`). Aucun champ de payload modifié. Les domaines
réservés (`.local`, `.test`, `.invalid`, `.example`) ne déclenchent pas
d'envoi réel (tests/démo).

---

## Suivi de livraison (session serveur `kbform-9b`) — ✅ LIVRÉ ET TESTÉ 2026-09-04

| Volet | État | Détail |
|---|---|---|
| Schéma (`form_agents`, `responses.agent_id`) | ✅ | `migrations/2026-09-04_mobile_form_agents.sql` — appliqué sur la base en service — + report `kbforms.sql`. |
| `AuthMiddleware` (jeton agent, revalidation à chaque requête) | ✅ | `optionalAuthenticate()`, `getAgent()`, `validateAgentToken()` (is_published + is_revoked revérifiés côté DB à **chaque** appel, pas qu'à la connexion). |
| `Router` — agents exclus par défaut des routes protégées | ✅ | 5ᵉ paramètre `agentOk` sur `Router::add()` ; seules `GET /forms/{id}/bundle` et `POST /responses/{id}/media` l'activent. Bloque aussi, sans rien coder de spécifique, tous les endpoints de gestion de formulaire préexistants (`/questions`, `/forms/{id}/sections`, etc.) — cf. note sécurité ci-dessous. |
| `POST /agent-login` | ✅ | Module `Agent` (Model/Service/Controller). |
| Gestion des agents (create/list/patch/delete) | ✅ | Réservée propriétaire/collaborateur **admin** (pas *editor*) — testé sur les trois profils. |
| `GET /forms/{id}/bundle` adapté | ✅ | Scope au `form_id` du jeton agent, 403 sinon. |
| `POST /responses` adapté | ✅ | `agent_id` forcé, `user_id` ignoré, `form_id` du payload vérifié, anti-abus (honeypot/rate-limit/captcha) sauté comme pour le lot mobile authentifié. **Fix appliqué en cours de route** : `submitBatch()` (lot) rejette désormais explicitement un jeton agent (403) — sans ce garde-fou il aurait silencieusement créé des réponses `user_id=0`. |
| `POST /responses/{id}/media` adapté | ✅ | Vérifie `response.agent_id === agent du jeton`. |
| UI form-builder (`form-settings.html`, onglet « Agents de terrain ») | ✅ | Liste + création (modale identifiant/mot de passe affichés une seule fois + copier) + révoquer/réactiver + supprimer. Vérifié en Chrome headless : 0 erreur JS. |
| Test `tools/test_c1_agents.sh` | ✅ **10/10 cas verts** | Création/permissions (propriétaire+admin OK, editor+tiers 403), unicité globale de l'identifiant, connexion (échecs 401 + succès), bundle scopé (bon formulaire OK, autre 403), endpoints exclus (`/me/*`, gestion formulaire, gestion agents → 403), soumission (`agent_id` attribué, `user_id` NULL, form_id divergent 403, lot 403), média (propre réponse OK, réponse d'autrui 403), **révocation → refus immédiat** (connexion + jeton déjà émis), **dépublication → refus immédiat** (idem), suppression. |
| Régression | ✅ | 17 suites (`test_a1`…`test_b8`, `test_c1`) toutes vertes — le changement d'`AuthMiddleware`/`Router` ne casse aucun jeton utilisateur existant. |

**Note sécurité (pas demandée par la spec, découverte en implémentant)** : avant ce lot, `QuestionController`/`SectionController` et plusieurs autres endpoints de gestion de formulaire ne vérifiaient déjà pas l'appartenance du formulaire à l'utilisateur du jeton (faille préexistante, hors périmètre). En élargissant `AuthMiddleware::authenticate()` pour accepter aussi un jeton agent, ces endpoints seraient devenus accessibles à un agent — un jeton de bien moindre confiance que celui d'un compte enregistré. Le garde-fou `agentOk` au niveau du `Router` neutralise ce risque sans toucher chaque contrôleur individuellement : par défaut, un jeton agent est refusé (403) sur **toute** route protégée, sauf les deux explicitement listées. Testé au cas 5 de `test_c1_agents.sh`.

---

# Demande serveur/web-builder pour KBForms Mobile — M4 : agents de terrain scopés à une enquête

Contexte : nouvelle idée produit (pas dans le backlog MoSCoW existant), discutée
et cadrée avec l'utilisateur le 2026-09-04. Décrite ici pour que la session
serveur/web-builder puisse l'implémenter — l'app mobile (Flutter,
`/home/heil/Downloads/kbgroup/KBforms-mobile/`) fera sa part une fois ce
contrat livré. Même méthode que M1/M2 : voir `tools/MOBILE_M2_SERVER_REQUEST.md`
pour le précédent contigu et les conventions déjà en place (routes, enveloppe
JSON, migrations datées + report dans `kbforms.sql`, `tools/test_bN_*.sh`).

## Le besoin (reformulé depuis la discussion produit)

Une entreprise (ou une administration — l'exemple donné : un recensement)
recrute des agents de terrain pour **une enquête précise**, souvent des
personnes qui n'ont pas de compte KBForms personnel. Aujourd'hui, la seule
façon de donner accès à un formulaire à quelqu'un est soit un compte KBForms
classique + assignation (M2), soit un lien public anonyme (`share_link`) —
aucun des deux ne convient : le premier suppose que l'agent crée un compte
permanent, le second ne permet pas de savoir qui a collecté quoi.

Le modèle voulu : l'entreprise crée l'enquête, puis **ajoute des agents à
cette enquête précise**. Le système génère pour chacun un **identifiant unique
+ mot de passe**, valables **uniquement pour cette enquête et tant qu'elle
reste publiée**. Côté mobile, l'agent choisit "connexion enquêteur" (au lieu
de la connexion normale), saisit son identifiant + mot de passe, et arrive
directement dans le remplissage de cette enquête — rien d'autre (pas de liste
de formulaires). Chaque réponse collectée est attribuée à cet agent.

Décisions déjà tranchées avec l'utilisateur (ne pas rouvrir sans lui) :
- "Période de disponibilité" = `forms.is_published` tel quel, **aucune** date
  de début/fin à ajouter. Dépublier le formulaire coupe immédiatement l'accès
  de tous ses agents.
- Identifiant + mot de passe **générés automatiquement par le système**,
  jamais choisis par l'entreprise ni par l'agent.
- Un agent est scopé à **un seul** formulaire — pas de notion d'agent
  multi-enquêtes, pas de compte "entreprise possède des enquêteurs" au sens
  large. Si la même personne travaille sur deux enquêtes, elle a deux
  identifiants distincts, sans lien entre eux.
- Pas d'auto-inscription : seul le propriétaire/admin du formulaire crée des
  agents (pas de code que l'agent saisirait lui-même).

## Schéma

Nouvelle table `form_agents` :

```sql
CREATE TABLE form_agents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  form_id INT NOT NULL,
  identifiant VARCHAR(32) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(255) NOT NULL COMMENT 'Nom donné par l''entreprise pour reconnaître l''agent (ex. "Jean Dupont", "Agent Nord-1")',
  is_revoked TINYINT(1) NOT NULL DEFAULT 0,
  created_by_user_id INT NOT NULL,
  last_login_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_form_agents_identifiant (identifiant),
  FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`identifiant` unique **globalement** (pas juste par formulaire) : l'écran de
connexion "enquêteur" ne demande pas de quel formulaire il s'agit, le serveur
le résout depuis l'identifiant.

`responses` gagne une colonne, symétrique à `user_id` (déjà nullable) :

```sql
ALTER TABLE responses ADD COLUMN agent_id INT DEFAULT NULL AFTER user_id,
  ADD FOREIGN KEY (agent_id) REFERENCES form_agents(id) ON DELETE SET NULL;
```

`user_id` et `agent_id` sont mutuellement exclusifs en pratique (une réponse
vient soit d'un compte normal, soit d'un agent, soit anonyme via lien public —
les trois `NULL`/rempli restent cohérents avec l'existant, pas de contrainte
CHECK nécessaire, juste une convention applicative).

## Auth : jeton agent, distinct d'un jeton utilisateur

Nouvel endpoint public `POST /agent-login` body `{identifiant, password}` :
- 401 si identifiant inconnu ou mot de passe invalide.
- 403 `code: form_unavailable` si `forms.is_published = 0` **ou**
  `form_agents.is_revoked = 1` (même message, ne pas distinguer côté client
  pourquoi — l'agent n'a pas à savoir si c'est lui spécifiquement qui a été
  coupé ou toute l'enquête).
- Sinon : met à jour `last_login_at`, renvoie
  `{success, token, form_id, form_title, display_name}`.

Le jeton JWT d'un agent est **structurellement différent** d'un jeton
utilisateur normal — pas de `user_id`, à la place :

```json
{"iat": ..., "exp": ..., "agent_id": 42, "form_id": 7}
```

Expiration proposée : **30 jours** (pas de refresh token — contrairement au
JWT utilisateur qui a un access token 1h + refresh avec rotation, un agent qui
expire se reconnecte simplement avec son identifiant/mot de passe, ça reste
un geste léger). Le vrai coupe-circuit n'est de toute façon pas l'expiration
du jeton mais la revérification **à chaque requête protégée** de
`is_published`/`is_revoked` (voir plus bas) : dépublier ou révoquer coupe
l'accès immédiatement, sans attendre l'expiration.

`AuthMiddleware::authenticate()` doit accepter les deux formes de jeton.
Ajouter un `AuthMiddleware::getAgent(): ?array` symétrique à `getUser()` —
`getUser()` renvoie `null` pour un jeton agent, `getAgent()` renvoie `null`
pour un jeton utilisateur normal. Sur **chaque** requête authentifiée par un
jeton agent, revalider `form_id` correspond bien au formulaire visé par la
route ET que le formulaire est toujours publié ET que l'agent n'est pas
révoqué (401/403 sinon) — pas seulement à la connexion.

## Endpoints existants à adapter (pas de duplication)

Plutôt que de dupliquer bundle/remplissage/soumission pour les agents, adapter
les 3 endpoints mobiles existants pour accepter **aussi** un jeton agent :

- `GET /forms/{id}/bundle` : si `getAgent()` non nul, exiger
  `agent['form_id'] === (int)$id` (403 sinon). Comportement inchangé sinon.
- `POST /responses` : si `getAgent()` non nul, forcer `agent_id` sur la ligne
  créée (jamais `user_id`), et vérifier que le `form_id` du payload
  correspond au formulaire de l'agent (403 sinon — un agent ne peut PAS
  soumettre pour un autre formulaire que le sien).
- `POST /responses/{id}/media` : inchangé une fois la réponse déjà créée par
  un agent légitime (le contrôle a eu lieu à la création).

Endpoints à exclure explicitement pour un jeton agent (401/403, pas de sens
pour ce cas d'usage) : `GET /me/forms`, `GET /me/assignments`,
`POST /me/devices`, tout endpoint de gestion de formulaire.

## Gestion des agents (propriétaire/admin du formulaire uniquement)

- `POST /forms/{id}/agents` body `{display_name}` (propriétaire/admin, 403
  sinon) → génère `identifiant` (ex. 8 caractères alphanumériques, à
  re-tirer en cas de collision vu l'unicité globale) + mot de passe (ex. 10
  caractères, aléatoire, lisible — pas de caractères ambigus 0/O/1/l), hash
  bcrypt stocké, renvoie
  `{success, agent_id, identifiant, password, display_name}`. **Le mot de
  passe en clair n'est renvoyé qu'à cet instant** — aucun endpoint ne le
  redonne jamais après (comme un mot de passe normal).
- `GET /forms/{id}/agents` (propriétaire/admin) →
  `{success, agents:[{id, identifiant, display_name, is_revoked, created_at,
  last_login_at, response_count}]}` — `response_count` = `COUNT(*)` sur
  `responses` pour cet `agent_id` (utile pour que l'entreprise voie qui
  collecte quoi, sans ouvrir chaque réponse).
- `PATCH /forms/{id}/agents/{agentId}` body `{is_revoked: bool}`
  (propriétaire/admin) → révoque/réactive un agent précis sans toucher aux
  autres ni au formulaire. `{success, agent_id, is_revoked}`.
- (optionnel, utile mais pas bloquant) `DELETE /forms/{id}/agents/{agentId}`
  → suppression définitive (les réponses déjà soumises gardent `agent_id`
  jusqu'à la suppression, puis passent à `NULL` via `ON DELETE SET NULL` —
  cohérent avec `user_id` sur un compte supprimé).

## UI form-builder web (propriétaire/admin)

Un onglet/panneau "Agents de terrain" sur la page de gestion d'un formulaire :
liste des agents (nom, identifiant, statut actif/révoqué, nb de réponses),
bouton "Ajouter un agent" (juste un nom à saisir) → affiche l'identifiant +
mot de passe générés **une seule fois**, dans une boîte de dialogue avec un
avertissement explicite ("notez-les maintenant, ils ne seront plus jamais
affichés") + bouton copier. Bouton révoquer/réactiver par agent.

## Ce que mobile fera une fois ce contrat livré (pour information, pas une demande)

- Écran de connexion : bascule "Connexion normale" / "Connexion enquêteur"
  (identifiant + mot de passe au lieu d'email + mot de passe).
- `POST /agent-login`, stockage du jeton agent séparément de la session
  utilisateur normale (`AuthStorage` a déjà une session unique — un jeton
  agent remplace toute session existante, pas de multi-session simultanée).
- Nouvel état d'auth distinct (`AuthenticatedAsAgent(formId, displayName)`),
  routage direct vers l'écran de remplissage de `formId` — pas de liste de
  formulaires, pas de tableau de bord, pas de réglages autres que
  déconnexion. Le mode kiosque existant (`lib/features/lock/`) verrouille
  déjà l'app sur un seul formulaire avec sortie protégée — réutilisable en
  grande partie plutôt que reconstruit.
- `POST /responses` avec le jeton agent (le serveur attribue `agent_id`
  automatiquement, rien à changer dans le payload envoyé par le mobile).
- Gestion de l'expiration/révocation : sur 401/403 avec jeton agent, retour
  direct à l'écran de connexion avec message clair ("cette enquête n'est
  plus disponible" plutôt qu'une erreur réseau générique).

## Tests attendus

`tools/test_cN_agents.sh` (même style que `test_b*`) : création d'agent,
connexion agent, bundle scopé au bon formulaire (403 sur un autre), soumission
avec attribution `agent_id`, révocation → connexion refusée immédiatement,
dépublication du formulaire → connexion refusée immédiatement, unicité
globale de l'identifiant, 403 sur les endpoints `/me/*` avec un jeton agent.
