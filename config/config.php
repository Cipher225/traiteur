<?php
/* ============================================================================
   CONFIGURATION DE L'APPLICATION
   ----------------------------------------------------------------------------
   Deux modes automatiques :

   • EN LOCAL (XAMPP) : les valeurs par défaut ci-dessous fonctionnent telles quelles.
   • EN LIGNE (Dokploy / Docker / hébergeur) : les variables d'environnement
     (DB_HOST, DB_NAME, DB_USER, DB_PASS, SITE_URL, GOOGLE_*) sont utilisées
     automatiquement si elles sont définies. Rien d'autre à modifier.

   La fonction env() lit une variable d'environnement, sinon utilise la valeur
   par défaut fournie. Cela permet au MÊME code de tourner en local et en ligne.
   ============================================================================ */

if (!function_exists('env_cfg')) {
    function env_cfg(string $cle, $defaut = '') {
        $v = getenv($cle);
        if ($v === false || $v === '') {
            // Support aussi $_ENV et $_SERVER (selon la config serveur)
            $v = $_ENV[$cle] ?? $_SERVER[$cle] ?? false;
        }
        return ($v === false || $v === null) ? $defaut : $v;
    }
}

/* ---- Base de données ----
   En local : localhost / traiteur_db / root / (vide).
   En ligne : définies par Dokploy via les variables d'environnement. */
define('DB_HOST', env_cfg('DB_HOST', 'localhost'));
define('DB_NAME', env_cfg('DB_NAME', 'traiteur_db'));
define('DB_USER', env_cfg('DB_USER', 'root'));
define('DB_PASS', env_cfg('DB_PASS', ''));
define('DB_PORT', env_cfg('DB_PORT', '3306'));

/* ---- Adresse du site (liens d'authentification des documents) ----
   Vide = détection automatique. En ligne, définissez SITE_URL (ex : https://mondomaine.com). */
define('SITE_URL', env_cfg('SITE_URL', ''));

/* ---- Connexion Google (facultatif) ---- */
define('GOOGLE_CLIENT_ID', env_cfg('GOOGLE_CLIENT_ID', ''));
define('GOOGLE_CLIENT_SECRET', env_cfg('GOOGLE_CLIENT_SECRET', ''));

/* ---- Fuseau horaire de l'application ----
   Fixé sur la Côte d'Ivoire (Abidjan = UTC+0) pour que l'heure soit correcte
   quel que soit le fuseau du serveur d'hébergement. Sans cela, un serveur réglé
   sur un autre fuseau (souvent UTC ou l'Europe) fausse les horaires de travail,
   les délais de suppression des messages, les dates, etc.
   Modifiable via la variable d'environnement APP_TIMEZONE si besoin. */
date_default_timezone_set(env_cfg('APP_TIMEZONE', 'Africa/Abidjan'));
