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

    /* Créer sa propre fiche dans le personnel.
       Sans elle, impossible d'éditer un badge ou une carte d'identification :
       ces documents s'appuient sur la fiche, pas sur le compte de connexion. */
    if (isset($_POST['creer_fiche'])) {
        require_once __DIR__ . '/includes/badges.php';
        $poste = trim($_POST['poste'] ?? '');
        if ($poste === '') {
            $err = 'Indiquez votre fonction dans l\'entreprise.';
        } elseif (!empty($u['employe_id'])) {
            $err = 'Votre fiche existe déjà.';
        } else {
            /* Un administrateur porte le premier matricule (la direction) ; un employé
               qui crée sa fiche depuis son profil suit la numérotation habituelle. */
            $typeMat = (($u['role'] ?? '') === 'admin') ? 'direction' : 'employe';
            $matricule = badge_generer_matricule($pdo, $settings, $typeMat);
            $pdo->prepare("INSERT INTO employes (nom, poste, matricule, telephone, email, actif, fiche_perso)
                           VALUES (?,?,?,?,?,1,1)")
                ->execute([$u['nom'], mb_substr($poste, 0, 100), $matricule,
                           $u['telephone'] ?? '', $u['email'] ?? '']);
            $eid = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE users SET employe_id=? WHERE id=?')->execute([$eid, $uid]);
            if (function_exists('journaliser')) {
                journaliser($pdo, 'creation', 'employe', $eid, 'Fiche personnel créée depuis le profil — ' . $matricule);
            }
            $ok = 'Votre fiche a été créée (matricule ' . $matricule . '). Vous pouvez maintenant éditer votre badge.';
            $u = $pdo->query("SELECT * FROM users WHERE id=$uid")->fetch();
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
$relieGoogle = !empty($u['google_id']);

/* Fiche personnel liée à ce compte, si elle existe */
$maFiche = null;
if (!empty($u['employe_id'])) {
    $st = $pdo->prepare('SELECT * FROM employes WHERE id=?');
    $st->execute([(int)$u['employe_id']]);
    $maFiche = $st->fetch() ?: null;
}
$monBadge = null;
if ($maFiche) {
    try {
        $st = $pdo->prepare("SELECT id, statut, date_expiration FROM badges WHERE employe_id=? ORDER BY id DESC LIMIT 1");
        $st->execute([(int)$maFiche['id']]);
        $monBadge = $st->fetch() ?: null;
    } catch (Throwable $e) {}
}
$aEmail      = trim((string)($u['email'] ?? '')) !== '';
$googlePret  = trim((string)($settings['google_client_id'] ?? '')) !== ''
            && trim((string)($settings['google_client_secret'] ?? '')) !== '';

/* État du compte : trois points simples, pour guider sans alourdir */
$points = [
    ['ok' => $aEmail, 'txt' => $aEmail
        ? 'Email renseigné — vous pourrez récupérer votre accès'
        : "Aucun email : en cas d'oubli du mot de passe, vous ne pourrez pas retrouver l'accès seul"],
    ['ok' => $relieGoogle, 'txt' => $relieGoogle
        ? 'Compte relié à Google — connexion en un clic'
        : 'Compte non relié à Google'],
    ['ok' => ($u['username'] ?? '') !== 'admin', 'txt' => ($u['username'] ?? '') !== 'admin'
        ? 'Identifiant personnalisé'
        : "Identifiant encore « admin » : trop facile à deviner"],
];
$score = count(array_filter($points, fn($p) => $p['ok']));

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

<div class="compte-entete panel glass">
  <div class="ce-ava"><?= e(mb_strtoupper(mb_substr($u['nom'] ?: 'A', 0, 1))) ?></div>
  <div class="ce-txt">
    <div class="ce-nom"><?= e($u['nom'] ?: 'Mon profil') ?></div>
    <div class="ce-role">
      <span class="ce-tag"><?= ($u['role'] ?? '') === 'admin' ? '👑 Administrateur' : '🧑‍🍳 Employé' ?></span>
      <?php if (!empty($u['created_at'])): ?>
      <span class="ce-depuis">Compte créé le <?= date('d/m/Y', strtotime($u['created_at'])) ?></span>
      <?php endif; ?>
    </div>
  </div>
  <div class="ce-score ce-score-<?= $score ?>">
    <div class="ce-jauge"><span style="width:<?= round($score / 3 * 100) ?>%"></span></div>
    <div class="ce-score-txt"><?= $score ?>/3 · sécurité</div>
  </div>
</div>

<div class="compte-etat panel glass">
  <?php foreach ($points as $p): ?>
  <div class="cet-ligne <?= $p['ok'] ? 'ok' : 'a-faire' ?>">
    <span class="cet-ico"><?= $p['ok'] ? '✓' : '!' ?></span>
    <span><?= e($p['txt']) ?></span>
  </div>
  <?php endforeach; ?>
</div>

<div class="compte-duo">
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
      <div class="field full"><label>Nouveau mot de passe</label>
        <input class="input" type="password" name="nouveau" id="mdp-new" required minlength="6" autocomplete="new-password">
        <div class="mdp-force"><span id="mdp-barre"></span></div>
        <span class="compte-note" id="mdp-txt">6 caractères minimum. Une phrase de plusieurs mots est plus sûre et plus facile à retenir.</span>
      </div>
      <div class="field full"><label>Confirmer</label>
        <input class="input" type="password" name="confirm" id="mdp-conf" required minlength="6" autocomplete="new-password">
        <span class="compte-note" id="mdp-match"></span>
      </div>
    </div>
    <button class="btn btn-gold" name="maj_mdp" value="1" style="margin-top:8px"><?= $aMotDePasse ? 'Changer le mot de passe' : 'Définir le mot de passe' ?></button>
  </form>
</div>
</div>

<div class="panel glass" style="margin-top:14px">
  <h2>🪪 Ma fiche &amp; mon badge</h2>
  <?php if (!$maFiche): ?>
    <p class="compte-aide">
      Les badges et cartes d'identification s'appuient sur la <strong>fiche du personnel</strong>,
      et non sur le compte de connexion. Votre compte n'a pas encore de fiche : créez-la ici,
      en une fois, pour pouvoir éditer votre propre badge.</p>
    <form method="post" class="fiche-creer">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <div class="fc-champ">
        <label>Votre fonction dans l'entreprise *</label>
        <input class="input" name="poste" required maxlength="100"
               placeholder="ex : Directrice Générale, Gérant, Responsable"
               value="<?= ($u['role'] ?? '') === 'admin' ? 'Direction' : '' ?>">
      </div>
      <button class="btn btn-gold" name="creer_fiche" value="1">🪪 Créer ma fiche</button>
    </form>
    <p class="compte-note" style="margin-top:10px">
      Votre nom, téléphone et email seront repris de vos informations ci-dessus, et un matricule
      vous sera attribué automatiquement. Vous resterez modifiable depuis
      <a href="employes.php" style="color:var(--gold)">Employés</a>.</p>
  <?php else: ?>
    <div class="fiche-ok">
      <span class="fo-ico">✅</span>
      <div class="fo-txt">
        <strong><?= e($maFiche['nom']) ?> — <?= e($maFiche['poste'] ?: 'Fonction non précisée') ?></strong>
        <div class="compte-note">Matricule <?= e($maFiche['matricule']) ?><?php
          if ($monBadge): ?> · badge n° <?= (int)$monBadge['id'] ?><?php
            if (!empty($monBadge['date_expiration'])): ?> valable jusqu'au <?= date('d/m/Y', strtotime($monBadge['date_expiration'])) ?><?php endif;
          else: ?> · aucun badge édité pour le moment<?php endif; ?></div>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
      <?php if ($monBadge): ?>
        <a class="btn btn-gold btn-sm" href="badge-print.php?id=<?= (int)$monBadge['id'] ?>" target="_blank">🖨️ Imprimer mon badge</a>
        <a class="btn btn-glass btn-sm" href="badges.php?edit=<?= (int)$monBadge['id'] ?>">✏️ Modifier</a>
      <?php else: ?>
        <a class="btn btn-gold btn-sm" href="badges.php">🪪 Éditer mon badge</a>
      <?php endif; ?>
      <a class="btn btn-glass btn-sm" href="employes.php?edit=<?= (int)$maFiche['id'] ?>">📋 Ma fiche personnel</a>
    </div>
  <?php endif; ?>
</div>

<div class="panel glass" style="margin-top:14px">
  <h2>🔗 Connexion avec Google</h2>
  <?php if (!$googlePret): ?>
    <p class="compte-aide" style="margin-bottom:0">
      Non configurée. <?php if (is_admin()): ?>Renseignez vos identifiants dans
      <a href="parametres.php?section=google" style="color:var(--gold)">Connexion Google</a>
      pour vous connecter d'un clic et récupérer votre accès en cas d'oubli.<?php else: ?>
      Contactez votre administrateur pour l'activer.<?php endif; ?></p>
  <?php elseif ($relieGoogle): ?>
    <div class="compte-google ok">
      <span class="cg-ico">✅</span>
      <div><strong>Votre compte est relié à Google.</strong>
        <div class="compte-note">Connexion en un clic, et accès retrouvé même en cas d'oubli du mot de passe.</div></div>
    </div>
  <?php else: ?>
    <div class="compte-google">
      <span class="cg-ico">🔗</span>
      <div><strong>Reliez votre compte à Google.</strong>
        <div class="compte-note">L'adresse Google doit être identique à l'email renseigné ci-dessus.</div></div>
      <a class="btn btn-glass btn-sm" href="../google-login.php" style="margin-left:auto">Relier</a>
    </div>
  <?php endif; ?>
</div>

<script>
/* Robustesse du mot de passe : on valorise la longueur plutôt que la complexité,
   plus sûre en pratique et bien plus simple à mémoriser. */
(function(){
  var champ=document.getElementById('mdp-new'), barre=document.getElementById('mdp-barre'),
      txt=document.getElementById('mdp-txt'), conf=document.getElementById('mdp-conf'),
      match=document.getElementById('mdp-match');
  if(!champ) return;
  function force(v){
    var n=0;
    if(v.length>=6) n++; if(v.length>=12) n++; if(v.length>=18) n++;
    if(/[a-z]/.test(v)&&/[A-Z]/.test(v)) n++;
    if(/[0-9]/.test(v)||/[^a-zA-Z0-9]/.test(v)) n++;
    return Math.min(4,n);
  }
  var lbl=['Trop court','Faible','Correct','Solide','Excellent'];
  var col=['#f87171','#f87171','#f0b429','#34d399','#10b981'];
  champ.addEventListener('input',function(){
    var v=this.value,n=force(v);
    if(!v){ barre.style.width='0'; txt.textContent='6 caractères minimum. Une phrase de plusieurs mots est plus sûre et plus facile à retenir.'; txt.style.color=''; return; }
    barre.style.width=((n+1)/5*100)+'%'; barre.style.background=col[n];
    txt.textContent=lbl[n]+(v.length<12?' — une phrase de plusieurs mots serait plus sûre':'');
    txt.style.color=col[n];
  });
  function verif(){
    if(!conf.value){ match.textContent=''; return; }
    var ok=(conf.value===champ.value);
    match.textContent=ok?'✓ Les deux mots de passe correspondent':'✗ Les mots de passe diffèrent';
    match.style.color=ok?'#10b981':'#f87171';
  }
  conf.addEventListener('input',verif); champ.addEventListener('input',verif);
})();
</script>

<?php admin_footer(); ?>
