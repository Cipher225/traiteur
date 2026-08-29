<?php
/* ============================================================================
   VÉRIFICATION PUBLIQUE D'UN BADGE (accessible en scannant le QR)
   ----------------------------------------------------------------------------
   Étape 1 : confirme que la personne est un membre authentique de l'entreprise,
             SANS révéler les coordonnées (protège en cas de badge perdu).
   Étape 2 : les coordonnées complètes (vCard) ne s'affichent qu'après une
             confirmation explicite, et seulement si le badge est actif.
   ============================================================================ */
require __DIR__ . '/config/db.php';
require __DIR__ . '/admin/includes/badges.php';
$s = get_settings($pdo);

$token = preg_replace('/[^a-f0-9]/', '', strtolower($_GET['t'] ?? ''));
$badge = null;
if ($token !== '') {
    $st = $pdo->prepare("SELECT * FROM badges WHERE token=? LIMIT 1");
    $st->execute([$token]);
    $badge = $st->fetch();
}

$statut = $badge ? badge_statut($badge) : null;
$voirContact = isset($_GET['contact']) && $badge && $statut === 'actif';
$ent = $s['nom_entreprise'] ?? 'Groupe Helisce';

/* Téléchargement direct de la vCard (bouton « Enregistrer le contact ») */
if ($voirContact && isset($_GET['vcard'])) {
    header('Content-Type: text/vcard; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9]/','_',$badge['nom']) . '.vcf"');
    echo badge_vcard($badge, $s);
    exit;
}

$logo = !empty($s['logo']) && is_file(__DIR__.'/uploads/'.$s['logo'])
        ? 'uploads/'.rawurlencode($s['logo']) : 'assets/img/logo.png';
$photo = $badge && !empty($badge['photo']) && is_file(__DIR__.'/uploads/'.$badge['photo'])
        ? 'uploads/'.rawurlencode($badge['photo']) : '';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vérification d'identité — <?= e($ent) ?></title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',-apple-system,Arial,sans-serif;min-height:100vh;
    display:grid;place-items:center;padding:20px;
    background:radial-gradient(60% 40% at 50% 0%,rgba(212,165,38,.12),transparent 60%),
      linear-gradient(180deg,#030a1a,#0a1f44 60%,#030a1a)}
  .card{width:100%;max-width:400px;background:#fff;border-radius:26px;overflow:hidden;
    box-shadow:0 30px 70px rgba(0,0,0,.4)}
  .head{background:linear-gradient(135deg,#0a1f44,#143264);padding:26px 24px 22px;text-align:center;position:relative}
  .head::after{content:'';position:absolute;left:0;right:0;bottom:0;height:4px;
    background:linear-gradient(90deg,#b8870f,#f0c14b,#d4a526,#b8870f)}
  .head img{width:54px;height:54px;object-fit:contain;margin-bottom:8px}
  .head .ent{color:#fff;font-size:17px;font-weight:800;letter-spacing:.04em}
  .head .slg{color:#d4a526;font-size:8.5px;letter-spacing:.16em;text-transform:uppercase;margin-top:3px}
  .body{padding:26px 24px 28px;text-align:center}
  .verdict{display:flex;flex-direction:column;align-items:center;gap:10px;margin-bottom:8px}
  .vico{width:72px;height:72px;border-radius:50%;display:grid;place-items:center;font-size:36px}
  .ok .vico{background:rgba(45,154,107,.14);color:#2d9a6b}
  .no .vico{background:rgba(226,75,74,.12);color:#e24b4a}
  .warn .vico{background:rgba(212,165,38,.14);color:#c99a1a}
  .verdict h1{font-size:20px;color:#0a1f44}
  .ok .verdict h1{color:#1e7a54}
  .no .verdict h1{color:#c0392b}
  .warn .verdict h1{color:#a67c0c}
  .sub{color:#6b7688;font-size:14px;line-height:1.6;margin:6px 0 20px}
  .photo{width:110px;height:110px;border-radius:50%;object-fit:cover;margin:0 auto 14px;
    border:3px solid #d4a526;box-shadow:0 8px 22px rgba(10,31,68,.24)}
  .photo.ini{display:grid;place-items:center;font-size:40px;font-weight:800;color:#0a1f44;
    background:linear-gradient(135deg,#f0c14b,#c8960c)}
  .who{margin-bottom:18px}
  .who .nom{font-size:20px;font-weight:800;color:#0a1f44}
  .who .poste{font-size:13.5px;color:#d4a526;font-weight:600;margin-top:3px}
  .who .mat{font-family:monospace;font-size:12px;color:#8a94a6;margin-top:6px;letter-spacing:.06em}
  .chip{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:999px;
    font-size:12px;font-weight:700;margin-top:4px}
  .chip.actif{background:rgba(45,154,107,.14);color:#2d9a6b}
  .chip.suspendu{background:rgba(226,75,74,.12);color:#e24b4a}
  .chip.expire{background:rgba(138,148,166,.16);color:#6b7688}
  .info{text-align:left;background:#f6f8fb;border-radius:16px;padding:16px 18px;margin:6px 0 18px}
  .info .r{display:flex;gap:10px;padding:7px 0;font-size:13.5px;color:#2d3442;border-bottom:1px solid #edf1f6}
  .info .r:last-child{border:0}
  .info .r b{color:#0a1f44;min-width:96px;display:inline-block}
  .btn{display:block;width:100%;padding:14px;border-radius:14px;font-size:14.5px;font-weight:700;
    text-decoration:none;text-align:center;border:none;cursor:pointer;margin-top:10px}
  .btn-gold{background:linear-gradient(135deg,#f0c14b,#d4a526,#b8870f);color:#0a1f44}
  .btn-ghost{background:#eef1f6;color:#0a1f44}
  .note{font-size:11.5px;color:#9aa3b2;margin-top:16px;line-height:1.6}
  .foot{background:#0a1f44;color:#7d8fb3;text-align:center;padding:12px;font-size:10.5px;letter-spacing:.1em;text-transform:uppercase}
</style>
</head>
<body>
<div class="card">
  <div class="head">
    <img src="<?= e($logo) ?>" alt="">
    <div class="ent"><?= e(mb_strtoupper($ent)) ?></div>
    <?php if(!empty($s['slogan'])): ?><div class="slg"><?= e($s['slogan']) ?></div><?php endif; ?>
  </div>

  <?php if (!$badge): ?>
    <div class="body no">
      <div class="verdict"><div class="vico">✕</div><h1>Badge non reconnu</h1></div>
      <p class="sub">Ce code ne correspond à aucun badge émis par <?= e($ent) ?>. Il pourrait s'agir d'une contrefaçon.</p>
    </div>
  <?php elseif ($statut !== 'actif'): ?>
    <div class="body warn">
      <div class="verdict"><div class="vico">!</div>
        <h1><?= $statut === 'expire' ? 'Badge expiré' : 'Badge suspendu' ?></h1></div>
      <p class="sub">Ce badge a bien été émis par <?= e($ent) ?>, mais il n'est
        <?= $statut === 'expire' ? 'plus valide (date dépassée)' : 'plus actif' ?>.
        Merci de ne pas vous y fier.</p>
      <div class="who">
        <div class="nom"><?= e($badge['nom']) ?></div>
        <span class="chip <?= $statut ?>"><?= $statut === 'expire' ? 'Expiré' : 'Suspendu' ?></span>
      </div>
    </div>
  <?php elseif (!$voirContact): ?>
    <!-- Étape 1 : authenticité confirmée, SANS exposer les coordonnées -->
    <div class="body ok">
      <div class="verdict"><div class="vico">✓</div><h1>Identité authentifiée</h1></div>
      <p class="sub">Cette personne est bien un membre officiel de <strong><?= e($ent) ?></strong>.</p>
      <?php if ($photo): ?><img class="photo" src="<?= e($photo) ?>" alt="">
      <?php else: ?><div class="photo ini"><?= badge_initiales($badge['nom']) ?></div><?php endif; ?>
      <div class="who">
        <div class="nom"><?= e($badge['nom']) ?></div>
        <?php if($badge['poste']): ?><div class="poste"><?= e($badge['poste']) ?></div><?php endif; ?>
        <div class="mat">🪪 <?= e($badge['matricule']) ?></div>
        <div style="margin-top:8px"><span class="chip actif">✓ Badge actif</span></div>
      </div>
      <a class="btn btn-gold" href="?t=<?= e($token) ?>&contact=1">Voir les coordonnées complètes</a>
      <p class="note">🔒 Pour la sécurité du porteur, les coordonnées détaillées
        ne sont affichées qu'à cette étape supplémentaire.</p>
    </div>
  <?php else: ?>
    <!-- Étape 2 : coordonnées complètes (badge actif uniquement) -->
    <div class="body ok">
      <?php if ($photo): ?><img class="photo" src="<?= e($photo) ?>" alt="">
      <?php else: ?><div class="photo ini"><?= badge_initiales($badge['nom']) ?></div><?php endif; ?>
      <div class="who">
        <div class="nom"><?= e($badge['nom']) ?></div>
        <?php if($badge['poste']): ?><div class="poste"><?= e($badge['poste']) ?></div><?php endif; ?>
        <div style="margin-top:8px"><span class="chip actif">✓ Membre authentifié</span></div>
      </div>
      <div class="info">
        <div class="r"><b>Matricule</b><span><?= e($badge['matricule']) ?></span></div>
        <?php if($badge['organisation']): ?><div class="r"><b>Organisation</b><span><?= e($badge['organisation']) ?></span></div><?php endif; ?>
        <?php if($badge['departement']): ?><div class="r"><b>Département</b><span><?= e($badge['departement']) ?></span></div><?php endif; ?>
        <?php if($badge['telephone']): ?><div class="r"><b>Téléphone</b><span><?= e($badge['telephone']) ?></span></div><?php endif; ?>
        <?php if($badge['email']): ?><div class="r"><b>Email</b><span><?= e($badge['email']) ?></span></div><?php endif; ?>
      </div>
      <a class="btn btn-gold" href="?t=<?= e($token) ?>&contact=1&vcard=1">📇 Enregistrer le contact</a>
      <a class="btn btn-ghost" href="?t=<?= e($token) ?>">← Retour</a>
    </div>
  <?php endif; ?>

  <div class="foot"><?= e($ent) ?> · Vérification d'identité</div>
</div>
</body>
</html>
