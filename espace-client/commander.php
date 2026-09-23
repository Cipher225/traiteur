<?php
require __DIR__ . '/inc.php';
$cid = (int)$CLIENT['id'];

/* ----------------------------------------------------------------------------
   Enregistrement de la demande
   ----------------------------------------------------------------------------
   Le client choisit des FORMULES (nos catégories de menu) et, à l'intérieur de
   chacune, garde ou écarte les éléments qu'elle contient. Une formule dont il
   n'a gardé aucun élément n'est tout simplement pas commandée.

   Rien de ce qui arrive du navigateur n'est repris tel quel : les noms des
   plats et leur appartenance à une formule sont relus dans la base. Un
   identifiant qui ne correspond à rien, ou qui appartient à une autre formule,
   est ignoré sans bruit.
---------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $packs   = $_POST['pack'] ?? [];
    $invites = max(0, (int)($_POST['nb_invites'] ?? 0));

    /* Référentiel de confiance : ce que la base dit, et rien d'autre. */
    $refCat  = [];
    foreach ($pdo->query('SELECT id, nom FROM categories') as $c) $refCat[(int)$c['id']] = $c['nom'];
    $refPlat = [];
    foreach ($pdo->query('SELECT id, nom, categorie_id FROM plats ORDER BY ordre, id') as $p) {
        $refPlat[(int)$p['id']] = ['nom' => $p['nom'], 'cat' => (int)$p['categorie_id']];
    }

    $lignes = [];
    foreach ($packs as $catId => $pack) {
        $catId = (int)$catId;
        if (!isset($refCat[$catId])) continue;

        /* Le nombre de personnes de la formule ; à défaut, celui de
           l'événement ; à défaut, une personne. */
        $pers = (int)($pack['pers'] ?? 0);
        if ($pers < 1) $pers = $invites > 0 ? $invites : 1;
        $pers = min($pers, 100000);

        $choisis = array_unique(array_map('intval', (array)($pack['plats'] ?? [])));
        foreach ($choisis as $pid) {
            if (!isset($refPlat[$pid]) || $refPlat[$pid]['cat'] !== $catId) continue;
            $lignes[] = ['plat_id' => $pid, 'nom' => $refPlat[$pid]['nom'], 'qte' => $pers];
        }
    }

    if (!$lignes) {
        flash("Votre sélection est vide : choisissez au moins un élément dans une formule.", 'error');
        header('Location: commander.php'); exit;
    }

    $numero = next_numero($pdo, 'commandes_client', $settings['prefixe_commande'] ?? 'CMD');
    $pdo->prepare("INSERT INTO commandes_client (numero, client_id, date_evenement, nb_invites, lieu, notes, statut) VALUES (?,?,?,?,?,?, 'nouvelle')")
        ->execute([
            $numero, $cid,
            ($_POST['date_evenement'] ?? '') ?: null,
            $invites,
            mb_substr(trim($_POST['lieu'] ?? ''), 0, 200),
            mb_substr(trim($_POST['notes'] ?? ''), 0, 1000),
        ]);
    $oid = (int)$pdo->lastInsertId();

    $ins = $pdo->prepare("INSERT INTO commandes_client_lignes (commande_id, plat_id, designation, quantite) VALUES (?,?,?,?)");
    foreach ($lignes as $l) $ins->execute([$oid, $l['plat_id'], mb_substr($l['nom'], 0, 200), $l['qte']]);

    flash('🎉 Votre demande ' . $numero . ' est partie ! Nous préparons votre proforma chiffrée : vous la retrouverez ici même, dans « Mes commandes ».');
    header('Location: mes-commandes.php'); exit;
}

/* ----------------------------------------------------------------------------
   Le menu proposé au client
   ----------------------------------------------------------------------------
   Aucun filtre sur « actif ». « Visible sur le site » ne décide que de ce que
   voient les visiteurs anonymes de la vitrine. Un client connecté, lui, est
   déjà en relation avec la maison : il doit pouvoir composer sa demande sur
   l'ensemble du catalogue, y compris les formules que nous ne mettons pas en
   avant publiquement.
---------------------------------------------------------------------------- */
$cats = $pdo->query('SELECT * FROM categories ORDER BY ordre, id')->fetchAll();
$platsParCat = [];
foreach ($pdo->query('SELECT * FROM plats ORDER BY categorie_id, ordre, id') as $p) {
    $platsParCat[$p['categorie_id']][] = $p;
}
/* Une formule sans contenu n'est pas une formule : on ne la propose pas. */
$cats = array_values(array_filter($cats, fn($c) => !empty($platsParCat[$c['id']])));

client_header('Commander', 'commander', $settings, $CLIENT);
?>
<div class="panel glass tone-blue cmd-intro">
  <h2>🛒 Composez votre prestation</h2>
  <ol class="cmd-etapes">
    <li><b>Indiquez le nombre de personnes</b> — chaque formule s'ajustera automatiquement.</li>
    <li><b>Choisissez vos formules</b> et décochez ce que vous ne souhaitez pas : vous ne payez que ce que vous gardez.</li>
    <li><b>Envoyez votre demande</b> — nous vous répondons par une <strong>proforma chiffrée</strong>, que vous retrouverez dans « Mes commandes ».</li>
  </ol>
  <div class="cmd-pers">
    <label for="nbInvites">👥 Pour combien de personnes ?</label>
    <input class="input" type="number" id="nbInvites" min="1" max="100000" placeholder="ex : 150" inputmode="numeric">
    <span class="cmd-pers-aide">Vous pourrez ajuster ce nombre formule par formule.</span>
  </div>
</div>

<div class="order-layout">
  <?php /* La liste des formules défile dans sa propre fenêtre : dépliées, dix
           formules occupaient plusieurs écrans et le récapitulatif de droite
           se retrouvait hors de vue au moment précis où il sert. */ ?>
  <div class="order-menu defilant">
    <?php if (!$cats): ?>
    <div class="panel glass" style="text-align:center;color:var(--ink-faint);padding:28px">
      Nos formules ne sont pas encore en ligne. Revenez très bientôt !
    </div>
    <?php endif; ?>

    <?php foreach ($cats as $c): $items = $platsParCat[$c['id']]; $n = count($items); ?>
    <section class="pk" id="pk<?= (int)$c['id'] ?>" data-id="<?= (int)$c['id'] ?>"
             data-nom="<?= e($c['nom']) ?>" data-total="<?= $n ?>">
      <header class="pk-tete">
        <span class="pk-ico"><?= e($c['icone'] ?: '🍽️') ?></span>
        <div class="pk-id">
          <h3><?= e($c['nom']) ?></h3>
          <p class="pk-sous">
            <?php if (trim((string)($c['description'] ?? '')) !== ''): ?><?= e($c['description']) ?> — <?php endif; ?>
            <?= $n ?> élément<?= $n > 1 ? 's' : '' ?> au choix
          </p>
        </div>
        <button type="button" class="btn btn-gold btn-sm pk-prendre">＋ Choisir cette formule</button>
        <button type="button" class="btn btn-glass btn-sm pk-retirer">Retirer</button>
      </header>

      <div class="pk-corps">
        <div class="pk-reglage">
          <label>👥 Personnes pour cette formule</label>
          <div class="pk-qte">
            <button type="button" class="qbtn pk-moins" aria-label="Diminuer">−</button>
            <input class="pk-pers" type="number" min="1" max="100000" value="1" inputmode="numeric" aria-label="Nombre de personnes">
            <button type="button" class="qbtn pk-plus" aria-label="Augmenter">+</button>
          </div>
        </div>

        <div class="pk-barre">
          <span class="pk-titre-liste">Ce que comprend la formule</span>
          <span class="pk-actions">
            <button type="button" class="lien-mini pk-tout">Tout garder</button>
            <button type="button" class="lien-mini pk-rien">Tout décocher</button>
          </span>
        </div>

        <ul class="pk-liste">
          <?php foreach ($items as $p): ?>
          <li>
            <label class="pk-el">
              <input type="checkbox" class="pk-case" value="<?= (int)$p['id'] ?>" data-nom="<?= e($p['nom']) ?>" checked>
              <span class="pk-coche" aria-hidden="true"></span>
              <span class="pk-txt">
                <strong><?= e($p['nom']) ?></strong>
                <?php if (trim((string)($p['description'] ?? '')) !== ''): ?>
                <span class="pk-desc"><?= e($p['description']) ?></span>
                <?php endif; ?>
              </span>
            </label>
          </li>
          <?php endforeach; ?>
        </ul>

        <p class="pk-bilan" aria-live="polite"></p>
      </div>
    </section>
    <?php endforeach; ?>
  </div>

  <aside class="order-cart">
    <div class="panel glass cart-sticky">
      <h2>🧺 Ma demande <span class="badge badge-gold" id="cartCount">0</span></h2>

      <div id="cartItems" class="recap">
        <p class="recap-vide">Rien de choisi pour l'instant. Prenez une formule à gauche : tout son contenu est coché, vous n'avez qu'à retirer ce dont vous ne voulez pas.</p>
      </div>

      <form method="post" id="orderForm">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <div id="hiddenItems"></div>

        <h3 class="form-section">📅 Votre événement</h3>
        <div class="field"><label>Date</label><input class="input" type="date" name="date_evenement"></div>
        <div class="field"><label>Nombre total d'invités</label>
          <input class="input" type="number" name="nb_invites" id="nbInvitesForm" min="0" placeholder="ex : 150" inputmode="numeric"></div>
        <div class="field"><label>Lieu</label><input class="input" name="lieu" placeholder="ex : Cocody, Abidjan"></div>
        <div class="field"><label>Précisions</label>
          <textarea class="input" name="notes" style="min-height:64px" placeholder="Allergies, horaires, disposition des tables…"></textarea></div>

        <button class="btn btn-gold" type="submit" id="orderBtn" style="width:100%;margin-top:12px" disabled>
          Envoyer ma demande</button>
        <p class="cart-note">Aucun prix à ce stade : notre équipe chiffre votre sélection et vous renvoie une proforma détaillée.</p>
      </form>
    </div>
  </aside>
</div>

<?php /* Sur téléphone, le récapitulatif se retrouve tout en bas, après les
         formules : le client compose sans voir ce qu'il a déjà pris. Cette
         barre lui dit en permanence où il en est et l'y emmène. */ ?>
<div class="cmd-barre" id="cmdBarre" hidden>
  <span class="cb-txt"><b id="cbN">0</b> formule<span id="cbS"></span> choisie<span id="cbS2"></span></span>
  <button type="button" class="btn btn-gold btn-sm" id="cbVoir">Voir ma demande ↓</button>
</div>

<script>
/* ---------------------------------------------------------------------------
   Composition de la demande
   ---------------------------------------------------------------------------
   Une formule est « prise » ou non. Prise, tout son contenu est coché : le
   client retire, il n'ajoute pas — c'est la formule complète qui est la
   proposition, et ce qu'il écarte reste affiché, barré, pour qu'il voie
   exactement ce qu'il a laissé de côté.

   Une formule dont plus rien n'est coché n'est pas commandée : elle le dit
   elle-même plutôt que de disparaître sans explication.
--------------------------------------------------------------------------- */
(function () {
  var packs   = Array.prototype.slice.call(document.querySelectorAll('.pk'));
  var champG  = document.getElementById('nbInvites');      // en tête de page
  var champF  = document.getElementById('nbInvitesForm');  // dans le formulaire
  var recap   = document.getElementById('cartItems');
  var caches  = document.getElementById('hiddenItems');
  var compte  = document.getElementById('cartCount');
  var bouton  = document.getElementById('orderBtn');

  function echappe(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
    });
  }

  /* Les deux champs « nombre de personnes » disent la même chose : ils
     restent d'accord, sinon le client ne sait plus lequel fait foi. */
  function lierChamps(source, cible) {
    source.addEventListener('input', function () {
      cible.value = source.value;
      packs.forEach(function (pk) {
        /* Les formules déjà prises et laissées à leur valeur d'origine
           suivent ; celles que le client a réglées à la main ne bougent pas. */
        if (pk.classList.contains('est-pris') && !pk.dataset.regleMain) majPers(pk, source.value);
      });
      majTout();
    });
  }
  if (champG && champF) { lierChamps(champG, champF); lierChamps(champF, champG); }

  function majPers(pk, val) {
    var n = Math.max(1, parseInt(val, 10) || 1);
    pk.querySelector('.pk-pers').value = n;
  }

  function personnes(pk) {
    return Math.max(1, parseInt(pk.querySelector('.pk-pers').value, 10) || 1);
  }

  function coches(pk) {
    return Array.prototype.slice.call(pk.querySelectorAll('.pk-case')).filter(function (c) { return c.checked; });
  }

  function prendre(pk, oui) {
    pk.classList.toggle('est-pris', oui);
    if (oui) {
      /* On repart de la formule entière : c'est la proposition de la maison. */
      pk.querySelectorAll('.pk-case').forEach(function (c) { c.checked = true; });
      delete pk.dataset.regleMain;
      majPers(pk, (champG && champG.value) || (champF && champF.value) || 1);
      pk.querySelector('.pk-corps').style.display = '';
    }
    majBilan(pk);
    majTout();
    if (oui) pk.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function majBilan(pk) {
    var total = parseInt(pk.dataset.total, 10) || 0;
    var pris  = coches(pk).length;
    var bilan = pk.querySelector('.pk-bilan');
    pk.classList.toggle('est-vide', pk.classList.contains('est-pris') && pris === 0);

    /* Un élément décoché reste lisible mais se montre écarté. */
    pk.querySelectorAll('.pk-el').forEach(function (el) {
      el.classList.toggle('est-hors', !el.querySelector('.pk-case').checked);
    });

    if (!pk.classList.contains('est-pris')) { bilan.textContent = ''; return; }
    if (pris === 0) {
      bilan.className = 'pk-bilan alerte';
      bilan.textContent = '⚠️ Aucun élément retenu : cette formule ne sera pas commandée.';
    } else if (pris === total) {
      bilan.className = 'pk-bilan';
      bilan.textContent = '✓ Formule complète — ' + total + ' élément' + (total > 1 ? 's' : '') +
                          ' pour ' + personnes(pk) + ' personne' + (personnes(pk) > 1 ? 's' : '') + '.';
    } else {
      bilan.className = 'pk-bilan';
      bilan.textContent = '✓ ' + pris + ' élément' + (pris > 1 ? 's' : '') + ' sur ' + total +
                          ' — ' + personnes(pk) + ' personne' + (personnes(pk) > 1 ? 's' : '') + '.';
    }
  }

  function majTout() {
    var lignes = [], champs = [], nbFormules = 0;

    packs.forEach(function (pk) {
      if (!pk.classList.contains('est-pris')) return;
      var pris = coches(pk);
      if (!pris.length) return;             // formule vidée : elle ne part pas
      nbFormules++;
      var id = pk.dataset.id, pers = personnes(pk);
      var gardes = pris.map(function (c) { return c.dataset.nom; });
      var ecartes = Array.prototype.slice.call(pk.querySelectorAll('.pk-case'))
                    .filter(function (c) { return !c.checked; })
                    .map(function (c) { return c.dataset.nom; });

      lignes.push(
        '<div class="recap-pk">' +
          '<div class="recap-t"><b>' + echappe(pk.dataset.nom) + '</b>' +
            '<span class="recap-p">' + pers + ' pers.</span></div>' +
          '<ul class="recap-l">' + gardes.map(function (n) {
            return '<li>' + echappe(n) + '</li>'; }).join('') + '</ul>' +
          (ecartes.length ? '<p class="recap-sans">Sans : ' +
             ecartes.map(echappe).join(', ') + '</p>' : '') +
        '</div>'
      );

      champs.push('<input type="hidden" name="pack[' + id + '][pers]" value="' + pers + '">');
      pris.forEach(function (c) {
        champs.push('<input type="hidden" name="pack[' + id + '][plats][]" value="' + c.value + '">');
      });
    });

    compte.textContent = nbFormules;
    bouton.disabled = nbFormules === 0;

    var barre = document.getElementById('cmdBarre');
    if (barre) {
      barre.hidden = nbFormules === 0;
      document.getElementById('cbN').textContent = nbFormules;
      var s = nbFormules > 1 ? 's' : '';
      document.getElementById('cbS').textContent  = s;
      document.getElementById('cbS2').textContent = s;
    }
    caches.innerHTML = champs.join('');
    recap.innerHTML = lignes.length ? lignes.join('')
      : '<p class="recap-vide">Rien de choisi pour l\'instant. Prenez une formule à gauche : tout son contenu est coché, vous n\'avez qu\'à retirer ce dont vous ne voulez pas.</p>';
  }

  packs.forEach(function (pk) {
    pk.querySelector('.pk-prendre').addEventListener('click', function () { prendre(pk, true); });
    pk.querySelector('.pk-retirer').addEventListener('click', function () { prendre(pk, false); });

    pk.querySelector('.pk-tout').addEventListener('click', function () {
      pk.querySelectorAll('.pk-case').forEach(function (c) { c.checked = true; });
      majBilan(pk); majTout();
    });
    pk.querySelector('.pk-rien').addEventListener('click', function () {
      pk.querySelectorAll('.pk-case').forEach(function (c) { c.checked = false; });
      majBilan(pk); majTout();
    });

    pk.querySelectorAll('.pk-case').forEach(function (c) {
      c.addEventListener('change', function () { majBilan(pk); majTout(); });
    });

    var pers = pk.querySelector('.pk-pers');
    pers.addEventListener('input', function () { pk.dataset.regleMain = '1'; majBilan(pk); majTout(); });
    pk.querySelector('.pk-moins').addEventListener('click', function () {
      pers.value = Math.max(1, (parseInt(pers.value, 10) || 1) - 1);
      pk.dataset.regleMain = '1'; majBilan(pk); majTout();
    });
    pk.querySelector('.pk-plus').addEventListener('click', function () {
      pers.value = Math.min(100000, (parseInt(pers.value, 10) || 0) + 1);
      pk.dataset.regleMain = '1'; majBilan(pk); majTout();
    });
  });

  var voir = document.getElementById('cbVoir');
  if (voir) voir.addEventListener('click', function () {
    document.querySelector('.order-cart').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  /* Un envoi sans rien de coché n'arrive pas jusqu'au serveur. */
  document.getElementById('orderForm').addEventListener('submit', function (e) {
    if (!document.getElementById('hiddenItems').children.length) e.preventDefault();
  });

  majTout();
})();
</script>
<?php client_footer(); ?>
