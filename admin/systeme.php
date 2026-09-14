<?php
/* ============================================================================
   ÉTAT DU SERVEUR

   Un tableau de bord technique : processeur, mémoire, disque, base de données.
   Les valeurs sont relevées en direct et rafraîchies toutes les trois secondes
   sans recharger la page.

   Une mesure indisponible est ANNONCÉE comme telle. Sur un hébergement
   mutualisé, l'accès aux compteurs du système est parfois fermé — inventer
   une jauge dans ce cas serait trompeur.
   ============================================================================ */
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/../config/systeme.php';

/* Réservé à l'administrateur : ces mesures n'ont pas d'intérêt pour un
   employé, et elles renseignent sur l'infrastructure. */
if (!is_admin()) { header('Location: index.php'); exit; }

/* Relevé au format JSON, appelé par la page pour se mettre à jour. */
if (isset($_GET['releve'])) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $r = sys_releve($pdo);
    $r['lisible'] = [
        'memoire_utilise' => sys_octets($r['memoire']['utilise']),
        'memoire_total'   => sys_octets($r['memoire']['total']),
        'php_pic'         => sys_octets($r['memoire_php']['pic']),
        'disque_utilise'  => sys_octets($r['disque']['utilise']),
        'disque_libre'    => sys_octets($r['disque']['libre'] ?? 0),
        'disque_total'    => sys_octets($r['disque']['total']),
        'base_poids'      => sys_octets($r['base']['poids']),
    ];
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

$r       = sys_releve($pdo);
$poids   = sys_poids_application();
$settings = get_settings($pdo);

admin_header('État du serveur', 'systeme', $pdo, $settings);
?>

<div class="sys" id="sys">

  <?php /* Pluie de caractères : elle occupe le fond, derrière tout le reste.
           Très atténuée — un décor doit rester lisible sous les chiffres. */ ?>
  <canvas id="pluie" class="sys-pluie" aria-hidden="true"></canvas>
  <div class="sys-lignes" aria-hidden="true"></div>

  <!-- Bandeau de tête -->
  <div class="sys-tete">
    <div class="st-gauche">
      <span class="st-pastille"></span>
      <div>
        <h1>État du serveur</h1>
        <p>Relevé en direct · <span id="sys-heure"><?= e($r['heure']) ?></span></p>
      </div>
    </div>
    <div class="st-droite">
      <span class="st-info">PHP <?= e($r['php']) ?></span>
      <?php if ($r['base']['version']): ?>
      <span class="st-info">MariaDB <?= e(explode('-', $r['base']['version'])[0]) ?></span>
      <?php endif; ?>
      <?php if ($r['uptime']['dispo']): ?>
      <span class="st-info">En service depuis <?= e($r['uptime']['texte']) ?></span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Trois cadrans -->
  <div class="sys-cadrans">

    <?php
    /* Chaque cadran : un anneau qui se remplit, la valeur au centre, et le
       détail chiffré en dessous. Le tracé est en SVG pur — aucune
       bibliothèque à charger. */
    $cadrans = [
      ['cle' => 'cpu',    'ico' => '⚡', 'titre' => 'Processeur',
       'dispo' => $r['processeur']['dispo'], 'pct' => $r['processeur']['pct'],
       'detail' => $r['processeur']['coeurs'] . ' cœur' . ($r['processeur']['coeurs'] > 1 ? 's' : '')],
      ['cle' => 'ram',    'ico' => '🧠', 'titre' => 'Mémoire vive',
       'dispo' => $r['memoire']['dispo'], 'pct' => $r['memoire']['pct'],
       'detail' => $r['memoire']['dispo']
                 ? sys_octets($r['memoire']['utilise']) . ' sur ' . sys_octets($r['memoire']['total'])
                 : 'Mesure fermée par l\'hébergeur'],
      ['cle' => 'disque', 'ico' => '💾', 'titre' => 'Disque',
       'dispo' => $r['disque']['dispo'], 'pct' => $r['disque']['pct'],
       'detail' => $r['disque']['dispo']
                 ? sys_octets($r['disque']['libre'] ?? 0) . ' encore libres'
                 : 'Mesure indisponible'],
    ];
    foreach ($cadrans as $c):
      $etat = !$c['dispo'] ? 'muet' : ($c['pct'] >= 90 ? 'critique' : ($c['pct'] >= 70 ? 'chaud' : 'calme'));
    ?>
    <div class="cad <?= $etat ?>" data-cle="<?= $c['cle'] ?>">
      <div class="cad-halo"></div>
      <div class="cad-tete"><span class="cad-ico"><?= $c['ico'] ?></span><?= e($c['titre']) ?></div>

      <div class="cad-anneau">
        <svg viewBox="0 0 200 200">
          <circle class="an-fond" cx="100" cy="100" r="82"/>
          <circle class="an-arc" cx="100" cy="100" r="82"
                  stroke-dasharray="515.2"
                  stroke-dashoffset="<?= $c['dispo'] ? 515.2 - 515.2 * $c['pct'] / 100 : 515.2 ?>"/>
          <?php /* Graduations : elles donnent l'aspect d'un instrument de mesure. */ ?>
          <g class="an-grad">
            <?php for ($k = 0; $k < 60; $k++): $a = $k * 6 - 90; ?>
            <line x1="<?= 100 + 92 * cos(deg2rad($a)) ?>" y1="<?= 100 + 92 * sin(deg2rad($a)) ?>"
                  x2="<?= 100 + ($k % 5 === 0 ? 84 : 88) * cos(deg2rad($a)) ?>"
                  y2="<?= 100 + ($k % 5 === 0 ? 84 : 88) * sin(deg2rad($a)) ?>"
                  opacity="<?= $k % 5 === 0 ? '.5' : '.2' ?>"/>
            <?php endfor; ?>
          </g>
        </svg>
        <div class="cad-val">
          <?php if ($c['dispo']): ?>
          <strong><?= number_format($c['pct'], 0) ?></strong><i>%</i>
          <?php else: ?>
          <span class="cad-muet">—</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="cad-detail"><?= e($c['detail']) ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Courbe d'activité -->
  <div class="sys-bloc">
    <div class="sb-tete">
      <h2>Activité du processeur</h2>
      <span class="sb-note">Soixante derniers relevés · un toutes les trois secondes</span>
    </div>
    <div class="sys-courbe">
      <svg id="courbe" viewBox="0 0 900 200" preserveAspectRatio="none"></svg>
      <div class="sc-balayage"></div>
    </div>
  </div>

  <!-- Compteurs détaillés -->
  <div class="sys-grille">

    <div class="sys-bloc">
      <div class="sb-tete"><h2>Charge du système</h2></div>
      <?php if ($r['processeur']['charge_dispo']): ?>
      <div class="jauges">
        <?php
        $libelles = ['1 minute', '5 minutes', '15 minutes'];
        foreach ($r['processeur']['charge'] as $i => $ch):
          $pctCh = $r['processeur']['coeurs'] > 0
                 ? min(100, $ch / $r['processeur']['coeurs'] * 100) : 0;
        ?>
        <div class="jg">
          <div class="jg-tete"><span><?= $libelles[$i] ?></span>
            <b data-charge="<?= $i ?>"><?= number_format($ch, 2, ',', ' ') ?></b></div>
          <div class="jg-piste"><span class="jg-barre" data-charge-barre="<?= $i ?>"
                style="width:<?= round($pctCh, 1) ?>%"></span></div>
        </div>
        <?php endforeach; ?>
      </div>
      <p class="sb-aide">
        La charge indique le nombre de tâches en attente. Au-delà de
        <?= (int)$r['processeur']['coeurs'] ?>, le serveur a plus de travail
        qu'il ne peut en traiter à l'instant.
      </p>
      <?php else: ?>
      <p class="sb-muet">Cette mesure n'est pas ouverte sur cet hébergement.</p>
      <?php endif; ?>
    </div>

    <div class="sys-bloc">
      <div class="sb-tete"><h2>Votre application</h2></div>
      <div class="cpt">
        <div class="cp">
          <span>Base de données</span>
          <b id="cp-base"><?= e(sys_octets($r['base']['poids'])) ?></b>
          <em><?= (int)$r['base']['tables'] ?> tables</em>
        </div>
        <div class="cp">
          <span>Fichiers envoyés</span>
          <b><?= e(sys_octets($poids['uploads'])) ?></b>
          <em><?= number_format($poids['fichiers'], 0, ',', ' ') ?> fichiers</em>
        </div>
        <div class="cp">
          <span>Mémoire PHP</span>
          <b id="cp-php"><?= e(sys_octets($r['memoire_php']['pic'])) ?></b>
          <em><?= $r['memoire_php']['limite'] > 0
                 ? 'sur ' . e(sys_octets($r['memoire_php']['limite'])) : 'sans limite' ?></em>
        </div>
        <div class="cp">
          <span>Connexions base</span>
          <b id="cp-conn"><?= (int)$r['base']['connexions'] ?></b>
          <em><span id="cp-duree"><?= e($r['base']['duree']) ?></span> ms de réponse</em>
        </div>
      </div>
    </div>
  </div>

  <!-- Terminal : le journal des relevés, en clair -->
  <div class="sys-bloc term">
    <div class="tm-barre">
      <span class="tm-pt r"></span><span class="tm-pt j"></span><span class="tm-pt v"></span>
      <span class="tm-titre">releve@<?= e(parse_url($_SERVER['HTTP_HOST'] ?? 'serveur', PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'serveur')) ?> — surveillance</span>
      <span class="tm-etat" id="tm-etat">● ACTIF</span>
    </div>
    <div class="tm-corps" id="terminal"></div>
  </div>

  <p class="sys-pied">
    Les mesures proviennent directement du serveur. Celles que votre hébergeur
    ne rend pas accessibles sont signalées, jamais estimées en silence.
  </p>
</div>

<script>
(function () {
  var sys = document.getElementById('sys');
  if (!sys) return;

  var doux = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var CIRC = 515.2;                       // circonférence de l'anneau (2πr, r = 82)
  var histo = [];                         // historique des relevés du processeur
  var MAX = 60;

  function etatDe(p) { return p >= 90 ? 'critique' : (p >= 70 ? 'chaud' : 'calme'); }

  function majCadran(cle, dispo, pct) {
    var c = sys.querySelector('.cad[data-cle="' + cle + '"]');
    if (!c) return;
    var arc = c.querySelector('.an-arc');
    var val = c.querySelector('.cad-val strong');

    if (!dispo) { c.className = 'cad muet'; return; }
    c.className = 'cad ' + etatDe(pct);
    if (arc) arc.style.strokeDashoffset = CIRC - CIRC * pct / 100;
    if (val) val.textContent = Math.round(pct);
  }

  /* ---- Courbe d'activité ---- */
  var svg = document.getElementById('courbe');

  function tracerCourbe() {
    if (!svg || histo.length < 2) return;
    var L = 900, H = 200, marge = 12;
    var pas = L / (MAX - 1);

    var pts = histo.map(function (v, n) {
      return [n * pas, marge + (H - marge * 2) * (1 - v / 100)];
    });

    /* Courbe adoucie : des segments droits donneraient un aspect brut. */
    var d = 'M' + pts[0][0] + ',' + pts[0][1];
    for (var n = 1; n < pts.length; n++) {
      var mx = (pts[n - 1][0] + pts[n][0]) / 2;
      d += 'C' + mx + ',' + pts[n - 1][1] + ' ' + mx + ',' + pts[n][1] + ' ' + pts[n][0] + ',' + pts[n][1];
    }

    var sol = 'L' + pts[pts.length - 1][0] + ',' + H + ' L' + pts[0][0] + ',' + H + ' Z';
    var dernier = pts[pts.length - 1];

    svg.innerHTML =
      '<defs>'
      + '<linearGradient id="cg" x1="0" y1="0" x2="0" y2="1">'
      + '<stop offset="0%" stop-color="#2dd48c" stop-opacity=".42"/>'
      + '<stop offset="100%" stop-color="#2dd48c" stop-opacity="0"/></linearGradient>'
      + '<filter id="cl"><feGaussianBlur stdDeviation="3" result="f"/>'
      + '<feMerge><feMergeNode in="f"/><feMergeNode in="SourceGraphic"/></feMerge></filter>'
      + '</defs>'
      + [25, 50, 75].map(function (p) {
          var y = 12 + (200 - 24) * (1 - p / 100);
          return '<line x1="0" y1="' + y + '" x2="900" y2="' + y
               + '" stroke="rgba(45,212,140,.1)"/>';
        }).join('')
      + '<path d="' + d + sol + '" fill="url(#cg)"/>'
      + '<path d="' + d + '" fill="none" stroke="#2dd48c" stroke-width="2.2" '
      + 'stroke-linecap="round" filter="url(#cl)"/>'
      + '<circle cx="' + dernier[0] + '" cy="' + dernier[1] + '" r="4.5" fill="#7cf0b8"/>'
      + '<circle cx="' + dernier[0] + '" cy="' + dernier[1] + '" r="4.5" fill="none" '
      + 'stroke="#7cf0b8" stroke-width="1.5" class="cc-onde"/>';
  }

  /* ---- Pluie de caractères ---- */
  (function () {
    var toile = document.getElementById('pluie');
    if (!toile || !doux) return;

    var ctx = toile.getContext('2d');
    /* Katakana, chiffres et lettres : l'alphabet d'origine. */
    var signes = 'アイウエオカキクケコサシスセソタチツテトナニヌネノ0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');
    var taille = 15, colonnes = 0, gouttes = [];

    function dimensionner() {
      var b = toile.parentElement.getBoundingClientRect();
      toile.width = b.width; toile.height = b.height;
      colonnes = Math.floor(b.width / taille);
      gouttes = [];
      for (var i = 0; i < colonnes; i++) gouttes[i] = Math.random() * -60;
    }

    var dernier = 0;
    function peindre(t) {
      /* Vingt images par seconde suffisent : au-delà, on consomme de la
         batterie pour un effet que l'œil ne distingue pas. */
      if (t - dernier > 50) {
        dernier = t;

        /* Voile translucide au lieu d'un effacement : c'est lui qui laisse
           la traînée derrière chaque caractère. */
        ctx.fillStyle = 'rgba(4, 10, 20, 0.09)';
        ctx.fillRect(0, 0, toile.width, toile.height);
        ctx.font = taille + 'px monospace';

        for (var i = 0; i < gouttes.length; i++) {
          var s = signes[Math.floor(Math.random() * signes.length)];
          var y = gouttes[i] * taille;

          /* La tête de la colonne est plus claire que sa traîne. */
          ctx.fillStyle = Math.random() > 0.97 ? 'rgba(190,255,220,.55)' : 'rgba(45,212,140,.28)';
          ctx.fillText(s, i * taille, y);

          if (y > toile.height && Math.random() > 0.975) gouttes[i] = 0;
          gouttes[i]++;
        }
      }
      image = requestAnimationFrame(peindre);
    }

    var image = null;
    dimensionner();
    image = requestAnimationFrame(peindre);

    var t = null;
    window.addEventListener('resize', function () {
      clearTimeout(t); t = setTimeout(dimensionner, 200);
    });

    /* Onglet en arrière-plan : on arrête tout. */
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) { cancelAnimationFrame(image); }
      else { image = requestAnimationFrame(peindre); }
    });
  })();

  /* ---- Terminal ---- */
  var terminal = document.getElementById('terminal');
  var LIGNES_MAX = 14;

  function tracer(niveau, texte) {
    if (!terminal) return;
    var l = document.createElement('div');
    l.className = 'tm-l ' + niveau;
    l.innerHTML = '<span class="tm-h">' + new Date().toTimeString().slice(0, 8) + '</span>'
                + '<span class="tm-n">' + niveau.toUpperCase().padEnd(4, ' ') + '</span>'
                + '<span class="tm-t"></span>';
    l.querySelector('.tm-t').textContent = texte;
    terminal.appendChild(l);
    while (terminal.children.length > LIGNES_MAX) terminal.removeChild(terminal.firstChild);
    terminal.scrollTop = terminal.scrollHeight;
  }

  tracer('ok', 'Liaison établie avec le serveur');
  tracer('info', 'Relevé automatique toutes les 3 secondes');
  tracer('info', 'Seuils de surveillance : 70 % attention · 90 % critique');

  /* ---- Relevé ---- */
  function relever() {
    fetch('systeme.php?releve=1', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        majCadran('cpu', d.processeur.dispo, d.processeur.pct);
        majCadran('ram', d.memoire.dispo, d.memoire.pct);
        majCadran('disque', d.disque.dispo, d.disque.pct);

        var h = document.getElementById('sys-heure');
        if (h) h.textContent = d.heure;

        var det = sys.querySelector('.cad[data-cle="ram"] .cad-detail');
        if (det && d.memoire.dispo) {
          det.textContent = d.lisible.memoire_utilise + ' sur ' + d.lisible.memoire_total;
        }

        if (d.processeur.charge_dispo) {
          d.processeur.charge.forEach(function (ch, n) {
            var b = sys.querySelector('[data-charge="' + n + '"]');
            var barre = sys.querySelector('[data-charge-barre="' + n + '"]');
            if (b) b.textContent = ch.toFixed(2).replace('.', ',');
            if (barre && d.processeur.coeurs > 0) {
              barre.style.width = Math.min(100, ch / d.processeur.coeurs * 100) + '%';
            }
          });
        }

        var maj = {
          'cp-base': d.lisible.base_poids, 'cp-php': d.lisible.php_pic,
          'cp-conn': d.base.connexions, 'cp-duree': d.base.duree
        };
        Object.keys(maj).forEach(function (id) {
          var el = document.getElementById(id);
          if (el) el.textContent = maj[id];
        });

        /* Le terminal ne recopie pas chaque relevé — ce serait du bruit. Il
           signale ce qui mérite l'attention : franchissement de seuil,
           retour à la normale, mesure fermée. */
        surveiller('processeur', d.processeur.pct, d.processeur.dispo);
        surveiller('mémoire vive', d.memoire.pct, d.memoire.dispo);
        surveiller('disque', d.disque.pct, d.disque.dispo);

        histo.push(d.processeur.dispo ? d.processeur.pct : 0);
        while (histo.length > MAX) histo.shift();
        tracerCourbe();
      })
      .catch(function () { /* une mesure ratée n'interrompt pas les suivantes */ });
  }

  /* Mémoire des seuils déjà signalés, pour ne pas répéter la même alerte
     toutes les trois secondes. */
  var seuils = {};
  function surveiller(nom, pct, dispo) {
    if (!dispo) {
      if (seuils[nom] !== 'muet') { seuils[nom] = 'muet'; tracer('warn', nom + ' : mesure fermée par l\'hébergeur'); }
      return;
    }
    var e = etatDe(pct);
    if (seuils[nom] === e) return;
    var avant = seuils[nom];
    seuils[nom] = e;
    if (avant === undefined) return;          // premier relevé : rien à signaler

    if (e === 'critique')   tracer('err',  nom + ' à ' + Math.round(pct) + ' % — seuil critique franchi');
    else if (e === 'chaud') tracer('warn', nom + ' à ' + Math.round(pct) + ' % — charge soutenue');
    else                    tracer('ok',   nom + ' revenu à ' + Math.round(pct) + ' % — situation normale');
  }

  /* Historique de départ : une ligne plate vaut mieux qu'un graphique vide. */
  var depart = <?= (float)$r['processeur']['pct'] ?>;
  for (var k = 0; k < MAX; k++) histo.push(depart);
  tracerCourbe();

  relever();
  var boucle = setInterval(relever, 3000);

  /* On cesse de mesurer quand l'onglet passe en arrière-plan : inutile de
     solliciter le serveur pour un écran que personne ne regarde. */
  document.addEventListener('visibilitychange', function () {
    clearInterval(boucle);
    if (!document.hidden) { relever(); boucle = setInterval(relever, 3000); }
  });
})();
</script>

<?php admin_footer(); ?>
