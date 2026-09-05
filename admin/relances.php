<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/documents.php';
require_once __DIR__ . '/../config/wave.php';
require_once __DIR__ . '/../config/relances.php';

/* ============================================================================
   RELANCE DES IMPAYÉS
   ============================================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Activer / désactiver l'envoi automatique
    if (isset($_POST['bascule'])) {
        if (!is_admin()) { flash("Action réservée à l'administrateur.", 'error'); header('Location: relances.php'); exit; }
        $v = $_POST['bascule'] === '1' ? '1' : '0';
        $pdo->prepare('INSERT INTO settings (cle, valeur) VALUES (?,?) ON DUPLICATE KEY UPDATE valeur=VALUES(valeur)')
            ->execute(['relances_actives', $v]);
        journaliser($pdo, 'reglage', 'relances', null, $v === '1' ? 'Relances automatiques activées' : 'Relances automatiques désactivées');
        flash($v === '1' ? 'Relances automatiques activées. ✅' : 'Relances automatiques désactivées.');
        header('Location: relances.php'); exit;
    }

    // Relance d'une facture précise
    if (isset($_POST['relancer'])) {
        $id = (int)$_POST['relancer'];
        foreach (factures_impayees($pdo, false) as $f) {
            if ((int)$f['id'] === $id) {
                $f['niveau'] = max(1, (int)$f['niveau']);
                $r = envoyer_relance($pdo, $f, $settings, 'manuelle');
                flash($r['ok'] ? 'Relance envoyée à ' . e($f['client_email']) . '. ✉️' : $r['erreur'], $r['ok'] ? 'success' : 'error');
                break;
            }
        }
        header('Location: relances.php'); exit;
    }

    // Relancer toutes les factures éligibles
    if (isset($_POST['relancer_tout'])) {
        $n = 0; $ko = 0;
        foreach (factures_impayees($pdo) as $f) {
            if (!$f['relancable']) continue;
            $r = envoyer_relance($pdo, $f, $settings, 'manuelle');
            if (!empty($r['ok'])) $n++; else $ko++;
        }
        flash($n > 0 ? $n . ' relance' . ($n > 1 ? 's' : '') . ' envoyée' . ($n > 1 ? 's' : '') . '.' . ($ko ? ' ' . $ko . ' échec(s).' : '')
                     : 'Aucune relance à envoyer pour le moment.', $n > 0 ? 'success' : 'error');
        header('Location: relances.php'); exit;
    }
}

$actives  = ($settings['relances_actives'] ?? '0') === '1';
$impayees = factures_impayees($pdo, false);
$devise   = $settings['devise'] ?? 'FCFA';

$totalDu  = 0; $totalEchu = 0; $nbEchues = 0; $nbRelancables = 0;
foreach ($impayees as $f) {
    $totalDu += $f['solde'];
    if ((int)$f['retard'] > 0) { $totalEchu += $f['solde']; $nbEchues++; }
    if ($f['relancable']) $nbRelancables++;
}

$histo = $pdo->query("SELECT r.*, f.numero, COALESCE(NULLIF(c.entreprise,''), c.nom) AS client
                      FROM relances r
                      LEFT JOIN factures f ON f.id = r.facture_id
                      LEFT JOIN clients c ON c.id = r.client_id
                      ORDER BY r.envoye_le DESC LIMIT 25")->fetchAll();

admin_header('Relance des impayés', 'relances', $pdo, $settings);
?>

<div class="stats-row" style="margin-bottom:14px">
  <div class="stat-card"><div class="stat-val"><?= number_format($totalDu, 0, ',', ' ') ?></div><div class="stat-lbl">Total à encaisser (<?= e($devise) ?>)</div></div>
  <div class="stat-card"><div class="stat-val" style="color:#f87171"><?= number_format($totalEchu, 0, ',', ' ') ?></div><div class="stat-lbl">Dont échu (<?= $nbEchues ?> facture<?= $nbEchues > 1 ? 's' : '' ?>)</div></div>
  <div class="stat-card"><div class="stat-val"><?= $nbRelancables ?></div><div class="stat-lbl">Relançables aujourd'hui</div></div>
</div>

<div class="panel glass" style="margin-bottom:14px">
  <h2>✉️ Relance des impayés</h2>
  <p style="color:var(--ink-faint);font-size:13.5px;margin:-8px 0 0">
    Aucun message ne part automatiquement. Vous choisissez qui relancer et quand :
    utilisez le bouton ✉️ en face d'une facture, ou « Tout relancer » pour l'ensemble
    des factures échues.
  </p>
  <?php if (empty($settings['smtp_hote'])): ?>
  <p style="margin:12px 0 0;font-size:12.5px;color:#f0b429">
    ⚠️ Aucun serveur d'envoi configuré : les emails ne partiront pas.
    <a href="parametres.php?section=email" style="color:var(--gold)">Configurer les emails</a>
  </p>
  <?php endif; ?>
</div>

<div class="panel glass" style="margin-bottom:14px">
  <h2>🧾 Factures en attente de règlement (<?= count($impayees) ?>)
    <?php if ($nbRelancables > 0): ?>
    <form method="post" style="margin-left:auto" onsubmit="return confirm('Envoyer <?= $nbRelancables ?> relance(s) maintenant ?')">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <button class="btn btn-gold btn-sm" name="relancer_tout" value="1">✉️ Tout relancer (<?= $nbRelancables ?>)</button>
    </form>
    <?php endif; ?>
  </h2>

  <?php if (!$impayees): ?>
    <p style="color:var(--ink-faint)">Aucune facture en attente. Tout est encaissé. 🎉</p>
  <?php else: ?>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Facture</th><th>Client</th><th>Échéance</th><th>Retard</th><th>Restant dû</th><th>Relances</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($impayees as $f):
        $r = (int)$f['retard'];
        $cls = $r >= 30 ? 'ep-echoue' : ($r > 0 ? 'ep-en_attente' : 'ep-paye'); ?>
        <tr>
          <td style="font-weight:700"><?= e($f['numero']) ?></td>
          <td><?= e($f['client_nom'] ?? '—') ?>
            <?php if (empty($f['client_email'])): ?>
            <div style="font-size:11px;color:#f87171">sans email</div>
            <?php endif; ?>
          </td>
          <td><?= date('d/m/Y', strtotime($f['date_echeance'])) ?></td>
          <td><span class="etat-pay <?= $cls ?>"><?= $r > 0 ? $r . ' j' : 'à venir' ?></span></td>
          <td style="font-weight:700"><?= number_format($f['solde'], 0, ',', ' ') ?></td>
          <td><?= (int)$f['nb_relances'] ?><?php if (!empty($f['derniere_relance'])): ?>
              <div style="font-size:11px;color:var(--ink-faint)">le <?= date('d/m', strtotime($f['derniere_relance'])) ?></div>
              <?php endif; ?></td>
          <td style="text-align:right;white-space:nowrap">
            <a class="btn btn-glass btn-sm" href="pdf.php?type=facture&id=<?= (int)$f['id'] ?>&auth=1" target="_blank">🧾</a>
            <?php if (!empty($f['client_email'])): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Envoyer une relance à <?= e($f['client_nom']) ?> ?')">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <button class="btn <?= $f['relancable'] ? 'btn-gold' : 'btn-glass' ?> btn-sm" name="relancer" value="<?= (int)$f['id'] ?>"
                      title="<?= $f['relancable'] ? 'Envoyer une relance' : 'Relance possible, mais le délai conseillé n\'est pas écoulé' ?>">✉️</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if ($histo): ?>
<div class="panel glass">
  <h2>📜 Relances envoyées</h2>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Date</th><th>Facture</th><th>Client</th><th>Niveau</th><th>Montant</th><th>Origine</th><th>État</th></tr></thead>
      <tbody>
      <?php foreach ($histo as $h): ?>
        <tr>
          <td><?= date('d/m/Y H:i', strtotime($h['envoye_le'])) ?></td>
          <td><?= e($h['numero'] ?? '—') ?></td>
          <td><?= e($h['client'] ?? '—') ?></td>
          <td><?= (int)$h['niveau'] ?></td>
          <td><?= number_format((float)$h['montant'], 0, ',', ' ') ?></td>
          <td style="font-size:12px;color:var(--ink-faint)"><?= e($h['origine']) ?></td>
          <td><span class="etat-pay <?= $h['succes'] ? 'ep-paye' : 'ep-echoue' ?>"><?= $h['succes'] ? 'Envoyée' : 'Échec' ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
