<?php
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/reseaux.php';
$s = get_settings($pdo);

$services = $pdo->query("SELECT * FROM services WHERE actif=1 ORDER BY ordre, id")->fetchAll();
$categories = $pdo->query("SELECT * FROM categories WHERE actif=1 ORDER BY ordre, id")->fetchAll();
$plats = $pdo->query("SELECT * FROM plats WHERE actif=1 ORDER BY categorie_id, ordre, id")->fetchAll();
/* Galerie : on charge les photos actives avec leur album, pour permettre
   au visiteur de filtrer par type de prestation. */
$galerie = $pdo->query("SELECT g.*, a.nom AS album_nom, a.icone AS album_icone, a.id AS aid
                        FROM galerie g LEFT JOIN galerie_albums a ON a.id = g.album_id
                        WHERE COALESCE(g.actif,1) = 1
                        ORDER BY g.ordre, g.id DESC LIMIT 40")->fetchAll();
$galerieAlbums = [];
foreach ($galerie as $g) {
    if (!empty($g['aid']) && !isset($galerieAlbums[$g['aid']])) {
        $galerieAlbums[$g['aid']] = ['nom' => $g['album_nom'], 'icone' => $g['album_icone'], 'n' => 0];
    }
    if (!empty($g['aid'])) $galerieAlbums[$g['aid']]['n']++;
}
$temoignages = $pdo->query("SELECT * FROM temoignages WHERE statut='valide' ORDER BY id DESC LIMIT 6")->fetchAll();
$videos = $pdo->query("SELECT * FROM videos WHERE actif=1 ORDER BY ordre, id DESC LIMIT 6")->fetchAll();

$platsParCat = [];
foreach ($plats as $p) $platsParCat[$p['categorie_id']][] = $p;
$f = flash();
?>
<!DOCTYPE html>
<html lang="fr" data-space="public">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($s['nom_entreprise']) ?> — <?= e($s['slogan']) ?></title>
<meta name="description" content="<?= e($s['hero_texte']) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('assets/css/glass.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/css/ios26.css') ?>">
<script src="<?= asset('assets/js/theme.js') ?>"></script>
<?php $pwaBase='.'; include __DIR__.'/config/pwa_head.php'; ?>
</head>
<body>
<div class="aurora"></div>

<!-- Barre supérieure -->
<header class="topbar glass-strong">
  <div class="topbar-row">
    <a class="brand" href="#accueil"><?= logo_html('.', 'brand-dot') ?><?= e($s['nom_entreprise']) ?></a>
    <div class="topbar-actions">
      <button class="theme-toggle" onclick="toggleTheme()" title="Changer de thème" aria-label="Changer de thème"><span data-theme-icon>☀️</span></button>
      <a class="login-pill" href="login.php" title="Espace de gestion">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>
        <span class="login-pill-txt">Connexion</span>
      </a>
    </div>
  </div>
  <?php if (!empty($services)): ?>
  <!-- Défilement des services (à l'intérieur de la barre) -->
  <div class="svc-ticker" aria-label="Nos services">
    <div class="svc-ticker-track">
      <?php for ($rep = 0; $rep < 2; $rep++): // dupliqué pour une boucle continue ?>
        <?php foreach ($services as $sv): ?>
          <a href="#services" class="svc-ticker-item">
            <span class="svc-ticker-ico"><?= e($sv['icone'] ?: '✨') ?></span>
            <span class="svc-ticker-nom"><?= e($sv['nom']) ?></span>
            <span class="svc-ticker-sep">✦</span>
          </a>
        <?php endforeach; ?>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</header>

<!-- Hero -->
<section class="hero" id="accueil">
  <div class="wrap hero-grid">
    <div>
      <div class="hero-brand-name"><?= e($s['nom_entreprise']) ?></div>
      <span class="eyebrow"><?= e($s['hero_eyebrow'] ?? 'Traiteur d\'exception') ?></span>
      <h1><?php
        $mots = explode(' ', $s['hero_titre']);
        $dernier = array_pop($mots);
        echo e(implode(' ', $mots)) . ' <em>' . e($dernier) . '</em>';
      ?></h1>
      <p class="lede"><?= e($s['hero_texte']) ?></p>
      <div class="hero-cta">
        <a href="#devis" class="btn btn-gold"><?= e($s['cta_devis'] ?? 'Demander un devis') ?></a>
        <a href="#menu" class="btn btn-glass"><?= e($s['cta_menu'] ?? 'Découvrir le menu') ?></a>
      </div>
    </div>
    <div style="position:relative">
      <div class="cuisine-stage" aria-hidden="true">
        <div class="cs-particles">
          <span></span><span></span><span></span><span></span><span></span><span></span>
          <span></span><span></span><span></span><span></span><span></span><span></span>
        </div>
        <svg class="cs-svg" viewBox="0 0 400 470" xmlns="http://www.w3.org/2000/svg">
          <defs>
            <radialGradient id="glow" cx="50%" cy="42%" r="55%">
              <stop offset="0%" stop-color="#d4a526" stop-opacity="0.55"/>
              <stop offset="45%" stop-color="#d4a526" stop-opacity="0.14"/>
              <stop offset="100%" stop-color="#d4a526" stop-opacity="0"/>
            </radialGradient>
            <linearGradient id="silver" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="#ffffff"/>
              <stop offset="18%" stop-color="#e9edf3"/>
              <stop offset="50%" stop-color="#aab3c2"/>
              <stop offset="78%" stop-color="#d7dde6"/>
              <stop offset="100%" stop-color="#8b94a5"/>
            </linearGradient>
            <linearGradient id="silverShine" x1="0" y1="0" x2="1" y2="0">
              <stop offset="0%" stop-color="#ffffff" stop-opacity="0"/>
              <stop offset="48%" stop-color="#ffffff" stop-opacity="0.85"/>
              <stop offset="100%" stop-color="#ffffff" stop-opacity="0"/>
            </linearGradient>
            <linearGradient id="plateGrad" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="#ffffff"/>
              <stop offset="100%" stop-color="#c9d0dc"/>
            </linearGradient>
            <radialGradient id="sauce" cx="50%" cy="40%" r="60%">
              <stop offset="0%" stop-color="#f6a94b"/>
              <stop offset="100%" stop-color="#b5641d"/>
            </radialGradient>
          </defs>

          <!-- Halo lumineux pulsant -->
          <ellipse class="cs-glow" cx="200" cy="215" rx="180" ry="180" fill="url(#glow)"/>

          <!-- Reflet / ombre au sol -->
          <ellipse cx="200" cy="392" rx="150" ry="26" fill="#000000" opacity="0.18"/>

          <!-- Assiette -->
          <g class="cs-plate">
            <ellipse cx="200" cy="360" rx="150" ry="46" fill="url(#plateGrad)"/>
            <ellipse cx="200" cy="356" rx="150" ry="44" fill="#ffffff"/>
            <ellipse cx="200" cy="356" rx="118" ry="33" fill="#eef1f6"/>
            <ellipse cx="200" cy="354" rx="92" ry="25" fill="#ffffff"/>

            <!-- Vapeur -->
            <g class="cs-steam" fill="none" stroke="#ffffff" stroke-width="5" stroke-linecap="round" opacity="0.7">
              <path class="st st1" d="M175 330 q-14 -26 0 -52 q14 -26 0 -52"/>
              <path class="st st2" d="M200 326 q-16 -30 0 -60 q16 -30 0 -60"/>
              <path class="st st3" d="M226 330 q14 -26 0 -52 q-14 -26 0 -52"/>
            </g>

            <!-- Plat signature -->
            <g class="cs-food">
              <ellipse cx="200" cy="352" rx="60" ry="18" fill="url(#sauce)"/>
              <ellipse cx="200" cy="345" rx="46" ry="15" fill="#e8892f"/>
              <ellipse cx="188" cy="340" rx="16" ry="11" fill="#f4b56a"/>
              <ellipse cx="214" cy="342" rx="14" ry="10" fill="#f4b56a"/>
              <circle cx="200" cy="334" r="8" fill="#c0431f"/>
              <path d="M196 330 q4 -12 8 0" stroke="#3f7d3a" stroke-width="4" fill="none" stroke-linecap="round"/>
              <circle cx="184" cy="346" r="3.4" fill="#8bc34a"/>
              <circle cx="220" cy="348" r="3" fill="#8bc34a"/>
              <circle cx="205" cy="350" r="2.6" fill="#c0431f"/>
            </g>
          </g>

          <!-- Garnitures en orbite -->
          <g class="cs-orbit-tilt">
            <g class="cs-orbit">
              <circle cx="330" cy="200" r="9" fill="#8bc34a"/>
              <circle cx="70"  cy="200" r="8" fill="#e0453a"/>
              <circle cx="200" cy="70"  r="7" fill="#d4a526"/>
              <circle cx="200" cy="330" r="6" fill="#f0e14a"/>
              <circle cx="290" cy="110" r="5" fill="#f28b3c"/>
              <circle cx="110" cy="290" r="5" fill="#6fae3f"/>
            </g>
          </g>

          <!-- Cloche argentée (se soulève en boucle) -->
          <g class="cs-cloche">
            <path d="M96 356 q0 -150 104 -150 q104 0 104 150 Z" fill="url(#silver)"/>
            <path class="cs-cloche-shine" d="M120 356 q0 -120 80 -120 q10 0 18 2 q-70 20 -70 118 Z" fill="url(#silverShine)" opacity="0.5"/>
            <rect x="150" y="352" width="100" height="12" rx="6" fill="#9aa3b3"/>
            <ellipse cx="200" cy="206" rx="12" ry="9" fill="#c7cedb"/>
            <circle cx="200" cy="198" r="9" fill="url(#silver)" stroke="#8b94a5" stroke-width="1.5"/>
          </g>
        </svg>
      </div>
      <div class="hero-chip one glass-strong">⭐ <span><?= e($s['hero_chip1'] ?? '') ?></span></div>
    </div>
  </div>
</section>

<!-- Services -->
<section id="services">
  <div class="wrap">
    <div class="sec-head reveal">
      <span class="eyebrow" style="justify-content:center"><?= e($s['sec_services_eyebrow'] ?? 'Nos prestations') ?></span>
      <h2><?= e($s['sec_services_titre'] ?? 'Un service pour chaque occasion') ?></h2>
      <p><?= e($s['apropos']) ?></p>
    </div>
    <div class="services-grid">
      <?php foreach ($services as $sv): ?>
      <?php $points = array_filter(array_map('trim', explode("\n", (string)($sv['details'] ?? '')))); ?>
      <article class="service-card glass reveal">
        <div class="ico"><?= e($sv['icone']) ?></div>
        <h3><?= e($sv['nom']) ?></h3>
        <p><?= e($sv['description']) ?></p>
        <?php if ($points): ?>
        <ul class="sc-points">
          <?php foreach ($points as $pt): ?><li><?= e($pt) ?></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php if (!empty($sv['prix_indicatif'])): ?>
        <div class="sc-prix"><?= e($sv['prix_indicatif']) ?></div>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Menu -->
<section id="menu">
  <div class="wrap">
    <div class="sec-head reveal">
      <span class="eyebrow" style="justify-content:center"><?= e($s['sec_menu_eyebrow'] ?? 'La carte') ?></span>
      <h2><?= e($s['sec_menu_titre'] ?? 'Notre menu du moment') ?></h2>
      <p><?= e($s['sec_menu_texte'] ?? 'Sélectionnez une catégorie et laissez-vous tenter.') ?></p>
    </div>
    <div class="menu-tabs glass reveal">
      <?php foreach ($categories as $i => $c): ?>
      <button class="menu-tab <?= $i === 0 ? 'active' : '' ?>" data-cat="<?= $c['id'] ?>"><?= e($c['icone']) ?> <?= e($c['nom']) ?></button>
      <?php endforeach; ?>
    </div>
    <?php foreach ($categories as $i => $c): ?>
    <div class="menu-grid menu-panel" data-panel="<?= $c['id'] ?>" <?= $i > 0 ? 'hidden' : '' ?>>
      <?php
        $pmin = (int)($c['prix_min'] ?? 0); $pmax = (int)($c['prix_max'] ?? 0);
        if ($pmin > 0 || $pmax > 0):
          if ($pmin > 0 && $pmax > 0 && $pmax !== $pmin) $interv = number_format($pmin,0,',',' ').' – '.number_format($pmax,0,',',' ').' FCFA';
          elseif ($pmin > 0) $interv = 'À partir de '.number_format($pmin,0,',',' ').' FCFA';
          else $interv = "Jusqu'à ".number_format($pmax,0,',',' ').' FCFA';
      ?>
      <div class="cat-prix glass" style="grid-column:1/-1"><span class="cat-prix-label"><?= e($c['icone']) ?> <?= e($c['nom']) ?></span><span class="cat-prix-val">💰 <?= $interv ?></span></div>
      <?php endif; ?>
      <?php foreach ($platsParCat[$c['id']] ?? [] as $p): ?>
      <figure class="dish reveal">
        <div class="dish-card glass">
          <?php if ($p['image']): ?><img src="uploads/<?= e($p['image']) ?>" alt="<?= e($p['nom']) ?>" loading="lazy">
          <?php else: ?><span class="dish-emoji"><?= e($c['icone']) ?></span><?php endif; ?>
          <?php if ($p['populaire']): ?><span class="dish-pop">🔥 Populaire</span><?php endif; ?>
        </div>
        <figcaption class="dish-caption">
          <span class="dish-name"><?= e($p['nom']) ?></span>
          <?php if (trim((string)$p['description']) !== ''): ?><span class="dish-desc"><?= e($p['description']) ?></span><?php endif; ?>
        </figcaption>
      </figure>
      <?php endforeach; ?>
      <?php if (empty($platsParCat[$c['id']])): ?>
        <p style="color:var(--ink-faint);grid-column:1/-1;text-align:center;padding:30px">Aucun plat dans cette catégorie pour le moment.</p>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- Galerie -->
<?php if ($galerie): ?>
<section id="galerie">
  <div class="wrap">
    <div class="sec-head reveal">
      <span class="eyebrow" style="justify-content:center"><?= e($s['sec_galerie_eyebrow'] ?? 'En images') ?></span>
      <h2><?= e($s['sec_galerie_titre'] ?? 'Nos plus belles réalisations') ?></h2>
    </div>
    <?php if (count($galerieAlbums) > 1): ?>
    <div class="gal-filtres reveal">
      <button type="button" class="gf actif" data-album="tous">✨ Tout voir
        <span><?= count($galerie) ?></span></button>
      <?php foreach ($galerieAlbums as $aid => $al): ?>
      <button type="button" class="gf" data-album="<?= (int)$aid ?>">
        <?= e($al['icone']) ?> <?= e($al['nom']) ?> <span><?= (int)$al['n'] ?></span></button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="gal-grid" id="gal-grid">
      <?php foreach ($galerie as $i => $g):
        /* Les photos verticales prennent deux rangées, les larges deux colonnes :
           la mosaïque respire au lieu d'aligner des vignettes identiques. */
        /* Chaque photo garde ses proportions : la disposition en colonnes
           s'adapte d'elle-même, sans classe particulière. */
        $forme = '';
      ?>
      <figure class="gal-item reveal <?= $forme ?>" data-album="<?= (int)($g['aid'] ?? 0) ?>"
              data-i="<?= $i ?>" data-src="uploads/<?= e($g['image']) ?>"
              data-titre="<?= e($g['titre']) ?>" data-legende="<?= e($g['description'] ?? '') ?>">
        <img src="uploads/<?= e($g['image']) ?>" alt="<?= e($g['titre']) ?>" loading="lazy">
        <figcaption>
          <?php if ($g['album_nom']): ?><em><?= e($g['album_icone']) ?> <?= e($g['album_nom']) ?></em><?php endif; ?>
          <?php if ($g['titre']): ?><strong><?= e($g['titre']) ?></strong><?php endif; ?>
          <?php if (!empty($g['description'])): ?><span><?= e($g['description']) ?></span><?php endif; ?>
        </figcaption>
        <span class="gal-loupe">⤢</span>
      </figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Vidéos -->
<?php if ($videos): ?>
<section id="videos">
  <div class="wrap">
    <div class="sec-head reveal">
      <span class="eyebrow" style="justify-content:center"><?= e($s['sec_videos_eyebrow'] ?? 'En vidéo') ?></span>
      <h2><?= e($s['sec_videos_titre'] ?? 'Nos prestations en action') ?></h2>
      <p><?= e($s['sec_videos_texte'] ?? '') ?></p>
    </div>
    <?php /* La vidéo ne se charge qu'au clic : la page reste légère, ce qui
             compte sur une connexion mobile. Avant cela, on n'affiche qu'une
             vignette dans un cadre lumineux. */ ?>
    <div class="video-grid">
      <?php foreach ($videos as $v):
        $estFichier = ($v['type'] === 'fichier' && $v['fichier']);
        $source = $estFichier ? 'uploads/' . $v['fichier'] : video_embed($v['url'] ?? '');
        $poster = $v['miniature'] ? 'uploads/' . $v['miniature'] : '';
      ?>
      <figure class="video-item reveal" data-type="<?= $estFichier ? 'fichier' : 'iframe' ?>"
              data-src="<?= e($source) ?>" data-lien="<?= e($v['url'] ?? '') ?>"
              data-titre="<?= e($v['titre']) ?>">
        <div class="video-frame">
          <div class="vf-cadre"></div>
          <?php if ($poster): ?>
          <img class="vf-poster" src="<?= e($poster) ?>" alt="<?= e($v['titre']) ?>" loading="lazy">
          <?php else: ?>
          <div class="vf-poster vf-defaut"><span>🎬</span></div>
          <?php endif; ?>
          <button type="button" class="vf-play" aria-label="Lire la vidéo">
            <span class="vp-onde"></span><span class="vp-onde d2"></span>
            <span class="vp-tri">▶</span>
          </button>
          <div class="vf-lecteur"></div>
        </div>
        <figcaption>
          <h3><?= e($v['titre']) ?></h3>
          <?php if ($v['description']): ?><p><?= e($v['description']) ?></p><?php endif; ?>
        </figcaption>
      </figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>
<!-- Visionneuse plein écran : photos de la galerie -->
<div class="visio" id="visio" hidden>
  <button type="button" class="vi-fermer" aria-label="Fermer">✕</button>
  <button type="button" class="vi-prec" aria-label="Photo précédente">‹</button>
  <figure class="vi-scene">
    <img id="vi-img" src="" alt="">
    <figcaption>
      <strong id="vi-titre"></strong>
      <span id="vi-legende"></span>
      <em id="vi-compteur"></em>
    </figcaption>
  </figure>
  <button type="button" class="vi-suiv" aria-label="Photo suivante">›</button>
</div>

<section id="avis">
  <div class="wrap">
    <div class="sec-head reveal">
      <span class="eyebrow" style="justify-content:center"><?= e($s['sec_avis_eyebrow'] ?? 'Ils nous font confiance') ?></span>
      <h2><?= e($s['sec_avis_titre'] ?? 'Avis de nos clients') ?></h2>
    </div>
    <?php if ($temoignages): ?>
    <div class="temo-grid">
      <?php foreach ($temoignages as $t): ?>
      <article class="temo glass reveal">
        <div class="stars"><?= str_repeat('★', (int)$t['note']) . str_repeat('☆', 5 - (int)$t['note']) ?></div>
        <p>« <?= e($t['texte']) ?> »</p>
        <div class="who"><span class="avatar"><?= e(mb_strtoupper(mb_substr($t['nom'], 0, 1))) ?></span><?= e($t['nom']) ?></div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="avis-form glass reveal" id="laisser-avis">
      <h3>⭐ Laissez votre avis</h3>
      <p>Vous avez fait appel à nos services ? Partagez votre expérience.</p>
      <form id="avisForm">
        <div class="avis-row">
          <input class="input" name="nom" placeholder="Votre nom *" required>
          <select class="input" name="note" aria-label="Note">
            <option value="5">★★★★★ (5/5)</option><option value="4">★★★★ (4/5)</option>
            <option value="3">★★★ (3/5)</option><option value="2">★★ (2/5)</option><option value="1">★ (1/5)</option>
          </select>
        </div>
        <textarea class="input" name="texte" placeholder="Votre message *" required style="min-height:90px;margin-top:10px"></textarea>
        <button class="btn btn-gold" type="submit" style="margin-top:12px">Envoyer mon avis</button>
        <span id="avisMsg" style="margin-left:12px;font-size:14px"></span>
      </form>
    </div>
  </div>
</section>

<!-- Proforma & contact -->
<section id="devis">
  <div class="wrap">
    <div class="sec-head reveal">
      <span class="eyebrow" style="justify-content:center"><?= e($s['sec_devis_eyebrow'] ?? 'Parlons de votre projet') ?></span>
      <h2><?= e($s['sec_devis_titre'] ?? 'Demandez votre proforma gratuit') ?></h2>
      <p><?= e($s['sec_devis_texte'] ?? 'Réponse sous 24h.') ?></p>
    </div>
    <?php if ($f): ?><div class="flash <?= $f['type'] === 'error' ? 'error' : '' ?>"><?= e($f['msg']) ?></div><?php endif; ?>
    <div class="devis-grid">
      <aside class="devis-info glass reveal">
        <h3>Nous contacter</h3>
        <div class="info-line"><span class="ic">📞</span><div><strong style="color:var(--ink)"><?= e($s['telephone']) ?></strong><br><?= e($s['horaires']) ?></div></div>
        <div class="info-line"><span class="ic">✉️</span><div><?= e($s['email']) ?></div></div>
        <div class="info-line"><span class="ic">📍</span><div><?= e($s['adresse']) ?></div></div>
        <?php if ($s['whatsapp']): ?>
        <a class="btn btn-glass wa-btn" style="justify-content:flex-start" href="https://wa.me/<?= e(preg_replace('/\D/', '', $s['whatsapp'])) ?>" target="_blank" rel="noopener"><svg class="wa-ico" viewBox="0 0 32 32" width="20" height="20" aria-hidden="true"><path fill="#25D366" d="M16 0a16 16 0 0 0-13.7 24.2L0 32l8-2.1A16 16 0 1 0 16 0z"/><path fill="#fff" d="M12.1 8.6c-.3-.6-.5-.6-.8-.6h-.7c-.2 0-.6.1-.9.4-.3.3-1.2 1.2-1.2 2.9s1.2 3.4 1.4 3.6c.2.3 2.4 3.8 6 5.2 3 1.2 3.6 1 4.2.9.6-.1 2-.8 2.3-1.6.3-.8.3-1.5.2-1.6-.1-.2-.3-.2-.7-.4-.3-.2-2-1-2.3-1.1-.3-.1-.5-.2-.8.2-.2.3-.8 1.1-1 1.3-.2.2-.4.2-.7.1-.4-.2-1.5-.6-2.9-1.8-1.1-1-1.8-2.2-2-2.5-.2-.4 0-.5.1-.7l.5-.6c.2-.2.2-.3.4-.6.1-.2 0-.4 0-.6s-.7-1.8-1-2.4z"/></svg> Écrire sur WhatsApp</a>
        <?php endif; ?>
      </aside>
      <form class="devis-form glass reveal" method="post" action="api/devis.php">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <div class="form-grid">
          <div class="field"><label>Nom complet *</label><input class="input" name="nom" required maxlength="100" placeholder="Votre nom"></div>
          <div class="field"><label>Téléphone *</label><input class="input" name="telephone" required maxlength="30" placeholder="+225 ..."></div>
          <div class="field"><label>E-mail</label><input class="input" type="email" name="email" maxlength="120" placeholder="vous@exemple.com"></div>
          <div class="field"><label>Type d'événement *</label>
            <select class="input" name="type_evenement" required>
              <option value="">Choisir…</option>
              <option>Mariage</option>
              <option>Fiançailles / Dot</option>
              <option>Baptême</option>
              <option>Communion</option>
              <option>Anniversaire</option>
              <option>Réception privée</option>
              <option>Événement d'entreprise</option>
              <option>Séminaire</option>
              <option>Conférence</option>
              <option>Assemblée générale</option>
              <option>Lancement de produit</option>
              <option>Team building</option>
              <option>Cocktail dînatoire</option>
              <option>Buffet</option>
              <option>Pause café</option>
              <option>Dîner de gala</option>
              <option>Autre</option>
            </select>
          </div>
          <div class="field"><label>Date de l'événement</label><input class="input" type="date" name="date_evenement"></div>
          <div class="field"><label>Nombre de participants</label><input class="input" type="number" name="nb_invites" min="1" placeholder="ex : 150"></div>
          <div class="field full"><label>Votre message</label><textarea class="input" name="message" maxlength="2000" placeholder="Décrivez votre événement, vos envies, votre budget…"></textarea></div>
          <div class="full"><button class="btn btn-gold" style="width:100%">Envoyer ma demande ✨</button></div>
        </div>
      </form>
    </div>
  </div>
</section>

<footer class="site-footer">
  <div class="wrap foot-grid">
    <div class="foot-col foot-about">
      <div class="foot-brand"><?= logo_html('.', 'brand-dot') ?><?= e($s['nom_entreprise']) ?></div>
      <p><?= e($s['footer_description'] ?? '') ?></p>
      <div class="socials">
        <?php foreach (reseaux_disponibles() as $cleR => $nomR):
              if (empty($s[$cleR])) continue; ?>
        <a href="<?= e($s[$cleR]) ?>" target="_blank" rel="noopener" aria-label="<?= e($nomR) ?>" title="<?= e($nomR) ?>"><?= reseau_svg($cleR, 20) ?></a>
        <?php endforeach; ?>
        <?php if ($s['whatsapp']): ?><a href="https://wa.me/<?= e(preg_replace('/\D/', '', $s['whatsapp'])) ?>" target="_blank" rel="noopener" aria-label="WhatsApp"><svg viewBox="0 0 32 32" width="20" height="20" aria-hidden="true"><path fill="#25D366" d="M16 0a16 16 0 0 0-13.7 24.2L0 32l8-2.1A16 16 0 1 0 16 0z"/><path fill="#fff" d="M12.1 8.6c-.3-.6-.5-.6-.8-.6h-.7c-.2 0-.6.1-.9.4-.3.3-1.2 1.2-1.2 2.9s1.2 3.4 1.4 3.6c.2.3 2.4 3.8 6 5.2 3 1.2 3.6 1 4.2.9.6-.1 2-.8 2.3-1.6.3-.8.3-1.5.2-1.6-.1-.2-.3-.2-.7-.4-.3-.2-2-1-2.3-1.1-.3-.1-.5-.2-.8.2-.2.3-.8 1.1-1 1.3-.2.2-.4.2-.7.1-.4-.2-1.5-.6-2.9-1.8-1.1-1-1.8-2.2-2-2.5-.2-.4 0-.5.1-.7l.5-.6c.2-.2.2-.3.4-.6.1-.2 0-.4 0-.6s-.7-1.8-1-2.4z"/></svg></a><?php endif; ?>
      </div>
    </div>

    <div class="foot-col">
      <h4>Navigation</h4>
      <a href="#accueil">Accueil</a>
      <a href="#services">Services</a>
      <a href="#menu">Menu</a>
      <a href="#galerie">Galerie</a>
      <?php if ($videos): ?><a href="#videos">Vidéos</a><?php endif; ?>
      <a href="#devis">Proforma</a>
    </div>

    <div class="foot-col">
      <h4>Contact</h4>
      <?php if ($s['telephone']): ?><a href="tel:<?= e(preg_replace('/\s/', '', $s['telephone'])) ?>">📞 <?= e($s['telephone']) ?></a><?php endif; ?>
      <?php if ($s['email']): ?><a href="mailto:<?= e($s['email']) ?>">✉️ <?= e($s['email']) ?></a><?php endif; ?>
      <?php if ($s['adresse']): ?><span>📍 <?= e($s['adresse']) ?></span><?php endif; ?>
      <?php if (!empty($s['horaires'])): ?><span>🕒 <?= e($s['horaires']) ?></span><?php endif; ?>
    </div>

    <div class="foot-col">
      <h4>Informations légales</h4>
      <?php if (!empty($s['forme_juridique'])): ?><span>Forme : <?= e($s['forme_juridique']) ?></span><?php endif; ?>
      <?php if (!empty($s['capital'])): ?><span>Capital : <?= e($s['capital']) ?></span><?php endif; ?>
      <?php if (!empty($s['rccm'])): ?><span>RCCM : <?= e($s['rccm']) ?></span><?php endif; ?>
      <?php if (!empty($s['ncc'])): ?><span>NCC : <?= e($s['ncc']) ?></span><?php endif; ?>
      <?php if (!empty($s['siege_social'])): ?><span>Siège : <?= e($s['siege_social']) ?></span><?php endif; ?>
    </div>
  </div>

  <div class="foot-bottom">
    <div class="wrap foot-bottom-in">
      <div>© <?= date('Y') ?> <?= e($s['nom_entreprise']) ?> — Tous droits réservés</div>
      <?php if (!empty($s['mentions_legales'])): ?><div class="foot-mentions"><?= e($s['mentions_legales']) ?></div><?php endif; ?>
    </div>
  </div>
</footer>

<!-- Signature du développeur (bandeau discret, distinct des infos de l'entreprise) -->
<div class="dev-sign">
  <div class="wrap dev-sign-in">
    <div class="dev-sign-label">Conception &amp; développement</div>
    <div class="dev-sign-name">Yanick Sergino Gakpo</div>
    <div class="dev-sign-links">
      <a href="mailto:yanicksergino@gmail.com" class="dev-sign-link">
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 5L2 7"/></svg>
        yanicksergino@gmail.com
      </a>
      <a href="https://wa.me/2250709522789" target="_blank" rel="noopener" class="dev-sign-link">
        <svg viewBox="0 0 32 32" width="15" height="15" aria-hidden="true"><path fill="#25D366" d="M16 0a16 16 0 0 0-13.7 24.2L0 32l8-2.1A16 16 0 1 0 16 0z"/><path fill="#fff" d="M12.1 8.6c-.3-.6-.5-.6-.8-.6h-.7c-.2 0-.6.1-.9.4-.3.3-1.2 1.2-1.2 2.9s1.2 3.4 1.4 3.6c.2.3 2.4 3.8 6 5.2 3 1.2 3.6 1 4.2.9.6-.1 2-.8 2.3-1.6.3-.8.3-1.5.2-1.6-.1-.2-.3-.2-.7-.4-.3-.2-2-1-2.3-1.1-.3-.1-.5-.2-.8.2-.2.3-.8 1.1-1 1.3-.2.2-.4.2-.7.1-.4-.2-1.5-.6-2.9-1.8-1.1-1-1.8-2.2-2-2.5-.2-.4 0-.5.1-.7l.5-.6c.2-.2.2-.3.4-.6.1-.2 0-.4 0-.6s-.7-1.8-1-2.4z"/></svg>
        +225 07 09 52 27 89
      </a>
      <span class="dev-sign-link dev-sign-loc">
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
        Abidjan, Côte d'Ivoire
      </span>
    </div>
  </div>
</div>

<!-- Tab bar flottante (mobile) -->
<nav class="tabbar glass-strong">
  <a href="#accueil" class="active"><span class="tico">🏠</span>Accueil</a>
  <a href="#services"><span class="tico">✨</span>Services</a>
  <a href="#menu"><span class="tico">🍽️</span>Menu</a>
  <a href="#galerie"><span class="tico">📸</span>Galerie</a>
  <?php if ($videos): ?><a href="#videos"><span class="tico">🎬</span>Vidéos</a><?php endif; ?>
  <a href="#devis"><span class="tico">📝</span>Proforma</a>
</nav>

<script>window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;</script>
<script src="<?= asset('assets/js/app.js') ?>"></script>
<script>
/* Apparition douce des sections au défilement — style iOS. */
(function(){
  if (matchMedia('(prefers-reduced-motion: reduce)').matches) {
    document.querySelectorAll('.reveal').forEach(function(el){ el.classList.add('in'); });
    return;
  }
  var cibles = document.querySelectorAll('section .glass, section .service-card, section .dish, section .menu-panel, section h2, .devis-form, .avis-form, .temo, .video-card');
  cibles.forEach(function(el){ el.classList.add('reveal'); });
  var io = new IntersectionObserver(function(entries){
    entries.forEach(function(e){ if(e.isIntersecting){ e.target.classList.add('in'); io.unobserve(e.target); } });
  }, {threshold:.12, rootMargin:'0px 0px -8% 0px'});
  cibles.forEach(function(el){ io.observe(el); });
})();

/* Navigation : clic fluide + onglet actif au défilement (barre du bas) */
(function(){
  var liens = document.querySelectorAll('.tabbar a[href^="#"]');
  liens.forEach(function(a){
    a.addEventListener('click', function(e){
      var cible = document.querySelector(a.getAttribute('href'));
      if(cible){ e.preventDefault(); cible.scrollIntoView({behavior:'smooth', block:'start'}); }
    });
  });
  var sections = [];
  liens.forEach(function(a){ var s=document.querySelector(a.getAttribute('href')); if(s) sections.push({a:a, s:s}); });
  var spy = new IntersectionObserver(function(entries){
    entries.forEach(function(e){
      if(e.isIntersecting){
        liens.forEach(function(x){ x.classList.remove('active'); });
        var found = sections.find(function(o){ return o.s === e.target; });
        if(found) found.a.classList.add('active');
      }
    });
  }, {threshold:.4, rootMargin:'-20% 0px -40% 0px'});
  sections.forEach(function(o){ spy.observe(o.s); });
})();
</script>
<?php $pwaBase='.'; $pwaBouton=true; include __DIR__.'/config/pwa_script.php'; ?>
<script>
/* ============================================================================
   GALERIE ET VIDÉOS
   Filtrage par album, visionneuse plein écran, lecture différée des vidéos.
   Aucune bibliothèque : la page reste légère sur une connexion mobile.
   ============================================================================ */
(function () {
  var doux = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- Filtres par album ---------- */
  var filtres = document.querySelectorAll('.gf');
  var photos  = Array.prototype.slice.call(document.querySelectorAll('.gal-item'));

  filtres.forEach(function (b) {
    b.addEventListener('click', function () {
      filtres.forEach(function (x) { x.classList.remove('actif'); });
      this.classList.add('actif');
      var cible = this.dataset.album;
      photos.forEach(function (p, i) {
        var garde = (cible === 'tous') || (p.dataset.album === cible);
        if (garde) {
          p.hidden = false;
          if (doux) {
            p.style.animation = 'none';
            void p.offsetWidth;                       // relance l'animation
            p.style.animation = 'galEntre .5s var(--ease-ios) ' + (i % 12) * .035 + 's both';
          }
        } else {
          p.hidden = true;
        }
      });
    });
  });

  /* ---------- Visionneuse ---------- */
  var visio = document.getElementById('visio');
  if (visio) {
    var vImg = document.getElementById('vi-img');
    var vTit = document.getElementById('vi-titre');
    var vLeg = document.getElementById('vi-legende');
    var vCpt = document.getElementById('vi-compteur');
    var courant = 0;

    function visibles() { return photos.filter(function (p) { return !p.hidden; }); }

    function montrer(i) {
      var liste = visibles();
      if (!liste.length) return;
      courant = (i + liste.length) % liste.length;
      var p = liste[courant];
      vImg.style.opacity = 0;
      var img = new Image();
      img.onload = function () {
        vImg.src = p.dataset.src;
        vImg.style.opacity = 1;
      };
      img.src = p.dataset.src;
      vTit.textContent = p.dataset.titre || '';
      vLeg.textContent = p.dataset.legende || '';
      vCpt.textContent = (courant + 1) + ' / ' + liste.length;
    }

    photos.forEach(function (p) {
      p.addEventListener('click', function () {
        var liste = visibles();
        montrer(liste.indexOf(this));
        visio.hidden = false;
        document.body.style.overflow = 'hidden';
      });
    });

    function fermer() { visio.hidden = true; document.body.style.overflow = ''; }
    visio.querySelector('.vi-fermer').addEventListener('click', fermer);
    visio.querySelector('.vi-prec').addEventListener('click', function (e) { e.stopPropagation(); montrer(courant - 1); });
    visio.querySelector('.vi-suiv').addEventListener('click', function (e) { e.stopPropagation(); montrer(courant + 1); });
    visio.addEventListener('click', function (e) { if (e.target === visio) fermer(); });
    document.addEventListener('keydown', function (e) {
      if (visio.hidden) return;
      if (e.key === 'Escape') fermer();
      if (e.key === 'ArrowLeft') montrer(courant - 1);
      if (e.key === 'ArrowRight') montrer(courant + 1);
    });

    /* Balayage tactile : le geste attendu sur un téléphone. */
    var x0 = null;
    visio.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
    visio.addEventListener('touchend', function (e) {
      if (x0 === null) return;
      var d = e.changedTouches[0].clientX - x0;
      if (Math.abs(d) > 55) montrer(courant + (d < 0 ? 1 : -1));
      x0 = null;
    }, { passive: true });
  }

  /* ---------- Vidéos : chargement au clic ---------- */
  function secours(fig, zone) {
    var lien = fig.dataset.lien || fig.dataset.src;
    zone.innerHTML =
      '<div class="vf-secours">'
      + '<p>La vidéo ne peut pas être lue ici.</p>'
      + '<a href="' + lien + '" target="_blank" rel="noopener">Ouvrir sur YouTube ↗</a>'
      + '</div>';
  }

  document.querySelectorAll('.video-item').forEach(function (fig) {
    var bouton = fig.querySelector('.vf-play');
    if (!bouton) return;
    bouton.addEventListener('click', function () {
      var zone = fig.querySelector('.vf-lecteur');
      if (fig.dataset.type === 'fichier') {
        zone.innerHTML = '<video controls autoplay playsinline preload="metadata" '
                       + 'style="width:100%;height:100%;display:block">'
                       + '<source src="' + fig.dataset.src + '">'
                       + 'Votre navigateur ne peut pas lire cette vidéo.</video>';
      } else {
        var u = fig.dataset.src + (fig.dataset.src.indexOf('?') >= 0 ? '&' : '?') + 'autoplay=1';
        var cadre = document.createElement('iframe');
        cadre.src = u;
        cadre.title = fig.dataset.titre || 'Vidéo';
        /* « fullscreen » doit figurer dans « allow » : sans lui, le bouton
           plein écran du lecteur reste inactif dans un cadre intégré. */
        cadre.setAttribute('allow',
          'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen');
        cadre.setAttribute('allowfullscreen', '');
        cadre.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        cadre.setAttribute('loading', 'eager');
        cadre.style.cssText = 'width:100%;height:100%;border:0;display:block';

        /* Si le lecteur ne se charge pas — vidéo privée, lecture intégrée
           refusée par son propriétaire, réseau coupé — on propose le lien
           direct plutôt que de laisser un rectangle noir. */
        var minuteur = setTimeout(function () {
          if (!fig.dataset.charge) secours(fig, zone);
        }, 6000);
        cadre.addEventListener('load', function () {
          fig.dataset.charge = '1';
          clearTimeout(minuteur);
        });

        zone.innerHTML = '';
        zone.appendChild(cadre);
      }
      fig.classList.add('joue');
    });
  });
})();
</script>
<script src="<?= asset('assets/js/ui.js') ?>"></script>
</body>
</html>
