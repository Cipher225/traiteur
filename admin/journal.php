<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';

/* ============================================================================
   JOURNAL DES ACTIONS
   Retrace les opérations sensibles : suppressions, encaissements, connexions,
   changements de réglages. Consultable par l'administrateur uniquement.
   ============================================================================ */

if (!is_admin()) {
    flash("Le journal est réservé à l'administrateur.", 'error');
    header('Location: index.php'); exit;
}

/* Purge des entrées anciennes.
   On ne supprime que ce qui dépasse l'ancienneté choisie, et on ne laisse une
   trace que si quelque chose a réellement été supprimé. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purger'])) {
    csrf_check();
    $jours = (int)($_POST['jours'] ?? 90);
    if (!in_array($jours, [30, 90, 180, 365], true)) $jours = 90;

    $st = $pdo->prepare("SELECT COUNT(*) FROM journal WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $st->execute([$jours]);
    $aSupprimer = (int)$st->fetchColumn();

    if ($aSupprimer === 0) {
        flash("Aucune entrée de plus de $jours jours : le journal est déjà à jour.");
    } else {
        $pdo->prepare("DELETE FROM journal WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)")->execute([$jours]);
        journaliser($pdo, 'purge', 'journal', null, $aSupprimer . ' entrée(s) de plus de ' . $jours . ' jours supprimée(s)');
        flash($aSupprimer . ' entrée' . ($aSupprimer > 1 ? 's supprimées' : ' supprimée') . '.');
    }
    header('Location: journal.php'); exit;
}

$LIBELLES = [
    'connexion'   => ['🔑', 'Connexion'],
    'suppression' => ['🗑️', 'Suppression'],
    'paiement'    => ['💳', 'Paiement'],
    'reglage'     => ['⚙️', 'Réglage'],
    'purge'       => ['🧹', 'Nettoyage'],
];

$filtre = $_GET['a'] ?? '';
$q      = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['p'] ?? 1));
$parPage = 50;

$where = []; $args = [];
if ($filtre !== '' && isset($LIBELLES[$filtre])) { $where[] = 'action = ?'; $args[] = $filtre; }
if ($q !== '') { $where[] = '(acteur LIKE ? OR detail LIKE ? OR cible LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
$clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* Nombre d'entrées purgeables pour chaque ancienneté proposée */
$purgeables = [];
foreach ([30, 90, 180, 365] as $j) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM journal WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $s->execute([$j]);
    $purgeables[$j] = (int)$s->fetchColumn();
}
$plusAncienne = $pdo->query("SELECT MIN(created_at) FROM journal")->fetchColumn();

$stTot = $pdo->prepare("SELECT COUNT(*) FROM journal $clause");
$stTot->execute($args);
$total = (int)$stTot->fetchColumn();
$pages = max(1, (int)ceil($total / $parPage));
$page  = min($page, $pages);

$st = $pdo->prepare("SELECT * FROM journal $clause ORDER BY created_at DESC LIMIT $parPage OFFSET " . (($page - 1) * $parPage));
$st->execute($args);
$lignes = $st->fetchAll();

/* Tentatives de connexion refusées, sur les dernières 24 h */
$echecs = [];
try {
    $echecs = $pdo->query("SELECT ip, COUNT(*) n, MAX(created_at) dernier,
                                  GROUP_CONCAT(DISTINCT identifiant SEPARATOR ', ') ids
                           FROM tentatives_connexion
                           WHERE reussi = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                           GROUP BY ip HAVING n >= 3 ORDER BY n DESC LIMIT 10")->fetchAll();
} catch (Throwable $e) {}

admin_header('Journal des actions', 'journal', $pdo, $settings);
?>

<?php if ($echecs): ?>
<div class="panel glass" style="margin-bottom:14px;border-left:4px solid #f0b429">
  <h2>⚠️ Tentatives de connexion refusées (24 h)</h2>
  <p style="color:var(--ink-faint);font-size:13px;margin:-8px 0 12px">
    Adresses ayant échoué au moins 3 fois. Au-delà de <?= CONNEXION_MAX_ESSAIS ?> échecs,
    l'accès est bloqué pendant <?= CONNEXION_BLOCAGE_MIN ?> minutes.</p>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Adresse</th><th>Échecs</th><th>Identifiants essayés</th><th>Dernière tentative</th></tr></thead>
      <tbody>
      <?php foreach ($echecs as $e): ?>
        <tr>
          <td style="font-family:monospace"><?= e($e['ip']) ?></td>
          <td><span class="etat-pay ep-echoue"><?= (int)$e['n'] ?></span></td>
          <td style="font-size:12px;color:var(--ink-faint)"><?= e(mb_substr($e['ids'] ?? '', 0, 80)) ?></td>
          <td><?= date('d/m/Y H:i', strtotime($e['dernier'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="panel glass">
  <h2>📖 Journal des actions (<?= $total ?>)</h2>
  <p style="color:var(--ink-faint);font-size:13px;margin:-8px 0 12px">
    Qui a fait quoi, et quand. Les entrées de plus de 90 jours peuvent être supprimées.</p>

  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
    <input class="input" name="q" value="<?= e($q) ?>" placeholder="Rechercher…" style="max-width:230px">
    <select class="input" name="a" style="max-width:180px">
      <option value="">Toutes les actions</option>
      <?php foreach ($LIBELLES as $k => [$ic, $lb]): ?>
      <option value="<?= $k ?>" <?= $filtre === $k ? 'selected' : '' ?>><?= $ic ?> <?= $lb ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-glass btn-sm">Filtrer</button>
    <?php if ($q !== '' || $filtre !== ''): ?>
    <a class="btn btn-glass btn-sm" href="journal.php">Réinitialiser</a>
    <?php endif; ?>
  </form>
  <form method="post" style="margin:-42px 0 14px;display:flex;justify-content:flex-end;gap:7px;align-items:center"
        onsubmit="return confirm('Supprimer définitivement ces entrées du journal ?')">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <span style="font-size:12.5px;color:var(--ink-faint)">Supprimer les entrées de plus de</span>
    <select class="input" name="jours" id="sel-jours" style="max-width:150px;padding:5px 9px;font-size:12.5px">
      <?php foreach ([30, 90, 180, 365] as $j): ?>
      <option value="<?= $j ?>" <?= $j === 90 ? 'selected' : '' ?> data-n="<?= $purgeables[$j] ?>">
        <?= $j ?> jours (<?= $purgeables[$j] ?>)
      </option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-glass btn-sm" name="purger" value="1" id="btn-purge"
            <?= $purgeables[90] === 0 ? 'disabled style="opacity:.45;cursor:not-allowed"' : '' ?>>🧹 Nettoyer</button>
  </form>
  <?php if (array_sum($purgeables) === 0): ?>
  <p style="margin:-6px 0 12px;font-size:12.5px;color:var(--ink-faint)">
    Rien à nettoyer : toutes les entrées sont récentes<?php if ($plusAncienne): ?>
    (la plus ancienne date du <?= date('d/m/Y', strtotime($plusAncienne)) ?>)<?php endif; ?>.
    Le nettoyage sert à alléger le journal au bout de plusieurs mois.
  </p>
  <?php endif; ?>

  <?php if (!$lignes): ?>
    <p style="color:var(--ink-faint)">Aucune entrée pour le moment.</p>
  <?php else: ?>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Date</th><th>Action</th><th>Par</th><th>Détail</th><th>Adresse</th></tr></thead>
      <tbody>
      <?php foreach ($lignes as $l): $inf = $LIBELLES[$l['action']] ?? ['•', $l['action']]; ?>
        <tr>
          <td style="white-space:nowrap"><?= date('d/m/Y H:i', strtotime($l['created_at'])) ?></td>
          <td><?= $inf[0] ?> <?= e($inf[1]) ?><?= $l['cible'] ? ' <span style="color:var(--ink-faint);font-size:12px">('.e($l['cible']).')</span>' : '' ?></td>
          <td><?= e($l['acteur'] ?: '—') ?><?= $l['role'] ? ' <span style="font-size:11px;color:var(--ink-faint)">'.e($l['role']).'</span>' : '' ?></td>
          <td style="font-size:12.5px"><?= e($l['detail']) ?></td>
          <td style="font-family:monospace;font-size:11px;color:var(--ink-faint)"><?= e($l['ip']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <div style="display:flex;gap:6px;justify-content:center;margin-top:14px;flex-wrap:wrap">
    <?php
    $lien = function ($p) use ($q, $filtre) {
        return 'journal.php?p=' . $p . ($q !== '' ? '&q=' . urlencode($q) : '') . ($filtre !== '' ? '&a=' . urlencode($filtre) : '');
    };
    if ($page > 1): ?><a class="btn btn-glass btn-sm" href="<?= $lien($page - 1) ?>">‹</a><?php endif;
    for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
      <a class="btn btn-sm <?= $i === $page ? 'btn-gold' : 'btn-glass' ?>" href="<?= $lien($i) ?>"><?= $i ?></a>
    <?php endfor;
    if ($page < $pages): ?><a class="btn btn-glass btn-sm" href="<?= $lien($page + 1) ?>">›</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<script>
(function(){
  var sel = document.getElementById('sel-jours');
  var btn = document.getElementById('btn-purge');
  if (!sel || !btn) return;
  function maj(){
    var n = parseInt(sel.options[sel.selectedIndex].dataset.n || '0', 10);
    btn.disabled = (n === 0);
    btn.style.opacity = n === 0 ? '.45' : '1';
    btn.style.cursor = n === 0 ? 'not-allowed' : 'pointer';
  }
  sel.addEventListener('change', maj); maj();
})();
</script>
<?php admin_footer(); ?>
