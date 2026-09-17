<?php
/* ============================================================================
   APERÇU DE LA SIGNATURE

   Affiché dans la page de rédaction, et consulté juste après avoir modifié le
   téléphone ou le logo de l'entreprise. L'aperçu était mis en cache à la
   journée : on changeait un numéro, on ouvrait l'aperçu, on revoyait l'ancien,
   et on en concluait que le changement n'avait pas pris. Le cache porte donc
   désormais sur le CONTENU de la signature : dès qu'un des champs affichés
   change, l'image est refaite.
   ============================================================================ */
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/docauth.php';
require_once __DIR__ . '/../config/signature_mail.php';

/* Deux aperçus possibles : avec ou sans le cartouche de vérification. */
$avecAuth = ($_GET['auth'] ?? '1') !== '0';

/* Les champs qui apparaissent réellement dans l'image. Le logo compte par sa
   date de modification : le nom du fichier ne change pas quand on le remplace. */
$cheminLogo = __DIR__ . '/../uploads/' . (string)($settings['logo'] ?? '');
$empreinte = md5(implode('|', [
    $settings['nom_entreprise'] ?? '', $settings['slogan']  ?? '',
    $settings['adresse']        ?? '', $settings['telephone'] ?? '',
    $settings['whatsapp']       ?? '', $settings['email']   ?? '',
    $settings['site_url']       ?? '', $settings['rccm']    ?? '',
    $settings['ncc']            ?? '', $settings['logo']    ?? '',
    is_file($cheminLogo) ? (string)filemtime($cheminLogo) : '',
    $avecAuth ? '1' : '0',
]));

$dossier = sys_get_temp_dir();
$fichier = $dossier . '/sig-apercu-' . $empreinte . '.png';

if (!is_file($fichier)) {
    /* Les aperçus précédents ne servent plus à rien : on les retire pour ne
       pas semer un fichier à chaque modification de paramètre. */
    foreach (glob($dossier . '/sig-apercu-*.png') ?: [] as $vieux) @unlink($vieux);
    signature_image($settings, 'GH-' . date('Y') . '-XXXXXX', 'APERCU00', $fichier, $avecAuth);
}

if (!is_file($fichier)) { http_response_code(500); exit; }

header('Content-Type: image/png');
/* Le navigateur peut garder l'image : son adresse change avec son contenu. */
header('Cache-Control: private, max-age=600');
readfile($fichier);
