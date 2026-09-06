# Backlog Web — Lot « Activation mobile » (V2)

Ces éléments sont du travail **serveur / web-builder** dans ce dépôt (`KBform/`),
tirés des prérequis de l'app mobile (`tools/MOBILE_M1_SERVER_CHANGES.md` et
`tools/MOBILE_M2_SERVER_REQUEST.md`). Ils n'existaient pas dans
`KBForms_Backlog_MoSCoW_MAJ_2026-08-31.xlsx` — à intégrer comme une section
**« Lot activation mobile »** en **V2** (colonnes identiques :
Priorité MoSCoW · Catégorie · Fonctionnalité · Statut actuel · Version cible · Note).

Statut : ✅ Livré · 🛠️ En cours · ❌ À faire

| Priorité | Catégorie | Fonctionnalité | Statut | Version | Note |
|---|---|---|---|---|---|
| 🔴 Must Have | 🔗 API mobile | Jeton d'accès court + refresh token opaque (`POST /auth/refresh`, table `refresh_tokens`, rotation) | ✅ Livré | V2 | A1 · `tools/test_a1_refresh.sh` |
| 🔴 Must Have | 🔗 API mobile | Chemin d'auth mobile de confiance (`X-KBF-Client-Secret`) contournant le CAPTCHA | ✅ Livré | V2 | A2 · reCAPTCHA v2 conservé pour le web · `tools/test_a2_mobile_auth.sh` |
| 🔴 Must Have | 🔗 API mobile | `GET /me/forms` (formulaires possédés + collaborateur) | ✅ Livré | V2 | A3 · `tools/test_a3_me_forms.sh` |
| 🟠 Should Have | 🔗 API mobile | Sync différentielle `GET /me/forms?since=<ISO8601>` | ✅ Livré | V2 | livré avec A3 (M2 · B1) · filtre `forms.updated_at` |
| 🔴 Must Have | 🔗 API mobile | `GET /forms/{id}/bundle` (form + sections + questions + conditions + thème + version, un appel) | ✅ Livré | V2 | A4 · `tools/test_a4_bundle.sh` |
| 🔴 Must Have | 🔗 API mobile | `forms.version` + 8 triggers + `GET /forms/{id}/version` | ✅ Livré | V2 | A5 · bump auto sur CRUD questions/sections/conditions/thème |
| 🔴 Must Have | 🔗 BD mobile | `responses.client_uuid` (idempotence) + métadonnées de collecte (`device_id`, `app_version`, `gps_*`, `started_at`, `duration_s`, `mock_location`) | ✅ Livré | V2 | A6 · doublon `client_uuid` → `{duplicate:true}` · `tools/test_a6_client_uuid.sh` |
| 🔴 Must Have | 🔗 API mobile | `POST /responses` en lot (`{responses:[…]}`, ≤100, Bearer) + horodatage client | ✅ Livré | V2 | A7 · rejouable · `tools/test_a7_batch.sh` |
| 🟠 Should Have | 🔗 API mobile | Upload média lié à une réponse (`POST /responses/{id}/media`) + `GET /responses/{id}` détaillé | ✅ Livré | V2 | A9 · stockage base64 en base · `tools/test_a9_response_media.sh` |
| 🟢 Could Have | 🔗 API mobile | Audit Bearer de toutes les routes protégées (sans navigateur) | ✅ Livré | V2 | A8 · `tools/test_a8_bearer_audit.sh` (73 routes) |
| 🟠 Should Have | 👥 Terrain & équipe | Assignation d'enquêtes : table `form_assignments` + `GET /me/assignments` + `POST`/`DELETE /forms/{id}/assignments` + `PATCH /assignments/{id}` | ✅ Livré | V2 | M2 · B2 (2026-09-03) · module `Assignment` · `tools/test_b2_assignments.sh` |
| 🟠 Should Have | 🔔 Notifications | Enregistrement device FCM (`POST`/`DELETE /me/devices`, table `device_tokens`) + envoi push à l'assignation | ✅ Livré | V2 | M2 · B3 (2026-09-03) · module `Notification` · FCM HTTP v1 · `tools/test_b3_push_devices.sh` |
| 🟠 Should Have | 📋 Types de question | Sous-type `signature` (enum `questions.type` + form-builder web + pad public) | ✅ Livré | V2 | M2 · B4 (2026-09-03) · `tools/test_b4_signature.sh` |
| 🟢 Could Have | 📋 Types de question | Sous-types `audio` / `video` + `media_max_duration_s` + média inline `POST /responses` | ✅ Livré | V2 | M2 · B5 (2026-09-04) · `tools/test_b5_audio_video.sh` (9 cas) |
| 🟠 Should Have | 📋 Types de question | Groupes de questions répétables : `repeat_groups`, `answers.repeat_index`, bornes 422, export CSV `[n]`, UI builder + bloc public | ✅ Livré | V2 | M2 · B8 (2026-09-04) · module `RepeatGroup` · `tools/test_b8_repeat_groups.sh` (11 cas) |
| 🟢 Could Have | 📋 Types de question | Listes de choix en cascade — API/BD + `bundle` + form-builder + rendu public | ✅ Livré | V2 | M2 · B7 (2026-09-04) · `tools/test_b7_cascade.sh` (12 cas) |
| 🟠 Should Have | 📋 Types de question | Champs calculés : type `calculated`, `calculated_expression`, validation serveur, évaluateur client (`age`/`round`/`abs`/arith.) | ✅ Livré | V2 | M2 · B6 (2026-09-04) · `tools/test_b6_calculated.sh` (7 cas) |
| 🟢 Could Have | 🔗 API mobile | Rate-limit / quota spécifique client mobile (par appareil) | ❌ À faire | V2 | complément anti-abus de A2 |
| 🟢 Could Have | 🔗 API mobile | Journal de synchronisation serveur (qui a synchronisé quoi, quand) | ❌ À faire | V3 | traçabilité / support |

## Résumé du lot

| | Livré | En cours | À faire |
|---|---|---|---|
| Must Have | 7 | 0 | 0 |
| Should Have | 9 | 0 | 0 |
| Could Have | 3 | 0 | 2 |
| **Total** | **19** | **0** | **2** |

Le socle M2 est **complet** (B1–B8). Restent seulement 2 « Could Have »
optionnels : rate-limit mobile par appareil, journal de synchronisation serveur
— non requis pour l'app, à faire si un besoin d'exploitation le justifie.

> Quand `KBForms_Backlog_MoSCoW_MAJ_2026-08-31.xlsx` sera régénéré, ces 20 lignes
> forment la section « Lot activation mobile » en V2, et les compteurs des
> feuilles « Résumé MoSCoW » / « Résumé version » sont à réincrémenter en
> conséquence (+14 ✅, +6 ❌ ; +18 V2, +2 V3 selon le tableau).
