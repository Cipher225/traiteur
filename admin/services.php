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
            /* Les points inclus sont saisis un par ligne : c'est la façon la
               plus simple de décrire une prestation sans éditeur compliqué. */
            $details = trim((string)($_POST['details'] ?? ''));
            $details = implode("\n", array_filter(array_map('trim', explode("\n", $details))));

            $data = [mb_substr($nom, 0, 120),
                     mb_substr(trim($_POST['description'] ?? ''), 0, 600),
                     mb_substr(trim($_POST['icone'] ?? '✨'), 0, 10),
                     mb_substr(trim($_POST['prix_indicatif'] ?? ''), 0, 80),
                     mb_substr($details, 0, 1500),
                     (int)($_POST['ordre'] ?? 0),
                     isset($_POST['actif']) ? 1 : 0];

            if ($id) {
                $pdo->prepare('UPDATE services SET nom=?, description=?, icone=?, prix_indicatif=?, details=?, ordre=?, actif=? WHERE id=?')
                    ->execute([...$data, $id]);
                flash('Service modifié.');
            } else {
                $pdo->prepare('INSERT INTO services (nom, description, icone, prix_indicatif, details, ordre, actif) VALUES (?,?,?,?,?,?,?)')
                    ->execute($data);
                flash('Service ajouté.');
            }
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
/* Monter ou descendre un service : plus simple que de saisir des numéros
   d'ordre à la main, surtout quand il y en a une douzaine. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deplacer'], $_POST['sens'])) {
    csrf_check();
    $sid = (int)$_POST['deplacer'];
    $sens = $_POST['sens'] === 'haut' ? 'haut' : 'bas';
    $tous = $pdo->query('SELECT id FROM services ORDER BY ordre, id')->fetchAll(PDO::FETCH_COLUMN);
    $pos  = array_search($sid, $tous, false);
    if ($pos !== false) {
        $voisin = $sens === 'haut' ? $pos - 1 : $pos + 1;
        if (isset($tous[$voisin])) {
            [$tous[$pos], $tous[$voisin]] = [$tous[$voisin], $tous[$pos]];
            $maj = $pdo->prepare('UPDATE services SET ordre=? WHERE id=?');
            foreach ($tous as $rang => $idS) $maj->execute([$rang + 1, $idS]);
        }
    }
    header('Location: services.php'); exit;
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
    <div class="field"><label>Tarif indicatif</label>
      <input class="input" name="prix_indicatif" value="<?= e($edit['prix_indicatif'] ?? '') ?>"
             placeholder="ex : à partir de 8 000 FCFA / personne">
      <span class="svc-aide">Facultatif. Un ordre de grandeur rassure le visiteur et filtre les demandes hors budget.</span>
    </div>
    <div class="field full"><label>Description</label>
      <textarea class="input" name="description" style="min-height:80px"
                placeholder="Une ou deux phrases : ce que vous proposez, pour quel type d'événement."><?= e($edit['description'] ?? '') ?></textarea>
    </div>
    <div class="field full"><label>Ce que comprend la prestation</label>
      <textarea class="input" name="details" style="min-height:100px"
                placeholder="Un point par ligne :&#10;Service à table par des serveurs en tenue&#10;Vaisselle et nappage fournis&#10;Installation et rangement inclus"><?= e($edit['details'] ?? '') ?></textarea>
      <span class="svc-aide">Un point par ligne. Ils s'afficheront en liste sur le site.</span>
    </div>
    <label class="switch"><input type="checkbox" name="actif" <?= ($edit['actif'] ?? 1) ? 'checked' : '' ?>> Visible sur le site</label>
    <div class="full" style="display:flex;gap:10px">
      <button class="btn btn-gold"><?= $edit ? 'Enregistrer' : 'Ajouter le service' ?></button>
      <?php if ($edit): ?><a class="btn btn-glass" href="services.php">Annuler</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="panel glass">
  <h2>✨ Vos prestations <span class="cnt"><?= count($services) ?></span></h2>
  <p class="svc-aide" style="margin:-6px 0 14px">
    L'ordre ci-dessous est celui du site. Utilisez les flèches pour mettre en avant
    ce que vous voulez vendre en premier.
  </p>

  <?php if ($services): ?>
  <div class="svc-liste">
    <?php foreach ($services as $iS => $sv):
      $points = array_filter(array_map('trim', explode("\n", (string)($sv['details'] ?? ''))));
    ?>
    <div class="svc <?= empty($sv['actif']) ? 'masque' : '' ?>">
      <div class="svc-ico"><?= e($sv['icone'] ?: '✨') ?></div>

      <div class="svc-corps">
        <div class="svc-tete">
          <strong><?= e($sv['nom']) ?></strong>
          <?php if (!empty($sv['prix_indicatif'])): ?>
          <span class="svc-prix"><?= e($sv['prix_indicatif']) ?></span>
          <?php endif; ?>
          <?php if (empty($sv['actif'])): ?><span class="svc-etat">Masqué</span><?php endif; ?>
        </div>
        <?php if (!empty($sv['description'])): ?>
        <p class="svc-desc"><?= e(mb_substr($sv['description'], 0, 160)) ?><?= mb_strlen($sv['description']) > 160 ? '…' : '' ?></p>
        <?php endif; ?>
        <?php if ($points): ?>
        <div class="svc-points">
          <?php foreach (array_slice($points, 0, 4) as $pt): ?>
          <span>✓ <?= e($pt) ?></span>
          <?php endforeach; ?>
          <?php if (count($points) > 4): ?><span class="svc-plus">+<?= count($points) - 4 ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <div class="svc-act">
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="sens" value="haut">
          <button class="svc-b" name="deplacer" value="<?= (int)$sv['id'] ?>" title="Monter"
                  <?= $iS === 0 ? 'disabled' : '' ?>>▲</button>
        </form>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="sens" value="bas">
          <button class="svc-b" name="deplacer" value="<?= (int)$sv['id'] ?>" title="Descendre"
                  <?= $iS === count($services) - 1 ? 'disabled' : '' ?>>▼</button>
        </form>
        <a class="svc-b" href="?edit=<?= (int)$sv['id'] ?>#form" title="Modifier">✏️</a>
        <form method="post" style="display:inline"
              data-confirm="Supprimer « <?= e($sv['nom']) ?> » ? Ce service disparaîtra du site.">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <button class="svc-b sup" name="supprimer" value="<?= (int)$sv['id'] ?>" title="Supprimer">✕</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <p style="color:var(--ink-faint);font-size:13px;margin:0">
    Aucune prestation. Ajoutez-en une ci-dessus : elles apparaîtront sur votre site.</p>
  <?php endif; ?>
</div>

<?php admin_footer(); ?>
