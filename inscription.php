<?php
require __DIR__ . '/config/db.php';
$settings = get_settings($pdo);
$ent = $settings['nom_entreprise'] ?? 'Groupe Helisce';

// Déjà connecté ?
if (!empty($_SESSION['admin_id'])) {
    $dest = (($_SESSION['admin_role'] ?? '') === 'client') ? 'espace-client/index.php' : 'admin/index.php';
    header('Location: ' . $dest); exit;
}

$err = ''; $old = [];
// Pré-remplissage depuis une demande de devis récente
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_SESSION['devis_ok'])) {
    $d = $_SESSION['devis_ok'];
    $old = ['nom'=>$d['nom'] ?? '', 'telephone'=>$d['tel'] ?? '', 'email'=>$d['email'] ?? ''];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $old = $_POST;
    $type = ($_POST['type_client'] ?? 'individuel') === 'entreprise' ? 'entreprise' : 'individuel';
    $nom = trim($_POST['nom'] ?? '');
    $entreprise = trim($_POST['entreprise'] ?? '');
    $tel = trim($_POST['telephone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $adresse = trim($_POST['adresse'] ?? '');
    $ncc = trim($_POST['ncc'] ?? '');
    $username = preg_replace('/[^a-z0-9._@+-]/', '', strtolower(trim($_POST['username'] ?? '')));
    $pass = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if ($nom === '' || $tel === '') $err = 'Merci d\'indiquer au minimum votre nom et votre téléphone.';
    elseif ($type === 'entreprise' && $entreprise === '') $err = 'Indiquez le nom de votre entreprise.';
    elseif ($username === '' || strlen($pass) < 6) $err = 'Choisissez un identifiant et un mot de passe d\'au moins 6 caractères.';
    elseif ($pass !== $pass2) $err = 'Les deux mots de passe ne correspondent pas.';
    else {
        $chk = $pdo->prepare('SELECT id FROM users WHERE username=?'); $chk->execute([$username]);
        if ($chk->fetch()) $err = 'Cet identifiant est déjà utilisé, choisissez-en un autre.';
        else {
            // Retrouver une fiche client existante (ex : créée via une demande de devis) sinon la créer
            $cid = 0; $telDigits = preg_replace('/\D/', '', $tel);
            if ($telDigits !== '') {
                $st = $pdo->prepare("SELECT id FROM clients WHERE REPLACE(REPLACE(telephone,' ',''),'-','') LIKE ? LIMIT 1");
                $st->execute(['%'.$telDigits.'%']); $cid = (int)($st->fetchColumn() ?: 0);
            }
            if (!$cid && $email !== '') {
                $st = $pdo->prepare("SELECT id FROM clients WHERE email=? LIMIT 1"); $st->execute([$email]); $cid = (int)($st->fetchColumn() ?: 0);
            }
            if ($cid) {
                $pdo->prepare('UPDATE clients SET nom=?, type_client=?, entreprise=?, telephone=?, email=?, adresse=?, ncc=? WHERE id=?')
                    ->execute([mb_substr($nom,0,120), $type, mb_substr($entreprise,0,150), mb_substr($tel,0,30), mb_substr($email,0,120), mb_substr($adresse,0,255), mb_substr($ncc,0,60), $cid]);
            } else {
                $pdo->prepare('INSERT INTO clients (nom, type_client, entreprise, telephone, email, adresse, ncc, notes) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([mb_substr($nom,0,120), $type, mb_substr($entreprise,0,150), mb_substr($tel,0,30), mb_substr($email,0,120), mb_substr($adresse,0,255), mb_substr($ncc,0,60), 'Inscription via le site.']);
                $cid = (int)$pdo->lastInsertId();
            }
            // Créer le compte
            $pdo->prepare("INSERT INTO users (username,password,nom,role,client_id,actif) VALUES (?,?,?,'client',?,1)")
                ->execute([mb_substr($username,0,50), password_hash($pass, PASSWORD_DEFAULT), mb_substr($nom,0,100), $cid]);
            $uid = (int)$pdo->lastInsertId();
            unset($_SESSION['devis_ok']);
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $uid; $_SESSION['admin_nom'] = $nom;
            $_SESSION['admin_role'] = 'client'; $_SESSION['admin_perms'] = [];
            header('Location: espace-client/index.php'); exit;
        }
    }
}
$g = fn($k) => e($old[$k] ?? '');
$typeSel = ($old['type_client'] ?? 'individuel');
?>
<!DOCTYPE html>
<html lang="fr" data-space="public">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Créer un compte — <?= e($ent) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('assets/css/glass.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
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
  .reg-wrap{position:relative; z-index:2; min-height:100svh; display:grid; place-items:center; padding:24px}
  .reg{
    position:relative; width:min(560px,100%); padding:38px 32px; border-radius:32px; overflow:hidden;
    background:linear-gradient(150deg, rgba(255,255,255,.05), rgba(255,255,255,.015));
    backdrop-filter:blur(18px) saturate(140%); -webkit-backdrop-filter:blur(18px) saturate(140%);
    border:1px solid rgba(255,255,255,.2);
    box-shadow:0 24px 70px rgba(0,0,0,.5), inset 0 1px 0 rgba(255,255,255,.25);
    animation:lgCardIn .8s var(--lgc-ease) both;
  }
  /* Halo lumineux qui pulse derrière la carte (met le logo en valeur) */
  .reg::after{content:'';position:absolute;inset:-2px;border-radius:34px;z-index:0;pointer-events:none;
    background:radial-gradient(circle at 50% 30%, rgba(233,193,92,.14), transparent 60%);
    animation:lgHalo 5s ease-in-out infinite}
  @keyframes lgHalo{0%,100%{opacity:.6}50%{opacity:1}}
  @keyframes lgCardIn{from{opacity:0;transform:translateY(28px) scale(.97)}to{opacity:1;transform:none}}

  /* Reflet liquide qui balaye la carte */
  .lg-sheen{position:absolute; top:0; left:-70%; width:55%; height:100%; z-index:1; pointer-events:none;
    background:linear-gradient(100deg, transparent, rgba(255,255,255,.14), transparent);
    transform:skewX(-18deg); animation:lgSheen 7s ease-in-out infinite}
  @keyframes lgSheen{0%,55%{left:-70%}80%,100%{left:140%}}
  .reg > *{position:relative; z-index:2}

  /* Logo dans une pastille blanche brillante */
  .reg .logo{
    width:76px; height:76px; border-radius:24px; margin:0 auto 18px; overflow:hidden;
    display:grid; place-items:center;
    background:#fff;
    box-shadow:0 12px 34px rgba(0,0,0,.35), 0 0 0 1px rgba(233,193,92,.5), 0 0 30px rgba(233,193,92,.25);
    animation:lgLogoPulse 4s ease-in-out infinite}
  @keyframes lgLogoPulse{0%,100%{box-shadow:0 12px 34px rgba(0,0,0,.35),0 0 0 1px rgba(233,193,92,.5),0 0 30px rgba(233,193,92,.2)}
    50%{box-shadow:0 12px 40px rgba(0,0,0,.4),0 0 0 1px rgba(233,193,92,.7),0 0 44px rgba(233,193,92,.4)}}
  .reg .logo .login-logo-img{width:100%;height:100%}
  .reg .logo img{width:100%;height:100%;object-fit:contain;display:block;padding:8px}
  .reg .logo span{font-size:34px}

  .reg h1{font-family:'Playfair Display',Georgia,serif; text-align:center; font-size:24px; margin:0 0 6px; color:#fff; font-weight:700}
  .reg .sub{text-align:center; color:var(--lgc-gold); font-size:13.5px; margin:0 0 26px; font-weight:600; letter-spacing:.3px}
  .reg form{display:flex; flex-direction:column; gap:15px}

  /* Champs verre */
  .reg .field label{display:block; font-size:12.5px; color:var(--lgc-ink-dim); margin-bottom:6px; font-weight:600}
  .reg .input{
    width:100%; padding:13px 16px; border-radius:14px; font-size:14.5px;
    background:rgba(255,255,255,.06); color:#fff;
    border:1px solid rgba(255,255,255,.16); transition:border-color .25s, box-shadow .25s, background .25s}
  .reg .input::placeholder{color:rgba(200,214,240,.5)}
  .reg .input:focus{outline:none; border-color:rgba(233,193,92,.7);
    background:rgba(255,255,255,.09); box-shadow:0 0 0 3px rgba(233,193,92,.18)}

  /* Bouton or lumineux */
  .reg .btn-gold{
    width:100%; margin-top:6px; padding:13px; border:none; border-radius:14px; cursor:pointer;
    font-family:inherit; font-size:15px; font-weight:700; color:#0a1020;
    background:linear-gradient(135deg,#f3d074 0%,var(--lgc-gold) 55%,#d3a53a 100%);
    box-shadow:0 8px 26px rgba(233,193,92,.4), inset 0 1px 0 rgba(255,255,255,.5);
    transition:transform .3s var(--lgc-ease), box-shadow .3s;
    animation:lgBtnGlow 4s ease-in-out infinite}
  .reg .btn-gold:hover{transform:translateY(-2px); box-shadow:0 14px 36px rgba(233,193,92,.55)}
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

  /* Logo compact en haut de la carte inscription */
  .reg .reg-logo-box{width:72px;height:72px;border-radius:22px;margin:0 auto 16px;overflow:hidden;
    display:grid;place-items:center;background:#fff;
    box-shadow:0 12px 34px rgba(0,0,0,.35),0 0 0 1px rgba(233,193,92,.5),0 0 30px rgba(233,193,92,.25);
    animation:lgLogoPulse 4s ease-in-out infinite}
  .reg .reg-logo-box .login-logo-img{width:100%;height:100%}
  .reg .reg-logo-box img{width:100%;height:100%;object-fit:contain;padding:8px;display:block}
  .reg h1{font-family:'Playfair Display',Georgia,serif;font-size:24px;text-align:center;margin:0 0 6px;color:#fff;font-weight:700}
  .reg .lead{color:var(--lgc-ink-dim);text-align:center;font-size:13.5px;margin:0 0 22px;line-height:1.5}
  /* Sélecteur particulier/entreprise */
  .seg{display:flex;gap:8px;background:rgba(255,255,255,.05);padding:5px;border-radius:14px;margin-bottom:20px;border:1px solid rgba(255,255,255,.12)}
  .seg label{flex:1;text-align:center;cursor:pointer;font-weight:600;font-size:14px}
  .seg input{display:none}
  .seg span{display:block;padding:11px;border-radius:10px;color:var(--lgc-ink-dim);transition:all .2s}
  .seg input:checked+span{background:linear-gradient(135deg,#f3d074,var(--lgc-gold));color:#0a1020;font-weight:700}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  .grid .full{grid-column:1/-1}
  .err{background:rgba(255,90,90,.14);border:1px solid rgba(255,90,90,.3);color:#ff9a9a;padding:11px 14px;border-radius:12px;margin-bottom:16px;font-size:13.5px}
  .reg .foot{text-align:center;margin-top:18px;font-size:13.5px;color:var(--lgc-ink-dim)}
  .reg .foot a{color:var(--lgc-gold);text-decoration:none}
  .ent-only{display:none}
  @media (max-width:520px){.grid{grid-template-columns:1fr}}

  /* ===== MODE CLAIR ===== */
  [data-theme="light"] body{
    background:
      radial-gradient(46% 40% at 82% 4%, rgba(212,165,38,.14), transparent 60%),
      radial-gradient(52% 46% at 8% 12%, rgba(90,140,220,.16), transparent 62%),
      linear-gradient(175deg,#f6f8fc 0%,#eef2f9 50%,#f4f6fb 100%) !important;
    color:#1a2744;
  }
  [data-theme="light"] .reg{
    background:linear-gradient(155deg, rgba(255,255,255,.32), rgba(255,255,255,.14)) !important;
    backdrop-filter:blur(20px) saturate(140%) !important; -webkit-backdrop-filter:blur(20px) saturate(140%) !important;
    border:1px solid rgba(255,255,255,.7) !important;
    box-shadow:0 24px 70px rgba(20,40,90,.15), inset 0 1px 0 rgba(255,255,255,.85) !important;
  }
  [data-theme="light"] .reg h1{ color:#0a1f44 !important; }
  [data-theme="light"] .reg .sub,
  [data-theme="light"] .reg .lead{ color:#b8870f !important; }
  [data-theme="light"] .reg .field label{ color:#2a3a58 !important; }
  [data-theme="light"] .reg .input{
    background:rgba(255,255,255,.9) !important; color:#0a1f44 !important;
    border:1px solid rgba(20,40,90,.15) !important;
  }
  [data-theme="light"] .reg .input::placeholder{ color:rgba(90,107,140,.55) !important; }
  [data-theme="light"] .reg .input:focus{ border-color:rgba(184,135,15,.6) !important; box-shadow:0 0 0 3px rgba(184,135,15,.15) !important; }
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

  .card-logo-bg{position:absolute; inset:0; z-index:0 !important; pointer-events:none; overflow:hidden;
    display:grid; place-items:center; border-radius:32px}
  .card-logo-bg .card-logo-water{width:70%; height:70%; opacity:.09;
    filter:drop-shadow(0 0 30px rgba(233,193,92,.4)); animation:lgBreath 9s ease-in-out infinite}
  .card-logo-bg .card-logo-water img{width:100%; height:100%; object-fit:contain; display:block}
  [data-theme="light"] .card-logo-bg .card-logo-water{opacity:.13}
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

<div class="reg-wrap">
  <div class="reg glass-strong">
    <div class="lg-sheen"></div>
    <div class="card-logo-bg"><?= logo_html('.', 'card-logo-water') ?></div>
    <div class="reg-logo-box"><?= logo_html('.', 'login-logo-img') ?></div>
    <h1>Créer mon compte</h1>
    <p class="lead"><?= e($ent) ?> — Rejoignez votre espace client pour commander, suivre vos devis et retrouver vos documents.</p>
    <?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div class="seg">
      <label><input type="radio" name="type_client" value="individuel" <?= $typeSel!=='entreprise'?'checked':'' ?> onchange="majType()"><span>👤 Particulier</span></label>
      <label><input type="radio" name="type_client" value="entreprise" <?= $typeSel==='entreprise'?'checked':'' ?> onchange="majType()"><span>🏢 Entreprise</span></label>
    </div>
    <div class="grid">
      <div class="field full ent-only" id="entField"><label>Nom de l'entreprise *</label><input class="input" name="entreprise" value="<?= $g('entreprise') ?>"></div>
      <div class="field"><label id="lblNom">Nom complet *</label><input class="input" name="nom" required value="<?= $g('nom') ?>"></div>
      <div class="field"><label>Téléphone *</label><input class="input" name="telephone" required value="<?= $g('telephone') ?>"></div>
      <div class="field"><label>E-mail</label><input class="input" type="email" name="email" value="<?= $g('email') ?>"></div>
      <div class="field"><label>Adresse</label><input class="input" name="adresse" value="<?= $g('adresse') ?>"></div>
      <div class="field full ent-only"><label>N° Compte Contribuable (NCC)</label><input class="input" name="ncc" value="<?= $g('ncc') ?>"></div>
      <div class="field full" style="border-top:1px solid var(--glass-border);padding-top:14px;margin-top:2px"><label>Identifiant de connexion *</label><input class="input" name="username" required pattern="[a-zA-Z0-9._-]+" value="<?= $g('username') ?>"></div>
      <div class="field"><label>Mot de passe *</label><input class="input" type="password" name="password" required minlength="6"></div>
      <div class="field"><label>Confirmer *</label><input class="input" type="password" name="password2" required minlength="6"></div>
    </div>
    <button class="btn btn-gold" style="width:100%;justify-content:center;margin-top:18px">Créer mon compte</button>
  </form>
  <div class="foot">Déjà inscrit ? <a href="login.php">Se connecter</a> · <a href="index.php">Retour au site</a></div>
  </div>
</div>
<script>
function majType(){
  const ent = document.querySelector('input[name=type_client]:checked').value === 'entreprise';
  document.querySelectorAll('.ent-only').forEach(el=>el.style.display = ent ? 'block' : 'none');
  document.getElementById('lblNom').textContent = ent ? 'Nom du contact *' : 'Nom complet *';
}
majType();
</script>
</body>
</html>
