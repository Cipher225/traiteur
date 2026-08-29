<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
$devise = $settings['devise'] ?? 'FCFA';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['supprimer'])) {
        $pdo->prepare('DELETE FROM fiches_paie WHERE id=?')->execute([(int)$_POST['supprimer']]);
        flash('Bulletin de paie supprimé.'); header('Location: paie.php'); exit;
    }
    if (isset($_POST['statut'], $_POST['id_statut'])) {
        $st = $_POST['statut'] === 'payee' ? 'payee' : 'brouillon';
        $pdo->prepare('UPDATE fiches_paie SET statut=?, date_paiement=? WHERE id=?')
            ->execute([$st, $st==='payee'?date('Y-m-d'):null, (int)$_POST['id_statut']]);
        flash('Statut mis à jour.'); header('Location: paie.php'); exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    $emp = ($_POST['employe_id'] ?? '') ?: null;
    $periode = preg_match('/^\d{4}-\d{2}$/', $_POST['periode'] ?? '') ? $_POST['periode'] : date('Y-m');
    $num = fn($k) => max(0, (float)($_POST[$k] ?? 0));
    $base=$num('salaire_base'); $sursal=$num('sursalaire'); $primes=$num('primes');
    $ptrans=$num('prime_transport'); $panc=$num('prime_anciennete'); $indem=$num('indemnites'); $hsup=$num('heures_sup');
    $cnps=$num('cnps'); $cnpsE=$num('cnps_employeur'); $impots=$num('impots'); $autres=$num('autres_deductions'); $avances=$num('avances');
    $jours = max(0, (float)($_POST['jours_travailles'] ?? 30));
    $gains = $base+$sursal+$primes+$ptrans+$panc+$indem+$hsup;
    $retenues = $cnps+$impots+$autres+$avances;
    $net = $gains - $retenues;
    $mode = mb_substr(trim($_POST['mode_paiement'] ?? 'Virement bancaire'), 0, 40);
    $banque = mb_substr(trim($_POST['banque'] ?? ''), 0, 120);
    $compte = mb_substr(trim($_POST['numero_compte'] ?? ''), 0, 60);
    $notes = mb_substr(trim($_POST['notes'] ?? ''), 0, 500);

    $cols = 'employe_id=?,periode=?,jours_travailles=?,salaire_base=?,sursalaire=?,primes=?,prime_transport=?,prime_anciennete=?,indemnites=?,heures_sup=?,cnps=?,cnps_employeur=?,impots=?,autres_deductions=?,avances=?,net_a_payer=?,mode_paiement=?,banque=?,numero_compte=?,notes=?';
    $vals = [$emp,$periode,$jours,$base,$sursal,$primes,$ptrans,$panc,$indem,$hsup,$cnps,$cnpsE,$impots,$autres,$avances,$net,$mode,$banque,$compte,$notes];
    if ($id) {
        $pdo->prepare("UPDATE fiches_paie SET $cols WHERE id=?")->execute([...$vals, $id]);
        flash('Bulletin de paie modifié.');
    } else {
        $numero = next_numero($pdo, 'fiches_paie', $settings['prefixe_fiche'] ?? 'PAIE');
        $pdo->prepare("INSERT INTO fiches_paie SET numero=?, $cols")->execute([$numero, ...$vals]);
        flash('Bulletin de paie ' . $numero . ' créé.');
    }
    header('Location: paie.php'); exit;
}

$mode_form = false; $edit = null; $pre_emp = null; $emp_data = null;
if (isset($_GET['edit'])) {
    $mode_form = true;
    if ($_GET['edit'] !== 'new') {
        $stmt = $pdo->prepare('SELECT * FROM fiches_paie WHERE id=?'); $stmt->execute([(int)$_GET['edit']]);
        $edit = $stmt->fetch(); $pre_emp = $edit['employe_id'] ?? '';
    } else { $pre_emp = $_GET['employe'] ?? ''; }
    if ($pre_emp) { $e = $pdo->prepare('SELECT * FROM employes WHERE id=?'); $e->execute([$pre_emp]); $emp_data = $e->fetch(); }
}

$employes = $pdo->query('SELECT id, nom, poste, salaire_base, banque, numero_compte FROM employes WHERE actif=1 ORDER BY nom')->fetchAll();
$fiches = $pdo->query("SELECT fp.*, e.nom AS employe, e.poste FROM fiches_paie fp LEFT JOIN employes e ON e.id=fp.employe_id ORDER BY fp.periode DESC, fp.id DESC")->fetchAll();

/* Rangement par année → mois (période de paie) */
require_once __DIR__ . '/includes/rangement.php';
/* On normalise la date de période pour le regroupement */
foreach ($fiches as &$_f) { $_f['_date'] = ($_f['periode'] ?? '') . '-01'; }
unset($_f);
$vueRng = ($_GET['vue'] ?? 'arbre') === 'liste' ? 'liste' : 'arbre';
$fRng = ['mois' => (int)($_GET['fm'] ?? 0), 'annee' => (int)($_GET['fa'] ?? 0)];
$fichesAff = rangement_filtrer($fiches, $fRng, '_date', 'employe_id');
$anneesRng = rangement_annees($fiches, '_date');

// Valeur par défaut banque/compte : celle de l'employé si non renseignée sur la fiche
$defBanque = $edit['banque'] ?? ($emp_data['banque'] ?? '');
$defCompte = $edit['numero_compte'] ?? ($emp_data['numero_compte'] ?? '');

admin_header('Bulletins de paie', 'paie', $pdo, $settings);
?>
<?php if ($mode_form): ?>
<div class="panel glass">
  <h2><?= $edit ? '✏️ Modifier ' . e($edit['numero']) : '📄 Nouveau bulletin de paie' ?>
    <a href="paie.php" class="btn btn-glass btn-sm" style="margin-left:auto">← Retour</a>
  </h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
    <div class="form-grid">
      <div class="field"><label>Employé</label>
        <select class="input" name="employe_id" id="empSel" onchange="remplirEmp()">
          <option value="">— Sélectionner —</option>
          <?php foreach ($employes as $em): ?>
          <option value="<?= $em['id'] ?>" data-base="<?= (int)$em['salaire_base'] ?>" data-banque="<?= e($em['banque']) ?>" data-compte="<?= e($em['numero_compte']) ?>" <?= (string)$pre_emp===(string)$em['id']?'selected':'' ?>><?= e($em['nom']) ?> — <?= e($em['poste']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Période (mois)</label><input class="input" type="month" name="periode" value="<?= e($edit['periode'] ?? date('Y-m')) ?>"></div>
      <div class="field"><label>Jours / base travaillés</label><input class="input" type="number" name="jours_travailles" min="0" max="31" step="0.5" value="<?= e($edit['jours_travailles'] ?? 30) ?>"></div>
    </div>

    <h3 class="form-section" style="color:var(--teal,#3edbc1)">➕ Gains</h3>
    <div class="form-grid">
      <div class="field"><label>Salaire de base</label><input class="input g" type="number" name="salaire_base" id="base" min="0" step="1000" value="<?= e($edit['salaire_base'] ?? ($emp_data['salaire_base'] ?? 0)) ?>" oninput="calc()"></div>
      <div class="field"><label>Sursalaire</label><input class="input g" type="number" name="sursalaire" min="0" step="1000" value="<?= e($edit['sursalaire'] ?? 0) ?>" oninput="calc()"></div>
      <div class="field"><label>Prime de transport</label><input class="input g" type="number" name="prime_transport" min="0" step="500" value="<?= e($edit['prime_transport'] ?? 0) ?>" oninput="calc()"></div>
      <div class="field"><label>Prime d'ancienneté</label><input class="input g" type="number" name="prime_anciennete" min="0" step="500" value="<?= e($edit['prime_anciennete'] ?? 0) ?>" oninput="calc()"></div>
      <div class="field"><label>Autres primes</label><input class="input g" type="number" name="primes" min="0" step="1000" value="<?= e($edit['primes'] ?? 0) ?>" oninput="calc()"></div>
      <div class="field"><label>Indemnités</label><input class="input g" type="number" name="indemnites" min="0" step="1000" value="<?= e($edit['indemnites'] ?? 0) ?>" oninput="calc()"></div>
      <div class="field"><label>Heures supplémentaires</label><input class="input g" type="number" name="heures_sup" min="0" step="1000" value="<?= e($edit['heures_sup'] ?? 0) ?>" oninput="calc()"></div>
    </div>

    <h3 class="form-section" style="color:#ffb1b1">➖ Retenues (part salariale)</h3>
    <div class="form-grid">
      <div class="field"><label>CNPS (6,3 %)</label><input class="input d" type="number" name="cnps" min="0" step="500" value="<?= e($edit['cnps'] ?? 0) ?>" oninput="calc()"></div>
      <div class="field"><label>Impôts (ITS)</label><input class="input d" type="number" name="impots" min="0" step="500" value="<?= e($edit['impots'] ?? 0) ?>" oninput="calc()"></div>
      <div class="field"><label>Avances / acomptes</label><input class="input d" type="number" name="avances" min="0" step="500" value="<?= e($edit['avances'] ?? 0) ?>" oninput="calc()"></div>
      <div class="field"><label>Autres retenues</label><input class="input d" type="number" name="autres_deductions" min="0" step="500" value="<?= e($edit['autres_deductions'] ?? 0) ?>" oninput="calc()"></div>
    </div>

    <h3 class="form-section">🏦 Charges patronales & paiement</h3>
    <div class="form-grid">
      <div class="field"><label>CNPS employeur (information)</label><input class="input" type="number" name="cnps_employeur" min="0" step="500" value="<?= e($edit['cnps_employeur'] ?? 0) ?>"></div>
      <div class="field"><label>Mode de paiement</label>
        <select class="input" name="mode_paiement">
          <?php foreach (modes_paiement() as $m): ?>
          <option <?= ($edit['mode_paiement'] ?? 'Virement bancaire')===$m?'selected':'' ?>><?= $m ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Banque</label><input class="input" name="banque" id="banque" value="<?= e($defBanque) ?>"></div>
      <div class="field"><label>N° de compte / RIB</label><input class="input" name="numero_compte" id="compte" value="<?= e($defCompte) ?>"></div>
    </div>

    <div style="display:flex;justify-content:flex-end;margin-top:22px">
      <div class="glass" style="padding:20px 26px;border-radius:16px;min-width:300px">
        <div style="display:flex;justify-content:space-between;padding:5px 0;color:var(--ink-dim)"><span>Salaire brut</span><strong id="v-gains" style="color:var(--teal)">0</strong></div>
        <div style="display:flex;justify-content:space-between;padding:5px 0;color:var(--ink-dim)"><span>Total retenues</span><strong id="v-ret" style="color:#ffb1b1">0</strong></div>
        <div style="display:flex;justify-content:space-between;padding:12px 0 0;margin-top:8px;border-top:1px solid var(--glass-border);font-size:18px"><span style="font-weight:700">Net à payer</span><strong id="v-net" style="color:var(--gold)">0</strong></div>
      </div>
    </div>

    <div class="field full" style="margin-top:20px"><label>Notes (mention particulière)</label><textarea class="input" name="notes" style="min-height:56px"><?= e($edit['notes'] ?? '') ?></textarea></div>
    <div style="display:flex;gap:10px;margin-top:16px">
      <button class="btn btn-gold"><?= $edit ? 'Enregistrer' : 'Créer le bulletin' ?></button>
      <a class="btn btn-glass" href="paie.php">Annuler</a>
    </div>
  </form>
</div>
<script>
const DEVISE = <?= json_encode($devise) ?>;
const IS_EDIT = <?= $edit ? 'true':'false' ?>;
function fmt(n){ return new Intl.NumberFormat('fr-FR').format(Math.round(n)) + ' ' + DEVISE; }
function remplirEmp(){
  const opt = document.getElementById('empSel').selectedOptions[0];
  if (!opt || IS_EDIT) return;
  if (opt.dataset.base) document.getElementById('base').value = opt.dataset.base;
  if (opt.dataset.banque) document.getElementById('banque').value = opt.dataset.banque;
  if (opt.dataset.compte) document.getElementById('compte').value = opt.dataset.compte;
  calc();
}
function calc(){
  let g=0,d=0;
  document.querySelectorAll('.g').forEach(i=>g+=parseFloat(i.value)||0);
  document.querySelectorAll('.d').forEach(i=>d+=parseFloat(i.value)||0);
  document.getElementById('v-gains').textContent=fmt(g);
  document.getElementById('v-ret').textContent=fmt(d);
  document.getElementById('v-net').textContent=fmt(g-d);
}
calc();
</script>

<?php else: ?>
<div class="panel glass">
  <h2>📄 Bulletins de paie (<?= count($fiches) ?>)
    <a href="paie.php?edit=new" class="btn btn-gold btn-sm" style="margin-left:auto">➕ Nouveau bulletin</a>
  </h2>
  <?php
  $moisFr = fn($m) => rangement_mois_fr((int)$m);
  ?>
  <form method="get" class="rng-filtres">
    <div class="f"><label>Mois</label>
      <select class="input" name="fm" onchange="this.form.submit()">
        <option value="0">Tous</option>
        <?php for($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $fRng['mois']==$m?'selected':'' ?>><?= $moisFr($m) ?></option><?php endfor; ?>
      </select>
    </div>
    <div class="f"><label>Année</label>
      <select class="input" name="fa" onchange="this.form.submit()">
        <option value="0">Toutes</option>
        <?php foreach ($anneesRng as $a): ?><option value="<?= $a ?>" <?= $fRng['annee']==$a?'selected':'' ?>><?= $a ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="rng-vue">
      <a href="paie.php?vue=arbre<?= $fRng['mois']?'&fm='.$fRng['mois']:'' ?><?= $fRng['annee']?'&fa='.$fRng['annee']:'' ?>" class="<?= $vueRng==='arbre'?'on':'' ?>">🗂️ Arbre</a>
      <a href="paie.php?vue=liste<?= $fRng['mois']?'&fm='.$fRng['mois']:'' ?><?= $fRng['annee']?'&fa='.$fRng['annee']:'' ?>" class="<?= $vueRng==='liste'?'on':'' ?>">📋 Liste</a>
    </div>
  </form>

  <?php
  $renderFiche = function($fp) use ($devise) { ob_start(); ?>
    <div class="rng-doc">
      <span class="num"><?= e($fp['numero']) ?></span>
      <span class="dt"><?= e($fp['employe'] ?: '—') ?><?= $fp['poste']?' · '.e($fp['poste']):'' ?></span>
      <span class="mt"><?= money($fp['net_a_payer'], $devise) ?></span>
      <span class="acts">
        <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id_statut" value="<?= $fp['id'] ?>">
          <select name="statut" onchange="this.form.submit()" class="input" style="padding:4px 8px;font-size:11px">
            <option value="brouillon" <?= $fp['statut']==='brouillon'?'selected':'' ?>>Brouillon</option>
            <option value="payee" <?= $fp['statut']==='payee'?'selected':'' ?>>Payé</option>
          </select></form>
        <a class="btn btn-glass btn-sm" href="print.php?type=fiche&id=<?= $fp['id'] ?>" target="_blank" title="Voir">📄</a>
        <?php if(is_admin()): ?><a class="btn btn-glass btn-sm" href="print.php?type=fiche&id=<?= $fp['id'] ?>&auth=1" target="_blank" title="Authentifiable">🔐</a><?php endif; ?>
        <a class="btn btn-glass btn-sm" href="paie.php?edit=<?= $fp['id'] ?>" title="Modifier">✏️</a>
        <form method="post" data-confirm="Supprimer <?= e($fp['numero']) ?> ?" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><button class="btn btn-danger btn-sm" name="supprimer" value="<?= $fp['id'] ?>">✕</button></form>
      </span>
    </div>
  <?php return ob_get_clean(); };
  ?>

  <?php if (!$fichesAff): ?>
    <div style="text-align:center;padding:34px;color:var(--ink-faint)">Aucun bulletin ne correspond.</div>
  <?php elseif ($vueRng === 'liste'): ?>
    <div class="rng-docs" style="margin-left:0"><?php foreach ($fichesAff as $fp) echo $renderFiche($fp); ?></div>
  <?php else: ?>
    <?php $arbre = rangement_par_mois($fichesAff, '_date'); ?>
    <div class="rng-tree">
      <?php foreach ($arbre as $annee => $mois): $nbA=0; foreach($mois as $ds) $nbA+=count($ds); ?>
      <details class="rng-annee" open>
        <summary><?= $annee ?><span class="cnt"><?= $nbA ?> bulletin<?= $nbA>1?'s':'' ?></span></summary>
        <?php foreach ($mois as $m => $docsM): ?>
        <details class="rng-mois" open>
          <summary><?= $moisFr($m) ?><span class="cnt"><?= count($docsM) ?></span></summary>
          <div class="rng-docs"><?php foreach ($docsM as $fp) echo $renderFiche($fp); ?></div>
        </details>
        <?php endforeach; ?>
      </details>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/includes/envoyer_modal.php'; ?>
<?php endif; ?>
<?php admin_footer(); ?>
