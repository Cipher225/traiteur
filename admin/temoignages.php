<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    if (isset($_POST['supprimer'])) {
        $pdo->prepare('DELETE FROM temoignages WHERE id=?')->execute([(int)$_POST['supprimer']]);
        flash('Témoignage supprimé.');
    } elseif (isset($_POST['valider'])) {
        $pdo->prepare("UPDATE temoignages SET statut='valide', actif=1 WHERE id=?")->execute([(int)$_POST['valider']]);
        flash('Témoignage validé et publié sur le site. ✅');
    } elseif (isset($_POST['rejeter'])) {
        $pdo->prepare("UPDATE temoignages SET statut='rejete', actif=0 WHERE id=?")->execute([(int)$_POST['rejeter']]);
        flash('Témoignage rejeté (non publié).');
    } elseif (isset($_POST['masquer'])) {
        $pdo->prepare("UPDATE temoignages SET statut='en_attente', actif=0 WHERE id=?")->execute([(int)$_POST['masquer']]);
        flash('Témoignage retiré du site (remis en attente).');
    }
    header('Location: temoignages.php'); exit;
}

$attente = $pdo->query("SELECT * FROM temoignages WHERE statut='en_attente' ORDER BY id DESC")->fetchAll();
$valides = $pdo->query("SELECT * FROM temoignages WHERE statut='valide' ORDER BY id DESC")->fetchAll();
$rejetes = $pdo->query("SELECT * FROM temoignages WHERE statut='rejete' ORDER BY id DESC")->fetchAll();

admin_header('Témoignages clients', 'temoignages', $pdo, $settings);

function carte_temo($t, $actions) { ?>
  <div class="temo-card glass">
    <div class="temo-head">
      <div><strong><?= e($t['nom']) ?></strong><?= !empty($t['email']) ? '<br><small style="color:var(--ink-faint)">'.e($t['email']).'</small>' : '' ?></div>
      <div style="color:var(--gold)"><?= str_repeat('★', (int)$t['note']) ?><span style="color:var(--ink-faint)"><?= str_repeat('★', 5-(int)$t['note']) ?></span></div>
    </div>
    <p class="temo-text">« <?= e($t['texte']) ?> »</p>
    <div class="temo-actions"><?= $actions ?></div>
  </div>
<?php }
$csrf = csrf_token();
?>
<div class="panel glass" style="border-left:4px solid var(--gold)">
  <h2>💬 Comment ça marche</h2>
  <p style="color:var(--ink-dim);font-size:14px;margin:0">Vos clients laissent un avis depuis le site public ou depuis leur espace client. Les avis arrivent ici <strong>en attente</strong> : vous décidez de les publier ou non. Seuls les avis <strong>validés</strong> apparaissent sur votre site.</p>
</div>

<div class="panel glass">
  <h2>⏳ En attente de validation <span class="badge badge-gold"><?= count($attente) ?></span></h2>
  <?php if ($attente): ?>
  <div class="temo-grid">
    <?php foreach ($attente as $t): carte_temo($t,
      '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="'.$csrf.'"><button class="btn btn-gold btn-sm" name="valider" value="'.$t['id'].'">✅ Publier</button></form>'
      .'<form method="post" style="display:inline"><input type="hidden" name="csrf" value="'.$csrf.'"><button class="btn btn-glass btn-sm" name="rejeter" value="'.$t['id'].'">✖ Rejeter</button></form>'
      .'<form method="post" style="display:inline" data-confirm="Supprimer définitivement ?"><input type="hidden" name="csrf" value="'.$csrf.'"><button class="btn btn-danger btn-sm" name="supprimer" value="'.$t['id'].'">🗑️</button></form>'
    ); endforeach; ?>
  </div>
  <?php else: ?><p style="color:var(--ink-faint);padding:10px 0">Aucun avis en attente. 👍</p><?php endif; ?>
</div>

<div class="panel glass">
  <h2>✅ Publiés sur le site <span class="badge badge-teal"><?= count($valides) ?></span></h2>
  <?php if ($valides): ?>
  <div class="temo-grid">
    <?php foreach ($valides as $t): carte_temo($t,
      '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="'.$csrf.'"><button class="btn btn-glass btn-sm" name="masquer" value="'.$t['id'].'">🙈 Retirer</button></form>'
      .'<form method="post" style="display:inline" data-confirm="Supprimer définitivement ?"><input type="hidden" name="csrf" value="'.$csrf.'"><button class="btn btn-danger btn-sm" name="supprimer" value="'.$t['id'].'">🗑️</button></form>'
    ); endforeach; ?>
  </div>
  <?php else: ?><p style="color:var(--ink-faint);padding:10px 0">Aucun avis publié pour l'instant.</p><?php endif; ?>
</div>

<?php if ($rejetes): ?>
<div class="panel glass">
  <h2>✖ Rejetés <span class="badge badge-danger"><?= count($rejetes) ?></span></h2>
  <div class="temo-grid">
    <?php foreach ($rejetes as $t): carte_temo($t,
      '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="'.$csrf.'"><button class="btn btn-gold btn-sm" name="valider" value="'.$t['id'].'">✅ Finalement publier</button></form>'
      .'<form method="post" style="display:inline" data-confirm="Supprimer définitivement ?"><input type="hidden" name="csrf" value="'.$csrf.'"><button class="btn btn-danger btn-sm" name="supprimer" value="'.$t['id'].'">🗑️</button></form>'
    ); endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="panel glass" style="text-align:center">
  <p style="color:var(--ink-dim);font-size:14px;margin:0;line-height:1.7">
    💬 Les avis sont rédigés uniquement par vos clients, depuis le site public ou leur espace client.<br>
    Votre rôle ici est de <strong>décider lesquels publier</strong> — vous ne rédigez pas d'avis vous-même.
  </p>
</div>
<?php admin_footer(); ?>
