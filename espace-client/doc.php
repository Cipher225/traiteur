<?php
/* ============================================================================
   ESPACE CLIENT — AFFICHAGE D'UN DOCUMENT
   Ce fichier ne redessine PAS les documents : il vérifie que le document
   appartient bien au client connecté, puis délègue au générateur unique de
   l'application (admin/print.php). Le client voit donc exactement le même
   document que l'administration, et toute correction s'applique des deux côtés.
   ============================================================================ */

require __DIR__ . '/inc.php';
require_once __DIR__ . '/../admin/includes/documents.php';

$cid  = (int)$CLIENT['id'];
$type = $_GET['type'] ?? '';
$id   = (int)($_GET['id'] ?? 0);

/* --- Contrôle de propriété : un client ne voit que ses propres documents --- */
$autorise = false;
if ($type === 'facture' || $type === 'proforma' || $type === 'livraison') {
    $st = $pdo->prepare('SELECT client_id FROM factures WHERE id=?');
    $st->execute([$id]);
    $autorise = ((int)($st->fetchColumn() ?: 0) === $cid);
} elseif ($type === 'recu') {
    $st = $pdo->prepare('SELECT client_id FROM recus WHERE id=?');
    $st->execute([$id]);
    $autorise = ((int)($st->fetchColumn() ?: 0) === $cid);
}

if (!$autorise) {
    http_response_code(403);
    exit('Document introuvable ou accès refusé.');
}

/* --- On passe la main au générateur commun --- */
define('DOC_ESPACE_CLIENT', true);
$_GET['auth'] = '1';   // le client reçoit le document complet : tampon, signature et QR d'authentification
require __DIR__ . '/../admin/print.php';
