<?php
/* Balises PWA (à inclure dans le <head>). $pwaBase = chemin relatif vers la racine ('.' ou '..'). */
$pwaBase = $pwaBase ?? '.';
?>
<link rel="manifest" href="<?= $pwaBase ?>/manifest.php">
<meta name="theme-color" content="#0a1f44">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= e($s['nom_entreprise'] ?? 'Groupe Helisce') ?>">
<link rel="apple-touch-icon" href="<?= $pwaBase ?>/icone-pwa.php?t=192">
