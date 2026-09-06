# Déploiement KBForms sur Render (gratuit, sans carte)

Render construit l'image **sur ses serveurs** à partir du dépôt GitHub
(`git@github.com:kbgroupesarl/KBform.git`). Rien à builder ni à pousser
manuellement : `git push`, puis on connecte Render au dépôt.

- **Web** : Render, service Docker, plan Free. **S'endort après 15 min
  d'inactivité** → premier appel après une pause ~30–60 s, puis normal.
- **Base MySQL** : Render n'en fait pas de gratuite → **Aiven** (MySQL 8 réel,
  plan gratuit, sans carte, ~5 Go, TLS obligatoire).
- **E-mails** : Render autorise le port 587 sortant → SMTP Gmail (mot de passe
  d'application), tout en variables d'environnement.

Toute la config passe par des variables d'env — aucun fichier `app/config/*`
à créer sur le serveur.

---

## 1. Base de données — Aiven

1. Compte sur https://aiven.io (sans carte) → **Create service → MySQL** →
   plan **Free** → une région proche.
2. Attendre l'état *Running*. Noter dans l'onglet *Connection information* :
   `Host`, `Port` (≠ 3306), `User` (`avnadmin`), `Password`, `Database name`
   (`defaultdb`), et télécharger le **CA Certificate** (`ca.pem`).
3. Importer le schéma. Aiven tourne sous **MySQL 8 strict** : le dump
   phpMyAdmin (`kbforms.sql`) mélange clés inline et clés différées et ne
   passe pas tel quel (erreurs `sql_require_primary_key`, puis FK vers une
   table sans clé unique). Utiliser le script fourni qui reporte toutes les
   clés étrangères à la fin :
   ```
   python3 tools/prepare_sql_for_managed_mysql.py kbforms.sql /tmp/kbforms_aiven.sql

   docker run --rm -i mysql:8 mysql \
     --host=<AIVEN_HOST> --port=<AIVEN_PORT> \
     --user=avnadmin --password='<AIVEN_PASS>' \
     --ssl-mode=REQUIRED \
     defaultdb < /tmp/kbforms_aiven.sql
   ```
   (le client MariaDB de XAMPP ne gère pas l'auth `caching_sha2_password`
   d'Aiven → passer par l'image `mysql:8` comme ci-dessus.)
4. Vérifier :
   ```
   docker run --rm -i mysql:8 mysql --host=<AIVEN_HOST> --port=<AIVEN_PORT> \
     --user=avnadmin --password='<AIVEN_PASS>' --ssl-mode=REQUIRED \
     defaultdb -e "SHOW TABLES; SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema='defaultdb';"
   ```
   → ~26 tables (`forms`, `questions`, `responses`, `form_agents`, …) et
   ~25 clés étrangères.

## 2. Pousser le code sur GitHub

```
git push origin main
```
(9 commits en attente à l'heure où ce guide est écrit : correctifs IDOR,
jeton mobile 7 j, e-mail agents, `Dockerfile`, `render.yaml`…)

## 3. Créer le service sur Render

**Option A — Blueprint (recommandé)** : Render → **New → Blueprint** →
sélectionner le dépôt → il lit `render.yaml` et crée le service `kbforms`.

**Option B — manuel** : Render → **New → Web Service** → dépôt → *Runtime*
= **Docker** → *Plan* = **Free** → *Health Check Path* = `/templates`.

## 4. Variables d'environnement (Render → service → Environment)

| Variable | Valeur |
|---|---|
| `KBF_BASE_URL` | `https://kbforms.onrender.com` (l'URL que Render attribue — à mettre après la 1ʳᵉ création) |
| `KBF_DB_HOST` | hôte Aiven |
| `KBF_DB_PORT` | port Aiven (ex. `12345`) |
| `KBF_DB_NAME` | `defaultdb` |
| `KBF_DB_USER` | `avnadmin` |
| `KBF_DB_PASS` | mot de passe Aiven |
| `KBF_DB_SSL_NO_VERIFY` | `1` — TLS obligatoire vers Aiven, sans vérifier son CA privé (suffisant pour un pilote ; pour vérifier le cert, voir §5) |
| `KBF_MOBILE_CLIENT_SECRET` | le **même** secret que `app/config/mobile.php` en local |
| `MAIL_DRIVER` | `smtp` |
| `MAIL_HOST` | `smtp.gmail.com` |
| `MAIL_PORT` | `587` |
| `MAIL_ENCRYPTION` | `tls` |
| `MAIL_USERNAME` | ton adresse Gmail |
| `MAIL_PASSWORD` | mot de passe d'application Gmail (16 car., sans espaces) |
| `MAIL_FROM_EMAIL` | ton adresse Gmail |
| `MAIL_FROM_NAME` | `KBForms` |
| `RECAPTCHA_SITE_KEY` / `RECAPTCHA_SECRET_KEY` | clés reCAPTCHA v2 pour `*.onrender.com` — ou **laisser vides** pour désactiver le CAPTCHA (l'app mobile n'est pas concernée) |

Après la 1ʳᵉ mise en ligne, Render affiche l'URL définitive : la reporter dans
`KBF_BASE_URL` et redéployer (sinon les liens d'invitation / `/f/{token}`
pointeront mal).

## 5. Certificat CA d'Aiven (vérification TLS propre)

Render → service → **Environment → Secret Files** → *Add Secret File* :
- *Filename* : `aiven-ca.pem`
- *Contents* : coller le contenu de `ca.pem` téléchargé en §1

Render le monte dans `/etc/secrets/aiven-ca.pem`. Alors, remplacer
`KBF_DB_SSL_NO_VERIFY` par `KBF_DB_SSL_CA=/etc/secrets/aiven-ca.pem` (le code
vérifie le certificat serveur dès qu'un CA est fourni). Pour un pilote,
`KBF_DB_SSL_NO_VERIFY=1` suffit.

## 6. Vérifications

```
curl -i https://kbforms.onrender.com/templates      # 401 attendu (pas 5xx)
```
(le tout premier appel après une pause peut mettre ~1 min : Render réveille le
service.)

Puis navigateur : `https://kbforms.onrender.com/` → créer un compte, un
formulaire, le publier, ouvrir `/f/...`, soumettre une réponse, la voir dans
« Réponses ».

## 7. App mobile

Dépôt `KBforms-mobile` : URL de base de l'API →
`https://kbforms.onrender.com`, même `client_secret` que
`KBF_MOBILE_CLIENT_SECRET`. Prévenir la session mobile.

⚠ Avec le plan Free qui s'endort, le premier `POST /responses` d'une session
de collecte après une longue pause peut échouer par timeout. Le jeton d'accès
mobile de 7 j (déjà en place) et la file d'attente hors-ligne côté app
absorbent l'essentiel, mais pour une collecte terrain intensive il faudra
passer au plan payant Render (~7 $/mois, plus de mise en veille) ou à une VM.

## 8. Limites du plan Free Render

- Mise en veille après 15 min sans trafic ; ~750 h/mois d'exécution.
- 512 Mo RAM, CPU partagé (le `Dockerfile` fixe `memory_limit=256M`).
- Disque **éphémère** : ne rien stocker sur le disque du conteneur. Ici c'est
  OK — les médias vont en base (`response_media`), les logs sont éphémères par
  nature. Surveiller la taille de la base Aiven (5 Go).
- Build ~3–6 min à chaque `git push` sur `main`.
