<?php
/* ============================================================================
   RETOUR DE GOOGLE

   Google renvoie ici après que vous avez donné — ou refusé — votre accord.
   Cette adresse doit être déclarée à l'identique dans la console Google, sinon
   l'autorisation est rejetée avant même d'arriver jusqu'ici.
   ============================================================================ */
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/gdrive.php';

if (!is_admin()) { header('Location: index.php'); exit; }

$retour = 'parametres.php#drive';

/* L'utilisateur a refusé, ou Google a rejeté la demande. */
if (!empty($_GET['error'])) {
    $motifs = [
        'access_denied' => "Vous avez refusé l'accès. Rien n'a été modifié.",
        'redirect_uri_mismatch' => "L'adresse de retour ne correspond pas à celle déclarée dans la console Google. "
            . "Elle doit être exactement : " . gdrive_retour(),
    ];
    flash($motifs[$_GET['error']] ?? ('Google a refusé : ' . e((string)$_GET['error'])), 'error');
    header('Location: ' . $retour); exit;
}

/* Le jeton d'état protège contre une demande forgée par un tiers. */
$attendu = (string)($_SESSION['gdrive_etat'] ?? '');
unset($_SESSION['gdrive_etat']);

if ($attendu === '' || !hash_equals($attendu, (string)($_GET['state'] ?? ''))) {
    flash("La demande d'autorisation n'est plus valide. Relancez la connexion.", 'error');
    header('Location: ' . $retour); exit;
}

if (empty($_GET['code'])) {
    flash("Google n'a transmis aucun code d'autorisation.", 'error');
    header('Location: ' . $retour); exit;
}

$erreur = null;
if (gdrive_echanger_code($pdo, (string)$_GET['code'], $erreur)) {
    $r = gdrive_reglages($pdo);
    flash('Google Drive connecté' . ($r['compte'] !== '' ? ' — compte ' . e($r['compte']) : '') . '.');
    if (function_exists('journaliser')) {
        journaliser($pdo, 'modification', 'Google Drive', null,
                    'Compte connecté' . ($r['compte'] !== '' ? ' : ' . $r['compte'] : ''));
    }
} else {
    flash('Connexion impossible : ' . e((string)$erreur), 'error');
}

header('Location: ' . $retour);
exit;
