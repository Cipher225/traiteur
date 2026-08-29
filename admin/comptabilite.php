<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
$devise = $settings['devise'] ?? 'FCFA';

$cats_entree = ['Prestation','Acompte','Vente','Remboursement','Autre'];
$cats_depense = ['Approvisionnement','Salaires','Loyer','Transport','Matériel','Électricité/Eau','Marketing','Taxes','Autre'];
$modes = modes_paiement();   // liste commune à toute l'application

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['supprimer'])) {
        $pdo->prepare('DELETE FROM transactions WHERE id=?')->execute([(int)$_POST['supprimer']]);
        flash('Opération supprimée.');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $type = $_POST['type'] === 'entree' ? 'entree' : 'depense';
        $libelle = trim($_POST['libelle'] ?? '');
        $montant = max(0, (float)($_POST['montant'] ?? 0));
        if ($libelle === '' || $montant <= 0) { flash('Libellé et montant (> 0) obligatoires.', 'error'); }
        else {
            $data = [
                $type,
                mb_substr(trim($_POST['categorie'] ?? 'Autre'), 0, 80),
                mb_substr($libelle, 0, 200),
                $montant,
                mb_substr(trim($_POST['mode_paiement'] ?? 'Espèces'), 0, 40),
                ($_POST['client_id'] ?? '') ?: null,
                ($_POST['date_operation'] ?? '') ?: date('Y-m-d'),
                mb_substr(trim($_POST['notes'] ?? ''), 0, 500),
            ];
            if ($id) {
                $pdo->prepare('UPDATE transactions SET type=?, categorie=?, libelle=?, montant=?, mode_paiement=?, client_id=?, date_operation=?, notes=? WHERE id=?')->execute([...$data, $id]);
                flash('Opération modifiée.');
            } else {
                $pdo->prepare('INSERT INTO transactions (type, categorie, libelle, montant, mode_paiement, client_id, date_operation, notes) VALUES (?,?,?,?,?,?,?,?)')->execute($data);
                flash('Opération enregistrée.');
            }
        }
    }
    header('Location: comptabilite.php'); exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch();
}

// Filtres période
$mois = $_GET['mois'] ?? date('Y-m');
$ftype = $_GET['type'] ?? '';
$where = "WHERE DATE_FORMAT(date_operation,'%Y-%m') = ?";
$params = [$mois];
if (in_array($ftype, ['entree','depense'])) { $where .= " AND type = ?"; $params[] = $ftype; }

$stmt = $pdo->prepare("SELECT t.*, c.nom AS client FROM transactions t LEFT JOIN clients c ON c.id=t.client_id $where ORDER BY date_operation DESC, t.id DESC");
$stmt->execute($params);
$ops = $stmt->fetchAll();

// Totaux du mois
$tot = $pdo->prepare("SELECT type, COALESCE(SUM(montant),0) s FROM transactions WHERE DATE_FORMAT(date_operation,'%Y-%m')=? GROUP BY type");
$tot->execute([$mois]);
$entrees = $depenses = 0;
foreach ($tot as $r) { if ($r['type']==='entree') $entrees = (float)$r['s']; else $depenses = (float)$r['s']; }
$solde = $entrees - $depenses;

// Solde global tous mois
$g = $pdo->query("SELECT COALESCE(SUM(CASE WHEN type='entree' THEN montant ELSE -montant END),0) FROM transactions")->fetchColumn();

$clients = $pdo->query('SELECT id, nom FROM clients ORDER BY nom')->fetchAll();

admin_header('Comptabilité', 'comptabilite', $pdo, $settings);
?>
<div class="stats">
  <div class="stat glass teal"><div class="s-ico">📈</div><div class="s-num" style="font-size:24px"><?= money($entrees, $devise) ?></div><div class="s-label">Entrées du mois</div></div>
  <div class="stat glass rose"><div class="s-ico">📉</div><div class="s-num" style="font-size:24px"><?= money($depenses, $devise) ?></div><div class="s-label">Dépenses du mois</div></div>
  <div class="stat glass <?= $solde>=0?'gold':'rose' ?>"><div class="s-ico"><?= $solde>=0?'✅':'⚠️' ?></div><div class="s-num" style="font-size:24px"><?= money($solde, $devise) ?></div><div class="s-label">Solde du mois</div></div>
  <div class="stat glass violet"><div class="s-ico">🏦</div><div class="s-num" style="font-size:24px"><?= money($g, $devise) ?></div><div class="s-label">Solde global (trésorerie)</div></div>
</div>

<div class="panel glass" id="form">
  <h2><?= $edit ? '✏️ Modifier l\'opération' : '➕ Nouvelle opération' ?></h2>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
    <div class="field"><label>Type *</label>
      <select class="input" name="type" id="typeSel" onchange="majCats()">
        <option value="entree" <?= ($edit['type'] ?? '')==='entree'?'selected':'' ?>>💚 Entrée (argent reçu)</option>
        <option value="depense" <?= ($edit['type'] ?? 'depense')==='depense'?'selected':'' ?>>❤️ Dépense (argent sorti)</option>
      </select>
    </div>
    <div class="field"><label>Catégorie</label><select class="input" name="categorie" id="catSel"></select></div>
    <div class="field"><label>Montant (<?= e($devise) ?>) *</label><input class="input" type="number" name="montant" min="0" step="100" required value="<?= e($edit['montant'] ?? '') ?>"></div>
    <div class="field"><label>Date</label><input class="input" type="date" name="date_operation" value="<?= e($edit['date_operation'] ?? date('Y-m-d')) ?>"></div>
    <div class="field"><label>Mode de paiement</label>
      <select class="input" name="mode_paiement">
        <?php foreach ($modes as $m): ?><option <?= ($edit['mode_paiement'] ?? '')===$m?'selected':'' ?>><?= $m ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Client (facultatif)</label>
      <select class="input" name="client_id"><option value="">—</option>
        <?php foreach ($clients as $c): ?><option value="<?= $c['id'] ?>" <?= ($edit['client_id'] ?? 0)==$c['id']?'selected':'' ?>><?= e($c['nom']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field full"><label>Libellé *</label><input class="input" name="libelle" required value="<?= e($edit['libelle'] ?? '') ?>" placeholder="ex : Acompte mariage Konan"></div>
    <div class="full" style="display:flex;gap:10px">
      <button class="btn btn-gold"><?= $edit ? 'Enregistrer' : 'Enregistrer l\'opération' ?></button>
      <?php if ($edit): ?><a class="btn btn-glass" href="comptabilite.php">Annuler</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="panel glass">
  <h2>💰 Journal des opérations
    <form method="get" style="margin-left:auto;display:flex;gap:8px;align-items:center">
      <input class="input" type="month" name="mois" value="<?= e($mois) ?>" style="padding:8px 12px" onchange="this.form.submit()">
      <select class="input" name="type" style="padding:8px 12px" onchange="this.form.submit()">
        <option value="">Tout</option>
        <option value="entree" <?= $ftype==='entree'?'selected':'' ?>>Entrées</option>
        <option value="depense" <?= $ftype==='depense'?'selected':'' ?>>Dépenses</option>
      </select>
    </form>
  </h2>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Date</th><th>Libellé</th><th>Catégorie</th><th>Mode</th><th style="text-align:right">Montant</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        <?php foreach ($ops as $o): ?>
        <tr>
          <td><?= date('d/m/Y', strtotime($o['date_operation'])) ?></td>
          <td><strong><?= e($o['libelle']) ?></strong><?= $o['client'] ? '<br><small>'.e($o['client']).'</small>' : '' ?></td>
          <td><span class="badge"><?= e($o['categorie']) ?></span></td>
          <td><?= e($o['mode_paiement']) ?></td>
          <td style="text-align:right;font-weight:800;color:<?= $o['type']==='entree'?'var(--teal)':'#ffb1b1' ?>">
            <?= $o['type']==='entree'?'+':'−' ?> <?= money($o['montant'], $devise) ?>
          </td>
          <td>
            <div class="td-actions">
              <a class="btn btn-glass btn-sm" href="?edit=<?= $o['id'] ?>#form">✏️</a>
              <form method="post" data-confirm="Supprimer cette opération ?">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <button class="btn btn-danger btn-sm" name="supprimer" value="<?= $o['id'] ?>">✕</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$ops): ?><tr><td colspan="6" style="text-align:center;padding:30px;color:var(--ink-faint)">Aucune opération pour cette période.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
const CATS = { entree: <?= json_encode($cats_entree) ?>, depense: <?= json_encode($cats_depense) ?> };
const currentCat = <?= json_encode($edit['categorie'] ?? '') ?>;
function majCats() {
  const t = document.getElementById('typeSel').value;
  const sel = document.getElementById('catSel');
  sel.innerHTML = '';
  CATS[t].forEach(c => {
    const o = document.createElement('option'); o.textContent = c; o.value = c;
    if (c === currentCat) o.selected = true;
    sel.appendChild(o);
  });
}
majCats();
</script>
<?php admin_footer(); ?>
