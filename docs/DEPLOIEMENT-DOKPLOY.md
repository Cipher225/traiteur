# Déploiement sur Hostinger (Dokploy) via GitHub

Ce guide met l'application en ligne **sans jamais perdre les données des clients**,
même lors des mises à jour futures.

---

## 1. Mettre le code sur GitHub

1. Crée un dépôt **privé** sur GitHub (ex : `groupe-helisce`).
2. Depuis le dossier `traiteur` sur ton ordinateur :

   ```bash
   git init
   git add .
   git commit -m "Version initiale"
   git branch -M main
   git remote add origin https://github.com/TON-COMPTE/groupe-helisce.git
   git push -u origin main
   ```

> Le fichier `.gitignore` empêche déjà d'envoyer les secrets (`.env`) et les
> fichiers téléversés par les clients (`uploads/`). C'est voulu : ces fichiers
> vivent sur le serveur, pas dans le code.

---

## 2. Créer l'application dans Dokploy

1. Dans Dokploy → **Create Project** → **Compose** (Docker Compose).
2. **Source** : connecte ton compte GitHub et choisis le dépôt `groupe-helisce`,
   branche `main`.
3. Dokploy détecte le fichier `docker-compose.yml` à la racine.

---

## 3. Renseigner les variables d'environnement

Dans l'onglet **Environment** de Dokploy, colle ces variables
(remplace les mots de passe par des mots de passe forts) :

```
DB_NAME=traiteur_db
DB_USER=traiteur
DB_PASS=un_mot_de_passe_fort
DB_ROOT_PASS=un_autre_mot_de_passe_fort
SITE_URL=https://ton-domaine.com
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
```

---

## 4. Domaine et HTTPS

1. Dans Dokploy → onglet **Domains** du service `app`, ajoute ton domaine
   (ex : `helisce.com`), port interne **80**.
2. Active **HTTPS** (Let's Encrypt) — Dokploy gère le certificat automatiquement.

---

## 5. Déployer

Clique sur **Deploy**. Au premier lancement :

- la base de données est créée sur un **volume persistant** ;
- le schéma complet est installé automatiquement ;
- l'administrateur par défaut est créé : **identifiant `admin` / mot de passe `admin123`**.

> ⚠️ **Change le mot de passe admin** dès la première connexion
> (menu Comptes & mots de passe).

---

## 6. Mettre à jour l'application plus tard (sans casser les données)

C'est tout l'intérêt du montage :

1. Modifie le code sur ton ordinateur.
2. `git add . && git commit -m "Ma modification" && git push`.
3. Dans Dokploy, clique **Redeploy** (ou active le déploiement automatique).

À chaque redéploiement :

- ✅ La base de données est **conservée** (volume `db_data`).
- ✅ Les logos, photos et documents sont **conservés** (volume `uploads_data`).
- ✅ Seules les **migrations** (nouvelles colonnes) sont appliquées, de façon sûre.
- ❌ Rien n'est jamais effacé ni réinstallé.

---

## Comment la sécurité des données est garantie (résumé technique)

| Élément | Mécanisme |
|---|---|
| Base de données | Volume Docker persistant `db_data` |
| Fichiers téléversés | Volume Docker persistant `uploads_data` |
| Premier lancement | Import du schéma **uniquement si la base est vide** |
| Mises à jour | `migrations.sql` **idempotent** (`ADD COLUMN IF NOT EXISTS`) |
| Réinstallation accidentelle | Impossible : tout est en `IF NOT EXISTS` / `INSERT IGNORE` |

---

## Sauvegardes recommandées

Même si les données sont persistantes, garde une sauvegarde régulière :

- Dans Dokploy, configure une **sauvegarde planifiée** du volume `db_data`
  (ou de la base MariaDB) vers un stockage externe (S3, etc.).
- Les fichiers `uploads_data` peuvent aussi être sauvegardés de la même manière.

---

## Test local avec Docker (facultatif)

Avant de déployer, tu peux tester sur ton ordinateur si Docker est installé :

```bash
cp .env.example .env      # renseigne les mots de passe
docker compose up --build
```

Puis ouvre `http://localhost` (le port est mappé par Docker).
