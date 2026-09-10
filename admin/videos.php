<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['supprimer'])) {
        $v = $pdo->prepare('SELECT fichier FROM videos WHERE id=?'); $v->execute([(int)$_POST['supprimer']]);
        if ($f = $v->fetchColumn()) @unlink(UPLOAD_DIR . '/' . $f);
        $pdo->prepare('DELETE FROM videos WHERE id=?')->execute([(int)$_POST['supprimer']]);
        flash('Vidéo supprimée.');
        header('Location: videos.php'); exit;
    }
    if (isset($_POST['toggle'])) {
        $pdo->prepare('UPDATE videos SET actif = 1 - actif WHERE id=?')->execute([(int)$_POST['toggle']]);
        header('Location: videos.php'); exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    $titre = trim($_POST['titre'] ?? '');
    $type = ($_POST['type'] ?? 'youtube') === 'fichier' ? 'fichier' : 'youtube';
    $url = trim($_POST['url'] ?? '');
    $desc = mb_substr(trim($_POST['description'] ?? ''), 0, 400);
    $ordre = (int)($_POST['ordre'] ?? 0);

    if ($titre === '') { flash('Le titre est obligatoire.', 'error'); header('Location: videos.php'); exit; }

    $fichier = null;
    if ($type === 'fichier' && !empty($_FILES['fichier']['name'])) {
        $motifVideo = null;
        $fichier = upload_video($_FILES['fichier'], UPLOAD_DIR, $motifVideo);
        if (!$fichier) {
            flash($motifVideo ?: 'Fichier vidéo invalide.', 'error');
            header('Location: videos.php'); exit;
        }
    }
    // miniature optionnelle
    $miniature = null;
    if (!empty($_FILES['miniature']['name'])) $miniature = upload_image($_FILES['miniature'], UPLOAD_DIR);

    if ($id) {
        $set = 'titre=?, description=?, type=?, url=?, ordre=?';
        $params = [mb_substr($titre,0,150), $desc, $type, $url, $ordre];
        if ($fichier) { $set .= ', fichier=?'; $params[] = $fichier; }
        if ($miniature) { $set .= ', miniature=?'; $params[] = $miniature; }
        $params[] = $id;
        $pdo->prepare("UPDATE videos SET $set WHERE id=?")->execute($params);
        flash('Vidéo modifiée.');
    } else {
        $pdo->prepare('INSERT INTO videos (titre, description, type, url, fichier, miniature, ordre) VALUES (?,?,?,?,?,?,?)')
            ->execute([mb_substr($titre,0,150), $desc, $type, $url, $fichier ?: '', $miniature ?: '', $ordre]);
        flash('Vidéo ajoutée. Elle apparaît sur le site ✨');
    }
    header('Location: videos.php'); exit;
}

$edit = null;
if (isset($_GET['edit'])) { $stmt = $pdo->prepare('SELECT * FROM videos WHERE id=?'); $stmt->execute([(int)$_GET['edit']]); $edit = $stmt->fetch(); }
$videos = $pdo->query('SELECT * FROM videos ORDER BY ordre, id DESC')->fetchAll();

admin_header('Vidéos', 'videos', $pdo, $settings);
?>
<div class="panel glass" id="form">
  <h2><?= $edit ? '✏️ Modifier la vidéo' : '🎬 Ajouter une vidéo' ?></h2>
  <form method="post" enctype="multipart/form-data" class="form-grid">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
    <div class="field full"><label>Titre *</label><input class="input" name="titre" required value="<?= e($edit['titre'] ?? '') ?>" placeholder="ex : Mariage de prestige à Cocody"></div>
    <div class="field full"><label>Description</label><input class="input" name="description" value="<?= e($edit['description'] ?? '') ?>"></div>
    <div class="field"><label>Source</label>
      <select class="input" name="type" id="typeSel" onchange="majType()">
        <option value="youtube" <?= ($edit['type'] ?? 'youtube')==='youtube'?'selected':'' ?>>Lien YouTube / Vimeo</option>
        <option value="fichier" <?= ($edit['type'] ?? '')==='fichier'?'selected':'' ?>>Fichier vidéo (upload)</option>
      </select>
    </div>
    <div class="field"><label>Ordre d'affichage</label><input class="input" type="number" name="ordre" value="<?= e($edit['ordre'] ?? 0) ?>"></div>
    <div class="field full" id="blocUrl">
      <label>Lien de la vidéo</label>
      <input class="input" name="url" id="champUrl" value="<?= e($edit['url'] ?? '') ?>"
             placeholder="Collez le lien copié depuis YouTube, Vimeo ou Dailymotion">
      <span class="vid-aide">
        Toutes les écritures sont acceptées : lien du navigateur, bouton « Partager »,
        Short, direct, version mobile. Collez simplement ce que vous avez copié.
      </span>
      <div id="apercuLien" class="vid-verif" hidden></div>
    </div>
    <div class="field full" id="blocFichier" style="display:none">
      <label>Fichier vidéo</label>
      <input class="input" type="file" name="fichier" id="champFichier"
             accept="video/mp4,video/webm,video/ogg,video/quicktime,video/x-msvideo,video/*">
      <span class="vid-aide">
        MP4, WebM, MOV ou AVI — jusqu'à <?= (int)VIDEO_MAX_MO ?> Mo.
        Une vidéo lourde ralentit le chargement de votre site : préférez un lien
        YouTube au-delà de quelques minutes de film.
      </span>
      <div id="poidsFichier" class="vid-verif" hidden></div>
      <?php if (!empty($edit['fichier'])): ?><small style="color:var(--ink-faint)">Actuel : <?= e($edit['fichier']) ?></small><?php endif; ?>
    </div>
    <div class="field full"><label>Miniature (image, facultatif — utile pour les fichiers vidéo)</label><input class="input" type="file" name="miniature" accept="image/*"></div>
    <div class="full" style="display:flex;gap:10px">
      <button class="btn btn-gold"><?= $edit ? 'Enregistrer' : 'Ajouter la vidéo' ?></button>
      <?php if ($edit): ?><a class="btn btn-glass" href="videos.php">Annuler</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="panel glass">
  <h2>🎬 Vidéos (<?= count($videos) ?>)</h2>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Ordre</th><th>Titre</th><th>Source</th><th>Statut</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        <?php foreach ($videos as $v): ?>
        <tr>
          <td><?= (int)$v['ordre'] ?></td>
          <td><strong><?= e($v['titre']) ?></strong><?= $v['description'] ? '<br><small>'.e($v['description']).'</small>' : '' ?></td>
          <td><?= $v['type']==='fichier' ? '📁 Fichier' : '▶️ '.e(parse_url($v['url'], PHP_URL_HOST) ?: 'Lien') ?></td>
          <td><span class="badge <?= $v['actif']?'badge-teal':'badge-danger' ?>"><?= $v['actif']?'Visible':'Masquée' ?></span></td>
          <td>
            <div class="td-actions">
              <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><button class="btn btn-glass btn-sm" name="toggle" value="<?= $v['id'] ?>"><?= $v['actif']?'🙈':'👁️' ?></button></form>
              <a class="btn btn-glass btn-sm" href="?edit=<?= $v['id'] ?>#form">✏️</a>
              <form method="post" data-confirm="Supprimer cette vidéo ?"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><button class="btn btn-danger btn-sm" name="supprimer" value="<?= $v['id'] ?>">✕</button></form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$videos): ?><tr><td colspan="5" style="text-align:center;padding:30px;color:var(--ink-faint)">Aucune vidéo. Ajoutez-en une ci-dessus.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
function majType(){
  const t = document.getElementById('typeSel').value;
  document.getElementById('blocUrl').style.display = t==='youtube' ? '' : 'none';
  document.getElementById('blocFichier').style.display = t==='fichier' ? '' : 'none';
}
majType();
</script>
<script>
(function () {
  /* On vérifie le lien et le poids AVANT l'envoi : découvrir après quatre
     minutes de transfert que le fichier est trop lourd est décourageant. */
  var MAX = <?= (int)VIDEO_MAX_MO ?>;

  var champ = document.getElementById('champUrl');
  var vu = document.getElementById('apercuLien');
  if (champ) {
    var motifs = [
      /[?&]v=([\w-]{11})/, /youtu\.be\/([\w-]{11})/,
      /youtube(?:-nocookie)?\.com\/embed\/([\w-]{11})/,
      /youtube\.com\/shorts\/([\w-]{11})/, /youtube\.com\/live\/([\w-]{11})/
    ];
    function verifier() {
      var u = champ.value.trim();
      if (!u) { vu.hidden = true; return; }
      var id = null;
      for (var i = 0; i < motifs.length; i++) { var m = u.match(motifs[i]); if (m) { id = m[1]; break; } }
      vu.hidden = false;
      if (id) {
        vu.className = 'vid-verif ok';
        vu.innerHTML = '✓ Vidéo YouTube reconnue'
          + '<img src="https://img.youtube.com/vi/' + id + '/mqdefault.jpg" alt="">';
      } else if (/vimeo\.com\/\d+/.test(u)) {
        vu.className = 'vid-verif ok'; vu.textContent = '✓ Vidéo Vimeo reconnue';
      } else if (/dailymotion\.com|dai\.ly/.test(u)) {
        vu.className = 'vid-verif ok'; vu.textContent = '✓ Vidéo Dailymotion reconnue';
      } else {
        vu.className = 'vid-verif non';
        vu.textContent = "Ce lien n'est pas reconnu. Copiez l'adresse depuis la barre du navigateur ou le bouton « Partager ».";
      }
    }
    champ.addEventListener('input', verifier);
    verifier();
  }

  var cf = document.getElementById('champFichier');
  var pf = document.getElementById('poidsFichier');
  if (cf) cf.addEventListener('change', function () {
    var f = this.files && this.files[0];
    if (!f) { pf.hidden = true; return; }
    var mo = f.size / 1048576;
    pf.hidden = false;
    if (mo > MAX) {
      pf.className = 'vid-verif non';
      pf.textContent = 'Fichier trop lourd : ' + mo.toFixed(0) + ' Mo pour ' + MAX + ' Mo autorisés.';
      this.value = '';
    } else {
      pf.className = 'vid-verif ok';
      pf.textContent = f.name + ' — ' + mo.toFixed(1) + ' Mo';
    }
  });
})();
</script>

<?php admin_footer(); ?>
