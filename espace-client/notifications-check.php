<?php
/* Point d'accès léger (espace client) : messages non lus en JSON. */
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/db.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

$uid = (int)($_SESSION['admin_id'] ?? 0);
$role = $_SESSION['admin_role'] ?? '';
if (!$uid || $role !== 'client') { echo json_encode(['messages' => 0, 'total' => 0]); exit; }

try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE destinataire_id=? AND lu=0");
    $st->execute([$uid]);
    $messages = (int)$st->fetchColumn();
} catch (\Throwable $e) {
    $messages = 0;
}

echo json_encode(['messages' => $messages, 'total' => $messages]);
