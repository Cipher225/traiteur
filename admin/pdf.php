<?php
/* ============================================================================
   TÉLÉCHARGEMENT PDF
   ----------------------------------------------------------------------------
   Produit le PDF sur le serveur et l'envoie au navigateur. Le rendu ne dépend
   donc plus du navigateur ni de l'appareil : le fichier est le même pour tous.

   Accessible depuis l'administration comme depuis l'espace client ; dans ce
   dernier cas, espace-client/doc-pdf.php a déjà vérifié que le document
   appartient bien au client.
   ============================================================================ */

if (!defined('PDF_ESPACE_CLIENT')) {
    require __DIR__ . '/includes/auth.php';
}
require_once __DIR__ . '/pdf-moteur.php';

$type = $_GET['type'] ?? 'facture';
$id   = (int)($_GET['id'] ?? 0);

/* ---- Chargement du document ---- */
$doc = null;
switch ($type) {
    case 'facture': case 'proforma': case 'livraison': $doc = get_facture($pdo, $id); break;
    case 'recu':                                       $doc = get_recu($pdo, $id);    break;
    case 'fiche':                                      $doc = get_fiche($pdo, $id);   break;
    case 'rapport':
        require_once __DIR__ . '/includes/demandes.php';
        $st = $pdo->prepare("SELECT r.*, u.nom AS auteur FROM rapports r
                             LEFT JOIN users u ON u.id = r.employe_user_id WHERE r.id = ?");
        $st->execute([$id]);
        $doc = $st->fetch() ?: null;
        if ($doc) {
            $info = demande_type_info((string)($doc['type'] ?? 'rapport'));
            $doc['type_libelle'] = $info[0] ?? 'Rapport';   // le libellé est la première valeur
            /* Un employé ne consulte que ses propres documents. */
            if (!is_admin() && (int)$doc['employe_user_id'] !== (int)($_SESSION['admin_id'] ?? 0)) {
                http_response_code(403); exit('Accès refusé.');
            }
        }
        break;
}
if (!$doc) { http_response_code(404); exit('Document introuvable.'); }

/* ---- Authentification : empreinte et QR, comme sur la version imprimable ---- */
$qrFichier = $empreinte = $code = null;
try {
    if ($type === 'fiche') {
        $cle = [$doc['numero'], (string)$doc['periode'], (string)$doc['net_a_payer']];
    } elseif ($type === 'rapport') {
        $cle = [$doc['numero'], (string)$doc['titre'], (string)$doc['date_rapport']];
    } else {
        $cle = [$doc['numero'], (string)($doc['date_emission'] ?? $doc['date_paiement'] ?? ''),
                (string)($doc['montant_ttc'] ?? $doc['montant'] ?? '')];
    }
    $empreinte = doc_checksum($cle);
    $code      = doc_token($pdo, $type, (int)$doc['id'], $doc['numero'], $empreinte);

    /* Le générateur de PDF ne lit que les images matricielles : on écrit le QR
       en PNG dans un fichier temporaire, supprimé juste après le rendu. */
    $dossier = __DIR__ . '/../uploads/tmp';
    if (!is_dir($dossier)) @mkdir($dossier, 0775, true);
    $chemin = $dossier . '/qr-' . $type . '-' . $id . '-' . bin2hex(random_bytes(4)) . '.png';
    if (qr_png_fichier(doc_verify_url($settings, $code), $chemin, 5)) $qrFichier = $chemin;
} catch (Throwable $e) { /* l'absence de QR ne doit jamais empêcher le téléchargement */ }

/* ---- Rendu ---- */
$html = pdf_document_html($pdo, $settings, $type, $doc, $qrFichier, $empreinte, $code);

require_once __DIR__ . '/../lib/dompdf/autoload.inc.php';

$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', false);      // aucune ressource distante : plus rapide et plus sûr
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('chroot', realpath(__DIR__ . '/..'));

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

/* ============================================================================
   BLOC D'AUTHENTIFICATION — dessiné sur la DERNIÈRE page seulement
   ----------------------------------------------------------------------------
   Un élément « fixe » se répéterait sur toutes les pages. On dessine donc
   directement sur la page finale, à une hauteur constante, juste au-dessus du
   pied de page : le bloc est ainsi toujours au même endroit, quel que soit le
   nombre de pages ou la longueur du contenu.
   ============================================================================ */
$canvas  = $dompdf->getCanvas();
$largeur = $canvas->get_width();       // 595 pt pour une page A4
$hauteur = $canvas->get_height();      // 842 pt

$mm  = fn($v) => $v * 2.834645;        // millimètres → points
$GAUCHE = $mm(10);
$DROITE = $largeur - $mm(10);
/* Le pied de page occupe les 22 derniers millimètres. Le bloc se place
   au-dessus : sa ligne haute est donc à 46 mm du bas de la feuille. */
$BASE   = $hauteur - $mm(60);

$signataire = (string)($settings['signataire_fonction'] ?? 'La Direction');
$dossier    = realpath(__DIR__ . '/..') . '/uploads/';
$fTampon    = !empty($settings['tampon_img'])    && is_file($dossier . $settings['tampon_img'])    ? $dossier . $settings['tampon_img']    : null;

/* On applique au tampon un rendu naturel : légère inclinaison et encrage
   irrégulier, comme un cachet réellement apposé. La graine dépend du document,
   pour qu'un même document présente toujours le même tampon. */
if ($fTampon) {
    require_once __DIR__ . '/../config/tampon.php';
    $tmp = __DIR__ . '/../uploads/tmp';
    if (!is_dir($tmp)) @mkdir($tmp, 0775, true);
    $fNaturel = $tmp . '/tampon-' . $type . '-' . $id . '.png';
    if (tampon_naturel($fTampon, $fNaturel, crc32($type . $id . ($doc['numero'] ?? '')))) {
        $fTampon = $fNaturel;
    }
}
$fSignature = !empty($settings['signature_img']) && is_file($dossier . $settings['signature_img']) ? $dossier . $settings['signature_img'] : null;

$canvas->page_script(function ($page, $pages, $c, $fm)
        use ($qrFichier, $empreinte, $code, $signataire, $fTampon, $fSignature, $GAUCHE, $DROITE, $BASE, $mm) {

    if ($page !== $pages) return;      // uniquement la dernière page

    $police = $fm->getFont('DejaVu Sans');
    $gras   = $fm->getFont('DejaVu Sans', 'bold');
    $or     = [0.72, 0.53, 0.15];
    $gris   = [0.43, 0.46, 0.52];
    $marine = [0.04, 0.12, 0.27];

    // Filet de séparation
    $c->line($GAUCHE, $BASE, $DROITE, $BASE, [0.91, 0.93, 0.95], 0.7);

    $y = $BASE + $mm(2);

    // ---- Colonne de gauche : QR et empreinte ----
    $xTexte = $GAUCHE;
    if ($qrFichier && is_file($qrFichier)) {
        $c->image($qrFichier, $GAUCHE, $y, $mm(17), $mm(17));
        $xTexte = $GAUCHE + $mm(20);
    }
    $c->text($xTexte, $y + 1,  'DOCUMENT AUTHENTIFIABLE', $gras, 6.5, $or, 0.8);
    $c->text($xTexte, $y + 11, "Scannez le code pour vérifier l'authenticité", $police, 6.2, $gris);
    if ($empreinte) $c->text($xTexte, $y + 20, 'Empreinte : ' . $empreinte, $police, 6.2, $gris);
    if ($code)      $c->text($xTexte, $y + 29, 'Code : ' . mb_substr($code, 0, 14) . '…', $police, 6.2, $gris);

    // ---- Colonne de droite : signataire, tampon et paraphe ----
    $l = $fm->getTextWidth($signataire, $gras, 8);
    $c->text($DROITE - $l, $y + 1, $signataire, $gras, 8, $marine);
    $c->line($DROITE - $mm(62), $y + 12, $DROITE, $y + 12, [0.70, 0.73, 0.78], 0.6);

    /* Tampon à sa taille réelle sur A4 (42 mm, comme un cachet d'entreprise),
       et paraphe juste en dessous — l'ordre habituel sur un document signé. */
    $lTampon = $mm(42);
    $hTampon = $mm(16);
    $xTampon = $DROITE - $mm(52);
    $yImg    = $y + $mm(5);

    if ($fTampon) $c->image($fTampon, $xTampon, $yImg, $lTampon, $hTampon);

    /* Le paraphe est tracé APRÈS le tampon et vient se poser sur sa partie
       basse, en débordant légèrement : c'est ainsi qu'on signe un document
       déjà cacheté. */
    if ($fSignature) {
        $lSig = $mm(34);
        $c->image($fSignature, $xTampon + ($lTampon - $lSig) / 2,
                  $yImg + $hTampon - $mm(4), $lSig, $mm(12));
    }
});

/* ---- Nom du fichier ---- */
$prefixes = ['facture' => 'Facture', 'proforma' => 'Proforma', 'livraison' => 'Bon-de-livraison',
             'recu' => 'Recu', 'fiche' => 'Bulletin-de-paie', 'rapport' => 'Document'];
$numFichier = ($type === 'livraison') ? numero_bon_livraison($pdo, (int)$doc['id']) : $doc['numero'];
$nom = ($prefixes[$type] ?? 'Document') . '-' . preg_replace('/[^A-Za-z0-9\-]/', '-', $numFichier) . '.pdf';

/* Le PDF est produit AVANT de supprimer l'image du QR : le dessin de la
   dernière page a besoin du fichier jusqu'au bout. */
$sortie = $dompdf->output();
if ($qrFichier && is_file($qrFichier)) @unlink($qrFichier);
if (!empty($fNaturel) && is_file($fNaturel)) @unlink($fNaturel);

header('Content-Type: application/pdf');
header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $nom . '"');
header('Content-Length: ' . strlen($sortie));
header('Cache-Control: private, max-age=0, must-revalidate');
echo $sortie;
