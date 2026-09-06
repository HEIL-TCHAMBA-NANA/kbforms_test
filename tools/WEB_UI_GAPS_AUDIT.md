# Audit — fonctionnalités serveur sans UI web (2026-09-05)

Demande : parcourir tout le projet pour lister les fonctionnalités développées
côté serveur mais inaccessibles/inutilisables depuis le frontend web (le
mobile étant exclu du périmètre). Méthode : extraction de toutes les routes de
`app/src/Core/Router.php`, recoupée avec tous les appels `KBF_API.*`/`fetch`
réellement faits par `app/public/assets/html/*.html` et `assets/js/*.js`.

Statut : **✅ les 6 lacunes trouvées sont livrées et testées** (`tools/test_d1_web_gaps.sh`,
+ 18 autres suites en régression, + vérification en Chrome headless réel —
voir « Vérifications » en bas). Reporté dans le backlog Web (nouvelle section
« 🖥️ CORRECTIFS UI WEB », `KBForms_Backlog_MoSCoW_MAJ_2026-08-31.xlsx`).

---

## 1. Assignation d'enquêtes — aucune UI web

**Existait côté serveur** (M2·B2) : `GET/POST /forms/{id}/assignments`,
`DELETE /forms/{id}/assignments/{userId}`, `PATCH /assignments/{id}`.
Zéro UI web malgré ça — seule l'app mobile pouvait consommer `GET /me/assignments`.

**Livré** : nouvel onglet **« Assignations »** dans `form-settings.html`
(propriétaire/collaborateur **admin**, même règle que côté API) :
- liste des assignations en cours (nom, consigne, statut, retirer) ;
- assigner un collaborateur existant (menu déroulant + consigne libre) ;
- changer le statut (`pending`/`in_progress`/`done`) directement depuis un
  menu déroulant sur la ligne.

Pas de nouvel endpoint : uniquement du câblage frontend sur l'API M2 existante.

---

## 2. Réponses avec média — invisibles dans `form-responses.html`

**Constat** : le tableau et le tiroir de détail n'appelaient jamais
`GET /responses/{id}` (qui renvoie `media[]`, table `response_media`) et
affichaient `answers.value` en texte brut échappé — une signature (data URI
PNG) apparaissait comme un bloc de base64 tronqué illisible, et tout média
envoyé via `POST /responses/{id}/media` (mobile/agent) n'apparaissait nulle part.

**Livré** :
- `openDrawer(responseId)` charge désormais `GET /responses/{id}` à la
  demande (au lieu de recevoir la ligne déjà en mémoire) ;
- détection de type par préfixe (`data:image`/`data:audio`/`data:video`) →
  rendu `<img>`/`<audio controls>`/`<video controls>` au lieu de texte brut,
  aussi bien dans la cellule du tableau (miniature/icône) que dans le tiroir ;
- les entrées de `response_media` sont affichées sous chaque question
  concernée (image/audio/vidéo selon le `mime`, sinon lien de téléchargement) ;
- un badge 📎 apparaît dans le tableau quand une réponse a des pièces jointes
  (`media_count`, ajouté à la réponse en masse — voir plus bas).

---

## 3. Groupes répétables — seule la 1ʳᵉ occurrence s'affichait

`form-responses.html` faisait `.find()` (une seule correspondance) sur les
réponses d'une question au lieu de les lister toutes ; les occurrences 2, 3…
d'un groupe répétable étaient silencieusement perdues à l'affichage (l'export
CSV, lui, les gérait déjà correctement avec des colonnes `[1]`, `[2]`…).

**Livré** : `answerOccurrences()` récupère **toutes** les occurrences triées
par `repeat_index` ; le tableau affiche un badge « N réponses », le tiroir
détaille chaque occurrence séparément (« Occurrence 1 », « Occurrence 2 »…).

**Changement serveur nécessaire** : `GET /forms/{id}/responses` (liste en
masse) n'exposait pas `repeat_index` par réponse (seul `GET /responses/{id}`,
utilisé un par un, l'avait). Ajouté à `ResponseModel::getResponsesByForm()` et
`ResponseService::getResponses()`.

---

## 4. Modifier une réponse — endpoint mort côté web

`PUT /responses/{id}` existait et fonctionnait mais rien ne l'appelait —
seule la suppression était câblée dans `form-responses.html`.

**Livré** : bouton « Modifier cette réponse » dans le tiroir → bascule les
champs éditables en formulaire (texte, choix, date/heure, échelle — un widget
adapté par type de question) ; **les groupes répétables, grilles, signatures,
audio/vidéo et champs calculés restent en lecture seule** (les ré-éditer
correctement nécessiterait de dupliquer une bonne partie du rendu de
`form-public.html` — hors scope de ce correctif) et sont renvoyés **tels
quels** à l'enregistrement pour ne pas les perdre (`PUT` remplace tout le jeu
de réponses).

**Faille corrigée au passage** : `ResponseController::updateResponse()`
n'avait **aucun contrôle d'accès** — n'importe quel jeton valide pouvait
réécrire n'importe quelle réponse de n'importe quel formulaire. Ajout du même
contrôle « propriétaire ou collaborateur » que `deleteResponse()`
(`userCanManage`). Testé : `tools/test_d1_web_gaps.sh` cas 3.

---

## 5. Validation serveur pré-soumission — jamais appelée

`POST /forms/{id}/validate` (vérifie les champs obligatoires côté serveur)
n'était appelé nulle part ; `form-public.html` ne fait que sa propre
validation client.

**Livré** : appelé en garde-fou juste avant l'envoi final, en plus de (pas à
la place de) la validation client — utile si le formulaire a changé entre le
chargement de la page et la soumission. Si l'appel échoue (réseau…), on ne
bloque pas l'envoi : la validation client reste la référence dans ce cas.

**Changement serveur nécessaire** : la route était `protected: true` — donc
**401 systématique pour un répondant anonyme**, le cas d'usage principal. Rendue
publique, comme ses deux voisines `POST /responses` et
`POST /forms/{id}/logic/evaluate` qui sont déjà dans ce cas (aucune donnée
sensible exposée : uniquement quels champs, déjà visibles sur le formulaire
public, sont obligatoires).

---

## 6. Permissions personnalisées — pas de création/édition/suppression

`roles.html` listait les permissions et permettait de les (dés)assigner à un
rôle, mais `POST /permissions`, `PUT /permissions/{id}`, `DELETE /permissions/{id}`
n'avaient aucune UI.

**Livré** : dans le panneau de détail d'un rôle — champ + bouton
« + Nouvelle permission », et icônes ✎ (renommer) / ✕ (supprimer) sur chaque
permission du catalogue.

**Note découverte, non corrigée (hors scope)** : `RoleController` n'a **aucun
garde-fou serveur** — la page `roles.html` se protège elle-même côté client
(`account_type` doit être `admin`/`enterprise`), mais un appel direct à
`POST /roles`, `POST /permissions`, `POST /roles/assign`, etc. avec le jeton
de **n'importe quel** compte réussirait. Pré-existant (pas introduit par ce
lot ni par M2/M4), touche aussi `createRole`/`deleteRole`/`assignRole`. À
corriger dans un lot dédié si voulu — même style de garde-fou que
`AgentService::canManage()` ou `ChoiceListService::canManage()`.

---

## Vérifications

- **`tools/test_d1_web_gaps.sh`** (nouveau) : 4 cas — endpoint de validation
  public, `repeat_index`/`media_count` exposés en masse, contrôle de
  propriété + préservation des occurrences répétables sur `PUT /responses/{id}`,
  CRUD permissions.
- **Régression complète** : 19 suites (`test_a1`…`test_d1`) toutes vertes.
- **Chrome headless réel** (pas seulement un lint) :
  - `form-responses.html`, `form-settings.html` (onglet Assignations),
    `roles.html` chargés sans aucune erreur console ;
  - tiroir de réponse **ouvert automatiquement** sur une réponse de test
    portant une signature (data URI), un groupe répétable à 2 occurrences et
    un média uploadé séparément → rendu confirmé : `<img src="data:image/png...">`
    au lieu de texte brut, « Occurrence 1 » / « Occurrence 2 » distinctes,
    « Pièce jointe » affichée ;
  - mode édition déclenché → un seul champ éditable proposé (le `short_text`),
    signature et groupe répétable correctement laissés en lecture seule.

## Fichiers modifiés

Backend : `app/src/Core/Router.php` (route validate publique),
`app/src/Modules/Form/Models/ResponseModel.php` (repeat_index + media_count
en masse), `app/src/Modules/Form/Services/ResponseService.php`
(`updateResponse` avec contrôle d'accès), `app/src/Modules/Form/Controllers/ResponseController.php`.
Frontend : `app/public/assets/html/form-settings.html` (onglet Assignations),
`app/public/assets/html/form-responses.html` (média/occurrences/édition),
`app/public/assets/html/roles.html` (CRUD permissions),
`app/public/assets/html/form-public.html` (appel de validation).
Backlog : `KBForms_Backlog_MoSCoW_MAJ_2026-08-31.xlsx` (nouvelle section,
sauvegarde `.backup-avant-d1-2026-09-05.xlsx`) — au passage, correction d'un
bug de génération du 2026-09-04 (`<sheetData>` manquant, XML non conforme
bien que toléré par LibreOffice) dans `regen_web_backlog.py`.
