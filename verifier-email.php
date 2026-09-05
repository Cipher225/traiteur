<?php
/* La vérification des messages est désormais assurée par la page unique
   verifier.php, comme pour les documents. On redirige les anciens liens. */
$code = trim((string)($_GET['c'] ?? ''));
header('Location: verifier.php' . ($code !== '' ? '?c=' . urlencode($code) : ''), true, 301);
exit;
