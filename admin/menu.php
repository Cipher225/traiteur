<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';

/* ----------------------------------------------------------------------------
   Publier, ou retirer du site.

   Publier n'est pas une modification de fiche : c'est un geste qu'on fait vingt
   fois par jour et dont on veut voir l'effet tout de suite. Il n'a donc pas de
   bouton « Enregistrer » — la bascule vaut enregistrement.

   L'état est envoyé explicitement (publié / retiré) plutôt qu'inversé côté
   serveur : deux clics rapides sur une connexion lente s'annuleraient l'un
   l'autre, et l'écran finirait par mentir sur l'état réel.
   ---------------------------------------------------------------------------- */
function menu_repondre_json(bool $ok, string $message = '', array $extra = []): void {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => $ok, 'message' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

/* La page appelle en arrière-plan ; un navigateur sans JavaScript poste
   normalement et repart sur la liste. */
function menu_est_json(): bool {
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $retour = 'menu.php';

    /* ---------- Publication immédiate ---------- */
    if (isset($_POST['cat_publier']) || isset($_POST['art_publier'])) {
        $estCat = isset($_POST['cat_publier']);
        $id     = (int)($_POST[$estCat ? 'cat_publier' : 'art_publier']);
        $etat   = !empty($_POST['publie']) ? 1 : 0;
        $table  = $estCat ? 'categories' : 'plats';

        try {
            $pdo->prepare("UPDATE $table SET actif=? WHERE id=?")->execute([$etat, $id]);
            $ok = true;
        } catch (Throwable $e) { $ok = false; }

        $quoi = $estCat ? 'Catégorie' : 'Article';
        $msg  = $ok ? ($quoi . ($etat ? ' publiée sur le site.' : ' retirée du site.'))
                    : "L'enregistrement n'a pas abouti.";
        if (!$estCat) $msg = $ok ? ($etat ? 'Article publié.' : 'Article retiré du site.') : $msg;

        if (menu_est_json()) menu_repondre_json($ok, $msg, ['etat' => $etat]);

        flash($msg, $ok ? 'success' : 'error');
        $cid = $estCat ? $id : (int)$pdo->query("SELECT categorie_id FROM plats WHERE id=" . $id)->fetchColumn();
        header('Location: menu.php?c=' . $cid); exit;
    }

    /* ---------- CATÉGORIES ---------- */
    if (isset($_POST['cat_save'])) {
        $id   = (int)($_POST['cat_id'] ?? 0);
        $nom  = trim($_POST['cat_nom'] ?? '');
        $icone = mb_substr(trim($_POST['cat_icone'] ?? '🍽️'), 0, 10) ?: '🍽️';
        $desc = mb_substr(trim($_POST['cat_desc'] ?? ''), 0, 255);
        /* À la création seulement : le formulaire de modification n'a plus de
           case « publier », la publication vivant sur la fiche. Sans ce
           « isset », une simple correction de nom aurait masqué la catégorie. */
        $actif = isset($_POST['cat_actif']) ? 1 : 0;
        $prixMin = max(0, (int)($_POST['cat_prix_min'] ?? 0));
        $prixMax = max(0, (int)($_POST['cat_prix_max'] ?? 0));
        if ($nom === '') { flash('Le nom de la catégorie est obligatoire.', 'error'); header("Location: $retour"); exit; }
        if ($id) {
            $pdo->prepare('UPDATE categories SET nom=?, icone=?, description=?, prix_min=?, prix_max=? WHERE id=?')
                ->execute([mb_substr($nom, 0, 100), $icone, $desc, $prixMin, $prixMax, $id]);
            flash('Catégorie mise à jour.');
            $retour = 'menu.php?c=' . $id;
        } else {
            $ordre = (int)$pdo->query('SELECT COALESCE(MAX(ordre),0)+1 FROM categories')->fetchColumn();
            $pdo->prepare('INSERT INTO categories (nom, icone, description, prix_min, prix_max, ordre, actif) VALUES (?,?,?,?,?,?,?)')
                ->execute([mb_substr($nom, 0, 100), $icone, $desc, $prixMin, $prixMax, $ordre, $actif]);
            flash('Catégorie « ' . $nom . ' » créée. Ajoutez-y vos articles.');
            $retour = 'menu.php?c=' . (int)$pdo->lastInsertId();
        }
        header("Location: $retour"); exit;
    }

    if (isset($_POST['cat_delete'])) {
        $id = (int)$_POST['cat_delete'];
        $imgs = $pdo->prepare('SELECT image FROM plats WHERE categorie_id=? AND image IS NOT NULL');
        $imgs->execute([$id]);
        foreach ($imgs as $r) @unlink(UPLOAD_DIR . '/' . $r['image']);
        $pdo->prepare('DELETE FROM categories WHERE id=?')->execute([$id]); // les plats suivent (ON DELETE CASCADE)
        flash('Catégorie et ses articles supprimés.');
        header('Location: menu.php'); exit;
    }

    if (isset($_POST['cat_move'])) {
        $id = (int)$_POST['cat_move'];
        $sens = ($_POST['sens'] ?? 'up') === 'up' ? 'up' : 'down';
        $cur = $pdo->prepare('SELECT ordre FROM categories WHERE id=?'); $cur->execute([$id]);
        $o = (int)$cur->fetchColumn();
        $q = $sens === 'up'
            ? $pdo->prepare('SELECT id, ordre FROM categories WHERE ordre < ? ORDER BY ordre DESC, id DESC LIMIT 1')
            : $pdo->prepare('SELECT id, ordre FROM categories WHERE ordre > ? ORDER BY ordre ASC, id ASC LIMIT 1');
        $q->execute([$o]);
        if ($v = $q->fetch()) {
            $pdo->prepare('UPDATE categories SET ordre=? WHERE id=?')->execute([(int)$v['ordre'], $id]);
            $pdo->prepare('UPDATE categories SET ordre=? WHERE id=?')->execute([$o, (int)$v['id']]);
        }
        header('Location: menu.php?c=' . $id); exit;
    }

    /* ---------- ARTICLES ---------- */
    if (isset($_POST['art_save'])) {
        $id   = (int)($_POST['art_id'] ?? 0);
        $cid  = (int)($_POST['art_cat'] ?? 0);
        $nom  = trim($_POST['art_nom'] ?? '');
        if ($nom === '' || !$cid) { flash("Le nom de l'article est obligatoire.", 'error'); header("Location: menu.php?c=$cid"); exit; }
        $desc = mb_substr(trim($_POST['art_desc'] ?? ''), 0, 500);
        $prix = max(0, (float)($_POST['art_prix'] ?? 0));
        $pop  = isset($_POST['art_populaire']) ? 1 : 0;
        /* Comme pour les catégories : la case n'existe qu'à l'ajout. En
           modification, on ne touche pas à la publication. */
        $actif = isset($_POST['art_actif']) ? 1 : 0;
        $image = upload_image_redim($_FILES['art_image'] ?? [], UPLOAD_DIR, 600, 600, 'cover');

        if ($id) {
            $anc = $pdo->prepare('SELECT image FROM plats WHERE id=?'); $anc->execute([$id]);
            $ancImg = $anc->fetchColumn();
            if (!empty($_POST['art_img_suppr']) && $ancImg) { @unlink(UPLOAD_DIR . '/' . $ancImg); $ancImg = null; }
            if ($image && $ancImg) @unlink(UPLOAD_DIR . '/' . $ancImg);
            $pdo->prepare('UPDATE plats SET categorie_id=?, nom=?, description=?, prix=?, populaire=?, image=? WHERE id=?')
                ->execute([$cid, mb_substr($nom, 0, 150), $desc, $prix, $pop, $image ?: $ancImg, $id]);
            flash('Article mis à jour.');
        } else {
            $ordre = (int)$pdo->query('SELECT COALESCE(MAX(ordre),0)+1 FROM plats WHERE categorie_id=' . $cid)->fetchColumn();
            $pdo->prepare('INSERT INTO plats (categorie_id, nom, description, prix, populaire, actif, image, ordre) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$cid, mb_substr($nom, 0, 150), $desc, $prix, $pop, $actif, $image, $ordre]);
            flash('Article ajouté au menu.');
        }
        header("Location: menu.php?c=$cid"); exit;
    }

    if (isset($_POST['art_delete'])) {
        $id = (int)$_POST['art_delete'];
        $r = $pdo->prepare('SELECT image, categorie_id FROM plats WHERE id=?'); $r->execute([$id]); $r = $r->fetch();
        if ($r) {
            if ($r['image']) @unlink(UPLOAD_DIR . '/' . $r['image']);
            $pdo->prepare('DELETE FROM plats WHERE id=?')->execute([$id]);
            flash('Article supprimé.');
        }
        header('Location: menu.php?c=' . (int)($r['categorie_id'] ?? 0)); exit;
    }

    if (isset($_POST['art_move'])) {
        $id = (int)$_POST['art_move'];
        $sens = ($_POST['sens'] ?? 'up') === 'up' ? 'up' : 'down';
        $cur = $pdo->prepare('SELECT ordre, categorie_id FROM plats WHERE id=?'); $cur->execute([$id]); $cur = $cur->fetch();
        if ($cur) {
            $o = (int)$cur['ordre']; $cid = (int)$cur['categorie_id'];
            $q = $sens === 'up'
                ? $pdo->prepare('SELECT id, ordre FROM plats WHERE categorie_id=? AND ordre < ? ORDER BY ordre DESC, id DESC LIMIT 1')
                : $pdo->prepare('SELECT id, ordre FROM plats WHERE categorie_id=? AND ordre > ? ORDER BY ordre ASC, id ASC LIMIT 1');
            $q->execute([$cid, $o]);
            if ($v = $q->fetch()) {
                $pdo->prepare('UPDATE plats SET ordre=? WHERE id=?')->execute([(int)$v['ordre'], $id]);
                $pdo->prepare('UPDATE plats SET ordre=? WHERE id=?')->execute([$o, (int)$v['id']]);
            }
            header("Location: menu.php?c=$cid"); exit;
        }
        header('Location: menu.php'); exit;
    }

    /* « art_toggle » (bascule aveugle) a été remplacé par « art_publier », qui
       reçoit l'état voulu. Deux clics rapides donnent ainsi le même résultat
       qu'un seul, alors qu'une bascule les annulait l'un l'autre. */

    header('Location: menu.php'); exit;
}

/* ----------------------------------------------------------------------------
   Lecture.

   Le menu se lit par catégorie : paginer couperait une formule en deux, ce qui
   n'aurait aucun sens. On garde donc le groupement, et la recherche filtre les
   plats — les catégories concernées s'ouvrent alors d'elles-mêmes.
   ---------------------------------------------------------------------------- */
$q = trim($_GET['q'] ?? '');

$cats = $pdo->query('SELECT * FROM categories ORDER BY ordre, id')->fetchAll();

$whereP = ' WHERE 1=1'; $argsP = [];
$rch = recherche_sql($q, ['nom', 'description']);
$whereP .= $rch['sql']; $argsP = $rch['args'];

$st = $pdo->prepare("SELECT * FROM plats $whereP ORDER BY categorie_id, ordre, id");
$st->execute($argsP);
$arts = $st->fetchAll();
$parCat = [];
foreach ($arts as $a) $parCat[$a['categorie_id']][] = $a;

$ouvert = (int)($_GET['c'] ?? 0);          // catégorie dépliée
$editCat = (int)($_GET['edit_cat'] ?? 0);   // catégorie en cours de modification
$editArt = (int)($_GET['edit_art'] ?? 0);   // article en cours de modification
if ($editArt) { foreach ($arts as $a) if ($a['id'] == $editArt) $ouvert = (int)$a['categorie_id']; }
/* Une recherche n'a d'intérêt que si l'on voit ses résultats : on ouvre toutes
   les catégories qui en contiennent. */
$catsOuvertes = [];
if ($q !== '') foreach ($arts as $a) $catsOuvertes[(int)$a['categorie_id']] = true;
if ($editCat) $ouvert = $editCat;
/* ----------------------------------------------------------------------------
   Le mode « travail sur une seule catégorie ».

   La page listait toutes les catégories en même temps, et le formulaire de
   modification s'ouvrait au milieu : on ne savait plus ce qu'on modifiait, ni
   où l'on était dans la page. Dès qu'on modifie quelque chose, la page ne
   montre donc plus que cette catégorie-là — et un chemin de retour.
   ---------------------------------------------------------------------------- */
$focus = 0;
if ($editCat) $focus = $editCat;
elseif ($editArt) { foreach ($arts as $a) if ((int)$a['id'] === $editArt) $focus = (int)$a['categorie_id']; }
/* La catégorie ciblée existe-t-elle encore ? Sinon on revient à la liste. */
if ($focus && !array_filter($cats, fn($c) => (int)$c['id'] === $focus)) $focus = 0;

$catFocus = null;
if ($focus) foreach ($cats as $c) if ((int)$c['id'] === $focus) $catFocus = $c;

$nbCats = count($cats); $nbArts = count($arts);
$nbInactifs = 0; foreach ($arts as $a) if (!$a['actif']) $nbInactifs++;

admin_header('Menu', 'menu', $pdo, $settings);
$csrf = csrf_token();
$devise = $settings['devise'] ?? 'FCFA';
?>
<?php if ($focus): ?>
<?php /* Le chemin de retour d'abord : on doit toujours savoir comment sortir
         de ce qu'on est en train de faire. */ ?>
<div class="menu-focus-barre">
  <a class="btn btn-glass btn-sm" href="menu.php?c=<?= $focus ?>">← Retour au menu complet</a>
  <span class="mfb-t"><?= e($catFocus['icone'] ?? '') ?> <?= e($catFocus['nom'] ?? '') ?></span>
  <span class="mfb-q"><?= $editArt ? 'Modification d’un article' : 'Modification de la catégorie' ?></span>
</div>
<?php else: ?>
<div class="panel glass menu-intro">
  <div class="mi-txt">
    <h2 style="border:0;margin:0;padding:0">🍽️ Le menu de la maison</h2>
    <p>Créez vos catégories — <em>Pause café du matin, Cocktail dînatoire, Plats principaux…</em> — puis ajoutez directement les articles qui les composent. Tout ce que vous publiez ici apparaît aussitôt sur le site et dans l'espace client.</p>
  </div>
  <div class="mi-stats">
    <div><strong><?= $nbCats ?></strong><span>catégorie<?= $nbCats > 1 ? 's' : '' ?></span></div>
    <div><strong><?= $nbArts ?></strong><span>article<?= $nbArts > 1 ? 's' : '' ?></span></div>
    <?php if ($nbInactifs): ?><div><strong><?= $nbInactifs ?></strong><span>masqué<?= $nbInactifs > 1 ? 's' : '' ?></span></div><?php endif; ?>
  </div>
</div>

<!-- ====== Nouvelle catégorie ====== -->
<div class="panel glass" style="margin-bottom:12px">
  <div class="mod-tete" style="margin:0">
    <h2 style="margin:0">🍽️ Carte
      <span class="cnt"><?= count($arts) ?> plat<?= count($arts) > 1 ? 's' : '' ?></span></h2>
    <?= barre_recherche($q, 'Nom du plat ou description…', $_GET) ?>
  </div>
</div>

<details class="panel glass newcat" <?= $nbCats ? '' : 'open' ?>>
  <summary><span class="nc-plus">＋</span> Créer une catégorie</summary>
  <form method="post" class="cat-form">
    <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="cat_save" value="1">
    <div class="cf-row">
      <div class="field ic"><label>Icône</label>
        <input class="input icone-input" name="cat_icone" id="cat_icone_new" value="🍽️" maxlength="4">
      </div>
      <div class="field"><label>Nom de la catégorie *</label><input class="input" name="cat_nom" placeholder="ex : Pause café du matin" required></div>
      <div class="field"><label>Description (facultatif)</label><input class="input" name="cat_desc" placeholder="ex : Servi de 8h à 10h30"></div>
      <div class="field"><label>Prix indicatif — de (FCFA)</label><input class="input" type="number" name="cat_prix_min" min="0" placeholder="ex : 15000"></div>
      <div class="field"><label>Prix indicatif — à (FCFA)</label><input class="input" type="number" name="cat_prix_max" min="0" placeholder="ex : 25000"></div>
      <?php /* La palette occupe toute la largeur : serrée dans la colonne de
               l'icône (76 px), elle s'affichait sur trois icônes de front et
               il fallait faire défiler pour en voir quatre-vingts. */ ?>
      <div class="icone-palette" data-cible="cat_icone_new"></div>
    </div>

    <div class="pub-bloc">
      <span class="pub-titre">🌐 Sur le site public</span>
      <label class="switch pub-sw">
        <input type="checkbox" name="cat_actif" checked><span></span>
        <b>Publier dès la création</b></label>
      <span class="pub-aide">Décochez pour préparer la catégorie tranquillement
        et ne la faire paraître qu'une fois ses articles en place.</span>
    </div>

    <div class="cf-actions"><button class="btn btn-gold">Créer</button></div>
  </form>
</details>

<?php endif; /* fin du mode liste */ ?>

<?php if (!$cats): ?>
<div class="panel glass" style="text-align:center;padding:44px;color:var(--ink-faint)">
  Aucune catégorie pour le moment. Créez la première ci-dessus — par exemple « Pause café du matin ».
</div>
<?php endif; ?>

<?php foreach ($cats as $i => $c):
  /* En mode travail, les autres catégories ne s'affichent pas : c'est tout
     l'objet de ce mode. */
  if ($focus && (int)$c['id'] !== $focus) continue;
  $items = $parCat[$c['id']] ?? [];
  /* En mode travail, la catégorie est forcément dépliée : elle est la seule
     chose à l'écran, la replier ne laisserait rien. */
  $isOpen = $focus
         || !empty($catsOuvertes[(int)$c['id']])
         || ($ouvert === (int)$c['id'])
         || ($q === '' && !$ouvert && $i === 0);
?>
<details class="panel glass cat-block <?= $c['actif'] ? '' : 'is-off' ?><?= $focus ? ' cat-focus' : '' ?>" <?= $isOpen ? 'open' : '' ?> id="cat<?= $c['id'] ?>">
  <summary class="cat-sum">
    <span class="cat-ic"><?= e($c['icone']) ?></span>
    <span class="cat-nom">
      <?= e($c['nom']) ?>
      <?php if (!$c['actif']): ?><span class="badge" style="margin-left:6px">masquée</span><?php endif; ?>
      <?php if (trim((string)($c['description'] ?? '')) !== ''): ?><small><?= e($c['description']) ?></small><?php endif; ?>
    </span>
    <span class="cat-count"><?= count($items) ?> article<?= count($items) > 1 ? 's' : '' ?></span>
    <span class="chev">▸</span>
  </summary>

  <div class="cat-body">
    <?php /* La publication est la seule décision qui se voit immédiatement sur
             le site : elle a sa ligne, en tête de la catégorie, et s'enregistre
             toute seule. Elle ne dépend plus d'un bouton « Enregistrer », donc
             elle ne risque plus d'emporter avec elle un nom à demi modifié. */ ?>
    <form method="post" class="pub-ligne pub-auto <?= $c['actif'] ? 'est-on' : '' ?>">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="cat_publier" value="<?= $c['id'] ?>">
      <label class="switch pub-sw">
        <input type="checkbox" name="publie" value="1" <?= $c['actif'] ? 'checked' : '' ?>><span></span>
        <b>Visible sur le site</b>
      </label>
      <span class="pub-dit"><?= $c['actif']
        ? 'Cette catégorie et ses articles publiés paraissent sur le site.'
        : 'Masquée : la catégorie et <strong>tous</strong> ses articles disparaissent du site. Rien n’est supprimé.' ?></span>
      <button class="btn btn-glass btn-sm pub-valider">Enregistrer</button>
      <span class="pub-etat" aria-live="polite"></span>
    </form>

    <?php if (!$focus): /* Déplacer ou supprimer n'a pas de sens pendant qu'on
                           modifie : ces boutons reviennent après. */ ?>
    <!-- Barre d'actions de la catégorie -->
    <div class="cat-actions">
      <a class="btn btn-glass btn-sm" href="menu.php?c=<?= $c['id'] ?>&edit_cat=<?= $c['id'] ?>">✏️ Modifier la catégorie</a>
      <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="sens" value="up">
        <button class="btn btn-glass btn-sm" name="cat_move" value="<?= $c['id'] ?>" title="Monter" <?= $i === 0 ? 'disabled' : '' ?>>↑</button></form>
      <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="sens" value="down">
        <button class="btn btn-glass btn-sm" name="cat_move" value="<?= $c['id'] ?>" title="Descendre" <?= $i === $nbCats - 1 ? 'disabled' : '' ?>>↓</button></form>
      <form method="post" data-confirm="Supprimer « <?= e($c['nom']) ?> » et ses <?= count($items) ?> article(s) ?" style="margin-left:auto">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <button class="btn btn-danger btn-sm" name="cat_delete" value="<?= $c['id'] ?>">🗑️ Supprimer</button></form>
    </div>
    <?php endif; ?>

    <?php if ($editCat === (int)$c['id']): ?>
    <form method="post" class="cat-form edit-inline">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="cat_save" value="1">
      <input type="hidden" name="cat_id" value="<?= $c['id'] ?>">
      <div class="cf-row">
        <div class="field ic"><label>Icône</label>
          <input class="input icone-input" name="cat_icone" id="cat_icone_edit" value="<?= e($c['icone']) ?>" maxlength="4">
        </div>
        <div class="field"><label>Nom *</label><input class="input" name="cat_nom" value="<?= e($c['nom']) ?>" required></div>
        <div class="field"><label>Description</label><input class="input" name="cat_desc" value="<?= e($c['description'] ?? '') ?>"></div>
        <div class="field"><label>Prix indicatif — de (FCFA)</label><input class="input" type="number" name="cat_prix_min" min="0" value="<?= (int)($c['prix_min'] ?? 0) ?>"></div>
        <div class="field"><label>Prix indicatif — à (FCFA)</label><input class="input" type="number" name="cat_prix_max" min="0" value="<?= (int)($c['prix_max'] ?? 0) ?>"></div>
        <div class="icone-palette" data-cible="cat_icone_edit"></div>
      </div>

      <?php /* Pas d'interrupteur de publication ici : il est en tête de la
               catégorie, où il s'enregistre seul. Un même réglage à deux
               endroits, avec deux façons de le valider, est exactement ce qui
               rendait cette page confuse. */ ?>
      <div class="cf-actions">
        <button class="btn btn-gold btn-sm">Enregistrer</button>
        <a class="btn btn-glass btn-sm" href="menu.php?c=<?= $c['id'] ?>">Annuler</a>
      </div>
    </form>
    <?php endif; ?>

    <!-- Articles de la catégorie -->
    <?php if ($items): ?>
    <div class="art-list">
      <?php foreach ($items as $k => $a): $enEdit = ($editArt === (int)$a['id']);
        /* Un article en cours de modification s'affiche seul : les vingt
           autres de la catégorie n'apprennent rien pendant ce temps. */
        if ($editArt && !$enEdit) continue; ?>
      <div class="art-row <?= $a['actif'] ? '' : 'is-off' ?>">
        <div class="art-thumb"><?php if ($a['image']): ?><img src="../uploads/<?= e($a['image']) ?>" alt=""><?php else: ?><span>🍽️</span><?php endif; ?></div>
        <div class="art-main">
          <strong><?= e($a['nom']) ?>
            <?php if ($a['populaire']): ?><span class="badge badge-gold">★ populaire</span><?php endif; ?>
            <?php if (!$a['actif']): ?><span class="badge">masqué</span><?php endif; ?>
          </strong>
          <?php if (trim((string)$a['description']) !== ''): ?><p><?= e($a['description']) ?></p><?php endif; ?>
        </div>
        <div class="art-prix"><?= $a['prix'] > 0 ? money($a['prix'], $devise) : '<span class="sur-devis">sur devis</span>' ?></div>
        <div class="art-act">
          <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="sens" value="up">
            <button class="ico-btn" name="art_move" value="<?= $a['id'] ?>" title="Monter" <?= $k === 0 ? 'disabled' : '' ?>>↑</button></form>
          <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="sens" value="down">
            <button class="ico-btn" name="art_move" value="<?= $a['id'] ?>" title="Descendre" <?= $k === count($items) - 1 ? 'disabled' : '' ?>>↓</button></form>
          <?php /* Un œil qui change de dessin n'apprend pas s'il décrit l'état
                   actuel ou l'action à venir. Un interrupteur, si : allumé =
                   visible. Il s'enregistre à l'instant où on le bascule. */ ?>
          <form method="post" class="pub-auto pub-art <?= $a['actif'] ? 'est-on' : '' ?>"
                title="<?= $a['actif'] ? 'Visible sur le site' : 'Masqué sur le site' ?>">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="art_publier" value="<?= $a['id'] ?>">
            <label class="switch pub-sw sw-mini">
              <input type="checkbox" name="publie" value="1" <?= $a['actif'] ? 'checked' : '' ?>><span></span>
              <b>Visible</b>
            </label>
            <button class="btn btn-glass btn-sm pub-valider">OK</button>
          </form>
          <a class="ico-btn" href="menu.php?c=<?= $c['id'] ?>&edit_art=<?= $a['id'] ?>" title="Modifier">✏️</a>
          <form method="post" data-confirm="Supprimer « <?= e($a['nom']) ?> » ?"><input type="hidden" name="csrf" value="<?= $csrf ?>">
            <button class="ico-btn danger" name="art_delete" value="<?= $a['id'] ?>" title="Supprimer">✕</button></form>
        </div>
      </div>
      <?php if ($enEdit): ?>
      <form method="post" enctype="multipart/form-data" class="art-form edit-inline">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="art_save" value="1">
        <input type="hidden" name="art_id" value="<?= $a['id'] ?>">
        <div class="af-grid">
          <div class="field"><label>Nom de l'article *</label><input class="input" name="art_nom" value="<?= e($a['nom']) ?>" required></div>
          <div class="field"><label>Prix (<?= e($devise) ?>) — 0 = sur devis</label><input class="input" type="number" step="1" min="0" name="art_prix" value="<?= (int)$a['prix'] ?>"></div>
          <div class="field"><label>Catégorie</label>
            <select class="input" name="art_cat"><?php foreach ($cats as $cc): ?><option value="<?= $cc['id'] ?>" <?= $cc['id'] == $a['categorie_id'] ? 'selected' : '' ?>><?= e($cc['icone']) ?> <?= e($cc['nom']) ?></option><?php endforeach; ?></select>
          </div>
          <div class="field full"><label>Description</label><input class="input" name="art_desc" value="<?= e($a['description']) ?>" placeholder="Composition, accompagnement…"></div>
          <div class="field"><label>Photo</label><input class="input" type="file" name="art_image" accept="image/*" data-redim="600x600" data-redim-mode="cover" data-redim-cut></div>
          <?php /* « Populaire » et « Retirer la photo » décrivent l'article :
                   leur place est dans sa fiche. Publier ou non ne le décrit
                   pas, cela le montre ou le cache — cet interrupteur-là est
                   resté sur la ligne de l'article, où il s'enregistre seul. */ ?>
          <div class="af-opts">
            <label class="switch"><input type="checkbox" name="art_populaire" <?= $a['populaire'] ? 'checked' : '' ?>><span></span> Mettre en avant</label>
            <?php if ($a['image']): ?><label class="switch"><input type="checkbox" name="art_img_suppr"><span></span> Retirer la photo</label><?php endif; ?>
          </div>
          <div class="full" style="display:flex;gap:8px">
            <button class="btn btn-gold btn-sm">Enregistrer</button>
            <a class="btn btn-glass btn-sm" href="menu.php?c=<?= $c['id'] ?>">Annuler</a>
          </div>
        </div>
      </form>
      <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php elseif (!$focus): ?>
    <p class="art-vide">Cette catégorie est vide. Ajoutez son premier article ci-dessous.</p>
    <?php endif; ?>

    <?php if (!$focus): /* On termine une modification avant d'en commencer
                           une autre : le formulaire d'ajout revient ensuite. */ ?>
    <!-- Ajout rapide d'un article dans CETTE catégorie -->
    <form method="post" enctype="multipart/form-data" class="art-form add">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="art_save" value="1">
      <input type="hidden" name="art_cat" value="<?= $c['id'] ?>">
      <div class="af-title">＋ Ajouter un article à « <?= e($c['nom']) ?> »</div>
      <div class="af-grid">
        <div class="field"><label>Nom de l'article *</label><input class="input" name="art_nom" placeholder="ex : Croissant au beurre" required></div>
        <div class="field"><label>Prix (<?= e($devise) ?>) — 0 = sur devis</label><input class="input" type="number" step="1" min="0" name="art_prix" value="0"></div>
        <div class="field"><label>Photo (facultatif)</label><input class="input" type="file" name="art_image" accept="image/*" data-redim="600x600" data-redim-mode="cover" data-redim-cut></div>
        <div class="field full"><label>Description (facultatif)</label><input class="input" name="art_desc" placeholder="ex : Pur beurre, cuit sur place chaque matin"></div>
        <div class="af-opts">
          <label class="switch"><input type="checkbox" name="art_populaire"><span></span> Mettre en avant</label>
        </div>
        <div class="pub-bloc full">
          <span class="pub-titre">🌐 Sur le site public</span>
          <label class="switch pub-sw">
            <input type="checkbox" name="art_actif" checked><span></span>
            <b>Publier dès l'ajout</b></label>
          <?php if (!$c['actif']): ?>
          <span class="pub-aide alerte">⚠️ La catégorie « <?= e($c['nom']) ?> » est masquée :
            l'article ne paraîtra pas tant qu'elle le reste.</span>
          <?php endif; ?>
        </div>
        <div class="full"><button class="btn btn-gold btn-sm">Ajouter au menu</button></div>
      </div>
    </form>
    <?php endif; /* fin du masquage en mode travail */ ?>
  </div>
</details>
<?php endforeach; ?>

<script>
/* Ouvre la bonne catégorie et amène l'utilisateur au bon endroit */
(function () {
  var c = new URLSearchParams(location.search).get('c');
  if (c) { var el = document.getElementById('cat' + c); if (el) el.scrollIntoView({ block: 'center' }); }
  var f = document.querySelector('.edit-inline');
  if (f) f.scrollIntoView({ block: 'center' });
})();

/* Palette d'icônes cliquables pour les catégories */
(function () {
  var ICONES = ['🍽️','🥗','🍲','🍛','🍜','🍝','🍕','🍔','🌮','🥙','🥪','🌯','🍱','🍚','🍙','🍢','🍡','🥘','🫕','🍤','🍗','🍖','🥩','🍟','🧆','🥟','🍳','🥞','🧇','🥓','🥐','🥖','🫓','🥨','🧀','🥧','🍰','🎂','🧁','🍮','🍨','🍦','🍧','🍩','🍪','🍫','🍬','🍭','🍯','☕','🍵','🧋','🥤','🧃','🍷','🍸','🍹','🍺','🍻','🥂','🍾','🥃','🍶','🧉','🍇','🍓','🍑','🍊','🍋','🍌','🍉','🍏','🥭','🍍','🥥','🥑','🍅','🥕','🌽','🌶️','🫑','🥦','🧅','🧄'];
  document.querySelectorAll('.icone-palette').forEach(function (pal) {
    var cible = document.getElementById(pal.getAttribute('data-cible'));
    if (!cible) return;
    ICONES.forEach(function (ic) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'ic-choix'; b.textContent = ic;
      if (ic === cible.value) b.classList.add('on');
      b.addEventListener('click', function () {
        cible.value = ic;
        pal.querySelectorAll('.ic-choix').forEach(function (x) { x.classList.remove('on'); });
        b.classList.add('on');
      });
      pal.appendChild(b);
    });
  });
})();

/* ---------------------------------------------------------------------------
   Publication : enregistrement automatique
   ---------------------------------------------------------------------------
   Le formulaire part complet, avec son bouton « Enregistrer » : sans
   JavaScript, il fonctionne comme n'importe quel formulaire de la page. Dès
   que ce script s'exécute, le bouton disparaît et c'est la bascule elle-même
   qui enregistre. L'utilisateur n'a donc jamais sous les yeux un interrupteur
   dont il ignore s'il est déjà pris en compte.
--------------------------------------------------------------------------- */
(function () {
  var formulaires = document.querySelectorAll('form.pub-auto');
  if (!formulaires.length) return;

  formulaires.forEach(function (f) {
    f.classList.add('auto');                       /* masque le bouton (CSS) */
    var caseAC = f.querySelector('input[type="checkbox"]');
    var etat   = f.querySelector('.pub-etat');
    var dit    = f.querySelector('.pub-dit');
    var enCours = false;

    function message(txt, erreur) {
      if (!etat) return;
      etat.textContent = txt;
      etat.classList.toggle('erreur', !!erreur);
      etat.classList.add('vu');
      clearTimeout(etat._t);
      /* Le « Enregistré » ne reste pas : ce qui compte ensuite, c'est la
         position de l'interrupteur, pas le souvenir du clic. */
      if (!erreur) etat._t = setTimeout(function () { etat.classList.remove('vu'); }, 2600);
    }

    caseAC.addEventListener('change', function () {
      if (enCours) return;
      enCours = true;
      var voulu = caseAC.checked;
      f.classList.toggle('est-on', voulu);
      f.classList.add('occupe');
      message('Enregistrement…', false);

      var donnees = new FormData(f);
      /* FormData ignore une case décochée : on pose l'état explicitement,
         sinon « masquer » n'enverrait rien du tout. */
      donnees.set('publie', voulu ? '1' : '0');

      fetch(f.action || location.href, {
        method: 'POST',
        body: donnees,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'fetch' }
      })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
      .then(function (rep) {
        if (!rep.ok) throw new Error(rep.message || 'refus');
        /* On se règle sur la réponse du serveur, pas sur ce qu'on croyait
           avoir envoyé : c'est lui qui détient l'état réel. */
        var reel = rep.etat === 1;
        caseAC.checked = reel;
        f.classList.toggle('est-on', reel);
        if (dit) dit.innerHTML = reel
          ? 'Cette catégorie et ses articles publiés paraissent sur le site.'
          : 'Masquée : la catégorie et <strong>tous</strong> ses articles disparaissent du site. Rien n’est supprimé.';
        f.setAttribute('title', reel ? 'Visible sur le site' : 'Masqué sur le site');
        var bloc = f.closest('.cat-block'); if (bloc) bloc.classList.toggle('is-off', !reel);
        var ligne = f.closest('.art-row');  if (ligne) ligne.classList.toggle('is-off', !reel);
        var badge = ligne ? ligne.querySelector('.art-main .badge:not(.badge-gold)') : null;
        if (ligne) {
          if (!reel && !badge) {
            var b = document.createElement('span');
            b.className = 'badge'; b.textContent = 'masqué';
            ligne.querySelector('.art-main strong').appendChild(document.createTextNode(' '));
            ligne.querySelector('.art-main strong').appendChild(b);
          } else if (reel && badge) { badge.remove(); }
        }
        message('Enregistré ✓', false);
      })
      .catch(function () {
        /* En cas d'échec, l'interrupteur revient où il était : mieux vaut
           montrer la vérité de la base qu'un réglage qui n'a pas pris. */
        caseAC.checked = !voulu;
        f.classList.toggle('est-on', !voulu);
        message('Échec — non enregistré', true);
      })
      .then(function () { enCours = false; f.classList.remove('occupe'); });
    });
  });
})();
</script>
<?php admin_footer(); ?>
