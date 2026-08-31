<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/icones.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['supprimer'])) {
        $pdo->prepare('DELETE FROM services WHERE id=?')->execute([(int)$_POST['supprimer']]);
        flash('Service supprimé.');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $nom = trim($_POST['nom'] ?? '');
        if ($nom === '') { flash('Le nom est obligatoire.', 'error'); }
        else {
            $data = [mb_substr($nom, 0, 120), mb_substr(trim($_POST['description'] ?? ''), 0, 600), mb_substr(trim($_POST['icone'] ?? '✨'), 0, 10), (int)($_POST['ordre'] ?? 0), isset($_POST['actif']) ? 1 : 0];
            if ($id) { $pdo->prepare('UPDATE services SET nom=?, description=?, icone=?, ordre=?, actif=? WHERE id=?')->execute([...$data, $id]); flash('Service modifié.'); }
            else { $pdo->prepare('INSERT INTO services (nom, description, icone, ordre, actif) VALUES (?,?,?,?,?)')->execute($data); flash('Service ajouté.'); }
        }
    }
    header('Location: services.php'); exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM services WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch();
}
$services = $pdo->query('SELECT * FROM services ORDER BY ordre, id')->fetchAll();

admin_header('Services & prestations', 'services', $pdo, $settings);
?>
<div class="panel glass" id="form">
  <h2><?= $edit ? '✏️ Modifier : ' . e($edit['nom']) : '➕ Nouveau service' ?></h2>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
    <div class="field"><label>Nom *</label><input class="input" name="nom" required value="<?= e($edit['nom'] ?? '') ?>"></div>
    <div class="field"><?= champ_icone('icone', $edit['icone'] ?? '', 'Icône du service', '✨') ?></div>
    <div class="field"><label>Ordre</label><input class="input" type="number" name="ordre" value="<?= e($edit['ordre'] ?? 0) ?>"></div>
    <div class="field full"><label>Description</label><textarea class="input" name="description" style="min-height:80px"><?= e($edit['description'] ?? '') ?></textarea></div>
    <label class="switch"><input type="checkbox" name="actif" <?= ($edit['actif'] ?? 1) ? 'checked' : '' ?>> Visible sur le site</label>
    <div class="full" style="display:flex;gap:10px">
      <button class="btn btn-gold"><?= $edit ? 'Enregistrer' : 'Ajouter le service' ?></button>
      <?php if ($edit): ?><a class="btn btn-glass" href="services.php">Annuler</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="panel glass">
  <h2>✨ Services (<?= count($services) ?>)</h2>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Icône</th><th>Service</th><th>Ordre</th><th>Statut</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        <?php foreach ($services as $sv): ?>
        <tr>
          <td style="font-size:22px"><?= e($sv['icone']) ?></td>
          <td><strong><?= e($sv['nom']) ?></strong><br><small><?= e(mb_substr($sv['description'], 0, 90)) ?>…</small></td>
          <td><?= $sv['ordre'] ?></td>
          <td><span class="badge <?= $sv['actif'] ? 'badge-teal' : 'badge-danger' ?>"><?= $sv['actif'] ? 'Visible' : 'Masqué' ?></span></td>
          <td>
            <div class="td-actions">
              <a class="btn btn-glass btn-sm" href="?edit=<?= $sv['id'] ?>#form">Modifier</a>
              <form method="post" data-confirm="Supprimer « <?= e($sv['nom']) ?> » ?">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <button class="btn btn-danger btn-sm" name="supprimer" value="<?= $sv['id'] ?>">✕</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php admin_footer(); ?>
