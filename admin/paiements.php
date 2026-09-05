<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/../config/wave.php';
require __DIR__ . '/includes/documents.php';

/* ============================================================================
   SUIVI DES PAIEMENTS EN LIGNE
   ============================================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Activer / désactiver le paiement en ligne (administrateur uniquement)
    if (isset($_POST['bascule'])) {
        if (!is_admin()) { flash("Action réservée à l'administrateur.", 'error'); header('Location: paiements.php'); exit; }
        $nouvel = ($_POST['bascule'] === '1') ? '1' : '0';
        $pdo->prepare('INSERT INTO settings (cle, valeur) VALUES (?,?) ON DUPLICATE KEY UPDATE valeur=VALUES(valeur)')
            ->execute(['wave_actif', $nouvel]);
        journaliser($pdo, 'reglage', 'paiement', null, $nouvel === '1' ? 'Paiement en ligne activé' : 'Paiement en ligne désactivé');
        flash($nouvel === '1' ? 'Paiement en ligne activé. ✅' : 'Paiement en ligne désactivé.');
        header('Location: paiements.php'); exit;
    }

    // Revérifier un paiement resté en attente
    if (isset($_POST['verifier'])) {
        $st = $pdo->prepare('SELECT * FROM paiements WHERE id=?');
        $st->execute([(int)$_POST['verifier']]);
        if ($p = $st->fetch()) {
            $v = wave_verifier($pdo, $p);
            if (!$v['ok']) {
                flash($v['erreur'], 'error');
            } elseif (!empty($v['paye'])) {
                $r = paiement_finaliser($pdo, $p['reference'], $settings, [
                    'transaction_id' => $v['data']['transaction_id'] ?? '', 'source' => 'verification_admin']);
                flash($r['ok'] ? 'Paiement confirmé, reçu émis et comptabilité mise à jour. ✅'
                               : ('Échec : ' . $r['erreur']), $r['ok'] ? 'success' : 'error');
            } else {
                flash('Wave indique que ce paiement n\'est pas abouti (état : ' . e($v['etat']) . ').');
            }
        }
        header('Location: paiements.php'); exit;
    }
}

$cfg  = wave_config($pdo);
$filtre = $_GET['f'] ?? 'tous';
$sql = "SELECT p.*, f.numero AS facture_num, r.numero AS recu_num,
               COALESCE(NULLIF(c.entreprise,''), c.nom) AS client_nom
        FROM paiements p
        LEFT JOIN factures f ON f.id = p.facture_id
        LEFT JOIN recus r ON r.id = p.recu_id
        LEFT JOIN clients c ON c.id = p.client_id";
if (in_array($filtre, ['en_attente','paye','echoue'], true)) $sql .= " WHERE p.statut = " . $pdo->quote($filtre);
$sql .= " ORDER BY p.created_at DESC LIMIT 200";
$paiements = $pdo->query($sql)->fetchAll();

$encaisse = (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM paiements WHERE statut='paye'")->fetchColumn();
$mois     = (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM paiements WHERE statut='paye'
                                AND YEAR(paye_le)=YEAR(CURDATE()) AND MONTH(paye_le)=MONTH(CURDATE())")->fetchColumn();
$attente  = (int)$pdo->query("SELECT COUNT(*) FROM paiements WHERE statut='en_attente'")->fetchColumn();

admin_header('Paiements en ligne', 'paiements', $pdo, $settings);
$devise = $settings['devise'] ?? 'FCFA';
?>

<div class="stats-row" style="margin-bottom:14px">
  <div class="stat-card"><div class="stat-val"><?= number_format($encaisse, 0, ',', ' ') ?></div><div class="stat-lbl">Encaissé au total (<?= e($devise) ?>)</div></div>
  <div class="stat-card"><div class="stat-val"><?= number_format($mois, 0, ',', ' ') ?></div><div class="stat-lbl">Encaissé ce mois</div></div>
  <div class="stat-card"><div class="stat-val"><?= $attente ?></div><div class="stat-lbl">En attente</div></div>
</div>

<div class="panel glass" style="margin-bottom:14px">
  <h2>💳 Paiement en ligne (Wave)</h2>
  <div class="pay-etat">
    <span class="pay-pastille <?= $cfg['actif'] ? 'on' : 'off' ?>"></span>
    <div>
      <strong><?= $cfg['actif'] ? 'Activé' : 'Désactivé' ?></strong>
      <div style="font-size:12.5px;color:var(--ink-faint)">
        <?php if (!$cfg['actif']): ?>
          Vos clients ne voient pas le bouton de paiement dans leur espace.
        <?php elseif ($cfg['cle'] === ''): ?>
          ⚠️ <strong style="color:#f0b429">La clé API Wave n'est pas renseignée</strong> : le bouton de paiement
          n'apparaît pas encore chez vos clients. <a href="parametres.php?section=wave" style="color:var(--gold)">Renseigner la clé</a>
        <?php else: ?>
          Mode <?= e($cfg['mode']) ?> · Vos clients peuvent régler leurs factures en ligne.
        <?php endif; ?>
      </div>
    </div>
    <?php if (is_admin()): ?>
    <form method="post" style="margin-left:auto">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <button class="btn <?= $cfg['actif'] ? 'btn-glass' : 'btn-gold' ?>" name="bascule" value="<?= $cfg['actif'] ? '0' : '1' ?>">
        <?= $cfg['actif'] ? '⏸️ Désactiver' : '▶️ Activer' ?>
      </button>
    </form>
    <?php endif; ?>
  </div>
  <?php if (is_admin()): ?>
  <p style="margin:12px 0 0;font-size:12.5px;color:var(--ink-faint)">
    Clé API, mode et clé de signature se règlent dans
    <a href="parametres.php?section=wave" style="color:var(--gold)">Paramètres → Paiement en ligne</a>.
  </p>
  <?php endif; ?>
</div>

<div class="panel glass">
  <h2>📜 Historique (<?= count($paiements) ?>)</h2>
  <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">
    <?php foreach (['tous'=>'Tous','paye'=>'Payés','en_attente'=>'En attente','echoue'=>'Échoués'] as $k=>$lb): ?>
    <a class="btn btn-sm <?= $filtre===$k ? 'btn-gold' : 'btn-glass' ?>" href="paiements.php?f=<?= $k ?>"><?= $lb ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$paiements): ?>
    <p style="color:var(--ink-faint)">Aucun paiement pour le moment.</p>
  <?php else: ?>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Date</th><th>Client</th><th>Facture</th><th>Montant</th><th>État</th><th>Reçu</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($paiements as $p):
        $lib = ['en_attente'=>'En attente','paye'=>'Payé','echoue'=>'Échoué','annule'=>'Annulé'][$p['statut']] ?? $p['statut']; ?>
        <tr>
          <td><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></td>
          <td><?= e($p['client_nom'] ?? '—') ?></td>
          <td><?= e($p['facture_num'] ?? '—') ?></td>
          <td style="font-weight:700"><?= number_format((float)$p['montant'], 0, ',', ' ') ?></td>
          <td><span class="etat-pay ep-<?= e($p['statut']) ?>"><?= e($lib) ?></span></td>
          <td><?php if (!empty($p['recu_id'])): ?>
              <a class="btn btn-glass btn-sm" href="pdf.php?type=recu&id=<?= (int)$p['recu_id'] ?>&auth=1" target="_blank">🧾 <?= e($p['recu_num']) ?></a>
              <a class="btn btn-gold btn-sm" href="pdf.php?type=recu&id=<?= (int)$p['recu_id'] ?>&dl=1" title="Télécharger en PDF">⬇️</a>
              <?php else: ?>—<?php endif; ?></td>
          <td style="text-align:right">
            <?php if ($p['statut'] === 'en_attente'): ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <button class="btn btn-glass btn-sm" name="verifier" value="<?= (int)$p['id'] ?>" title="Revérifier auprès de Wave">🔄</button>
            </form>
            <?php endif; ?>
            <span title="Référence : <?= e($p['reference']) ?>" style="font-size:11px;color:var(--ink-faint)"><?= e($p['reference']) ?></span>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php admin_footer(); ?>
