<?php
require __DIR__ . '/../config/db.php';
$settings = get_settings($pdo);

// Réservé aux clients connectés
if (empty($_SESSION['admin_id']) || ($_SESSION['admin_role'] ?? '') !== 'client') {
    header('Location: ../login.php'); exit;
}
$CLIENT_UID = (int)$_SESSION['admin_id'];

/* ---- Sécurité de session : session unique + déconnexion sur inactivité ---- */
if (!defined('INACTIVITE_MAX')) define('INACTIVITE_MAX', 3 * 60);
(function() use ($pdo, $CLIENT_UID) {
    $maintenant = time();
    if (isset($_SESSION['derniere_activite'])
        && ($maintenant - (int)$_SESSION['derniere_activite']) > INACTIVITE_MAX) {
        $pdo->prepare("UPDATE users SET session_id=NULL WHERE id=?")->execute([$CLIENT_UID]);
        session_unset(); session_destroy();
        header('Location: ../login.php?inactif=1'); exit;
    }
    $_SESSION['derniere_activite'] = $maintenant;

    if (!empty($_SESSION['sid_check'])) {
        $u = $pdo->prepare("SELECT session_id FROM users WHERE id=?");
        $u->execute([$CLIENT_UID]);
        $sidBase = $u->fetchColumn();
        if ($sidBase && $sidBase !== $_SESSION['sid_check']) {
            session_unset(); session_destroy();
            header('Location: ../login.php?ailleurs=1'); exit;
        }
    }
    $pdo->prepare("UPDATE users SET last_activity=NOW() WHERE id=?")->execute([$CLIENT_UID]);
})();

// Charger la fiche client liée
$stmt = $pdo->prepare("SELECT c.* FROM users u JOIN clients c ON c.id=u.client_id WHERE u.id=?");
$stmt->execute([$CLIENT_UID]);
$CLIENT = $stmt->fetch();
if (!$CLIENT) { session_destroy(); header('Location: ../login.php'); exit; }

function client_header(string $titre, string $actif, array $settings, array $client): void {
    global $pdo;
    $NOTIF = notifications($pdo, current_user() + ['client_id' => (int)$client['id']]);
    $ent = $settings['nom_entreprise'] ?? 'Groupe Helisce';
    $paiementDispo = false;
    try {
        require_once __DIR__ . '/../config/wave.php';
        $paiementDispo = wave_disponible($GLOBALS['pdo'] ?? $pdo);
    } catch (Throwable $e) { $paiementDispo = false; }

    $nav = [
        'accueil'    => ['🏠', 'Accueil',        'index.php'],
        'commander'  => ['🛒', 'Commander',      'commander.php'],
        'commandes'  => ['📦', 'Mes commandes',  'mes-commandes.php'],
        'payer'      => ['💳', 'Régler une facture', 'payer.php'],
        'messagerie' => ['💬', 'Messagerie',      'messagerie.php'],
        'documents'  => ['🧾', 'Mes documents',  'index.php#documents'],
        'avis'       => ['⭐', 'Laisser un avis', 'index.php#avis'],
        'profil'     => ['👤', 'Mon profil',      'profil.php'],
    ];
    if (!$paiementDispo) unset($nav['payer']);   // l'entrée n'apparaît que si le paiement est actif
    ?>
<!DOCTYPE html>
<html lang="fr" data-space="app">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($titre) ?> — <?= e($ent) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('../assets/css/glass.css') ?>">
<link rel="stylesheet" href="<?= asset('../assets/css/admin.css') ?>">
<script src="<?= asset('../assets/js/theme.js') ?>"></script>
<?php $pwaBase='..'; $s=$settings; include __DIR__.'/../config/pwa_head.php'; ?>
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
      <div><strong><?= e($ent) ?></strong><small>Espace client</small></div>
    </div>
    <nav class="side-nav">
      <?php foreach ($nav as $k=>[$ico,$label,$url]): ?>
      <a href="<?= $url ?>" class="<?= $k===$actif?'active':'' ?>"><span class="nico"><?= $ico ?></span><?= $label ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
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
          <p class="crumb">Bonjour, <?= e($client['nom']) ?> 👋 · <span class="badge">Client</span></p>
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
    <?php if ($f = flash()): ?><div class="flash <?= $f['type']==='error'?'error':'' ?>"><?= e($f['msg']) ?></div><?php endif; ?>
<?php }

function client_footer(): void { ?>
  </main>
</div>
<script>
(function(){
  var side=document.querySelector('.sidebar'), ov=document.getElementById('side-overlay');
  function open(){ side.classList.add('open'); if(ov) ov.classList.add('show'); }
  function close(){ side.classList.remove('open'); if(ov) ov.classList.remove('show'); }
  document.querySelectorAll('[data-toggle-side]').forEach(b=>b.addEventListener('click',function(){ side.classList.contains('open')?close():open(); }));
  document.querySelectorAll('[data-close-side]').forEach(b=>b.addEventListener('click',close));
  if(ov) ov.addEventListener('click',close);
  side.querySelectorAll('.side-nav a').forEach(a=>a.addEventListener('click',function(){ if(window.innerWidth<=880) close(); }));
})();
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
<script src="../assets/js/notifications-direct.js"></script>
<?php $pwaBase='..'; $pwaBouton=false; include __DIR__.'/../config/pwa_script.php'; ?>
<script>window.INACTIVITE_SECONDES = <?= (int)INACTIVITE_MAX ?>; window.INACTIVITE_URL = '../login.php?inactif=1';</script>
<script src="../assets/js/inactivite.js"></script>
</body>
</html>
<?php }
