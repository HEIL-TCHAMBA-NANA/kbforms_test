# KBForms — accès réseau & mise en production

Rien de ce qui a été configuré ne « casse » quand l'hôte change : les liens
passent tous par `Core\AppUrl`, l'API est en chemins relatifs, et chaque
service tiers se règle dans **un seul fichier** de `app/config/` (tous
gitignorés, un `.example` versionné à côté de chacun).

Trois environnements, une seule variable qui change vraiment : **l'URL de base**.

---

## 1. Ce qui dépend de l'hôte (et ce qui n'en dépend pas)

| Élément | Dépend de l'URL ? | Où ça se règle |
|---|---|---|
| Liens de partage `/f/{token}` | oui — **auto** | `Core\AppUrl` (détection IP LAN, ou `base_url`) |
| Liens d'invitation `/invite?token=` | oui — **auto** | idem |
| Envoi d'emails (SMTP) | non | `config/mail.php` — inchangé partout |
| Connexion Google (OAuth) | **oui — strict** | `config/google.php` + console Google |
| CAPTCHA (reCAPTCHA v2) | oui — liste de domaines | console reCAPTCHA (pas de fichier à changer) |
| Base de données | non | `config/database.php` (127.0.0.1 local au serveur) |
| Auth JWT, CORS, routage | non | — |

---

## 2. La variable maîtresse : `KBF_BASE_URL`

Priorité, du plus fort au plus faible :

1. variable d'environnement **`KBF_BASE_URL`**
2. `app/config/app.php` → `['base_url' => '...']`
3. hôte de la requête s'il n'est pas `localhost`
4. IP LAN détectée automatiquement

En **dev**, on ne met rien → l'app détecte `localhost` puis substitue l'IP LAN
pour les liens partagés. En **prod**, on fixe `KBF_BASE_URL` (ou `app.php`) une
fois et tout en découle.

---

## 3. Ouvrir l'accès aux autres machines du réseau (LAN)

Apache écoute déjà sur toutes les interfaces (`*:80`). Il reste :

```bash
# pare-feu : autoriser le port 80 depuis le réseau local
sudo ufw allow from 192.168.0.0/16 to any port 80 proto tcp
```

Puis, depuis une autre machine : `http://<IP-de-ton-PC>/login`
(récupère l'IP avec `hostname -I`).

Ce qui marche alors depuis les autres postes : formulaires publics, réponses,
invitations, partage, **CAPTCHA** (après ajout du domaine, voir §5), le tout.

**Sauf la connexion Google** : Google refuse les IP privées et le HTTP hors
`localhost`. Sur le LAN elle ne marchera qu'en passant par un tunnel HTTPS
(ngrok / cloudflared) — voir §4.

---

## 4. Connexion Google — le seul point sensible

`config/google.php` :
```php
'redirect_uri' => 'http://localhost/auth/google/callback', // dev
```

### Pour un tunnel de test (accès externe / téléphone)
1. `ngrok http 80` → tu obtiens `https://xxxx.ngrok-free.app`
2. Console Google → **Clients** → ton client Web → ajoute :
   - Origine JS : `https://xxxx.ngrok-free.app`
   - URI de redirection : `https://xxxx.ngrok-free.app/auth/google/callback`
3. `config/google.php` → `'redirect_uri' => 'https://xxxx.ngrok-free.app/auth/google/callback'`
4. `export KBF_BASE_URL=https://xxxx.ngrok-free.app` (ou `app.php`)

### Pour la production (vrai domaine HTTPS)
1. Console Google → ajoute `https://forms.tondomaine.com` +
   `https://forms.tondomaine.com/auth/google/callback`
   (on peut garder les entrées localhost à côté — la liste est multiple)
2. Console Google → **Audience** → passe l'app en **« En production »**
   (sinon seuls les utilisateurs test peuvent se connecter)
3. `config/google.php` → `'redirect_uri' => null` (il sera déduit de `KBF_BASE_URL`)

---

## 5. CAPTCHA — ajouter des domaines (aucun fichier à toucher)

`config/recaptcha.php` (clés) reste **identique** partout. Seule la liste de
domaines change, dans la console : <https://www.google.com/recaptcha/admin>
→ ta clé → **Paramètres** → *Domaines* → ajouter, côte à côte :

```
localhost
192.168.1.32        (ou le sous-réseau si testé sur LAN)
forms.tondomaine.com
```

reCAPTCHA v2 accepte les IP et le HTTP, contrairement à OAuth.

---

## 6. Checklist « passage en production »

- [ ] `KBF_BASE_URL=https://forms.tondomaine.com` (env du vhost) **ou**
      `cp app/config/app.php.example app/config/app.php` puis renseigner `base_url`
- [ ] `config/google.php` → `redirect_uri => null` ; domaine ajouté + app publiée côté Google
- [ ] Console reCAPTCHA → domaine de prod ajouté
- [ ] `config/mail.php` → éventuellement `from_email` sur ton domaine
      (avec Gmail, garde l'adresse Gmail ; sinon SPF/DKIM du domaine requis)
- [ ] `app/config/dev-mode` **absent** (sinon les codes de reset fuitent dans l'API)
- [ ] HTTPS activé sur le vhost (obligatoire pour OAuth Google en prod)
- [ ] `app/logs/` accessible en écriture par `www-data`
      (`sudo chgrp -R www-data app/logs && sudo chmod -R g+w app/logs`)
- [ ] (optionnel) restreindre `Access-Control-Allow-Origin` dans `app/public/index.php`

Aucun des fichiers `app/config/*.php` n'est versionné : le déploiement les
recrée à partir des `.example` avec les valeurs de l'environnement cible.

---

## 7. Base de données — migrations & sauvegardes

### Mettre à jour une base existante
Une installation **fraîche** part de `kbforms.sql` (déjà à jour). Une base
**existante** applique les migrations incrémentales :

```bash
/opt/lampp/bin/mysql -u kbforms -p kbforms < migrations/2026-08-31_v1_hardening.sql
```
(colonnes ajoutées : `forms.response_retention_days`, `forms.require_captcha`,
`forms.notify_emails`, `responses.ip_hash` + index)

### Sauvegardes
```bash
scripts/backup-db.sh                 # → backups/kbforms_<horodatage>.sql.gz (rotation : 14)
scripts/restore-db.sh backups/kbforms_2026-09-01_120000.sql.gz
```
Variables surchargeables : `KBF_DB_NAME`, `KBF_DB_USER`, `KBF_DB_PASS`,
`KBF_DB_SOCKET`, `KBF_BACKUP_DIR`, `KBF_BACKUP_KEEP`.

Cron quotidien (crontab de l'utilisateur applicatif) :
```
15 3 * * *  /chemin/vers/KBForms/scripts/backup-db.sh >> /chemin/vers/KBForms/logs/backup.log 2>&1
```
**Testez une restauration** au moins une fois : un backup jamais restauré n'est pas un backup.

---

## 8. Logs

Tous les `error_log()` de l'app vont dans **`logs/app.log`** (à la racine du
dépôt — le dossier doit appartenir à / être inscriptible par le process web).
`MailService` écrit aussi `app/logs/mail.log`, la réinitialisation de mot de
passe `app/logs/password-resets.log` (repli `/tmp` si non inscriptible).

```bash
sudo chgrp -R www-data logs app/logs
sudo chmod -R g+w     logs app/logs
```
`logs/*.log` et `app/logs/*.log` sont gitignorés.
