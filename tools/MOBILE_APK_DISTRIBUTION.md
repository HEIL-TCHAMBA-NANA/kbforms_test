# Distribuer l'APK de test (sans Play Store)

La sidebar du web affiche un onglet **« Application mobile »** → page `/download`
(bouton de téléchargement + instructions d'installation Android).

L'onglet **n'apparaît que si une distribution est configurée**. `GET /auth/config`
renvoie un booléen `mobile_apk` (jamais l'URL réelle). Le bouton de la page
pointe vers **`GET /download/apk`**, une redirection 302 côté serveur vers l'APK,
résolue dans cet ordre :

1. **Fichier livré dans l'image** : `app/public/downloads/kbforms.apk` existe
   → redirige vers `/downloads/kbforms.apk` (servi directement par Apache).
2. Sinon **variable d'environnement** `KBF_MOBILE_APK_URL` (URL absolue).
3. Sinon `mobile_apk = false`, `/download/apk` renvoie 404 → onglet masqué.

L'URL GitHub (ou autre) n'est donc jamais visible dans la page ni dans le HTML —
seul `/download/apk` l'est.

---

## Option A — Release GitHub (recommandé)

Pas de binaire dans l'historique git, APK remplaçable sans redéployer le web.

1. Sur le dépôt **public** `HEIL-TCHAMBA-NANA/kbforms_test` :
   *Releases → Draft a new release → Tag* `mobile-test` (ou `v0.1.0`),
   glisser le fichier `kbforms.apk` en pièce jointe, *Publish*.
2. URL stable de la dernière version :
   `https://github.com/HEIL-TCHAMBA-NANA/kbforms_test/releases/latest/download/kbforms.apk`
   (le nom du fichier doit rester `kbforms.apk` d'une release à l'autre).
3. Render → service `kbforms` → Environment :
   `KBF_MOBILE_APK_URL = https://github.com/HEIL-TCHAMBA-NANA/kbforms_test/releases/latest/download/kbforms.apk`
   → *Save* (redéploiement auto).
4. Nouvelle version de l'app : publier une nouvelle release avec le même nom de
   fichier. Rien à changer côté Render.

## Option B — APK dans le dépôt

Le plus simple, mais ~15–25 Mo ajoutés à git à chaque build, et un redéploiement
Render pour chaque mise à jour.

```sh
cp /chemin/vers/app-release.apk app/public/downloads/kbforms.apk
git add -f app/public/downloads/kbforms.apk    # -f : *.apk est git-ignoré par défaut
git commit -m "Mobile : APK de test vX"
git push
```

Render redéploie, le fichier est servi sur `https://<domaine>/downloads/kbforms.apk`
et détecté automatiquement (pas besoin de `KBF_MOBILE_APK_URL`).

## En LAN (sans Render)

Option B uniquement : le fichier sous `app/public/downloads/` est servi par
l'Apache local sur `http://<ip-du-PC>/downloads/kbforms.apk`.

---

## Notes

- `app/public/.htaccess` déclare le type MIME `application/vnd.android.package-archive`
  pour `.apk` → Android propose l'installation, un navigateur desktop télécharge.
- L'app mobile doit pointer vers la bonne API (`--dart-define` au build Flutter,
  cf. `KBforms-mobile`). Un APK compilé pour `http://10.0.2.2` (émulateur) ne
  fonctionnera pas sur un vrai téléphone.
- Pour un pilote élargi, préférer un canal de test fermé Play Console
  (Internal testing) ou Firebase App Distribution — mais les deux demandent
  un compte (et carte pour Play Console).
