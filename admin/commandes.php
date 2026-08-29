<?php
/* Les demandes de devis du site sont désormais dans « Commandes clients ». */
require __DIR__ . '/includes/auth.php';
header('Location: commandes-client.php?vue=devis' . (isset($_GET['filtre']) ? '&filtre=' . urlencode($_GET['filtre']) : ''));
exit;
