<?php
/* ============================================================================
   DÉCONNEXION

   Détruire la session PHP ne suffit pas : tant que l'identifiant de session
   reste inscrit en base, le compte est réputé occupé et une nouvelle connexion
   serait refusée pendant dix minutes. On libère donc explicitement.
   ============================================================================ */
require __DIR__ . '/config/db.php';

$uid = (int)($_SESSION['admin_id'] ?? 0);
if ($uid > 0) {
    session_liberer($pdo, $uid);
    if (function_exists('journaliser')) {
        journaliser($pdo, 'connexion', 'utilisateur', $uid, 'Déconnexion');
    }
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
              $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Location: login.php?deconnecte=1');
exit;
