<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['supprimer'])) {
        $id = (int)$_POST['supprimer'];
        $img = $pdo->prepare('SELECT image FROM galerie WHERE id=?');
        $img->execute([$id]);
        if ($old = $img->fetchColumn()) @unlink(UPLOAD_DIR . '/' . $old);
        $pdo->prepare('DELETE FROM galerie WHERE id=?')->execute([$id]);
        flash('Photo supprimée.');
    } else {
        $image = upload_image($_FILES['image'] ?? [], UPLOAD_DIR);
        if (!$image) { flash('Image invalide (jpg, png, webp, gif — 5 Mo max).', 'error'); }
        else {
            $pdo->prepare('INSERT INTO galerie (titre, image, ordre) VALUES (?,?,?)')
                ->execute([mb_substr(trim($_POST['titre'] ?? ''), 0, 150), $image, (int)($_POST['ordre'] ?? 0)]);
            flash('Photo ajoutée à la galerie.');
        }
    }
    header('Location: galerie.php'); exit;
}

$photos = $pdo->query('SELECT * FROM galerie ORDER BY ordre, id DESC')->fetchAll();

admin_header('Galerie photos', 'galerie', $pdo, $settings);
?>
<div class="panel glass">
  <h2>➕ Ajouter une photo</h2>
  <form method="post" enctype="multipart/form-data" class="form-grid">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div class="field"><label>Photo * (jpg, png, webp — 5 Mo max)</label><input class="input" type="file" name="image" accept="image/*" required></div>
    <div class="field"><label>Légende (facultatif)</label><input class="input" name="titre" placeholder="ex : Mariage à Cocody"></div>
    <div class="field"><label>Ordre</label><input class="input" type="number" name="ordre" value="0"></div>
    <div class="full"><button class="btn btn-gold">Ajouter à la galerie</button></div>
  </form>
</div>

<div class="panel glass">
  <h2>📸 Photos (<?= count($photos) ?>)</h2>
  <?php if (!$photos): ?>
  <p style="color:var(--ink-faint);text-align:center;padding:34px">La galerie est vide. Ajoutez vos plus belles réalisations pour impressionner vos visiteurs ✨</p>
  <?php else: ?>
  <div class="gal-admin">
    <?php foreach ($photos as $g): ?>
    <div class="card glass">
      <img src="../uploads/<?= e($g['image']) ?>" alt="<?= e($g['titre']) ?>">
      <div class="meta">
        <span style="color:var(--ink-dim)"><?= e($g['titre'] ?: 'Sans titre') ?></span>
        <form method="post" data-confirm="Supprimer cette photo ?">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <button class="btn btn-danger btn-sm" name="supprimer" value="<?= $g['id'] ?>">✕</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php admin_footer(); ?>
