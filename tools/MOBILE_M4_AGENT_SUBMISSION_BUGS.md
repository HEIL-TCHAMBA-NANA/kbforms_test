# Bugs bloquants — mode "agent de terrain" (M4) côté mobile (2026-09-05)

Contexte : suite à la livraison serveur du M4 (`tools/MOBILE_M4_AGENTS_REQUEST.md`,
commit `b76c3f2` côté web), un audit fonctionnel/logique complet a été mené sur
`/home/heil/Downloads/kbgroup/KBforms-mobile` (agent Explore en lecture seule,
aucun fichier mobile modifié). Verdict : l'authentification agent est réelle et
fonctionnelle, mais le parcours de collecte lui-même est cassé de bout en bout.
Aucun agent de terrain ne peut aujourd'hui remplir et faire aboutir une réponse.

Le reste du périmètre mobile (M1/M1.5/M2 : auth normale, cache offline,
assignations, push, signature, audio/vidéo, champs calculés, cascade, groupes
répétables) a été vérifié comme réellement implémenté et fonctionnel — voir
suite complète `flutter test` : 154 tests passés, 0 échec (exécutée côté web
le 2026-09-05). Ces deux bugs sont isolés au mode agent.

---

## Bug 1 — CRITIQUE : soumission impossible pour un jeton agent

**Constat** : `sync_service.dart` n'envoie jamais que l'enveloppe de lot
`{"responses":[...]}` vers `POST /responses`, quel que soit le type de session
(normale ou agent). Or côté serveur (`ResponseController.php:185-194`), ce
format aiguille systématiquement vers `submitBatch()`, qui **rejette
explicitement tout jeton agent en 403** :
> "L'envoi groupé n'est pas disponible pour un jeton agent."

C'est un choix serveur assumé et testé (`tools/test_c1_agents.sh`), pas un bug
côté web. Le format attendu pour un agent est la soumission **unitaire** :
`POST /responses` avec `{form_id, answers:[...]}` **sans** l'enveloppe
`"responses"` — exactement le format que `ResponseController::submitResponse()`
route vers son chemin `optionalAuthenticate()` (accepte anonyme, utilisateur
normal, OU jeton agent — voir `ResponseController.php:88-179`). Le contrat
initial (`MOBILE_M4_AGENTS_REQUEST.md`, section "Ce que mobile fera") précisait
déjà : *"POST /responses avec le jeton agent... rien à changer dans le payload
envoyé par le mobile"* — c'est-à-dire le format déjà utilisé pour un
utilisateur normal en soumission directe, pas le format batch offline-first.

**Conséquence** : `FillController.submit()` finalise dans `ResponseQueue`
comme pour un utilisateur normal, `SyncService` la pousse ensuite via
l'enveloppe batch → 403 à chaque tentative → la réponse ne quitte jamais la
file locale, retry infini.

**Piste de correctif** (à valider côté mobile) : dans `SyncService`, détecter
la session agent (`AgentAuthStorage`/`AuthenticatedAsAgent`) et, pour ce cas,
poster chaque réponse due individuellement en `POST /responses` avec le corps
`{form_id, answers, client_uuid?, ...meta}` (sans enveloppe), en traitant la
réponse `{success, response_id}` au lieu de `{success, results:[...]}`.

---

## Bug 2 — CRITIQUE : le bundle du formulaire n'est jamais synchronisé pour un agent

**Constat** : `FillController.build()` (`fill_controller.dart:171-174`) lit le
formulaire **exclusivement** en local (`formDaoProvider.readForm(arg)`) et
lève `StateError('Formulaire non synchronisé.')` s'il est absent — pas de
repli réseau. Le seul point de l'app qui télécharge réellement le bundle
(`GET /forms/{id}/bundle`, explicitement `agentOk: true` côté serveur —
`Router.php:135`) est `FormSyncController`, utilisé uniquement par
`FormDetailPage`. Or le routeur applicatif mobile **empêche un agent d'accéder
à `FormDetailPage`** (redirection forcée vers `/agent-fill`), et
`AgentFillPage` n'appelle `FormSyncController` nulle part.

**Conséquence** : sur un appareil de terrain fraîchement configuré (le cas
d'usage nominal du M4 — appareil partagé, sans compte personnel préexistant),
un agent qui se connecte pour la première fois tombe directement sur l'erreur
bloquante ci-dessus, sans aucun moyen de récupérer depuis l'écran.

**Piste de correctif** : dans `AgentFillPage`, déclencher explicitement la
récupération du bundle (`GET /forms/{id}/bundle`, déjà `agentOk` côté serveur)
avant d'afficher `FillPage`, de la même manière que `FormSyncController` le
fait pour le parcours normal.

---

## Point mineur (non bloquant)

`GET /forms/{id}/version`, interrogé toutes les 3 min pendant la saisie
(`checkServerVersionNow`), n'est **pas** `agentOk` côté routeur
(`Router.php:136`) — échouera systématiquement en 403 pour un agent. Sans
impact visible aujourd'hui (l'erreur est avalée silencieusement côté mobile),
mais à revoir si un jour cet appel doit avoir un effet pour un agent aussi.
Si besoin, on peut l'ajouter à la liste `agentOk` côté serveur sur demande.

---

## Ce qui n'a PAS besoin d'être touché côté serveur

Tout le contrat serveur nécessaire existe déjà et est testé
(`tools/test_c1_agents.sh`) : `POST /agent-login`, `GET /forms/{id}/bundle`
(agentOk), `POST /responses` en mode unitaire avec jeton agent, `POST
/responses/{id}/media` (agentOk). Les deux bugs ci-dessus sont uniquement une
question d'intégration côté mobile — le commit M4 mobile (`990b614`) n'a
touché que l'auth/le stockage de session, jamais `fill_controller.dart`,
`sync_service.dart` ni `form_sync_controller.dart`.
