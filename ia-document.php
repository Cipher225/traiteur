<?php
/* ============================================================================
   TÉLÉCHARGEMENT D'UN DOCUMENT PROPOSÉ PAR L'ASSISTANT

   Le fichier n'est jamais servi par son chemin réel : on passe par cet
   intermédiaire, qui vérifie que le document est bien publié et compte les
   téléchargements. Sans cela, n'importe quel fichier du dossier serait
   accessible en devinant son nom.
   ============================================================================ */
require __DIR__ . '/config/db.php';

$id = (int)($_GET['d'] ?? 0);
if ($id <= 0) { http_response_code(404); exit('Document introuvable.'); }

try {
    $st = $pdo->prepare("SELECT fichier, fichier_nom FROM ia_documents WHERE id=? AND actif=1");
    $st->execute([$id]);
    $d = $st->fetch();
} catch (Throwable $e) { $d = null; }

if (!$d) { http_response_code(404); exit('Document introuvable.'); }

$chemin = __DIR__ . '/uploads/documents/' . basename($d['fichier']);
if (!is_file($chemin)) { http_response_code(404); exit('Fichier absent du serveur.'); }

try {
    $pdo->prepare("UPDATE ia_documents SET telechargements = telechargements + 1 WHERE id=?")
        ->execute([$id]);
} catch (Throwable $e) {}

$ext = strtolower(pathinfo($d['fichier_nom'], PATHINFO_EXTENSION));
$types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
          'png' => 'image/png',
          'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
          'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];

header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . $d['fichier_nom'] . '"');
header('Content-Length: ' . filesize($chemin));
header('X-Content-Type-Options: nosniff');
readfile($chemin);
