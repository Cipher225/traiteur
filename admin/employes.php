<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/badges.php';
require_once __DIR__ . '/../config/protocole_admin.php';
if (!is_admin()) { flash("Réservé à l'administrateur.", 'error'); header('Location: index.php'); exit; }
$devise = $settings['devise'] ?? 'FCFA';

$modules = all_modules();
$attribuables = array_filter($modules, fn($m) => !$m[4] && !($m[5] ?? false)); // ni admin-only ni core

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Enregistrer les horaires de travail
    if (isset($_POST['maj_horaires'])) {
        $jours = array_values(array_intersect(['1','2','3','4','5','6','7'], $_POST['jours'] ?? []));
        $vals = [
            'work_hours_actif' => isset($_POST['work_actif']) ? '1' : '0',
            'work_jours' => implode(',', $jours) ?: '1,2,3,4,5,6',
            'work_debut' => preg_match('/^\d{2}:\d{2}$/', $_POST['work_debut'] ?? '') ? $_POST['work_debut'] : '08:00',
            'work_fin'   => preg_match('/^\d{2}:\d{2}$/', $_POST['work_fin'] ?? '') ? $_POST['work_fin'] : '17:00',
        ];
        $up = $pdo->prepare("INSERT INTO settings (cle,valeur) VALUES (?,?) ON DUPLICATE KEY UPDATE valeur=VALUES(valeur)");
        foreach ($vals as $k=>$v) $up->execute([$k,$v]);
        flash('Horaires d\'accès des employés mis à jour.');
        header('Location: employes.php'); exit;
    }

    /* ---- Protocole administrateur : demander, approuver, refuser, annuler ---- */
    if (isset($_POST['retrait_demander'])) {
        $err = null;
        $ok = admin_retrait_demander($pdo, (int)$_POST['retrait_demander'],
                                     (string)($_POST['retrait_action'] ?? ''),
                                     (string)($_POST['retrait_motif'] ?? ''), $err);
        flash($ok ? "Demande déposée. Elle prend effet dès qu'un second administrateur "
                  . "l'approuve, et expire sous " . ADMIN_RETRAIT_DELAI_H . " heures sans réponse."
                  : (string)$err, $ok ? 'success' : 'error');
        header('Location: employes.php#protocole'); exit;
    }
    if (isset($_POST['retrait_approuver'])) {
        $err = null;
        $ok = admin_retrait_approuver($pdo, (int)$_POST['retrait_approuver'], $err);
        flash($ok ? 'Retrait approuvé et appliqué.' : (string)$err, $ok ? 'success' : 'error');
        header('Location: employes.php#protocole'); exit;
    }
    if (isset($_POST['retrait_refuser'])) {
        admin_retrait_refuser($pdo, (int)$_POST['retrait_refuser'],
                              (string)($_POST['refus_motif'] ?? ''));
        flash('Demande refusée. Le compte reste administrateur.');
        header('Location: employes.php#protocole'); exit;
    }
    if (isset($_POST['retrait_annuler'])) {
        admin_retrait_annuler($pdo, (int)$_POST['retrait_annuler']);
        flash('Votre demande a été retirée.');
        header('Location: employes.php#protocole'); exit;
    }

    // Supprimer définitivement un employé (les DONNÉES de l'entreprise restent)
    if (isset($_POST['supprimer'])) {
        $eid = (int)$_POST['supprimer'];
        // Récupérer le compte lié pour archiver son identité AVANT suppression
        $uid = (int)$pdo->query("SELECT id FROM users WHERE employe_id=" . $eid . " AND role IN ('employe','admin') LIMIT 1")->fetchColumn();

        /* Un administrateur ne s'efface pas d'un clic : il faut l'accord d'un
           second. Le contrôle est ici, au plus près de la requête, et non dans
           l'affichage — un bouton caché n'a jamais empêché une requête POST. */
        if ($uid > 0 && admin_protege($pdo, $uid)) {
            flash("Ce compte est un compte administrateur : sa suppression demande "
                . "l'accord d'un second administrateur. Déposez la demande ci-dessous.", 'error');
            header('Location: employes.php#protocole'); exit;
        }

        if ($uid > 0) {
            archiver_membre($pdo, $uid); // garde le nom pour messages, forum, rapports…
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
        }
        // La fiche employé est retirée, mais messages/rapports/tâches/badges/documents demeurent.
        $pdo->prepare('DELETE FROM employes WHERE id=?')->execute([$eid]);
        flash('Employé supprimé. Toutes les données de l\'entreprise (messages, rapports, documents…) ont été conservées.');
        header('Location: employes.php'); exit;
    }
    // Activer / désactiver le compte
    if (isset($_POST['toggle_compte'])) {
        $uid = (int)$_POST['toggle_compte'];

        /* Désactiver, c'est fermer la porte : l'effet est celui d'un retrait,
           et la règle est donc la même. Seule la RÉACTIVATION reste libre —
           rendre ses accès à quelqu'un ne prive personne. */
        $st = $pdo->prepare("SELECT role, actif FROM users WHERE id=?");
        $st->execute([$uid]);
        $c = $st->fetch();

        if ($c && $c['role'] === 'admin' && !empty($c['actif']) && $uid !== (int)$_SESSION['admin_id']) {
            flash("Désactiver un administrateur revient à le retirer : il faut l'accord "
                . "d'un second administrateur. Déposez la demande ci-dessous.", 'error');
            header('Location: employes.php#protocole'); exit;
        }

        if ($uid !== (int)$_SESSION['admin_id'])
            $pdo->prepare("UPDATE users SET actif=1-actif WHERE id=? AND role IN ('employe','admin')")->execute([$uid]);
        flash('Statut du compte mis à jour.');
        header('Location: employes.php'); exit;
    }
    // Accès exceptionnel (hors horaires)
    if (isset($_POST['acces_exception'])) {
        $uid = (int)$_POST['acces_exception'];
        $mode = $_POST['exc_mode'] ?? 'jour';
        if ($mode === 'revoquer') {
            $pdo->prepare("UPDATE users SET acces_exception_until=NULL WHERE id=? AND role IN ('employe','admin')")->execute([$uid]);
            flash('Accès exceptionnel révoqué.');
        } else {
            // Déterminer la date de fin selon le mode choisi
            if ($mode === 'heures') {
                $h = max(1, min(72, (int)($_POST['exc_heures'] ?? 1)));  // 1 à 72 h
                $until = date('Y-m-d H:i:s', time() + $h * 3600);
            } elseif (preg_match('/^(\d+)h$/', $mode, $m)) {
                $until = date('Y-m-d H:i:s', time() + (int)$m[1] * 3600); // 1h, 2h, 4h…
            } elseif ($mode === 'custom' && !empty($_POST['exc_until'])) {
                $until = date('Y-m-d H:i:s', strtotime($_POST['exc_until']));
            } else { // 'jour' : jusqu'à la fin de la journée
                $until = date('Y-m-d 23:59:59');
            }
            $pdo->prepare("UPDATE users SET acces_exception_until=? WHERE id=? AND role IN ('employe','admin')")->execute([$until, $uid]);
            flash('Accès accordé jusqu\'au ' . date('d/m/Y à H:i', strtotime($until)) . '.');
        }
        header('Location: employes.php'); exit;
    }

    // Enregistrer un employé + son compte
    $id  = (int)($_POST['id'] ?? 0);
    $nom = trim($_POST['nom'] ?? '');
    if ($nom === '') { flash('Le nom est obligatoire.', 'error'); header('Location: employes.php'.($id?'?edit='.$id:'')); exit; }

    // Matricule : généré automatiquement à la création, sinon conservé.
    require_once __DIR__ . '/includes/badges.php';
    $matricule = trim($_POST['matricule'] ?? '');
    if ($matricule === '') {
        if ($id) {
            $matricule = (string)$pdo->query("SELECT matricule FROM employes WHERE id=$id")->fetchColumn();
        }
        if ($matricule === '' || $matricule === null) {
            $matricule = badge_generer_matricule($pdo, $settings, 'employe');
        }
    }

    // Photo employé (utilisée aussi pour badge/carte)
    $photoEmp = null;
    if (!empty($_FILES['photo']['name'])) $photoEmp = upload_image_redim($_FILES['photo'], UPLOAD_DIR, 400, 480, 'cover');

    $empData = [
        mb_substr($nom, 0, 120),
        mb_substr(trim($_POST['poste'] ?? ''), 0, 100),
        mb_substr($matricule, 0, 40),
        mb_substr(trim($_POST['categorie'] ?? ''), 0, 60),
        mb_substr(trim($_POST['departement'] ?? ''), 0, 120),
        mb_substr(trim($_POST['groupe_sanguin'] ?? ''), 0, 8),
        ($_POST['date_naissance'] ?? '') ?: null,
        mb_substr(trim($_POST['telephone'] ?? ''), 0, 30),
        mb_substr(trim($_POST['email'] ?? ''), 0, 120),
        mb_substr(trim($_POST['numero_cnps'] ?? ''), 0, 60),
        mb_substr(trim($_POST['banque'] ?? ''), 0, 120),
        mb_substr(trim($_POST['numero_compte'] ?? ''), 0, 60),
        max(0, (float)($_POST['salaire_base'] ?? 0)),
        ($_POST['date_embauche'] ?? '') ?: null,
        isset($_POST['actif']) ? 1 : 0,
    ];
    if ($id) {
        // La photo n'est mise à jour que si une nouvelle est fournie
        if ($photoEmp) {
            $anc = $pdo->query("SELECT photo FROM employes WHERE id=$id")->fetchColumn();
            if ($anc && is_file(UPLOAD_DIR.'/'.$anc)) @unlink(UPLOAD_DIR.'/'.$anc);
            $pdo->prepare('UPDATE employes SET nom=?,poste=?,matricule=?,categorie=?,departement=?,groupe_sanguin=?,date_naissance=?,telephone=?,email=?,numero_cnps=?,banque=?,numero_compte=?,salaire_base=?,date_embauche=?,actif=?,photo=? WHERE id=?')
                ->execute([...$empData, $photoEmp, $id]);
        } else {
            $pdo->prepare('UPDATE employes SET nom=?,poste=?,matricule=?,categorie=?,departement=?,groupe_sanguin=?,date_naissance=?,telephone=?,email=?,numero_cnps=?,banque=?,numero_compte=?,salaire_base=?,date_embauche=?,actif=? WHERE id=?')
                ->execute([...$empData, $id]);
        }
        $eid = $id;
    } else {
        $pdo->prepare('INSERT INTO employes (nom,poste,matricule,categorie,departement,groupe_sanguin,date_naissance,telephone,email,numero_cnps,banque,numero_compte,salaire_base,date_embauche,actif,photo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([...$empData, $photoEmp]);
        $eid = (int)$pdo->lastInsertId();
    }

    // Compte de connexion (facultatif)
    $username = preg_replace('/[^a-z0-9._@+-]/', '', strtolower(trim($_POST['username'] ?? '')));
    $pass     = $_POST['password'] ?? '';
    $perms    = array_values(array_intersect(array_keys($attribuables), $_POST['perms'] ?? []));

    // Compte déjà lié ?
    /* Rôle du compte : seul un administrateur peut en désigner un autre.
       Sans cette restriction, un employé pourrait s'octroyer tous les droits. */
    $roleDemande = (is_admin() && ($_POST['role_compte'] ?? '') === 'admin') ? 'admin' : 'employe';

    /* Un administrateur reçoit TOUT, sans qu'on ait à cocher quoi que ce soit :
       les cases du formulaire ne concernent que les employés. */
    if ($roleDemande === 'admin') $perms = permissions_admin();
    $permsJson = json_encode($perms);

    $stmt = $pdo->prepare("SELECT id, role FROM users WHERE employe_id=? AND role IN ('employe','admin')");
    $stmt->execute([$eid]);
    $ligneCompte = $stmt->fetch();
    $existingUid = $ligneCompte['id'] ?? null;

    /* Rétrograder un administrateur lui retire tous ses accès : cela passe par
       le protocole, comme une suppression. Sans ce garde-fou, il suffirait de
       décocher une liste déroulante pour contourner l'accord mutuel. */
    if ($existingUid && ($ligneCompte['role'] ?? '') === 'admin' && $roleDemande !== 'admin'
        && (int)$existingUid !== (int)$_SESSION['admin_id']) {
        flash("Retirer les droits d'un administrateur demande l'accord d'un second "
            . "administrateur. Ouvrez « Protocole administrateur » pour déposer la demande.", 'error');
        header('Location: employes.php?edit=' . $eid . '#protocole'); exit;
    }

    if ($username !== '') {
        // unicité
        $chk = $pdo->prepare('SELECT id FROM users WHERE username=? AND id<>?');
        $chk->execute([$username, (int)($existingUid ?: 0)]);
        if ($chk->fetch()) { flash('Cet identifiant est déjà pris.', 'error'); header('Location: employes.php?edit='.$eid); exit; }

        if ($existingUid) {
            // Un administrateur ne peut pas se rétrograder lui-même : il resterait sans accès
            $sePropre = ((int)$existingUid === (int)($_SESSION['admin_id'] ?? 0));
            if ($sePropre && ($ligneCompte['role'] ?? '') === 'admin' && $roleDemande !== 'admin') {
                $roleDemande = 'admin';
                flash("Vous ne pouvez pas retirer vos propres droits d'administrateur.", 'error');
            }
            $pdo->prepare('UPDATE users SET nom=?, username=?, permissions=?, role=? WHERE id=?')
                ->execute([mb_substr($nom,0,100), mb_substr($username,0,50), $permsJson, $roleDemande, $existingUid]);
            if ($pass !== '') {
                if (strlen($pass) < 6) { flash('Mot de passe : 6 caractères minimum.', 'error'); header('Location: employes.php?edit='.$eid); exit; }
                $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $existingUid]);
            }
            flash('Employé et compte mis à jour.');
        } else {
            if (strlen($pass) < 6) { flash('Pour créer le compte, indiquez un mot de passe (6 caractères min.).', 'error'); header('Location: employes.php?edit='.$eid); exit; }
            $pdo->prepare("INSERT INTO users (username,password,nom,role,permissions,employe_id,actif) VALUES (?,?,?,?,?,?,1)")
                ->execute([mb_substr($username,0,50), password_hash($pass, PASSWORD_DEFAULT),
                           mb_substr($nom,0,100), $roleDemande, $permsJson, $eid]);
            flash($roleDemande === 'admin'
                ? 'Compte ADMINISTRATEUR créé. Cette personne a désormais accès à toute l\'application.'
                : 'Employé enregistré et compte créé. Communiquez-lui son identifiant et son mot de passe.');
        }
    } else {
        // pas de username fourni : si un compte existe, on met juste à jour ses permissions
        if ($existingUid) {
            $pdo->prepare('UPDATE users SET nom=?, permissions=? WHERE id=?')->execute([mb_substr($nom,0,100), $permsJson, $existingUid]);
        }
        flash('Employé enregistré.');
    }
    header('Location: employes.php'); exit;
}

// Chargement pour édition
$edit = null; $compte = null; $edit_perms = [];
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM employes WHERE id=?'); $stmt->execute([(int)$_GET['edit']]); $edit = $stmt->fetch();
    if ($edit) {
        $c = $pdo->prepare("SELECT * FROM users WHERE employe_id=? AND role IN ('employe','admin')"); $c->execute([$edit['id']]); $compte = $c->fetch();
        $edit_perms = $compte && $compte['permissions'] ? (json_decode($compte['permissions'], true) ?: []) : [];
    }
}

/* ------------------------------------------------------- Protocole admin ----
   Les administrateurs en exercice, et les demandes de retrait en cours.
   ---------------------------------------------------------------------------- */
$moiId = (int)($_SESSION['admin_id'] ?? 0);
$lesAdmins = [];
try {
    $lesAdmins = $pdo->query("SELECT u.id, u.nom, u.username, u.actif, u.employe_id
                              FROM users u WHERE u.role='admin' ORDER BY u.nom")->fetchAll();
} catch (Throwable $e) {}
$retraits      = admin_retraits($pdo, 'attente');
$retraitsPasse = admin_retraits($pdo, 'histoire', 10);
$nbAdminsActifs = admin_nombre($pdo);

/* Quel administrateur fait déjà l'objet d'une demande ? */
$visePar = [];
foreach ($retraits as $d) $visePar[(int)$d['cible_id']] = $d;

/* Une équipe grandit, et les anciens employés restent en fiche : la liste se
   parcourt par pages et se cherche par nom, poste ou matricule. */
$q = trim($_GET['q'] ?? '');

$jointures = "FROM employes e
              LEFT JOIN users u ON u.employe_id = e.id AND u.role IN ('employe','admin')";
/* Les fiches personnelles, créées depuis « Mon profil », restent hors liste. */
$where = ' WHERE COALESCE(e.fiche_perso, 0) = 0'; $args = [];

$rch = recherche_sql($q, ['e.nom', 'e.poste', 'e.matricule', 'e.telephone', 'u.username']);
$where .= $rch['sql']; $args = array_merge($args, $rch['args']);

$pg = pagination($pdo, "SELECT COUNT(*) $jointures $where", $args, 30);

$st = $pdo->prepare("SELECT e.*, u.id AS uid, u.username, u.actif AS compte_actif,
                            u.role AS compte_role, u.permissions, u.acces_exception_until
                     $jointures $where ORDER BY e.actif DESC, e.nom" . $pg['limite']);
$st->execute($args);
$rows = $st->fetchAll();

admin_header('Employés & accès', 'employes', $pdo, $settings);
$wJours = array_filter(array_map('intval', explode(',', $settings['work_jours'] ?? '1,2,3,4,5,6')));
$joursNoms = [1=>'Lun',2=>'Mar',3=>'Mer',4=>'Jeu',5=>'Ven',6=>'Sam',7=>'Dim'];
$ACTIONS = admin_actions_retrait();
?>

<!-- ================= PROTOCOLE ADMINISTRATEUR ================= -->
<div class="panel glass prot" id="protocole">
  <div class="mod-tete">
    <h2 style="margin:0">🔐 Protocole administrateur
      <span class="cnt"><?= count($lesAdmins) ?></span></h2>
    <?php if ($retraits): ?>
    <span class="prot-att"><?= count($retraits) ?> demande<?= count($retraits) > 1 ? 's' : '' ?> en attente</span>
    <?php endif; ?>
  </div>

  <p class="prot-regle">
    Un administrateur voit tout et peut tout défaire : <strong>aucun ne peut en écarter
    un autre seul</strong>. Il dépose une demande motivée, qu’un <strong>second
    administrateur</strong> approuve — la personne visée comprise, si elle accepte son
    départ. Sans réponse, la demande expire au bout de <?= ADMIN_RETRAIT_DELAI_H ?> heures.
    Le dernier administrateur actif ne peut jamais être retiré.
  </p>

  <?php /* ---- Demandes en attente ---- */ ?>
  <?php foreach ($retraits as $d):
    $moiDemandeur = ((int)$d['demandeur_id'] === $moiId);
    $moiVise      = ((int)$d['cible_id'] === $moiId);
    $reste        = strtotime((string)$d['expire_le']) - time();
    $resteTxt     = $reste > 3600 ? floor($reste / 3600) . ' h' : max(1, floor($reste / 60)) . ' min';
  ?>
  <div class="prot-dem <?= $moiVise ? 'vise' : '' ?>">
    <div class="pd-tete">
      <span class="pd-ico"><?= $moiVise ? '⚠️' : '🔎' ?></span>
      <div>
        <strong><?= e((string)$ACTIONS[$d['action']][0] ?? $d['action']) ?> —
          <?= e((string)$d['cible_nom']) ?><?= $moiVise ? ' (vous)' : '' ?></strong>
        <span>Demandé par <?= e((string)$d['demandeur_nom']) ?>
          le <?= date('d/m/Y à H:i', strtotime((string)$d['created_at'])) ?>
          · expire dans <?= $resteTxt ?></span>
      </div>
    </div>
    <p class="pd-motif"><?= nl2br(e((string)$d['motif'])) ?></p>

    <div class="pd-actes">
      <?php if ($moiDemandeur): ?>
        <span class="pd-note">Vous avez déposé cette demande : un autre administrateur
          doit l’approuver.</span>
        <form method="post" style="display:inline"
              data-confirm="Retirer votre demande concernant <?= e((string)$d['cible_nom']) ?> ?">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <button class="btn btn-glass btn-sm" name="retrait_annuler" value="<?= (int)$d['id'] ?>">
            Retirer ma demande</button>
        </form>
      <?php else: ?>
        <form method="post" style="display:inline"
              data-confirm="Approuver ce retrait ? L’action sera appliquée immédiatement.">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <button class="btn btn-gold btn-sm" name="retrait_approuver" value="<?= (int)$d['id'] ?>">
            ✓ J’approuve<?= $moiVise ? ' mon retrait' : '' ?></button>
        </form>
        <form method="post" class="pd-refus">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input class="input" name="refus_motif" maxlength="500" placeholder="Motif du refus (facultatif)">
          <button class="btn btn-glass btn-sm" name="retrait_refuser" value="<?= (int)$d['id'] ?>">
            ✕ Je refuse</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <?php /* ---- Les administrateurs en exercice ---- */ ?>
  <div class="prot-liste">
    <?php foreach ($lesAdmins as $a):
      $estMoi   = ((int)$a['id'] === $moiId);
      $dernier  = ($nbAdminsActifs <= 1 && !empty($a['actif']));
      $enCours  = $visePar[(int)$a['id']] ?? null;
    ?>
    <div class="pa <?= empty($a['actif']) ? 'off' : '' ?>">
      <span class="pa-ico">👤</span>
      <div class="pa-c">
        <div class="pa-n"><?= e((string)$a['nom']) ?>
          <?php if ($estMoi): ?><span class="pa-moi">vous</span><?php endif; ?>
          <?php if (empty($a['actif'])): ?><span class="pa-etat">désactivé</span><?php endif; ?>
          <?php if ($dernier): ?><span class="pa-cle">🔑 dernier administrateur</span><?php endif; ?>
        </div>
        <div class="pa-d"><code><?= e((string)$a['username']) ?></code> · accès à toute l’application</div>
      </div>
      <div class="pa-a">
        <?php if ($enCours): ?>
          <span class="pa-encours">Demande en cours</span>
        <?php elseif ($estMoi): ?>
          <span class="pa-note">Un retrait ne se demande pas pour soi-même</span>
        <?php elseif ($dernier): ?>
          <span class="pa-note">Protégé : le retirer fermerait l’application</span>
        <?php else: ?>
          <details class="pa-form">
            <summary class="btn btn-glass btn-sm">Demander un retrait</summary>
            <form method="post" class="pf">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <label>Que demandez-vous ?</label>
              <select class="input" name="retrait_action">
                <?php foreach ($ACTIONS as $k => $lib): ?>
                <option value="<?= $k ?>"><?= e($lib[0]) ?> — <?= e($lib[1]) ?></option>
                <?php endforeach; ?>
              </select>
              <label>Motif <span class="pf-aide">— c’est ce que le second administrateur lira</span></label>
              <textarea class="input" name="retrait_motif" rows="3" required minlength="10"
                placeholder="Expliquez la situation en quelques phrases."></textarea>
              <button class="btn btn-gold btn-sm" name="retrait_demander" value="<?= (int)$a['id'] ?>">
                Déposer la demande</button>
              <span class="pf-aide">Rien ne se passe avant l’accord d’un second administrateur.
                <?= e((string)$a['nom']) ?> en est prévenu immédiatement.</span>
            </form>
          </details>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($retraitsPasse): ?>
  <details class="prot-hist">
    <summary>Demandes déjà tranchées (<?= count($retraitsPasse) ?>)</summary>
    <?php
    $etats = ['approuvee' => ['Approuvée', 'ok'], 'refusee' => ['Refusée', 'no'],
              'annulee' => ['Retirée', 'na'], 'expiree' => ['Expirée sans réponse', 'na']];
    foreach ($retraitsPasse as $d): $et = $etats[$d['statut']] ?? [$d['statut'], 'na']; ?>
    <div class="ph-l">
      <span class="ph-e <?= $et[1] ?>"><?= e($et[0]) ?></span>
      <span class="ph-t"><?= e((string)($ACTIONS[$d['action']][0] ?? $d['action'])) ?>
        — <?= e((string)$d['cible_nom']) ?></span>
      <span class="ph-q"><?= e((string)$d['demandeur_nom']) ?>
        <?= $d['approbateur_nom'] ? ' → ' . e((string)$d['approbateur_nom']) : '' ?>
        · <?= date('d/m/Y', strtotime((string)$d['created_at'])) ?></span>
    </div>
    <?php endforeach; ?>
  </details>
  <?php endif; ?>
</div>

<div class="panel glass" style="border-left:4px solid var(--gold)">
  <h2>🕐 Horaires d'accès des employés</h2>
  <p style="color:var(--ink-dim);font-size:13.5px;margin:-4px 0 14px">En dehors de ces horaires, les employés ne peuvent pas accéder à leur espace de travail. (Ne concerne pas l'administrateur ni les clients.)</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="maj_horaires" value="1">
    <label class="switch" style="margin-bottom:14px"><input type="checkbox" name="work_actif" <?= ($settings['work_hours_actif'] ?? '1')==='1'?'checked':'' ?>> Activer la restriction horaire</label>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
      <?php foreach ($joursNoms as $n=>$lib): ?>
      <label class="jour-chip"><input type="checkbox" name="jours[]" value="<?= $n ?>" <?= in_array($n,$wJours,true)?'checked':'' ?>><span><?= $lib ?></span></label>
      <?php endforeach; ?>
    </div>
    <div class="form-grid" style="max-width:420px">
      <div class="field"><label>Heure de début</label><input class="input" type="time" name="work_debut" value="<?= e($settings['work_debut'] ?? '08:00') ?>"></div>
      <div class="field"><label>Heure de fin</label><input class="input" type="time" name="work_fin" value="<?= e($settings['work_fin'] ?? '17:00') ?>"></div>
    </div>
    <button class="btn btn-gold" style="margin-top:16px">Enregistrer les horaires</button>
  </form>
</div>
<?php
?>
<details class="panel glass panel-pliable" id="form" <?= $edit ? 'open' : '' ?>>
  <summary class="panel-titre"><?= $edit ? '✏️ Modifier : ' . e($edit['nom']) : '➕ Nouvel employé' ?><span class="chev">▾</span>
  </summary>
  <?php if ($edit): ?><p style="margin:0 0 10px"><a href="employes.php" class="btn btn-glass btn-sm">Annuler la modification</a></p><?php endif; ?>
  <p style="color:var(--ink-faint);font-size:13.5px;margin:-6px 0 16px">Fiche de l'employé, accès à l'espace et permissions : tout se gère ici, au même endroit.</p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">

    <h3 class="form-section">🧑‍🍳 Informations de l'employé</h3>
    <div class="form-grid">
      <div class="field"><label>Nom complet *</label><input class="input" name="nom" required value="<?= e($edit['nom'] ?? '') ?>"></div>
      <div class="field"><label>Poste</label><input class="input" name="poste" value="<?= e($edit['poste'] ?? '') ?>"></div>
      <div class="field"><label>Matricule <span style="color:var(--ink-faint);font-weight:400">— généré automatiquement si vide</span></label>
        <input class="input champ-auto" name="matricule" readonly value="<?= e($edit['matricule'] ?? '') ?>" placeholder="<?= $edit ? '' : 'Généré automatiquement à l\'enregistrement' ?>">
        <div style="margin-top:5px;font-size:12px;color:var(--ink-faint)">Le matricule est attribué automatiquement, il n'est pas modifiable.</div></div>
      <div class="field"><label>Département / Service</label><input class="input" name="departement" value="<?= e($edit['departement'] ?? '') ?>"></div>
      <div class="field"><label>Catégorie / Classification</label><input class="input" name="categorie" value="<?= e($edit['categorie'] ?? '') ?>" placeholder="ex : Cat. 3, Agent de maîtrise"></div>
      <div class="field"><label>Groupe sanguin</label>
        <select class="input" name="groupe_sanguin">
          <option value="">—</option>
          <?php foreach (['O+','O-','A+','A-','B+','B-','AB+','AB-'] as $gs): ?>
          <option value="<?= $gs ?>" <?= ($edit['groupe_sanguin'] ?? '')===$gs?'selected':'' ?>><?= $gs ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Date de naissance</label><input class="input" type="date" name="date_naissance" value="<?= e($edit['date_naissance'] ?? '') ?>"></div>
      <div class="field"><label>Salaire de base (<?= e($devise) ?>)</label><input class="input" type="number" name="salaire_base" min="0" step="1000" value="<?= e($edit['salaire_base'] ?? '') ?>"></div>
      <div class="field"><label>Date d'embauche</label><input class="input" type="date" name="date_embauche" value="<?= e($edit['date_embauche'] ?? '') ?>"></div>
      <div class="field"><label>Téléphone</label><input class="input" name="telephone" value="<?= e($edit['telephone'] ?? '') ?>"></div>
      <div class="field"><label>E-mail</label><input class="input" type="email" name="email" value="<?= e($edit['email'] ?? '') ?>"></div>
      <div class="field full"><label>Photo <span style="color:var(--ink-faint);font-weight:400">— pour le badge et la carte (portrait)</span></label>
        <input class="input" type="file" name="photo" accept="image/*" data-redim="400x480" data-redim-mode="cover">
        <?php if (!empty($edit['photo'])): ?><p style="font-size:12px;color:var(--ink-faint);margin-top:6px">Photo actuelle conservée si vous n'en choisissez pas une nouvelle.</p><?php endif; ?>
      </div>
      <div class="field"><label>N° CNPS</label><input class="input" name="numero_cnps" value="<?= e($edit['numero_cnps'] ?? '') ?>"></div>
      <div class="field"><label>Banque (pour la paie)</label><input class="input" name="banque" value="<?= e($edit['banque'] ?? '') ?>"></div>
      <div class="field"><label>N° de compte / RIB</label><input class="input" name="numero_compte" value="<?= e($edit['numero_compte'] ?? '') ?>"></div>
      <label class="switch"><input type="checkbox" name="actif" <?= ($edit['actif'] ?? 1) ? 'checked' : '' ?>> Employé actif</label>
    </div>

    <h3 class="form-section">🔐 Accès à l'espace <?= $compte ? '<span class="badge '.($compte['actif']?'badge-teal':'badge-danger').'">'.($compte['actif']?'Compte actif':'Compte désactivé').'</span>' : '' ?></h3>
    <p style="color:var(--ink-faint);font-size:12.5px;margin:-4px 0 14px">Renseignez un identifiant et un mot de passe pour donner à l'employé un accès à son espace. Laissez vide si l'employé n'a pas besoin de se connecter.</p>
    <div class="form-grid">
      <div class="field"><label>Identifiant de connexion</label><input class="input" name="username" value="<?= e($compte['username'] ?? '') ?>" placeholder="ex : grace" pattern="[a-zA-Z0-9._@+-]*"></div>
      <div class="field"><label>Mot de passe <?= $compte ? '(laisser vide = inchangé)' : '' ?></label><input class="input" type="text" name="password" placeholder="6 caractères min." autocomplete="new-password"></div>
      <?php if (is_admin()): $roleActuel = $compte['role'] ?? 'employe'; ?>
      <div class="field full">
        <label>Type de compte</label>
        <select class="input" name="role_compte" id="sel-role-compte">
          <option value="employe" <?= $roleActuel !== 'admin' ? 'selected' : '' ?>>👤 Employé — accès limité aux sections cochées</option>
          <option value="admin"   <?= $roleActuel === 'admin' ? 'selected' : '' ?>>👑 Administrateur — accès total à l'application</option>
        </select>
        <div id="avert-admin" style="margin-top:8px;padding:9px 13px;border-radius:11px;font-size:12.5px;
             display:<?= $roleActuel === 'admin' ? 'block' : 'none' ?>;
             color:#f0b429;background:rgba(240,180,41,.12);border:1px solid rgba(240,180,41,.3)">
          ⚠️ Un administrateur peut tout consulter et tout modifier : facturation, comptabilité,
          paiements, suppression de documents et gestion des accès. N'accordez ce rôle qu'à une
          personne de confiance.
        </div>
      </div>
      <?php endif; ?>
    </div>

    <h3 class="form-section">🗂️ Sections autorisées</h3>
    <p style="color:var(--ink-faint);font-size:12.5px;margin:-4px 0 14px">Le tableau de bord, la messagerie, le forum, les tâches et les rapports sont toujours accessibles. Cochez les autres sections auxquelles l'employé aura accès.</p>
    <div id="perms-admin" style="margin:-8px 0 14px;padding:10px 14px;border-radius:11px;font-size:12.5px;
         display:<?= ($compte['role'] ?? '') === 'admin' ? 'block' : 'none' ?>;
         color:#7dd3fc;background:rgba(125,211,252,.1);border:1px solid rgba(125,211,252,.28)">
      👑 Ce compte est administrateur : il reçoit <strong>toutes les sections</strong>, y compris
      celles qui ne figurent pas ci-dessous. Les cases ne servent qu'aux comptes employés.
    </div>
    <div class="perms-grid">
      <?php foreach (groupes_modules() as $g=>$gl):
        $items = array_filter($attribuables, fn($m)=>$m[3]===$g); if(!$items) continue; ?>
      <div class="perms-col">
        <div class="perms-title"><?= $gl ?></div>
        <?php foreach ($items as $k=>[$label,$ico]): ?>
        <label class="perm-item"><input type="checkbox" name="perms[]" value="<?= $k ?>" <?= in_array($k,$edit_perms,true)?'checked':'' ?>><span><?= $ico ?> <?= e($label) ?></span></label>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <div style="display:flex;gap:10px;margin-top:20px;flex-wrap:wrap">
      <button class="btn btn-gold"><?= $edit ? 'Enregistrer' : 'Ajouter l\'employé' ?></button>
      <label class="btn btn-glass btn-sm" style="cursor:pointer" onclick="document.querySelectorAll('.perms-grid input').forEach(c=>c.checked=true)">Tout cocher</label>
      <label class="btn btn-glass btn-sm" style="cursor:pointer" onclick="document.querySelectorAll('.perms-grid input').forEach(c=>c.checked=false)">Tout décocher</label>
    </div>
  </form>
</details>

<div class="panel glass tbl-equipe">
  <div class="mod-tete">
    <h2 style="margin:0">🧑‍🍳 Équipe <span class="cnt"><?= number_format($pg['total'], 0, ',', ' ') ?></span></h2>
    <?= barre_recherche($q, 'Nom, poste, matricule…', $_GET) ?>
  </div>
  <div class="tbl-wrap defilant">
    <table>
      <thead><tr><th>Nom</th><th>Poste</th><th>Salaire base</th><th>Accès</th><th>Statut</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): $p = $r['permissions'] ? (json_decode($r['permissions'],true) ?: []) : []; ?>
        <tr>
          <td><span class="emp-nom"><?= e($r['nom']) ?></span><?= $r['matricule'] ? '<span class="emp-mat">'.e($r['matricule']).'</span>' : '' ?></td>
          <td><?= e($r['poste'] ?: '—') ?></td>
          <td><strong><?= money($r['salaire_base'], $devise) ?></strong></td>
          <td>
            <?php if ($r['uid']): ?>
              <code><?= e($r['username']) ?></code>
              <span class="badge <?= $r['compte_actif']?'badge-teal':'badge-danger' ?>" style="font-size:10px"><?= $r['compte_actif']?'actif':'désactivé' ?></span>
              <?php if ($p): $libs = implode(', ', array_map(fn($k)=>$modules[$k][0]??$k, $p)); ?>
                <br><small style="color:var(--ink-faint)" title="<?= e($libs) ?>"><?= count($p) ?> section<?= count($p)>1?'s':'' ?> ⓘ</small>
              <?php endif; ?>
            <?php else: ?><small style="color:var(--ink-faint)">Pas de compte</small><?php endif; ?>
          </td>
          <td><span class="badge <?= $r['actif'] ? 'badge-teal' : 'badge-danger' ?>"><?= $r['actif'] ? 'Actif' : 'Inactif' ?></span></td>
          <td>
            <div class="td-actions">
              <a class="btn btn-glass btn-sm" href="paie.php?edit=new&employe=<?= $r['id'] ?>" title="Bulletin de paie">📄</a>
              <a class="btn btn-glass btn-sm" href="?edit=<?= $r['id'] ?>#form" title="Modifier">✏️</a>
              <?php if ($r['uid']): ?>
              <?php /* Fermer l'accès d'un administrateur passe par le protocole ;
                       le rouvrir ne prive personne et reste libre. */
                    $estAdminLigne = (($r['compte_role'] ?? '') === 'admin');
                    if ($estAdminLigne && $r['compte_actif'] && (int)$r['uid'] !== $moiId): ?>
                <a class="btn btn-glass btn-sm" href="#protocole"
                   title="Retirer cet administrateur demande l'accord d'un second">🔐</a>
              <?php else: ?>
              <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <button class="btn btn-glass btn-sm" name="toggle_compte" value="<?= $r['uid'] ?>" title="<?= $r['compte_actif']?'Désactiver l\'accès':'Réactiver l\'accès' ?>"><?= $r['compte_actif']?'⏸️':'▶️' ?></button></form>
              <?php endif; ?>
              <?php $excActif = $r['acces_exception_until'] && strtotime($r['acces_exception_until']) >= time(); ?>
              <details class="exc-menu" style="position:relative;display:inline-block">
                <summary class="btn btn-sm <?= $excActif?'btn-gold':'btn-glass' ?>" title="Accorder un accès hors horaires">🕑</summary>
                <div class="glass exc-pop">
                  <div class="exc-pop-titre">⏱️ Accès hors horaires</div>
                  <?php if ($excActif): ?>
                  <p class="exc-actif">✓ Autorisé jusqu'au <strong><?= date('d/m à H:i', strtotime($r['acces_exception_until'])) ?></strong></p>
                  <?php else: ?>
                  <p class="exc-hint">Accordez à cet employé un accès temporaire pour un travail urgent :</p>
                  <?php endif; ?>
                  <form method="post" class="exc-form">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="acces_exception" value="<?= $r['uid'] ?>">
                    <div class="exc-rapide">
                      <button class="btn btn-glass btn-sm" name="exc_mode" value="1h">1 h</button>
                      <button class="btn btn-glass btn-sm" name="exc_mode" value="2h">2 h</button>
                      <button class="btn btn-glass btn-sm" name="exc_mode" value="4h">4 h</button>
                      <button class="btn btn-glass btn-sm" name="exc_mode" value="jour">Journée</button>
                    </div>
                    <div class="exc-perso">
                      <label>Durée précise :</label>
                      <div class="exc-perso-row">
                        <input class="input" type="number" name="exc_heures" min="1" max="72" step="1" placeholder="Ex : 3">
                        <span>heure(s)</span>
                        <button class="btn btn-gold btn-sm" name="exc_mode" value="heures">Accorder</button>
                      </div>
                    </div>
                    <?php if ($excActif): ?>
                    <button class="btn btn-danger btn-sm exc-revoq" name="exc_mode" value="revoquer">Révoquer l'accès</button>
                    <?php endif; ?>
                  </form>
                </div>
              </details>
              <?php endif; ?>
              <?php if (($r['compte_role'] ?? '') === 'admin'): ?>
                <a class="btn btn-glass btn-sm" href="#protocole"
                   title="Administrateur : la suppression demande l'accord d'un second">🔐</a>
              <?php else: ?>
              <form method="post" data-confirm="Supprimer « <?= e($r['nom']) ?> » et son accès ?">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <button class="btn btn-danger btn-sm" name="supprimer" value="<?= $r['id'] ?>">✕</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" style="text-align:center;padding:30px;color:var(--ink-faint)">Aucun employé.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
(function(){
  var sel = document.getElementById('sel-role-compte');
  var av  = document.getElementById('avert-admin');
  var pa  = document.getElementById('perms-admin');
  if (!sel) return;
  /* Les cases de sections n'ont aucun effet sur un administrateur : on le dit
     au moment où le rôle change, plutôt que de laisser cocher pour rien. */
  sel.addEventListener('change', function () {
    var estAdmin = (this.value === 'admin');
    if (av) av.style.display = estAdmin ? 'block' : 'none';
    if (pa) pa.style.display = estAdmin ? 'block' : 'none';
    document.querySelectorAll('.perms-grid input[type=checkbox]').forEach(function (c) {
      if (estAdmin) { c.dataset.avant = c.checked ? '1' : '0'; c.checked = true; c.disabled = true; }
      else { c.disabled = false; if (c.dataset.avant !== undefined) c.checked = c.dataset.avant === '1'; }
    });
  });
  if (sel.value === 'admin') sel.dispatchEvent(new Event('change'));
})();
</script>
<?= pagination_html($pg, 'employé', $_GET) ?>

<?php admin_footer(); ?>
