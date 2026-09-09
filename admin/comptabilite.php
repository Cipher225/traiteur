<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
$devise = $settings['devise'] ?? 'FCFA';

/* Les catégories sont définies en un seul endroit : la comptabilité, les bons
   d'entrée et les bons de sortie parlent ainsi le même langage. Sans cela,
   « Loyer » ici et « Achats » là produiraient des états incohérents. */
$cats_entree  = array_keys(categories_recette());
$cats_depense = array_keys(categories_depense());
$modes = modes_paiement();   // liste commune à toute l'application

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    /* ---- Charges récurrentes ---- */
    if (isset($_POST['charge_creer'])) {
        $lib = trim($_POST['ch_libelle'] ?? '');
        $mt  = max(0, (float)($_POST['ch_montant'] ?? 0));
        if ($lib === '' || $mt <= 0) flash('Libellé et montant obligatoires.', 'error');
        else {
            $pdo->prepare('INSERT INTO charges_recurrentes (libelle, categorie, montant, mode_paiement, jour_du_mois)
                           VALUES (?,?,?,?,?)')
                ->execute([mb_substr($lib, 0, 160),
                           mb_substr(trim($_POST['ch_categorie'] ?? 'Divers'), 0, 80), $mt,
                           mb_substr(trim($_POST['ch_mode'] ?? 'Espèces'), 0, 40),
                           max(1, min(28, (int)($_POST['ch_jour'] ?? 1)))]);
            flash('Charge récurrente ajoutée. Elle vous sera proposée chaque mois.');
        }
        header('Location: comptabilite.php#charges'); exit;
    }

    if (isset($_POST['charge_retirer'])) {
        $pdo->prepare('DELETE FROM charges_recurrentes WHERE id=?')->execute([(int)$_POST['charge_retirer']]);
        flash('Charge récurrente retirée.');
        header('Location: comptabilite.php#charges'); exit;
    }

    if (isset($_POST['charge_enregistrer'])) {
        /* On enregistre les charges du mois cochées, en une seule fois. */
        $moisCible = preg_match('/^\d{4}-\d{2}$/', (string)($_POST['ch_mois'] ?? '')) ? $_POST['ch_mois'] : date('Y-m');
        $n = 0;
        $ins = $pdo->prepare("INSERT INTO transactions (type, categorie, libelle, montant, mode_paiement,
                              date_operation, notes) VALUES ('depense',?,?,?,?,?,?)");
        foreach ((array)($_POST['charges'] ?? []) as $cid) {
            $st = $pdo->prepare('SELECT * FROM charges_recurrentes WHERE id=? AND actif=1');
            $st->execute([(int)$cid]);
            if (!$ch = $st->fetch()) continue;

            /* Sécurité : jamais deux fois la même charge dans le même mois. */
            $v = $pdo->prepare("SELECT 1 FROM transactions WHERE libelle=? AND DATE_FORMAT(date_operation,'%Y-%m')=?");
            $v->execute([$ch['libelle'], $moisCible]);
            if ($v->fetchColumn()) continue;

            $jour = str_pad((string)min(28, max(1, (int)$ch['jour_du_mois'])), 2, '0', STR_PAD_LEFT);
            $ins->execute([$ch['categorie'], $ch['libelle'], $ch['montant'], $ch['mode_paiement'],
                           $moisCible . '-' . $jour, 'Charge récurrente enregistrée pour le mois.']);
            $n++;
        }
        journaliser($pdo, 'creation', 'ecriture', null, $n . ' charge(s) récurrente(s) enregistrée(s)');
        flash($n > 0 ? $n . ' charge(s) enregistrée(s) pour ' . $moisCible . '.' : 'Aucune charge à enregistrer.');
        header('Location: comptabilite.php#charges'); exit;
    }

    if (isset($_POST['supprimer'])) {
        /* Une écriture née d'un bon d'entrée ou de sortie appartient à ce bon :
           la supprimer ici laisserait le bon sans contrepartie comptable.
           On renvoie donc vers le document d'origine. */
        $id = (int)$_POST['supprimer'];
        $st = $pdo->prepare('SELECT recu_id FROM transactions WHERE id=?');
        $st->execute([$id]);
        $src = $st->fetchColumn();
        if ($src) {
            flash("Cette écriture provient d'un bon de caisse. Supprimez le bon : "
                . "l'écriture disparaîtra avec lui.", 'error');
        } else {
            $pdo->prepare('DELETE FROM transactions WHERE id=?')->execute([$id]);
            journaliser($pdo, 'suppression', 'ecriture', $id, 'Écriture manuelle supprimée');
            flash('Opération supprimée.');
        }
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
            /* Une écriture issue d'un bon se modifie sur le bon, pas ici :
               sinon les deux versions divergeraient silencieusement. */
            $sourceBon = null;
            if ($id) {
                $st = $pdo->prepare('SELECT recu_id FROM transactions WHERE id=?');
                $st->execute([$id]);
                $sourceBon = $st->fetchColumn() ?: null;
            }

            if ($sourceBon) {
                flash("Cette écriture provient d'un bon de caisse. Modifiez le bon : "
                    . "l'écriture suivra automatiquement.", 'error');

            } elseif (!$id && ($doublon = ecriture_doublon($pdo, $type, $montant, $data[6]))
                      && empty($_POST['confirmer_doublon'])) {
                /* Même montant, même sens, à quelques jours près : très
                   probablement la même opération saisie deux fois. */
                $_SESSION['compta_doublon'] = $doublon;
                flash('Une opération identique existe déjà : ' . e($doublon['libelle'])
                    . ' du ' . date('d/m/Y', strtotime($doublon['date_operation']))
                    . ' (' . number_format((float)$doublon['montant'], 0, ',', ' ') . ').'
                    . ' Confirmez si vous voulez tout de même l\'enregistrer.', 'error');

            } elseif ($id) {
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

/* ----------------------------------------------------------------------------
   Le journal se lit par pages. Afficher mille écritures d'un coup rend la page
   lourde à charger et impossible à parcourir : on en montre 40 à la fois, avec
   les totaux calculés sur la période ENTIÈRE, pas seulement sur la page.
   ---------------------------------------------------------------------------- */
$parPage = 40;
$stTot = $pdo->prepare("SELECT COUNT(*) FROM transactions t LEFT JOIN clients c ON c.id=t.client_id $where");
$stTot->execute($params);
$nbOps  = (int)$stTot->fetchColumn();
$nbPages = max(1, (int)ceil($nbOps / $parPage));
$page   = max(1, min($nbPages, (int)($_GET['p'] ?? 1)));
$depart = ($page - 1) * $parPage;

$stmt = $pdo->prepare("SELECT t.*, c.nom AS client FROM transactions t LEFT JOIN clients c ON c.id=t.client_id $where ORDER BY date_operation DESC, t.id DESC LIMIT $parPage OFFSET $depart");
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
$charges = charges_du_mois($pdo, $mois);
$chargesDues = array_values(array_filter($charges, fn($c) => !$c['deja']));

/* Montant court : la devise est rappelée en tête de page, la répéter sur
   chaque ligne alourdirait la lecture. */
if (!function_exists('nfc')) {
    function nfc($v) { return number_format((float)$v, 0, ',', ' '); }
}

admin_header('Comptabilité', 'comptabilite', $pdo, $settings);
?>
<?php
/* Combien d'écritures viennent des bons ? L'information rassure : la
   comptabilité se remplit toute seule, on ne saisit rien deux fois. */
$nbAuto = 0; $nbMain = 0;
/* Ces compteurs portent sur TOUTE la période, pas sur la page affichée :
   un décompte partiel n'aurait aucun sens. */
try {
    $st = $pdo->prepare("SELECT SUM(t.recu_id IS NOT NULL) a, SUM(t.recu_id IS NULL) m
                         FROM transactions t $where");
    $st->execute($params);
    $r = $st->fetch();
    $nbAuto = (int)($r['a'] ?? 0); $nbMain = (int)($r['m'] ?? 0);
} catch (Throwable $e) {}
?>
<div class="panel glass compta-guide">
  <span class="cg-ico">🧭</span>
  <div class="cg-t">
    <strong>Vos mouvements d'argent arrivent ici automatiquement</strong>
    <div>Chaque bon d'entrée ou de sortie crée son écriture : vous n'avez rien à ressaisir.
      N'ajoutez une <em>saisie directe</em> que pour ce qui ne passe pas par la caisse —
      un virement bancaire, une régularisation, un ajustement.
      <?php if ($nbAuto || $nbMain): ?>
      <br>Ce mois-ci : <strong><?= $nbAuto ?></strong> écriture(s) issue(s) des bons,
      <strong><?= $nbMain ?></strong> saisie(s) directe(s).
      <?php endif; ?></div>
  </div>
  <div class="cg-liens">
    <a class="btn btn-glass btn-sm" href="recus.php?type=entree">➕ Entrée de caisse</a>
    <a class="btn btn-glass btn-sm" href="recus.php?type=sortie">➖ Sortie de caisse</a>
  </div>
</div>

<div class="stats">
  <div class="stat glass teal"><div class="s-ico">📈</div><div class="s-num" style="font-size:24px"><?= money($entrees, $devise) ?></div><div class="s-label">Entrées du mois</div></div>
  <div class="stat glass rose"><div class="s-ico">📉</div><div class="s-num" style="font-size:24px"><?= money($depenses, $devise) ?></div><div class="s-label">Dépenses du mois</div></div>
  <div class="stat glass <?= $solde>=0?'gold':'rose' ?>"><div class="s-ico"><?= $solde>=0?'✅':'⚠️' ?></div><div class="s-num" style="font-size:24px"><?= money($solde, $devise) ?></div><div class="s-label">Solde du mois</div></div>
  <div class="stat glass violet"><div class="s-ico">🏦</div><div class="s-num" style="font-size:24px"><?= money($g, $devise) ?></div><div class="s-label">Solde global (trésorerie)</div></div>
</div>

<?php $doublon = $_SESSION['compta_doublon'] ?? null; unset($_SESSION['compta_doublon']); ?>
<?php if ($doublon): ?>
<div class="panel glass" style="border-left:4px solid #f0b429;margin-bottom:14px">
  <h2>⚠️ Opération peut-être déjà enregistrée</h2>
  <p style="font-size:13px;color:var(--ink-dim);line-height:1.6;margin:0 0 12px">
    Une écriture de même montant existe déjà :
    <strong><?= e($doublon['libelle']) ?></strong>,
    le <?= date('d/m/Y', strtotime($doublon['date_operation'])) ?>,
    <?= money($doublon['montant'], $devise) ?>
    <?= !empty($doublon['recu_id']) ? '(issue d\'un bon de caisse)' : '(saisie directe)' ?>.
    <br>Si c'est bien la même opération, ne la saisissez pas une seconde fois.
  </p>
  <form method="post" style="display:flex;gap:9px;flex-wrap:wrap">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <?php foreach (['type','categorie','libelle','montant','mode_paiement','client_id','date_operation','notes'] as $ch): ?>
    <input type="hidden" name="<?= $ch ?>" value="<?= e($_POST[$ch] ?? '') ?>">
    <?php endforeach; ?>
    <button class="btn btn-gold btn-sm" name="confirmer_doublon" value="1">Enregistrer quand même</button>
    <a class="btn btn-glass btn-sm" href="comptabilite.php">Annuler</a>
  </form>
</div>
<?php endif; ?>

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
  <?php
  /* Un tableau à sept colonnes devient illisible dès la dixième ligne : les
     colonnes se serrent et le libellé se coupe. On présente donc chaque
     opération sur une ligne compacte, où l'essentiel — date, libellé, montant —
     saute aux yeux, et le reste s'efface derrière. */
  $moisNoms = ['', 'jan', 'fév', 'mar', 'avr', 'mai', 'juin', 'juil', 'août', 'sep', 'oct', 'nov', 'déc'];
  $jourPrec = '';
  ?>
  <div class="jrn">
    <?php foreach ($ops as $o):
      $d = strtotime($o['date_operation']);
      $jour = date('Y-m-d', $d);
      $entree = $o['type'] === 'entree';
    ?>
      <?php if ($jour !== $jourPrec): $jourPrec = $jour; ?>
      <div class="jrn-jour">
        <span><?= date('j', $d) . ' ' . $moisNoms[(int)date('n', $d)] ?></span>
        <?php
          /* Solde du jour : on voit tout de suite si la journée a rapporté. */
          $sj = 0;
          foreach ($ops as $x) if (date('Y-m-d', strtotime($x['date_operation'])) === $jour)
              $sj += ($x['type'] === 'entree' ? 1 : -1) * (float)$x['montant'];
        ?>
        <b class="<?= $sj >= 0 ? 'pos' : 'neg' ?>"><?= ($sj >= 0 ? '+' : '−') . ' ' . nfc(abs($sj)) ?></b>
      </div>
      <?php endif; ?>

      <div class="jrn-l <?= $entree ? 'e' : 'd' ?>">
        <span class="jl-sens" title="<?= $entree ? 'Entrée' : 'Dépense' ?>"><?= $entree ? '↓' : '↑' ?></span>

        <div class="jl-c">
          <div class="jl-t"><?= e($o['libelle']) ?></div>
          <div class="jl-m">
            <span class="jl-cat"><?= e($o['categorie']) ?></span>
            <?php if ($o['client']): ?><span class="jl-cli"><?= e($o['client']) ?></span><?php endif; ?>
            <?php if ($o['mode_paiement']): ?><span class="jl-mode"><?= e($o['mode_paiement']) ?></span><?php endif; ?>
            <?php if (!empty($o['recu_id'])): ?>
            <a class="jl-src" href="recus.php?type=<?= $entree ? 'entree' : 'sortie' ?>&edit=<?= (int)$o['recu_id'] ?>#form">🔗 bon</a>
            <?php else: ?>
            <span class="jl-src main">✍️ saisie</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="jl-mt <?= $entree ? 'pos' : 'neg' ?>">
          <?= $entree ? '+' : '−' ?> <?= nfc($o['montant']) ?>
        </div>

        <div class="jl-a">
          <?php if (!empty($o['recu_id'])): ?>
            <a class="jl-b" href="recus.php?type=<?= $entree ? 'entree' : 'sortie' ?>&edit=<?= (int)$o['recu_id'] ?>#form"
               title="Se modifie sur le bon de caisse">✏️</a>
          <?php else: ?>
            <a class="jl-b" href="?mois=<?= e($mois) ?>&edit=<?= $o['id'] ?>#form" title="Modifier">✏️</a>
            <form method="post" style="display:inline"
                  data-confirm="Supprimer cette opération ? Elle disparaîtra définitivement de vos comptes.">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <button class="jl-b sup" name="supprimer" value="<?= $o['id'] ?>" title="Supprimer">✕</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if (!$ops): ?>
    <p style="text-align:center;padding:30px;color:var(--ink-faint);font-size:13px;margin:0">
      Aucune opération pour cette période.</p>
    <?php endif; ?>
  </div>

  <?php if ($nbPages > 1): ?>
  <?php
    /* On n'affiche pas cinquante numéros : les pages proches, plus la première
       et la dernière. C'est ce dont on a besoin pour se repérer. */
    $lien = function ($p) use ($mois, $ftype) {
        return 'comptabilite.php?mois=' . urlencode($mois)
             . ($ftype ? '&type=' . urlencode($ftype) : '') . '&p=' . (int)$p;
    };
    $pages = array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $nbPages],
             fn($p) => $p >= 1 && $p <= $nbPages));
    sort($pages);
  ?>
  <div class="pagin">
    <span class="pg-info"><?= number_format($nbOps, 0, ',', ' ') ?> opération<?= $nbOps > 1 ? 's' : '' ?>
      · page <?= $page ?> sur <?= $nbPages ?></span>
    <div class="pg-liens">
      <?php if ($page > 1): ?><a class="pg" href="<?= e($lien($page - 1)) ?>">‹</a><?php endif; ?>
      <?php $prec = 0; foreach ($pages as $p): ?>
        <?php if ($prec && $p > $prec + 1): ?><span class="pg-pts">…</span><?php endif; ?>
        <a class="pg <?= $p === $page ? 'actif' : '' ?>" href="<?= e($lien($p)) ?>"><?= $p ?></a>
        <?php $prec = $p; endforeach; ?>
      <?php if ($page < $nbPages): ?><a class="pg" href="<?= e($lien($page + 1)) ?>">›</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

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
<!-- ================= CHARGES RÉCURRENTES ================= -->
<div class="panel glass" id="charges" style="margin-top:14px">
  <h2>🔁 Charges récurrentes</h2>
  <p style="color:var(--ink-faint);font-size:13px;margin:-8px 0 14px;line-height:1.55">
    Le loyer, les salaires, l'abonnement Internet reviennent chaque mois.
    Décrivez-les une fois : l'application vous les proposera, et refusera de les
    enregistrer deux fois dans le même mois.
  </p>

  <?php if ($chargesDues): ?>
  <form method="post" class="ch-rappel">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="ch_mois" value="<?= e($mois) ?>">
    <div class="ch-titre">⏰ <?= count($chargesDues) ?> charge(s) pas encore enregistrée(s) pour <?= e($mois) ?></div>
    <?php foreach ($chargesDues as $ch): ?>
    <label class="ch-l">
      <input type="checkbox" name="charges[]" value="<?= (int)$ch['id'] ?>" checked>
      <span class="ch-n"><?= e($ch['libelle']) ?></span>
      <span class="ch-c"><?= e($ch['categorie']) ?></span>
      <span class="ch-m"><?= money($ch['montant'], $devise) ?></span>
    </label>
    <?php endforeach; ?>
    <button class="btn btn-gold btn-sm" name="charge_enregistrer" value="1" style="margin-top:10px">
      ✓ Enregistrer les charges cochées</button>
  </form>
  <?php elseif ($charges): ?>
  <div class="ch-ok">✅ Toutes vos charges du mois sont enregistrées.</div>
  <?php endif; ?>

  <?php if ($charges): ?>
  <div class="tbl-wrap" style="margin-top:12px">
    <table>
      <thead><tr><th>Charge</th><th>Catégorie</th><th>Jour</th><th class="r">Montant</th><th>Ce mois</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($charges as $ch): ?>
        <tr>
          <td><strong><?= e($ch['libelle']) ?></strong></td>
          <td><span class="badge"><?= e($ch['categorie']) ?></span></td>
          <td>le <?= (int)$ch['jour_du_mois'] ?></td>
          <td class="r" style="font-weight:700"><?= money($ch['montant'], $devise) ?></td>
          <td><?= $ch['deja'] ? '<span class="orig orig-bon">✓ Enregistrée</span>'
                              : '<span class="orig orig-main">En attente</span>' ?></td>
          <td>
            <form method="post" data-confirm="Retirer cette charge récurrente ?">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <button class="btn btn-danger btn-sm" name="charge_retirer" value="<?= (int)$ch['id'] ?>">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>


  <?php endif; ?>

  <form method="post" class="form-grid" style="margin-top:14px">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div class="field"><label>Libellé *</label>
      <input class="input" name="ch_libelle" placeholder="ex : Loyer du local" required></div>
    <div class="field"><label>Catégorie</label>
      <select class="input" name="ch_categorie">
        <?php foreach (categories_depense() as $nom => $ico): ?>
        <option value="<?= e($nom) ?>"><?= $ico ?> <?= e($nom) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Montant (<?= e($devise) ?>) *</label>
      <input class="input" type="number" name="ch_montant" min="0" step="100" required></div>
    <div class="field"><label>Jour du mois</label>
      <input class="input" type="number" name="ch_jour" min="1" max="28" value="1">
      <span style="display:block;margin-top:4px;font-size:12px;color:var(--ink-faint)">28 au maximum, pour exister tous les mois.</span>
    </div>
    <div class="field"><label>Mode de paiement</label>
      <select class="input" name="ch_mode">
        <?php foreach ($modes as $m): ?><option><?= $m ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="full"><button class="btn btn-glass" name="charge_creer" value="1">➕ Ajouter cette charge</button></div>
  </form>
</div>

<?php admin_footer(); ?>
