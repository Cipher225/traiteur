<?php
require __DIR__ . '/inc.php';
require __DIR__ . '/../config/wave.php';
require __DIR__ . '/../admin/includes/documents.php';

/* ============================================================================
   ESPACE CLIENT — RÉGLER UNE FACTURE
   ============================================================================ */

$dispo = wave_disponible($pdo);

/* --- Lancement d'un paiement --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payer'])) {
    csrf_check();
    if (!$dispo) {
        flash("Le paiement en ligne n'est pas disponible pour le moment.", 'error');
        header('Location: payer.php'); exit;
    }
    $idFacture = (int)$_POST['payer'];

    // La facture doit appartenir au client connecté : contrôle systématique
    $st = $pdo->prepare("SELECT id FROM factures WHERE id=? AND client_id=? AND type='facture' AND statut<>'annulee'");
    $st->execute([$idFacture, (int)$CLIENT['id']]);
    if (!$st->fetchColumn()) {
        flash("Cette facture n'est pas disponible.", 'error');
        header('Location: payer.php'); exit;
    }

    $facture = get_facture($pdo, $idFacture);
    $res = wave_creer_paiement($pdo, $facture, $CLIENT, $settings);
    if ($res['ok']) { header('Location: ' . $res['url']); exit; }
    flash($res['erreur'], 'error');
    header('Location: payer.php'); exit;
}

/* --- Factures à régler --- */
$st = $pdo->prepare("SELECT * FROM factures WHERE client_id=? AND type='facture' AND statut<>'annulee'
                     ORDER BY date_emission DESC, id DESC");
$st->execute([(int)$CLIENT['id']]);
$factures = [];
foreach ($st->fetchAll() as $f) {
    $doc = get_facture($pdo, (int)$f['id']);
    if (!$doc) continue;
    $regle  = paiements_deja_regles($pdo, (int)$f['id']);
    $solde  = max(0, (float)$doc['montant_ttc'] - $regle);
    $doc['regle'] = $regle;
    $doc['solde'] = $solde;
    $factures[] = $doc;
}
$aRegler = array_values(array_filter($factures, fn($f) => $f['solde'] > 0 && $f['statut'] !== 'payee'));
$reglees = array_values(array_filter($factures, fn($f) => $f['solde'] <= 0 || $f['statut'] === 'payee'));

/* --- Historique des paiements --- */
$hist = $pdo->prepare("SELECT p.*, f.numero AS facture_num, r.numero AS recu_num
                       FROM paiements p
                       LEFT JOIN factures f ON f.id = p.facture_id
                       LEFT JOIN recus r ON r.id = p.recu_id
                       WHERE p.client_id=? ORDER BY p.created_at DESC LIMIT 20");
$hist->execute([(int)$CLIENT['id']]);
$paiements = $hist->fetchAll();

client_header('Régler une facture', 'payer', $settings, $CLIENT);
$devise = $settings['devise'] ?? 'FCFA';
?>

<?php if (!$dispo): ?>
<div class="panel glass">
  <h2>💳 Paiement en ligne</h2>
  <p style="color:var(--ink-faint)">Le paiement en ligne n'est pas disponible pour le moment.
     Vous pouvez régler vos factures par les moyens habituels ou nous contacter.</p>
</div>
<?php else: ?>

<div class="panel glass" style="margin-bottom:14px">
  <h2>💳 Régler une facture</h2>
  <p style="color:var(--ink-faint);font-size:13.5px;margin:-8px 0 4px">
    Le paiement est effectué sur la plateforme sécurisée <strong>Wave</strong>. Votre reçu est délivré
    automatiquement dès la confirmation, et vous le recevez également par email.</p>
</div>

<div class="panel glass" style="margin-bottom:14px">
  <h2>🧾 À régler (<?= count($aRegler) ?>)</h2>
  <?php if (!$aRegler): ?>
    <p style="color:var(--ink-faint)">Aucune facture en attente de règlement. 🎉</p>
  <?php else: ?>
  <div class="pay-liste">
    <?php foreach ($aRegler as $f): ?>
    <div class="pay-carte">
      <div class="pay-info">
        <div class="pay-num"><?= e($f['numero']) ?></div>
        <div class="pay-meta">
          Émise le <?= date('d/m/Y', strtotime($f['date_emission'])) ?>
          <?php if (!empty($f['date_echeance'])): ?> · Échéance le <?= date('d/m/Y', strtotime($f['date_echeance'])) ?><?php endif; ?>
          <?php if ($f['regle'] > 0): ?> · Déjà réglé : <?= number_format($f['regle'], 0, ',', ' ') ?> <?= e($devise) ?><?php endif; ?>
        </div>
      </div>
      <div class="pay-montant"><?= number_format($f['solde'], 0, ',', ' ') ?> <span><?= e($devise) ?></span></div>
      <form method="post" onsubmit="return confirm('Vous allez être redirigé vers Wave pour régler <?= number_format($f['solde'], 0, ',', ' ') ?> <?= e($devise) ?>. Continuer ?')">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <button class="btn btn-gold" name="payer" value="<?= (int)$f['id'] ?>">Payer avec Wave</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($paiements): ?>
<div class="panel glass">
  <h2>📜 Mes paiements</h2>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Date</th><th>Facture</th><th>Montant</th><th>État</th><th>Reçu</th></tr></thead>
      <tbody>
      <?php foreach ($paiements as $p):
        $lib = ['en_attente'=>'En attente','paye'=>'Payé','echoue'=>'Échoué','annule'=>'Annulé'][$p['statut']] ?? $p['statut']; ?>
        <tr>
          <td><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></td>
          <td><?= e($p['facture_num'] ?? '—') ?></td>
          <td><?= number_format((float)$p['montant'], 0, ',', ' ') ?> <?= e($devise) ?></td>
          <td><span class="etat-pay ep-<?= e($p['statut']) ?>"><?= e($lib) ?></span></td>
          <td><?php if (!empty($p['recu_id'])): ?>
            <a class="btn btn-glass btn-sm" href="doc-pdf.php?type=recu&id=<?= (int)$p['recu_id'] ?>" target="_blank">🧾 <?= e($p['recu_num']) ?></a>
          <?php else: ?>—<?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>
<?php client_footer(); ?>
