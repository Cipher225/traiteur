<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/badges.php';

$err = '';

/* ---------------------- Actions ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    /* Créer ou modifier un badge */
    if (isset($_POST['enregistrer'])) {
        $id           = (int)($_POST['id'] ?? 0);
        $type         = ($_POST['type_porteur'] ?? 'employe') === 'externe' ? 'externe' : 'employe';
        $employe_id   = $type === 'employe' && !empty($_POST['employe_id']) ? (int)$_POST['employe_id'] : null;
        $externe_id   = $type === 'externe' && !empty($_POST['externe_id']) ? (int)$_POST['externe_id'] : null;
        $nom          = trim($_POST['nom'] ?? '');
        $poste        = trim($_POST['poste'] ?? '');
        $organisation = trim($_POST['organisation'] ?? '');
        $telephone    = trim($_POST['telephone'] ?? '');
        $email        = trim($_POST['email'] ?? '');
        $departement  = trim($_POST['departement'] ?? '');
        $expiration   = $_POST['date_expiration'] ?: null;
        $groupe_sang  = trim($_POST['groupe_sanguin'] ?? '');
        $naissance    = $_POST['date_naissance'] ?: null;
        $embauche     = $_POST['date_embauche'] ?: null;
        $statut       = in_array($_POST['statut'] ?? '', ['actif','suspendu','expire'], true) ? $_POST['statut'] : 'actif';

        if ($nom === '') {
            $err = 'Le nom est obligatoire.';
        } else {
            /* Photo */
            $photo = null;
            if (!empty($_FILES['photo']['name'])) {
                $photo = upload_image($_FILES['photo'], UPLOAD_DIR);
            }

            if ($id > 0) {
                $b = $pdo->query("SELECT * FROM badges WHERE id=$id")->fetch();
                if ($b) {
                    if (!$photo) $photo = $b['photo'];
                    elseif (!empty($b['photo']) && is_file(UPLOAD_DIR . '/' . $b['photo'])) @unlink(UPLOAD_DIR . '/' . $b['photo']);
                    $pdo->prepare("UPDATE badges SET type_porteur=?, employe_id=?, externe_id=?, nom=?, poste=?, organisation=?, telephone=?, email=?, departement=?, groupe_sanguin=?, date_naissance=?, date_embauche=?, date_expiration=?, statut=?, photo=? WHERE id=?")
                        ->execute([$type, $employe_id, $externe_id, $nom, $poste, $organisation, $telephone, $email, $departement, $groupe_sang, $naissance, $embauche, $expiration, $statut, $photo, $id]);
                    flash('Badge mis à jour.');
                }
            } else {
                // Un badge d'employé reprend le matricule officiel de l'employé (un seul et même).
                $matricule = '';
                if ($type === 'employe' && $employe_id) {
                    $matricule = (string)$pdo->query("SELECT matricule FROM employes WHERE id=" . (int)$employe_id)->fetchColumn();
                }
                // Un badge externe lié à une fiche du registre reprend son numéro de référence.
                if ($type === 'externe' && $externe_id) {
                    $matricule = (string)$pdo->query("SELECT reference FROM externes WHERE id=" . (int)$externe_id)->fetchColumn();
                }
                if ($matricule === '') $matricule = badge_generer_matricule($pdo, $settings, $type);
                $token = bin2hex(random_bytes(16));
                $pdo->prepare("INSERT INTO badges (matricule, type_porteur, employe_id, externe_id, nom, poste, organisation, telephone, email, departement, groupe_sanguin, date_naissance, date_embauche, photo, date_emission, date_expiration, statut, token, cree_par) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$matricule, $type, $employe_id, $externe_id, $nom, $poste, $organisation, $telephone, $email, $departement, $groupe_sang, $naissance, $embauche, $photo, date('Y-m-d'), $expiration, $statut, $token, (int)$_SESSION['admin_id']]);
                // Rattacher le badge à la fiche du registre externe (traçabilité bidirectionnelle)
                if ($type === 'externe' && $externe_id) {
                    $nouveau = (int)$pdo->lastInsertId();
                    $pdo->prepare("UPDATE externes SET badge_id=? WHERE id=?")->execute([$nouveau, $externe_id]);
                }
                flash('Badge ' . $matricule . ' créé.');
            }
            header('Location: badges.php'); exit;
        }
    }

    if (isset($_POST['supprimer'])) {
        $id = (int)$_POST['supprimer'];
        $b = $pdo->query("SELECT photo FROM badges WHERE id=$id")->fetch();
        if ($b && !empty($b['photo']) && is_file(UPLOAD_DIR . '/' . $b['photo'])) @unlink(UPLOAD_DIR . '/' . $b['photo']);
        // Délier la fiche du registre externe pour ne pas laisser de référence vers un badge disparu
        try { $pdo->prepare("UPDATE externes SET badge_id=NULL WHERE badge_id=?")->execute([$id]); } catch (\Throwable $e) {}
        $pdo->prepare("DELETE FROM badges WHERE id=?")->execute([$id]);
        flash('Badge supprimé. (La fiche du registre externe, si elle existe, est conservée.)');
        header('Location: badges.php'); exit;
    }

    if (isset($_POST['changer_statut'])) {
        $id = (int)$_POST['changer_statut'];
        $s = in_array($_POST['statut'] ?? '', ['actif','suspendu','expire'], true) ? $_POST['statut'] : 'actif';
        $pdo->prepare("UPDATE badges SET statut=? WHERE id=?")->execute([$s, $id]);
        flash('Statut mis à jour.');
        header('Location: badges.php'); exit;
    }
}

/* ---------------------- Données ---------------------- */
$edit = null;
if (isset($_GET['edit'])) $edit = $pdo->query("SELECT * FROM badges WHERE id=" . (int)$_GET['edit'])->fetch();

$filtre = $_GET['type'] ?? '';
$q = trim($_GET['q'] ?? '');
$sql = "SELECT * FROM badges WHERE 1";
$params = [];
if ($filtre === 'employe' || $filtre === 'externe') { $sql .= " AND type_porteur=?"; $params[] = $filtre; }
if ($q !== '') { $sql .= " AND (nom LIKE ? OR matricule LIKE ? OR poste LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; }
$sql .= " ORDER BY id DESC";
$st = $pdo->prepare($sql); $st->execute($params); $badges = $st->fetchAll();

$employes = $pdo->query("SELECT id, nom, poste, matricule, telephone, email, departement, groupe_sanguin, date_naissance, date_embauche, photo FROM employes WHERE actif=1 ORDER BY nom")->fetchAll();
// Membres externes du registre (pour rattacher un badge à une fiche existante)
$externesReg = [];
try {
    $externesReg = $pdo->query("SELECT id, reference, nom, organisation, fonction, telephone, email FROM externes WHERE statut='actif' ORDER BY nom")->fetchAll();
} catch (\Throwable $e) { $externesReg = []; }

$stats = [
    'total'    => (int)$pdo->query("SELECT COUNT(*) FROM badges")->fetchColumn(),
    'actifs'   => (int)$pdo->query("SELECT COUNT(*) FROM badges WHERE statut='actif'")->fetchColumn(),
    'employes' => (int)$pdo->query("SELECT COUNT(*) FROM badges WHERE type_porteur='employe'")->fetchColumn(),
    'externes' => (int)$pdo->query("SELECT COUNT(*) FROM badges WHERE type_porteur='externe'")->fetchColumn(),
];

admin_header('Badges & cartes', 'badges', $pdo, $settings);
?>
<?php if ($err): ?><div class="flash error"><?= e($err) ?></div><?php endif; ?>

<div class="stat-grid">
  <div class="stat glass violet"><div class="s-ico">🪪</div><div class="s-num"><?= $stats['total'] ?></div><div class="s-label">Badges émis</div></div>
  <div class="stat glass teal"><div class="s-ico">✅</div><div class="s-num"><?= $stats['actifs'] ?></div><div class="s-label">Actifs</div></div>
  <div class="stat glass gold"><div class="s-ico">🧑‍🍳</div><div class="s-num"><?= $stats['employes'] ?></div><div class="s-label">Employés</div></div>
  <div class="stat glass rose"><div class="s-ico">🤝</div><div class="s-num"><?= $stats['externes'] ?></div><div class="s-label">Externes</div></div>
</div>

<!-- Formulaire de création / édition -->
<div class="panel glass" id="form">
  <h2><?= $edit ? '✏️ Modifier le badge ' . e($edit['matricule']) : '🪪 Émettre un nouveau badge' ?></h2>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= $edit['id'] ?>"><?php endif; ?>

    <div class="bdg-type">
      <label class="bdg-type-opt">
        <input type="radio" name="type_porteur" value="employe" <?= (!$edit || $edit['type_porteur']==='employe') ? 'checked' : '' ?> onchange="majType()">
        <span>🧑‍🍳 Employé</span>
      </label>
      <label class="bdg-type-opt">
        <input type="radio" name="type_porteur" value="externe" <?= ($edit && $edit['type_porteur']==='externe') ? 'checked' : '' ?> onchange="majType()">
        <span>🤝 Membre externe</span>
      </label>
    </div>

    <div id="zone-employe" class="field" style="margin-top:14px">
      <label>Sélectionner l'employé <span style="color:var(--ink-faint);font-weight:400">— remplit automatiquement les infos</span></label>
      <div id="mat-info" style="font-size:12.5px;color:var(--gold3);font-weight:600;margin-bottom:8px"></div>
      <select class="input" name="employe_id" id="sel-employe" onchange="remplirEmploye()">
        <option value="">— Choisir un employé —</option>
        <?php foreach ($employes as $emp): ?>
        <option value="<?= $emp['id'] ?>"
          data-nom="<?= e($emp['nom']) ?>" data-poste="<?= e($emp['poste']) ?>"
          data-tel="<?= e($emp['telephone']) ?>" data-email="<?= e($emp['email']) ?>"
          data-dep="<?= e($emp['departement']) ?>" data-sang="<?= e($emp['groupe_sanguin']) ?>"
          data-naiss="<?= e($emp['date_naissance']) ?>" data-emb="<?= e($emp['date_embauche']) ?>"
          data-mat="<?= e($emp['matricule']) ?>"
          <?= ($edit && $edit['employe_id']==$emp['id']) ? 'selected' : '' ?>><?= e($emp['nom']) ?> — <?= e($emp['poste']) ?> · <?= e($emp['matricule'] ?: 'sans matricule') ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div id="zone-externe" class="field" style="margin-top:14px;display:none">
      <label>Sélectionner un membre externe <span style="color:var(--ink-faint);font-weight:400">— depuis le registre, remplit automatiquement</span></label>
      <div id="ext-info" style="font-size:12.5px;color:var(--gold3);font-weight:600;margin-bottom:8px"></div>
      <?php if ($externesReg): ?>
      <select class="input" name="externe_id" id="sel-externe" onchange="remplirExterne()">
        <option value="">— Choisir dans le registre —</option>
        <?php foreach ($externesReg as $ex): ?>
        <option value="<?= $ex['id'] ?>"
          data-nom="<?= e($ex['nom']) ?>" data-poste="<?= e($ex['fonction']) ?>"
          data-org="<?= e($ex['organisation']) ?>" data-tel="<?= e($ex['telephone']) ?>"
          data-email="<?= e($ex['email']) ?>" data-ref="<?= e($ex['reference']) ?>"
          <?= ($edit && ($edit['externe_id'] ?? 0)==$ex['id']) ? 'selected' : '' ?>><?= e($ex['reference']) ?> — <?= e($ex['nom']) ?><?= $ex['organisation']?' ('.e($ex['organisation']).')':'' ?></option>
        <?php endforeach; ?>
      </select>
      <p style="font-size:12px;color:var(--ink-faint);margin-top:6px">Pas encore dans le registre ? <a href="externes.php" style="color:var(--gold)">Ajouter un membre externe</a>, ou laissez vide pour saisir directement ci-dessous.</p>
      <?php else: ?>
      <p style="font-size:12.5px;color:var(--ink-faint)">Aucun membre externe dans le registre. <a href="externes.php" style="color:var(--gold)">Créer une fiche</a> pour la traçabilité, ou saisissez directement les infos ci-dessous.</p>
      <?php endif; ?>
    </div>

    <div class="form-grid" style="margin-top:14px">
      <div class="field"><label>Nom complet *</label><input class="input" name="nom" id="f-nom" value="<?= e($edit['nom'] ?? '') ?>" required></div>
      <div class="field"><label>Poste / Fonction</label><input class="input" name="poste" id="f-poste" value="<?= e($edit['poste'] ?? '') ?>"></div>
      <div class="field" id="zone-org"><label>Organisation <span style="color:var(--ink-faint);font-weight:400">— société du membre externe</span></label><input class="input" name="organisation" value="<?= e($edit['organisation'] ?? '') ?>"></div>
      <div class="field champ-employe" id="wrap-dep"><label>Département / Service</label><input class="input" id="f-dep" name="departement" value="<?= e($edit['departement'] ?? '') ?>"></div>
      <div class="field"><label>Groupe sanguin</label>
        <select class="input" name="groupe_sanguin">
          <option value="">—</option>
          <?php foreach (['O+','O-','A+','A-','B+','B-','AB+','AB-'] as $gs): ?>
          <option value="<?= $gs ?>" <?= ($edit['groupe_sanguin'] ?? '')===$gs?'selected':'' ?>><?= $gs ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Date de naissance</label><input class="input" id="f-naiss" type="date" name="date_naissance" value="<?= e($edit['date_naissance'] ?? '') ?>"></div>
      <div class="field champ-employe" id="wrap-emb"><label>Date d'embauche</label><input class="input" id="f-emb" type="date" name="date_embauche" value="<?= e($edit['date_embauche'] ?? '') ?>"></div>
      <div class="field"><label>Téléphone</label><input class="input" name="telephone" id="f-tel" value="<?= e($edit['telephone'] ?? '') ?>"></div>
      <div class="field"><label>Email</label><input class="input" type="email" name="email" id="f-email" value="<?= e($edit['email'] ?? '') ?>"></div>
      <div class="field"><label>Date d'expiration <span style="color:var(--ink-faint);font-weight:400">— facultatif</span></label><input class="input" type="date" name="date_expiration" value="<?= e($edit['date_expiration'] ?? '') ?>"></div>
      <div class="field"><label>Statut</label>
        <select class="input" name="statut">
          <option value="actif" <?= ($edit['statut'] ?? 'actif')==='actif'?'selected':'' ?>>Actif</option>
          <option value="suspendu" <?= ($edit['statut'] ?? '')==='suspendu'?'selected':'' ?>>Suspendu</option>
        </select>
      </div>
      <div class="field full"><label>Photo <span style="color:var(--ink-faint);font-weight:400">— portrait carré recommandé</span></label>
        <input class="input" type="file" name="photo" accept="image/*">
        <?php if (!empty($edit['photo'])): ?><p style="font-size:12px;color:var(--ink-faint);margin-top:6px">Photo actuelle conservée si vous n'en choisissez pas une nouvelle.</p><?php endif; ?>
      </div>
    </div>

    <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap">
      <button class="btn btn-gold" name="enregistrer" value="1"><?= $edit ? 'Enregistrer les modifications' : 'Émettre le badge' ?></button>
      <?php if ($edit): ?><a class="btn btn-glass" href="badges.php">Annuler</a><?php endif; ?>
    </div>
  </form>
</div>

<!-- Liste des badges -->
<div class="panel glass">
  <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
    <h2 style="border:0;margin:0;padding:0">🗂️ Badges émis (<?= count($badges) ?>)</h2>
    <div class="vue-tabs" style="margin:0 0 0 auto">
      <a class="vt <?= $filtre===''?'on':'' ?>" href="badges.php">Tous</a>
      <a class="vt <?= $filtre==='employe'?'on':'' ?>" href="?type=employe">Employés</a>
      <a class="vt <?= $filtre==='externe'?'on':'' ?>" href="?type=externe">Externes</a>
    </div>
    <form method="get" style="display:flex;gap:6px">
      <?php if ($filtre): ?><input type="hidden" name="type" value="<?= e($filtre) ?>"><?php endif; ?>
      <input class="input" name="q" placeholder="Rechercher…" value="<?= e($q) ?>" style="max-width:180px">
    </form>
  </div>

  <?php if (!$badges): ?>
    <div style="text-align:center;padding:40px;color:var(--ink-faint)">Aucun badge <?= $q||$filtre?'ne correspond':'émis pour le moment' ?>.</div>
  <?php else: ?>
  <div class="bdg-list">
    <?php foreach ($badges as $b): $st = badge_statut($b); [$sl,$sb] = badge_statut_label($st); ?>
    <div class="bdg-item">
      <div class="bdg-item-photo">
        <?php if (!empty($b['photo']) && is_file(UPLOAD_DIR.'/'.$b['photo'])): ?>
          <img src="../uploads/<?= e($b['photo']) ?>" alt="">
        <?php else: ?>
          <span class="bdg-ini"><?= badge_initiales($b['nom']) ?></span>
        <?php endif; ?>
      </div>
      <div class="bdg-item-info">
        <div class="bdg-item-nom"><?= e($b['nom']) ?> <span class="badge <?= $sb ?>"><?= $sl ?></span></div>
        <div class="bdg-item-sub"><?= e($b['poste'] ?: '—') ?><?= $b['organisation'] ? ' · '.e($b['organisation']) : '' ?></div>
        <div class="bdg-item-mat">🪪 <?= e($b['matricule']) ?> · <?= $b['type_porteur']==='externe'?'Externe':'Employé' ?></div>
      </div>
      <div class="bdg-item-act">
        <?php if ($b['type_porteur'] === 'employe'): ?>
        <a class="btn btn-gold btn-sm" href="badge-print.php?id=<?= $b['id'] ?>&format=carte" target="_blank" title="Carte professionnelle">💳 Carte</a>
        <?php endif; ?>
        <a class="btn btn-glass btn-sm" href="badge-print.php?id=<?= $b['id'] ?>&format=badge" target="_blank" title="Badge">🪪 Badge</a>
        <a class="btn btn-glass btn-sm" href="?edit=<?= $b['id'] ?>#form" title="Modifier">✏️</a>
        <form method="post" style="display:inline" data-confirm="Supprimer ce badge définitivement ?">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <button class="btn btn-glass btn-sm" name="supprimer" value="<?= $b['id'] ?>">🗑️</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<script>
function majType(){
  var externe = document.querySelector('input[name=type_porteur]:checked').value === 'externe';
  document.getElementById('zone-employe').style.display = externe ? 'none' : 'block';
  var ze = document.getElementById('zone-externe'); if(ze) ze.style.display = externe ? 'block' : 'none';
  document.getElementById('zone-org').style.display = externe ? 'block' : 'none';
  // Département et date d'embauche ne concernent pas les externes → grisés et vidés
  ['f-dep','f-emb'].forEach(function(id){
    var el = document.getElementById(id);
    if(el){ el.disabled = externe; if(externe) el.value=''; }
  });
  ['wrap-dep','wrap-emb'].forEach(function(id){
    var w = document.getElementById(id);
    if(w) w.classList.toggle('champ-grise', externe);
  });
}
function remplirEmploye(){
  var opt = document.getElementById('sel-employe').selectedOptions[0];
  if(!opt || !opt.value) return;
  var set = function(id,val){ var el=document.getElementById(id); if(el) el.value = val || ''; };
  set('f-nom', opt.dataset.nom);
  set('f-poste', opt.dataset.poste);
  set('f-tel', opt.dataset.tel);
  set('f-email', opt.dataset.email);
  set('f-dep', opt.dataset.dep);
  set('f-naiss', opt.dataset.naiss);
  set('f-emb', opt.dataset.emb);
  var gs=document.querySelector('[name=groupe_sanguin]'); if(gs) gs.value = opt.dataset.sang || '';
  // Rappel visuel du matricule qui sera repris
  var info=document.getElementById('mat-info');
  if(info) info.textContent = opt.dataset.mat ? ('Matricule repris : '+opt.dataset.mat) : 'Cet employé n\'a pas encore de matricule — un matricule sera généré.';
}
function remplirExterne(){
  var opt = document.getElementById('sel-externe').selectedOptions[0];
  if(!opt || !opt.value){ var i=document.getElementById('ext-info'); if(i) i.textContent=''; return; }
  var set = function(id,val){ var el=document.getElementById(id); if(el) el.value = val || ''; };
  set('f-nom', opt.dataset.nom);
  set('f-poste', opt.dataset.poste);
  set('f-tel', opt.dataset.tel);
  set('f-email', opt.dataset.email);
  var org=document.querySelector('[name=organisation]'); if(org) org.value = opt.dataset.org || '';
  var info=document.getElementById('ext-info');
  if(info) info.textContent = 'Référence reprise : '+opt.dataset.ref;
}
majType();
</script>

<?php admin_footer(); ?>
