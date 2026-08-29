# Déploiement sur Hostinger VPS

Ce guide résume les étapes pour mettre l'application en ligne sur un VPS Hostinger, et confirme sa compatibilité.

## Compatibilité vérifiée

L'application a été auditée pour l'hébergement. Tout est compatible avec un VPS Hostinger standard :

- **PHP** : le code utilise des fonctions de PHP 7.4 et 8.x (arrow functions, match). Sur le VPS, installez PHP 8.1 ou 8.2 (recommandé).
- **Extensions** : seules des extensions standard sont requises — `pdo_mysql`, `mbstring`, `curl`, `gd`. Elles sont présentes par défaut dans une installation LAMP classique.
- **Base de données** : MySQL ou MariaDB, en encodage `utf8mb4` (déjà géré par le fichier `database.sql`). L'import a été testé : 26 tables créées sans erreur, accents corrects.
- **Chemins** : entièrement portables (aucun chemin codé en dur), l'application fonctionne quel que soit le dossier d'installation.

## Étapes de déploiement

### 1. Préparer le serveur (une seule fois)
Sur le VPS, installez la pile LAMP : Apache (ou Nginx), MySQL/MariaDB, PHP 8.1+ avec les extensions `pdo_mysql`, `mbstring`, `curl`, `gd`. Hostinger propose des images VPS avec cette pile préinstallée.

### 2. Transférer les fichiers
Copiez le contenu du dossier `traiteur` dans le répertoire web du serveur (souvent `/var/www/html` ou le dossier racine de votre domaine).

### 3. Créer et importer la base
- Créez une base de données (par exemple `traiteur_db`) en `utf8mb4`.
- Importez le fichier `database.sql` (via phpMyAdmin ou en ligne de commande `mysql`).

### 4. Configurer l'application
Éditez **uniquement** le fichier `config/config.php` :
- `DB_HOST` : généralement `localhost` (ou l'hôte fourni par Hostinger).
- `DB_NAME` : le nom de la base créée.
- `DB_USER` et `DB_PASS` : les identifiants MySQL du serveur.
- `SITE_URL` : l'adresse publique de votre site (par exemple `https://www.groupehelisce.com`). **Important** pour que les QR codes des badges fonctionnent.
- `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` : seulement si vous utilisez la connexion Google.

### 5. Droits d'écriture
Assurez-vous que le dossier `uploads/` (et `uploads/coffre/`) est accessible en écriture par le serveur web, pour permettre le téléversement des photos, logos et documents.

### 6. Première connexion
Connectez-vous avec `admin` / `admin123`, puis **changez immédiatement le mot de passe** dans « Mon profil ». Configurez ensuite votre logo, vos coordonnées et vos paramètres dans la page Paramètres.

## À faire pour la production (rappel de l'audit)

Une fois en ligne, pensez à :
- **Activer HTTPS** (certificat SSL — Hostinger en propose gratuitement) et forcer la redirection vers `https://`. C'est indispensable, notamment pour la vérification des badges par QR code.
- **Ajouter des en-têtes de sécurité** au niveau du serveur (via un `.htaccess` racine ou la configuration Apache/Nginx) : `X-Frame-Options`, `X-Content-Type-Options`, etc.
- **Changer le mot de passe administrateur** par défaut (voir étape 6).

Ces points ne concernent que l'exploitation en ligne ; l'application elle-même est prête.
