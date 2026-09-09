<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/documents.php';
require_once __DIR__ . '/../config/wave.php';

/* ============================================================================
   TABLEAU DE BORD FINANCIER
   ----------------------------------------------------------------------------
   Les graphiques sont dessinés en SVG côté serveur : ils s'affichent sans
   bibliothèque extérieure, donc instantanément et même sans connexion.
   ============================================================================ */

if (!is_admin()) {
    flash("Cette page est réservée à l'administrateur.", 'error');
    header('Location: index.php'); exit;
}

$devise = $settings['devise'] ?? 'FCFA';
$annee  = (int)($_GET['annee'] ?? date('Y'));

/* Format court des montants, sans devise : les colonnes restent alignées. */
if (!function_exists('nf')) {
    function nf($n) { return number_format((float)$n, 0, ',', ' '); }
}

$anneesDispo = $pdo->query("SELECT DISTINCT YEAR(date_operation) a FROM transactions ORDER BY a DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!$anneesDispo) $anneesDispo = [(int)date('Y')];

/* ---------- Entrées et dépenses mois par mois ---------- */
$mois = array_fill(1, 12, ['entree' => 0.0, 'depense' => 0.0]);
$st = $pdo->prepare("SELECT MONTH(date_operation) m, type, SUM(montant) s
                     FROM transactions WHERE YEAR(date_operation)=? GROUP BY m, type");
$st->execute([$annee]);
foreach ($st->fetchAll() as $r) $mois[(int)$r['m']][$r['type']] = (float)$r['s'];

$totalEntrees = array_sum(array_column($mois, 'entree'));
$totalDepenses = array_sum(array_column($mois, 'depense'));
$resultat = $totalEntrees - $totalDepenses;
$maxMois = max(1, max(array_map(fn($m) => max($m['entree'], $m['depense']), $mois)));

/* ---------- Meilleurs clients ---------- */
$topClients = [];
foreach ($pdo->query("SELECT id, COALESCE(NULLIF(entreprise,''), nom) AS nom FROM clients")->fetchAll() as $c) {
    $st = $pdo->prepare("SELECT id FROM factures WHERE client_id=? AND type='facture'
                         AND statut<>'annulee' AND YEAR(date_emission)=?");
    $st->execute([(int)$c['id'], $annee]);
    $tot = 0.0; $nb = 0;
    foreach ($st->fetchAll() as $f) {
        $doc = get_facture($pdo, (int)$f['id']);
        if ($doc) { $tot += (float)$doc['montant_ttc']; $nb++; }
    }
    if ($tot > 0) $topClients[] = ['nom' => $c['nom'], 'total' => $tot, 'nb' => $nb];
}
usort($topClients, fn($a, $b) => $b['total'] <=> $a['total']);
$topClients = array_slice($topClients, 0, 8);
$maxClient = $topClients ? $topClients[0]['total'] : 1;

/* ---------- Impayés ---------- */
$impayes = 0.0; $nbImpayes = 0; $enRetard = 0.0;
$st = $pdo->query("SELECT id, date_echeance FROM factures
                   WHERE type='facture' AND statut NOT IN ('payee','annulee','brouillon')");
foreach ($st->fetchAll() as $f) {
    $doc = get_facture($pdo, (int)$f['id']);
    if (!$doc) continue;
    $solde = (float)$doc['montant_ttc'] - paiements_deja_regles($pdo, (int)$f['id']);
    if ($solde <= 1) continue;
    $impayes += $solde; $nbImpayes++;
    if (!empty($f['date_echeance']) && strtotime($f['date_echeance']) < time()) $enRetard += $solde;
}

/* ---------- Répartition par catégorie ---------- */
/* ----------------------------------------------------------------------------
   Rentabilité par activité : ce que chaque prestation a rapporté, ce qu'elle a
   coûté, et ce qu'il en reste. C'est la vraie mesure de la performance : un
   gros chiffre d'affaires avec de grosses dépenses peut rapporter moins qu'une
   petite prestation bien maîtrisée.
   ---------------------------------------------------------------------------- */
$activites = [];
try {
    $st = $pdo->prepare("SELECT f.id, f.numero, f.activite, f.date_emission, f.statut,
                                COALESCE(NULLIF(c.entreprise,''), c.nom) AS client
                         FROM factures f LEFT JOIN clients c ON c.id = f.client_id
                         WHERE f.type='facture' AND f.statut <> 'annulee'
                           AND YEAR(f.date_emission) = ?
                         ORDER BY f.date_emission DESC");
    $st->execute([$annee]);
    foreach ($st->fetchAll() as $f) {
        $r = rentabilite_activite($pdo, (int)$f['id']);
        if ($r['ca'] <= 0 && $r['depenses'] <= 0) continue;
        $activites[] = $f + $r;
    }
    /* Les prestations les moins rentables en premier : ce sont celles qui
       demandent votre attention. */
    usort($activites, fn($a, $b) => $a['taux'] <=> $b['taux']);
    /* Sur une année chargée, la liste devient interminable. On montre les plus
       urgentes — les moins rentables — et le reste se déplie à la demande. */
    $activitesTotal = count($activites);
    $activitesVisibles = 8;
} catch (Throwable $e) { $activites = []; $activitesTotal = 0; $activitesVisibles = 8; }

$totalCA   = array_sum(array_column($activites, 'ca'));
$totalDep  = array_sum(array_column($activites, 'depenses'));
$margeGlob = $totalCA - $totalDep;

/* Charges générales : les dépenses non rattachées à une prestation */
$chargesGenerales = 0.0;
try {
    $st = $pdo->prepare("SELECT COALESCE(SUM(montant),0) FROM transactions
                         WHERE type='depense' AND facture_id IS NULL AND YEAR(date_operation)=?");
    $st->execute([$annee]);
    $chargesGenerales = (float)$st->fetchColumn();
} catch (Throwable $e) {}

$cats = $pdo->prepare("SELECT categorie, SUM(montant) s FROM transactions
                       WHERE type='entree' AND YEAR(date_operation)=?
                       GROUP BY categorie ORDER BY s DESC LIMIT 6");
$cats->execute([$annee]);
$categories = $cats->fetchAll();
$maxCat = $categories ? max(array_map(fn($c) => (float)$c['s'], $categories)) : 1;

$nomsMois = ['', 'Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
$fmt = fn($v) => number_format((float)$v, 0, ',', ' ');

admin_header('Tableau de bord financier', 'finances', $pdo, $settings);
?>

<div class="panel glass" style="margin-bottom:14px">
  <h2>📊 Année <?= $annee ?>
    <form method="get" style="margin-left:auto">
      <select class="input" name="annee" onchange="this.form.submit()" style="max-width:120px">
        <?php foreach ($anneesDispo as $a): ?>
        <option value="<?= (int)$a ?>" <?= (int)$a === $annee ? 'selected' : '' ?>><?= (int)$a ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </h2>
  <div class="stats-row" style="margin-top:4px">
    <div class="stat-card"><div class="stat-val" style="color:#10b981"><?= $fmt($totalEntrees) ?></div><div class="stat-lbl">Encaissements (<?= e($devise) ?>)</div></div>
    <div class="stat-card"><div class="stat-val" style="color:#f87171"><?= $fmt($totalDepenses) ?></div><div class="stat-lbl">Dépenses</div></div>
    <div class="stat-card"><div class="stat-val" style="color:<?= $resultat >= 0 ? '#d4a526' : '#f87171' ?>"><?= $fmt($resultat) ?></div><div class="stat-lbl">Résultat</div></div>
    <div class="stat-card"><div class="stat-val" style="color:#f0b429"><?= $fmt($impayes) ?></div><div class="stat-lbl"><?= $nbImpayes ?> impayé<?= $nbImpayes > 1 ? 's' : '' ?></div></div>
  </div>
</div>

<div class="panel glass" style="margin-bottom:14px">
  <h2>📈 Évolution mensuelle</h2>
  <div class="fin-legende">
    <span><i style="background:#10b981"></i> Encaissements</span>
    <span><i style="background:#f87171"></i> Dépenses</span>
  </div>
  <div class="fin-graphe">
    <?php for ($m = 1; $m <= 12; $m++):
      $e = $mois[$m]['entree']; $d = $mois[$m]['depense'];
      $he = $maxMois > 0 ? max(2, round($e / $maxMois * 100)) : 2;
      $hd = $maxMois > 0 ? max(2, round($d / $maxMois * 100)) : 2; ?>
    <div class="fin-col" title="<?= $nomsMois[$m] ?> — encaissé <?= $fmt($e) ?>, dépensé <?= $fmt($d) ?>">
      <div class="fin-barres">
        <div class="fin-b fin-e" style="height:<?= $he ?>%"><span><?= $e > 0 ? $fmt($e) : '' ?></span></div>
        <div class="fin-b fin-d" style="height:<?= $hd ?>%"></div>
      </div>
      <div class="fin-mois"><?= $nomsMois[$m] ?></div>
    </div>
    <?php endfor; ?>
  </div>
</div>

<div class="fin-duo">
  <div class="panel glass">
    <h2>🏆 Meilleurs clients</h2>
    <?php if (!$topClients): ?>
      <p style="color:var(--ink-faint)">Aucune facture sur cette année.</p>
    <?php else: foreach ($topClients as $i => $c): ?>
    <div class="fin-ligne">
      <div class="fin-rang"><?= $i + 1 ?></div>
      <div class="fin-detail">
        <div class="fin-nom"><?= e($c['nom']) ?> <span><?= $c['nb'] ?> facture<?= $c['nb'] > 1 ? 's' : '' ?></span></div>
        <div class="fin-jauge"><div style="width:<?= max(3, round($c['total'] / $maxClient * 100)) ?>%"></div></div>
      </div>
      <div class="fin-val"><?= $fmt($c['total']) ?></div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="panel glass">
    <h2>🗂️ Encaissements par catégorie</h2>
    <?php if (!$categories): ?>
      <p style="color:var(--ink-faint)">Aucune écriture sur cette année.</p>
    <?php else: foreach ($categories as $c): ?>
    <div class="fin-ligne">
      <div class="fin-detail">
        <div class="fin-nom"><?= e($c['categorie']) ?></div>
        <div class="fin-jauge"><div style="width:<?= max(3, round((float)$c['s'] / $maxCat * 100)) ?>%;background:linear-gradient(90deg,#d4a526,#e9c15c)"></div></div>
      </div>
      <div class="fin-val"><?= $fmt($c['s']) ?></div>
    </div>
    <?php endforeach; endif; ?>

    <?php if ($enRetard > 0): ?>
    <div style="margin-top:16px;padding:11px 14px;border-radius:12px;font-size:13px;
                color:#f87171;background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3)">
      ⚠️ <strong><?= $fmt($enRetard) ?> <?= e($devise) ?></strong> de factures échues non réglées.
      <a href="relances.php" style="color:var(--gold)">Relancer les clients</a>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="panel glass" style="margin-top:14px">
  <h2>📥 Export comptable</h2>
  <p style="color:var(--ink-faint);font-size:13.5px;margin:-8px 0 12px">
    Fichiers CSV directement lisibles par Excel et par les logiciels comptables.
    Chaque export se termine par une ligne de totaux.</p>
  <form method="get" action="export.php" target="_blank" class="form-grid">
    <div class="field"><label>Du</label><input class="input" type="date" name="du" value="<?= $annee ?>-01-01"></div>
    <div class="field"><label>Au</label><input class="input" type="date" name="au" value="<?= $annee ?>-12-31"></div>
    <div class="field"><label>Contenu</label>
      <select class="input" name="t">
        <option value="transactions">Écritures comptables</option>
        <option value="factures">Factures et proformas</option>
        <option value="paiements">Paiements en ligne</option>
        <option value="clients">Clients et encours</option>
      </select>
    </div>
    <div class="field"><label>Format</label>
      <select class="input" name="f">
        <option value="tableau">Tableau mis en forme (Excel)</option>
        <option value="csv">Fichier CSV (logiciel comptable)</option>
      </select>
      <span style="display:block;margin-top:4px;font-size:12px;color:var(--ink-faint)">
        Le tableau porte l'en-tête de l'entreprise et les totaux. Le CSV se relit par n'importe quel logiciel.
      </span>
    </div>
    <div class="full"><button class="btn btn-gold">📥 Télécharger</button></div>
  </form>
</div>

<!-- ================= RENTABILITÉ PAR ACTIVITÉ ================= -->
<div class="panel glass" style="margin-top:14px">
  <h2>🎯 Rentabilité par activité</h2>
  <p style="color:var(--ink-faint);font-size:13px;margin:-8px 0 14px;line-height:1.55">
    Ce que chaque prestation a rapporté, ce qu'elle a coûté, et ce qu'il en reste.
    Les moins rentables apparaissent en premier.
  </p>

  <?php if ($activites): ?>
  <div class="rent-resume">
    <div class="rr-b"><span class="rr-v"><?= money($totalCA, $devise) ?></span><span class="rr-l">Chiffre d'affaires</span></div>
    <div class="rr-b"><span class="rr-v" style="color:#f87171"><?= money($totalDep, $devise) ?></span><span class="rr-l">Dépenses rattachées</span></div>
    <div class="rr-b"><span class="rr-v" style="color:<?= $margeGlob >= 0 ? '#10b981' : '#f87171' ?>"><?= money($margeGlob, $devise) ?></span>
      <span class="rr-l">Marge sur prestations</span></div>
    <div class="rr-b"><span class="rr-v" style="color:#f0b429"><?= money($chargesGenerales, $devise) ?></span>
      <span class="rr-l">Charges générales</span></div>
  </div>

  <?php
  /* Une carte par prestation plutôt qu'un tableau de sept colonnes. Sur un
     téléphone, sept colonnes de chiffres deviennent illisibles ; une carte
     garde son sens à toutes les largeurs, et la barre de marge se lit d'un
     coup d'œil sans chercher dans une colonne. */
  ?>
  <div class="rent-liste">
    <?php foreach ($activites as $iAct => $a):
      $t = (float)$a['taux'];
      $classe = $t >= 40 ? 'bon' : ($t >= 15 ? 'moyen' : 'faible');
      $reste  = $a['ca'] - $a['encaisse'];
      /* Part des dépenses dans le chiffre d'affaires : c'est elle qui explique
         la marge, et la barre la rend immédiatement lisible. */
      $partDep = $a['ca'] > 0 ? min(100, $a['depenses'] / $a['ca'] * 100) : 0;
    ?>
    <div class="rc <?= $classe ?> <?= $iAct >= $activitesVisibles ? 'act-cachee' : '' ?>"
         <?= $iAct >= $activitesVisibles ? 'hidden' : '' ?>>

      <div class="rc-tete">
        <div class="rc-id">
          <strong><?= e($a['activite'] ?: $a['numero']) ?></strong>
          <span><?= e($a['client'] ?: 'Client de passage') ?>
            · <?= e($a['numero']) ?><?= !empty($a['date_emission']) ? ' · ' . date('d/m/Y', strtotime($a['date_emission'])) : '' ?></span>
        </div>
        <div class="rc-taux <?= $classe ?>"><?= number_format($t, 0) ?><i>%</i></div>
      </div>

      <div class="rc-barre" title="Part des dépenses dans le chiffre d'affaires">
        <span class="rb-dep" style="width:<?= round($partDep, 1) ?>%"></span>
      </div>

      <div class="rc-chiffres">
        <div class="rch"><span>Facturé</span><b><?= nf($a['ca']) ?></b></div>
        <div class="rch"><span>Encaissé</span>
          <b class="<?= $a['encaisse'] >= $a['ca'] - 1 ? 'ok' : 'attente' ?>"><?= nf($a['encaisse']) ?></b>
          <?php if ($reste > 1): ?><em>reste <?= nf($reste) ?></em><?php endif; ?>
        </div>
        <div class="rch"><span>Dépenses</span><b class="dep"><?= nf($a['depenses']) ?></b></div>
        <div class="rch marge"><span>Marge</span>
          <b class="<?= $a['marge'] >= 0 ? 'ok' : 'dep' ?>"><?= nf($a['marge']) ?></b></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($activitesTotal > $activitesVisibles): ?>
  <button type="button" class="btn btn-glass btn-sm" id="voir-activites" style="margin-top:12px">
    ▾ Voir les <?= $activitesTotal - $activitesVisibles ?> autres activités</button>
  <?php endif; ?>

  <p style="margin:12px 0 0;font-size:12px;color:var(--ink-faint);line-height:1.6">
    <strong style="color:var(--ink)">Comment lire ce tableau.</strong>
    « Facturé » est le montant de la facture ; « Encaissé » ce que le client a réellement réglé.
    Une marge élevée mais un encaissement faible signale une facture à relancer.
    Les charges générales — loyer, salaires, électricité — ne sont pas réparties par prestation :
    elles se déduisent de la marge globale.
  </p>

  <?php else: ?>
  <p style="color:var(--ink-faint);font-size:13px;margin:0">
    Aucune activité chiffrée pour <?= (int)$annee ?>. Rattachez vos dépenses à une prestation
    depuis <a href="recus.php?type=sortie" style="color:var(--gold)">Sorties</a> pour voir apparaître
    leur rentabilité ici.
  </p>
  <?php endif; ?>
</div>

<script>
(function () {
  /* Le reste des activités apparaît à la demande, en cascade. */
  var b = document.getElementById('voir-activites');
  if (!b) return;
  b.addEventListener('click', function () {
    var lignes = document.querySelectorAll('.act-cachee');
    lignes.forEach(function (l, i) {
      l.hidden = false;
      l.style.opacity = 0;
      l.style.transition = 'opacity .4s ease ' + (i * .04) + 's';
      requestAnimationFrame(function () { l.style.opacity = 1; });
    });
    this.remove();
  });
})();
</script>

<?php admin_footer(); ?>
