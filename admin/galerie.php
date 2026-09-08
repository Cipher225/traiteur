<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';

/* ============================================================================
   GALERIE
   ----------------------------------------------------------------------------
   Les photos sont rangées par album — mariages, séminaires, cocktails — et
   peuvent être rattachées à une prestation réelle. Le visiteur du site filtre
   ainsi les réalisations qui ressemblent à son projet, au lieu de faire défiler
   un mur d'images sans repère.
   ============================================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    /* ---- Envoi de plusieurs photos d'un coup ---- */
    if (isset($_POST['ajouter'])) {
        $album  = (int)($_POST['album_id'] ?? 0) ?: null;
        $fact   = (int)($_POST['facture_id'] ?? 0) ?: null;
        $titre  = trim($_POST['titre'] ?? '');
        $ajouts = 0; $refus = [];

        $noms = $_FILES['images']['name'] ?? [];
        foreach ($noms as $i => $nom) {
            if (($_FILES['images']['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
            $un = [
                'name'     => $nom,
                'type'     => $_FILES['images']['type'][$i],
                'tmp_name' => $_FILES['images']['tmp_name'][$i],
                'error'    => $_FILES['images']['error'][$i],
                'size'     => $_FILES['images']['size'][$i],
            ];
            $image = upload_image($un, UPLOAD_DIR);
            if (!$image) { $refus[] = $nom; continue; }

            /* On mémorise les dimensions : la mosaïque du site s'en sert pour
               placer chaque photo sans la déformer. */
            $dim = @getimagesize(UPLOAD_DIR . '/' . $image);
            $pdo->prepare('INSERT INTO galerie (titre, image, album_id, facture_id, description, ordre, actif, largeur, hauteur)
                           VALUES (?,?,?,?,?,?,1,?,?)')
                ->execute([mb_substr($titre !== '' ? $titre : pathinfo($nom, PATHINFO_FILENAME), 0, 150),
                           $image, $album, $fact,
                           mb_substr(trim($_POST['description'] ?? ''), 0, 300),
                           (int)($_POST['ordre'] ?? 0),
                           (int)($dim[0] ?? 0), (int)($dim[1] ?? 0)]);
            $ajouts++;
        }

        if ($ajouts) flash($ajouts . ' photo' . ($ajouts > 1 ? 's ajoutées' : ' ajoutée') . ' à la galerie. 📸');
        if ($refus)  flash('Format refusé pour : ' . e(implode(', ', array_slice($refus, 0, 3))), 'error');
        if (!$ajouts && !$refus) flash('Aucune photo sélectionnée.', 'error');
        header('Location: galerie.php' . ($album ? '?album=' . $album : '')); exit;
    }

    /* ---- Album ---- */
    if (isset($_POST['album_creer'])) {
        $nom = trim($_POST['al_nom'] ?? '');
        if ($nom === '') flash("Donnez un nom à l'album.", 'error');
        else {
            $pdo->prepare('INSERT INTO galerie_albums (nom, icone, description, ordre) VALUES (?,?,?,?)')
                ->execute([mb_substr($nom, 0, 120),
                           mb_substr(trim($_POST['al_icone'] ?? '📸'), 0, 12),
                           mb_substr(trim($_POST['al_description'] ?? ''), 0, 300),
                           (int)($_POST['al_ordre'] ?? 0)]);
            flash('Album créé.');
        }
        header('Location: galerie.php'); exit;
    }

    if (isset($_POST['album_supprimer'])) {
        $aid = (int)$_POST['album_supprimer'];
        /* Les photos ne sont pas détruites : elles rejoignent « Sans album ».
           Supprimer un rangement ne doit jamais détruire son contenu. */
        $pdo->prepare('UPDATE galerie SET album_id=NULL WHERE album_id=?')->execute([$aid]);
        $pdo->prepare('DELETE FROM galerie_albums WHERE id=?')->execute([$aid]);
        flash('Album supprimé. Ses photos ont été conservées.');
        header('Location: galerie.php'); exit;
    }

    /* ---- Actions groupées sur les photos ---- */
    if (isset($_POST['lot_action'])) {
        $ids = array_filter(array_map('intval', (array)($_POST['photos'] ?? [])));
        if (!$ids) { flash('Aucune photo sélectionnée.', 'error'); header('Location: galerie.php'); exit; }
        $trous = implode(',', array_fill(0, count($ids), '?'));

        if ($_POST['lot_action'] === 'supprimer') {
            $st = $pdo->prepare("SELECT image FROM galerie WHERE id IN ($trous)");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $img) {
                $chemin = UPLOAD_DIR . '/' . $img;
                if ($img && is_file($chemin)) @unlink($chemin);
            }
            $pdo->prepare("DELETE FROM galerie WHERE id IN ($trous)")->execute($ids);
            flash(count($ids) . ' photo(s) supprimée(s).');

        } elseif ($_POST['lot_action'] === 'deplacer') {
            $cible = (int)($_POST['lot_album'] ?? 0) ?: null;
            $pdo->prepare("UPDATE galerie SET album_id=? WHERE id IN ($trous)")
                ->execute(array_merge([$cible], $ids));
            flash(count($ids) . ' photo(s) déplacée(s).');

        } elseif ($_POST['lot_action'] === 'masquer' || $_POST['lot_action'] === 'afficher') {
            $v = $_POST['lot_action'] === 'afficher' ? 1 : 0;
            $pdo->prepare("UPDATE galerie SET actif=? WHERE id IN ($trous)")
                ->execute(array_merge([$v], $ids));
            flash(count($ids) . ' photo(s) ' . ($v ? 'affichée(s)' : 'masquée(s)') . ' sur le site.');
        }
        header('Location: galerie.php' . (!empty($_POST['retour_album']) ? '?album=' . (int)$_POST['retour_album'] : '')); exit;
    }
}

$albums = $pdo->query("SELECT a.*, (SELECT COUNT(*) FROM galerie g WHERE g.album_id = a.id) AS nb
                       FROM galerie_albums a ORDER BY a.ordre, a.nom")->fetchAll();
$sansAlbum = (int)$pdo->query("SELECT COUNT(*) FROM galerie WHERE album_id IS NULL")->fetchColumn();
$totalPhotos = (int)$pdo->query("SELECT COUNT(*) FROM galerie")->fetchColumn();

$albumSel = isset($_GET['album']) ? (int)$_GET['album'] : null;
$sql = "SELECT g.*, a.nom AS album_nom, a.icone AS album_icone, f.numero AS facture_num, f.activite
        FROM galerie g
        LEFT JOIN galerie_albums a ON a.id = g.album_id
        LEFT JOIN factures f ON f.id = g.facture_id";
$args = [];
if ($albumSel === 0)      { $sql .= " WHERE g.album_id IS NULL"; }
elseif ($albumSel)        { $sql .= " WHERE g.album_id = ?"; $args[] = $albumSel; }
$sql .= " ORDER BY g.ordre, g.id DESC";
$st = $pdo->prepare($sql); $st->execute($args);
$photos = $st->fetchAll();

$prestations = $pdo->query("SELECT f.id, f.numero, f.activite,
                                   COALESCE(NULLIF(c.entreprise,''), c.nom) AS client
                            FROM factures f LEFT JOIN clients c ON c.id = f.client_id
                            WHERE f.type='facture' AND f.statut <> 'annulee'
                            ORDER BY f.date_emission DESC LIMIT 60")->fetchAll();

admin_header('Galerie', 'galerie', $pdo, $settings);
?>

<!-- ---------- Albums ---------- -->
<div class="panel glass">
  <h2>🗂️ Albums</h2>
  <p class="gal-aide">Rangez vos photos par type de prestation : le visiteur du site
    retrouve alors les réalisations qui ressemblent à son projet.</p>

  <div class="gal-albums">
    <a class="ga <?= $albumSel === null ? 'actif' : '' ?>" href="galerie.php">
      <span class="ga-i">🖼️</span><span class="ga-n">Toutes</span><span class="ga-c"><?= $totalPhotos ?></span>
    </a>
    <?php foreach ($albums as $a): ?>
    <a class="ga <?= $albumSel === (int)$a['id'] ? 'actif' : '' ?>" href="galerie.php?album=<?= (int)$a['id'] ?>">
      <span class="ga-i"><?= e($a['icone']) ?></span>
      <span class="ga-n"><?= e($a['nom']) ?></span>
      <span class="ga-c"><?= (int)$a['nb'] ?></span>
    </a>
    <?php endforeach; ?>
    <?php if ($sansAlbum): ?>
    <a class="ga <?= $albumSel === 0 ? 'actif' : '' ?>" href="galerie.php?album=0">
      <span class="ga-i">📁</span><span class="ga-n">Sans album</span><span class="ga-c"><?= $sansAlbum ?></span>
    </a>
    <?php endif; ?>
  </div>

  <details class="gal-pliable">
    <summary class="gal-plus">➕ Créer un album</summary>
    <form method="post" class="form-grid" style="margin-top:10px">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <div class="field"><label>Nom *</label><input class="input" name="al_nom" required placeholder="ex : Mariages"></div>
      <div class="field"><label>Icône</label><input class="input" name="al_icone" value="📸" maxlength="8"></div>
      <div class="field"><label>Ordre</label><input class="input" type="number" name="al_ordre" value="0"></div>
      <div class="field full"><label>Description</label><input class="input" name="al_description" placeholder="Une phrase affichée sur le site"></div>
      <div class="full"><button class="btn btn-glass" name="album_creer" value="1">Créer l'album</button></div>
    </form>
  </details>

  <?php if ($albumSel && $albumSel > 0): ?>
  <form method="post" style="margin-top:10px" onsubmit="return confirm('Supprimer cet album ? Ses photos seront conservées.')">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <button class="btn btn-danger btn-sm" name="album_supprimer" value="<?= (int)$albumSel ?>">✕ Supprimer cet album</button>
  </form>
  <?php endif; ?>
</div>

<!-- ---------- Ajout de photos ---------- -->
<div class="panel glass" style="margin-top:14px">
  <h2>📤 Ajouter des photos</h2>
  <form method="post" enctype="multipart/form-data" class="form-grid" id="form-photos">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div class="field full">
      <label>Photos * <span style="color:var(--ink-faint);font-weight:400">— plusieurs à la fois</span></label>
      <div class="depot" id="depot">
        <input type="file" name="images[]" id="images" accept="image/*" multiple hidden>
        <div class="dp-vide" id="dp-vide">
          <span class="dp-i">🖼️</span>
          <strong>Choisissez vos photos</strong>
          <span>ou glissez-les ici — JPG, PNG ou WebP</span>
        </div>
        <div class="dp-apercus" id="dp-apercus"></div>
      </div>
    </div>

    <div class="field"><label>Album</label>
      <select class="input" name="album_id">
        <option value="">— Sans album —</option>
        <?php foreach ($albums as $a): ?>
        <option value="<?= (int)$a['id'] ?>" <?= $albumSel === (int)$a['id'] ? 'selected' : '' ?>>
          <?= e($a['icone']) ?> <?= e($a['nom']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field"><label>Prestation liée</label>
      <select class="input" name="facture_id">
        <option value="">— Aucune —</option>
        <?php foreach ($prestations as $p): ?>
        <option value="<?= (int)$p['id'] ?>">
          <?= e($p['activite'] ?: $p['numero']) ?><?= $p['client'] ? ' — ' . e($p['client']) : '' ?></option>
        <?php endforeach; ?>
      </select>
      <span class="gal-note">Relie ces photos à une prestation réelle.</span>
    </div>

    <div class="field"><label>Titre commun</label>
      <input class="input" name="titre" placeholder="Vide = nom du fichier"></div>

    <div class="field"><label>Ordre d'affichage</label>
      <input class="input" type="number" name="ordre" value="0"></div>

    <div class="field full"><label>Légende</label>
      <input class="input" name="description" placeholder="Une phrase affichée au survol sur le site"></div>

    <div class="full">
      <button class="btn btn-gold" name="ajouter" value="1">📤 Ajouter à la galerie</button>
      <span class="gal-note" id="compte-photos"></span>
    </div>
  </form>
</div>

<!-- ---------- Les photos ---------- -->
<div class="panel glass" style="margin-top:14px">
  <form method="post" id="form-lot">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="retour_album" value="<?= $albumSel !== null ? (int)$albumSel : '' ?>">

    <div class="gal-tete">
      <h2 style="margin:0">🖼️ <?= count($photos) ?> photo<?= count($photos) > 1 ? 's' : '' ?></h2>
      <div class="gal-outils" id="gal-outils" hidden>
        <span class="go-n"><b id="go-nb">0</b> sélectionnée(s)</span>
        <select class="input input-sm" name="lot_album">
          <option value="">— Sans album —</option>
          <?php foreach ($albums as $a): ?>
          <option value="<?= (int)$a['id'] ?>"><?= e($a['icone']) ?> <?= e($a['nom']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-glass btn-sm" name="lot_action" value="deplacer">📁 Déplacer</button>
        <button class="btn btn-glass btn-sm" name="lot_action" value="masquer">🙈 Masquer</button>
        <button class="btn btn-glass btn-sm" name="lot_action" value="afficher">👁️ Afficher</button>
        <button class="btn btn-danger btn-sm" name="lot_action" value="supprimer"
                onclick="return confirm('Supprimer définitivement les photos sélectionnées ?')">✕</button>
      </div>
      <?php if ($photos): ?>
      <button type="button" class="btn btn-glass btn-sm" id="tout-sel">Tout sélectionner</button>
      <?php endif; ?>
    </div>

    <?php if ($photos): ?>
    <div class="gal-grille">
      <?php foreach ($photos as $g): ?>
      <label class="gp <?= empty($g['actif']) ? 'masquee' : '' ?>">
        <input type="checkbox" name="photos[]" value="<?= (int)$g['id'] ?>" class="gp-case" hidden>
        <img src="../uploads/<?= e($g['image']) ?>" alt="<?= e($g['titre']) ?>" loading="lazy">
        <span class="gp-coche">✓</span>
        <span class="gp-info">
          <strong><?= e($g['titre']) ?></strong>
          <?php if ($g['album_nom']): ?><em><?= e($g['album_icone']) ?> <?= e($g['album_nom']) ?></em><?php endif; ?>
          <?php if ($g['activite'] || $g['facture_num']): ?>
          <em>🔗 <?= e($g['activite'] ?: $g['facture_num']) ?></em>
          <?php endif; ?>
        </span>
        <?php if (empty($g['actif'])): ?><span class="gp-masque">Masquée</span><?php endif; ?>
      </label>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p style="color:var(--ink-faint);font-size:13px;margin:0">
      Aucune photo dans cette sélection. Ajoutez-en ci-dessus.</p>
    <?php endif; ?>
  </form>
</div>

<script>
(function () {
  /* ---- Dépôt de fichiers : aperçu immédiat avant l'envoi ---- */
  var champ = document.getElementById('images');
  var zone  = document.getElementById('depot');
  var vide  = document.getElementById('dp-vide');
  var apercus = document.getElementById('dp-apercus');
  var compte = document.getElementById('compte-photos');

  function afficherApercus(fichiers) {
    apercus.innerHTML = '';
    var n = 0;
    Array.prototype.forEach.call(fichiers, function (f) {
      if (f.type.indexOf('image/') !== 0) return;
      n++;
      var d = document.createElement('div');
      d.className = 'dp-a';
      var img = document.createElement('img');
      img.src = URL.createObjectURL(f);
      img.onload = function () { URL.revokeObjectURL(img.src); };
      d.appendChild(img);
      apercus.appendChild(d);
      requestAnimationFrame(function () { d.classList.add('vu'); });
    });
    vide.hidden = n > 0;
    compte.textContent = n ? n + ' photo(s) prête(s) à être envoyée(s).' : '';
  }

  zone.addEventListener('click', function () { champ.click(); });
  champ.addEventListener('change', function () { afficherApercus(this.files); });

  ['dragenter', 'dragover'].forEach(function (ev) {
    zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('survol'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove('survol'); });
  });
  zone.addEventListener('drop', function (e) {
    champ.files = e.dataTransfer.files;
    afficherApercus(e.dataTransfer.files);
  });

  /* ---- Sélection multiple dans la grille ---- */
  var outils = document.getElementById('gal-outils');
  var goNb = document.getElementById('go-nb');
  function cases() { return Array.prototype.slice.call(document.querySelectorAll('.gp-case')); }
  function maj() {
    var n = cases().filter(function (c) { return c.checked; }).length;
    if (goNb) goNb.textContent = n;
    if (outils) outils.hidden = n === 0;
    cases().forEach(function (c) { c.closest('.gp').classList.toggle('choisie', c.checked); });
  }
  document.addEventListener('change', function (e) {
    if (e.target.classList && e.target.classList.contains('gp-case')) maj();
  });
  var tout = document.getElementById('tout-sel');
  if (tout) tout.addEventListener('click', function () {
    var toutes = cases().length > 0 && cases().every(function (c) { return c.checked; });
    cases().forEach(function (c) { c.checked = !toutes; });
    this.textContent = toutes ? 'Tout sélectionner' : 'Tout désélectionner';
    maj();
  });
  maj();
})();
</script>

<?php admin_footer(); ?>
