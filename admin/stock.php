<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
$devise = $settings['devise'] ?? 'FCFA';
$admin = is_admin();

$categories = ['Ingrédient','Boisson','Matériel','Vaisselle','Consommable','Décoration','Autre'];
$unites = ['unité','kg','g','L','cL','carton','sac','bouteille','paquet','lot'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Ajouter / modifier un article
    if (isset($_POST['enregistrer'])) {
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            mb_substr(trim($_POST['nom'] ?? ''), 0, 150),
            mb_substr(trim($_POST['categorie'] ?? 'Ingrédient'), 0, 80),
            mb_substr(trim($_POST['unite'] ?? 'unité'), 0, 30),
            (float)($_POST['quantite'] ?? 0),
            (float)($_POST['seuil_alerte'] ?? 0),
            (float)($_POST['prix_unitaire'] ?? 0),
            mb_substr(trim($_POST['fournisseur'] ?? ''), 0, 150),
            mb_substr(trim($_POST['notes'] ?? ''), 0, 1000),
        ];
        if ($_POST['nom'] === '') { flash('Le nom est obligatoire.', 'error'); }
        elseif ($id) {
            $pdo->prepare('UPDATE stock_articles SET nom=?, categorie=?, unite=?, quantite=?, seuil_alerte=?, prix_unitaire=?, fournisseur=?, notes=? WHERE id=?')->execute([...$data, $id]);
            flash('Article mis à jour.');
        } else {
            $pdo->prepare('INSERT INTO stock_articles (nom, categorie, unite, quantite, seuil_alerte, prix_unitaire, fournisseur, notes) VALUES (?,?,?,?,?,?,?,?)')->execute($data);
            flash('Article ajouté au stock.');
        }
        header('Location: stock.php'); exit;
    }

    // Mouvement d'entrée / sortie
    if (isset($_POST['mouvement'])) {
        $aid = (int)$_POST['article_id'];
        $type = $_POST['mouvement'] === 'sortie' ? 'sortie' : 'entree';
        $qte = (float)$_POST['qte_mvt'];
        if ($qte > 0 && $aid) {
            $pdo->prepare('INSERT INTO stock_mouvements (article_id, type, quantite, motif, date_mouvement) VALUES (?,?,?,?,CURDATE())')
                ->execute([$aid, $type, $qte, mb_substr(trim($_POST['motif'] ?? ''), 0, 200)]);
            // Mettre à jour la quantité de l'article
            $op = $type === 'entree' ? '+' : '-';
            $pdo->prepare("UPDATE stock_articles SET quantite = GREATEST(0, quantite $op ?) WHERE id=?")->execute([$qte, $aid]);
            flash($type === 'entree' ? 'Entrée de stock enregistrée.' : 'Sortie de stock enregistrée.');
        }
        header('Location: stock.php'); exit;
    }

    // Supprimer un article (admin)
    if ($admin && isset($_POST['supprimer'])) {
        $aid = (int)$_POST['supprimer'];
        $pdo->prepare('DELETE FROM stock_mouvements WHERE article_id=?')->execute([$aid]);
        $pdo->prepare('DELETE FROM stock_articles WHERE id=?')->execute([$aid]);
        flash('Article supprimé.');
        header('Location: stock.php'); exit;
    }
}

// Filtre par catégorie
$fcat = $_GET['cat'] ?? '';
$where = ''; $params = [];
if ($fcat !== '' && in_array($fcat, $categories, true)) { $where = 'WHERE categorie = ?'; $params[] = $fcat; }

$articles = $pdo->prepare("SELECT * FROM stock_articles $where ORDER BY nom");
$articles->execute($params);
$articles = $articles->fetchAll();

// Statistiques
$tous = $pdo->query("SELECT quantite, seuil_alerte, prix_unitaire FROM stock_articles")->fetchAll();
$nbArticles = count($tous);
$valeurTotale = 0; $nbAlertes = 0;
foreach ($tous as $a) {
    $valeurTotale += $a['quantite'] * $a['prix_unitaire'];
    if ($a['seuil_alerte'] > 0 && $a['quantite'] <= $a['seuil_alerte']) $nbAlertes++;
}

// Article en cours d'édition
$edit = null;
if (isset($_GET['edit'])) {
    $e = $pdo->prepare('SELECT * FROM stock_articles WHERE id=?'); $e->execute([(int)$_GET['edit']]);
    $edit = $e->fetch();
}

admin_header('Stock', 'stock', $pdo, $settings);
?>

<div class="panel glass">
  <div class="stock-stats">
    <div class="ss-item"><div class="ss-ico">📦</div><div><div class="ss-num"><?= $nbArticles ?></div><div class="ss-lbl">Articles en stock</div></div></div>
    <div class="ss-item"><div class="ss-ico">💰</div><div><div class="ss-num"><?= money($valeurTotale, $devise) ?></div><div class="ss-lbl">Valeur du stock</div></div></div>
    <div class="ss-item <?= $nbAlertes>0?'ss-alerte':'' ?>"><div class="ss-ico"><?= $nbAlertes>0?'⚠️':'✅' ?></div><div><div class="ss-num"><?= $nbAlertes ?></div><div class="ss-lbl">Alerte<?= $nbAlertes>1?'s':'' ?> stock bas</div></div></div>
  </div>
</div>

<!-- Formulaire d'ajout / modification -->
<details class="panel glass panel-pliable" id="form" <?= $edit ? 'open' : '' ?>>
  <summary class="panel-titre"><?= $edit ? '✏️ Modifier : ' . e($edit['nom']) : '➕ Nouvel article' ?><span class="chev">▾</span></summary>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
    <div class="field"><label>Nom de l'article *</label><input class="input" name="nom" required value="<?= e($edit['nom'] ?? '') ?>" placeholder="Ex : Riz parfumé"></div>
    <div class="field"><label>Catégorie</label>
      <select class="input" name="categorie">
        <?php foreach ($categories as $c): ?><option value="<?= $c ?>" <?= (($edit['categorie'] ?? '')===$c)?'selected':'' ?>><?= $c ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Unité</label>
      <select class="input" name="unite">
        <?php foreach ($unites as $u): ?><option value="<?= $u ?>" <?= (($edit['unite'] ?? 'unité')===$u)?'selected':'' ?>><?= $u ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Quantité actuelle</label><input class="input" type="number" step="0.01" name="quantite" value="<?= e($edit['quantite'] ?? '0') ?>"></div>
    <div class="field"><label>Seuil d'alerte <span style="color:var(--ink-faint);font-weight:400">(stock bas si ≤)</span></label><input class="input" type="number" step="0.01" name="seuil_alerte" value="<?= e($edit['seuil_alerte'] ?? '0') ?>"></div>
    <div class="field"><label>Prix unitaire (<?= e($devise) ?>)</label><input class="input" type="number" step="1" name="prix_unitaire" value="<?= e($edit['prix_unitaire'] ?? '0') ?>"></div>
    <div class="field"><label>Fournisseur</label><input class="input" name="fournisseur" value="<?= e($edit['fournisseur'] ?? '') ?>" placeholder="Facultatif"></div>
    <div class="field full"><label>Notes</label><input class="input" name="notes" value="<?= e($edit['notes'] ?? '') ?>" placeholder="Facultatif"></div>
    <div class="full" style="margin-top:6px">
      <button class="btn btn-gold" name="enregistrer" value="1"><?= $edit ? '💾 Enregistrer les modifications' : '➕ Ajouter au stock' ?></button>
      <?php if ($edit): ?><a class="btn btn-glass" href="stock.php">Annuler</a><?php endif; ?>
    </div>
  </form>
</details>

<!-- Liste des articles -->
<div class="panel glass">
  <div class="stock-filtres">
    <a href="stock.php" class="<?= $fcat===''?'on':'' ?>">Tous</a>
    <?php foreach ($categories as $c): ?>
    <a href="stock.php?cat=<?= urlencode($c) ?>" class="<?= $fcat===$c?'on':'' ?>"><?= $c ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$articles): ?>
    <p style="text-align:center;color:var(--ink-dim);padding:30px">Aucun article. Ajoutez votre premier article ci-dessus.</p>
  <?php else: ?>
  <div class="stock-liste">
    <?php foreach ($articles as $a):
      $bas = $a['seuil_alerte'] > 0 && $a['quantite'] <= $a['seuil_alerte'];
    ?>
    <div class="stock-row <?= $bas?'stock-bas':'' ?>">
      <div class="sr-main">
        <div class="sr-nom"><?= e($a['nom']) ?> <?php if($bas): ?><span class="sr-alerte">⚠️ Stock bas</span><?php endif; ?></div>
        <div class="sr-meta"><?= e($a['categorie']) ?><?= $a['fournisseur'] ? ' · '.e($a['fournisseur']) : '' ?></div>
      </div>
      <div class="sr-qte">
        <span class="sr-qte-num"><?= rtrim(rtrim(number_format($a['quantite'],2,',',' '),'0'),',') ?></span>
        <span class="sr-qte-unite"><?= e($a['unite']) ?></span>
      </div>
      <div class="sr-val"><?= money($a['quantite']*$a['prix_unitaire'], $devise) ?></div>
      <div class="sr-actions">
        <!-- Mouvement rapide -->
        <form method="post" class="sr-mvt">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="article_id" value="<?= $a['id'] ?>">
          <input type="number" step="0.01" min="0" name="qte_mvt" placeholder="Qté" class="input sr-mvt-qte" required>
          <button class="btn btn-glass btn-sm" type="submit" name="mouvement" value="entree" title="Entrée (ajouter au stock)">＋</button>
          <button class="btn btn-glass btn-sm" type="submit" name="mouvement" value="sortie" title="Sortie (retirer du stock)">－</button>
        </form>
        <a class="btn btn-glass btn-sm" href="stock.php?edit=<?= $a['id'] ?>#form" title="Modifier">✏️</a>
        <?php if ($admin): ?>
        <form method="post" style="display:inline" data-confirm="Supprimer « <?= e($a['nom']) ?> » du stock ?">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <button class="btn btn-danger btn-sm" name="supprimer" value="<?= $a['id'] ?>" title="Supprimer">🗑️</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php admin_footer(); ?>
