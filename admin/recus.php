<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';

/* Deux natures de document : bon de sortie (par défaut) et bon d'entrée */
$TYPE   = ($_GET['type'] ?? ($_POST['type'] ?? '')) === 'entree' ? 'entree' : 'sortie';
$LIB    = $TYPE === 'entree' ? 'Entrée' : 'Sortie';
$LIBS   = $TYPE === 'entree' ? 'Entrées' : 'Sorties';
$ICONE  = $TYPE === 'entree' ? '📥' : '📤';
$PREF   = $TYPE === 'entree' ? 'BE' : 'BS';
$RETOUR = 'recus.php' . ($TYPE === 'entree' ? '?type=entree' : '');
$devise = $settings['devise'] ?? 'FCFA';
$modes = modes_paiement();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['supprimer'])) {
        $pdo->prepare('DELETE FROM recus WHERE id=?')->execute([(int)$_POST['supprimer']]);
        flash($LIB . ' supprimé.');
        header('Location: ' . $RETOUR); exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    $client_id = ($_POST['client_id'] ?? '') ?: null;
    $facture_id = ($_POST['facture_id'] ?? '') ?: null;
    $montant = max(0, (float)($_POST['montant'] ?? 0));
    $mode = mb_substr(trim($_POST['mode_paiement'] ?? 'Espèces'), 0, 40);
    $motif = mb_substr(trim($_POST['motif'] ?? ''), 0, 255);
    $date = ($_POST['date_paiement'] ?? '') ?: date('Y-m-d');
    $notes = mb_substr(trim($_POST['notes'] ?? ''), 0, 500);
    $activite = mb_substr(trim($_POST['activite'] ?? ''), 0, 255);
    $date_evt = ($_POST['date_evenement'] ?? '') ?: null;
    $lieu     = mb_substr(trim($_POST['lieu'] ?? ''), 0, 255);

    if ($montant <= 0) { flash('Le montant doit être supérieur à 0.', 'error'); header('Location: ' . $RETOUR); exit; }

    if ($id) {
        $pdo->prepare('UPDATE recus SET client_id=?, facture_id=?, montant=?, mode_paiement=?, motif=?, date_paiement=?, notes=?, activite=?, date_evenement=?, lieu=? WHERE id=?')
            ->execute([$client_id, $facture_id, $montant, $mode, $motif, $date, $notes, $activite, $date_evt, $lieu, $id]);
        flash($LIB . ' modifié.');
    } else {
        $numero = next_numero($pdo, 'recus', $PREF);
        $pdo->prepare('INSERT INTO recus (numero, type, client_id, facture_id, montant, mode_paiement, motif, date_paiement, notes, activite, date_evenement, lieu, vu_client) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0)')
            ->execute([$numero, $TYPE, $client_id, $facture_id, $montant, $mode, $motif, $date, $notes, $activite, $date_evt, $lieu]);
        flash($LIB . ' ' . $numero . ' créé.');
    }
    header('Location: ' . $RETOUR); exit;
}

$edit = null;
if (isset($_GET['edit'])) { $stmt = $pdo->prepare('SELECT * FROM recus WHERE id=?'); $stmt->execute([(int)$_GET['edit']]); $edit = $stmt->fetch(); }
$clients = $pdo->query('SELECT id, nom FROM clients ORDER BY nom')->fetchAll();
$facts = $pdo->query("SELECT id, numero FROM factures WHERE type='facture' ORDER BY id DESC LIMIT 100")->fetchAll();
$st = $pdo->prepare("SELECT r.*, c.nom AS client, f.numero AS facture FROM recus r LEFT JOIN clients c ON c.id=r.client_id LEFT JOIN factures f ON f.id=r.facture_id WHERE r.type=? ORDER BY r.date_paiement DESC, r.id DESC");
$st->execute([$TYPE]); $recus = $st->fetchAll();

/* Rangement : filtres + arborescence (Année → Mois → Client / Fournisseur) */
require_once __DIR__ . '/includes/rangement.php';
$vueRng = ($_GET['vue'] ?? 'arbre') === 'liste' ? 'liste' : 'arbre';
$fRng = ['client' => (int)($_GET['fc'] ?? 0), 'mois' => (int)($_GET['fm'] ?? 0), 'annee' => (int)($_GET['fa'] ?? 0)];
$recusAff = rangement_filtrer($recus, $fRng, 'date_paiement', 'client_id');
$anneesRng = rangement_annees($recus, 'date_paiement');
$ts = $pdo->prepare("SELECT COALESCE(SUM(montant),0) FROM recus WHERE type=? AND DATE_FORMAT(date_paiement,'%Y-%m')=?");
$ts->execute([$TYPE, date('Y-m')]); $total = $ts->fetchColumn();

admin_header($LIBS, $TYPE === 'entree' ? 'bons_entree' : 'bons_sortie', $pdo, $settings);
?>
<div class="stats">
  <div class="stat glass teal"><div class="s-ico"><?= $ICONE ?></div><div class="s-num"><?= count($recus) ?></div><div class="s-label"><?= e($LIBS) ?> émises</div></div>
  <div class="stat glass gold"><div class="s-ico">💵</div><div class="s-num" style="font-size:19px"><?= money($total, $devise) ?></div><div class="s-label">Encaissé ce mois</div></div>
</div>

<details class="panel glass panel-pliable" id="form" <?= $edit ? 'open' : '' ?>>
  <summary class="panel-titre"><?= $edit ? '✏️ Modifier ' . e($LIB) . ' ' . e($edit['numero']) : $ICONE . ' Nouvelle ' . e(mb_strtolower($LIB)) ?><span class="chev">▾</span></summary>
  <?php if ($edit): ?><p style="margin:0 0 10px"><a href="recus.php" class="btn btn-glass btn-sm">Annuler la modification</a></p><?php endif; ?>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="type" value="<?= e($TYPE) ?>">
    <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
    <div class="field"><label>Client</label>
      <select class="input" name="client_id"><option value="">— Client de passage —</option>
        <?php foreach ($clients as $c): ?><option value="<?= $c['id'] ?>" <?= ($edit['client_id'] ?? 0)==$c['id']?'selected':'' ?>><?= e($c['nom']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Montant (<?= e($devise) ?>) *</label><input class="input" type="number" name="montant" min="0" step="100" required value="<?= e($edit['montant'] ?? '') ?>"></div>
    <div class="field"><label>Mode de paiement</label>
      <select class="input" name="mode_paiement"><?php foreach ($modes as $m): ?><option <?= ($edit['mode_paiement'] ?? '')===$m?'selected':'' ?>><?= $m ?></option><?php endforeach; ?></select>
    </div>
    <div class="field"><label>Date du paiement</label><input class="input" type="date" name="date_paiement" value="<?= e($edit['date_paiement'] ?? date('Y-m-d')) ?>"></div>
    <div class="field full"><label>Activité / Description de la prestation</label><input class="input" name="activite" placeholder="ex : Buffet mariage, Cocktail…" value="<?= e($edit['activite'] ?? '') ?>"></div>
    <div class="field"><label>Date de l'événement</label><input class="input" type="date" name="date_evenement" value="<?= e($edit['date_evenement'] ?? '') ?>"></div>
    <div class="field"><label>Lieu de l'événement</label><input class="input" name="lieu" placeholder="ex : Cocody, Salle des fêtes…" value="<?= e($edit['lieu'] ?? '') ?>"></div>
    <div class="field"><label>Facture liée (facultatif)</label>
      <select class="input" name="facture_id"><option value="">—</option>
        <?php foreach ($facts as $fa): ?><option value="<?= $fa['id'] ?>" <?= ($edit['facture_id'] ?? 0)==$fa['id']?'selected':'' ?>><?= e($fa['numero']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Motif</label><input class="input" name="motif" value="<?= e($edit['motif'] ?? '') ?>" placeholder="ex : Acompte prestation mariage"></div>
    <div class="field full"><label>Notes</label><input class="input" name="notes" value="<?= e($edit['notes'] ?? '') ?>"></div>
    <div class="full" style="display:flex;gap:10px">
      <button class="btn btn-gold"><?= $edit ? 'Enregistrer' : 'Créer cette ' . e(mb_strtolower($LIB)) ?></button>
    </div>
  </form>
</details>

<div class="panel glass">
  <h2><?= $ICONE ?> <?= e($LIBS) ?> (<?= count($recus) ?>)</h2>
  <?php
  /* Filtres rapides */
  $clients = $pdo->query('SELECT id, nom FROM clients ORDER BY nom')->fetchAll();
  $baseUrl = 'recus.php?type=' . $TYPE;
  $moisFr = fn($m) => rangement_mois_fr((int)$m);
  $libClient = $TYPE === 'entree' ? 'Fournisseur / Émetteur' : 'Client';
  ?>
  <form method="get" class="rng-filtres">
    <input type="hidden" name="type" value="<?= e($TYPE) ?>">
    <input type="hidden" name="vue" value="<?= e($vueRng) ?>">
    <div class="f"><label><?= $libClient ?></label>
      <select class="input" name="fc" onchange="this.form.submit()">
        <option value="0">Tous</option>
        <?php foreach ($clients as $c): ?><option value="<?= $c['id'] ?>" <?= $fRng['client']==$c['id']?'selected':'' ?>><?= e($c['nom']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="f"><label>Mois</label>
      <select class="input" name="fm" onchange="this.form.submit()">
        <option value="0">Tous</option>
        <?php for($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $fRng['mois']==$m?'selected':'' ?>><?= $moisFr($m) ?></option><?php endfor; ?>
      </select>
    </div>
    <div class="f"><label>Année</label>
      <select class="input" name="fa" onchange="this.form.submit()">
        <option value="0">Toutes</option>
        <?php foreach ($anneesRng as $a): ?><option value="<?= $a ?>" <?= $fRng['annee']==$a?'selected':'' ?>><?= $a ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="rng-vue">
      <a href="<?= $baseUrl ?>&vue=arbre<?= $fRng['client']?'&fc='.$fRng['client']:'' ?><?= $fRng['mois']?'&fm='.$fRng['mois']:'' ?><?= $fRng['annee']?'&fa='.$fRng['annee']:'' ?>" class="<?= $vueRng==='arbre'?'on':'' ?>">🗂️ Arbre</a>
      <a href="<?= $baseUrl ?>&vue=liste<?= $fRng['client']?'&fc='.$fRng['client']:'' ?><?= $fRng['mois']?'&fm='.$fRng['mois']:'' ?><?= $fRng['annee']?'&fa='.$fRng['annee']:'' ?>" class="<?= $vueRng==='liste'?'on':'' ?>">📋 Liste</a>
    </div>
  </form>

  <?php
  /* Rendu d'un bon (ligne compacte réutilisée) */
  $renderRecu = function($r) use ($devise, $LIB, $TYPE) { ob_start(); ?>
    <div class="rng-doc">
      <span class="num"><?= e($r['numero']) ?></span>
      <span class="dt"><?= date('d/m/Y', strtotime($r['date_paiement'])) ?><?= $r['mode_paiement']?' · '.e($r['mode_paiement']):'' ?></span>
      <span class="mt" style="color:var(--teal)"><?= money($r['montant'], $devise) ?></span>
      <span class="acts">
        <a class="btn btn-glass btn-sm" href="print.php?type=recu&id=<?= $r['id'] ?>" target="_blank" title="Voir">📄</a>
        <?php if(is_admin()): ?><a class="btn btn-glass btn-sm" href="print.php?type=recu&id=<?= $r['id'] ?>&auth=1" target="_blank" title="Authentifiable">🔐</a><?php endif; ?>
        <a class="btn btn-glass btn-sm" href="?type=<?= e($TYPE) ?>&edit=<?= $r['id'] ?>#form" title="Modifier">✏️</a>
        <form method="post" data-confirm="Supprimer <?= e($r['numero']) ?> ?" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="type" value="<?= e($TYPE) ?>"><button class="btn btn-danger btn-sm" name="supprimer" value="<?= $r['id'] ?>">✕</button></form>
      </span>
    </div>
  <?php return ob_get_clean(); };
  ?>

  <?php if (!$recusAff): ?>
    <div style="text-align:center;padding:36px;color:var(--ink-faint)">Aucun document ne correspond.</div>
  <?php elseif ($vueRng === 'liste'): ?>
    <div class="rng-docs" style="margin-left:0"><?php foreach ($recusAff as $r) echo $renderRecu($r); ?></div>
  <?php else: ?>
    <?php $arbre = rangement_arbre($recusAff, 'date_paiement', 'client'); ?>
    <div class="rng-tree">
      <?php foreach ($arbre as $annee => $mois): $nbA=0; foreach($mois as $cl) foreach($cl as $ds) $nbA+=count($ds); ?>
      <details class="rng-annee" open>
        <summary><?= $annee ?><span class="cnt"><?= $nbA ?> doc<?= $nbA>1?'s':'' ?></span></summary>
        <?php foreach ($mois as $m => $clients2): $nbM=0; foreach($clients2 as $ds) $nbM+=count($ds); ?>
        <details class="rng-mois" open>
          <summary><?= $moisFr($m) ?><span class="cnt"><?= $nbM ?></span></summary>
          <?php foreach ($clients2 as $client => $docs): ?>
          <details class="rng-client" open>
            <summary>👤 <?= e($client) ?><span class="cnt"><?= count($docs) ?></span></summary>
            <div class="rng-docs"><?php foreach ($docs as $r) echo $renderRecu($r); ?></div>
          </details>
          <?php endforeach; ?>
        </details>
        <?php endforeach; ?>
      </details>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/envoyer_modal.php'; ?>
<?php admin_footer(); ?>
