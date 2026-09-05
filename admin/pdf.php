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
}
if (!$doc) { http_response_code(404); exit('Document introuvable.'); }

/* ---- Authentification : empreinte et QR, comme sur la version imprimable ---- */
$qrFichier = $empreinte = $code = null;
try {
    $cle = ($type === 'fiche')
        ? [$doc['numero'], (string)$doc['periode'], (string)$doc['net_a_payer']]
        : [$doc['numero'], (string)($doc['date_emission'] ?? $doc['date_paiement'] ?? ''),
           (string)($doc['montant_ttc'] ?? $doc['montant'] ?? '')];
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

/* ---- Nom du fichier ---- */
$prefixes = ['facture' => 'Facture', 'proforma' => 'Proforma', 'livraison' => 'Bon-de-livraison',
             'recu' => 'Recu', 'fiche' => 'Bulletin-de-paie'];
$nom = ($prefixes[$type] ?? 'Document') . '-' . preg_replace('/[^A-Za-z0-9\-]/', '-', $doc['numero']) . '.pdf';

if ($qrFichier && is_file($qrFichier)) @unlink($qrFichier);

header('Content-Type: application/pdf');
header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $nom . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
echo $dompdf->output();
