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

/* Regroupement. Une entreprise compte souvent plusieurs interlocuteurs : le
   gérant, la personne qui commande, celle qui règle. On rassemble donc tous
   les contacts d'une même société sous un seul bloc, plutôt que de les
   éparpiller dans une longue liste. */
$societes = [];      // entreprises, chacune avec ses interlocuteurs
$particuliers = [];  // clients individuels
$libres = [];        // fournisseurs et partenaires, sans client rattaché

foreach ($contacts as $ct) {
    if (!$ct['client_id']) { $libres[] = $ct; continue; }

    $estEntreprise = (($ct['type_client'] ?? '') === 'entreprise')
                  || trim((string)$ct['entreprise']) !== '';
    if (!$estEntreprise) { $particuliers[] = $ct; continue; }

    $cid = (int)$ct['client_id'];
    if (!isset($societes[$cid])) {
        $societes[$cid] = [
            'nom'       => trim((string)$ct['entreprise']) !== '' ? $ct['entreprise'] : $ct['client_nom'],
            'client_id' => $cid,
            'contacts'  => [],
        ];
    }
    $societes[$cid]['contacts'][] = $ct;
}
uasort($societes, fn($a, $b) => strcasecmp($a['nom'], $b['nom']));

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
  <div class="stat-card"><div class="stat-val"><?= count($societes) ?></div><div class="stat-lbl">Entreprises</div></div>
  <div class="stat-card"><div class="stat-val"><?= count($particuliers) ?></div><div class="stat-lbl">Particuliers</div></div>
</div>

<div class="panel glass" style="margin-bottom:14px">
  <form method="get" style="display:flex;gap:9px;flex-wrap:wrap;align-items:center">
    <input class="input" name="q" value="<?= e($q) ?>" placeholder="Nom, société, fonction, numéro…" style="flex:1;min-width:220px">
    <button class="btn btn-gold">🔍 Rechercher</button>
    <?php if ($q !== ''): ?><a class="btn btn-glass" href="annuaire.php">Effacer</a><?php endif; ?>
    <a class="btn btn-glass" href="annuaire.php?nouveau=0#form">➕ Nouveau contact</a>
  </form>
</div>

<?php
/* Une ligne de contact : compacte, tout tient sur une seule ligne pour qu'un
   annuaire de cent personnes reste consultable d'un coup d'œil. */
function ligne_contact(array $ct, bool $admin): void {
    $tel = trim((string)$ct['telephone']) !== '' ? $ct['telephone'] : ($ct['telephone2'] ?? '');
    ?>
    <div class="an-ligne">
      <div class="an-pt"><?= e(mb_strtoupper(mb_substr($ct['nom'], 0, 1))) ?></div>
      <div class="an-info">
        <div class="an-n"><?= e($ct['nom']) ?><?php if ($ct['principal']): ?><span class="an-tag">Principal</span><?php endif; ?></div>
        <?php if ($ct['poste']): ?><div class="an-p"><?= e($ct['poste']) ?></div><?php endif; ?>
      </div>
      <div class="an-coord">
        <?php if ($tel): ?><a class="an-c" href="tel:<?= e(preg_replace('/\s+/', '', $tel)) ?>">📞 <?= e($tel) ?></a><?php endif; ?>
        <?php if ($ct['whatsapp']): ?><a class="an-c wa" href="https://wa.me/<?= e(preg_replace('/\D+/', '', $ct['whatsapp'])) ?>" target="_blank" rel="noopener" title="WhatsApp">💬</a><?php endif; ?>
        <?php if ($ct['email']): ?><a class="an-c" href="mailto:<?= e($ct['email']) ?>" title="<?= e($ct['email']) ?>">✉️</a><?php endif; ?>
        <?php if (!$tel && !$ct['email']): ?><span class="an-manque">à compléter</span><?php endif; ?>
      </div>
      <div class="an-act">
        <a class="an-b" href="annuaire.php?edit=<?= (int)$ct['id'] ?>#form" title="Modifier">✏️</a>
        <?php if ($admin): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Supprimer <?= e($ct['nom']) ?> ?')">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <button class="an-b sup" name="supprimer" value="<?= (int)$ct['id'] ?>" title="Supprimer">✕</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php
}
?>

<?php if ($societes): ?>
<div class="panel glass" style="margin-bottom:14px">
  <h2>🏢 Entreprises <span class="cnt"><?= count($societes) ?></span></h2>
  <?php foreach ($societes as $s): ?>
  <div class="an-soc-bloc">
    <div class="an-soc-tete">
      <span class="an-soc-nom">🏢 <?= e($s['nom']) ?></span>
      <span class="an-soc-nb"><?= count($s['contacts']) ?> contact<?= count($s['contacts']) > 1 ? 's' : '' ?></span>
      <a class="an-soc-add" href="annuaire.php?nouveau=<?= $s['client_id'] ?>#form">➕ Ajouter</a>
    </div>
    <?php foreach ($s['contacts'] as $ct) ligne_contact($ct, is_admin()); ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($particuliers): ?>
<div class="panel glass" style="margin-bottom:14px">
  <h2>👤 Particuliers <span class="cnt"><?= count($particuliers) ?></span></h2>
  <?php foreach ($particuliers as $ct) ligne_contact($ct, is_admin()); ?>
</div>
<?php endif; ?>

<?php if ($libres): ?>
<div class="panel glass" style="margin-bottom:14px">
  <h2>📇 Fournisseurs &amp; partenaires <span class="cnt"><?= count($libres) ?></span></h2>
  <?php foreach ($libres as $ct) ligne_contact($ct, is_admin()); ?>
</div>
<?php endif; ?>

<?php if (!$contacts): ?>
<div class="panel glass" style="margin-bottom:14px">
  <p style="color:var(--ink-faint);margin:0">
    <?= $q !== '' ? 'Aucun contact ne correspond à cette recherche.' : "L'annuaire est vide. Ajoutez votre premier contact ci-dessous." ?></p>
</div>
<?php endif; ?>

<?php
/* Le formulaire reste replié : il ne s'ouvre qu'à la demande, pour laisser
   toute la place à l'annuaire lui-même. Il s'ouvre automatiquement quand on
   modifie un contact ou qu'on en ajoute un à une société. */
$ouvert = (bool)$edit || isset($_GET['nouveau']);
$clientPre = (int)($_GET['nouveau'] ?? 0);
?>
<details class="panel glass panel-pliable" id="form" <?= $ouvert ? 'open' : '' ?>>
  <summary class="panel-titre">
    <span><?= $edit ? '✏️ Modifier le contact' : '➕ Ajouter un contact' ?></span>
    <span class="chev">▾</span>
  </summary>
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
        <option value="<?= (int)$cl['id'] ?>" <?= (int)($edit['client_id'] ?? $clientPre) === (int)$cl['id'] ? 'selected' : '' ?>><?= e($lb) ?></option>
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
</details>

<?php admin_footer(); ?>
