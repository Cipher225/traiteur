<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';

// Harmonisation unique : tout client avec une raison sociale devient de type « entreprise »
// (corrige les fiches créées avant l'ajout du type de client).
try {
    $pdo->query("UPDATE clients SET type_client='entreprise' WHERE entreprise IS NOT NULL AND entreprise <> '' AND type_client <> 'entreprise'");
} catch (\Throwable $e) { /* sans effet si la colonne n'existe pas encore */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (isset($_POST['toggle_compte'])) {
        $uid = (int)$_POST['toggle_compte'];
        $pdo->prepare("UPDATE users SET actif=1-actif WHERE id=? AND role='client'")->execute([$uid]);
        flash('Statut de l\'accès client mis à jour.');
        header('Location: clients.php'); exit;
    }

    /* --- Suppression d'un client ---
       Le client n'est supprimable que s'il n'a aucun document rattaché : sinon on
       perdrait la traçabilité de factures ou de paiements déjà émis. */
    if (isset($_POST['supprimer'])) {
        if (!is_admin()) { flash("Seul un administrateur peut supprimer un client.", 'error'); header('Location: clients.php'); exit; }
        $cid = (int)$_POST['supprimer'];

        $liens = [];
        foreach ([['factures', 'facture'], ['recus', 'entrée / sortie'],
                  ['commandes_client', 'commande'], ['paiements', 'paiement']] as [$table, $libelle]) {
            try {
                $st = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE client_id=?");
                $st->execute([$cid]);
                $n = (int)$st->fetchColumn();
                if ($n > 0) $liens[] = $n . ' ' . $libelle . ($n > 1 ? 's' : '');
            } catch (Throwable $e) { /* table absente : on ignore */ }
        }

        if ($liens) {
            flash("Ce client ne peut pas être supprimé : il possède " . implode(', ', $liens)
                . ". Vous pouvez désactiver son accès à la place.", 'error');
            header('Location: clients.php'); exit;
        }

        $pdo->prepare("DELETE FROM users WHERE client_id=? AND role='client'")->execute([$cid]);
        $pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$cid]);
        journaliser($pdo, 'suppression', 'client', $cid, 'Client supprimé');
        flash('Client supprimé.');
        header('Location: clients.php'); exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    $nom = trim($_POST['nom'] ?? '');
    if ($nom === '') { flash('Le nom du client est obligatoire.', 'error'); header('Location: clients.php'); exit; }

    $entreprise = mb_substr(trim($_POST['entreprise'] ?? ''), 0, 150);
    // Type : « entreprise » si un nom d'entreprise est renseigné (ou choix explicite), sinon « individuel »
    $typeClient = (($_POST['type_client'] ?? '') === 'entreprise' || $entreprise !== '') ? 'entreprise' : 'individuel';

    $data = [
        mb_substr($nom, 0, 120),
        $entreprise,
        $typeClient,
        mb_substr(trim($_POST['telephone'] ?? ''), 0, 30),
        mb_substr(trim($_POST['email'] ?? ''), 0, 120),
        mb_substr(trim($_POST['adresse'] ?? ''), 0, 255),
        mb_substr(trim($_POST['ncc'] ?? ''), 0, 60),
        mb_substr(trim($_POST['notes'] ?? ''), 0, 1000),
    ];
    if ($id) {
        $pdo->prepare('UPDATE clients SET nom=?, entreprise=?, type_client=?, telephone=?, email=?, adresse=?, ncc=?, notes=? WHERE id=?')->execute([...$data, $id]);

        /* Le compte de connexion suit la fiche. Sans cela, le client verrait
           encore son ancien nom dans son espace, et les emails partiraient à
           l'ancienne adresse — un décalage invisible mais gênant.
           On ne touche ni au mot de passe, ni au lien Google, ni à
           l'identifiant : ils appartiennent au client. */
        try {
            $pdo->prepare("UPDATE users SET nom = ?, email = COALESCE(NULLIF(?, ''), email),
                                            telephone = COALESCE(NULLIF(?, ''), telephone)
                           WHERE client_id = ? AND role = 'client'")
                ->execute([$data[0], $data[4], $data[3], $id]);
        } catch (Throwable $e) { /* la fiche reste enregistrée même sans compte lié */ }
        $cid = $id; flash('Client modifié.');
    } else {
        $pdo->prepare('INSERT INTO clients (nom, entreprise, type_client, telephone, email, adresse, ncc, notes) VALUES (?,?,?,?,?,?,?,?)')->execute($data);
        $cid = (int)$pdo->lastInsertId(); flash('Client ajouté au fichier clients.');
    }

    // Accès à l'espace client (facultatif)
    $username = preg_replace('/[^a-z0-9._@+-]/', '', strtolower(trim($_POST['username'] ?? '')));
    $pass = $_POST['password'] ?? '';
    $c = $pdo->prepare("SELECT id FROM users WHERE client_id=? AND role='client'");
    $c->execute([$cid]); $existingUid = $c->fetchColumn();

    if ($username !== '') {
        $chk = $pdo->prepare('SELECT id FROM users WHERE username=? AND id<>?');
        $chk->execute([$username, (int)($existingUid ?: 0)]);
        if ($chk->fetch()) { flash('Cet identifiant est déjà pris.', 'error'); header('Location: clients.php?edit='.$cid); exit; }
        if ($existingUid) {
            $pdo->prepare('UPDATE users SET nom=?, username=? WHERE id=?')->execute([mb_substr($nom,0,100), mb_substr($username,0,50), $existingUid]);
            if ($pass !== '') {
                if (strlen($pass) < 6) { flash('Mot de passe : 6 caractères minimum.', 'error'); header('Location: clients.php?edit='.$cid); exit; }
                $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $existingUid]);
            }
            flash('Client et accès mis à jour.');
        } else {
            if (strlen($pass) < 6) { flash('Pour créer l\'accès, indiquez un mot de passe (6 caractères min.).', 'error'); header('Location: clients.php?edit='.$cid); exit; }
            $pdo->prepare("INSERT INTO users (username,password,nom,role,client_id,actif) VALUES (?,?,?,'client',?,1)")
                ->execute([mb_substr($username,0,50), password_hash($pass, PASSWORD_DEFAULT), mb_substr($nom,0,100), $cid]);
            flash('Client enregistré et accès à l\'espace créé. Communiquez-lui son identifiant et son mot de passe.');
        }
    }
    header('Location: clients.php'); exit;
}

$edit = null; $compte = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM clients WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch();
    if ($edit) { $c = $pdo->prepare("SELECT * FROM users WHERE client_id=? AND role='client'"); $c->execute([$edit['id']]); $compte = $c->fetch(); }
}
/* ----------------------------------------------------------------------------
   Fichier clients
   Une simple liste de noms ne dit rien d'utile. Ce qu'on cherche en ouvrant
   cette page, c'est : qui est ce client, combien il nous a rapporté, s'il nous
   doit encore quelque chose, et quand on a travaillé avec lui la dernière fois.
   ---------------------------------------------------------------------------- */
$q       = trim($_GET['q'] ?? '');
$fType   = $_GET['t'] ?? '';                       // entreprise | individuel
$fLettre = strtoupper(trim($_GET['l'] ?? ''));     // navigation alphabétique
$tri     = $_GET['tri'] ?? 'nom';                  // nom | ca | recent

$where = []; $args = [];
if ($q !== '') {
    $where[] = "(c.nom LIKE ? OR c.entreprise LIKE ? OR c.telephone LIKE ? OR c.email LIKE ?)";
    array_push($args, "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($fType === 'entreprise')      { $where[] = "COALESCE(c.entreprise,'') <> ''"; }
elseif ($fType === 'individuel')  { $where[] = "COALESCE(c.entreprise,'') = ''"; }
if ($fLettre !== '' && preg_match('/^[A-Z]$/', $fLettre)) {
    $where[] = "UPPER(LEFT(COALESCE(NULLIF(c.entreprise,''), c.nom), 1)) = ?";
    $args[] = $fLettre;
}
$sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$ordres = [
    'nom'    => 'affiche ASC',
    'ca'     => 'ca DESC, affiche ASC',
    'recent' => 'derniere IS NULL, derniere DESC',
];
$ordre = $ordres[$tri] ?? $ordres['nom'];

$pg = pagination($pdo, "SELECT COUNT(*) FROM clients c $sqlWhere", $args, 24);

/* Chaque client arrive avec son historique : nombre de documents, chiffre
   d'affaires facturé, reste dû et date de la dernière prestation. Tout est
   calculé en une seule requête — une par client rendrait la page lente. */
$sql = "SELECT c.*, u.id AS uid, u.username, u.actif AS compte_actif,
               COALESCE(NULLIF(c.entreprise,''), c.nom) AS affiche,
               (SELECT COUNT(*) FROM factures f
                 WHERE f.client_id = c.id AND f.type='facture' AND f.statut <> 'annulee') AS nb_docs,
               (SELECT COALESCE(SUM(
                    GREATEST((SELECT COALESCE(SUM(l.quantite * IF(l.par_jour = 1, GREATEST(1, f.nb_jours), 1) * l.prix_unitaire),0)
                                FROM facture_lignes l WHERE l.facture_id = f.id) - COALESCE(f.remise,0), 0)
                    * (1 + IF(f.tva_applicable=1, f.tva_taux/100, 0))), 0)
                  FROM factures f
                 WHERE f.client_id = c.id AND f.type='facture' AND f.statut <> 'annulee') AS ca,
               (SELECT COALESCE(SUM(r.montant),0) FROM recus r
                 WHERE r.client_id = c.id AND r.type='entree') AS regle,
               (SELECT MAX(f.date_emission) FROM factures f
                 WHERE f.client_id = c.id AND f.statut <> 'annulee') AS derniere
          FROM clients c
          LEFT JOIN users u ON u.client_id = c.id AND u.role='client'
          $sqlWhere
      ORDER BY $ordre" . $pg['limite'];
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$clients = $stmt->fetchAll();

/* Lettres réellement présentes : proposer un « K » sans client serait
   frustrant. */
$lettres = [];
try {
    $lettres = $pdo->query("SELECT DISTINCT UPPER(LEFT(COALESCE(NULLIF(entreprise,''), nom), 1)) L
                            FROM clients ORDER BY L")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

admin_header('Clients', 'clients', $pdo, $settings);
?>
<details class="panel glass panel-pliable" id="form" <?= $edit ? 'open' : '' ?>>
  <summary class="panel-titre"><?= $edit ? '✏️ Modifier : ' . e($edit['nom']) : '➕ Nouveau client' ?><span class="chev">▾</span></summary>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
    <div class="field"><label>Type de client</label>
      <select class="input" name="type_client" id="type_client">
        <option value="individuel" <?= (($edit['type_client'] ?? '') !== 'entreprise') ? 'selected' : '' ?>>👤 Particulier</option>
        <option value="entreprise" <?= (($edit['type_client'] ?? '') === 'entreprise') ? 'selected' : '' ?>>🏢 Entreprise</option>
      </select>
    </div>
    <div class="field"><label>Nom du contact *</label><input class="input" name="nom" required value="<?= e($edit['nom'] ?? '') ?>"></div>
    <div class="field"><label>Nom de l'entreprise <span style="color:var(--ink-faint);font-weight:400">(si entreprise)</span></label><input class="input" name="entreprise" id="entreprise_champ" value="<?= e($edit['entreprise'] ?? '') ?>" placeholder="Ex : Hôtel Ivoire"></div>
    <div class="field"><label>Téléphone</label><input class="input" name="telephone" value="<?= e($edit['telephone'] ?? '') ?>"></div>
    <div class="field"><label>E-mail</label><input class="input" type="email" name="email" value="<?= e($edit['email'] ?? '') ?>"></div>
    <div class="field"><label>Adresse</label><input class="input" name="adresse" value="<?= e($edit['adresse'] ?? '') ?>"></div>
    <div class="field"><label>N° Compte Contribuable (NCC)</label><input class="input" name="ncc" value="<?= e($edit['ncc'] ?? '') ?>"></div>
    <div class="field full"><label>Notes</label><textarea class="input" name="notes" style="min-height:70px"><?= e($edit['notes'] ?? '') ?></textarea></div>

    <div class="full"><h3 class="form-section">🔐 Accès à l'espace client <?= $compte ? '<span class="badge '.($compte['actif']?'badge-teal':'badge-danger').'">'.($compte['actif']?'Actif':'Désactivé').'</span>' : '' ?></h3></div>
    <div class="field"><label>Identifiant de connexion</label><input class="input" name="username" value="<?= e($compte['username'] ?? '') ?>" placeholder="ex : client-konan" pattern="[a-zA-Z0-9._@+-]*"></div>
    <div class="field"><label>Mot de passe <?= $compte ? '(laisser vide = inchangé)' : '' ?></label><input class="input" type="text" name="password" placeholder="6 caractères min." autocomplete="new-password"></div>
    <div class="field full" style="color:var(--ink-faint);font-size:12.5px;margin-top:-6px">Renseignez un identifiant et un mot de passe pour donner au client l'accès à son espace (ses factures, devis, reçus et la possibilité de laisser un avis). Laissez vide si inutile.</div>

    <div class="full" style="display:flex;gap:10px">
      <button class="btn btn-gold"><?= $edit ? 'Enregistrer' : 'Ajouter le client' ?></button>
      <?php if ($edit): ?><a class="btn btn-glass" href="clients.php">Annuler</a><?php endif; ?>
    </div>
  </form>
</details>

<div class="panel glass">
  <div class="cl-tete">
    <h2 style="margin:0">👥 Fichier clients <span class="cnt"><?= number_format($pg['total'], 0, ',', ' ') ?></span></h2>
    <form method="get" class="cl-recherche">
      <?php if ($fType): ?><input type="hidden" name="t" value="<?= e($fType) ?>"><?php endif; ?>
      <input class="input" name="q" value="<?= e($q) ?>" placeholder="Nom, société, téléphone, email…">
      <button class="btn btn-gold btn-sm">🔍</button>
      <?php if ($q !== '' || $fType || $fLettre): ?>
      <a class="btn btn-glass btn-sm" href="clients.php">Effacer</a>
      <?php endif; ?>
    </form>
  </div>

  <?php
  /* Trois façons de retrouver un client : par son nom, par sa nature, ou en
     sautant directement à sa lettre. Sur trois cents fiches, chercher à la
     main dans une liste alphabétique est une perte de temps. */
  $lienFiltre = function (array $chg) use ($q, $fType, $fLettre, $tri) {
      $p = array_filter(array_merge(
          ['q' => $q, 't' => $fType, 'l' => $fLettre, 'tri' => $tri === 'nom' ? '' : $tri], $chg));
      return 'clients.php' . ($p ? '?' . http_build_query($p) : '');
  };
  ?>

  <div class="cl-filtres">
    <div class="cl-groupe">
      <a class="clf <?= $fType === '' ? 'on' : '' ?>" href="<?= e($lienFiltre(['t' => ''])) ?>">Tous</a>
      <a class="clf <?= $fType === 'entreprise' ? 'on' : '' ?>" href="<?= e($lienFiltre(['t' => 'entreprise'])) ?>">🏢 Entreprises</a>
      <a class="clf <?= $fType === 'individuel' ? 'on' : '' ?>" href="<?= e($lienFiltre(['t' => 'individuel'])) ?>">👤 Particuliers</a>
    </div>
    <div class="cl-groupe">
      <span class="cl-lbl">Trier par</span>
      <a class="clf <?= $tri === 'nom' ? 'on' : '' ?>" href="<?= e($lienFiltre(['tri' => ''])) ?>">Nom</a>
      <a class="clf <?= $tri === 'ca' ? 'on' : '' ?>" href="<?= e($lienFiltre(['tri' => 'ca'])) ?>">Chiffre d'affaires</a>
      <a class="clf <?= $tri === 'recent' ? 'on' : '' ?>" href="<?= e($lienFiltre(['tri' => 'recent'])) ?>">Dernière prestation</a>
    </div>
  </div>

  <?php if ($lettres && $q === ''): ?>
  <div class="cl-alpha">
    <a class="cla <?= $fLettre === '' ? 'on' : '' ?>" href="<?= e($lienFiltre(['l' => ''])) ?>">Tout</a>
    <?php foreach ($lettres as $L): if (!preg_match('/^[A-Z]$/', (string)$L)) continue; ?>
    <a class="cla <?= $fLettre === $L ? 'on' : '' ?>" href="<?= e($lienFiltre(['l' => $L])) ?>"><?= e($L) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($clients): ?>
  <div class="cl-liste">
    <?php foreach ($clients as $c):
      $nom     = $c['affiche'];
      $estEnt  = trim((string)$c['entreprise']) !== '';
      $reste   = max(0, (float)$c['ca'] - (float)$c['regle']);
      $tel     = preg_replace('/\D/', '', (string)$c['telephone']);
    ?>
    <div class="cl">
      <div class="cl-av <?= $estEnt ? 'ent' : 'ind' ?>"><?= e(mb_strtoupper(mb_substr($nom, 0, 1))) ?></div>

      <div class="cl-id">
        <div class="cl-n"><?= e($nom) ?>
          <?php if ($c['uid']): ?>
          <span class="cl-acces <?= $c['compte_actif'] ? 'on' : 'off' ?>"
                title="<?= $c['compte_actif'] ? 'Accès actif' : 'Accès désactivé' ?>">🔑</span>
          <?php endif; ?>
        </div>
        <?php if ($estEnt && $c['nom'] !== $nom): ?>
        <div class="cl-s">👤 <?= e($c['nom']) ?></div>
        <?php endif; ?>
        <div class="cl-coord">
          <?php if ($c['telephone']): ?><a href="tel:<?= e($tel) ?>">📞 <?= e($c['telephone']) ?></a><?php endif; ?>
          <?php if ($c['email']): ?><a href="mailto:<?= e($c['email']) ?>" title="<?= e($c['email']) ?>">✉️</a><?php endif; ?>
          <?php if ($tel): ?><a href="https://wa.me/<?= e($tel) ?>" target="_blank" rel="noopener" title="WhatsApp">💬</a><?php endif; ?>
        </div>
      </div>

      <div class="cl-chiffres">
        <?php if ((float)$c['ca'] > 0): ?>
        <div class="clc"><span>Facturé</span><b><?= number_format((float)$c['ca'], 0, ',', ' ') ?></b></div>
        <?php if ($reste > 1): ?>
        <div class="clc"><span>Reste dû</span><b class="du"><?= number_format($reste, 0, ',', ' ') ?></b></div>
        <?php endif; ?>
        <div class="clc"><span>Documents</span><b><?= (int)$c['nb_docs'] ?></b></div>
        <?php else: ?>
        <div class="clc"><span class="cl-neuf">Aucune facture</span></div>
        <?php endif; ?>
        <?php if ($c['derniere']): ?>
        <div class="clc"><span>Dernière</span><b class="dt"><?= date('m/Y', strtotime($c['derniere'])) ?></b></div>
        <?php endif; ?>
      </div>

      <div class="cl-act">
        <a class="cl-b or" href="factures.php?edit=new&client=<?= (int)$c['id'] ?>" title="Créer une facture">🧾</a>
        <a class="cl-b" href="?edit=<?= (int)$c['id'] ?>#form" title="Modifier">✏️</a>
        <?php if ($c['uid']): ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <button class="cl-b" name="toggle_compte" value="<?= (int)$c['uid'] ?>"
                  title="<?= $c['compte_actif'] ? "Désactiver l'accès" : "Réactiver l'accès" ?>"><?= $c['compte_actif'] ? '⏸️' : '▶️' ?></button>
        </form>
        <?php endif; ?>
        <form method="post" style="display:inline"
              data-confirm="Supprimer « <?= e($nom) ?> » et son accès ? Ses documents ne seront pas effacés.">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <button class="cl-b sup" name="supprimer" value="<?= (int)$c['id'] ?>" title="Supprimer">✕</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <p style="text-align:center;padding:34px;color:var(--ink-faint);font-size:13px;margin:0">
    Aucun client<?= ($q !== '' || $fType || $fLettre) ? ' pour cette recherche' : '' ?>.
    <?= ($q === '' && !$fType && !$fLettre) ? 'Ajoutez votre premier client ci-dessus.' : '' ?>
  </p>
  <?php endif; ?>

<?= pagination_html($pg, 'client', ['q' => $q, 't' => $fType, 'l' => $fLettre, 'tri' => $tri === 'nom' ? '' : $tri]) ?>
</div>
</div>
<?php admin_footer(); ?>
