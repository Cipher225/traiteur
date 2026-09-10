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
                         ORDER BY f.date_emission DESC LIMIT 60");
    $st->execute([$annee]);
    /* On analyse les 60 prestations les plus récentes de l'année. Calculer la
       rentabilité demande trois requêtes par facture : sur plusieurs centaines,
       la page mettrait plusieurs secondes à s'ouvrir pour un intérêt nul —
       personne n'examine deux cents lignes à la fois. */
    $analysees = 0;
    foreach ($st->fetchAll() as $f) {
        $analysees++;
        $r = rentabilite_activite($pdo, (int)$f['id']);
        if ($r['ca'] <= 0 && $r['depenses'] <= 0) continue;
        $activites[] = $f + $r;
    }
    /* Les prestations les moins rentables en premier : ce sont celles qui
       demandent votre attention. */
    usort($activites, fn($a, $b) => $a['taux'] <=> $b['taux']);

    /* Nombre total de prestations de l'année, pour situer l'échantillon. */
    $stN = $pdo->prepare("SELECT COUNT(*) FROM factures
                          WHERE type='facture' AND statut <> 'annulee' AND YEAR(date_emission) = ?");
    $stN->execute([$annee]);
    $activitesAnnee = (int)$stN->fetchColumn();

    /* On n'affiche que 24 cartes : au-delà, la page devient un mur. */
    $activitesTotal = count($activites);
    $activites = array_slice($activites, 0, 24);
    $activitesVisibles = 8;
} catch (Throwable $e) {
    $activites = []; $activitesTotal = 0; $activitesVisibles = 8; $activitesAnnee = 0; $analysees = 0;
}

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

<div class="panel glass" id="evo" style="margin-bottom:14px">
  <div class="evo-tete">
    <h2 style="margin:0">📈 Évolution mensuelle</h2>
    <div class="pg-mode" id="evo-echelle" hidden>
      <button type="button" class="pge" data-echelle="lineaire">Proportionnel</button>
      <button type="button" class="pge actif" data-echelle="compresse">Compressé</button>
    </div>
    <div class="evo-series">
      <button type="button" class="evs actif" data-serie="entrees">
        <i style="background:linear-gradient(135deg,#6ee7b7,#10b981)"></i>Encaissements</button>
      <button type="button" class="evs actif" data-serie="depenses">
        <i style="background:linear-gradient(135deg,#fca5a5,#f87171)"></i>Dépenses</button>
      <button type="button" class="evs" data-serie="solde">
        <i style="background:linear-gradient(135deg,#e9c15c,#d4a526)"></i>Solde</button>
    </div>
  </div>
  <div class="evo-zone">
    <svg id="evo-svg" viewBox="0 0 900 300" preserveAspectRatio="none"
         aria-label="Évolution des encaissements et dépenses sur l'année"></svg>
    <div id="evo-bulle" class="pg-bulle" hidden></div>
  </div>
</div>

<script>
(function () {
  /* Même moteur que le pouls du tableau de bord : des aires adoucies plutôt
     que des barres. Sur douze mois, une courbe montre la tendance ; des barres
     obligent à comparer des hauteurs une à une. */
  var svg = document.getElementById('evo-svg');
  var bulle = document.getElementById('evo-bulle');
  if (!svg) return;

  var MOIS = <?= json_encode(array_values(array_slice($nomsMois, 1)), JSON_UNESCAPED_UNICODE) ?>;
  var DATA = {
    entrees:  <?= json_encode(array_map(fn($m) => round($m['entree']), array_values($mois))) ?>,
    depenses: <?= json_encode(array_map(fn($m) => round($m['depense']), array_values($mois))) ?>,
    solde:    <?= json_encode(array_map(fn($m) => round($m['entree'] - $m['depense']), array_values($mois))) ?>
  };
  var COUL = { entrees: ['#6ee7b7', '#10b981'], depenses: ['#fca5a5', '#f87171'], solde: ['#e9c15c', '#d4a526'] };
  var NOMS = { entrees: 'Encaissements', depenses: 'Dépenses', solde: 'Solde' };

  var actives = ['entrees', 'depenses'];
  var echelle = 'lineaire';
  var anime = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* Un mois exceptionnel écrase tous les autres. Au-delà d'un rapport de 20
     entre le plus gros et le plus petit mois, on compresse l'échelle. */
  function ecartFort() {
    var vals = [];
    actives.forEach(function (s) {
      DATA[s].forEach(function (v) { if (Math.abs(v) > 0) vals.push(Math.abs(v)); });
    });
    if (vals.length < 3) return false;
    var mx = Math.max.apply(null, vals), mn = Math.min.apply(null, vals);
    return mn > 0 && mx / mn > 20;
  }
  function comprimer(v) { var s = v < 0 ? -1 : 1; return s * Math.log10(1 + Math.abs(v)); }
  var L = 900, H = 300, MG = 58, MD = 18, MH = 20, MB = 38;
  var lg = L - MG - MD, ht = H - MH - MB;

  function fmt(v) {
    var a = Math.abs(v);
    if (a >= 1e9) return (v / 1e9).toFixed(1).replace('.0', '') + ' Md';
    if (a >= 1e6) return (v / 1e6).toFixed(1).replace('.0', '') + ' M';
    if (a >= 1e3) return Math.round(v / 1e3) + ' k';
    return String(Math.round(v));
  }
  function fmtLong(v) { return Math.round(v).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }

  function bornes() {
    var vals = [];
    actives.forEach(function (s) { vals = vals.concat(DATA[s]); });
    if (!vals.length) vals = [0];
    var mx = Math.max.apply(null, vals.concat([0]));
    var mn = Math.min.apply(null, vals.concat([0]));
    if (mx === mn) mx = mn + 1;
    return { mx: mx * 1.08, mn: mn < 0 ? mn * 1.12 : 0 };
  }
  function y(v, b) {
    if (echelle === 'compresse') {
      var cv = comprimer(v), cmn = comprimer(b.mn), cmx = comprimer(b.mx);
      if (cmx === cmn) return MH + ht;
      return MH + ht - ((cv - cmn) / (cmx - cmn)) * ht;
    }
    return MH + ht - ((v - b.mn) / (b.mx - b.mn)) * ht;
  }
  function x(i) { return MG + (MOIS.length > 1 ? i * (lg / (MOIS.length - 1)) : lg / 2); }

  function chemin(vals, b) {
    var d = '';
    for (var i = 0; i < vals.length; i++) {
      var px = x(i), py = y(vals[i], b);
      if (i === 0) { d += 'M' + px + ',' + py; continue; }
      var pxp = x(i - 1), pyp = y(vals[i - 1], b), mx = (pxp + px) / 2;
      d += 'C' + mx + ',' + pyp + ' ' + mx + ',' + py + ' ' + px + ',' + py;
    }
    return d;
  }

  function dessiner() {
    var b = bornes(), el = '<defs>';
    Object.keys(COUL).forEach(function (s) {
      el += '<linearGradient id="ev-a-' + s + '" x1="0" y1="0" x2="0" y2="1">'
          + '<stop offset="0%" stop-color="' + COUL[s][0] + '" stop-opacity=".40"/>'
          + '<stop offset="100%" stop-color="' + COUL[s][1] + '" stop-opacity="0"/></linearGradient>'
          + '<linearGradient id="ev-l-' + s + '" x1="0" y1="0" x2="0" y2="1">'
          + '<stop offset="0%" stop-color="' + COUL[s][0] + '"/>'
          + '<stop offset="100%" stop-color="' + COUL[s][1] + '"/></linearGradient>';
    });
    el += '<filter id="ev-lueur"><feGaussianBlur stdDeviation="3.5" result="f"/>'
        + '<feMerge><feMergeNode in="f"/><feMergeNode in="SourceGraphic"/></feMerge></filter></defs>';

    var graduations = [];
    if (echelle === 'compresse') {
      graduations.push(0);
      var plafond = Math.max(Math.abs(b.mx), Math.abs(b.mn));
      for (var p = 2; Math.pow(10, p) <= plafond * 1.5; p++) graduations.push(Math.pow(10, p));
    } else {
      for (var k = 0; k <= 4; k++) graduations.push(b.mn + (b.mx - b.mn) * (k / 4));
    }
    graduations.forEach(function (v) {
      var py = y(v, b);
      if (py < MH - 2 || py > MH + ht + 2) return;
      el += '<line x1="' + MG + '" y1="' + py + '" x2="' + (L - MD) + '" y2="' + py
          + '" stroke="rgba(255,255,255,.07)"/>'
          + '<text x="' + (MG - 10) + '" y="' + (py + 4) + '" text-anchor="end" '
          + 'fill="rgba(255,255,255,.36)" font-size="11">' + fmt(v) + '</text>';
    });
    if (b.mn < 0) {
      el += '<line x1="' + MG + '" y1="' + y(0, b) + '" x2="' + (L - MD) + '" y2="' + y(0, b)
          + '" stroke="rgba(255,255,255,.22)" stroke-width="1.5" stroke-dasharray="4 4"/>';
    }
    MOIS.forEach(function (m, i) {
      el += '<text x="' + x(i) + '" y="' + (H - 13) + '" text-anchor="middle" '
          + 'fill="rgba(255,255,255,.42)" font-size="11.5">' + m + '</text>';
    });

    actives.forEach(function (s) {
      var d = chemin(DATA[s], b), base = y(Math.max(0, b.mn), b);
      el += '<path d="' + d + ' L' + x(MOIS.length - 1) + ',' + base + ' L' + x(0) + ',' + base
          + ' Z" fill="url(#ev-a-' + s + ')" class="ev-aire"/>'
          + '<path d="' + d + '" fill="none" stroke="url(#ev-l-' + s + ')" stroke-width="2.6" '
          + 'stroke-linecap="round" filter="url(#ev-lueur)" class="ev-ligne"/>';
      DATA[s].forEach(function (v, i) {
        el += '<circle class="ev-pt" cx="' + x(i) + '" cy="' + y(v, b) + '" r="3.4" fill="'
            + COUL[s][1] + '" stroke="#0a1020" stroke-width="1.6"/>';
      });
    });

    MOIS.forEach(function (m, i) {
      var larg = lg / MOIS.length;
      el += '<rect class="ev-hit" data-i="' + i + '" x="' + (x(i) - larg / 2) + '" y="' + MH
          + '" width="' + larg + '" height="' + ht + '" fill="transparent"/>';
    });
    el += '<line id="ev-guide" x1="0" y1="' + MH + '" x2="0" y2="' + (MH + ht)
        + '" stroke="rgba(240,193,75,.5)" stroke-dasharray="3 3" opacity="0"/>';

    svg.innerHTML = el;
    survol();

    if (anime) {
      svg.querySelectorAll('.ev-ligne').forEach(function (p, n) {
        var l = p.getTotalLength();
        p.style.strokeDasharray = l; p.style.strokeDashoffset = l;
        p.style.transition = 'stroke-dashoffset 1.4s cubic-bezier(.4,0,.2,1) ' + (n * .16) + 's';
        requestAnimationFrame(function () { p.style.strokeDashoffset = 0; });
      });
      svg.querySelectorAll('.ev-aire').forEach(function (a, n) {
        a.style.opacity = 0; a.style.transition = 'opacity .9s ease ' + (.3 + n * .16) + 's';
        requestAnimationFrame(function () { a.style.opacity = 1; });
      });
      svg.querySelectorAll('.ev-pt').forEach(function (c, n) {
        c.style.opacity = 0; c.style.transition = 'opacity .4s ease ' + (.65 + n * .02) + 's';
        requestAnimationFrame(function () { c.style.opacity = 1; });
      });
    }
  }

  function survol() {
    var guide = svg.querySelector('#ev-guide');
    svg.querySelectorAll('.ev-hit').forEach(function (z) {
      z.addEventListener('mouseenter', function () {
        var i = +this.dataset.i;
        if (guide) { guide.setAttribute('x1', x(i)); guide.setAttribute('x2', x(i)); guide.setAttribute('opacity', 1); }
        var h = '<div class="pb-mois">' + MOIS[i] + '</div>';
        actives.forEach(function (s) {
          h += '<div class="pb-l"><i style="background:' + COUL[s][1] + '"></i><span>'
             + NOMS[s] + '</span><b>' + fmtLong(DATA[s][i]) + '</b></div>';
        });
        bulle.innerHTML = h; bulle.hidden = false;
        var r = svg.getBoundingClientRect();
        bulle.style.left = Math.min(Math.max((x(i) / L) * r.width, 90), r.width - 90) + 'px';
      });
    });
    svg.addEventListener('mouseleave', function () {
      bulle.hidden = true;
      if (guide) guide.setAttribute('opacity', 0);
    });
  }

  document.querySelectorAll('#evo .evs').forEach(function (b2) {
    b2.addEventListener('click', function () {
      var s = this.dataset.serie, i = actives.indexOf(s);
      if (i >= 0) { if (actives.length === 1) return; actives.splice(i, 1); this.classList.remove('actif'); }
      else { actives.push(s); this.classList.add('actif'); }
      majEchelle();
      dessiner();
    });
  });

  document.querySelectorAll('#evo .pge').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('#evo .pge').forEach(function (b2) { b2.classList.remove('actif'); });
      this.classList.add('actif');
      echelle = this.dataset.echelle;
      dessiner();
    });
  });
  function majEchelle() {
    var zone = document.getElementById('evo-echelle');
    var fort = ecartFort();
    if (zone) zone.hidden = !fort;
    echelle = fort ? 'compresse' : 'lineaire';
    document.querySelectorAll('#evo .pge').forEach(function (b2) {
      b2.classList.toggle('actif', b2.dataset.echelle === echelle);
    });
  }

  var vu = false;
  function lancer() { if (vu) return; vu = true; majEchelle(); dessiner(); }
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (e, o) {
      e.forEach(function (x2) { if (x2.isIntersecting) { lancer(); o.disconnect(); } });
    }, { threshold: .15 }).observe(document.getElementById('evo'));
  } else { lancer(); }

  var t;
  window.addEventListener('resize', function () {
    clearTimeout(t); t = setTimeout(function () { if (vu) { anime = false; dessiner(); } }, 200);
  });
})();
</script>

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

  <?php $affichees = count($activites); ?>
  <?php if ($affichees > $activitesVisibles): ?>
  <button type="button" class="btn btn-glass btn-sm" id="voir-activites" style="margin-top:12px">
    ▾ Voir les <?= $affichees - $activitesVisibles ?> autres activités</button>
  <?php endif; ?>
  <?php if ($activitesAnnee > $analysees): ?>
  <p style="margin:10px 0 0;font-size:11.5px;color:var(--ink-faint)">
    Analyse portant sur les <?= (int)$analysees ?> prestations les plus récentes,
    sur <?= (int)$activitesAnnee ?> en <?= (int)$annee ?>.
  </p>
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
