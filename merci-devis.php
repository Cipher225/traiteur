<?php
require __DIR__ . '/config/db.php';
$settings = get_settings($pdo);
$ent = $settings['nom_entreprise'] ?? 'Groupe Helisce';

$data = $_SESSION['devis_ok'] ?? null;
if (!$data) { header('Location: index.php'); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['creer_compte'])) {
    csrf_check();
    $username = preg_replace('/[^a-z0-9._@+-]/', '', strtolower(trim($_POST['username'] ?? '')));
    $pass = $_POST['password'] ?? '';
    if ($username === '' || strlen($pass) < 6) {
        $err = 'Choisissez un identifiant et un mot de passe d\'au moins 6 caractères.';
    } else {
        $chk = $pdo->prepare('SELECT id FROM users WHERE username=?'); $chk->execute([$username]);
        if ($chk->fetch()) { $err = 'Cet identifiant est déjà pris, choisissez-en un autre.'; }
        else {
            $pdo->prepare("INSERT INTO users (username,password,nom,role,client_id,actif) VALUES (?,?,?,'client',?,1)")
                ->execute([mb_substr($username,0,50), password_hash($pass, PASSWORD_DEFAULT), mb_substr($data['nom'],0,100), (int)$data['client_id']]);
            // Connexion automatique
            $uid = (int)$pdo->lastInsertId();
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $uid; $_SESSION['admin_nom'] = $data['nom'];
            $_SESSION['admin_role'] = 'client'; $_SESSION['admin_perms'] = [];
            unset($_SESSION['devis_ok']);
            header('Location: espace-client/index.php'); exit;
        }
    }
}

// Identifiant suggéré
$suggest = '';
if (!empty($data['email']) && strpos($data['email'],'@')!==false) $suggest = strtolower(explode('@',$data['email'])[0]);
if ($suggest==='') $suggest = strtolower(preg_replace('/[^a-z0-9]/','', explode(' ', $data['nom'])[0] ?? 'client'));
$suggest = preg_replace('/[^a-z0-9._@+-]/','',$suggest);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Merci — <?= e($ent) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('assets/css/glass.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
<script src="<?= asset('assets/js/theme.js') ?>"></script>
<style>
  body{min-height:100svh;display:grid;place-items:center;padding:24px}
  .merci{max-width:520px;width:100%;padding:38px 34px;border-radius:26px}
  .merci .ok{font-size:54px;text-align:center;margin-bottom:6px}
  .merci h1{font-family:var(--font-display);font-size:26px;text-align:center;margin-bottom:8px}
  .merci .lead{color:var(--ink-dim);text-align:center;line-height:1.6;margin-bottom:22px}
  .merci .sep{height:1px;background:var(--glass-border);margin:22px 0}
  .merci h2{font-family:var(--font-display);font-size:19px;margin-bottom:6px}
  .merci .sub{color:var(--ink-dim);font-size:14px;margin-bottom:16px;line-height:1.5}
  .merci .adv{display:flex;gap:10px;align-items:flex-start;font-size:13.5px;color:var(--ink-dim);margin-bottom:8px}
  .merci .adv span{color:var(--gold)}
  .merci .field{margin-bottom:14px}
  .merci label{display:block;font-size:13px;font-weight:600;color:var(--ink-dim);margin-bottom:6px}
  .merci .err{background:rgba(229,115,115,.12);border:1px solid rgba(229,115,115,.4);color:#ffb1b1;padding:10px 14px;border-radius:12px;margin-bottom:16px;font-size:13.5px}
  .merci .skip{text-align:center;margin-top:16px}
  .merci .skip a{color:var(--ink-faint);font-size:13.5px}
</style>
</head>
<body>
<div class="aurora"></div>
<div class="merci glass-strong">
  <div class="ok">🎉</div>
  <h1>Merci <?= e(explode(' ', $data['nom'])[0]) ?> !</h1>
  <p class="lead">Votre demande de devis a bien été envoyée. Notre équipe vous recontacte sous 24h pour vous préparer une proposition personnalisée.</p>

  <div class="sep"></div>

  <?php if (!empty($data['has_account'])): ?>
    <h2>🔐 Vous avez déjà un espace client</h2>
    <p class="sub">Connectez-vous pour suivre votre demande, retrouver vos devis et passer commande.</p>
    <a class="btn btn-gold" href="login.php" style="width:100%;justify-content:center">Se connecter à mon espace</a>
  <?php else: ?>
    <h2>✨ Créez votre espace client</h2>
    <p class="sub">En quelques secondes, créez votre compte pour <strong>suivre votre demande</strong>, recevoir votre devis, commander en ligne et retrouver tous vos documents.</p>
    <a class="btn btn-gold" href="inscription.php" style="width:100%;justify-content:center">Créer mon espace client</a>
    <div class="skip"><a href="index.php">Non merci, revenir au site</a></div>
  <?php endif; ?>
</div>
</body>
</html>
