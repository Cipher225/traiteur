<?php
/* Documents d'un client, au format JSON.
   Appelé par la messagerie quand on choisit un destinataire : on propose
   alors ses factures, proformas, bons de livraison et reçus. */
require __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$cid = (int)($_GET['client'] ?? 0);
if (!$cid) { echo json_encode([]); exit; }

$docs = [];

try {
    $st = $pdo->prepare("SELECT id, numero, type, date_emission, bl_numero
                         FROM factures WHERE client_id = ? ORDER BY date_emission DESC, id DESC LIMIT 40");
    $st->execute([$cid]);
    foreach ($st->fetchAll() as $f) {
        $quand = !empty($f['date_emission']) ? date('d/m/Y', strtotime($f['date_emission'])) : '';
        $docs[] = ['type' => $f['type'] === 'proforma' ? 'proforma' : 'facture',
                   'id' => (int)$f['id'],
                   'libelle' => ($f['type'] === 'proforma' ? 'Proforma ' : 'Facture ') . $f['numero'] . ' — ' . $quand];
        if ($f['type'] !== 'proforma') {
            $docs[] = ['type' => 'livraison', 'id' => (int)$f['id'],
                       'libelle' => 'Bon de livraison ' . ($f['bl_numero'] ?: 'de ' . $f['numero']) . ' — ' . $quand];
        }
    }

    $st = $pdo->prepare("SELECT id, numero, type, date_paiement FROM recus
                         WHERE client_id = ? ORDER BY date_paiement DESC, id DESC LIMIT 25");
    $st->execute([$cid]);
    foreach ($st->fetchAll() as $r) {
        $quand = !empty($r['date_paiement']) ? date('d/m/Y', strtotime($r['date_paiement'])) : '';
        $docs[] = ['type' => 'recu', 'id' => (int)$r['id'],
                   'libelle' => 'Reçu ' . $r['numero'] . ' — ' . $quand];
    }
} catch (Throwable $e) { /* une table absente ne doit pas bloquer la messagerie */ }

echo json_encode($docs, JSON_UNESCAPED_UNICODE);
