<?php
/* Point d'accès léger : renvoie le nombre de messages non lus (JSON).
   Utilisé par notif-son.js pour jouer la mélodie à l'arrivée d'un message. */
require __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$uid = (int)($_SESSION['admin_id'] ?? 0);
if (!$uid) { echo json_encode(['messages' => 0, 'total' => 0]); exit; }

try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE destinataire_id=? AND lu=0");
    $st->execute([$uid]);
    $messages = (int)$st->fetchColumn();
} catch (\Throwable $e) {
    $messages = 0;
}

$total = $messages;
try {
    $u = current_user();
    if ($u) { $N = notifications($pdo, $u); $total = (int)($N['total'] ?? $messages); }
} catch (\Throwable $e) {}

echo json_encode(['messages' => $messages, 'total' => $total]);
