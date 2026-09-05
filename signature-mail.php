<?php
/* Image de signature d'un message : appelée par le client de messagerie.
   Accès public, mais uniquement par référence — aucune donnée n'est exposée. */
require __DIR__ . '/config/config.php';
require __DIR__ . '/config/db.php';

$ref = preg_replace('/[^A-Z0-9\-]/', '', strtoupper((string)($_GET['c'] ?? '')));
$fichier = __DIR__ . '/uploads/signatures/' . $ref . '.png';

if ($ref === '' || !is_file($fichier)) { http_response_code(404); exit; }

header('Content-Type: image/png');
header('Cache-Control: public, max-age=604800');
readfile($fichier);
