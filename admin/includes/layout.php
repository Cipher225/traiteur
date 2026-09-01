<?php
function admin_header(string $titre, string $actif, PDO $pdo, array $settings): void {
    $nb_nouveaux = (int)$pdo->query("SELECT COUNT(*) FROM commandes WHERE statut='nouveau'")->fetchColumn();
    $uid = (int)($_SESSION['admin_id'] ?? 0);
    // Notifications & badges selon le rôle
    $NOTIF = notifications($pdo, current_user());
    $notif = $NOTIF['total'];
    $badges = ['commandes' => $nb_nouveaux];
    try {
        if (is_admin()) {
            $r = (int)$pdo->query("SELECT COUNT(*) FROM rapports WHERE statut='envoye' AND lu_par_admin=0")->fetchColumn();
            $badges['rapports'] = $r;
            $notif = $r; $notif_url = 'rapports.php'; $notif_label = 'rapport(s) reçu(s)';
        } else {
            $t = (int)$pdo->query("SELECT COUNT(*) FROM taches WHERE assigne_a=$uid AND statut<>'termine'")->fetchColumn();
            $nouv = (int)$pdo->query("SELECT COUNT(*) FROM taches WHERE assigne_a=$uid AND vue=0")->fetchColumn();
            $badges['taches'] = $t;
            $notif = $nouv; $notif_url = 'taches.php'; $notif_label = 'nouvelle(s) tâche(s)';
        }
    } catch (Throwable $e) { /* tables pas encore créées */ }
    // Messages non lus (messagerie) — pour admin et employé
    try {
        $mnl = (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE destinataire_id=$uid AND lu=0")->fetchColumn();
        if ($mnl > 0) { $badges['messagerie'] = $mnl; if (!$notif) { $notif = $mnl; $notif_url = 'messagerie.php'; $notif_label = 'nouveau(x) message(s)'; } }
    } catch (Throwable $e) {}
    // Nouvelles commandes clients (admin)
    if (is_admin()) {
        try {
            $nc = (int)$pdo->query("SELECT COUNT(*) FROM commandes_client WHERE statut='nouvelle'")->fetchColumn();
            if ($nc > 0) { $badges['commandes_client'] = $nc; if (!$notif) { $notif = $nc; $notif_url = 'commandes-client.php'; $notif_label = 'nouvelle(s) commande(s)'; } }
        } catch (Throwable $e) {}
    }
    ?>
<!DOCTYPE html>
<html lang="fr" data-space="app">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($titre) ?> — Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('../assets/css/glass.css') ?>">
<link rel="stylesheet" href="<?= asset('../assets/css/admin.css') ?>">
<script src="<?= asset('../assets/js/theme.js') ?>"></script>
<?php $pwaBase='..'; $s=$settings; include __DIR__.'/../../config/pwa_head.php'; ?>
</head>
<body>
<div class="aurora"></div>
<div class="admin-shell">
  <aside class="sidebar glass-strong">
    <div class="side-mac" aria-hidden="true">
      <button class="mac-dot mac-close" data-close-side aria-label="Fermer le menu" title="Fermer"></button>
      <span class="mac-dot mac-min"></span>
      <span class="mac-dot mac-max"></span>
    </div>
    <div class="side-brand">
      <?= logo_html('..', 'brand-dot') ?>
      <div><strong><?= e($settings['nom_entreprise']) ?></strong><small><?= is_admin() ? 'Espace admin' : 'Espace employé' ?></small></div>
    </div>
    <nav class="side-nav">
      <a href="index.php" class="<?= $actif === 'dashboard' ? 'active' : '' ?>"><span class="nico">⚡</span>Tableau de bord</a>
      <?php
      $groupes = groupes_modules();
      foreach ($groupes as $g => $gLabel):
          // Modules visibles de ce groupe pour l'utilisateur courant
          $items = array_filter(all_modules(), fn($m, $k) => $m[3] === $g && can($k), ARRAY_FILTER_USE_BOTH);
          if (!$items) continue; ?>
        <div class="nav-sep"><?= e($gLabel) ?></div>
        <?php foreach ($items as $key => [$label, $ico, $url, $grp, $adminOnly]): ?>
        <a href="<?= $url ?>" class="<?= $key === $actif ? 'active' : '' ?>">
          <span class="nico"><?= $ico ?></span><?= $label ?>
          <?php if (!empty($badges[$key])): ?><span class="nav-badge"><?= $badges[$key] ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <a href="profil.php" class="btn btn-glass btn-sm" style="width:100%">👤 Mon profil</a>
      <a href="../index.php" target="_blank" class="btn btn-glass btn-sm" style="width:100%">🌐 Voir le site</a>
      <a href="../logout.php" class="btn btn-danger btn-sm" style="width:100%">Déconnexion</a>
    </div>
  </aside>
  <div id="side-overlay" class="side-overlay"></div>

  <main class="admin-main">
    <header class="admin-top">
      <div style="display:flex;align-items:center;gap:10px;min-width:0">
        <button type="button" class="btn-retour" onclick="retourArriere()" aria-label="Retour">
          <span class="chev">‹</span><span class="btn-retour-txt">Retour</span>
        </button>
        <div style="min-width:0">
          <h1><?= e($titre) ?></h1>
          <p class="crumb">Bonjour, <?= e($_SESSION['admin_nom']) ?> 👋<?= is_admin() ? '' : ' · <span class="badge">Employé</span>' ?></p>
        </div>
      </div>
      <div style="display:flex;gap:10px;align-items:center">
<div class="notif-wrap" data-msg-count="<?php
            $mc = 0; foreach ($NOTIF['items'] as $it) { if (($it['icone'] ?? '') === '💬') $mc += (int)$it['n']; }
            echo $mc; ?>">
          <button type="button" class="notif-bell" onclick="this.parentNode.classList.toggle('open')" title="Notifications">
            🔔<?php if ($NOTIF['total']): ?><span class="notif-dot"><?= $NOTIF['total'] > 99 ? '99+' : $NOTIF['total'] ?></span><?php endif; ?>
          </button>
          <div class="notif-panel">
            <div class="np-head">Notifications<?php if ($NOTIF['total']): ?> <span><?= $NOTIF['total'] ?></span><?php endif; ?></div>
            <?php if (!$NOTIF['items']): ?>
              <div class="np-vide">Tout est à jour ✨</div>
            <?php else: foreach ($NOTIF['items'] as $it): ?>
              <a class="np-item" href="<?= e($it['url']) ?>">
                <span class="np-ic"><?= $it['icone'] ?></span>
                <span class="np-tx"><strong><?= (int)$it['n'] ?></strong> <?= e($it['texte']) ?></span>
                <span class="np-go">›</span>
              </a>
            <?php endforeach; endif; ?>
          </div>
        </div>
        <button class="theme-toggle" onclick="toggleTheme()" title="Changer de thème"><span data-theme-icon>☀️</span></button>
        <button class="btn btn-glass btn-sm menu-btn" data-toggle-side aria-label="Menu">☰</button>
      </div>
    </header>
    <?php if ($f = flash()): ?><div class="flash <?= $f['type'] === 'error' ? 'error' : '' ?>"><?= e($f['msg']) ?></div><?php endif; ?>
<?php } // fin admin_header

function admin_footer(): void { ?>
  </main>
</div>
<script>
// Sidebar mobile : ouverture / fermeture (style macOS) + overlay
(function(){
  var side = document.querySelector('.sidebar');
  var ov = document.getElementById('side-overlay');
  function open(){ side.classList.add('open'); if(ov) ov.classList.add('show'); }
  function close(){ side.classList.remove('open'); if(ov) ov.classList.remove('show'); }
  document.querySelectorAll('[data-toggle-side]').forEach(b => b.addEventListener('click', function(){
    side.classList.contains('open') ? close() : open();
  }));
  document.querySelectorAll('[data-close-side]').forEach(b => b.addEventListener('click', close));
  if(ov) ov.addEventListener('click', close);
  // Fermer après avoir cliqué un lien de navigation (confort mobile)
  side.querySelectorAll('.side-nav a').forEach(a => a.addEventListener('click', function(){ if(window.innerWidth<=880) close(); }));
})();
// Confirmation avant suppression
document.querySelectorAll('form[data-confirm]').forEach(fo =>
  fo.addEventListener('submit', e => { if (!confirm(fo.dataset.confirm)) e.preventDefault(); }));
// Bouton retour (style iPhone) : revient à la page précédente, ou au tableau de bord
function retourArriere(){
  if (document.referrer && document.referrer.indexOf(location.host) !== -1 && history.length > 1) {
    history.back();
  } else {
    window.location.href = 'index.php';
  }
}
</script>

<script src="<?= asset('../assets/js/notif-son.js') ?>"></script>
<script>window.NOTIF_URL = 'notifications-check.php';</script>
<script src="<?= asset('../assets/js/notifications-direct.js') ?>"></script>
<script src="<?= asset('../assets/js/img-redim.js') ?>"></script>

<?php $pwaBase='..'; $pwaBouton=false; include __DIR__.'/../../config/pwa_script.php'; ?>
</body>
</html>
<?php } ?>
