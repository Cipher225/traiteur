<?php
/* ============================================================================
   SIGNATURE ÉLECTRONIQUE DE L'ENTREPRISE
   ----------------------------------------------------------------------------
   Soyons précis sur ce qui est possible : aucune signature affichée dans un
   email ne peut être rendue « incopiable ». N'importe qui peut faire une copie
   d'écran. Ce qui compte, ce n'est donc pas d'empêcher la copie — c'est de
   rendre toute copie DÉTECTABLE.

   Le principe retenu est celui des documents authentifiables :

   • chaque message reçoit une référence unique et une empreinte calculée à
     partir de son contenu (destinataire, objet, texte, date) ;
   • la signature est une IMAGE fabriquée par le serveur, jamais du texte :
     elle ne peut pas être modifiée dans un client de messagerie ;
   • elle porte un lien de vérification. Le destinataire clique et le serveur
     confirme — ou non — que ce message précis est bien parti de chez vous.

   Un faussaire peut recopier l'image. Mais son message n'aura pas de référence
   valide : la vérification échouera, et la fraude sera visible immédiatement.
   C'est exactement ainsi que fonctionnent les certificats professionnels.
   ============================================================================ */

require_once __DIR__ . '/docauth.php';

/* Empreinte d'un message : elle change dès qu'un seul caractère change. */
function signature_empreinte(string $destinataire, string $sujet, string $corps, string $date): string
{
    $base = mb_strtolower(trim($destinataire)) . '|' . trim($sujet) . '|'
          . preg_replace('/\s+/', ' ', strip_tags($corps)) . '|' . $date;
    return strtoupper(substr(hash('sha256', $base), 0, 12));
}

/* Référence lisible, communiquée dans le message : GH-2026-A4F91C */
function signature_reference(PDO $pdo): string
{
    $annee = date('Y');
    do {
        $ref = 'GH-' . $annee . '-' . strtoupper(bin2hex(random_bytes(3)));
        $st = $pdo->prepare('SELECT COUNT(*) FROM emails_envoyes WHERE reference = ?');
        $st->execute([$ref]);
    } while ((int)$st->fetchColumn() > 0);
    return $ref;
}

/* ---------------------------------------------------------------------------
   Fabrique l'image de signature. Tout est dessiné par le serveur : le
   destinataire reçoit une image, pas du texte modifiable.
   --------------------------------------------------------------------------- */
function signature_image(array $s, string $reference, string $empreinte, string $chemin,
                        bool $avecAuth = true): bool
{
    if (!function_exists('imagecreatetruecolor')) return false;

    /* Sans authentification, la signature garde toutes les coordonnées de
       l'entreprise : seul le cartouche de vérification disparaît. Le bloc de
       texte occupe alors toute la largeur. */
    $L = $avecAuth ? 1100 : 820;
    $H = $avecAuth ? 300  : 250;
    $im = imagecreatetruecolor($L, $H);
    imagesavealpha($im, true);
    imagealphablending($im, true);
    imagefilledrectangle($im, 0, 0, $L, $H, imagecolorallocate($im, 255, 255, 255));

    $marine = imagecolorallocate($im, 10, 31, 68);
    $or     = imagecolorallocate($im, 184, 135, 15);
    $orClair= imagecolorallocate($im, 212, 165, 38);
    $gris   = imagecolorallocate($im, 110, 118, 133);
    $trait  = imagecolorallocate($im, 226, 201, 139);

    /* Bande dorée verticale, comme sur les documents */
    imagefilledrectangle($im, 0, 0, 5, $H, $orClair);

    $police  = __DIR__ . '/../assets/fonts/DejaVuSans.ttf';
    $policeG = __DIR__ . '/../assets/fonts/DejaVuSans-Bold.ttf';
    $dispo   = is_file($police) && is_file($policeG) && function_exists('imagettftext');

    $ecrire = function ($txt, $x, $y, $taille, $couleur, $gras = false) use ($im, $police, $policeG, $dispo) {
        if ($dispo) {
            imagettftext($im, $taille, 0, $x, $y, $couleur, $gras ? $policeG : $police, $txt);
        } else {
            /* Sans polices vectorielles, on utilise la police interne : moins
               élégant, mais la signature reste lisible et vérifiable. */
            imagestring($im, 5, $x, $y - 14, $txt, $couleur);
        }
    };

    $x = 46; $y = 62;

    /* Logo, si disponible */
    $logo = trim((string)($s['logo'] ?? ''));
    $cheminLogo = __DIR__ . '/../uploads/' . $logo;
    if ($logo !== '' && is_file($cheminLogo)) {
        $src = @imagecreatefromstring(file_get_contents($cheminLogo));
        if ($src) {
            $lw = imagesx($src); $lh = imagesy($src);
            $h2 = 74; $l2 = (int)($lw * $h2 / max(1, $lh));
            imagecopyresampled($im, $src, $x, 26, 0, 0, $l2, $h2, $lw, $lh);
            imagedestroy($src);
            $x += $l2 + 30;
        }
    }

    $ecrire(mb_strtoupper((string)($s['nom_entreprise'] ?? '')), $x, $y, 21, $marine, true);
    $ecrire(mb_strtoupper((string)($s['slogan'] ?? '')), $x, $y + 24, 9, $or);

    /* Filet de séparation */
    imagefilledrectangle($im, 46, 118, $L - ($avecAuth ? 260 : 46), 119, $trait);

    /* Coordonnées */
    $lignes = array_values(array_filter([
        trim((string)($s['adresse'] ?? '')),
        trim((string)($s['telephone'] ?? '')) . (trim((string)($s['whatsapp'] ?? '')) ? '   ·   ' . $s['whatsapp'] : ''),
        trim((string)($s['email'] ?? '')) . (trim((string)($s['site_url'] ?? '')) ? '   ·   ' . preg_replace('#^https?://#', '', (string)$s['site_url']) : ''),
        (trim((string)($s['rccm'] ?? '')) ? 'RCCM : ' . $s['rccm'] : '')
            . (trim((string)($s['ncc'] ?? '')) ? '   ·   N° CC : ' . $s['ncc'] : ''),
    ]));
    $yl = 150;
    foreach ($lignes as $ligne) {
        imagefilledellipse($im, 52, $yl - 5, 7, 7, $orClair);
        $ecrire($ligne, 66, $yl, 11, $gris);
        $yl += 26;
    }

    /* Bloc d'authentification, à droite — uniquement si le message est
       authentifié. Sinon la signature s'arrête aux coordonnées. */
    if (!$avecAuth) {
        $ok = imagepng($im, $chemin);
        imagedestroy($im);
        return (bool)$ok;
    }

    $xa = $L - 240;
    imagefilledrectangle($im, $xa - 20, 24, $L - 24, $H - 24, imagecolorallocate($im, 250, 250, 252));
    imagerectangle($im, $xa - 20, 24, $L - 24, $H - 24, $trait);

    $ecrire('MESSAGE AUTHENTIFIABLE', $xa, 52, 9, $or, true);
    $ecrire('Référence', $xa, 78, 9, $gris);
    $ecrire($reference, $xa, 96, 12, $marine, true);
    $ecrire('Empreinte', $xa, 122, 9, $gris);
    $ecrire($empreinte, $xa, 140, 11, $marine, true);

    /* QR de vérification */
    $url = adresse_site($s) . '/verifier.php?c=' . $reference;   // page unique
    $tmp = sys_get_temp_dir() . '/qr-sig-' . $reference . '.png';
    if (function_exists('qr_png_fichier') && qr_png_fichier($url, $tmp, 4, 1)) {
        $qr = @imagecreatefrompng($tmp);
        if ($qr) {
            imagecopyresampled($im, $qr, $xa, 156, 0, 0, 96, 96, imagesx($qr), imagesy($qr));
            imagedestroy($qr);
            $ecrire('Scannez pour vérifier', $xa + 104, 200, 8.5, $gris);
            $ecrire("l'authenticité de ce", $xa + 104, 216, 8.5, $gris);
            $ecrire('message', $xa + 104, 232, 8.5, $gris);
        }
        @unlink($tmp);
    }

    $ok = imagepng($im, $chemin);
    imagedestroy($im);
    return (bool)$ok;
}

/* ----------------------------------------------------------------------------
   Habille un message de la signature de l'entreprise.

   Tout email sortant doit porter la même signature, qu'il vienne du module
   E-mail ou du bouton « Envoyer par mail » d'un document. Sans cela, le client
   reçoit tantôt un message signé, tantôt un texte nu — et se demande si le
   second vient bien de vous.

   L'image est jointe en interne (cid) : elle s'affiche même quand le client
   bloque les images distantes, ce qui est le réglage par défaut de la plupart
   des messageries.

   Renvoie le corps complet ; $images est rempli des pièces à intégrer, et
   $aSupprimer des fichiers temporaires à effacer après l'envoi.
   ---------------------------------------------------------------------------- */
function email_signe(PDO $pdo, array $settings, string $corps,
                     array &$images, array &$aSupprimer,
                     ?bool $authentifier = null, string $destinataire = '', string $sujet = '',
                     ?int $clientId = null, string $nomDestinataire = '', string $pieces = ''): string
{
    /* Si l'appelant ne tranche pas, on suit le réglage général — celui que
       pilote l'interrupteur du module E-mail. Un document envoyé depuis une
       facture doit être authentifiable au même titre qu'un message. */
    if ($authentifier === null) {
        $authentifier = ($settings['signature_auth_defaut'] ?? '1') !== '0';
    }

    $dossier = __DIR__ . '/../uploads/signatures';
    if (!is_dir($dossier)) @mkdir($dossier, 0775, true);

    $date      = date('Y-m-d H:i:s');
    $reference = signature_reference($pdo);
    $empreinte = $authentifier ? signature_empreinte($destinataire, $sujet, $corps, $date) : '';
    $fichier   = $dossier . '/' . $reference . '.png';

    if (!signature_image($settings, $reference, $empreinte, $fichier, $authentifier)) {
        return $corps;                     // sans image, on renvoie le texte tel quel
    }

    $images[] = ['cid' => 'signature-entreprise', 'chemin' => $fichier];

    $logo = __DIR__ . '/../uploads/' . (string)($settings['logo'] ?? '');
    if (!empty($settings['logo']) && is_file($logo)) {
        $images[] = ['cid' => 'logo-entreprise', 'chemin' => $logo];
    }

    /* Une signature non authentifiée ne sert à rien après l'envoi : on la
       supprime pour ne pas encombrer le disque. */
    if (!$authentifier) {
        $aSupprimer[] = $fichier;
    } else {
        /* Un message authentifiable doit être ENREGISTRÉ, sinon la page de
           vérification ne reconnaîtra pas sa référence et le destinataire
           conclura que le document est faux. */
        try {
            $pdo->prepare('INSERT INTO emails_envoyes (reference, empreinte, destinataire,
                           destinataire_nom, client_id, sujet, corps, piece_jointe,
                           envoye_par, envoye_par_nom, statut, envoye_le, authentifie)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)')
                ->execute([$reference, $empreinte, $destinataire,
                           mb_substr($nomDestinataire, 0, 150), $clientId,
                           mb_substr($sujet, 0, 200), $corps, mb_substr($pieces, 0, 190),
                           (int)($_SESSION['admin_id'] ?? 0) ?: null,
                           (string)($_SESSION['admin_nom'] ?? 'Système'),
                           'envoye', $date]);
        } catch (Throwable $e) { /* l'envoi prime sur la traçabilité */ }
    }

    $site = adresse_site($settings);
    $url  = $site . '/verifier.php?c=' . urlencode($reference);

    return $corps
        . '<div style="margin-top:26px;border-top:1px solid #e8ecf2;padding-top:16px">'
        . '<img src="cid:signature-entreprise" alt="' . e($settings['nom_entreprise'] ?? '')
        . '" style="max-width:100%;height:auto;display:block">'
        . ($authentifier
            ? '<p style="margin:10px 0 0;font-size:11px;color:#8a9ab5;line-height:1.6">'
              . 'Référence de ce message : <strong style="color:#0a1f44">' . e($reference) . '</strong>'
              . ($site !== '' ? ' — <a href="' . e($url) . '" style="color:#b8870f">vérifier son authenticité</a>' : '')
              . '</p>'
            : '')
        . '</div>';
}
