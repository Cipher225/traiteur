<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/../config/docauth.php';
require __DIR__ . '/includes/doc_html.php';

/* ============================================================================
   IMPRESSION D'UN DOCUMENT RÉDIGÉ
   Reprend l'en-tête, le pied de page et, si demandé, le tampon et la signature
   de la société : le rendu est identique aux factures et bons de livraison.
   ============================================================================ */

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM documents_texte WHERE id=?');
$st->execute([$id]);
$doc = $st->fetch();
if (!$doc) { http_response_code(404); exit('Document introuvable.'); }

$settings = get_settings($pdo);
$titre = mb_strtoupper($doc['titre'] !== '' ? $doc['titre'] : $doc['categorie']);
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($doc['titre']) ?></title>
<style>
<?= doc_html_styles() ?>
  /* Mise en forme du texte rédigé */
  .txt{padding:6px 40px 0;font-size:12.6px;line-height:1.45;color:#2d3442}
  .txt h1{font-size:19px;color:#0a1f44;margin:14px 0 8px}
  .txt h2{font-size:15px;color:#0a1f44;margin:14px 0 6px}
  .txt h3{font-size:13.5px;color:#0a1f44;margin:12px 0 5px}
  .txt p{margin:0 0 8px}
  .txt ul,.txt ol{margin:0 0 10px;padding-left:22px}
  .txt li{margin-bottom:4px}
  .txt blockquote{margin:10px 0;padding:8px 14px;border-left:3px solid #d4a526;background:#faf7ef;color:#41485a}
  .txt pre{background:#f4f6fa;padding:10px;border-radius:6px;font-size:11px;white-space:pre-wrap}
  .txt table{width:100%;border-collapse:collapse;margin:10px 0}
  .txt th,.txt td{border:1px solid #d7dde8;padding:6px 9px;font-size:11.5px;text-align:left}
  .txt th{background:#0a1f44;color:#fff;font-weight:700}
  .txt img{max-width:100%;height:auto}
  .txt a{color:#1d4ed8}
  .saut-page{break-after:page;page-break-after:always;height:0}
  .txt .zone-texte{border:1px solid #c8d0de;border-radius:6px;padding:10px 13px;margin:12px 0;background:#fbfcfe}
  .txt .zone-encadree{border-left:4px solid #d4a526;background:#faf7ef;border-radius:0 6px 6px 0;
    padding:10px 14px;margin:12px 0}
  /* Cadre de page : le pied de page est repete et sa hauteur reservee sur chaque page,
     le texte ne peut donc jamais passer dessous. */
  .cadre-page{width:100%;border-collapse:collapse}
  .cadre-page > tbody > tr > td, .cadre-page > tfoot > tr > td{padding:0;border:none}
  @media print{
    /* 16 mm d'air en haut de CHAQUE page (la page 2 et les suivantes ne sont plus collees au bord) */
    @page{ size:A4; margin:16mm 0 4mm 0; }
    /* l'en-tete de la 1re page se resserre d'autant : sa mise en page ne change pas */
    .dh{padding-top:2px}
    /* Le cadre occupe toute la hauteur de la page : le pied de page se retrouve donc
       colle en bas de CHAQUE page, y compris la derniere, meme si elle est peu remplie. */
    .cadre-page > tbody > tr > td{vertical-align:top}
    .cadre-page tfoot{display:table-footer-group}
    /* Espace vide reserve en bas de CHAQUE page, a la hauteur exacte du pied de page */
    .pied-reserve{height:21mm}
    /* Le vrai pied de page est fixe : il apparait en bas de chaque page, sans jamais
       chevaucher le texte puisque la place lui est reservee ci-dessus. */
    .df{position:fixed;bottom:6mm;left:0;right:0;margin:0;background:#fff}
    .df-page{position:fixed;bottom:2mm;left:0;right:0;margin:0;padding:0;background:#fff}
    .auth{padding-bottom:0;margin:26px 40px 14px;page-break-inside:avoid;break-inside:avoid}
    .txt table{page-break-inside:auto}
    .txt tr{page-break-inside:avoid}
    .txt h1,.txt h2,.txt h3{page-break-after:avoid}
  }
</style>
</head>
<body data-space="admin">

<div class="barre">
  <button class="btn-p" onclick="imprimerDoc()">🖨️ Imprimer / Enregistrer en PDF</button>
  <a class="btn-p btn-r" href="documents.php?edit=<?= (int)$doc['id'] ?>">← Retour</a>
</div>

<div class="sheet">
  <table class="cadre-page">
    <tfoot><tr><td><div class="pied-reserve"></div></td></tr></tfoot>
    <tbody><tr><td>
      <?php if ((int)$doc['avec_entete'] === 1): ?>
        <?= doc_html_header($settings, $titre, [], '..') ?>
      <?php endif; ?>

      <div class="txt"><?= $doc['contenu'] ?></div>

      <?php if ((int)$doc['avec_signature'] === 1): ?>
        <?= doc_html_auth($settings, '..', null, null, null) ?>
      <?php endif; ?>
    </td></tr></tbody>
  </table>
  <?= doc_html_footer($settings) ?>
</div>

<script>
/* Fait descendre le bloc tampon/signature juste au-dessus du pied de page,
   sur la derniere page du document. */
(function(){
  var HAUT_MM = 16, BAS_MM = 4, RESERVE_MM = 21, PAGE_MM = 297;
  var UTILE_MM = PAGE_MM - HAUT_MM - BAS_MM - RESERVE_MM;   // hauteur de texte par page

  function mm(v){
    var d = document.createElement('div');
    d.style.cssText = 'position:absolute;visibility:hidden;left:0;top:0;width:1px;height:' + v + 'mm';
    document.body.appendChild(d);
    var h = d.getBoundingClientRect().height;
    d.parentNode.removeChild(d);
    return h;
  }

  function placer(){
    var cellule = document.querySelector('.cadre-page > tbody > tr > td');
    var auth    = document.querySelector('.auth');
    if (!cellule || !auth) return;
    var ancien = document.getElementById('cale-signature');
    if (ancien && ancien.parentNode) ancien.parentNode.removeChild(ancien);

    var pageH = mm(UTILE_MM);
    if (!pageH || pageH < 200) return;

    var haut = cellule.getBoundingClientRect().top;
    var r = auth.getBoundingClientRect();
    var basBloc = (r.top - haut) + r.height;
    if (basBloc <= 0) return;

    // un bloc insecable peut basculer sur la page suivante a chaque coupure : on en tient compte
    var blocs = cellule.querySelectorAll('p, h1, h2, h3, table, ul, ol, blockquote, .zone-texte, .zone-encadree');
    var plusHaut = 0;
    for (var i = 0; i < blocs.length; i++) {
      var h = blocs[i].getBoundingClientRect().height;
      if (h > plusHaut && h < pageH) plusHaut = h;
    }
    var pages    = Math.max(1, Math.ceil(basBloc / pageH));
    var securite = (pages - 1) * plusHaut + mm(13);
    var espace   = (pages * pageH - securite) - basBloc;
    if (!isFinite(espace) || espace < 8) return;

    var cale = document.createElement('div');
    cale.id = 'cale-signature';
    cale.setAttribute('aria-hidden', 'true');
    cale.style.height = espace + 'px';
    auth.parentNode.insertBefore(cale, auth);
  }

  function lancer(){ try { placer(); } catch(e){} }
  if (document.readyState === 'complete') lancer(); else window.addEventListener('load', lancer);
  window.addEventListener('beforeprint', lancer);
  window.imprimerDoc = function(){ lancer(); setTimeout(function(){ lancer(); window.print(); }, 120); };
})();
</script>
</body>
</html>
