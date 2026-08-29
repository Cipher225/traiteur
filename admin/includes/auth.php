<?php
require __DIR__ . '/../../config/db.php';
if (empty($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}

/* ---- Sécurité de session : session unique + déconnexion sur inactivité ---- */
define('INACTIVITE_MAX', 30 * 60);   // 30 minutes sans activité => déconnexion
(function() use ($pdo) {
    $uid = (int)$_SESSION['admin_id'];

    /* Inactivité : on se base sur l'horodatage stocké DANS la session PHP.
       Une connexion fraîche initialise cette valeur, donc pas de déconnexion
       intempestive juste après s'être connecté. */
    $maintenant = time();
    if (isset($_SESSION['derniere_activite'])
        && ($maintenant - (int)$_SESSION['derniere_activite']) > INACTIVITE_MAX) {
        $pdo->prepare("UPDATE users SET session_id=NULL WHERE id=?")->execute([$uid]);
        session_unset(); session_destroy();
        header('Location: ../login.php?inactif=1'); exit;
    }
    $_SESSION['derniere_activite'] = $maintenant;

    /* Session unique : si une connexion plus récente a eu lieu ailleurs,
       l'identifiant de session en base ne correspond plus => on coupe.
       (On ne vérifie que si un sid_check a bien été posé à la connexion.) */
    if (!empty($_SESSION['sid_check'])) {
        $u = $pdo->prepare("SELECT session_id FROM users WHERE id=?");
        $u->execute([$uid]);
        $sidBase = $u->fetchColumn();
        if ($sidBase && $sidBase !== $_SESSION['sid_check']) {
            session_unset(); session_destroy();
            header('Location: ../login.php?ailleurs=1'); exit;
        }
    }

    /* Trace d'activité en base (informative). */
    $pdo->prepare("UPDATE users SET last_activity=NOW() WHERE id=?")->execute([$uid]);
})();

$settings = get_settings($pdo);
if (!defined('UPLOAD_DIR')) define('UPLOAD_DIR', __DIR__ . '/../../uploads');

/* Contrôle des horaires de travail : un employé n'accède à son espace
   qu'aux heures ouvrées définies par l'administrateur — sauf accès
   exceptionnel accordé par l'admin. */
if ((current_user()['role'] ?? '') === 'employe' && !within_work_hours($settings)) {
    // Accès exceptionnel encore valide ?
    $exc = $pdo->prepare("SELECT acces_exception_until FROM users WHERE id=?");
    $exc->execute([(int)$_SESSION['admin_id']]);
    $until = $exc->fetchColumn();
    $exceptionActive = $until && strtotime($until) >= time();
    if (!$exceptionActive) {
    $horaires = work_hours_label($settings);
    http_response_code(403);
    ?><!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hors horaires de travail</title>
    <link rel="stylesheet" href="<?= asset('../assets/css/glass.css') ?>"><script src="<?= asset('../assets/js/theme.js') ?>"></script>
    <style>body{min-height:100svh;display:grid;place-items:center;padding:24px}
      .hw{max-width:440px;text-align:center;padding:40px 32px;border-radius:24px}
      .hw .ico{font-size:52px;margin-bottom:14px}
      .hw h1{font-family:var(--font-display);font-size:24px;margin-bottom:10px}
      .hw p{color:var(--ink-dim);line-height:1.6;margin-bottom:8px}
      .hw .hrs{color:var(--gold);font-weight:700;margin:14px 0 22px}</style></head>
    <body><div class="aurora"></div>
    <div class="hw glass-strong">
      <div class="ico">🕐</div>
      <h1>Espace fermé pour le moment</h1>
      <p>Bonjour <?= e($_SESSION['admin_nom'] ?? '') ?>, votre espace de travail n'est accessible que pendant les heures ouvrées.</p>
      <div class="hrs"><?= e($horaires) ?></div>
      <p style="font-size:13.5px">Revenez pendant ces horaires pour retrouver vos tâches, rapports et messages.</p>
      <a href="../logout.php" class="btn btn-glass" style="margin-top:18px">Se déconnecter</a>
    </div></body></html><?php
    exit;
    }
}

/* Contrôle automatique des permissions selon la page appelée.
   L'admin a accès à tout ; un employé seulement à ses modules autorisés. */
$__page = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
$__map = [
    'index' => 'dashboard',
    'profil' => 'dashboard',   // page personnelle, accessible à tous les connectés
    'badge-print' => 'badges',   // impression carte/badge
    'pdf'   => '__doc__',   // documents : accessibles si factures OU paie
    'print' => '__doc__',
    'commandes-client' => 'commandes_client',
    'messagerie' => 'messagerie',
    'forum' => 'forum',
    'commandes' => 'commandes_client',   // demandes de devis fusionnées
    'recus' => '__bons__',               // bons de sortie ET bons d'entrée
    'plats' => 'menu',       // fusionnés dans le module Menu
    'categories' => 'menu',
];
$__key = $__map[$__page] ?? $__page;

// Les factures et proformas partagent la même page
if ($__page === 'factures' && (($_GET['doc'] ?? '') === 'proforma')) {
    if (!can('factures') && !can('proformas')) {
        flash("Vous n'avez pas accès à cette section.", 'error');
        header('Location: index.php'); exit;
    }
    $__key = null; // déjà vérifié
}

if ($__key === '__bons__') {
    if (!can('bons_sortie') && !can('bons_entree')) {
        flash("Vous n'avez pas accès à ces documents.", 'error');
        header('Location: index.php'); exit;
    }
} elseif ($__key === '__doc__') {
    if (!can('factures') && !can('paie') && !can('proformas') && !can('bons_sortie') && !can('bons_entree')) {
        flash("Vous n'avez pas accès à ce document.", 'error');
        header('Location: index.php'); exit;
    }
} elseif ($__key && $__key !== 'dashboard' && array_key_exists($__key, all_modules())) {
    require_perm($__key);
}
