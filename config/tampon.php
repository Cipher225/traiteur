<?php
/* ============================================================================
   RENDU NATUREL DU TAMPON
   ----------------------------------------------------------------------------
   Un cachet appliqué à la main n'est jamais parfait : il est légèrement de
   travers, l'encre est inégale, et certains bords sont plus pâles. On applique
   donc au tampon une légère rotation, une transparence partielle et de fines
   irrégularités d'encrage. Le résultat est bien plus crédible qu'une image nette.
   ============================================================================ */
function tampon_naturel(string $source, string $destination, int $graine = 0): bool
{
    if (!function_exists('imagecreatefrompng')) return false;
    $info = @getimagesize($source);
    if (!$info) return false;

    $im = match ($info[2]) {
        IMAGETYPE_PNG  => @imagecreatefrompng($source),
        IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : null,
        default        => null,
    };
    if (!$im) return false;

    imagealphablending($im, false);
    imagesavealpha($im, true);
    $l = imagesx($im); $h = imagesy($im);

    /* La graine dépend du document : le même document garde toujours le même
       tampon, mais deux documents différents n'ont pas exactement le même. */
    mt_srand($graine ?: 12345);

    /* 1. Encrage inégal : on éclaircit légèrement des zones, comme un cachet
          qui n'a pas appuyé uniformément. */
    $zones = [];
    for ($i = 0; $i < 7; $i++) {
        $zones[] = [mt_rand(0, $l), mt_rand(0, $h), mt_rand((int)($l * 0.12), (int)($l * 0.30))];
    }

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $l; $x++) {
            $c = imagecolorat($im, $x, $y);
            $a = ($c >> 24) & 0x7F;
            if ($a >= 120) continue;                 // déjà transparent

            $perte = 0;
            foreach ($zones as [$zx, $zy, $zr]) {
                $d = sqrt(($x - $zx) ** 2 + ($y - $zy) ** 2);
                if ($d < $zr) $perte = max($perte, (1 - $d / $zr) * 26);
            }
            /* Micro-grain : quelques points d'encre manquants */
            if (mt_rand(0, 100) < 4) $perte += mt_rand(8, 30);

            $na = min(127, (int)round($a + $perte + 14));   // +14 : encre jamais opaque
            $couleur = ($na << 24) | ($c & 0x00FFFFFF);
            imagesetpixel($im, $x, $y, $couleur);
        }
    }

    /* 2. Légère inclinaison, comme un cachet posé à la main */
    $angle = (mt_rand(0, 100) / 100) * 5 - 2.5;          // entre -2,5° et +2,5°
    $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
    $tourne = imagerotate($im, $angle, $transparent);
    imagealphablending($tourne, false);
    imagesavealpha($tourne, true);

    $ok = imagepng($tourne, $destination);
    imagedestroy($im); imagedestroy($tourne);
    return (bool)$ok;
}
