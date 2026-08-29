<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';

// Harmonisation unique : tout client avec une raison sociale devient de type « entreprise »
// (corrige les fiches créées avant l'ajout du type de client).
try {
    $pdo->query("UPDATE clients SET type_client='entreprise' WHERE entreprise IS NOT NULL AND entreprise <> '' AND type_client <> 'entreprise'");
} catch (\Throwable $e) { /* sans effet si la colonne n'existe pas encore */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (isset($_POST['toggle_compte'])) {
        $uid = (int)$_POST['toggle_compte'];
        $pdo->prepare("UPDATE users SET actif=1-actif WHERE id=? AND role='client'")->execute([$uid]);
        flash('Statut de l\'accès client mis à jour.');
        header('Location: clients.php'); exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    $nom = trim($_POST['nom'] ?? '');
    if ($nom === '') { flash('Le nom du client est obligatoire.', 'error'); header('Location: clients.php'); exit; }

    $entreprise = mb_substr(trim($_POST['entreprise'] ?? ''), 0, 150);
    // Type : « entreprise » si un nom d'entreprise est renseigné (ou choix explicite), sinon « individuel »
    $typeClient = (($_POST['type_client'] ?? '') === 'entreprise' || $entreprise !== '') ? 'entreprise' : 'individuel';

    $data = [
        mb_substr($nom, 0, 120),
        $entreprise,
        $typeClient,
        mb_substr(trim($_POST['telephone'] ?? ''), 0, 30),
        mb_substr(trim($_POST['email'] ?? ''), 0, 120),
        mb_substr(trim($_POST['adresse'] ?? ''), 0, 255),
        mb_substr(trim($_POST['ncc'] ?? ''), 0, 60),
        mb_substr(trim($_POST['notes'] ?? ''), 0, 1000),
    ];
    if ($id) {
        $pdo->prepare('UPDATE clients SET nom=?, entreprise=?, type_client=?, telephone=?, email=?, adresse=?, ncc=?, notes=? WHERE id=?')->execute([...$data, $id]);
        $cid = $id; flash('Client modifié.');
    } else {
        $pdo->prepare('INSERT INTO clients (nom, entreprise, type_client, telephone, email, adresse, ncc, notes) VALUES (?,?,?,?,?,?,?,?)')->execute($data);
        $cid = (int)$pdo->lastInsertId(); flash('Client ajouté au fichier clients.');
    }

    // Accès à l'espace client (facultatif)
    $username = preg_replace('/[^a-z0-9._-]/', '', strtolower(trim($_POST['username'] ?? '')));
    $pass = $_POST['password'] ?? '';
    $c = $pdo->prepare("SELECT id FROM users WHERE client_id=? AND role='client'");
    $c->execute([$cid]); $existingUid = $c->fetchColumn();

    if ($username !== '') {
        $chk = $pdo->prepare('SELECT id FROM users WHERE username=? AND id<>?');
        $chk->execute([$username, (int)($existingUid ?: 0)]);
        if ($chk->fetch()) { flash('Cet identifiant est déjà pris.', 'error'); header('Location: clients.php?edit='.$cid); exit; }
        if ($existingUid) {
            $pdo->prepare('UPDATE users SET nom=?, username=? WHERE id=?')->execute([mb_substr($nom,0,100), mb_substr($username,0,50), $existingUid]);
            if ($pass !== '') {
                if (strlen($pass) < 6) { flash('Mot de passe : 6 caractères minimum.', 'error'); header('Location: clients.php?edit='.$cid); exit; }
                $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $existingUid]);
            }
            flash('Client et accès mis à jour.');
        } else {
            if (strlen($pass) < 6) { flash('Pour créer l\'accès, indiquez un mot de passe (6 caractères min.).', 'error'); header('Location: clients.php?edit='.$cid); exit; }
            $pdo->prepare("INSERT INTO users (username,password,nom,role,client_id,actif) VALUES (?,?,?,'client',?,1)")
                ->execute([mb_substr($username,0,50), password_hash($pass, PASSWORD_DEFAULT), mb_substr($nom,0,100), $cid]);
            flash('Client enregistré et accès à l\'espace créé. Communiquez-lui son identifiant et son mot de passe.');
        }
    }
    header('Location: clients.php'); exit;
}

$edit = null; $compte = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM clients WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch();
    if ($edit) { $c = $pdo->prepare("SELECT * FROM users WHERE client_id=? AND role='client'"); $c->execute([$edit['id']]); $compte = $c->fetch(); }
}
$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $stmt = $pdo->prepare("SELECT c.*, u.id AS uid, u.username, u.actif AS compte_actif FROM clients c LEFT JOIN users u ON u.client_id=c.id AND u.role='client' WHERE c.nom LIKE ? OR c.entreprise LIKE ? OR c.telephone LIKE ? ORDER BY c.nom");
    $stmt->execute(["%$q%", "%$q%", "%$q%"]);
    $clients = $stmt->fetchAll();
} else {
    $clients = $pdo->query("SELECT c.*, u.id AS uid, u.username, u.actif AS compte_actif FROM clients c LEFT JOIN users u ON u.client_id=c.id AND u.role='client' ORDER BY c.nom")->fetchAll();
}

admin_header('Clients', 'clients', $pdo, $settings);
?>
<details class="panel glass panel-pliable" id="form" <?= $edit ? 'open' : '' ?>>
  <summary class="panel-titre"><?= $edit ? '✏️ Modifier : ' . e($edit['nom']) : '➕ Nouveau client' ?><span class="chev">▾</span></summary>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
    <div class="field"><label>Type de client</label>
      <select class="input" name="type_client" id="type_client">
        <option value="individuel" <?= (($edit['type_client'] ?? '') !== 'entreprise') ? 'selected' : '' ?>>👤 Particulier</option>
        <option value="entreprise" <?= (($edit['type_client'] ?? '') === 'entreprise') ? 'selected' : '' ?>>🏢 Entreprise</option>
      </select>
    </div>
    <div class="field"><label>Nom du contact *</label><input class="input" name="nom" required value="<?= e($edit['nom'] ?? '') ?>"></div>
    <div class="field"><label>Nom de l'entreprise <span style="color:var(--ink-faint);font-weight:400">(si entreprise)</span></label><input class="input" name="entreprise" id="entreprise_champ" value="<?= e($edit['entreprise'] ?? '') ?>" placeholder="Ex : Hôtel Ivoire"></div>
    <div class="field"><label>Téléphone</label><input class="input" name="telephone" value="<?= e($edit['telephone'] ?? '') ?>"></div>
    <div class="field"><label>E-mail</label><input class="input" type="email" name="email" value="<?= e($edit['email'] ?? '') ?>"></div>
    <div class="field"><label>Adresse</label><input class="input" name="adresse" value="<?= e($edit['adresse'] ?? '') ?>"></div>
    <div class="field"><label>N° Compte Contribuable (NCC)</label><input class="input" name="ncc" value="<?= e($edit['ncc'] ?? '') ?>"></div>
    <div class="field full"><label>Notes</label><textarea class="input" name="notes" style="min-height:70px"><?= e($edit['notes'] ?? '') ?></textarea></div>

    <div class="full"><h3 class="form-section">🔐 Accès à l'espace client <?= $compte ? '<span class="badge '.($compte['actif']?'badge-teal':'badge-danger').'">'.($compte['actif']?'Actif':'Désactivé').'</span>' : '' ?></h3></div>
    <div class="field"><label>Identifiant de connexion</label><input class="input" name="username" value="<?= e($compte['username'] ?? '') ?>" placeholder="ex : client-konan" pattern="[a-zA-Z0-9._-]*"></div>
    <div class="field"><label>Mot de passe <?= $compte ? '(laisser vide = inchangé)' : '' ?></label><input class="input" type="text" name="password" placeholder="6 caractères min." autocomplete="new-password"></div>
    <div class="field full" style="color:var(--ink-faint);font-size:12.5px;margin-top:-6px">Renseignez un identifiant et un mot de passe pour donner au client l'accès à son espace (ses factures, devis, reçus et la possibilité de laisser un avis). Laissez vide si inutile.</div>

    <div class="full" style="display:flex;gap:10px">
      <button class="btn btn-gold"><?= $edit ? 'Enregistrer' : 'Ajouter le client' ?></button>
      <?php if ($edit): ?><a class="btn btn-glass" href="clients.php">Annuler</a><?php endif; ?>
    </div>
  </form>
</details>

<div class="panel glass">
  <h2>👥 Fichier clients (<?= count($clients) ?>)
    <form method="get" style="margin-left:auto;display:flex;gap:8px">
      <input class="input" name="q" value="<?= e($q) ?>" placeholder="Rechercher…" style="padding:8px 14px;width:200px">
      <button class="btn btn-glass btn-sm">🔍</button>
    </form>
  </h2>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Client</th><th>Entreprise</th><th>Téléphone</th><th>Accès espace</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        <?php foreach ($clients as $c): ?>
        <tr>
          <td><strong><?= e($c['nom']) ?></strong></td>
          <td><?= e($c['entreprise'] ?: '—') ?></td>
          <td><?= e($c['telephone'] ?: '—') ?></td>
          <td>
            <?php if ($c['uid']): ?><code><?= e($c['username']) ?></code> <span class="badge <?= $c['compte_actif']?'badge-teal':'badge-danger' ?>" style="font-size:10px"><?= $c['compte_actif']?'actif':'désactivé' ?></span>
            <?php else: ?><small style="color:var(--ink-faint)">Aucun accès</small><?php endif; ?>
          </td>
          <td>
            <div class="td-actions">
              <?php if (preg_replace('/\D/', '', $c['telephone'])): ?>
              <a class="btn btn-glass btn-sm" href="https://wa.me/<?= preg_replace('/\D/', '', $c['telephone']) ?>" target="_blank" rel="noopener">💬</a>
              <?php endif; ?>
              <a class="btn btn-glass btn-sm" href="factures.php?edit=new&client=<?= $c['id'] ?>">🧾 Facturer</a>
              <a class="btn btn-glass btn-sm" href="?edit=<?= $c['id'] ?>#form">✏️</a>
              <?php if ($c['uid']): ?>
              <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <button class="btn btn-glass btn-sm" name="toggle_compte" value="<?= $c['uid'] ?>" title="<?= $c['compte_actif']?'Désactiver l\'accès':'Réactiver l\'accès' ?>"><?= $c['compte_actif']?'⏸️':'▶️' ?></button></form>
              <?php endif; ?>
              <form method="post" data-confirm="Supprimer « <?= e($c['nom']) ?> » et son accès ?">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <button class="btn btn-danger btn-sm" name="supprimer" value="<?= $c['id'] ?>">✕</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$clients): ?><tr><td colspan="5" style="text-align:center;padding:30px;color:var(--ink-faint)">Aucun client<?= $q ? ' trouvé' : '' ?>. Ajoutez votre premier client ci-dessus.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php admin_footer(); ?>
