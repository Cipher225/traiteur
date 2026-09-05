<?php
require __DIR__ . '/inc.php';
$pdo->prepare('UPDATE commandes_client SET vu_client=1 WHERE client_id=? AND vu_client=0')
    ->execute([(int)$CLIENT['id']]);
$cid = (int)$CLIENT['id'];

$cmds = $pdo->prepare("SELECT * FROM commandes_client WHERE client_id=? ORDER BY created_at DESC");
$cmds->execute([$cid]); $cmds = $cmds->fetchAll();

$etapes = ['nouvelle'=>'Reçue','en_traitement'=>'En préparation du devis','devis_envoye'=>'Devis disponible','confirmee'=>'Confirmée','terminee'=>'Terminée'];
$flow = array_keys($etapes);
$badge = ['nouvelle'=>'badge-gold','en_traitement'=>'badge-violet','devis_envoye'=>'badge-teal','confirmee'=>'badge-teal','terminee'=>'badge','annulee'=>'badge-danger'];

client_header('Mes commandes', 'commandes', $settings, $CLIENT);
?>
<div class="panel glass" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
  <div style="flex:1;min-width:200px"><h2 style="border:0;margin:0;padding:0">📦 Mes commandes</h2><p style="color:var(--ink-dim);margin:4px 0 0;font-size:14px">Suivez l'évolution de vos commandes et retrouvez vos devis.</p></div>
  <a href="commander.php" class="btn btn-gold">🛒 Nouvelle commande</a>
</div>

<?php foreach ($cmds as $cmd):
  $lignes = $pdo->prepare("SELECT * FROM commandes_client_lignes WHERE commande_id=?"); $lignes->execute([$cmd['id']]); $lignes = $lignes->fetchAll();
  $annulee = $cmd['statut']==='annulee';
  $stepIdx = array_search($cmd['statut'], $flow); if ($stepIdx===false) $stepIdx=-1;
?>
<div class="panel glass">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
    <div>
      <h2 style="border:0;margin:0;padding:0"><?= e($cmd['numero']) ?></h2>
      <p style="color:var(--ink-faint);font-size:13px;margin:4px 0 0">Passée le <?= date('d/m/Y à H:i', strtotime($cmd['created_at'])) ?><?php if($cmd['date_evenement']): ?> · Événement le <?= date('d/m/Y', strtotime($cmd['date_evenement'])) ?><?php endif; ?><?php if($cmd['nb_invites']): ?> · <?= (int)$cmd['nb_invites'] ?> participants<?php endif; ?></p>
    </div>
    <span class="badge <?= $badge[$cmd['statut']] ?? 'badge' ?>" style="font-size:13px"><?= $etapes[$cmd['statut']] ?? ($annulee?'Annulée':$cmd['statut']) ?></span>
  </div>

  <?php if (!$annulee): ?>
  <div class="track">
    <?php foreach ($etapes as $k=>$lib): $i=array_search($k,$flow); $done=$i<=$stepIdx; $current=$i===$stepIdx; ?>
    <div class="track-step <?= $done?'done':'' ?> <?= $current?'current':'' ?>">
      <div class="track-dot"><?= $done?'✓':'' ?></div>
      <div class="track-lib"><?= e($lib) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <p style="color:var(--rose,#e57373);font-weight:600;margin:10px 0">Cette commande a été annulée.</p>
  <?php endif; ?>

  <div class="cmd-detail">
    <div>
      <h4>Plats commandés</h4>
      <ul class="cmd-plats">
        <?php foreach ($lignes as $l): ?><li><span class="cart-q"><?= (int)$l['quantite'] ?>×</span> <?= e($l['designation']) ?></li><?php endforeach; ?>
        <?php if (!$lignes): ?><li style="color:var(--ink-faint)">—</li><?php endif; ?>
      </ul>
      <?php if (trim((string)$cmd['notes'])!==''): ?><p style="color:var(--ink-dim);font-size:13.5px"><strong>Précisions :</strong> <?= e($cmd['notes']) ?></p><?php endif; ?>
    </div>
    <div>
      <?php if ($cmd['statut']==='devis_envoye' || $cmd['statut']==='confirmee'): ?>
        <?php if ($cmd['proforma_id']): ?>
        <div class="devis-ready glass">
          <div style="font-size:30px">📄</div>
          <strong>Votre devis est prêt !</strong>
          <p style="color:var(--ink-dim);font-size:13px;margin:6px 0 12px">Consultez et téléchargez votre devis personnalisé.</p>
          <a class="btn btn-gold" href="doc-pdf.php?type=proforma&id=<?= (int)$cmd['proforma_id'] ?>" target="_blank">Voir mon devis</a>
        </div>
        <?php endif; ?>
      <?php elseif ($cmd['statut']==='nouvelle' || $cmd['statut']==='en_traitement'): ?>
        <div class="devis-wait">
          <div style="font-size:28px">⏳</div>
          <p style="color:var(--ink-dim);font-size:13.5px">Votre devis est en cours de préparation. Vous serez notifié dès qu'il sera disponible ici.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php if (!$cmds): ?>
<div class="panel glass" style="text-align:center;padding:40px">
  <div style="font-size:44px">🛒</div>
  <h2 style="border:0;justify-content:center">Aucune commande pour l'instant</h2>
  <p style="color:var(--ink-dim)">Composez votre première commande, c'est simple et sans engagement.</p>
  <a href="commander.php" class="btn btn-gold" style="margin-top:10px">Commander maintenant</a>
</div>
<?php endif; ?>
<?php client_footer(); ?>
