<?php
/* Aperçu de la signature, affiché dans la page de rédaction. */
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/docauth.php';
require_once __DIR__ . '/../config/signature_mail.php';

$fichier = sys_get_temp_dir() . '/sig-apercu-' . date('Ymd') . '.png';
if (!is_file($fichier)) {
    signature_image($settings, 'GH-' . date('Y') . '-XXXXXX', 'APERCU00', $fichier);
}
header('Content-Type: image/png');
header('Cache-Control: private, max-age=3600');
readfile($fichier);
