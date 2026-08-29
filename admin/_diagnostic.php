<?php
// Diagnostic — vérifie que la dernière version des fichiers de génération PDF est déployée.
header('Content-Type: text/html; charset=utf-8');
$opcache = function_exists('opcache_reset') ? (opcache_reset() ? 'vidé' : 'échec') : 'inactif';

$fDoc = __DIR__ . '/includes/doc_html.php';   // vrai générateur des factures (HTML)
$cDoc = is_file($fDoc) ? file_get_contents($fDoc) : '';
$dDoc = is_file($fDoc) ? date('d/m/Y H:i:s', filemtime($fDoc)) : 'introuvable';

// Marqueurs de la correction du tampon/signature
$tamponOK    = strpos($cDoc, 'width:180px') !== false;         // tampon taille normale
$signOK      = strpos($cDoc, 'top:36px;transform:translateX(-50%)') !== false; // signature centrée dans le bas
$fPrint = __DIR__ . '/print.php';
$cPrint = is_file($fPrint) ? file_get_contents($fPrint) : '';
$fPrint = __DIR__ . '/print.php'; $cPrint = is_file($fPrint) ? file_get_contents($fPrint) : '';
$ancrageOK = (strpos($cDoc, 'body.mode-mesure .sheet') !== false)
            && (strpos($cDoc, '.df{position:fixed') !== false)
            && (strpos($cPrint, "classList.add('mode-mesure')") !== false);
$aJour = $tamponOK && $signOK && $ancrageOK;
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><title>Diagnostic factures</title>
<style>
body{font-family:system-ui,sans-serif;background:#0a1f44;color:#fff;padding:30px;line-height:1.7}
.box{max-width:600px;margin:0 auto;background:rgba(255,255,255,.06);border:1px solid rgba(212,165,38,.3);border-radius:16px;padding:24px}
h1{color:#e9c15c;font-size:20px}.ok{color:#4ade80;font-weight:700}.ko{color:#f87171;font-weight:700}
.ligne{padding:8px 0;border-bottom:1px solid rgba(255,255,255,.08)}
.verdict{font-size:22px;font-weight:800;padding:16px;border-radius:12px;text-align:center;margin-top:18px}
.vok{background:rgba(74,222,128,.15);color:#4ade80}.vko{background:rgba(248,113,113,.15);color:#f87171}
code{background:rgba(255,255,255,.1);padding:2px 8px;border-radius:6px;color:#e9c15c}
</style></head><body><div class="box">
<h1>🔍 Diagnostic — génération des factures</h1>
<div class="ligne">Fichier vérifié : <code>admin/includes/doc_html.php</code></div>
<div class="ligne">Dernière modification : <strong><?= $dDoc ?></strong></div>
<div class="ligne">OPcache PHP : <strong><?= $opcache ?></strong></div>
<hr>
<div class="ligne">Tampon taille normale : <span class="<?= $tamponOK?'ok':'ko' ?>"><?= $tamponOK?'✅ PRÉSENT':'❌ ABSENT' ?></span></div>
<div class="ligne">Signature centrée dans le bas du tampon : <span class="<?= $signOK?'ok':'ko' ?>"><?= $signOK?'✅ PRÉSENT':'❌ ABSENT' ?></span></div>
<div class="ligne">Rendu identique PC/tablette + bloc en bas de la dernière page : <span class="<?= $ancrageOK?'ok':'ko' ?>"><?= $ancrageOK?'✅ PRÉSENT':'❌ ABSENT' ?></span></div>
<div class="verdict <?= $aJour?'vok':'vko' ?>">
<?php if ($aJour): ?>✅ Version À JOUR<br><span style="font-size:14px;font-weight:400">Le bon fichier est en place. Régénère une facture : le tampon est à taille normale et la signature dans le bas.</span>
<?php else: ?>❌ ANCIENNE version<br><span style="font-size:14px;font-weight:400">Ce dossier contient encore l'ancien doc_html.php. Le remplacement du dossier a échoué pour ce fichier.</span>
<?php endif; ?>
</div>
</div></body></html>
