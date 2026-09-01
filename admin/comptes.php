<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';

if (!is_admin()) { flash("Accès réservé à l'administrateur.", 'error'); header('Location: index.php'); exit; }

$err = ''; $codeGenere = null; $userGenere = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    /* Un compte administrateur ne se gère jamais depuis cette page : chacun passe
       par Paramètres → Mon compte, où le mot de passe actuel est exigé. */
    $estAdmin = function (int $uid) use ($pdo): bool {
        $s = $pdo->prepare('SELECT role FROM users WHERE id=?');
        $s->execute([$uid]);
        return ($s->fetchColumn() === 'admin');
    };

    /* Générer un code de réinitialisation pour un utilisateur */
    if (isset($_POST['generer'])) {
        $uid = (int)$_POST['user_id'];
        if ($estAdmin($uid)) {
            $err = "Un compte administrateur se gère depuis Paramètres → Mon compte.";
            $uid = 0;
        }
        $u = $uid ? $pdo->query("SELECT * FROM users WHERE id=$uid")->fetch() : null;
        if ($u) {
            $code = strtoupper(bin2hex(random_bytes(4)));   // 8 caractères
            $expire = date('Y-m-d H:i:s', time() + 3600 * 24);   // valable 24 h
            $pdo->prepare("UPDATE users SET reset_code=?, reset_expire=? WHERE id=?")
                ->execute([$code, $expire, $uid]);
            $codeGenere = $code;
            $userGenere = $u;
            flash('Code de réinitialisation généré. Communiquez-le à la personne concernée.');
        }
    }

    /* Réinitialiser directement le mot de passe (dépannage) */
    if (isset($_POST['reset_direct'])) {
        $uid = (int)$_POST['user_id'];
        $nouveau = $_POST['nouveau'] ?? '';
        if ($estAdmin($uid)) {
            $err = "Un compte administrateur se gère depuis Paramètres → Mon compte.";
        } elseif (strlen($nouveau) < 6) {
            $err = 'Le mot de passe doit contenir au moins 6 caractères.';
        } else {
            $pdo->prepare("UPDATE users SET password=?, reset_code=NULL, reset_expire=NULL WHERE id=?")
                ->execute([password_hash($nouveau, PASSWORD_DEFAULT), $uid]);
            flash('Mot de passe réinitialisé.');
        }
    }
}

/* Les administrateurs ne figurent pas ici : chacun gère son identifiant et son
   mot de passe depuis Paramètres → Mon compte. Cette page sert aux accès que
   VOUS attribuez — employés et clients. */
$users = $pdo->query("SELECT u.*, c.nom AS client_nom, e.nom AS employe_nom
                      FROM users u
                      LEFT JOIN clients c ON c.id=u.client_id
                      LEFT JOIN employes e ON e.id=u.employe_id
                      WHERE u.role <> 'admin'
                      ORDER BY FIELD(u.role,'employe','client'), u.nom")->fetchAll();

$roleLabel = ['admin'=>'Administrateur','employe'=>'Employé','client'=>'Client'];
$roleBadge = ['admin'=>'badge-violet','employe'=>'badge-teal','client'=>'badge-gold'];

admin_header('Comptes & accès', '', $pdo, $settings);
?>
<?php if ($err): ?><div class="flash error"><?= e($err) ?></div><?php endif; ?>

<?php if ($codeGenere): ?>
<div class="panel glass" style="border-left:4px solid var(--gold)">
  <h2 style="border:0;margin:0 0 8px">🔑 Code de réinitialisation généré</h2>
  <p style="color:var(--ink-dim);margin-bottom:12px">
    Pour <strong style="color:var(--ink)"><?= e($userGenere['nom']) ?></strong>
    (<?= e($userGenere['username']) ?>). Transmettez-lui ce code — il est valable 24 heures.
  </p>
  <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
    <div style="font-family:monospace;font-size:30px;font-weight:800;letter-spacing:.25em;
         color:var(--ink);background:var(--glass-border-soft);padding:14px 26px;border-radius:14px;
         border:1px dashed var(--gold)"><?= e($codeGenere) ?></div>
    <div style="flex:1;min-width:220px">
      <p style="font-size:13px;color:var(--ink-dim);line-height:1.7">
        La personne se rend sur la page de connexion, clique sur
        « Mot de passe oublié ? », saisit son nom d'utilisateur et ce code,
        puis choisit un nouveau mot de passe.
      </p>
      <p style="font-size:12.5px;color:var(--ink-faint);margin-top:6px">
        Lien direct : <code><?= e((defined('SITE_URL') && SITE_URL ? SITE_URL : '')) ?>/reset.php</code>
      </p>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="panel glass">
  <h2>👥 Comptes utilisateurs (<?= count($users) ?>)</h2>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Nom</th><th>Identifiant</th><th>Rôle</th><th>Connexion</th><th>Statut</th><th style="text-align:right">Réinitialisation</th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td><span style="font-weight:700;color:var(--ink)"><?= e($u['nom']) ?></span>
            <?php if ($u['email']): ?><br><small style="color:var(--ink-faint)"><?= e($u['email']) ?></small><?php endif; ?>
          </td>
          <td><?= e($u['username']) ?></td>
          <td><span class="badge <?= $roleBadge[$u['role']] ?? 'badge' ?>"><?= $roleLabel[$u['role']] ?? $u['role'] ?></span></td>
          <td><?= $u['google_id'] ? '<span title="Compte Google">🔵 Google</span>' : '🔑 Mot de passe' ?></td>
          <td><?= (int)$u['actif'] === 1 ? '<span class="badge badge-teal">Actif</span>' : '<span class="badge badge-danger">Inactif</span>' ?></td>
          <td style="text-align:right">
            <div class="td-actions" style="justify-content:flex-end">
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <button class="btn btn-glass btn-sm" name="generer" value="1" title="Générer un code de réinitialisation">🔑 Générer un code</button>
              </form>
              <button class="btn btn-glass btn-sm" onclick="document.getElementById('rd<?= $u['id'] ?>').style.display='flex'" title="Définir directement un mot de passe">✏️</button>
            </div>
            <form method="post" id="rd<?= $u['id'] ?>" style="display:none;gap:6px;margin-top:8px;justify-content:flex-end">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <input class="input" type="text" name="nouveau" placeholder="Nouveau mot de passe" minlength="6" style="max-width:200px">
              <button class="btn btn-gold btn-sm" name="reset_direct" value="1">Définir</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php admin_footer(); ?>
