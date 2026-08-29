<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';

$me = (int)$_SESSION['admin_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Ajouter / modifier un membre externe
    if (isset($_POST['enregistrer'])) {
        $id           = (int)($_POST['id'] ?? 0);
        $nom          = trim($_POST['nom'] ?? '');
        $organisation = trim($_POST['organisation'] ?? '');
        $fonction     = trim($_POST['fonction'] ?? '');
        $telephone    = trim($_POST['telephone'] ?? '');
        $email        = trim($_POST['email'] ?? '');
        $adresse      = trim($_POST['adresse'] ?? '');
        $piece        = trim($_POST['piece_identite'] ?? '');
        $notes        = trim($_POST['notes'] ?? '');
        $statut       = ($_POST['statut'] ?? 'actif') === 'inactif' ? 'inactif' : 'actif';

        if ($nom === '') { flash('Le nom est obligatoire.', 'error'); header('Location: externes.php'); exit; }

        if ($id > 0) {
            $pdo->prepare("UPDATE externes SET nom=?, organisation=?, fonction=?, telephone=?, email=?, adresse=?, piece_identite=?, notes=?, statut=? WHERE id=?")
                ->execute([$nom, $organisation, $fonction, $telephone, $email, $adresse, $piece, $notes, $statut, $id]);
            flash('Membre externe mis à jour.');
        } else {
            $reference = externe_generer_reference($pdo, $settings);
            $pdo->prepare("INSERT INTO externes (reference, nom, organisation, fonction, telephone, email, adresse, piece_identite, notes, statut, cree_par) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$reference, $nom, $organisation, $fonction, $telephone, $email, $adresse, $piece, $notes, $statut, $me]);
            flash('Membre externe enregistré sous la référence ' . $reference . '.');
        }
        header('Location: externes.php'); exit;
    }

    // Ajouter une intervention à l'historique
    if (isset($_POST['ajouter_intervention'])) {
        $eid  = (int)$_POST['externe_id'];
        $date = $_POST['date_intervention'] ?: date('Y-m-d');
        $objet = trim($_POST['objet'] ?? '');
        $lieu  = trim($_POST['lieu'] ?? '');
        $notes = trim($_POST['int_notes'] ?? '');
        if ($eid > 0) {
            $pdo->prepare("INSERT INTO externes_interventions (externe_id, date_intervention, objet, lieu, notes, cree_par) VALUES (?,?,?,?,?,?)")
                ->execute([$eid, $date, $objet, $lieu, $notes, $me]);
            // Mettre à jour le compteur + la dernière date
            $pdo->prepare("UPDATE externes SET nb_interventions = nb_interventions + 1, derniere_intervention = GREATEST(COALESCE(derniere_intervention,'1900-01-01'), ?) WHERE id=?")
                ->execute([$date, $eid]);
            flash('Intervention ajoutée à l\'historique.');
        }
        header('Location: externes.php?voir=' . $eid); exit;
    }

    // Supprimer définitivement un membre externe (et son historique).
    // Pour conserver les traces sans supprimer, l'admin peut plutôt le passer en "inactif".
    if (isset($_POST['supprimer'])) {
        $id = (int)$_POST['supprimer'];
        // Délier le badge éventuel pour ne pas laisser de référence orpheline
        $pdo->prepare("UPDATE badges SET externe_id=NULL WHERE externe_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM externes_interventions WHERE externe_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM externes WHERE id=?")->execute([$id]);
        flash('Membre externe supprimé du registre. (Astuce : passez plutôt un externe en « inactif » pour conserver son historique.)');
        header('Location: externes.php'); exit;
    }
}

// Vue détaillée d'un externe ?
$voir = (int)($_GET['voir'] ?? 0);
$detail = null; $interventions = [];
if ($voir) {
    $st = $pdo->prepare("SELECT * FROM externes WHERE id=?");
    $st->execute([$voir]);
    $detail = $st->fetch();
    if ($detail) {
        $st = $pdo->prepare("SELECT * FROM externes_interventions WHERE externe_id=? ORDER BY date_intervention DESC, id DESC");
        $st->execute([$voir]);
        $interventions = $st->fetchAll();
    }
}

// Édition d'un externe ?
$edit = null;
if (($eid = (int)($_GET['edit'] ?? 0))) {
    $st = $pdo->prepare("SELECT * FROM externes WHERE id=?");
    $st->execute([$eid]);
    $edit = $st->fetch();
}

// Filtre + liste
$filtre = $_GET['statut'] ?? 'tous';
$sql = "SELECT * FROM externes";
$params = [];
if ($filtre === 'actif' || $filtre === 'inactif') { $sql .= " WHERE statut=?"; $params[] = $filtre; }
$sql .= " ORDER BY created_at DESC";
$st = $pdo->prepare($sql); $st->execute($params);
$liste = $st->fetchAll();

// Stats
$stats = [
    'total'  => (int)$pdo->query("SELECT COUNT(*) FROM externes")->fetchColumn(),
    'actifs' => (int)$pdo->query("SELECT COUNT(*) FROM externes WHERE statut='actif'")->fetchColumn(),
    'interventions' => (int)$pdo->query("SELECT COUNT(*) FROM externes_interventions")->fetchColumn(),
];

admin_header('Membres externes', 'externes', $pdo, $settings);
?>

<?php if ($detail): // ===== VUE DÉTAILLÉE D'UN EXTERNE ===== ?>
<div class="panel glass" style="margin-bottom:16px">
  <a href="externes.php" class="btn btn-glass btn-sm" style="margin-bottom:14px">← Retour au registre</a>
  <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:14px;align-items:start">
    <div>
      <h2 style="margin-bottom:6px"><?= e($detail['nom']) ?>
        <span class="badge <?= $detail['statut']==='actif'?'badge-green':'badge-gray' ?>"><?= $detail['statut']==='actif'?'Actif':'Inactif' ?></span>
      </h2>
      <div class="ext-ref"><?= e($detail['reference']) ?></div>
      <div style="color:var(--ink-dim);font-size:14px;line-height:1.9;margin-top:10px">
        <?php if ($detail['organisation']): ?>🏢 <strong><?= e($detail['organisation']) ?></strong><br><?php endif; ?>
        <?php if ($detail['fonction']): ?>💼 <?= e($detail['fonction']) ?><br><?php endif; ?>
        <?php if ($detail['telephone']): ?>📞 <a href="tel:<?= e($detail['telephone']) ?>" style="color:var(--gold);text-decoration:none"><?= e($detail['telephone']) ?></a><br><?php endif; ?>
        <?php if ($detail['email']): ?>✉️ <?= e($detail['email']) ?><br><?php endif; ?>
        <?php if ($detail['adresse']): ?>📍 <?= e($detail['adresse']) ?><br><?php endif; ?>
        <?php if ($detail['piece_identite']): ?>🪪 <?= e($detail['piece_identite']) ?><br><?php endif; ?>
      </div>
      <?php if ($detail['notes']): ?><p style="margin-top:12px;padding:12px;border-radius:12px;background:var(--glass-border-soft);color:var(--ink-dim);font-size:13.5px"><?= nl2br(e($detail['notes'])) ?></p><?php endif; ?>
    </div>
    <div style="text-align:right;min-width:150px">
      <div class="ext-compteur"><?= (int)$detail['nb_interventions'] ?></div>
      <div style="color:var(--ink-faint);font-size:12px">intervention(s)</div>
      <?php if ($detail['derniere_intervention']): ?>
      <div style="color:var(--ink-dim);font-size:12px;margin-top:8px">Dernière : <?= date('d/m/Y', strtotime($detail['derniere_intervention'])) ?></div>
      <?php endif; ?>
      <a href="externes.php?edit=<?= $detail['id'] ?>" class="btn btn-glass btn-sm" style="margin-top:12px">✏️ Modifier la fiche</a>
    </div>
  </div>
</div>

<div class="panel glass" style="margin-bottom:16px">
  <h3 style="margin-bottom:12px">➕ Ajouter une intervention</h3>
  <form method="post" class="ext-int-form">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="externe_id" value="<?= $detail['id'] ?>">
    <div class="ext-grid">
      <div class="field"><label>Date *</label><input class="input" type="date" name="date_intervention" value="<?= date('Y-m-d') ?>" required></div>
      <div class="field"><label>Objet / événement</label><input class="input" name="objet" placeholder="Ex : Mariage Kouassi"></div>
      <div class="field"><label>Lieu</label><input class="input" name="lieu" placeholder="Ex : Cocody"></div>
    </div>
    <div class="field"><label>Notes</label><input class="input" name="int_notes" placeholder="Détails, prestation, remarques…"></div>
    <button class="btn btn-gold" name="ajouter_intervention" value="1" style="margin-top:10px">Enregistrer l'intervention</button>
  </form>
</div>

<div class="panel glass">
  <h3 style="margin-bottom:12px">🗓️ Historique des interventions</h3>
  <?php if (!$interventions): ?>
    <p style="color:var(--ink-faint)">Aucune intervention enregistrée pour l'instant.</p>
  <?php else: ?>
    <div class="ext-histo">
      <?php foreach ($interventions as $it): ?>
      <div class="ext-histo-item">
        <div class="ext-histo-date"><?= date('d/m/Y', strtotime($it['date_intervention'])) ?></div>
        <div class="ext-histo-body">
          <?php if ($it['objet']): ?><strong><?= e($it['objet']) ?></strong><?php endif; ?>
          <?php if ($it['lieu']): ?> · <span style="color:var(--ink-dim)"><?= e($it['lieu']) ?></span><?php endif; ?>
          <?php if ($it['notes']): ?><div style="color:var(--ink-dim);font-size:13px;margin-top:3px"><?= e($it['notes']) ?></div><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php else: // ===== REGISTRE (LISTE) ===== ?>

<div class="stats-row" style="margin-bottom:16px">
  <div class="stat-card glass"><div class="stat-val"><?= $stats['total'] ?></div><div class="stat-lbl">Membres externes</div></div>
  <div class="stat-card glass"><div class="stat-val"><?= $stats['actifs'] ?></div><div class="stat-lbl">Actifs</div></div>
  <div class="stat-card glass"><div class="stat-val"><?= $stats['interventions'] ?></div><div class="stat-lbl">Interventions totales</div></div>
</div>

<div class="panel glass" style="margin-bottom:16px">
  <h3 style="margin-bottom:12px"><?= $edit ? '✏️ Modifier le membre externe' : '➕ Nouveau membre externe' ?></h3>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= $edit['id'] ?>"><?php endif; ?>
    <div class="ext-grid">
      <div class="field"><label>Nom complet *</label><input class="input" name="nom" value="<?= e($edit['nom'] ?? '') ?>" required></div>
      <div class="field"><label>Organisation / société</label><input class="input" name="organisation" value="<?= e($edit['organisation'] ?? '') ?>" placeholder="Ex : Traiteur Partenaire SARL"></div>
      <div class="field"><label>Fonction / rôle</label><input class="input" name="fonction" value="<?= e($edit['fonction'] ?? '') ?>" placeholder="Ex : Serveur extra"></div>
      <div class="field"><label>Téléphone</label><input class="input" name="telephone" value="<?= e($edit['telephone'] ?? '') ?>"></div>
      <div class="field"><label>Email</label><input class="input" type="email" name="email" value="<?= e($edit['email'] ?? '') ?>"></div>
      <div class="field"><label>Pièce d'identité</label><input class="input" name="piece_identite" value="<?= e($edit['piece_identite'] ?? '') ?>" placeholder="Ex : CNI n° C012345678"></div>
      <div class="field"><label>Adresse</label><input class="input" name="adresse" value="<?= e($edit['adresse'] ?? '') ?>"></div>
      <div class="field"><label>Statut</label><select class="input" name="statut">
        <option value="actif" <?= ($edit['statut'] ?? 'actif')==='actif'?'selected':'' ?>>Actif</option>
        <option value="inactif" <?= ($edit['statut'] ?? '')==='inactif'?'selected':'' ?>>Inactif</option>
      </select></div>
    </div>
    <div class="field"><label>Notes</label><textarea class="input" name="notes" style="min-height:70px" placeholder="Informations utiles, compétences, disponibilités…"><?= e($edit['notes'] ?? '') ?></textarea></div>
    <div style="display:flex;gap:10px;margin-top:12px">
      <button class="btn btn-gold" name="enregistrer" value="1"><?= $edit ? 'Enregistrer les modifications' : 'Ajouter au registre' ?></button>
      <?php if ($edit): ?><a href="externes.php" class="btn btn-glass">Annuler</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="panel glass">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px">
    <h3 style="margin:0">📋 Registre des membres externes</h3>
    <div class="ext-filtres">
      <a href="externes.php" class="btn btn-sm <?= $filtre==='tous'?'btn-gold':'btn-glass' ?>">Tous</a>
      <a href="externes.php?statut=actif" class="btn btn-sm <?= $filtre==='actif'?'btn-gold':'btn-glass' ?>">Actifs</a>
      <a href="externes.php?statut=inactif" class="btn btn-sm <?= $filtre==='inactif'?'btn-gold':'btn-glass' ?>">Inactifs</a>
    </div>
  </div>

  <?php if (!$liste): ?>
    <p style="color:var(--ink-faint)">Aucun membre externe enregistré. Ajoutez-en un ci-dessus.</p>
  <?php else: ?>
    <div class="ext-liste">
      <?php foreach ($liste as $x): ?>
      <div class="ext-card">
        <div class="ext-card-main">
          <div class="ext-card-ref"><?= e($x['reference']) ?></div>
          <div class="ext-card-nom"><?= e($x['nom']) ?>
            <span class="badge <?= $x['statut']==='actif'?'badge-green':'badge-gray' ?>" style="font-size:9px"><?= $x['statut']==='actif'?'Actif':'Inactif' ?></span>
          </div>
          <div class="ext-card-sub">
            <?php if ($x['organisation']): ?>🏢 <?= e($x['organisation']) ?><?php endif; ?>
            <?php if ($x['fonction']): ?> · <?= e($x['fonction']) ?><?php endif; ?>
          </div>
          <div class="ext-card-meta">
            <?= (int)$x['nb_interventions'] ?> intervention(s)
            <?php if ($x['derniere_intervention']): ?> · dernière le <?= date('d/m/Y', strtotime($x['derniere_intervention'])) ?><?php endif; ?>
          </div>
        </div>
        <div class="ext-card-actions">
          <a href="externes.php?voir=<?= $x['id'] ?>" class="btn btn-gold btn-sm">Voir / Historique</a>
          <a href="externes.php?edit=<?= $x['id'] ?>" class="btn btn-glass btn-sm">Modifier</a>
          <form method="post" data-confirm="Supprimer ce membre externe et tout son historique ? Cette action est irréversible.">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <button class="btn btn-danger btn-sm" name="supprimer" value="<?= $x['id'] ?>">Supprimer</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php endif; ?>

<?php admin_footer(); ?>
