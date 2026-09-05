<?php
/* Téléchargement PDF depuis l'espace client.
   On vérifie d'abord que le document appartient bien au client connecté,
   puis on délègue au générateur commun. */
require __DIR__ . '/inc.php';

$type = $_GET['type'] ?? 'facture';
$id   = (int)($_GET['id'] ?? 0);
$cid  = (int)($CLIENT['id'] ?? 0);

$ok = false;
try {
    if (in_array($type, ['facture', 'proforma', 'livraison'], true)) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM factures WHERE id=? AND client_id=?');
    } elseif ($type === 'recu') {
        $st = $pdo->prepare('SELECT COUNT(*) FROM recus WHERE id=? AND client_id=?');
    } else {
        $st = null;
    }
    if ($st) { $st->execute([$id, $cid]); $ok = (bool)$st->fetchColumn(); }
} catch (Throwable $e) { $ok = false; }

if (!$ok) { http_response_code(404); exit('Document introuvable ou accès refusé.'); }

define('PDF_ESPACE_CLIENT', true);
require __DIR__ . '/../admin/pdf.php';
