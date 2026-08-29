<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
$uid = (int)$_SESSION['admin_id'];
$admin = is_admin();

$prio_badge = ['basse'=>'badge','normale'=>'badge-violet','haute'=>'badge-danger'];
$prio_label = ['basse'=>'Basse','normale'=>'Normale','haute'=>'Haute'];
$stat_badge = ['a_faire'=>'badge-gold','en_cours'=>'badge-violet','termine'=>'badge-teal'];
$stat_label = ['a_faire'=>'À faire','en_cours'=>'En cours','termine'=>'Terminée'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // --- Actions ADMIN ---
    if ($admin && isset($_POST['supprimer'])) {
        $pdo->prepare('DELETE FROM taches WHERE id=?')->execute([(int)$_POST['supprimer']]);
        flash('Tâche supprimée.'); header('Location: taches.php'); exit;
    }
    if ($admin && isset($_POST['assigner'])) {
        $id = (int)($_POST['id'] ?? 0);
        $titre = trim($_POST['titre'] ?? '');
        $desc = mb_substr(trim($_POST['description'] ?? ''), 0, 2000);
        $assig = ($_POST['assigne_a'] ?? '') ?: null;
        $prio = in_array($_POST['priorite'] ?? '', ['basse','normale','haute']) ? $_POST['priorite'] : 'normale';
        $lim = ($_POST['date_limite'] ?? '') ?: null;
        if ($titre === '' || !$assig) { flash('Titre et employé obligatoires.', 'error'); header('Location: taches.php'); exit; }
        if ($id) {
            $pdo->prepare('UPDATE taches SET titre=?, description=?, assigne_a=?, priorite=?, date_limite=? WHERE id=?')
                ->execute([mb_substr($titre,0,200), $desc, $assig, $prio, $lim, $id]);
            flash('Tâche modifiée.');
        } else {
            $pdo->prepare('INSERT INTO taches (titre, description, assigne_a, priorite, date_limite, cree_par, vue) VALUES (?,?,?,?,?,?,0)')
                ->execute([mb_substr($titre,0,200), $desc, $assig, $prio, $lim, $uid]);
            flash('Tâche assignée à l\'employé ✅');
        }
        header('Location: taches.php'); exit;
    }

    // --- Actions EMPLOYÉ (sur ses propres tâches) ---
    if (!$admin && isset($_POST['maj_statut'])) {
        $tid = (int)$_POST['maj_statut'];
        $st = in_array($_POST['statut'] ?? '', ['a_faire','en_cours','termine']) ? $_POST['statut'] : 'a_faire';
        $note = mb_substr(trim($_POST['note_employe'] ?? ''), 0, 2000);
        $pdo->prepare('UPDATE taches SET statut=?, note_employe=? WHERE id=? AND assigne_a=?')->execute([$st, $note, $tid, $uid]);
        flash('Tâche mise à jour.'); header('Location: taches.php'); exit;
    }
}

// Marquer les tâches de l'employé comme vues (efface la notification)
if (!$admin) { $pdo->prepare('UPDATE taches SET vue=1 WHERE assigne_a=? AND vue=0')->execute([$uid]); }

$edit = null;
if ($admin && isset($_GET['edit'])) { $stmt = $pdo->prepare('SELECT * FROM taches WHERE id=?'); $stmt->execute([(int)$_GET['edit']]); $edit = $stmt->fetch(); }

$employes = $pdo->query("SELECT id, nom FROM users WHERE role='employe' AND actif=1 ORDER BY nom")->fetchAll();

if ($admin) {
    $fEmp = $_GET['emp'] ?? '';
    $sql = "SELECT t.*, u.nom AS employe FROM taches t LEFT JOIN users u ON u.id=t.assigne_a";
    $params = [];
    if ($fEmp !== '') { $sql .= " WHERE t.assigne_a=?"; $params[] = (int)$fEmp; }
    $sql .= " ORDER BY FIELD(t.statut,'a_faire','en_cours','termine'), t.date_limite IS NULL, t.date_limite";
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $taches = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT * FROM taches WHERE assigne_a=? ORDER BY FIELD(statut,'a_faire','en_cours','termine'), date_limite IS NULL, date_limite");
    $stmt->execute([$uid]); $taches = $stmt->fetchAll();
}

admin_header('Tâches', 'taches', $pdo, $settings);
?>

<?php if ($admin): ?>
<div class="panel glass" id="form">
  <h2><?= $edit ? '✏️ Modifier la tâche' : '➕ Assigner une tâche' ?>
    <?php if ($edit): ?><a href="taches.php" class="btn btn-glass btn-sm" style="margin-left:auto">Annuler</a><?php endif; ?>
  </h2>
  <?php if (!$employes): ?>
    <p style="color:var(--ink-faint)">Créez d'abord un compte employé dans <a href="acces.php" style="color:var(--gold)">Accès employés</a> pour pouvoir lui assigner des tâches.</p>
  <?php else: ?>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
    <div class="field full"><label>Titre de la tâche *</label><input class="input" name="titre" required value="<?= e($edit['titre'] ?? '') ?>" placeholder="ex : Préparer le buffet du mariage Konan"></div>
    <div class="field"><label>Assigner à *</label>
      <select class="input" name="assigne_a" required>
        <option value="">— Choisir un employé —</option>
        <?php foreach ($employes as $em): ?><option value="<?= $em['id'] ?>" <?= ($edit['assigne_a'] ?? 0)==$em['id']?'selected':'' ?>><?= e($em['nom']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Priorité</label>
      <select class="input" name="priorite">
        <?php foreach ($prio_label as $k=>$v): ?><option value="<?= $k ?>" <?= ($edit['priorite'] ?? 'normale')===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Échéance</label><input class="input" type="date" name="date_limite" value="<?= e($edit['date_limite'] ?? '') ?>"></div>
    <div class="field full"><label>Description / consignes</label><textarea class="input" name="description" style="min-height:90px"><?= e($edit['description'] ?? '') ?></textarea></div>
    <div class="full"><button class="btn btn-gold" name="assigner" value="1"><?= $edit ? 'Enregistrer' : 'Assigner la tâche' ?></button></div>
  </form>
  <?php endif; ?>
</div>

<div class="panel glass">
  <h2>✅ Toutes les tâches (<?= count($taches) ?>)
    <form method="get" style="margin-left:auto">
      <select class="input" name="emp" style="padding:8px 12px" onchange="this.form.submit()">
        <option value="">Tous les employés</option>
        <?php foreach ($employes as $em): ?><option value="<?= $em['id'] ?>" <?= ($_GET['emp'] ?? '')==$em['id']?'selected':'' ?>><?= e($em['nom']) ?></option><?php endforeach; ?>
      </select>
    </form>
  </h2>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Tâche</th><th>Employé</th><th>Priorité</th><th>Échéance</th><th>Statut</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        <?php foreach ($taches as $t): ?>
        <tr>
          <td><strong><?= e($t['titre']) ?></strong><?= $t['note_employe'] ? '<br><small>📝 '.e(mb_strimwidth($t['note_employe'],0,70,'…')).'</small>' : '' ?></td>
          <td><?= e($t['employe'] ?: '—') ?></td>
          <td><span class="badge <?= $prio_badge[$t['priorite']] ?>"><?= $prio_label[$t['priorite']] ?></span></td>
          <td><?= $t['date_limite'] ? date('d/m/Y', strtotime($t['date_limite'])) : '—' ?></td>
          <td><span class="badge <?= $stat_badge[$t['statut']] ?>"><?= $stat_label[$t['statut']] ?></span></td>
          <td><div class="td-actions">
            <a class="btn btn-glass btn-sm" href="?edit=<?= $t['id'] ?>#form">✏️</a>
            <form method="post" data-confirm="Supprimer cette tâche ?"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><button class="btn btn-danger btn-sm" name="supprimer" value="<?= $t['id'] ?>">✕</button></form>
          </div></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$taches): ?><tr><td colspan="6" style="text-align:center;padding:30px;color:var(--ink-faint)">Aucune tâche assignée pour l'instant.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: /* ===================== VUE EMPLOYÉ ===================== */ ?>
<div class="panel glass">
  <h2>✅ Mes tâches (<?= count($taches) ?>)</h2>
  <p style="color:var(--ink-faint);font-size:13.5px;margin:-8px 0 4px">Mettez à jour l'avancement de chaque tâche et laissez un commentaire. Faites votre rapport journalier depuis l'onglet <a href="rapports.php" style="color:var(--gold)">Rapports</a>.</p>
</div>

<?php if (!$taches): ?>
<div class="panel glass"><p style="text-align:center;padding:20px;color:var(--ink-faint)">Aucune tâche pour le moment. 🎉</p></div>
<?php endif; ?>

<?php foreach ($taches as $t): ?>
<div class="panel glass task-card">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap">
    <div style="flex:1;min-width:220px">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px">
        <span class="badge <?= $prio_badge[$t['priorite']] ?>">Priorité <?= strtolower($prio_label[$t['priorite']]) ?></span>
        <span class="badge <?= $stat_badge[$t['statut']] ?>"><?= $stat_label[$t['statut']] ?></span>
        <?php if ($t['date_limite']): ?><span class="badge">📅 <?= date('d/m/Y', strtotime($t['date_limite'])) ?></span><?php endif; ?>
      </div>
      <h3 style="font-family:var(--font-display);font-size:17px;margin-bottom:6px"><?= e($t['titre']) ?></h3>
      <?php if ($t['description']): ?><p style="color:var(--ink-dim);font-size:14px;white-space:pre-wrap"><?= e($t['description']) ?></p><?php endif; ?>
    </div>
  </div>
  <form method="post" class="form-grid" style="margin-top:16px">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div class="field"><label>Avancement</label>
      <select class="input" name="statut">
        <?php foreach ($stat_label as $k=>$v): ?><option value="<?= $k ?>" <?= $t['statut']===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field full"><label>Mon commentaire</label><textarea class="input" name="note_employe" style="min-height:60px" placeholder="Précisions, difficultés, résultat…"><?= e($t['note_employe'] ?? '') ?></textarea></div>
    <div class="full"><button class="btn btn-gold btn-sm" name="maj_statut" value="<?= $t['id'] ?>">Mettre à jour</button></div>
  </form>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php admin_footer(); ?>
