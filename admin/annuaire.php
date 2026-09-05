<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';

/* ============================================================================
   ANNUAIRE TÉLÉPHONIQUE
   ----------------------------------------------------------------------------
   Regroupe tous les interlocuteurs, classés en entreprises et particuliers.
   Un même client peut avoir plusieurs contacts : le gérant, la personne qui
   passe les commandes, celle qui règle les factures. Chaque fiche est
   modifiable sur place, sans quitter la page.
   ============================================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    /* ---- Enregistrement d'un contact ---- */
    if (isset($_POST['enregistrer'])) {
        $id   = (int)($_POST['id'] ?? 0);
        $nom  = trim($_POST['nom'] ?? '');
        $cid  = (int)($_POST['client_id'] ?? 0) ?: null;

        if ($nom === '') {
            flash('Le nom du contact est obligatoire.', 'error');
        } else {
            $champs = [
                mb_substr($nom, 0, 120),
                mb_substr(trim($_POST['poste'] ?? ''), 0, 100),
                mb_substr(trim($_POST['telephone'] ?? ''), 0, 40),
                mb_substr(trim($_POST['telephone2'] ?? ''), 0, 40),
                mb_substr(trim($_POST['whatsapp'] ?? ''), 0, 40),
                mb_substr(trim($_POST['email'] ?? ''), 0, 190),
                mb_substr(trim($_POST['adresse'] ?? ''), 0, 255),
                trim($_POST['notes'] ?? ''),
                $cid,
            ];

            if ($id) {
                $pdo->prepare('UPDATE contacts SET nom=?, poste=?, telephone=?, telephone2=?,
                               whatsapp=?, email=?, adresse=?, notes=?, client_id=? WHERE id=?')
                    ->execute([...$champs, $id]);
                flash('Contact mis à jour.');
            } else {
                $pdo->prepare('INSERT INTO contacts (nom, poste, telephone, telephone2,
                               whatsapp, email, adresse, notes, client_id) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute($champs);
                $id = (int)$pdo->lastInsertId();
                flash('Contact ajouté à l\'annuaire.');
            }

            /* Le contact principal met à jour la fiche du client : les deux
               restent ainsi cohérents, sans double saisie. */
            if ($cid && !empty($_POST['principal'])) {
                $pdo->prepare('UPDATE contacts SET principal=0 WHERE client_id=?')->execute([$cid]);
                $pdo->prepare('UPDATE contacts SET principal=1 WHERE id=?')->execute([$id]);
                $pdo->prepare('UPDATE clients SET telephone=?, email=?, adresse=? WHERE id=?')
                    ->execute([$champs[2], $champs[5], $champs[6], $cid]);
            }
            journaliser($pdo, $id ? 'modification' : 'creation', 'contact', $id, $nom);
        }
        header('Location: annuaire.php'); exit;
    }

    /* ---- Suppression ---- */
    if (isset($_POST['supprimer'])) {
        $id = (int)$_POST['supprimer'];
        $st = $pdo->prepare('SELECT nom, principal FROM contacts WHERE id=?');
        $st->execute([$id]);
        $ct = $st->fetch();
        if ($ct && (int)$ct['principal'] === 1) {
            flash("Ce contact est le contact principal du client : désignez-en un autre avant de le supprimer.", 'error');
        } else {
            $pdo->prepare('DELETE FROM contacts WHERE id=?')->execute([$id]);
            journaliser($pdo, 'suppression', 'contact', $id, $ct['nom'] ?? '');
            flash('Contact supprimé.');
        }
        header('Location: annuaire.php'); exit;
    }
}

/* ---- Recherche et chargement ---- */
$q = trim($_GET['q'] ?? '');
$sql = "SELECT ct.*, c.nom AS client_nom, c.entreprise, c.type_client
        FROM contacts ct LEFT JOIN clients c ON c.id = ct.client_id";
$args = [];
if ($q !== '') {
    $sql .= " WHERE ct.nom LIKE ? OR ct.poste LIKE ? OR ct.telephone LIKE ? OR ct.telephone2 LIKE ?
              OR ct.whatsapp LIKE ? OR ct.email LIKE ? OR c.nom LIKE ? OR c.entreprise LIKE ?";
    $args = array_fill(0, 8, '%' . $q . '%');
}
$sql .= " ORDER BY ct.principal DESC, ct.nom";
$st = $pdo->prepare($sql);
$st->execute($args);
$contacts = $st->fetchAll();

/* Regroupement : entreprises d'un côté, particuliers de l'autre */
$groupes = ['entreprise' => [], 'individuel' => [], 'libre' => []];
foreach ($contacts as $ct) {
    if (!$ct['client_id'])                              $groupes['libre'][] = $ct;
    elseif (($ct['type_client'] ?? '') === 'entreprise' || trim((string)$ct['entreprise']) !== '')
                                                        $groupes['entreprise'][] = $ct;
    else                                                $groupes['individuel'][] = $ct;
}

$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM contacts WHERE id=?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
}
$clients = $pdo->query("SELECT id, nom, entreprise, type_client FROM clients ORDER BY nom")->fetchAll();

$titres = ['entreprise' => ['🏢', 'Entreprises'], 'individuel' => ['👤', 'Particuliers'],
           'libre' => ['📇', 'Contacts libres']];

admin_header('Annuaire téléphonique', 'annuaire', $pdo, $settings);
?>

<div class="stats-row" style="margin-bottom:14px">
  <div class="stat-card"><div class="stat-val"><?= count($contacts) ?></div><div class="stat-lbl">Contacts</div></div>
  <div class="stat-card"><div class="stat-val"><?= count($groupes['entreprise']) ?></div><div class="stat-lbl">Entreprises</div></div>
  <div class="stat-card"><div class="stat-val"><?= count($groupes['individuel']) ?></div><div class="stat-lbl">Particuliers</div></div>
</div>

<div class="panel glass" style="margin-bottom:14px">
  <form method="get" style="display:flex;gap:9px;flex-wrap:wrap;align-items:center">
    <input class="input" name="q" value="<?= e($q) ?>" placeholder="Nom, société, fonction, numéro…" style="flex:1;min-width:220px">
    <button class="btn btn-gold">🔍 Rechercher</button>
    <?php if ($q !== ''): ?><a class="btn btn-glass" href="annuaire.php">Effacer</a><?php endif; ?>
    <a class="btn btn-glass" href="#form">➕ Nouveau contact</a>
  </form>
</div>

<?php foreach ($groupes as $cle => $liste): if (!$liste) continue; [$ico, $titre] = $titres[$cle]; ?>
<div class="panel glass" style="margin-bottom:14px">
  <h2><?= $ico ?> <?= e($titre) ?> <span class="cnt"><?= count($liste) ?></span></h2>
  <div class="annuaire">
    <?php foreach ($liste as $ct):
      $societe = trim((string)($ct['entreprise'] ?? '')) !== '' ? $ct['entreprise'] : ($ct['client_nom'] ?? '');
      $initiale = mb_strtoupper(mb_substr($ct['nom'], 0, 1)); ?>
    <div class="an-carte">
      <div class="an-tete">
        <div class="an-ava <?= $cle === 'entreprise' ? 'ent' : ($cle === 'libre' ? 'lib' : 'ind') ?>"><?= e($initiale) ?></div>
        <div class="an-id">
          <div class="an-nom"><?= e($ct['nom']) ?>
            <?php if ($ct['principal']): ?><span class="an-tag">Principal</span><?php endif; ?></div>
          <?php if ($ct['poste']): ?><div class="an-poste"><?= e($ct['poste']) ?></div><?php endif; ?>
          <?php if ($societe): ?><div class="an-soc"><?= e($societe) ?></div><?php endif; ?>
        </div>
      </div>

      <div class="an-lignes">
        <?php if ($ct['telephone']): ?>
        <a class="an-l" href="tel:<?= e(preg_replace('/\s+/', '', $ct['telephone'])) ?>"><span>📞</span><?= e($ct['telephone']) ?></a>
        <?php endif; ?>
        <?php if ($ct['telephone2']): ?>
        <a class="an-l" href="tel:<?= e(preg_replace('/\s+/', '', $ct['telephone2'])) ?>"><span>📞</span><?= e($ct['telephone2']) ?></a>
        <?php endif; ?>
        <?php if ($ct['whatsapp']): ?>
        <a class="an-l wa" href="https://wa.me/<?= e(preg_replace('/\D+/', '', $ct['whatsapp'])) ?>" target="_blank" rel="noopener"><span>💬</span><?= e($ct['whatsapp']) ?></a>
        <?php endif; ?>
        <?php if ($ct['email']): ?>
        <a class="an-l" href="mailto:<?= e($ct['email']) ?>"><span>✉️</span><?= e($ct['email']) ?></a>
        <?php endif; ?>
        <?php if ($ct['adresse']): ?>
        <div class="an-l"><span>📍</span><?= e($ct['adresse']) ?></div>
        <?php endif; ?>
        <?php if (!$ct['telephone'] && !$ct['telephone2'] && !$ct['email']): ?>
        <div class="an-vide">Aucune coordonnée — complétez la fiche</div>
        <?php endif; ?>
      </div>

      <div class="an-actions">
        <a class="btn btn-glass btn-sm" href="annuaire.php?edit=<?= (int)$ct['id'] ?>#form">✏️ Modifier</a>
        <?php if ($ct['client_id']): ?>
        <a class="btn btn-glass btn-sm" href="clients.php?edit=<?= (int)$ct['client_id'] ?>">👥 Client</a>
        <?php endif; ?>
        <?php if (is_admin()): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Supprimer <?= e($ct['nom']) ?> de l\'annuaire ?')">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <button class="btn btn-danger btn-sm" name="supprimer" value="<?= (int)$ct['id'] ?>">✕</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<?php if (!$contacts): ?>
<div class="panel glass" style="margin-bottom:14px">
  <p style="color:var(--ink-faint);margin:0">
    <?= $q !== '' ? 'Aucun contact ne correspond à cette recherche.' : "L'annuaire est vide. Ajoutez votre premier contact ci-dessous." ?></p>
</div>
<?php endif; ?>

<div class="panel glass" id="form">
  <h2><?= $edit ? '✏️ Modifier le contact' : '➕ Nouveau contact' ?></h2>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

    <div class="field"><label>Nom du contact *</label>
      <input class="input" name="nom" required maxlength="120" value="<?= e($edit['nom'] ?? '') ?>" placeholder="ex : Aminata Traoré"></div>

    <div class="field"><label>Fonction</label>
      <input class="input" name="poste" maxlength="100" value="<?= e($edit['poste'] ?? '') ?>" placeholder="ex : Directrice, Responsable achats"></div>

    <div class="field"><label>Client rattaché</label>
      <select class="input" name="client_id">
        <option value="">— Contact libre —</option>
        <?php foreach ($clients as $cl): $lb = trim((string)$cl['entreprise']) !== '' ? $cl['entreprise'] : $cl['nom']; ?>
        <option value="<?= (int)$cl['id'] ?>" <?= (int)($edit['client_id'] ?? 0) === (int)$cl['id'] ? 'selected' : '' ?>><?= e($lb) ?></option>
        <?php endforeach; ?>
      </select>
      <span style="display:block;margin-top:4px;font-size:12px;color:var(--ink-faint)">Un contact libre n'est rattaché à aucun client.</span>
    </div>

    <div class="field"><label>Téléphone</label>
      <input class="input" name="telephone" maxlength="40" value="<?= e($edit['telephone'] ?? '') ?>" placeholder="+225 07 00 00 00 00"></div>

    <div class="field"><label>Second numéro</label>
      <input class="input" name="telephone2" maxlength="40" value="<?= e($edit['telephone2'] ?? '') ?>"></div>

    <div class="field"><label>WhatsApp</label>
      <input class="input" name="whatsapp" maxlength="40" value="<?= e($edit['whatsapp'] ?? '') ?>"></div>

    <div class="field"><label>Email</label>
      <input class="input" type="email" name="email" maxlength="190" value="<?= e($edit['email'] ?? '') ?>"></div>

    <div class="field full"><label>Adresse</label>
      <input class="input" name="adresse" maxlength="255" value="<?= e($edit['adresse'] ?? '') ?>"></div>

    <div class="field full"><label>Notes</label>
      <textarea class="input" name="notes" rows="2" placeholder="Disponibilités, préférences, informations utiles…"><?= e($edit['notes'] ?? '') ?></textarea></div>

    <div class="field full">
      <label style="display:flex;align-items:center;gap:9px;cursor:pointer">
        <input type="checkbox" name="principal" value="1" <?= !empty($edit['principal']) ? 'checked' : '' ?>>
        <span>Contact principal du client — met à jour sa fiche avec ces coordonnées</span>
      </label>
    </div>

    <div class="full">
      <button class="btn btn-gold" name="enregistrer" value="1"><?= $edit ? '💾 Enregistrer' : '➕ Ajouter au répertoire' ?></button>
      <?php if ($edit): ?><a class="btn btn-glass" href="annuaire.php">Annuler</a><?php endif; ?>
    </div>
  </form>
</div>

<?php admin_footer(); ?>
