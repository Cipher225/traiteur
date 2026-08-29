<?php
/* ============================================================================
   GÉNÉRATEUR D'ICÔNE DE L'APPLICATION (PWA)
   Fabrique une icône carrée (192 ou 512 px) à partir du logo de l'entreprise,
   centré sur le fond navy de la charte. Version « maskable » (m=1) avec marge.
   Le résultat est mis en cache par le navigateur.
   ============================================================================ */
require __DIR__ . '/config/db.php';
$s = get_settings($pdo);

$taille = (int)($_GET['t'] ?? 192);
if (!in_array($taille, [192, 512], true)) $taille = 192;
$maskable = !empty($_GET['m']);   // version avec marge de sécurité (icône adaptative)

// Trouver le fichier logo (paramètre personnalisé en priorité, sinon logo par défaut)
$logoFichier = trim((string)($s['logo'] ?? ''));
$cheminLogo = '';
if ($logoFichier !== '' && is_file(__DIR__ . '/uploads/' . $logoFichier)) {
    $cheminLogo = __DIR__ . '/uploads/' . $logoFichier;
} elseif (is_file(__DIR__ . '/assets/img/logo.png')) {
    $cheminLogo = __DIR__ . '/assets/img/logo.png';
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=604800'); // 7 jours

// Si GD n'est pas disponible ou pas de logo : renvoyer le logo brut (repli)
if (!function_exists('imagecreatetruecolor') || $cheminLogo === '') {
    if ($cheminLogo !== '') { readfile($cheminLogo); }
    exit;
}

// Toile carrée transparente
$canvas = imagecreatetruecolor($taille, $taille);
imagesavealpha($canvas, true);
$transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
imagefill($canvas, 0, 0, $transparent);

$blanc = imagecolorallocate($canvas, 255, 255, 255);
$navy  = imagecolorallocate($canvas, 10, 31, 68); // #0a1f44 (bleu foncé de la charte)

// Rayon des coins arrondis. Pour la version « maskable », pas d'arrondi (fond plein) :
// c'est le système qui applique sa propre forme. Pour la version normale, on arrondit nous-mêmes.
$rayon = $maskable ? 0 : (int)round($taille * 0.22);

// Dessine un rectangle blanc à coins arrondis qui remplit toute l'icône
function rect_arrondi($img, $x1, $y1, $x2, $y2, $r, $couleur) {
    if ($r <= 0) { imagefilledrectangle($img, $x1, $y1, $x2, $y2, $couleur); return; }
    imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $couleur);
    imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $couleur);
    imagefilledellipse($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $couleur);
    imagefilledellipse($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $couleur);
    imagefilledellipse($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $couleur);
    imagefilledellipse($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $couleur);
}

imagealphablending($canvas, true);
// Fond blanc arrondi
rect_arrondi($canvas, 0, 0, $taille - 1, $taille - 1, $rayon, $blanc);

// Contour bleu foncé fin, arrondi lui aussi (dessiné en superposant blanc à l'intérieur)
$ep = max(2, (int)round($taille * 0.012));
if (!$maskable) {
    rect_arrondi($canvas, 0, 0, $taille - 1, $taille - 1, $rayon, $navy);
    rect_arrondi($canvas, $ep, $ep, $taille - 1 - $ep, $taille - 1 - $ep, max(0, $rayon - $ep), $blanc);
} else {
    // maskable : contour droit fin (le système arrondira)
    for ($i = 0; $i < $ep; $i++) {
        imagerectangle($canvas, $i, $i, $taille - 1 - $i, $taille - 1 - $i, $navy);
    }
}

// Charger le logo
$infos = @getimagesize($cheminLogo);
$logo = null;
if ($infos) {
    switch ($infos[2]) {
        case IMAGETYPE_PNG:  $logo = @imagecreatefrompng($cheminLogo); break;
        case IMAGETYPE_JPEG: $logo = @imagecreatefromjpeg($cheminLogo); break;
        case IMAGETYPE_WEBP: if (function_exists('imagecreatefromwebp')) $logo = @imagecreatefromwebp($cheminLogo); break;
        case IMAGETYPE_GIF:  $logo = @imagecreatefromgif($cheminLogo); break;
    }
}

if ($logo) {
    $lw = imagesx($logo); $lh = imagesy($logo);
    // Zone utile : plus petite si maskable (marge de sécurité de 20%)
    $zone = $maskable ? (int)($taille * 0.62) : (int)($taille * 0.72);
    $ratio = min($zone / $lw, $zone / $lh);
    $nw = (int)($lw * $ratio); $nh = (int)($lh * $ratio);
    $dx = (int)(($taille - $nw) / 2); $dy = (int)(($taille - $nh) / 2);
    imagealphablending($canvas, true);
    imagecopyresampled($canvas, $logo, $dx, $dy, 0, 0, $nw, $nh, $lw, $lh);
    imagedestroy($logo);
}

imagepng($canvas);
imagedestroy($canvas);

