<?php
/* Les plats et les catégories sont désormais réunis dans le module « Menu ». */
require __DIR__ . '/includes/auth.php';
$c = isset($_GET['cat']) ? '?c=' . (int)$_GET['cat'] : '';
header('Location: menu.php' . $c); exit;
