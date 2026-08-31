<?php
/* ============================================================================
   POINT DE VÉRIFICATION — ESPACE CLIENT
   Interrogé régulièrement pour actualiser la pastille des messages sans
   recharger la page.
   ============================================================================ */
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/db.php';
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid  = (int)($_SESSION['admin_id'] ?? 0);
$role = $_SESSION['admin_role'] ?? '';
if (!$uid || $role !== 'client') { echo json_encode(['ok' => false]); exit; }

$messages = 0;
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE destinataire_id=? AND lu=0");
    $st->execute([$uid]);
    $messages = (int)$st->fetchColumn();
} catch (Throwable $e) {}

echo json_encode([
    'ok'       => true,
    'messages' => $messages,
    'total'    => $messages,
    'badges'   => ['messagerie' => $messages],
]);
