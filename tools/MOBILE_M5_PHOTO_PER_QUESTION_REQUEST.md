## Suivi de livraison (session serveur `kbform-0d`) — ✅ LIVRÉ ET TESTÉ 2026-09-07

| Volet | État | Détail |
|---|---|---|
| Schéma : `questions.allow_photo` | ✅ | `migrations/2026-09-07_question_allow_photo.sql` (appliqué) + report `kbforms.sql`. `TINYINT(1) NOT NULL DEFAULT 0` — toutes les questions existantes et nouvelles opt-out sans activation explicite. |
| `POST /questions` / `PUT /questions/{id}` | ✅ | `allow_photo` accepté dans le body (`filter_var(..., FILTER_VALIDATE_BOOL)`), comme `media_max_duration_s`. Omettre le champ dans un PUT = valeur inchangée (testé). Aucune validation de type : `allow_photo:true` accepté et stocké tel quel sur n'importe quel type (le filtrage signature/audio/video se fait côté client, cf. contrat). |
| `GET /forms/{id}/bundle` | ✅ | `allow_photo` (booléen) présent sur **chaque** question du JSON — via `QuestionModel::getQuestionsByForm()`, donc exposé aussi sur `GET /f/{token}` (formulaire public web, coût marginal, comme suggéré). |
| Export CSV | — | non touché (conforme à la demande : réglage d'interface, pas une valeur de réponse). |
| UI form-builder (`assets/js/form-builder.js`) | ✅ | Interrupteur « Photo autorisée » à côté de « Obligatoire », masqué pour `signature`/`audio`/`video`. Bascule via le setter générique `edit()` (le PUT renvoie déjà tout l'objet question). Remis à `false` si on change le type vers signature/audio/video. Vérifié en Chrome headless : présent sur un `short_text`, absent sur une `signature`, 0 erreur JS. |
| Test `tools/test_c2_allow_photo.sh` | ✅ **5/5 cas** | défaut false, activation à la création, PUT bascule ↔, PUT sans le champ = inchangé, présence sur `/bundle` et `/f/{token}`. |
| Régression | ✅ | Suites `test_a*`…`test_e1` + `test_c2` vertes. |

Côté mobile : rien à faire de plus une fois ceci en place — le champ `allow_photo` est déjà dans la liste blanche du cache bundle et la condition d'affichage de l'icône ; l'option se réactivera d'elle-même sur les questions où le propriétaire l'aura cochée.

---

# Demande serveur/web-builder pour KBForms Mobile — M5 : pièce jointe photo générique, opt-in par question

Contexte : bug utilisateur signalé le 2026-09-07 — l'app mobile affiche une
icône caméra sur **toutes** les questions au remplissage (sauf
signature/audio/vidéo, qui ont déjà leur propre capture dédiée), sans que
rien de tel n'ait été demandé à la création du formulaire. Certains
utilisateurs rapportent un plantage à l'usage sur cette icône (piste
probable : intent caméra natif mal géré sur certains OEM Android, pas
encore isolé avec certitude — voir plus bas).

**Correctif mobile immédiat déjà posé** (élimine le déclencheur du crash
partout, effet indépendant de ce contrat) : l'icône ne s'affiche plus DU
TOUT tant qu'un champ serveur `allow_photo` truthy n'est pas présent sur la
question. Comme ce champ n'existe pas encore côté serveur, l'icône
n'apparaît plus nulle part pour l'instant — régression fonctionnelle
assumée (la fonctionnalité "joindre une photo à n'importe quelle réponse"
n'est plus disponible tant que ce contrat n'est pas livré), mais plus sûre
et conforme à la demande ("uniquement quand c'est pertinent et précisé à
la création").

Fichiers mobiles touchés (pour référence, rien à faire de votre côté ici) :
`lib/core/db/models/question_extras.dart` (`allowPhoto` getter),
`lib/core/db/daos/form_dao.dart` (`allow_photo` ajouté à la liste blanche
qui détermine quels champs du bundle serveur sont mis en cache),
`lib/features/fill/ui/fill_page.dart` (condition d'affichage).

## Réponses aux points à trancher

- **Nom du champ** : `allow_photo` (booléen) — déjà câblé côté mobile sous
  ce nom exact, dans le même style que `media_max_duration_s` (extra
  serré, pas une colonne obligatoire pour tous les types). Merci de
  reprendre ce nom pour éviter un second aller-retour mobile.
- **Photo seule, ou pièce jointe générique ?** Photo seule. Le widget
  mobile concerné (`PhotoAttachments`) capture uniquement des images
  (caméra ou galerie), plusieurs par question, additif — distinct de la
  capture unique dédiée des types `signature`/`audio`/`video`. Pas
  d'interaction avec `media_max_duration_s` (spécifique à la durée
  audio/vidéo, sans rapport). Pas de besoin identifié pour un fichier
  arbitraire (PDF, etc.) — hors périmètre de cette demande.
- **Valeur par défaut** : `0`/`false` pour toutes les questions existantes
  et nouvelles, sauf activation explicite. C'est le cœur du correctif :
  avant ce bug, le mobile l'activait implicitement pour tout le monde ;
  désormais explicite et opt-in, sans exception.
- **Types concernés** : tous les types de question SAUF `signature`,
  `audio`, `video` (déjà leur propre capture média dédiée — l'icône photo
  générique n'a pas de sens là). Le mobile n'affichera de toute façon
  jamais l'icône sur ces trois types, quelle que soit la valeur du champ
  — donc aucune validation serveur stricte n'est nécessaire pour rejeter
  `allow_photo:true` sur `signature`/`audio`/`video` ; accepter et stocker
  tel quel suffit, le filtrage réel se fait côté client.
- **Exposé où** :
  - `POST /questions` et `PUT /questions/{id}` : accepter `allow_photo`
    dans le body (comme `media_max_duration_s`).
  - `GET /forms/{id}/bundle` : inclure `allow_photo` dans chaque question
    du JSON — **c'est le seul endpoint que le mobile lit réellement**
    (jamais `GET /f/{token}` directement, même pour un formulaire public :
    le mobile s'authentifie toujours et passe par `/bundle`).
  - `GET /f/{token}` (formulaire public web) : optionnel de mon point de
    vue mobile — je n'en ai pas besoin — mais si le web envisage un jour
    la même fonctionnalité sur le formulaire public, autant l'exposer
    aussi tant que la colonne existe (coût marginal).
  - Export CSV : **non nécessaire**. C'est un réglage d'interface de
    saisie, pas une valeur de réponse — les photos elles-mêmes transitent
    déjà par `response_media`/`POST /responses/{id}/media`, indépendamment
    de ce booléen.

## Pas encore isolé : la piste crash

Je n'ai pas pu reproduire le plantage natif en conditions contrôlées
(FileProvider `image_picker` correctement fusionné dans le manifest
vérifié, permission `CAMERA` déclarée) — soupçon d'un souci propre à
certains téléphones (Transsion/Infinix observé chez l'utilisateur) sur
l'intent caméra système. Le correctif d'opt-in élimine le déclencheur pour
l'immense majorité des questions (celles où ce n'était jamais voulu), donc
priorité basse pour investiguer plus loin dans l'immédiat — je resterai
attentif si le crash revient sur une question où `allow_photo` est
délibérément activé une fois ce contrat livré.
