<?php
/* Passage quotidien des relances — exécuté par le planificateur du conteneur. */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/wave.php';
require __DIR__ . '/../admin/includes/documents.php';
require __DIR__ . '/../config/relances.php';

$r = relances_automatiques($pdo);
echo date('Y-m-d H:i') . ' | ' . ($r['actif'] ? $r['envoyees'] . ' relance(s) envoyée(s)' : 'relances désactivées') . PHP_EOL;
