# Déploiement KBForms (web + API) sur AwardSpace — hébergement gratuit sans carte

Cible : mettre l'API + le frontend web en ligne sur une URL HTTPS stable, que
l'app mobile pourra utiliser. AwardSpace free : PHP 8, 1 base MySQL, ~1 Go de
disque, `.htaccess`, `mail()` fonctionnel, SSL gratuit, pas de carte bancaire.

Aucune dépendance Composer dans ce projet → rien à installer, on téléverse les
fichiers tels quels.

---

## 1. Compte + sous-domaine

1. Créer un compte sur https://www.awardspace.com (plan **Free**).
2. Panneau → **Hosting Manager → Domain Manager** → créer un **sous-domaine**
   gratuit, ex. `kbform.atwebpages.com`.
3. Lors de la création (ou via *Edit* ensuite), régler le **dossier racine**
   du sous-domaine sur `.../kbform/app/public` (voir étape 3 pour l'arbo).
   Si l'interface ne permet pas un sous-dossier, voir la variante « shim » en
   bas de ce document.
4. Panneau → **PHP Settings** (ou *PHP Configuration*) → choisir **PHP 8.1**
   ou plus.

## 2. Base de données

1. Panneau → **MySQL Databases** → créer une base + un utilisateur (noter
   `host`, `nom de base`, `utilisateur`, `mot de passe` — l'hôte n'est
   généralement PAS `localhost` mais quelque chose comme `fdbXXXX.awardspace.net`).
2. Ouvrir **phpMyAdmin** (lien dans le panneau) → sélectionner la base →
   onglet **Importer** → envoyer le fichier `kbforms.sql` du dépôt (schéma
   complet et à jour : tables `forms`, `questions`, `responses`, `answers`,
   `form_agents` (avec `email`), `choice_lists`, `repeat_groups`,
   `form_assignments`, `device_tokens`, `refresh_tokens`, etc.).
3. Si l'import dépasse la limite de taille : compresser `kbforms.sql` en
   `.zip` (phpMyAdmin accepte le zip), ou l'importer en deux fois.

## 3. Fichiers à téléverser (FTP ou gestionnaire de fichiers)

Arborescence à conserver **telle quelle** :

```
kbform/
├── app/
│   ├── public/        ← racine web du sous-domaine
│   │   ├── index.php
│   │   ├── .htaccess
│   │   └── assets/
│   ├── src/
│   ├── config/        ← fichiers de config (voir étape 4)
│   └── logs/          ← doit être inscriptible (chmod 755/775)
```

**À téléverser** : tout `app/` (`public/`, `src/`, `config/`).
**À NE PAS téléverser** : `.git/`, `tools/`, `migrations/` (déjà dans
`kbforms.sql`), `node_modules/` (inexistant), les `app/config/*` de dev
(recréés à l'étape 4), `app/logs/*.log`.

> `.htaccess` est déjà dans `app/public/` — il route tout vers `index.php` et
> transmet l'en-tête `Authorization` (indispensable pour les jetons JWT).
> Rien à modifier.

## 4. Fichiers de configuration à créer dans `app/config/` sur le serveur

Ces fichiers ne sont pas dans le dépôt (secrets). Les créer directement sur
l'hébergeur (gestionnaire de fichiers) à partir des `.example` fournis.

### `app/config/database.php`
```php
<?php
return [
    'host'     => 'fdbXXXX.awardspace.net',   // hôte MySQL du panneau
    'dbname'   => 'VOTRE_BASE',
    'username' => 'VOTRE_USER',
    'password' => 'VOTRE_MOT_DE_PASSE',
    'charset'  => 'utf8mb4',
];
```

### `app/config/app.php`
```php
<?php
return ['base_url' => 'https://kbform.atwebpages.com'];
```
Fixe l'URL utilisée dans les e-mails d'invitation et les liens `/f/{token}`.

### `app/config/mail.php`
D'abord essayer le driver `mail` (MTA local d'AwardSpace, le plus fiable en
mutualisé) :
```php
<?php
return [
    'driver'     => 'mail',
    'from_email' => 'noreply@kbform.atwebpages.com',  // doit être sur le domaine hébergé
    'from_name'  => 'KBForms',
];
```
Si les e-mails n'arrivent pas, tester `'driver' => 'smtp'` avec un compte
Gmail + mot de passe d'application (comme en local) — mais AwardSpace free
peut bloquer le port 587 sortant.

### `app/config/mobile.php`
Reprendre **exactement** le même `client_secret` qu'en local (l'app mobile
l'envoie dans l'en-tête `X-KBF-Client-Secret`) :
```php
<?php
return ['client_secret' => 'bcb410db801583e9d171cecd0ef493b98b14f68dd89cb958bc491537df2c34ee'];
```

### `app/config/recaptcha.php`
Le CAPTCHA web (login/register) a besoin de clés valides pour le nouveau
domaine. Deux options :
- **Recommandé** : créer une paire de clés reCAPTCHA **v2 « case à cocher »**
  sur https://www.google.com/recaptcha/admin en ajoutant `kbform.atwebpages.com`
  aux domaines, puis :
  ```php
  <?php
  return ['site_key' => 'NOUVELLE_SITE_KEY', 'secret_key' => 'NOUVELLE_SECRET_KEY'];
  ```
- **Ou désactiver** pour un pilote (ne pas créer le fichier, ou clés vides) :
  ```php
  <?php
  return ['site_key' => '', 'secret_key' => ''];
  ```
  L'app mobile n'est pas concernée (elle contourne le CAPTCHA via le
  `client_secret`).

### Optionnel — non nécessaires pour un pilote
`google.php` / `google-sheets.php` / `*-key.json` (connexion Google, export
Sheets) et `fcm.php` / `kbforms-fcm-key.json` (notifications push). Sans ces
fichiers, ces fonctions sont simplement inactives, le reste marche.

## 5. HTTPS

Panneau → **SSL/TLS** (ou *Free SSL Certificate*) → activer **Let's Encrypt**
pour le sous-domaine. Attendre l'émission (quelques minutes) puis vérifier que
`https://kbform.atwebpages.com` répond.

## 6. Vérifications post-déploiement

```
# API vivante (doit renvoyer 401, pas une erreur PHP)
curl -i https://kbform.atwebpages.com/templates

# Inscription web
curl -s -X POST https://kbform.atwebpages.com/register \
  -H 'Content-Type: application/json' \
  -d '{"first_name":"Test","last_name":"T","email":"t@example.com","password":"secret123","account_type":"individual"}'
```
Puis, dans un navigateur : ouvrir `https://kbform.atwebpages.com/`, créer un
compte, créer un formulaire, le publier, ouvrir le lien public `/f/...`,
soumettre une réponse, la voir dans « Réponses ».

## 7. Pointer l'app mobile

Côté dépôt mobile (`KBforms-mobile`), changer l'URL de base de l'API pour
`https://kbform.atwebpages.com` et rebuild l'APK. Le `client_secret` doit être
identique à celui de `app/config/mobile.php`.

## 8. Limites d'AwardSpace free à garder en tête

- **~1 Go de disque.** Les médias (photos, signatures) sont stockés en base64
  **dans la base MySQL** (`response_media`) → la base grossit vite. Surveiller
  sa taille dans phpMyAdmin ; purger les vieilles réponses si besoin
  (`POST /forms/{id}/responses/purge`).
- **Pas de cron** → la purge automatique des réponses ne tourne pas seule
  (appel manuel de l'endpoint de purge).
- **`max_execution_time` ~30 s, `post_max_size` souvent 8–64 Mo** → une
  soumission avec plusieurs photos volumineuses peut être refusée. Tester avec
  des médias réalistes.
- **Bande passante ~5 Go/mois** sur le plan gratuit.
- Trafic « suspendu » si le compte est inactif trop longtemps — se connecter
  au panneau de temps en temps.

Quand ces limites deviennent gênantes : migrer vers Render + TiDB Cloud
Serverless (tous deux sans carte) en dockerisant, ou vers une VM Oracle/GCP
si une carte devient disponible.

---

## Variante « shim » (si on ne peut pas mettre la racine sur `app/public`)

Téléverser tout le dossier `kbform/` **dans la racine web**, puis y ajouter
directement à la racine un `index.php` :
```php
<?php require __DIR__ . '/kbform/app/public/index.php';
```
et un `.htaccess` (⚠ `index.php` ne sert PAS les fichiers statiques : il faut
rediriger `/assets/...` vers le vrai dossier) :
```apache
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

# fichiers/dossiers réels servis directement
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]

# CSS / JS / images / polices → vrai emplacement sous kbform/app/public/assets
RewriteRule ^assets/(.*)$ kbform/app/public/assets/$1 [L]

# tout le reste → routeur
RewriteRule ^ index.php [QSA,L]
```
Le `.htaccess` d'origine dans `kbform/app/public/` n'est alors pas utilisé —
c'est celui de la racine qui compte.
