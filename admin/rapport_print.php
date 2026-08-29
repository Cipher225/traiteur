<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/demandes.php';
require __DIR__ . '/includes/doc_html.php';
require __DIR__ . '/../config/docauth.php';

$uid = (int)$_SESSION['admin_id'];
$id  = (int)($_GET['id'] ?? 0);
$AUTH = isset($_GET['auth']) && is_admin();   // seul l'administrateur peut authentifier

$stmt = $pdo->prepare("SELECT r.*, u.nom AS auteur FROM rapports r LEFT JOIN users u ON u.id=r.employe_user_id WHERE r.id=?");
$stmt->execute([$id]); $r = $stmt->fetch();
if (!$r) { http_response_code(404); exit('Document introuvable.'); }
if (!is_admin() && (int)$r['employe_user_id'] !== $uid) { http_response_code(403); exit('Accès refusé.'); }

$info  = demande_type_info($r['type'] ?? 'rapport');
$asPdf = isset($_GET['pdf']);
$dompdfAutoload = __DIR__ . '/../lib/dompdf/autoload.inc.php';
$avecDompdf = false;   // on n'utilise plus Dompdf : la vue HTML imprimable garantit le même papier partout
$pdfFallback = $asPdf;   // ?pdf=1 ouvre la vue HTML puis lance l'impression

/* Images en data-URI : indispensable pour Dompdf, sans risque à l'écran */
function img_uri(string $chemin): string {
    if (!is_file($chemin)) return '';
    $ext = strtolower(pathinfo($chemin, PATHINFO_EXTENSION));
    $mime = $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg');
    return 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($chemin));
}

/* En-tête du document */
$titre = mb_strtoupper($info[0]);
$entete = [
    ['N° Document', $r['numero']],
    ['Date',        date('d/m/Y', strtotime($r['date_rapport']))],
];
if ($r['envoye_at']) $entete[] = ['Transmis le', date('d/m/Y', strtotime($r['envoye_at']))];

/* Blocs demandeur / informations */
$l1 = [['Nom & prénoms', $r['auteur'] ?? '—'], ['Objet', $r['titre']]];
if ($r['date_debut'] && $r['date_fin']) {
    $l1[] = [($r['type'] === 'conge_maladie' ? "Période d'arrêt" : 'Période'),
             'du ' . date('d/m/Y', strtotime($r['date_debut'])) . ' au ' . date('d/m/Y', strtotime($r['date_fin']))];
} elseif ($r['date_debut']) {
    $l1[] = ['À partir du', date('d/m/Y', strtotime($r['date_debut']))];
}
if (!empty($r['lieu'])) $l1[] = ['Lieu', $r['lieu']];

$l2 = [['Nature', $info[0]], ['Référence', $r['numero']]];
if (!empty($r['hopital'])) $l2[] = ['Hôpital / Clinique', $r['hopital']];
if (!empty($r['motif']))   $l2[] = [($r['type'] === 'explication' ? 'Objet de la demande' : 'Motif'), $r['motif']];
if (demande_decidable($r['type'] ?? '') && !empty($r['decision']) && ($r['decision'] ?? '') !== 'en_attente') {
    $dec = $r['decision'] === 'accepte' ? 'ACCEPTÉE' : 'REFUSÉE';
    if (!empty($r['decision_motif'])) $dec .= ' — ' . $r['decision_motif'];
    $l2[] = ['Décision', $dec];
}
$corps = $r['contenu'] ?: '<p></p>';

/* Authentification */
$qrUri = $checksum = $token = null;
if ($AUTH) {
    $checksum = doc_checksum([$r['numero'], $r['titre'], $r['date_rapport']]);
    $token = doc_token($pdo, $r['type'] ?: 'rapport', (int)$r['id'], $r['numero'], $checksum);
    $qrUri = qr_datauri(doc_verify_url($settings, $token), 4);
}

/* En mode Dompdf, toutes les images passent en data-URI */
$stDoc = $settings;
$base = '..';
if ($avecDompdf) {
    $up = __DIR__ . '/../uploads/';
    $logoFile = !empty($settings['logo']) && is_file($up . $settings['logo'])
        ? $up . $settings['logo'] : __DIR__ . '/../assets/img/logo.png';
    $stDoc['logo'] = '';
    $logoData = img_uri($logoFile);
    if (!empty($settings['signature_img']) && is_file($up . $settings['signature_img'])) $stDoc['signature_img'] = '';
    if (!empty($settings['tampon_img']) && is_file($up . $settings['tampon_img'])) $stDoc['tampon_img'] = '';
}

ob_start(); ?>
<style><?= doc_html_styles() ?>
  .cadre-page{width:100%;border-collapse:collapse}
  .cadre-page > tbody > tr > td, .cadre-page > tfoot > tr > td{padding:0;border:none}
  @media print{
    @page{ size:A4; margin:16mm 0 4mm 0; }
    .dh{padding-top:2px}
    .cadre-page tfoot{display:table-footer-group}
    .cadre-page > tbody > tr > td{vertical-align:top}
    /* espace reserve en bas de chaque page, a la hauteur du pied de page */
    .pied-reserve{height:21mm}
    .df{position:fixed;bottom:6mm;left:0;right:0;margin:0;background:#fff}
    .df-page{position:fixed;bottom:2mm;left:0;right:0;margin:0;padding:0;background:#fff}
    .auth{page-break-inside:avoid;break-inside:avoid}
    .content p{page-break-inside:avoid}
  }
  .content{padding:4px 40px 0;font-size:12.5px;line-height:1.7;color:#2d3442}
  .content h1{font-size:17px;margin:12px 0 7px;color:#0a1f44}
  .content h2{font-size:15px;margin:11px 0 6px;color:#0a1f44}
  .content h3{font-size:13.5px;margin:10px 0 5px;color:#0a1f44}
  .content p{margin:0 0 10px}
  .content ul,.content ol{margin:0 0 10px 22px}
  .content blockquote{border-left:3px solid #d4a526;padding-left:13px;color:#5a6478;font-style:italic;margin:0 0 10px}
  .content img{max-width:100%}
</style>
<div class="sheet">
  <table class="cadre-page"><tfoot><tr><td><div class="pied-reserve"></div></td></tr></tfoot><tbody><tr><td>
  <div class="flex-fill">
  <?php if ($avecDompdf): ?>
  <div class="dh"><div class="dh-left">
    <img src="<?= $logoData ?>" alt="">
    <div class="ent"><?= e(mb_strtoupper($settings['nom_entreprise'] ?? '')) ?></div>
    <?php if (trim((string)($settings['slogan'] ?? '')) !== ''): ?><div class="slg"><?= e($settings['slogan']) ?></div><?php endif; ?>
  </div><div class="dh-right"><div class="dh-title"><?= e($titre) ?></div><div class="dh-rule"></div>
    <div class="dh-info"><?php foreach ($entete as $row): ?><div class="r"><b><?= e($row[0]) ?></b><i>:</i><span><?= e($row[1]) ?></span></div><?php endforeach; ?></div>
  </div></div><div class="dh-sep"></div>
  <?php else: ?>
  <?= doc_html_header($settings, $titre, $entete, $base) ?>
  <?php endif; ?>

  <?= doc_html_parties('Demandeur', $l1, 'Informations', $l2) ?>
  <div class="content"><?= $corps ?></div>
  <?php if ($qrUri): ?><?= doc_html_auth($avecDompdf ? $stDoc : $settings, $base, $qrUri, $checksum, $token) ?><?php endif; ?>
  </div>
  </td></tr></tbody></table>
  <?= doc_html_footer($settings) ?>
</div>
<?php
$sheet = ob_get_clean();

if ($avecDompdf) {
    require_once $dompdfAutoload;
    $html = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><style>body{font-family:DejaVu Sans,sans-serif;background:#fff;padding:0}.sheet{box-shadow:none;max-width:none}</style></head><body>' . $sheet . '</body></html>';
    $o = new \Dompdf\Options();
    $o->set('isRemoteEnabled', false); $o->set('defaultFont', 'DejaVu Sans');
    $d = new \Dompdf\Dompdf($o);
    $d->loadHtml($html, 'UTF-8'); $d->setPaper('A4', 'portrait'); $d->render();
    $d->stream(preg_replace('/[^A-Za-z0-9_-]/', '', $r['numero']) . '.pdf', ['Attachment' => true]);
    exit;
}
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8">
<title><?= e($titre . ' ' . $r['numero']) ?></title>
</head><body>
<div class="barre">
  <button class="btn-p" onclick="window.print()">🖨️ Imprimer / Enregistrer en PDF</button>
  <a class="btn-g" href="javascript:history.back()">← Retour</a>
</div>
<?= $sheet ?>
<?php if ($pdfFallback): ?>
<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},400);});</script>
<?php endif; ?>
</body></html>
