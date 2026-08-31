<?php
require __DIR__ . '/config/db.php';
$s = get_settings($pdo);
if (!empty($_SESSION['admin_id'])) {
    $dest = (($_SESSION['admin_role'] ?? '') === 'client') ? 'espace-client/index.php' : 'admin/index.php';
    header('Location: ' . $dest); exit;
}

$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    /* Protection contre les essais en série : comptabilisée en base et par adresse IP,
       elle résiste aux robots qui n'acceptent pas les cookies. */
    $identifiantSaisi = trim($_POST['username'] ?? '');
    $minutes = connexion_bloquee($pdo);
    if ($minutes > 0) {
        $erreur = 'Trop de tentatives infructueuses. Réessayez dans ' . $minutes . ' minute' . ($minutes > 1 ? 's' : '') . '.';
        enregistrer_tentative($pdo, $identifiantSaisi, false);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([trim($_POST['username'] ?? '')]);
        $user = $stmt->fetch();
        if ($user && !$user['actif']) {
            $erreur = 'Ce compte a été désactivé. Contactez l\'administrateur.';
        } elseif ($user && password_verify($_POST['password'] ?? '', $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $user['id'];
            $_SESSION['admin_nom'] = $user['nom'];
            $_SESSION['admin_role'] = $user['role'] ?? 'admin';
            $_SESSION['admin_perms'] = $user['permissions'] ? (json_decode($user['permissions'], true) ?: []) : [];
            reinitialiser_tentatives($pdo);
            enregistrer_tentative($pdo, $identifiantSaisi, true);
            /* Session unique : on enregistre l'identifiant de session courant.
               Toute autre session déjà ouverte pour ce compte devient caduque. */
            $sid = session_id();
            $pdo->prepare("UPDATE users SET session_id=?, last_activity=NOW() WHERE id=?")
                ->execute([$sid, $user['id']]);
            $_SESSION['sid_check'] = $sid;
            $_SESSION['derniere_activite'] = time();
            capturer_connexion($pdo, (int)$user['id']);
            journaliser($pdo, 'connexion', 'utilisateur', (int)$user['id'], 'Connexion réussie');
            $dest = ($_SESSION['admin_role'] === 'client') ? 'espace-client/index.php' : 'admin/index.php';
            header('Location: ' . $dest); exit;
        } else {
            enregistrer_tentative($pdo, $identifiantSaisi, false);
            $restants = CONNEXION_MAX_ESSAIS - 1 - (int)$pdo->query(
                "SELECT COUNT(*) FROM tentatives_connexion WHERE ip = " . $pdo->quote(adresse_ip()) .
                " AND reussi = 0 AND created_at > DATE_SUB(NOW(), INTERVAL " . CONNEXION_BLOCAGE_MIN . " MINUTE)"
            )->fetchColumn();
            $erreur = 'Identifiants incorrects.'
                    . ($restants >= 0 && $restants <= 2
                        ? ' Il vous reste ' . max(0, $restants) . ' tentative' . ($restants > 1 ? 's' : '') . '.'
                        : '');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr" data-space="public">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Connexion — <?= e($s['nom_entreprise']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('assets/css/glass.css') ?>">
<script src="<?= asset('assets/js/theme.js') ?>"></script>
<style>
  :root{
    --lgc-gold:#e9c15c; --lgc-ink:#eaf0fb; --lgc-ink-dim:#a9b7d0;
    --lgc-ease:cubic-bezier(.22,1,.36,1);
  }
  *{box-sizing:border-box}
  html,body{margin:0;min-height:100%;overflow-x:hidden}
  body{
    min-height:100svh; position:relative; color:var(--lgc-ink);
    font-family:'Sora',-apple-system,system-ui,sans-serif;
    background:
      radial-gradient(46% 40% at 82% 6%, rgba(233,193,92,.10), transparent 60%),
      radial-gradient(52% 46% at 8% 12%, rgba(38,86,170,.28), transparent 62%),
      linear-gradient(175deg, #020714 0%, #05122b 50%, #020814 100%);
    background-attachment:fixed;
  }

  /* ---- Orbes de lumière liquides ---- */
  .lg-orb{position:fixed; border-radius:50%; filter:blur(90px); z-index:0; pointer-events:none; mix-blend-mode:screen}
  .lg-orb-1{width:44vw;height:44vw;left:-10vw;top:2vh;opacity:.5;
    background:radial-gradient(circle,rgba(42,96,190,.95),transparent 70%);
    animation:lgFloat1 24s var(--lgc-ease) infinite}
  .lg-orb-2{width:38vw;height:38vw;right:-8vw;top:20vh;opacity:.4;
    background:radial-gradient(circle,rgba(233,193,92,.55),transparent 70%);
    animation:lgFloat2 30s var(--lgc-ease) infinite}
  .lg-orb-3{width:30vw;height:30vw;left:40vw;bottom:-12vh;opacity:.35;
    background:radial-gradient(circle,rgba(60,120,220,.7),transparent 70%);
    animation:lgFloat3 28s var(--lgc-ease) infinite}
  @keyframes lgFloat1{0%,100%{transform:translate(0,0) scale(1)}50%{transform:translate(12vw,16vh) scale(1.15)}}
  @keyframes lgFloat2{0%,100%{transform:translate(0,0) scale(1)}50%{transform:translate(-14vw,18vh) scale(1.2)}}
  @keyframes lgFloat3{0%,100%{transform:translate(0,0) scale(1)}50%{transform:translate(-8vw,-14vh) scale(1.1)}}

  /* ---- Logo de l'entreprise en filigrane lumineux ---- */
  .lg-logo-bg{position:fixed; inset:0; z-index:1; pointer-events:none; overflow:hidden}
  .lg-logo-bg .lg-logo-water{
    position:absolute; top:50%; left:50%; transform:translate(-50%,-50%);
    width:min(72vh,580px); height:min(72vh,580px);
    opacity:.34; filter:drop-shadow(0 0 70px rgba(233,193,92,.6));
    animation:lgBreath 9s ease-in-out infinite}
  .lg-logo-bg .lg-logo-water img{width:100%;height:100%;object-fit:contain;display:block}
  @keyframes lgBreath{0%,100%{opacity:.24;transform:translate(-50%,-50%) scale(1) rotate(0deg)}50%{opacity:.38;transform:translate(-50%,-50%) scale(1.06) rotate(4deg)}}

  /* ---- Grain subtil ---- */
  .lg-grain{position:fixed; inset:0; z-index:0; pointer-events:none; opacity:.035;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='3'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E")}

  /* ---- Carte de connexion ---- */
  .login-wrap{position:relative; z-index:2; min-height:100svh; display:grid; place-items:center; padding:24px}
  .login-card{
    position:relative; width:min(380px,100%); padding:38px 32px; border-radius:32px; overflow:hidden;
    background:linear-gradient(150deg, rgba(255,255,255,.05), rgba(255,255,255,.015));
    backdrop-filter:blur(18px) saturate(140%); -webkit-backdrop-filter:blur(18px) saturate(140%);
    border:1px solid rgba(255,255,255,.2);
    box-shadow:0 24px 70px rgba(0,0,0,.5), inset 0 1px 0 rgba(255,255,255,.25);
    animation:lgCardIn .8s var(--lgc-ease) both;
  }
  /* Halo lumineux qui pulse derrière la carte (met le logo en valeur) */
  .login-card::after{content:'';position:absolute;inset:-2px;border-radius:34px;z-index:0;pointer-events:none;
    background:radial-gradient(circle at 50% 30%, rgba(233,193,92,.14), transparent 60%);
    animation:lgHalo 5s ease-in-out infinite}
  @keyframes lgHalo{0%,100%{opacity:.6}50%{opacity:1}}
  @keyframes lgCardIn{from{opacity:0;transform:translateY(28px) scale(.97)}to{opacity:1;transform:none}}

  /* Reflet liquide qui balaye la carte */
  .lg-sheen{position:absolute; top:0; left:-70%; width:55%; height:100%; z-index:1; pointer-events:none;
    background:linear-gradient(100deg, transparent, rgba(255,255,255,.14), transparent);
    transform:skewX(-18deg); animation:lgSheen 7s ease-in-out infinite}
  @keyframes lgSheen{0%,55%{left:-70%}80%,100%{left:140%}}
  .login-card > *{position:relative; z-index:2}

  /* Logo dans une pastille blanche brillante */
  .login-card .logo{
    width:76px; height:76px; border-radius:24px; margin:0 auto 18px; overflow:hidden;
    display:grid; place-items:center;
    background:#fff;
    box-shadow:0 12px 34px rgba(0,0,0,.35), 0 0 0 1px rgba(233,193,92,.5), 0 0 30px rgba(233,193,92,.25);
    animation:lgLogoPulse 4s ease-in-out infinite}
  @keyframes lgLogoPulse{0%,100%{box-shadow:0 12px 34px rgba(0,0,0,.35),0 0 0 1px rgba(233,193,92,.5),0 0 30px rgba(233,193,92,.2)}
    50%{box-shadow:0 12px 40px rgba(0,0,0,.4),0 0 0 1px rgba(233,193,92,.7),0 0 44px rgba(233,193,92,.4)}}
  .login-card .logo .login-logo-img{width:100%;height:100%}
  .login-card .logo img{width:100%;height:100%;object-fit:contain;display:block;padding:8px}
  .login-card .logo span{font-size:34px}

  .login-card h1{font-family:'Playfair Display',Georgia,serif; text-align:center; font-size:24px; margin:0 0 6px; color:#fff; font-weight:700}
  .login-card .sub{text-align:center; color:var(--lgc-gold); font-size:13.5px; margin:0 0 26px; font-weight:600; letter-spacing:.3px}
  .login-card form{display:flex; flex-direction:column; gap:15px}

  /* Champs verre */
  .login-card .field label{display:block; font-size:12.5px; color:var(--lgc-ink-dim); margin-bottom:6px; font-weight:600}
  .login-card .input{
    width:100%; padding:13px 16px; border-radius:14px; font-size:14.5px;
    background:rgba(255,255,255,.06); color:#fff;
    border:1px solid rgba(255,255,255,.16); transition:border-color .25s, box-shadow .25s, background .25s}
  .login-card .input::placeholder{color:rgba(200,214,240,.5)}
  .login-card .input:focus{outline:none; border-color:rgba(233,193,92,.7);
    background:rgba(255,255,255,.09); box-shadow:0 0 0 3px rgba(233,193,92,.18)}

  /* Bouton or lumineux */
  .login-card .btn-gold{
    width:100%; margin-top:6px; padding:13px; border:none; border-radius:14px; cursor:pointer;
    font-family:inherit; font-size:15px; font-weight:700; color:#0a1020;
    background:linear-gradient(135deg,#f3d074 0%,var(--lgc-gold) 55%,#d3a53a 100%);
    box-shadow:0 8px 26px rgba(233,193,92,.4), inset 0 1px 0 rgba(255,255,255,.5);
    transition:transform .3s var(--lgc-ease), box-shadow .3s;
    animation:lgBtnGlow 4s ease-in-out infinite}
  .login-card .btn-gold:hover{transform:translateY(-2px); box-shadow:0 14px 36px rgba(233,193,92,.55)}
  @keyframes lgBtnGlow{0%,100%{box-shadow:0 8px 26px rgba(233,193,92,.35),inset 0 1px 0 rgba(255,255,255,.5)}
    50%{box-shadow:0 8px 34px rgba(233,193,92,.6),inset 0 1px 0 rgba(255,255,255,.6)}}

  .btn-glass{display:inline-flex;align-items:center;justify-content:center;gap:8px;
    padding:12px 16px;border-radius:14px;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;
    background:rgba(255,255,255,.08); color:#fff; border:1px solid rgba(255,255,255,.16);
    transition:background .25s, transform .25s}
  .btn-glass:hover{background:rgba(255,255,255,.16); transform:translateY(-1px)}

  .flash{padding:11px 14px;border-radius:12px;font-size:13.5px;margin-bottom:6px}
  .flash.error{background:rgba(255,90,90,.14);color:#ff9a9a;border:1px solid rgba(255,90,90,.3)}

  .back{display:block;text-align:center;margin-top:20px;color:var(--lgc-ink-dim);font-size:13px;text-decoration:none;transition:color .2s}
  .back:hover{color:var(--lgc-gold)}

  .or-sep{display:flex;align-items:center;gap:12px;margin:18px 0;color:var(--lgc-ink-dim);font-size:12.5px}
  .or-sep::before,.or-sep::after{content:'';flex:1;height:1px;background:rgba(255,255,255,.14)}
  .btn-google{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;
    padding:11px 16px;border-radius:14px;background:#fff;color:#3c4043;font-weight:600;font-size:14px;
    border:none;text-decoration:none;transition:box-shadow .2s,transform .2s}
  .btn-google:hover{box-shadow:0 6px 18px rgba(0,0,0,.25);transform:translateY(-1px)}

  /* ---- Particules dorées scintillantes ---- */
  .lg-particles{position:fixed; inset:0; z-index:1; pointer-events:none; overflow:hidden}
  .lg-particles span{position:absolute; display:block; width:6px; height:6px; border-radius:50%;
    background:radial-gradient(circle, rgba(233,193,92,.9), rgba(233,193,92,0) 70%);
    animation:lgRise linear infinite}
  .lg-particles span:nth-child(1){left:8%;  animation-duration:14s; animation-delay:0s}
  .lg-particles span:nth-child(2){left:18%; animation-duration:18s; animation-delay:2s; width:4px;height:4px}
  .lg-particles span:nth-child(3){left:28%; animation-duration:12s; animation-delay:4s}
  .lg-particles span:nth-child(4){left:38%; animation-duration:20s; animation-delay:1s; width:5px;height:5px}
  .lg-particles span:nth-child(5){left:48%; animation-duration:16s; animation-delay:5s}
  .lg-particles span:nth-child(6){left:58%; animation-duration:13s; animation-delay:3s; width:3px;height:3px}
  .lg-particles span:nth-child(7){left:66%; animation-duration:19s; animation-delay:6s}
  .lg-particles span:nth-child(8){left:74%; animation-duration:15s; animation-delay:2.5s; width:5px;height:5px}
  .lg-particles span:nth-child(9){left:82%; animation-duration:17s; animation-delay:4.5s}
  .lg-particles span:nth-child(10){left:90%; animation-duration:21s; animation-delay:1.5s; width:4px;height:4px}
  .lg-particles span:nth-child(11){left:12%; animation-duration:22s; animation-delay:7s; width:3px;height:3px}
  .lg-particles span:nth-child(12){left:95%; animation-duration:14s; animation-delay:3.5s}
  @keyframes lgRise{
    0%{transform:translateY(105vh) scale(.6); opacity:0}
    10%{opacity:1}
    90%{opacity:1}
    100%{transform:translateY(-10vh) scale(1); opacity:0}
  }

  /* ===== MODE CLAIR ===== */
  [data-theme="light"] body{
    background:
      radial-gradient(46% 40% at 82% 4%, rgba(212,165,38,.14), transparent 60%),
      radial-gradient(52% 46% at 8% 12%, rgba(90,140,220,.16), transparent 62%),
      linear-gradient(175deg,#f6f8fc 0%,#eef2f9 50%,#f4f6fb 100%) !important;
    color:#1a2744;
  }
  [data-theme="light"] .login-card{
    background:linear-gradient(155deg, rgba(255,255,255,.32), rgba(255,255,255,.14)) !important;
    backdrop-filter:blur(20px) saturate(140%) !important; -webkit-backdrop-filter:blur(20px) saturate(140%) !important;
    border:1px solid rgba(255,255,255,.7) !important;
    box-shadow:0 24px 70px rgba(20,40,90,.15), inset 0 1px 0 rgba(255,255,255,.85) !important;
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

  .lien-sec{color:#c4d0e6}
  [data-theme="light"] .lien-sec{color:#3d4a67 !important}

  /* Logo en filigrane À L'INTÉRIEUR de la carte (visible à coup sûr) */
  .card-logo-bg{position:absolute; inset:0; z-index:0 !important; pointer-events:none; overflow:hidden;
    display:grid; place-items:center; border-radius:32px}
  .card-logo-bg .card-logo-water{width:82%; height:82%; opacity:.10;
    filter:drop-shadow(0 0 30px rgba(233,193,92,.4)); animation:lgBreath 9s ease-in-out infinite}
  .card-logo-bg .card-logo-water img{width:100%; height:100%; object-fit:contain; display:block}
  [data-theme="light"] .card-logo-bg .card-logo-water{opacity:.14}
</style>
</head>
<body>
<!-- Décor iOS 26 : orbes liquides + logo en filigrane -->
<div class="lg-orb lg-orb-1"></div>
<div class="lg-orb lg-orb-2"></div>
<div class="lg-orb lg-orb-3"></div>
<div class="lg-logo-bg"><?= logo_html('.', 'lg-logo-water') ?></div>
<div class="lg-grain"></div>
<div class="lg-particles">
  <span></span><span></span><span></span><span></span><span></span>
  <span></span><span></span><span></span><span></span><span></span>
  <span></span><span></span>
</div>

<button class="theme-toggle" onclick="toggleTheme()" title="Changer de thème" style="position:fixed;top:20px;right:20px;z-index:10"><span data-theme-icon>☀️</span></button>
<div class="login-wrap">
  <div class="login-card glass-strong">
    <div class="lg-sheen"></div>
    <div class="card-logo-bg"><?= logo_html('.', 'card-logo-water') ?></div>
    <div class="logo"><?= logo_html('.', 'login-logo-img') ?></div>
    <h1>Espace de gestion</h1>
    <p class="sub"><?= e($s['nom_entreprise']) ?></p>
    <?php
    $notice = '';
    if (isset($_GET['inactif']))  $notice = 'Vous avez été déconnecté après une période d\'inactivité.';
    if (isset($_GET['ailleurs']))  $notice = 'Votre compte vient d\'être connecté sur un autre appareil.';
    if (isset($_GET['deconnecte'])) $notice = 'Session terminée.';
    ?>
    <?php if ($notice): ?><div class="flash" style="background:rgba(212,165,38,.14);color:#8a6d13;border:1px solid rgba(212,165,38,.3);margin-bottom:14px"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($erreur): ?><div class="flash error"><?= e($erreur) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <div class="field"><label>Nom d'utilisateur</label><input class="input" name="username" required autofocus></div>
      <div class="field"><label>Mot de passe</label><input class="input" type="password" name="password" required></div>
      <button class="btn btn-gold" style="width:100%;margin-top:6px">Se connecter</button>
      <a href="reset.php" class="lien-sec" style="display:block;text-align:center;margin-top:12px;font-size:13px;text-decoration:none">Mot de passe oublié ?</a>
    </form>
    <?php if (google_actif($pdo)): ?>
    <div class="or-sep"><span>ou</span></div>
    <a class="btn-google" href="google-login.php?role=client">
      <svg width="18" height="18" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.6 2.4 30.1 0 24 0 14.6 0 6.4 5.4 2.6 13.2l7.9 6.1C12.3 13.3 17.6 9.5 24 9.5z"/><path fill="#4285F4" d="M46.1 24.6c0-1.6-.1-3.1-.4-4.6H24v9.1h12.4c-.5 2.9-2.1 5.3-4.6 7l7.2 5.6c4.2-3.9 6.6-9.6 6.6-16.1z"/><path fill="#FBBC05" d="M10.5 28.3c-.5-1.4-.8-2.9-.8-4.3s.3-3 .8-4.3l-7.9-6.1C1 16.6 0 20.2 0 24s1 7.4 2.6 10.4l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.2-5.6c-2 1.4-4.6 2.2-8.7 2.2-6.4 0-11.7-3.8-13.5-9.3l-7.9 6.1C6.4 42.6 14.6 48 24 48z"/></svg>
      Continuer avec Google
    </a>
    <?php endif; ?>
    <div style="text-align:center;margin-top:16px;padding-top:16px;border-top:1px solid var(--glass-border,rgba(255,255,255,.1))">
      <p class="lien-sec" style="font-size:13.5px;margin-bottom:10px">Pas encore de compte ?</p>
      <a class="btn btn-glass" href="inscription.php" style="width:100%;justify-content:center">✨ Créer un compte client</a>
    </div>
    <a class="back" href="index.php">← Retour au site</a>
  </div>
</div>
</body>
</html>
