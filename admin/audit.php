<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/../config/audit.php';

/* ============================================================================
   AUDIT FINANCIER
   Applique à vos données les contrôles qu'un comptable effectuerait.
   Cette page ne modifie jamais rien : elle lit, compare et signale.
   ============================================================================ */

$debut  = microtime(true);
$audit  = audit_financier($pdo);
$duree  = round((microtime(true) - $debut) * 1000);

$g = $audit['gravite'];
$totalAnomalies = $g['critique'] + $g['attention'] + $g['info'];

/* Le score seul ne dit pas tout : une anomalie critique compte plus que
   trois remarques mineures. Le verdict en tient compte. */
if ($g['critique'] > 0)      { $verdict = 'critique'; $vTitre = 'Des corrections sont nécessaires';
                               $vTexte = 'Certains de vos chiffres sont faux. Traitez les points rouges en priorité.'; }
elseif ($g['attention'] > 0) { $verdict = 'attention'; $vTitre = 'Quelques points à vérifier';
                               $vTexte = 'Rien de grave, mais ces écarts méritent un coup d\'œil.'; }
elseif ($g['info'] > 0)      { $verdict = 'info'; $vTitre = 'Comptes sains';
                               $vTexte = 'Aucune erreur. Quelques rappels sans gravité.'; }
else                         { $verdict = 'parfait'; $vTitre = 'Comptes parfaitement tenus';
                               $vTexte = 'Tous les contrôles passent. Vos chiffres sont fiables.'; }

$couleurs = ['critique' => '#f87171', 'attention' => '#f0b429', 'info' => '#7dd3fc', 'parfait' => '#10b981'];
$libelles = ['critique' => 'Critique', 'attention' => 'À vérifier', 'info' => 'Information'];

admin_header('Audit financier', 'audit', $pdo, $settings);
?>

<!-- ---------- Verdict ---------- -->
<div class="panel glass audit-tete" id="audit">
  <div class="at-jauge">
    <svg viewBox="0 0 200 200" class="aj-svg">
      <defs>
        <linearGradient id="aj-grad" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" stop-color="<?= $couleurs[$verdict] ?>" stop-opacity=".95"/>
          <stop offset="100%" stop-color="<?= $couleurs[$verdict] ?>" stop-opacity=".55"/>
        </linearGradient>
        <filter id="aj-lueur"><feGaussianBlur stdDeviation="4.5" result="f"/>
          <feMerge><feMergeNode in="f"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
      </defs>
      <circle cx="100" cy="100" r="82" fill="none" stroke="rgba(255,255,255,.07)" stroke-width="13"/>
      <circle cx="100" cy="100" r="82" fill="none" stroke="url(#aj-grad)" stroke-width="13"
              stroke-linecap="round" filter="url(#aj-lueur)" id="aj-arc"
              transform="rotate(-90 100 100)"
              stroke-dasharray="<?= 2 * M_PI * 82 ?>" stroke-dashoffset="<?= 2 * M_PI * 82 ?>"
              data-score="<?= (int)$audit['score'] ?>"/>
    </svg>
    <div class="aj-centre">
      <span class="aj-val" data-val="<?= (int)$audit['score'] ?>">0</span>
      <span class="aj-pc">%</span>
      <span class="aj-sous"><?= (int)$audit['ok'] ?>/<?= (int)$audit['total'] ?> contrôles</span>
    </div>
  </div>

  <div class="at-verdict">
    <span class="av-pastille <?= $verdict ?>"><?= ['critique'=>'⚠️','attention'=>'🔍','info'=>'💡','parfait'=>'✅'][$verdict] ?></span>
    <h2><?= e($vTitre) ?></h2>
    <p><?= e($vTexte) ?></p>

    <div class="at-compteurs">
      <div class="ac <?= $g['critique'] ? 'vif' : '' ?>" data-g="critique">
        <b data-val="<?= $g['critique'] ?>">0</b><span>Critique<?= $g['critique'] > 1 ? 's' : '' ?></span></div>
      <div class="ac <?= $g['attention'] ? 'vif' : '' ?>" data-g="attention">
        <b data-val="<?= $g['attention'] ?>">0</b><span>À vérifier</span></div>
      <div class="ac <?= $g['info'] ? 'vif' : '' ?>" data-g="info">
        <b data-val="<?= $g['info'] ?>">0</b><span>Information<?= $g['info'] > 1 ? 's' : '' ?></span></div>
    </div>

    <div class="at-pied">
      <span><?= (int)$audit['total'] ?> règles vérifiées en <?= $duree ?> ms · <?= date('d/m/Y à H:i') ?></span>
      <a class="btn btn-glass btn-sm" href="audit.php">↻ Relancer</a>
    </div>
  </div>
</div>

<!-- ---------- Familles de contrôles ---------- -->
<?php foreach ($audit['familles'] as $iFam => $fam):
  $part = $fam['total'] > 0 ? round($fam['ok'] / $fam['total'] * 100) : 100; ?>
<div class="panel glass audit-fam" style="margin-top:14px">
  <div class="af-tete">
    <span class="af-i"><?= $fam['icone'] ?></span>
    <div class="af-t">
      <h2><?= e($fam['titre']) ?></h2>
      <span><?= $fam['ok'] ?> règle<?= $fam['ok'] > 1 ? 's' : '' ?> sur <?= $fam['total'] ?> respectée<?= $fam['ok'] > 1 ? 's' : '' ?><?= $fam['anomalies'] ? ' · ' . $fam['anomalies'] . ' anomalie' . ($fam['anomalies'] > 1 ? 's' : '') : '' ?></span>
    </div>
    <div class="af-jauge">
      <span class="afj-f <?= $part === 100 ? 'plein' : '' ?>" data-largeur="<?= $part ?>"
            style="animation-delay:<?= $iFam * .12 ?>s"></span>
    </div>
    <span class="af-pc <?= $part === 100 ? 'plein' : '' ?>"><?= $part ?> %</span>
  </div>

  <div class="af-regles">
    <?php foreach ($fam['regles'] as $r): ?>
    <?php if ($r['nb'] === 0): ?>
      <div class="ar ok">
        <span class="ar-e">✓</span>
        <div class="ar-t"><strong><?= e($r['nom']) ?></strong></div>
      </div>
    <?php else: ?>
      <details class="ar <?= e($r['gravite']) ?>">
        <summary>
          <span class="ar-e"><?= ['critique'=>'✕','attention'=>'!','info'=>'i'][$r['gravite']] ?></span>
          <div class="ar-t">
            <strong><?= e($r['nom']) ?></strong>
            <span class="ar-p"><?= e($r['pourquoi']) ?></span>
          </div>
          <span class="ar-n"><?= $r['nb'] ?></span>
        </summary>
        <div class="ar-liste">
          <?php foreach ($r['anomalies'] as $a): ?>
          <a class="al" href="<?= e($a['lien']) ?>">
            <span class="al-p">•</span>
            <span class="al-t"><?= e($a['texte']) ?></span>
            <span class="al-f">→</span>
          </a>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<div class="panel glass" style="margin-top:14px">
  <h2>🛡️ Ce que fait cet audit</h2>
  <p style="font-size:13px;color:var(--ink-dim);line-height:1.7;margin:0">
    Il applique à vos données les contrôles qu'un comptable effectuerait : cohérence des
    encaissements, absence de doublons, justificatifs présents, règles de tenue de comptes.
    <br><br>
    <strong style="color:var(--ink)">Il ne modifie jamais rien.</strong> Il lit, compare et
    signale — à vous de décider. Un outil qui corrigerait tout seul masquerait la cause du
    problème, et vous ne sauriez plus ce qui s'est passé.
    <br><br>
    Chaque anomalie mène directement à la page où la corriger. Relancez l'audit après
    correction : le score remonte.
  </p>
</div>

<script>
(function () {
  var anime = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function lancer() {
    /* Arc de la jauge : il se remplit depuis zéro. */
    var arc = document.getElementById('aj-arc');
    if (arc) {
      var C = parseFloat(arc.getAttribute('stroke-dasharray'));
      var cible = C - (C * (parseFloat(arc.dataset.score) || 0) / 100);
      if (anime) {
        arc.style.transition = 'stroke-dashoffset 1.6s cubic-bezier(.3,.8,.3,1)';
        requestAnimationFrame(function () { arc.style.strokeDashoffset = cible; });
      } else { arc.style.strokeDashoffset = cible; }
    }

    /* Les chiffres défilent. */
    document.querySelectorAll('#audit [data-val]').forEach(function (el) {
      var cible = parseFloat(el.dataset.val) || 0;
      if (!anime) { el.textContent = cible; return; }
      var t0 = null, duree = 1300;
      function pas(t) {
        if (!t0) t0 = t;
        var p = Math.min((t - t0) / duree, 1);
        el.textContent = Math.round(cible * (1 - Math.pow(1 - p, 3)));
        if (p < 1) requestAnimationFrame(pas);
      }
      requestAnimationFrame(pas);
    });

    /* Jauges de chaque famille. */
    document.querySelectorAll('.afj-f').forEach(function (f, n) {
      var l = f.dataset.largeur + '%';
      if (!anime) { f.style.width = l; return; }
      f.style.width = '0';
      f.style.transition = 'width 1.1s cubic-bezier(.3,.9,.3,1) ' + (.3 + n * .12) + 's';
      requestAnimationFrame(function () { f.style.width = l; });
    });

    /* Les règles apparaissent en cascade. */
    if (anime) {
      document.querySelectorAll('.ar').forEach(function (r, n) {
        r.style.opacity = 0; r.style.transform = 'translateY(10px)';
        r.style.transition = 'opacity .45s ease ' + (n * .035) + 's, transform .45s var(--ease-ios) ' + (n * .035) + 's';
        requestAnimationFrame(function () { r.style.opacity = 1; r.style.transform = 'none'; });
      });
    }
  }

  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (e, o) {
      e.forEach(function (x) { if (x.isIntersecting) { lancer(); o.disconnect(); } });
    }, { threshold: .2 }).observe(document.getElementById('audit'));
  } else { lancer(); }
})();
</script>

<?php admin_footer(); ?>
