<?php
require __DIR__ . '/config/db.php';
$s = get_settings($pdo);

$err = ''; $ok = false;
$prefill = trim($_GET['u'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $code     = strtoupper(trim($_POST['code'] ?? ''));
    $nouveau  = $_POST['nouveau'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    $st = $pdo->prepare("SELECT * FROM users WHERE username=? LIMIT 1");
    $st->execute([$username]);
    $u = $st->fetch();

    if (!$u || !$u['reset_code'] || strtoupper($u['reset_code']) !== $code) {
        $err = "Nom d'utilisateur ou code incorrect.";
    } elseif (empty($u['reset_expire']) || strtotime($u['reset_expire']) < time()) {
        $err = "Ce code a expiré. Demandez-en un nouveau à l'administrateur.";
    } elseif (strlen($nouveau) < 6) {
        $err = 'Le nouveau mot de passe doit contenir au moins 6 caractères.';
    } elseif ($nouveau !== $confirm) {
        $err = 'La confirmation ne correspond pas.';
    } else {
        $pdo->prepare("UPDATE users SET password=?, reset_code=NULL, reset_expire=NULL WHERE id=?")
            ->execute([password_hash($nouveau, PASSWORD_DEFAULT), $u['id']]);
        $ok = true;
    }
}
?><!DOCTYPE html>
<html lang="fr" data-space="app" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Réinitialiser le mot de passe — <?= e($s['nom_entreprise'] ?? '') ?></title>
<link rel="stylesheet" href="<?= asset('assets/css/glass.css') ?>">
<style>
  body{min-height:100vh;display:grid;place-items:center;padding:24px;background:#f4f6f9}
  .rz{width:100%;max-width:430px;background:#fff;border:1px solid #e8ecf2;border-radius:22px;
      padding:34px 30px;box-shadow:0 16px 44px rgba(10,31,68,.12)}
  .rz .logo{text-align:center;margin-bottom:14px}
  .rz .logo img{width:60px;height:60px;object-fit:contain}
  .rz h1{font-size:21px;color:#0a1f44;text-align:center;margin-bottom:6px}
  .rz .sub{text-align:center;color:#8a9ab5;font-size:13.5px;margin-bottom:22px}
  .rz .field{margin-bottom:14px}
  .rz label{display:block;font-size:13px;color:#4a5568;margin-bottom:6px;font-weight:600}
  .rz .input{width:100%}
  .rz .btn{width:100%;justify-content:center;margin-top:6px}
  .rz .back{display:block;text-align:center;margin-top:16px;color:#8a9ab5;font-size:13px;text-decoration:none}
  .flash{padding:12px 14px;border-radius:12px;margin-bottom:16px;font-size:13.5px}
  .flash.error{background:#fef2f2;color:#c0392b;border:1px solid rgba(226,75,74,.25)}
  .flash.ok{background:#eafaf3;color:#1e7a54;border:1px solid rgba(45,154,107,.25)}

  /* ===== MODE CLAIR ===== */
  [data-theme="light"] body{
    background:
      radial-gradient(46% 40% at 82% 4%, rgba(212,165,38,.14), transparent 60%),
      radial-gradient(52% 46% at 8% 12%, rgba(90,140,220,.16), transparent 62%),
      linear-gradient(175deg,#f6f8fc 0%,#eef2f9 50%,#f4f6fb 100%) !important;
    color:#1a2744;
  }
  [data-theme="light"] .login-card{
    background:linear-gradient(155deg, rgba(255,255,255,.35) 0%, rgba(255,255,255,.18) 100%) !important;
    border:1px solid rgba(255,255,255,.6) !important;
    box-shadow:0 24px 70px rgba(20,40,90,.18), inset 0 1px 0 rgba(255,255,255,.8) !important;
  }
  [data-theme="light"] .login-card h1{ color:#0a1f44 !important; }
  [data-theme="light"] .login-card .sub,
  [data-theme="light"] .login-card .lead{ color:#b8870f !important; }
  [data-theme="light"] .login-card .field label{ color:#2a3a58 !important; }
  [data-theme="light"] .login-card .input{
    background:rgba(255,255,255,.9) !important; color:#0a1f44 !important;
    border:1px solid rgba(20,40,90,.15) !important;
  }
  [data-theme="light"] .login-card .input::placeholder{ color:rgba(90,107,140,.55) !important; }
  [data-theme="light"] .login-card .input:focus{ border-color:rgba(184,135,15,.6) !important; box-shadow:0 0 0 3px rgba(184,135,15,.15) !important; }
  [data-theme="light"] .lg-logo-bg .lg-logo-water{ opacity:.28 !important; filter:drop-shadow(0 0 50px rgba(184,135,15,.4)) !important; }
  [data-theme="light"] .or-sep{ color:#2a3a58 !important; }
  [data-theme="light"] .or-sep::before,[data-theme="light"] .or-sep::after{ background:rgba(20,40,90,.12) !important; }
  [data-theme="light"] .back{ color:#2a3a58 !important; }
  [data-theme="light"] .back:hover{ color:#b8870f !important; }
  [data-theme="light"] .foot{ color:#2a3a58 !important; }
  [data-theme="light"] .seg{ background:rgba(20,40,90,.05) !important; border-color:rgba(20,40,90,.1) !important; }
  [data-theme="light"] .seg span{ color:#2a3a58 !important; }

  /* Bouton bascule clair/sombre — bien visible */
  .theme-toggle{
    width:44px;height:44px;border-radius:50%;display:grid;place-items:center;cursor:pointer;font-size:19px;
    background:linear-gradient(150deg, rgba(255,255,255,.16), rgba(255,255,255,.06));
    backdrop-filter:blur(30px) saturate(180%); -webkit-backdrop-filter:blur(30px) saturate(180%);
    border:1px solid rgba(255,255,255,.25);
    box-shadow:0 8px 24px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.3);
    transition:transform .3s cubic-bezier(.22,1,.36,1), box-shadow .3s}
  .theme-toggle:hover{transform:translateY(-2px) rotate(-12deg); box-shadow:0 12px 30px rgba(233,193,92,.35)}
  [data-theme="light"] .theme-toggle{
    background:linear-gradient(150deg, rgba(255,255,255,.9), rgba(255,255,255,.7));
    border:1px solid rgba(20,40,90,.12);
    box-shadow:0 8px 24px rgba(20,40,90,.15), inset 0 1px 0 rgba(255,255,255,.9)}
  /* toggle bien visible */
</style>
</head>
<body>
<div class="rz">
  <div class="logo"><?= logo_html('.', '') ?></div>
  <h1>Réinitialiser le mot de passe</h1>
  <p class="sub">Saisissez le code fourni par l'administrateur</p>

  <?php if ($ok): ?>
    <div class="flash ok">✅ Votre mot de passe a été réinitialisé. Vous pouvez maintenant vous connecter.</div>
    <a class="btn btn-gold" href="login.php">Aller à la connexion</a>
  <?php else: ?>
    <?php if ($err): ?><div class="flash error"><?= e($err) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <div class="field"><label>Nom d'utilisateur</label>
        <input class="input" name="username" value="<?= e($prefill) ?>" required autofocus></div>
      <div class="field"><label>Code de réinitialisation</label>
        <input class="input" name="code" placeholder="Ex : A1B2C3D4" required
               style="font-family:monospace;letter-spacing:.15em;text-transform:uppercase"></div>
      <div class="field"><label>Nouveau mot de passe</label>
        <input class="input" type="password" name="nouveau" required minlength="6"></div>
      <div class="field"><label>Confirmer le mot de passe</label>
        <input class="input" type="password" name="confirm" required minlength="6"></div>
      <button class="btn btn-gold">Réinitialiser</button>
    </form>
  <?php endif; ?>
  <a class="back" href="login.php">← Retour à la connexion</a>
</div>
</body>
</html>
