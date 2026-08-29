<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';

$uid = (int)$_SESSION['admin_id'];
$u = $pdo->query("SELECT * FROM users WHERE id=$uid")->fetch();
if (!$u) { header('Location: index.php'); exit; }

$bienvenue = isset($_GET['bienvenue']);
$err = ''; $ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (isset($_POST['maj_profil'])) {
        $nom   = trim($_POST['nom'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $tel   = trim($_POST['telephone'] ?? '');
        $username = trim($_POST['username'] ?? '');
        if ($nom === '' || $username === '') {
            $err = "Le nom et le nom d'utilisateur sont obligatoires.";
        } else {
            /* unicité du username */
            $st = $pdo->prepare("SELECT 1 FROM users WHERE username=? AND id<>?");
            $st->execute([$username, $uid]);
            if ($st->fetchColumn()) {
                $err = "Ce nom d'utilisateur est déjà utilisé.";
            } else {
                $pdo->prepare("UPDATE users SET nom=?, email=?, telephone=?, username=?, profil_complet=1 WHERE id=?")
                    ->execute([$nom, $email, $tel, $username, $uid]);
                $_SESSION['admin_nom'] = $nom;
                $ok = 'Profil mis à jour.';
                $u = $pdo->query("SELECT * FROM users WHERE id=$uid")->fetch();
            }
        }
    }

    if (isset($_POST['maj_mdp'])) {
        $actuel = $_POST['actuel'] ?? '';
        $nouveau = $_POST['nouveau'] ?? '';
        $confirm = $_POST['confirm'] ?? '';
        $aMotDePasse = trim((string)$u['password']) !== '';
        if ($aMotDePasse && !password_verify($actuel, $u['password'])) {
            $err = 'Mot de passe actuel incorrect.';
        } elseif (strlen($nouveau) < 6) {
            $err = 'Le nouveau mot de passe doit contenir au moins 6 caractères.';
        } elseif ($nouveau !== $confirm) {
            $err = 'La confirmation ne correspond pas.';
        } else {
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")
                ->execute([password_hash($nouveau, PASSWORD_DEFAULT), $uid]);
            $ok = 'Mot de passe modifié.';
            $u = $pdo->query("SELECT * FROM users WHERE id=$uid")->fetch();
        }
    }
}

$aMotDePasse = trim((string)$u['password']) !== '';
admin_header('Mon profil', '', $pdo, $settings);
?>
<?php if ($bienvenue): ?>
<div class="panel glass" style="border-left:4px solid var(--gold)">
  <h2 style="border:0;margin:0 0 6px">👋 Bienvenue <?= e($u['nom']) ?> !</h2>
  <p style="color:var(--ink-dim)">Votre compte a été créé via Google. Complétez vos informations ci-dessous pour finaliser votre profil.</p>
</div>
<?php endif; ?>

<?php if ($err): ?><div class="flash error"><?= e($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="flash"><?= e($ok) ?></div><?php endif; ?>

<div class="panel glass">
  <h2>👤 Mes informations</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div class="form-grid">
      <div class="field"><label>Nom complet *</label><input class="input" name="nom" value="<?= e($u['nom']) ?>" required></div>
      <div class="field"><label>Nom d'utilisateur *</label><input class="input" name="username" value="<?= e($u['username']) ?>" required></div>
      <div class="field"><label>Email</label><input class="input" type="email" name="email" value="<?= e($u['email'] ?? '') ?>"></div>
      <div class="field"><label>Téléphone</label><input class="input" name="telephone" value="<?= e($u['telephone'] ?? '') ?>"></div>
    </div>
    <button class="btn btn-gold" name="maj_profil" value="1" style="margin-top:8px">Enregistrer mes informations</button>
  </form>
</div>

<div class="panel glass">
  <h2>🔒 <?= $aMotDePasse ? 'Changer mon mot de passe' : 'Définir un mot de passe' ?></h2>
  <?php if (!$aMotDePasse): ?>
  <p style="color:var(--ink-dim);font-size:13.5px;margin-bottom:12px">Votre compte utilise Google pour se connecter. Vous pouvez définir un mot de passe pour vous connecter aussi de façon classique.</p>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div class="form-grid">
      <?php if ($aMotDePasse): ?>
      <div class="field"><label>Mot de passe actuel</label><input class="input" type="password" name="actuel" required></div>
      <?php endif; ?>
      <div class="field"><label>Nouveau mot de passe</label><input class="input" type="password" name="nouveau" required minlength="6"></div>
      <div class="field"><label>Confirmer</label><input class="input" type="password" name="confirm" required minlength="6"></div>
    </div>
    <button class="btn btn-gold" name="maj_mdp" value="1" style="margin-top:8px"><?= $aMotDePasse ? 'Changer le mot de passe' : 'Définir le mot de passe' ?></button>
  </form>
</div>

<?php admin_footer(); ?>
