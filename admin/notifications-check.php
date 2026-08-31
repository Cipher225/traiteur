<?php
/* ============================================================================
   POINT DE VÉRIFICATION — ESPACE ADMINISTRATION
   Interrogé toutes les 10 secondes par le navigateur pour actualiser les
   pastilles du menu sans que l'utilisateur ait à recharger la page.
   Réponse volontairement minuscule (quelques octets) pour rester léger.
   ============================================================================ */
require __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid = (int)($_SESSION['admin_id'] ?? 0);
if (!$uid) { echo json_encode(['ok' => false]); exit; }

$rep = ['ok' => true, 'messages' => 0, 'forum' => 0, 'total' => 0, 'badges' => []];

try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE destinataire_id=? AND lu=0");
    $st->execute([$uid]);
    $rep['messages'] = (int)$st->fetchColumn();
} catch (Throwable $e) {}

// Sujets du forum comportant des messages postés depuis la dernière visite
try {
    $st = $pdo->prepare("SELECT forum_vu_at FROM users WHERE id=?");
    $st->execute([$uid]);
    $depuis = $st->fetchColumn() ?: '1970-01-01';
    $st = $pdo->prepare("SELECT COUNT(*) FROM forum_posts WHERE created_at > ? AND auteur_id <> ?");
    $st->execute([$depuis, $uid]);
    $rep['forum'] = (int)$st->fetchColumn();
} catch (Throwable $e) {}

try {
    $u = current_user();
    if ($u) {
        $N = notifications($pdo, $u);
        $rep['total'] = (int)($N['total'] ?? $rep['messages']);
    }
} catch (Throwable $e) { $rep['total'] = $rep['messages']; }

$rep['badges'] = ['messagerie' => $rep['messages'], 'forum' => $rep['forum']];
echo json_encode($rep);
