<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/badges.php';
if (!is_admin()) { flash("Réservé à l'administrateur.", 'error'); header('Location: index.php'); exit; }
$devise = $settings['devise'] ?? 'FCFA';

$modules = all_modules();
$attribuables = array_filter($modules, fn($m) => !$m[4] && !($m[5] ?? false)); // ni admin-only ni core

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Enregistrer les horaires de travail
    if (isset($_POST['maj_horaires'])) {
        $jours = array_values(array_intersect(['1','2','3','4','5','6','7'], $_POST['jours'] ?? []));
        $vals = [
            'work_hours_actif' => isset($_POST['work_actif']) ? '1' : '0',
            'work_jours' => implode(',', $jours) ?: '1,2,3,4,5,6',
            'work_debut' => preg_match('/^\d{2}:\d{2}$/', $_POST['work_debut'] ?? '') ? $_POST['work_debut'] : '08:00',
            'work_fin'   => preg_match('/^\d{2}:\d{2}$/', $_POST['work_fin'] ?? '') ? $_POST['work_fin'] : '17:00',
        ];
        $up = $pdo->prepare("INSERT INTO settings (cle,valeur) VALUES (?,?) ON DUPLICATE KEY UPDATE valeur=VALUES(valeur)");
        foreach ($vals as $k=>$v) $up->execute([$k,$v]);
        flash('Horaires d\'accès des employés mis à jour.');
        header('Location: employes.php'); exit;
    }

    // Supprimer définitivement un employé (les DONNÉES de l'entreprise restent)
    if (isset($_POST['supprimer'])) {
        $eid = (int)$_POST['supprimer'];
        // Récupérer le compte lié pour archiver son identité AVANT suppression
        $uid = (int)$pdo->query("SELECT id FROM users WHERE employe_id=" . $eid . " AND role='employe' LIMIT 1")->fetchColumn();
        if ($uid > 0) {
            archiver_membre($pdo, $uid); // garde le nom pour messages, forum, rapports…
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
        }
        // La fiche employé est retirée, mais messages/rapports/tâches/badges/documents demeurent.
        $pdo->prepare('DELETE FROM employes WHERE id=?')->execute([$eid]);
        flash('Employé supprimé. Toutes les données de l\'entreprise (messages, rapports, documents…) ont été conservées.');
        header('Location: employes.php'); exit;
    }
    // Activer / désactiver le compte
    if (isset($_POST['toggle_compte'])) {
        $uid = (int)$_POST['toggle_compte'];
        if ($uid !== (int)$_SESSION['admin_id'])
            $pdo->prepare("UPDATE users SET actif=1-actif WHERE id=? AND role='employe'")->execute([$uid]);
        flash('Statut du compte mis à jour.');
        header('Location: employes.php'); exit;
    }
    // Accès exceptionnel (hors horaires)
    if (isset($_POST['acces_exception'])) {
        $uid = (int)$_POST['acces_exception'];
        $mode = $_POST['exc_mode'] ?? 'jour';
        if ($mode === 'revoquer') {
            $pdo->prepare("UPDATE users SET acces_exception_until=NULL WHERE id=? AND role='employe'")->execute([$uid]);
            flash('Accès exceptionnel révoqué.');
        } else {
            // Déterminer la date de fin selon le mode choisi
            if ($mode === 'heures') {
                $h = max(1, min(72, (int)($_POST['exc_heures'] ?? 1)));  // 1 à 72 h
                $until = date('Y-m-d H:i:s', time() + $h * 3600);
            } elseif (preg_match('/^(\d+)h$/', $mode, $m)) {
                $until = date('Y-m-d H:i:s', time() + (int)$m[1] * 3600); // 1h, 2h, 4h…
            } elseif ($mode === 'custom' && !empty($_POST['exc_until'])) {
                $until = date('Y-m-d H:i:s', strtotime($_POST['exc_until']));
            } else { // 'jour' : jusqu'à la fin de la journée
                $until = date('Y-m-d 23:59:59');
            }
            $pdo->prepare("UPDATE users SET acces_exception_until=? WHERE id=? AND role='employe'")->execute([$until, $uid]);
            flash('Accès accordé jusqu\'au ' . date('d/m/Y à H:i', strtotime($until)) . '.');
        }
        header('Location: employes.php'); exit;
    }

    // Enregistrer un employé + son compte
    $id  = (int)($_POST['id'] ?? 0);
    $nom = trim($_POST['nom'] ?? '');
    if ($nom === '') { flash('Le nom est obligatoire.', 'error'); header('Location: employes.php'.($id?'?edit='.$id:'')); exit; }

    // Matricule : généré automatiquement à la création, sinon conservé.
    require_once __DIR__ . '/includes/badges.php';
    $matricule = trim($_POST['matricule'] ?? '');
    if ($matricule === '') {
        if ($id) {
            $matricule = (string)$pdo->query("SELECT matricule FROM employes WHERE id=$id")->fetchColumn();
        }
        if ($matricule === '' || $matricule === null) {
            $matricule = badge_generer_matricule($pdo, $settings, 'employe');
        }
    }

    // Photo employé (utilisée aussi pour badge/carte)
    $photoEmp = null;
    if (!empty($_FILES['photo']['name'])) $photoEmp = upload_image_redim($_FILES['photo'], UPLOAD_DIR, 400, 480, 'cover');

    $empData = [
        mb_substr($nom, 0, 120),
        mb_substr(trim($_POST['poste'] ?? ''), 0, 100),
        mb_substr($matricule, 0, 40),
        mb_substr(trim($_POST['categorie'] ?? ''), 0, 60),
        mb_substr(trim($_POST['departement'] ?? ''), 0, 120),
        mb_substr(trim($_POST['groupe_sanguin'] ?? ''), 0, 8),
        ($_POST['date_naissance'] ?? '') ?: null,
        mb_substr(trim($_POST['telephone'] ?? ''), 0, 30),
        mb_substr(trim($_POST['email'] ?? ''), 0, 120),
        mb_substr(trim($_POST['numero_cnps'] ?? ''), 0, 60),
        mb_substr(trim($_POST['banque'] ?? ''), 0, 120),
        mb_substr(trim($_POST['numero_compte'] ?? ''), 0, 60),
        max(0, (float)($_POST['salaire_base'] ?? 0)),
        ($_POST['date_embauche'] ?? '') ?: null,
        isset($_POST['actif']) ? 1 : 0,
    ];
    if ($id) {
        // La photo n'est mise à jour que si une nouvelle est fournie
        if ($photoEmp) {
            $anc = $pdo->query("SELECT photo FROM employes WHERE id=$id")->fetchColumn();
            if ($anc && is_file(UPLOAD_DIR.'/'.$anc)) @unlink(UPLOAD_DIR.'/'.$anc);
            $pdo->prepare('UPDATE employes SET nom=?,poste=?,matricule=?,categorie=?,departement=?,groupe_sanguin=?,date_naissance=?,telephone=?,email=?,numero_cnps=?,banque=?,numero_compte=?,salaire_base=?,date_embauche=?,actif=?,photo=? WHERE id=?')
                ->execute([...$empData, $photoEmp, $id]);
        } else {
            $pdo->prepare('UPDATE employes SET nom=?,poste=?,matricule=?,categorie=?,departement=?,groupe_sanguin=?,date_naissance=?,telephone=?,email=?,numero_cnps=?,banque=?,numero_compte=?,salaire_base=?,date_embauche=?,actif=? WHERE id=?')
                ->execute([...$empData, $id]);
        }
        $eid = $id;
    } else {
        $pdo->prepare('INSERT INTO employes (nom,poste,matricule,categorie,departement,groupe_sanguin,date_naissance,telephone,email,numero_cnps,banque,numero_compte,salaire_base,date_embauche,actif,photo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([...$empData, $photoEmp]);
        $eid = (int)$pdo->lastInsertId();
    }

    // Compte de connexion (facultatif)
    $username = preg_replace('/[^a-z0-9._-]/', '', strtolower(trim($_POST['username'] ?? '')));
    $pass     = $_POST['password'] ?? '';
    $perms    = array_values(array_intersect(array_keys($attribuables), $_POST['perms'] ?? []));
    $permsJson = json_encode($perms);

    // Compte déjà lié ?
    $stmt = $pdo->prepare("SELECT id FROM users WHERE employe_id=? AND role='employe'");
    $stmt->execute([$eid]); $existingUid = $stmt->fetchColumn();

    if ($username !== '') {
        // unicité
        $chk = $pdo->prepare('SELECT id FROM users WHERE username=? AND id<>?');
        $chk->execute([$username, (int)($existingUid ?: 0)]);
        if ($chk->fetch()) { flash('Cet identifiant est déjà pris.', 'error'); header('Location: employes.php?edit='.$eid); exit; }

        if ($existingUid) {
            $pdo->prepare('UPDATE users SET nom=?, username=?, permissions=? WHERE id=?')
                ->execute([mb_substr($nom,0,100), mb_substr($username,0,50), $permsJson, $existingUid]);
            if ($pass !== '') {
                if (strlen($pass) < 6) { flash('Mot de passe : 6 caractères minimum.', 'error'); header('Location: employes.php?edit='.$eid); exit; }
                $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $existingUid]);
            }
            flash('Employé et compte mis à jour.');
        } else {
            if (strlen($pass) < 6) { flash('Pour créer le compte, indiquez un mot de passe (6 caractères min.).', 'error'); header('Location: employes.php?edit='.$eid); exit; }
            $pdo->prepare("INSERT INTO users (username,password,nom,role,permissions,employe_id,actif) VALUES (?,?,?,'employe',?,?,1)")
                ->execute([mb_substr($username,0,50), password_hash($pass, PASSWORD_DEFAULT), mb_substr($nom,0,100), $permsJson, $eid]);
            flash('Employé enregistré et compte créé. Communiquez-lui son identifiant et son mot de passe.');
        }
    } else {
        // pas de username fourni : si un compte existe, on met juste à jour ses permissions
        if ($existingUid) {
            $pdo->prepare('UPDATE users SET nom=?, permissions=? WHERE id=?')->execute([mb_substr($nom,0,100), $permsJson, $existingUid]);
        }
        flash('Employé enregistré.');
    }
    header('Location: employes.php'); exit;
}

// Chargement pour édition
$edit = null; $compte = null; $edit_perms = [];
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM employes WHERE id=?'); $stmt->execute([(int)$_GET['edit']]); $edit = $stmt->fetch();
    if ($edit) {
        $c = $pdo->prepare("SELECT * FROM users WHERE employe_id=? AND role='employe'"); $c->execute([$edit['id']]); $compte = $c->fetch();
        $edit_perms = $compte && $compte['permissions'] ? (json_decode($compte['permissions'], true) ?: []) : [];
    }
}

$rows = $pdo->query("SELECT e.*, u.id AS uid, u.username, u.actif AS compte_actif, u.permissions, u.acces_exception_until
                     FROM employes e LEFT JOIN users u ON u.employe_id=e.id AND u.role='employe'
                     ORDER BY e.actif DESC, e.nom")->fetchAll();

admin_header('Employés & accès', 'employes', $pdo, $settings);
$wJours = array_filter(array_map('intval', explode(',', $settings['work_jours'] ?? '1,2,3,4,5,6')));
$joursNoms = [1=>'Lun',2=>'Mar',3=>'Mer',4=>'Jeu',5=>'Ven',6=>'Sam',7=>'Dim'];
?>
<div class="panel glass" style="border-left:4px solid var(--gold)">
  <h2>🕐 Horaires d'accès des employés</h2>
  <p style="color:var(--ink-dim);font-size:13.5px;margin:-4px 0 14px">En dehors de ces horaires, les employés ne peuvent pas accéder à leur espace de travail. (Ne concerne pas l'administrateur ni les clients.)</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="maj_horaires" value="1">
    <label class="switch" style="margin-bottom:14px"><input type="checkbox" name="work_actif" <?= ($settings['work_hours_actif'] ?? '1')==='1'?'checked':'' ?>> Activer la restriction horaire</label>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
      <?php foreach ($joursNoms as $n=>$lib): ?>
      <label class="jour-chip"><input type="checkbox" name="jours[]" value="<?= $n ?>" <?= in_array($n,$wJours,true)?'checked':'' ?>><span><?= $lib ?></span></label>
      <?php endforeach; ?>
    </div>
    <div class="form-grid" style="max-width:420px">
      <div class="field"><label>Heure de début</label><input class="input" type="time" name="work_debut" value="<?= e($settings['work_debut'] ?? '08:00') ?>"></div>
      <div class="field"><label>Heure de fin</label><input class="input" type="time" name="work_fin" value="<?= e($settings['work_fin'] ?? '17:00') ?>"></div>
    </div>
    <button class="btn btn-gold" style="margin-top:16px">Enregistrer les horaires</button>
  </form>
</div>
<?php
?>
<details class="panel glass panel-pliable" id="form" <?= $edit ? 'open' : '' ?>>
  <summary class="panel-titre"><?= $edit ? '✏️ Modifier : ' . e($edit['nom']) : '➕ Nouvel employé' ?><span class="chev">▾</span>
  </summary>
  <?php if ($edit): ?><p style="margin:0 0 10px"><a href="employes.php" class="btn btn-glass btn-sm">Annuler la modification</a></p><?php endif; ?>
  <p style="color:var(--ink-faint);font-size:13.5px;margin:-6px 0 16px">Fiche de l'employé, accès à l'espace et permissions : tout se gère ici, au même endroit.</p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">

    <h3 class="form-section">🧑‍🍳 Informations de l'employé</h3>
    <div class="form-grid">
      <div class="field"><label>Nom complet *</label><input class="input" name="nom" required value="<?= e($edit['nom'] ?? '') ?>"></div>
      <div class="field"><label>Poste</label><input class="input" name="poste" value="<?= e($edit['poste'] ?? '') ?>"></div>
      <div class="field"><label>Matricule <span style="color:var(--ink-faint);font-weight:400">— généré automatiquement si vide</span></label>
        <input class="input champ-auto" name="matricule" readonly value="<?= e($edit['matricule'] ?? '') ?>" placeholder="<?= $edit ? '' : 'Généré automatiquement à l\'enregistrement' ?>">
        <div style="margin-top:5px;font-size:12px;color:var(--ink-faint)">Le matricule est attribué automatiquement, il n'est pas modifiable.</div></div>
      <div class="field"><label>Département / Service</label><input class="input" name="departement" value="<?= e($edit['departement'] ?? '') ?>"></div>
      <div class="field"><label>Catégorie / Classification</label><input class="input" name="categorie" value="<?= e($edit['categorie'] ?? '') ?>" placeholder="ex : Cat. 3, Agent de maîtrise"></div>
      <div class="field"><label>Groupe sanguin</label>
        <select class="input" name="groupe_sanguin">
          <option value="">—</option>
          <?php foreach (['O+','O-','A+','A-','B+','B-','AB+','AB-'] as $gs): ?>
          <option value="<?= $gs ?>" <?= ($edit['groupe_sanguin'] ?? '')===$gs?'selected':'' ?>><?= $gs ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Date de naissance</label><input class="input" type="date" name="date_naissance" value="<?= e($edit['date_naissance'] ?? '') ?>"></div>
      <div class="field"><label>Salaire de base (<?= e($devise) ?>)</label><input class="input" type="number" name="salaire_base" min="0" step="1000" value="<?= e($edit['salaire_base'] ?? '') ?>"></div>
      <div class="field"><label>Date d'embauche</label><input class="input" type="date" name="date_embauche" value="<?= e($edit['date_embauche'] ?? '') ?>"></div>
      <div class="field"><label>Téléphone</label><input class="input" name="telephone" value="<?= e($edit['telephone'] ?? '') ?>"></div>
      <div class="field"><label>E-mail</label><input class="input" type="email" name="email" value="<?= e($edit['email'] ?? '') ?>"></div>
      <div class="field full"><label>Photo <span style="color:var(--ink-faint);font-weight:400">— pour le badge et la carte (portrait)</span></label>
        <input class="input" type="file" name="photo" accept="image/*" data-redim="400x480" data-redim-mode="cover">
        <?php if (!empty($edit['photo'])): ?><p style="font-size:12px;color:var(--ink-faint);margin-top:6px">Photo actuelle conservée si vous n'en choisissez pas une nouvelle.</p><?php endif; ?>
      </div>
      <div class="field"><label>N° CNPS</label><input class="input" name="numero_cnps" value="<?= e($edit['numero_cnps'] ?? '') ?>"></div>
      <div class="field"><label>Banque (pour la paie)</label><input class="input" name="banque" value="<?= e($edit['banque'] ?? '') ?>"></div>
      <div class="field"><label>N° de compte / RIB</label><input class="input" name="numero_compte" value="<?= e($edit['numero_compte'] ?? '') ?>"></div>
      <label class="switch"><input type="checkbox" name="actif" <?= ($edit['actif'] ?? 1) ? 'checked' : '' ?>> Employé actif</label>
    </div>

    <h3 class="form-section">🔐 Accès à l'espace <?= $compte ? '<span class="badge '.($compte['actif']?'badge-teal':'badge-danger').'">'.($compte['actif']?'Compte actif':'Compte désactivé').'</span>' : '' ?></h3>
    <p style="color:var(--ink-faint);font-size:12.5px;margin:-4px 0 14px">Renseignez un identifiant et un mot de passe pour donner à l'employé un accès à son espace. Laissez vide si l'employé n'a pas besoin de se connecter.</p>
    <div class="form-grid">
      <div class="field"><label>Identifiant de connexion</label><input class="input" name="username" value="<?= e($compte['username'] ?? '') ?>" placeholder="ex : grace" pattern="[a-zA-Z0-9._-]*"></div>
      <div class="field"><label>Mot de passe <?= $compte ? '(laisser vide = inchangé)' : '' ?></label><input class="input" type="text" name="password" placeholder="6 caractères min." autocomplete="new-password"></div>
    </div>

    <h3 class="form-section">🗂️ Sections autorisées</h3>
    <p style="color:var(--ink-faint);font-size:12.5px;margin:-4px 0 14px">Le tableau de bord, la messagerie, le forum, les tâches et les rapports sont toujours accessibles. Cochez les autres sections auxquelles l'employé aura accès.</p>
    <div class="perms-grid">
      <?php foreach (groupes_modules() as $g=>$gl):
        $items = array_filter($attribuables, fn($m)=>$m[3]===$g); if(!$items) continue; ?>
      <div class="perms-col">
        <div class="perms-title"><?= $gl ?></div>
        <?php foreach ($items as $k=>[$label,$ico]): ?>
        <label class="perm-item"><input type="checkbox" name="perms[]" value="<?= $k ?>" <?= in_array($k,$edit_perms,true)?'checked':'' ?>><span><?= $ico ?> <?= e($label) ?></span></label>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <div style="display:flex;gap:10px;margin-top:20px;flex-wrap:wrap">
      <button class="btn btn-gold"><?= $edit ? 'Enregistrer' : 'Ajouter l\'employé' ?></button>
      <label class="btn btn-glass btn-sm" style="cursor:pointer" onclick="document.querySelectorAll('.perms-grid input').forEach(c=>c.checked=true)">Tout cocher</label>
      <label class="btn btn-glass btn-sm" style="cursor:pointer" onclick="document.querySelectorAll('.perms-grid input').forEach(c=>c.checked=false)">Tout décocher</label>
    </div>
  </form>
</details>

<div class="panel glass tbl-equipe">
  <h2>🧑‍🍳 Équipe (<?= count($rows) ?>)</h2>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Nom</th><th>Poste</th><th>Salaire base</th><th>Accès</th><th>Statut</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): $p = $r['permissions'] ? (json_decode($r['permissions'],true) ?: []) : []; ?>
        <tr>
          <td><span class="emp-nom"><?= e($r['nom']) ?></span><?= $r['matricule'] ? '<span class="emp-mat">'.e($r['matricule']).'</span>' : '' ?></td>
          <td><?= e($r['poste'] ?: '—') ?></td>
          <td><strong><?= money($r['salaire_base'], $devise) ?></strong></td>
          <td>
            <?php if ($r['uid']): ?>
              <code><?= e($r['username']) ?></code>
              <span class="badge <?= $r['compte_actif']?'badge-teal':'badge-danger' ?>" style="font-size:10px"><?= $r['compte_actif']?'actif':'désactivé' ?></span>
              <?php if ($p): $libs = implode(', ', array_map(fn($k)=>$modules[$k][0]??$k, $p)); ?>
                <br><small style="color:var(--ink-faint)" title="<?= e($libs) ?>"><?= count($p) ?> section<?= count($p)>1?'s':'' ?> ⓘ</small>
              <?php endif; ?>
            <?php else: ?><small style="color:var(--ink-faint)">Pas de compte</small><?php endif; ?>
          </td>
          <td><span class="badge <?= $r['actif'] ? 'badge-teal' : 'badge-danger' ?>"><?= $r['actif'] ? 'Actif' : 'Inactif' ?></span></td>
          <td>
            <div class="td-actions">
              <a class="btn btn-glass btn-sm" href="paie.php?edit=new&employe=<?= $r['id'] ?>" title="Bulletin de paie">📄</a>
              <a class="btn btn-glass btn-sm" href="?edit=<?= $r['id'] ?>#form" title="Modifier">✏️</a>
              <?php if ($r['uid']): ?>
              <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <button class="btn btn-glass btn-sm" name="toggle_compte" value="<?= $r['uid'] ?>" title="<?= $r['compte_actif']?'Désactiver l\'accès':'Réactiver l\'accès' ?>"><?= $r['compte_actif']?'⏸️':'▶️' ?></button></form>
              <?php $excActif = $r['acces_exception_until'] && strtotime($r['acces_exception_until']) >= time(); ?>
              <details class="exc-menu" style="position:relative;display:inline-block">
                <summary class="btn btn-sm <?= $excActif?'btn-gold':'btn-glass' ?>" title="Accorder un accès hors horaires">🕑</summary>
                <div class="glass exc-pop">
                  <div class="exc-pop-titre">⏱️ Accès hors horaires</div>
                  <?php if ($excActif): ?>
                  <p class="exc-actif">✓ Autorisé jusqu'au <strong><?= date('d/m à H:i', strtotime($r['acces_exception_until'])) ?></strong></p>
                  <?php else: ?>
                  <p class="exc-hint">Accordez à cet employé un accès temporaire pour un travail urgent :</p>
                  <?php endif; ?>
                  <form method="post" class="exc-form">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="acces_exception" value="<?= $r['uid'] ?>">
                    <div class="exc-rapide">
                      <button class="btn btn-glass btn-sm" name="exc_mode" value="1h">1 h</button>
                      <button class="btn btn-glass btn-sm" name="exc_mode" value="2h">2 h</button>
                      <button class="btn btn-glass btn-sm" name="exc_mode" value="4h">4 h</button>
                      <button class="btn btn-glass btn-sm" name="exc_mode" value="jour">Journée</button>
                    </div>
                    <div class="exc-perso">
                      <label>Durée précise :</label>
                      <div class="exc-perso-row">
                        <input class="input" type="number" name="exc_heures" min="1" max="72" step="1" placeholder="Ex : 3">
                        <span>heure(s)</span>
                        <button class="btn btn-gold btn-sm" name="exc_mode" value="heures">Accorder</button>
                      </div>
                    </div>
                    <?php if ($excActif): ?>
                    <button class="btn btn-danger btn-sm exc-revoq" name="exc_mode" value="revoquer">Révoquer l'accès</button>
                    <?php endif; ?>
                  </form>
                </div>
              </details>
              <?php endif; ?>
              <form method="post" data-confirm="Supprimer « <?= e($r['nom']) ?> » et son accès ?">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <button class="btn btn-danger btn-sm" name="supprimer" value="<?= $r['id'] ?>">✕</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" style="text-align:center;padding:30px;color:var(--ink-faint)">Aucun employé.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php admin_footer(); ?>
