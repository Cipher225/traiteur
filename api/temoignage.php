<?php
require __DIR__ . '/../config/db.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'msg'=>'Méthode invalide.']); exit; }

// CSRF (souple : on renvoie du JSON plutôt qu'une redirection)
if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) { echo json_encode(['ok'=>false,'msg'=>'Session expirée, rechargez la page.']); exit; }

$nom = trim($_POST['nom'] ?? '');
$texte = trim($_POST['texte'] ?? '');
$note = min(5, max(1, (int)($_POST['note'] ?? 5)));
if ($nom === '' || $texte === '') { echo json_encode(['ok'=>false,'msg'=>'Merci d\'indiquer votre nom et votre message.']); exit; }

$pdo->prepare("INSERT INTO temoignages (nom, texte, note, statut, actif) VALUES (?,?,?, 'en_attente', 0)")
    ->execute([mb_substr($nom,0,100), mb_substr($texte,0,800), $note]);
echo json_encode(['ok'=>true,'msg'=>'Merci beaucoup pour votre avis ! 🙏']);
