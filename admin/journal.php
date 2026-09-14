<?php
require __DIR__ . '/includes/auth.php';

/* ----------------------------------------------------------------------------
   Anciennetés proposées à la purge, définies UNE FOIS.
   Elles servent au contrôle de la valeur reçue, au calcul des compteurs et au
   menu déroulant : trois listes séparées finissaient toujours par diverger.
   Une semaine est utile après une séance de mise au point, quand le journal
   s'est rempli d'essais sans intérêt.
   ---------------------------------------------------------------------------- */
const ANCIENNETES = [7, 30, 90, 180, 365];

function anciennete_libelle(int $j): string {
    if ($j === 7)   return '1 semaine';
    if ($j === 30)  return '1 mois';
    if ($j === 90)  return '3 mois';
    if ($j === 180) return '6 mois';
    if ($j === 365) return '1 an';
    return $j . ' jours';
}
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
    if (!in_array($jours, ANCIENNETES, true)) $jours = 90;

    $st = $pdo->prepare("SELECT COUNT(*) FROM journal WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $st->execute([$jours]);
    $aSupprimer = (int)$st->fetchColumn();

    if ($aSupprimer === 0) {
        flash("Aucune entrée de plus de " . anciennete_libelle($jours) . " : le journal est déjà à jour.");
    } else {
        $pdo->prepare("DELETE FROM journal WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)")->execute([$jours]);
        journaliser($pdo, 'purge', 'journal', null, $aSupprimer . ' entrée(s) de plus de ' . anciennete_libelle($jours) . ' supprimée(s)');
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
foreach (ANCIENNETES as $j) {
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
  <?php /* Deux formulaires distincts, sur deux lignes distinctes. L'ancienne
           version remontait le second de 42 pixels pour l'aligner sur le
           premier : dès que la fenêtre rétrécissait, les deux se chevauchaient. */ ?>
  <div class="jr-tete">
    <div>
      <h2 style="margin:0">📖 Journal des actions <span class="cnt"><?= number_format($total, 0, ',', ' ') ?></span></h2>
      <p class="jr-sous">Qui a fait quoi, et quand.</p>
    </div>
    <form method="get" class="jr-filtres">
      <input class="input" name="q" value="<?= e($q) ?>" placeholder="Rechercher…">
      <select class="input" name="a">
        <option value="">Toutes les actions</option>
        <?php foreach ($LIBELLES as $k => [$ic, $lb]): ?>
        <option value="<?= $k ?>" <?= $filtre === $k ? 'selected' : '' ?>><?= $ic ?> <?= $lb ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-gold btn-sm">🔍</button>
      <?php if ($q !== '' || $filtre !== ''): ?>
      <a class="btn btn-glass btn-sm" href="journal.php">Effacer</a>
      <?php endif; ?>
    </form>
  </div>

  <form method="post" class="jr-purge"
        data-confirm="Supprimer définitivement ces entrées du journal ? Elles ne pourront pas être récupérées.">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <span class="jp-txt">🧹 Supprimer les entrées de plus de</span>
    <select class="input" name="jours" id="sel-jours">
      <?php foreach (ANCIENNETES as $j): ?>
      <option value="<?= $j ?>" <?= $j === 90 ? 'selected' : '' ?> data-n="<?= $purgeables[$j] ?>">
        <?= anciennete_libelle($j) ?> — <?= $purgeables[$j] ?> entrée<?= $purgeables[$j] > 1 ? 's' : '' ?>
      </option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-glass btn-sm" name="purger" value="1" id="btn-purge"
            <?= $purgeables[90] === 0 ? 'disabled' : '' ?>>Nettoyer</button>
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
  <?php
  /* Cinq colonnes serrées obligeaient à couper les mots et à lire en diagonale.
     Une ligne par action, groupée par jour, se parcourt bien mieux : on cherche
     presque toujours « ce qui s'est passé tel jour ». */
  $moisFr = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
             'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  $jourPrec = '';
  ?>
  <div class="jr">
    <?php foreach ($lignes as $l):
      $inf = $LIBELLES[$l['action']] ?? ['•', $l['action']];
      $t = strtotime($l['created_at']);
      $jour = date('Y-m-d', $t);
    ?>
      <?php if ($jour !== $jourPrec): $jourPrec = $jour; ?>
      <div class="jr-jour">
        <?php
          if ($jour === date('Y-m-d'))                          echo "Aujourd'hui";
          elseif ($jour === date('Y-m-d', strtotime('-1 day'))) echo 'Hier';
          else echo date('j', $t) . ' ' . $moisFr[(int)date('n', $t)] . ' ' . date('Y', $t);
        ?>
      </div>
      <?php endif; ?>

      <div class="jr-l act-<?= e($l['action']) ?>">
        <span class="jl-h"><?= date('H:i', $t) ?></span>
        <span class="jl-ico" title="<?= e($inf[1]) ?>"><?= $inf[0] ?></span>

        <div class="jl-c">
          <div class="jl-t">
            <strong><?= e($inf[1]) ?></strong>
            <?php if ($l['cible']): ?><span class="jl-cible"><?= e($l['cible']) ?></span><?php endif; ?>
            <span class="jl-par"><?= e($l['acteur'] ?: '—') ?><?php
              if ($l['role']): ?> <em><?= e($l['role']) ?></em><?php endif; ?></span>
          </div>
          <?php if (trim((string)$l['detail']) !== ''): ?>
          <div class="jl-d"><?= e($l['detail']) ?></div>
          <?php endif; ?>
        </div>

        <?php if ($l['ip']): ?><span class="jl-ip"><?= e($l['ip']) ?></span><?php endif; ?>
      </div>
    <?php endforeach; ?>
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
