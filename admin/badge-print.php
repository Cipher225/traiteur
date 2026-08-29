<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/badges.php';
require __DIR__ . '/../config/docauth.php';

$id = (int)($_GET['id'] ?? 0);
$format = ($_GET['format'] ?? 'carte') === 'badge' ? 'badge' : 'carte';
$b = $pdo->query("SELECT * FROM badges WHERE id=$id")->fetch();
if (!$b) { http_response_code(404); exit('Badge introuvable.'); }

// Les membres externes n'ont droit qu'à un badge, jamais à la carte professionnelle.
if (($b['type_porteur'] ?? '') === 'externe') { $format = 'badge'; }

$ent      = $settings['nom_entreprise'] ?? 'Groupe Helisce';
$slogan   = $settings['slogan'] ?? '';
$logo     = !empty($settings['logo']) && is_file(__DIR__.'/../uploads/'.$settings['logo'])
            ? '../uploads/'.rawurlencode($settings['logo']) : '../assets/img/logo.png';
$photo    = !empty($b['photo']) && is_file(__DIR__.'/../uploads/'.$b['photo'])
            ? '../uploads/'.rawurlencode($b['photo']) : '';
/* Le QR encode l'URL de vérification en ligne (sécurisé). */
$qrUri    = qr_datauri(badge_url_verif($b, $settings), 4);
$org      = $b['organisation'] ?: $ent;
$adrEnt   = $settings['adresse'] ?? '';
$telEnt   = $settings['telephone'] ?? '';
$emailEnt = $settings['email'] ?? '';
$siteEnt  = preg_replace('#^https?://#', '', (string)($settings['site_url'] ?? ''));

function bdg_date($d){ return $d ? date('d/m/Y', strtotime($d)) : '—'; }
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $format==='carte'?'Carte professionnelle':'Badge' ?> — <?= e($b['nom']) ?></title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  :root{--navy:#0e2452;--navy2:#152f63;--gold:#c9a227;--gold2:#e8c766;--gold3:#a8811a;--ink:#16233f}
  body{font-family:'Segoe UI',-apple-system,Arial,sans-serif;background:#dfe3ea;padding:30px 14px;
       -webkit-print-color-adjust:exact;print-color-adjust:exact}
  .barre{max-width:640px;margin:0 auto 22px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
  .barre button,.barre a{border:none;padding:11px 20px;border-radius:11px;font-size:13.5px;font-weight:700;
       cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:8px}
  .btn-p{background:linear-gradient(135deg,#c9971f,#e8b93f);color:#0a1f44}
  .btn-d{background:#0a1f44;color:#fff}
  .btn-g{background:#e8ecf2;color:#2d3442}
  .stage{display:flex;flex-direction:column;align-items:center;gap:22px;width:100%;max-width:100%}
  .ic{width:16px;height:16px;flex-shrink:0;fill:none;stroke:var(--gold3);stroke-width:1.9}

  /* ==================== CARTE PROFESSIONNELLE (format ID-1 : 85,6×54 mm) ==================== */
  .cv{width:540px;height:340px;min-width:540px;max-width:540px;flex:none;box-sizing:border-box;
      border-radius:20px;position:relative;overflow:hidden;background:#fdfdfb;
      box-shadow:0 18px 44px rgba(14,36,82,.28)}
  /* Bandeau navy incurvé en haut à droite (contient la photo, sans déborder) */
  .swoosh{position:absolute;top:0;right:0;width:210px;height:100%;overflow:hidden;z-index:0}
  .swoosh.l{left:0;right:auto;width:150px;transform:scaleX(-1)}
  .swoosh i{position:absolute;border-radius:50%}
  .swoosh .navy{width:430px;height:520px;right:-120px;top:-90px;
      background:linear-gradient(135deg,#0a1f44,#1c3f7e)}
  .swoosh .gold{width:430px;height:520px;right:-96px;top:-96px;background:transparent;
      border:8px solid transparent;border-left-color:var(--gold);border-top-color:var(--gold2)}
  .swoosh .gold2{width:430px;height:520px;right:-70px;top:10px;background:transparent;
      border:3px solid transparent;border-left-color:rgba(201,162,39,.5)}

  .cv-in{position:absolute;inset:0;padding:24px 26px;z-index:2;max-width:360px}
  .cv-brand{display:flex;align-items:center;gap:12px}
  .cv-brand img{width:58px;height:58px;object-fit:contain}
  .cv-brand .bt{font-family:Georgia,serif;font-size:19px;font-weight:700;letter-spacing:.07em;color:var(--navy);line-height:1;white-space:nowrap}
  .cv-brand .bl{width:130px;height:1px;background:linear-gradient(90deg,var(--gold),transparent);margin:5px 0 3px;position:relative}
  .cv-brand .bl::before{content:'◆';position:absolute;left:50%;top:-7px;transform:translateX(-50%);color:var(--gold);font-size:7px}
  .cv-brand .bs{font-size:7.5px;letter-spacing:.08em;color:var(--gold3);font-style:italic}
  .cv-id{margin-top:24px}
  .cv-id .nom{font-size:23px;font-weight:800;color:var(--navy);line-height:1.05}
  .cv-id .fonc{font-size:12px;color:var(--gold3);letter-spacing:.06em;margin-top:5px;text-transform:uppercase;font-weight:700}
  .cv-id .uline{width:140px;height:2px;background:linear-gradient(90deg,var(--gold),transparent);margin-top:9px}
  /* Infos en une seule colonne nette (coordonnées + fonction) */
  .cv-info{margin-top:22px;display:flex;flex-direction:column;gap:11px;max-width:330px}
  .cv-row{display:flex;align-items:center;gap:10px;font-size:11px;color:var(--ink)}
  .cv-row b{color:var(--navy);font-weight:700}
  .cv-row .lbl{color:#6a7488;font-weight:600;text-transform:uppercase;letter-spacing:.02em;min-width:64px}
  /* Photo ronde dans le bandeau navy, entièrement contenue */
  .cv-photo{position:absolute;top:50%;transform:translateY(-50%);right:26px;width:150px;height:150px;
      border-radius:50%;object-fit:cover;z-index:3;
      border:4px solid var(--gold);box-shadow:0 0 0 5px #fff,0 10px 26px rgba(14,36,82,.35)}
  .cv-photo.ini{display:grid;place-items:center;font-size:52px;font-weight:800;color:var(--navy);
      background:linear-gradient(135deg,var(--gold2),var(--gold3))}
  /* Matricule discret en bas de la carte */
  .cv-mat{position:absolute;left:26px;bottom:18px;z-index:2;display:flex;align-items:center;gap:8px}
  .cv-mat .lbl{font-size:8px;color:#6a7488;letter-spacing:.14em;text-transform:uppercase;font-weight:700}
  .cv-mat .val{font-family:monospace;font-size:13px;color:var(--navy);font-weight:800;letter-spacing:.08em;
      background:linear-gradient(135deg,#fbf3d8,#f5e7b8);padding:3px 10px;border-radius:6px;border:1px solid var(--gold)}

  /* VERSO */
  .cvb-in{position:absolute;inset:0;padding:26px 26px 20px 90px;z-index:2;display:flex;flex-direction:column;align-items:center}
  .cvb-brand{display:flex;flex-direction:column;align-items:center;gap:2px;margin-top:2px}
  .cvb-brand img{width:58px;height:58px;object-fit:contain}
  .cvb-brand .bt{font-family:Georgia,serif;font-size:21px;font-weight:700;letter-spacing:.12em;color:var(--navy)}
  .cvb-brand .bs{font-size:7.5px;letter-spacing:.08em;color:var(--gold3);font-style:italic}
  /* Services : grille 2×2 centrée, ne chevauche pas le QR */
  .cvb-services{display:grid;grid-template-columns:1fr 1fr;gap:12px 22px;margin:16px 0 0;margin-right:130px}
  .cvb-serv{display:flex;flex-direction:column;align-items:center;gap:5px;width:78px;text-align:center}
  .cvb-serv .si{width:28px;height:28px;stroke:var(--navy);fill:none;stroke-width:1.6}
  .cvb-serv .sl{font-size:7.5px;font-weight:700;color:var(--navy);letter-spacing:.02em;line-height:1.25;text-transform:uppercase}
  /* QR en bas à droite, hors de la zone des services */
  .cvb-qr{position:absolute;right:32px;top:50%;transform:translateY(-45%);text-align:center;width:116px;z-index:3}
  .cvb-qr .qbox{width:96px;height:96px;background:#fff;border-radius:14px;padding:7px;margin:0 auto;
      border:2px solid var(--navy);box-shadow:0 6px 16px rgba(14,36,82,.2)}
  .cvb-qr .qbox img{width:100%;height:100%;display:block}
  .cvb-qr .qpill{display:inline-flex;align-items:center;gap:5px;background:var(--navy);color:#fff;
      font-size:8px;font-weight:700;padding:4px 12px;border-radius:999px;margin-top:8px;letter-spacing:.04em}
  .cvb-qr .qsub{font-size:7px;color:#6a7488;margin-top:4px}
  .cvb-foot{position:absolute;left:90px;right:0;bottom:30px;display:flex;justify-content:center;gap:12px;
      padding:0 20px;flex-wrap:wrap}
  .cvb-fi{display:flex;align-items:center;gap:5px;font-size:8px;color:var(--ink)}
  .cvb-fi .ic{width:11px;height:11px;stroke:var(--gold3)}
  .cvb-band{position:absolute;left:0;right:0;bottom:0;background:var(--navy);color:var(--gold2);
      text-align:center;padding:7px;font-size:8px;font-weight:700;letter-spacing:.16em}
  .cvb-qr .qbox img{width:100%;height:100%;display:block}
  .cvb-qr .qpill{display:inline-flex;align-items:center;gap:5px;background:var(--navy);color:#fff;
      font-size:8px;font-weight:700;padding:4px 12px;border-radius:999px;margin-top:8px;letter-spacing:.04em}
  .cvb-qr .qsub{font-size:7px;color:#6a7488;margin-top:4px}
  .cvb-foot{position:absolute;left:90px;right:0;bottom:30px;display:flex;justify-content:center;gap:12px;
      padding:0 20px;flex-wrap:wrap}
  .cvb-fi{display:flex;align-items:center;gap:5px;font-size:8px;color:var(--ink)}
  .cvb-fi .ic{width:11px;height:11px;stroke:var(--gold3)}
  .cvb-band{position:absolute;left:0;right:0;bottom:0;background:var(--navy);color:var(--gold2);
      text-align:center;padding:7px;font-size:8px;font-weight:700;letter-spacing:.16em}

  /* ==================== BADGE VERTICAL ==================== */
  .bg{width:300px;height:470px;min-width:300px;max-width:300px;flex:none;box-sizing:border-box;
      border-radius:22px;position:relative;overflow:hidden;background:#fdfdfb;
      box-shadow:0 22px 55px rgba(14,36,82,.32)}
  .bg-top{position:absolute;top:0;left:0;right:0;height:150px;overflow:hidden;z-index:0}
  .bg-top .navy{position:absolute;width:520px;height:340px;left:-110px;top:-210px;border-radius:50%;
      background:linear-gradient(135deg,#0a1f44,#1c3f7e)}
  .bg-top .gold{position:absolute;width:520px;height:340px;left:-90px;top:-194px;border-radius:50%;
      background:transparent;border:8px solid transparent;border-bottom-color:var(--gold)}
  .bg-bot{position:absolute;bottom:0;left:0;right:0;height:120px;overflow:hidden;z-index:0}
  .bg-bot .navy{position:absolute;width:520px;height:320px;right:-110px;bottom:-210px;border-radius:50%;
      background:linear-gradient(135deg,#0a1f44,#1c3f7e)}
  .bg-bot .gold{position:absolute;width:520px;height:320px;right:-90px;bottom:-194px;border-radius:50%;
      background:transparent;border:7px solid transparent;border-top-color:var(--gold)}
  .bg-in{position:absolute;inset:0;z-index:2;display:flex;flex-direction:column;align-items:center;padding:20px 20px 26px;text-align:center}
  .bg-brand{display:flex;flex-direction:column;align-items:center;gap:2px}
  .bg-brand img{width:52px;height:52px;object-fit:contain}
  .bg-brand .logo-pastille{width:72px;height:72px;border-radius:50%;display:grid;place-items:center;
      background:radial-gradient(circle,#ffffff,#f2f4f8);box-shadow:0 4px 12px rgba(0,0,0,.25),0 0 0 2px rgba(212,165,38,.5);margin-bottom:2px}
  .bg-brand .bt{font-family:Georgia,serif;font-size:16px;font-weight:700;letter-spacing:.1em;color:#fff}
  .bg-brand .bs{font-size:6px;letter-spacing:.08em;color:var(--gold2);font-style:italic}
  .bg-photo{width:118px;height:118px;border-radius:50%;object-fit:cover;margin:10px 0 10px;
      border:4px solid var(--gold);box-shadow:0 0 0 4px #fff,0 10px 24px rgba(14,36,82,.3)}
  .bg-photo.ini{display:grid;place-items:center;font-size:44px;font-weight:800;color:var(--navy);
      background:linear-gradient(135deg,var(--gold2),var(--gold3))}
  .bg-nom{font-size:20px;font-weight:800;color:var(--navy);line-height:1.05}
  .bg-fonc{font-size:10px;color:var(--gold3);font-weight:700;margin-top:4px;text-transform:uppercase;letter-spacing:.05em}
  .bg-uline{width:80px;height:2px;background:linear-gradient(90deg,transparent,var(--gold),transparent);margin:7px 0}
  .bg-meta{display:flex;flex-direction:column;gap:4px;width:100%;padding:0 14px}
  .bg-mrow{display:flex;align-items:center;justify-content:center;gap:6px;font-size:9px;color:var(--ink)}
  .bg-mrow b{color:var(--navy)}
  .bg-mrow .lbl{color:#6a7488;font-weight:600}
  .bg-pied{margin-top:auto;display:flex;flex-direction:column;align-items:center;width:100%}
  .bg-qr{width:78px;height:78px;background:#fff;border-radius:10px;padding:5px;margin:0 0 6px;
      border:2px solid var(--navy)}
  .bg-qr img{width:100%;height:100%;display:block}
  .bg-mat{font-family:monospace;font-size:14px;font-weight:700;color:var(--gold2);letter-spacing:.06em}
  .bg-matlbl{font-size:7px;color:var(--gold3);letter-spacing:.16em;text-transform:uppercase;margin-top:6px;font-weight:700}

  .cap{font-size:12px;color:#6b7688;text-align:center}
  /* Sur petit écran : permettre le défilement sans déformer la carte */
  .stage{overflow-x:auto;-webkit-overflow-scrolling:touch}
  @media print{
    body{background:#fff;padding:0}
    .barre,.cap{display:none}
    .stage{gap:12px}
    @page{margin:12mm}
  }
</style>
</head>
<body>
<div class="barre">
  <button class="btn-p" onclick="window.print()">🖨️ Imprimer / Enregistrer en PDF</button>
  <?php if (($b['type_porteur'] ?? '') !== 'externe'): ?>
  <a class="btn-d" href="badge-print.php?id=<?= $b['id'] ?>&format=<?= $format==='carte'?'badge':'carte' ?>">
    <?= $format==='carte' ? '🪪 Voir le badge' : '💳 Voir la carte' ?></a>
  <?php endif; ?>
  <a class="btn-g" href="badges.php">← Retour</a>
</div>

<div class="stage">
<?php if ($format === 'carte'): ?>
  <!-- RECTO -->
  <div class="cv">
    <div class="swoosh"><i class="navy"></i><i class="gold"></i><i class="gold2"></i></div>
    <div class="cv-in">
      <div class="cv-brand">
        <img src="<?= e($logo) ?>" alt="">
        <div>
          <div class="bt"><?= e(mb_strtoupper($ent)) ?></div>
          <div class="bl"></div>
          <?php if($slogan): ?><div class="bs"><?= e($slogan) ?></div><?php endif; ?>
        </div>
      </div>
      <div class="cv-id">
        <div class="nom"><?= e($b['nom']) ?></div>
        <div class="fonc"><?= e($b['poste'] ?: 'Membre') ?></div>
        <div class="uline"></div>
      </div>
      <div class="cv-info">
        <?php if($b['email']): ?>
        <div class="cv-row"><svg class="ic" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg><span class="lbl">Email</span> <b><?= e($b['email']) ?></b></div>
        <?php endif; ?>
        <?php if($b['telephone']): ?>
        <div class="cv-row"><svg class="ic" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2"/></svg><span class="lbl">Tél</span> <b><?= e($b['telephone']) ?></b></div>
        <?php endif; ?>
        <?php if($b['departement']): ?>
        <div class="cv-row"><svg class="ic" viewBox="0 0 24 24"><path d="M3 21h18M5 21V7l7-4 7 4v14"/></svg><span class="lbl">Département</span> <b><?= e($b['departement']) ?></b></div>
        <?php endif; ?>
        <div class="cv-row"><svg class="ic" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 9h18M8 3v4M16 3v4"/><path d="M9 15l2 2 4-4"/></svg><span class="lbl">Validité</span> <b><?= bdg_date($b['date_expiration']) ?></b></div>
      </div>
    </div>
    <?php if ($photo): ?><img class="cv-photo" src="<?= e($photo) ?>" alt="">
    <?php else: ?><div class="cv-photo ini"><?= badge_initiales($b['nom']) ?></div><?php endif; ?>
    <div class="cv-mat"><span class="lbl"><?= ($b['type_porteur'] ?? '')==='externe' ? 'Identifiant' : 'Matricule' ?></span><span class="val"><?= e($b['matricule']) ?></span></div>
  </div>

  <!-- VERSO -->
  <div class="cv">
    <div class="swoosh l"><i class="navy"></i><i class="gold"></i><i class="gold2"></i></div>
    <div class="cvb-in">
      <div class="cvb-brand">
        <img src="<?= e($logo) ?>" alt="">
        <div class="bt"><?= e(mb_strtoupper($ent)) ?></div>
        <?php if($slogan): ?><div class="bs"><?= e($slogan) ?></div><?php endif; ?>
      </div>
      <div class="cvb-services">
        <div class="cvb-serv"><svg class="si" viewBox="0 0 24 24"><path d="M4 15h16M6 15a6 6 0 0112 0M12 9V7"/></svg><span class="sl">Traiteur</span></div>
        <div class="cvb-serv"><svg class="si" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 9h18M8 3v4M16 3v4"/></svg><span class="sl">Événementiel</span></div>
        <div class="cvb-serv"><svg class="si" viewBox="0 0 24 24"><path d="M3 8l9-4 9 4-9 4-9-4zM3 8v8l9 4 9-4V8"/></svg><span class="sl">Fournitures de bureau</span></div>
        <div class="cvb-serv"><svg class="si" viewBox="0 0 24 24"><path d="M8 12l3 3 5-6M4 8l4-4 3 2 3-2 4 4"/></svg><span class="sl">Services divers</span></div>
      </div>
    </div>
    <div class="cvb-qr">
      <div class="qbox"><img src="<?= $qrUri ?>" alt="QR"></div>

    </div>
    <div class="cvb-foot">
      <?php if($adrEnt): ?><div class="cvb-fi"><svg class="ic" viewBox="0 0 24 24"><path d="M12 21s7-6 7-11a7 7 0 10-14 0c0 5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg><?= e($adrEnt) ?></div><?php endif; ?>
      <?php if($siteEnt): ?><div class="cvb-fi"><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/></svg><?= e($siteEnt) ?></div><?php endif; ?>
      <?php if($telEnt): ?><div class="cvb-fi"><svg class="ic" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2"/></svg><?= e($telEnt) ?></div><?php endif; ?>
      <?php if($emailEnt): ?><div class="cvb-fi"><svg class="ic" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg><?= e($emailEnt) ?></div><?php endif; ?>
    </div>
    <div class="cvb-band">PROFESSIONNALISME · INNOVATION · ENGAGEMENT · EXCELLENCE</div>
  </div>
  <div class="cap">Carte professionnelle — recto &amp; verso (85,6 × 54 mm)</div>

<?php else: ?>
  <!-- BADGE VERTICAL -->
  <div class="bg">
    <div class="bg-top"><i class="navy"></i><i class="gold"></i></div>
    <div class="bg-bot"><i class="navy"></i><i class="gold"></i></div>
    <div class="bg-in">
      <div class="bg-brand">
        <div class="logo-pastille"><img src="<?= e($logo) ?>" alt=""></div>
        <div class="bt"><?= e(mb_strtoupper($ent)) ?></div>
        <?php if($slogan): ?><div class="bs"><?= e($slogan) ?></div><?php endif; ?>
      </div>
      <?php if ($photo): ?><img class="bg-photo" src="<?= e($photo) ?>" alt="">
      <?php else: ?><div class="bg-photo ini"><?= badge_initiales($b['nom']) ?></div><?php endif; ?>
      <div class="bg-nom"><?= e($b['nom']) ?></div>
      <div class="bg-fonc"><?= e($b['poste'] ?: 'Membre') ?></div>
      <div class="bg-uline"></div>
      <div class="bg-meta">
        <?php if($b['groupe_sanguin']): ?><div class="bg-mrow"><span class="lbl">Groupe sanguin :</span> <b><?= e($b['groupe_sanguin']) ?></b></div><?php endif; ?>
        <?php if($b['departement']): ?><div class="bg-mrow"><span class="lbl">Département :</span> <b><?= e($b['departement']) ?></b></div><?php endif; ?>
        <div class="bg-mrow"><span class="lbl">Validité :</span> <b><?= bdg_date($b['date_expiration']) ?></b></div>
      </div>
      <div class="bg-pied">
        <div class="bg-qr"><img src="<?= $qrUri ?>" alt="QR"></div>
        <div class="bg-matlbl"><?= ($b['type_porteur'] ?? '')==='externe' ? 'Identifiant' : 'Matricule' ?></div><div class="bg-mat"><?= e($b['matricule']) ?></div>
      </div>
    </div>
  </div>
  <div class="cap">Badge vertical (54 × 86 mm)</div>
<?php endif; ?>
</div>
</body>
</html>
