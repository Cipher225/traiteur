<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/documents.php';
$devise = $settings['devise'] ?? 'FCFA';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    /* Demandes de devis reçues depuis le site vitrine */
    if (isset($_POST['devis_statut'], $_POST['devis_id'])) {
        $ok = ['nouveau','en_cours','confirme','termine','annule'];
        if (in_array($_POST['devis_statut'], $ok, true)) {
            $pdo->prepare('UPDATE commandes SET statut=? WHERE id=?')->execute([$_POST['devis_statut'], (int)$_POST['devis_id']]);
            flash('Statut de la demande mis à jour.');
        }
        header('Location: commandes-client.php?vue=devis'); exit;
    }
    if (isset($_POST['devis_supprimer'])) {
        $pdo->prepare('DELETE FROM commandes WHERE id=?')->execute([(int)$_POST['devis_supprimer']]);
        flash('Demande supprimée.');
        header('Location: commandes-client.php?vue=devis'); exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    if (isset($_POST['supprimer'])) {
        $pdo->prepare('DELETE FROM commandes_client_lignes WHERE commande_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM commandes_client WHERE id=?')->execute([$id]);
        flash('Commande supprimée.'); header('Location: commandes-client.php'); exit;
    }
    if (isset($_POST['statut'])) {
        $s = $_POST['statut'];
        $ok = ['nouvelle','en_traitement','devis_envoye','confirmee','terminee','annulee'];
        if (in_array($s,$ok,true)) { $pdo->prepare('UPDATE commandes_client SET statut=?, vu_client=0 WHERE id=?')->execute([$s,$id]); flash('Statut mis à jour.'); }
        header('Location: commandes-client.php'); exit;
    }
    // Générer le devis (proforma) à partir de la commande
    if (isset($_POST['generer_devis'])) {
        $cmd = $pdo->prepare('SELECT * FROM commandes_client WHERE id=?'); $cmd->execute([$id]); $cmd = $cmd->fetch();
        if ($cmd) {
            if ($cmd['proforma_id']) { header('Location: factures.php?doc=proforma&edit='.$cmd['proforma_id']); exit; }
            $numero = next_numero($pdo, 'factures', $settings['prefixe_proforma'] ?? 'PRO');
            $tva = (float)($settings['tva_taux'] ?? 18);
            $pdo->prepare("INSERT INTO factures (numero,type,client_id,date_emission,date_echeance,tva_taux,remise,statut,notes) VALUES (?,'proforma',?,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 15 DAY),?,0,'brouillon',?)")
                ->execute([$numero, $cmd['client_id'], $tva, 'Devis établi à partir de la commande '.$cmd['numero'].($cmd['date_evenement']?' — événement du '.date('d/m/Y',strtotime($cmd['date_evenement'])):'')]);
            $fid = (int)$pdo->lastInsertId();
            $lg = $pdo->prepare('SELECT ccl.*, cat.nom AS cat_nom, cat.ordre AS cat_ordre FROM commandes_client_lignes ccl LEFT JOIN plats p ON p.id=ccl.plat_id LEFT JOIN categories cat ON cat.id=p.categorie_id WHERE ccl.commande_id=?'); $lg->execute([$id]);
            $lignesCmd = $lg->fetchAll();

            /* Regrouper les plats par catégorie : la catégorie devient la prestation,
               les plats deviennent les éléments inclus (détails), comme en saisie manuelle. */
            $groupes = [];
            foreach ($lignesCmd as $l) {
                $cat = trim((string)($l['cat_nom'] ?? '')) ?: 'Prestations';
                if (!isset($groupes[$cat])) $groupes[$cat] = ['ordre' => (int)($l['cat_ordre'] ?? 999), 'plats' => []];
                /* On liste chaque plat (avec sa quantité) comme élément inclus. */
                $q = (int)$l['quantite'];
                $groupes[$cat]['plats'][] = ($q > 1 ? $q . '× ' : '') . $l['designation'];
            }
            /* Tri par ordre de catégorie */
            uasort($groupes, fn($a, $b) => $a['ordre'] <=> $b['ordre']);

            $ins = $pdo->prepare('INSERT INTO facture_lignes (facture_id,designation,categorie,details,quantite,prix_unitaire) VALUES (?,?,?,?,1,0)');
            foreach ($groupes as $catNom => $g) {
                /* designation = nom de la prestation (la catégorie),
                   categorie = même libellé (titre de section),
                   details = un plat par ligne (éléments inclus, sans prix). */
                $details = implode("\n", $g['plats']);
                $ins->execute([$fid, $catNom, $catNom, $details]);
            }
            $pdo->prepare("UPDATE commandes_client SET proforma_id=?, statut='en_traitement', vu_client=0 WHERE id=?")->execute([$fid, $id]);
            flash('Devis créé à partir de la commande. Renseignez les prix, puis passez le statut à « Devis envoyé » pour que le client le voie.');
            header('Location: factures.php?doc=proforma&edit='.$fid); exit;
        }
        header('Location: commandes-client.php'); exit;
    }
}

$filtre = $_GET['statut'] ?? '';
$sql = "SELECT cc.*, c.nom AS client_nom, c.telephone AS client_tel FROM commandes_client cc LEFT JOIN clients c ON c.id=cc.client_id";
$params = [];
if ($filtre !== '') { $sql .= " WHERE cc.statut=?"; $params[] = $filtre; }
$sql .= " ORDER BY FIELD(cc.statut,'nouvelle','en_traitement')=0, cc.created_at DESC";
$st = $pdo->prepare($sql); $st->execute($params); $cmds = $st->fetchAll();
$nbNouvelles = (int)$pdo->query("SELECT COUNT(*) FROM commandes_client WHERE statut='nouvelle'")->fetchColumn();
$vue = ($_GET['vue'] ?? '') === 'devis' ? 'devis' : 'commandes';
$nbDevis = (int)$pdo->query("SELECT COUNT(*) FROM commandes WHERE statut='nouveau'")->fetchColumn();
$dFiltre = $_GET['filtre'] ?? '';
$dLabels = ['nouveau'=>'Nouveau','en_cours'=>'En cours','confirme'=>'Confirmé','termine'=>'Terminé','annule'=>'Annulé'];
$dBadges = ['nouveau'=>'badge-gold','en_cours'=>'badge-violet','confirme'=>'badge-teal','termine'=>'badge','annule'=>'badge-danger'];
if ($dFiltre && isset($dLabels[$dFiltre])) {
    $st = $pdo->prepare('SELECT * FROM commandes WHERE statut=? ORDER BY created_at DESC'); $st->execute([$dFiltre]);
} else {
    $st = $pdo->query('SELECT * FROM commandes ORDER BY created_at DESC');
}
$demandes = $st->fetchAll();

$etapes = ['nouvelle'=>'Nouvelle','en_traitement'=>'En traitement','devis_envoye'=>'Devis envoyé','confirmee'=>'Confirmée','terminee'=>'Terminée','annulee'=>'Annulée'];
$badge = ['nouvelle'=>'badge-gold','en_traitement'=>'badge-violet','devis_envoye'=>'badge-teal','confirmee'=>'badge-teal','terminee'=>'badge','annulee'=>'badge-danger'];

admin_header('Commandes clients', 'commandes_client', $pdo, $settings);
?>
<div class="vue-tabs">
  <a class="vt <?= $vue === 'commandes' ? 'on' : '' ?>" href="commandes-client.php">
    📦 Commandes de l'espace client<?php if ($nbNouvelles): ?><span class="vt-n"><?= $nbNouvelles ?></span><?php endif; ?>
  </a>
  <a class="vt <?= $vue === 'devis' ? 'on' : '' ?>" href="commandes-client.php?vue=devis">
    📮 Demandes de devis du site<?php if ($nbDevis): ?><span class="vt-n"><?= $nbDevis ?></span><?php endif; ?>
  </a>
</div>
<?php if ($vue === 'devis'): ?>

<div class="panel glass">
  <h2>🎯 Filtrer par statut</h2>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn btn-sm <?= $dFiltre === '' ? 'btn-gold' : 'btn-glass' ?>" href="commandes-client.php?vue=devis">Toutes</a>
    <?php foreach ($dLabels as $k => $l): ?>
    <a class="btn btn-sm <?= $dFiltre === $k ? 'btn-gold' : 'btn-glass' ?>" href="?vue=devis&filtre=<?= $k ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php foreach ($demandes as $c):
  $telClean = preg_replace('/\D/', '', $c['telephone']);
  $moisEv = $c['date_evenement'] ? date('Y-m', strtotime($c['date_evenement'])) : '';
?>
<div class="panel glass">
  <div style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:start">
    <div style="min-width:0;flex:1">
      <h2 style="margin-bottom:10px"><?= e($c['nom']) ?> <span class="badge <?= $dBadges[$c['statut']] ?>"><?= $dLabels[$c['statut']] ?></span></h2>
      <div class="dmd-infos">
        <?php if ($c['telephone']): ?>
        <a class="dmd-info" href="tel:<?= e($c['telephone']) ?>"><span class="dmd-ico">📞</span><strong><?= e($c['telephone']) ?></strong></a>
        <?php endif; ?>
        <?php if ($c['email']): ?>
        <a class="dmd-info" href="mailto:<?= e($c['email']) ?>"><span class="dmd-ico">✉️</span><?= e($c['email']) ?></a>
        <?php endif; ?>
        <span class="dmd-info"><span class="dmd-ico">🎉</span><?= e($c['type_evenement']) ?></span>
        <?php if ($c['date_evenement']): ?>
        <a class="dmd-info dmd-cal" href="calendrier.php?m=<?= $moisEv ?>" title="Voir dans le calendrier"><span class="dmd-ico">🗓️</span><?= date('d/m/Y', strtotime($c['date_evenement'])) ?></a>
        <?php endif; ?>
        <?php if ($c['nb_invites']): ?>
        <span class="dmd-info"><span class="dmd-ico">👥</span><?= (int)$c['nb_invites'] ?> participants</span>
        <?php endif; ?>
      </div>
      <small style="color:var(--ink-faint);display:block;margin-top:8px">Reçue le <?= date('d/m/Y à H:i', strtotime($c['created_at'])) ?></small>
      <?php if ($c['message']): ?>
      <p style="margin-top:12px;padding:14px;border-radius:14px;background:var(--glass-border-soft);border:1px solid var(--glass-border);color:var(--ink-dim);font-size:14px">« <?= nl2br(e($c['message'])) ?> »</p>
      <?php endif; ?>
    </div>
    <div class="dmd-actions">
      <form method="post" style="display:flex;gap:8px">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="devis_id" value="<?= $c['id'] ?>">
        <select class="input" name="devis_statut" onchange="this.form.submit()" style="padding:9px 12px">
          <?php foreach ($dLabels as $k => $l): ?>
          <option value="<?= $k ?>" <?= $c['statut'] === $k ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <div class="dmd-contact">
        <?php if ($telClean): ?>
        <a class="btn btn-wa btn-sm" href="https://wa.me/<?= $telClean ?>" target="_blank" rel="noopener" title="Contacter par WhatsApp">
          <svg viewBox="0 0 32 32" width="17" height="17" aria-hidden="true"><path fill="#25D366" d="M16 0a16 16 0 0 0-13.7 24.2L0 32l8-2.1A16 16 0 1 0 16 0z"/><path fill="#fff" d="M12.1 8.6c-.3-.6-.5-.6-.8-.6h-.7c-.2 0-.6.1-.9.4-.3.3-1.2 1.2-1.2 2.9s1.2 3.4 1.4 3.6c.2.3 2.4 3.8 6 5.2 3 1.2 3.6 1 4.2.9.6-.1 2-.8 2.3-1.6.3-.8.3-1.5.2-1.6-.1-.2-.3-.2-.7-.4-.3-.2-2-1-2.3-1.1-.3-.1-.5-.2-.8.2-.2.3-.8 1.1-1 1.3-.2.2-.4.2-.7.1-.4-.2-1.5-.6-2.9-1.8-1.1-1-1.8-2.2-2-2.5-.2-.4 0-.5.1-.7l.5-.6c.2-.2.2-.3.4-.6.1-.2 0-.4 0-.6s-.7-1.8-1-2.4z"/></svg>
          WhatsApp
        </a>
        <?php endif; ?>
        <?php if ($c['email']): ?>
        <a class="btn btn-glass btn-sm" href="mailto:<?= e($c['email']) ?>?subject=<?= rawurlencode('Votre demande de devis — '.($settings['nom_entreprise'] ?? 'Groupe Helisce')) ?>&body=<?= rawurlencode('Bonjour '.$c['nom'].",\n\n") ?>" title="Contacter par email">✉️ Email</a>
        <?php endif; ?>
      </div>
      <form method="post" data-confirm="Supprimer définitivement cette demande ?">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <button class="btn btn-danger btn-sm" name="devis_supprimer" value="<?= $c['id'] ?>" style="width:100%">Supprimer</button>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php if (!$demandes): ?>
<div class="panel glass" style="text-align:center;padding:50px;color:var(--ink-faint)">Aucune demande <?= $dFiltre ? 'avec ce statut' : 'pour le moment' ?>.</div>
<?php endif; ?>
<?php admin_footer(); exit; endif; ?>
<div class="panel glass" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
  <h2 style="border:0;margin:0;padding:0">📦 Commandes clients<?php if($nbNouvelles): ?> <span class="badge badge-gold"><?= $nbNouvelles ?> nouvelle<?= $nbNouvelles>1?'s':'' ?></span><?php endif; ?></h2>
  <form method="get" style="margin-left:auto">
    <select class="input" name="statut" onchange="this.form.submit()" style="padding:8px 12px">
      <option value="">Tous les statuts</option>
      <?php foreach ($etapes as $k=>$v): ?><option value="<?= $k ?>" <?= $filtre===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
    </select>
  </form>
</div>

<?php foreach ($cmds as $cmd):
  $lignes = $pdo->prepare("SELECT * FROM commandes_client_lignes WHERE commande_id=?"); $lignes->execute([$cmd['id']]); $lignes = $lignes->fetchAll();
  $nouvelle = $cmd['statut']==='nouvelle';
?>
<details class="cmd-card"<?= $nouvelle?' open':'' ?>>
  <summary class="cmd-sum">
    <span class="cmd-num"><?= e($cmd['numero']) ?></span>
    <span class="cmd-cli"><?= e($cmd['client_nom'] ?? 'Client') ?></span>
    <span class="cmd-meta-inline">
      <?php if($cmd['date_evenement']): ?>📅 <?= date('d/m/Y', strtotime($cmd['date_evenement'])) ?><?php endif; ?>
      <?php if($cmd['nb_invites']): ?> · 👥 <?= (int)$cmd['nb_invites'] ?><?php endif; ?>
    </span>
    <span class="badge <?= $badge[$cmd['statut']] ?? 'badge' ?>" style="font-size:12px"><?= $etapes[$cmd['statut']] ?? $cmd['statut'] ?></span>
  </summary>

  <div class="cmd-body">
    <p style="color:var(--ink-faint);font-size:12.5px;margin:0 0 10px">
      Reçue le <?= date('d/m/Y à H:i', strtotime($cmd['created_at'])) ?>
      <?php if($cmd['client_tel']): ?> · 📞 <?= e($cmd['client_tel']) ?><?php endif; ?>
      <?php if($cmd['lieu']): ?> · 📍 <?= e($cmd['lieu']) ?><?php endif; ?>
    </p>
    <div class="cmd-detail">
      <div>
        <h4>Plats demandés</h4>
        <ul class="cmd-plats">
          <?php foreach ($lignes as $l): ?><li><span class="cart-q"><?= (int)$l['quantite'] ?>×</span> <?= e($l['designation']) ?></li><?php endforeach; ?>
        </ul>
        <?php if (trim((string)$cmd['notes'])!==''): ?><p style="color:var(--ink-dim);font-size:13.5px"><strong>Précisions client :</strong> <?= e($cmd['notes']) ?></p><?php endif; ?>
      </div>
      <div style="display:flex;flex-direction:column;gap:10px">
        <?php if ($cmd['proforma_id']): ?>
          <a class="btn btn-glass" href="factures.php?doc=proforma&edit=<?= (int)$cmd['proforma_id'] ?>">✏️ Modifier le devis</a>
          <a class="btn btn-glass btn-sm" href="pdf.php?type=proforma&id=<?= (int)$cmd['proforma_id'] ?>&auth=1" target="_blank">📄 Voir le devis</a>
        <?php else: ?>
          <form method="post"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= $cmd['id'] ?>">
            <button class="btn btn-gold" name="generer_devis" value="1" style="width:100%">🧾 Générer le devis</button></form>
        <?php endif; ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= $cmd['id'] ?>">
          <select class="input" name="statut" onchange="this.form.submit()" style="width:100%">
            <?php foreach ($etapes as $k=>$v): ?><option value="<?= $k ?>" <?= $cmd['statut']===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
          </select>
        </form>
        <form method="post" data-confirm="Supprimer cette commande ?"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= $cmd['id'] ?>">
          <button class="btn btn-danger btn-sm" name="supprimer" value="1" style="width:100%">🗑️ Supprimer</button></form>
      </div>
    </div>
  </div>
</details>
<?php endforeach; ?>
<?php if (!$cmds): ?>
<div class="panel glass" style="text-align:center;padding:40px;color:var(--ink-faint)">
  <div style="font-size:44px">📦</div>Aucune commande client<?= $filtre?' avec ce statut':'' ?> pour l'instant.
</div>
<?php endif; ?>
<?php admin_footer(); ?>
