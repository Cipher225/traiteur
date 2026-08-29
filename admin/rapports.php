<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
if (!is_admin()) {
    $pdo->prepare("UPDATE rapports SET vu_par_employe=1 WHERE employe_user_id=? AND vu_par_employe=0")
        ->execute([(int)$_SESSION['admin_id']]);
}
require __DIR__ . '/includes/demandes.php';
$uid = (int)$_SESSION['admin_id'];
$admin = is_admin();
$TYPES = demande_types();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (isset($_POST['supprimer'])) {
        if ($admin) $pdo->prepare('DELETE FROM rapports WHERE id=?')->execute([(int)$_POST['supprimer']]);
        else $pdo->prepare("DELETE FROM rapports WHERE id=? AND employe_user_id=? AND (statut='brouillon' OR decision='refuse')")->execute([(int)$_POST['supprimer'], $uid]);
        flash('Document supprimé.'); header('Location: rapports.php'); exit;
    }

    // Décision de l'admin sur une demande (accepter / refuser)
    if ($admin && isset($_POST['decision'])) {
        $rid = (int)$_POST['rid'];
        $dec = $_POST['decision'] === 'accepte' ? 'accepte' : ($_POST['decision'] === 'refuse' ? 'refuse' : 'en_attente');
        $motif = mb_substr(trim($_POST['decision_motif'] ?? ''), 0, 255);
        $pdo->prepare("UPDATE rapports SET decision=?, decision_motif=?, decision_at=NOW() WHERE id=?")->execute([$dec, $motif, $rid]);
        flash($dec === 'accepte' ? '✅ Demande acceptée.' : ($dec === 'refuse' ? '❌ Demande refusée.' : 'Décision mise à jour.'));
        header('Location: rapports.php' . ($_GET['t'] ?? '' ? '?t='.$_GET['t'] : '')); exit;
    }

    if (!$admin && (isset($_POST['enregistrer']) || isset($_POST['envoyer_admin']))) {
      try {
        $id = (int)($_POST['id'] ?? 0);
        $type = array_key_exists($_POST['type'] ?? '', $TYPES) ? $_POST['type'] : 'rapport';
        $info = $TYPES[$type];
        $titre = trim($_POST['titre'] ?? '');
        $date = ($_POST['date_rapport'] ?? '') ?: date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
        $contenu = clean_report_html($_POST['contenu'] ?? '');
        $dd = ($_POST['date_debut'] ?? '') ?: null; if ($dd && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$dd)) $dd=null;
        $df = ($_POST['date_fin'] ?? '') ?: null;   if ($df && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$df)) $df=null;
        $motif = mb_substr(trim($_POST['motif'] ?? ''), 0, 255);
        $hopital = mb_substr(trim($_POST['hopital'] ?? ''), 0, 200);
        $lieu = mb_substr(trim($_POST['lieu'] ?? ''), 0, 200);
        $envoyer = isset($_POST['envoyer_admin']);
        if ($titre === '') { flash('Donnez un titre à votre '.mb_strtolower($info[0]).'.', 'error'); header('Location: rapports.php?edit='.($id?:'new').'&type='.$type); exit; }

        if ($id) {
            $stmt = $pdo->prepare("SELECT statut, type FROM rapports WHERE id=? AND employe_user_id=?");
            $stmt->execute([$id, $uid]); $row = $stmt->fetch();
            if (!$row || $row['statut'] === 'envoye') { flash('Ce document ne peut plus être modifié.', 'error'); header('Location: rapports.php'); exit; }
            $sql = "UPDATE rapports SET titre=?, date_rapport=?, contenu=?, date_debut=?, date_fin=?, motif=?, hopital=?, lieu=?"
                 . ($envoyer ? ", statut='envoye', envoye_at=NOW(), lu_par_admin=0" : "") . " WHERE id=?";
            $pdo->prepare($sql)->execute([mb_substr($titre,0,200), $date, $contenu, $dd, $df, $motif, $hopital, $lieu, $id]);
            flash($envoyer ? '📤 Envoyé à l\'administrateur (une copie reste dans votre espace).' : 'Brouillon enregistré.');
        } else {
            $numero = next_numero($pdo, 'rapports', $info[2]);
            $statut = $envoyer ? 'envoye' : 'brouillon';
            $env = $envoyer ? date('Y-m-d H:i:s') : null;
            $pdo->prepare("INSERT INTO rapports (numero, employe_user_id, type, titre, contenu, date_rapport, date_debut, date_fin, motif, hopital, lieu, statut, envoye_at, lu_par_admin) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0)")
                ->execute([$numero, $uid, $type, mb_substr($titre,0,200), $contenu, $date, $dd, $df, $motif, $hopital, $lieu, $statut, $env]);
            flash($envoyer ? '📤 Envoyé à l\'administrateur (une copie reste dans votre espace).' : 'Brouillon enregistré.');
        }
        header('Location: rapports.php'); exit;
      } catch (\Throwable $ex) {
        flash('Enregistrement impossible. Détail : ' . $ex->getMessage(), 'error');
        header('Location: rapports.php?edit=' . ((int)($_POST['id'] ?? 0) ?: 'new')); exit;
      }
    }
}

if ($admin) { $pdo->query("UPDATE rapports SET lu_par_admin=1 WHERE statut='envoye' AND lu_par_admin=0"); }

/* ---------- Édition (employé) ---------- */
$mode_form = false; $edit = null; $curType = 'rapport';
if (!$admin && isset($_GET['edit'])) {
    $mode_form = true;
    if ($_GET['edit'] !== 'new') {
        $stmt = $pdo->prepare("SELECT * FROM rapports WHERE id=? AND employe_user_id=?");
        $stmt->execute([(int)$_GET['edit'], $uid]); $edit = $stmt->fetch();
        if ($edit && $edit['statut'] === 'envoye') { $mode_form = false; $edit = null; flash('Ce document a déjà été envoyé.', 'error'); }
        if ($edit) $curType = $edit['type'] ?: 'rapport';
    } else {
        $curType = array_key_exists($_GET['type'] ?? '', $TYPES) ? $_GET['type'] : 'rapport';
    }
}

/* ---------- Listes ---------- */
if ($admin) {
    $fType = $_GET['t'] ?? '';
    $sql = "SELECT r.*, u.nom AS auteur FROM rapports r LEFT JOIN users u ON u.id=r.employe_user_id WHERE r.statut='envoye'";
    $params = [];
    if ($fType !== '' && array_key_exists($fType, $TYPES)) { $sql .= " AND r.type=?"; $params[] = $fType; }
    $sql .= " ORDER BY r.envoye_at DESC";
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $rapports = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT * FROM rapports WHERE employe_user_id=? ORDER BY created_at DESC, id DESC");
    $stmt->execute([$uid]); $rapports = $stmt->fetchAll();
}

admin_header($admin ? 'Rapports & demandes reçus' : 'Rapports & demandes', 'rapports', $pdo, $settings);

function fmt_periode($r) {
    if ($r['date_debut'] && $r['date_fin']) return 'du '.date('d/m/Y',strtotime($r['date_debut'])).' au '.date('d/m/Y',strtotime($r['date_fin']));
    if ($r['date_debut']) return 'à partir du '.date('d/m/Y',strtotime($r['date_debut']));
    return '';
}
?>

<?php if ($mode_form): $info = $TYPES[$curType]; $fields = $info[3]; ?>
<div class="panel glass">
  <h2><?= $edit ? '✏️ Modifier' : $info[1].' '.e($info[0]) ?>
    <a href="rapports.php" class="btn btn-glass btn-sm" style="margin-left:auto">← Retour</a>
  </h2>
  <form method="post" id="rapForm">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
    <input type="hidden" name="type" value="<?= e($curType) ?>">

    <div class="form-grid" style="margin-bottom:6px">
      <div class="field"><label>Objet / Titre *</label><input class="input" name="titre" required value="<?= e($edit['titre'] ?? ($info[0].' — '.date('d/m/Y'))) ?>"></div>
      <div class="field"><label>Date du document</label><input class="input" type="date" name="date_rapport" value="<?= e($edit['date_rapport'] ?? date('Y-m-d')) ?>"></div>
    </div>

    <?php if (in_array('periode', $fields)): ?>
    <div class="form-grid">
      <div class="field"><label><?= $curType==='conge_maladie'?'Arrêt du':'Du' ?> *</label><input class="input" type="date" name="date_debut" value="<?= e($edit['date_debut'] ?? '') ?>" required></div>
      <div class="field"><label>Au *</label><input class="input" type="date" name="date_fin" value="<?= e($edit['date_fin'] ?? '') ?>" required></div>
    </div>
    <?php endif; ?>

    <?php if (in_array('hopital', $fields)): ?>
    <div class="form-grid">
      <div class="field"><label>Hôpital / Clinique *</label><input class="input" name="hopital" value="<?= e($edit['hopital'] ?? '') ?>" placeholder="ex : CHU de Cocody" required></div>
      <div class="field"><label>Lieu / Ville</label><input class="input" name="lieu" value="<?= e($edit['lieu'] ?? '') ?>" placeholder="ex : Abidjan"></div>
    </div>
    <?php endif; ?>

    <?php if (in_array('motif', $fields)): ?>
    <div class="field"><label><?= $curType==='conge_maladie'?'Nature / diagnostic (facultatif)':'Motif *' ?></label><input class="input" name="motif" value="<?= e($edit['motif'] ?? '') ?>" <?= $curType!=='conge_maladie'?'required':'' ?> placeholder="Motif de la demande"></div>
    <?php endif; ?>

    <?php if (in_array('objet', $fields)): ?>
    <div class="field"><label>Objet de la demande d'explication *</label><input class="input" name="motif" value="<?= e($edit['motif'] ?? '') ?>" required placeholder="Rappelez l'objet de la demande à laquelle vous répondez"></div>
    <?php endif; ?>

    <label style="font-size:13px;font-weight:600;color:var(--ink-dim);margin:14px 0 7px;display:block"><?= $curType==='rapport'?'Contenu':'Message / détails' ?></label>
    <div class="editor-wrap glass">
      <div class="editor-toolbar" id="toolbar">
        <?php
          $fontGroups = [
            'Serif' => ['Times New Roman','Georgia','Cambria','Garamond','Book Antiqua','Baskerville Old Face','Bodoni MT','Bookman Old Style','Constantia','Palatino Linotype','Century','Century Schoolbook','Goudy Old Style','Perpetua','Rockwell'],
            'Sans Serif' => ['Arial','Aptos','Calibri','Verdana','Tahoma','Trebuchet MS','Segoe UI','Franklin Gothic Medium','Gill Sans MT','Century Gothic','Corbel','Candara','Bahnschrift','Leelawadee UI','Yu Gothic UI'],
            'Monospace' => ['Consolas','Courier New','Lucida Console'],
            'Script (manuscrites)' => ['Brush Script MT','Edwardian Script ITC','Freestyle Script','French Script MT','Kunstler Script','Lucida Handwriting','Mistral','Monotype Corsiva','Palace Script MT','Pristina','Segoe Script','Vivaldi'],
            'Decoratives / Display' => ['Algerian','Broadway','Chiller','Cooper Black','Curlz MT','Elephant','Forte','Gigi','Haettenschweiler','Harlow Solid Italic','Harrington','Impact','Jokerman','Juice ITC','Magneto','Old English Text MT','Playbill','Ravie','Showcard Gothic','Snap ITC','Stencil','Tw Cen MT'],
            'Symboles' => ['Symbol','Webdings','Wingdings','Wingdings 2','Wingdings 3','Marlett'],
            'Autres' => ['Arial Black','Arial Narrow','Calisto MT','Comic Sans MS','Copperplate Gothic Bold','Copperplate Gothic Light','Ebrima','Footlight MT Light','Imprint MT Shadow','Maiandra GD','MS Gothic','MS Mincho','MS PGothic','MS PMincho','Niagara Engraved','Niagara Solid','OCR A Extended'],
          ];
          $palette = ['#000000','#434343','#666666','#999999','#b7b7b7','#cccccc','#ffffff','#e11d48','#d4a526','#f59e0b','#eab308','#22c55e','#14b8a6','#3b82f6','#6366f1','#a855f7','#ec4899','#7f1d1d','#78350f','#14532d','#164e63','#1e3a8a','#3730a3','#581c87'];
        ?>
        <select onchange="fmt('formatBlock', this.value); this.selectedIndex=0" title="Style de paragraphe">
          <option value="">¶ Style</option><option value="P">Paragraphe</option><option value="H1">Titre 1</option><option value="H2">Titre 2</option><option value="H3">Titre 3</option><option value="BLOCKQUOTE">Citation</option><option value="PRE">Bloc de code</option>
        </select>
        <select onchange="fmt('fontName', this.value); this.selectedIndex=0" title="Police" style="max-width:150px">
          <option value="">Police</option>
          <?php foreach ($fontGroups as $grp => $fonts): ?>
          <optgroup label="<?= e($grp) ?>">
            <?php foreach ($fonts as $ft): ?><option value="<?= e($ft) ?>" style="font-family:'<?= e($ft) ?>'"><?= e($ft) ?></option><?php endforeach; ?>
          </optgroup>
          <?php endforeach; ?>
        </select>
        <select onchange="fmt('fontSize', this.value); this.selectedIndex=0" title="Taille du texte">
          <option value="">Taille</option>
          <option value="1">Minuscule</option><option value="2">Très petit</option><option value="3">Petit (normal)</option><option value="4">Moyen</option><option value="5">Grand</option><option value="6">Très grand</option><option value="7">Énorme</option>
        </select>
        <span class="tb-sep"></span>
        <button type="button" onclick="fmt('bold')" title="Gras"><b>G</b></button>
        <button type="button" onclick="fmt('italic')" title="Italique"><i>I</i></button>
        <button type="button" onclick="fmt('underline')" title="Souligné"><u>S</u></button>
        <button type="button" onclick="fmt('strikeThrough')" title="Barré"><s>B</s></button>
        <button type="button" onclick="fmt('superscript')" title="Exposant">x²</button>
        <button type="button" onclick="fmt('subscript')" title="Indice">x₂</button>
        <span class="tb-sep"></span>
        <details class="tb-colormenu">
          <summary title="Couleur du texte"><span style="border-bottom:3px solid var(--gold);padding:0 2px">A</span></summary>
          <div class="tb-swatches">
            <div class="tb-swrow"><?php foreach ($palette as $col): ?><button type="button" class="tb-sw" style="background:<?= $col ?>" onclick="fmt('foreColor','<?= $col ?>')"></button><?php endforeach; ?></div>
            <label class="tb-custom">Personnalisée <input type="color" oninput="fmt('foreColor', this.value)"></label>
          </div>
        </details>
        <details class="tb-colormenu">
          <summary title="Surlignage">🖍</summary>
          <div class="tb-swatches">
            <div class="tb-swrow"><?php foreach ($palette as $col): ?><button type="button" class="tb-sw" style="background:<?= $col ?>" onclick="fmt('hiliteColor','<?= $col ?>')"></button><?php endforeach; ?></div>
            <label class="tb-custom">Personnalisée <input type="color" oninput="fmt('hiliteColor', this.value)"></label>
          </div>
        </details>
        <span class="tb-sep"></span>
        <button type="button" onclick="fmt('insertUnorderedList')" title="Liste à puces">•≣</button>
        <button type="button" onclick="fmt('insertOrderedList')" title="Liste numérotée">1≣</button>
        <button type="button" onclick="fmt('outdent')" title="Réduire le retrait">⇤</button>
        <button type="button" onclick="fmt('indent')" title="Augmenter le retrait">⇥</button>
        <span class="tb-sep"></span>
        <button type="button" onclick="fmt('justifyLeft')" title="Gauche">⬅</button>
        <button type="button" onclick="fmt('justifyCenter')" title="Centrer">⬌</button>
        <button type="button" onclick="fmt('justifyRight')" title="Droite">➡</button>
        <button type="button" onclick="fmt('justifyFull')" title="Justifier">☰</button>
        <span class="tb-sep"></span>
        <button type="button" onclick="ajouterLien()" title="Lien">🔗</button>
        <button type="button" onclick="fmt('removeFormat')" title="Effacer la mise en forme">⌫</button>
        <button type="button" onclick="fmt('undo')" title="Annuler">↶</button>
        <button type="button" onclick="fmt('redo')" title="Rétablir">↷</button>
      </div>
      <div class="editor-area" id="editor" contenteditable="true"><?= $edit['contenu'] ?? ('<p>'.e($info[4]).'</p>') ?></div>
    </div>
    <input type="hidden" name="contenu" id="contenu">

    <div style="display:flex;gap:10px;margin-top:18px;flex-wrap:wrap">
      <button class="btn btn-gold" name="envoyer_admin" value="1" onclick="syncEditor()">📤 Envoyer à l'administrateur (PDF)</button>
      <button class="btn btn-glass" name="enregistrer" value="1" onclick="syncEditor()">💾 Enregistrer le brouillon</button>
      <a class="btn btn-glass" href="rapports.php">Annuler</a>
    </div>
  </form>
</div>
<script>
(function(){
  const editor = document.getElementById('editor');
  const hidden = document.getElementById('contenu');
  let savedRange = null;
  function saveSel(){
    const s = window.getSelection();
    if (s && s.rangeCount) {
      const r = s.getRangeAt(0);
      if (editor.contains(r.commonAncestorContainer)) savedRange = r.cloneRange();
    }
  }
  function restoreSel(){
    if (!savedRange) return false;
    const s = window.getSelection(); s.removeAllRanges(); s.addRange(savedRange); return true;
  }
  document.addEventListener('selectionchange', saveSel);
  editor.addEventListener('keyup', saveSel);
  editor.addEventListener('mouseup', saveSel);
  try { document.execCommand('styleWithCSS', false, false); } catch(e){}
  window.fmt = function(cmd, val){
    editor.focus(); restoreSel();
    document.execCommand(cmd, false, (val===undefined || val==='') ? null : val);
    saveSel(); syncEditor();
  };
  window.ajouterLien = function(){ const u = prompt('Adresse du lien (https://…)'); if(u) fmt('createLink', u); };
  window.syncEditor = function(){ hidden.value = editor.innerHTML; };
  editor.addEventListener('input', syncEditor); syncEditor();
  document.querySelectorAll('#toolbar button, #toolbar summary, #toolbar label').forEach(function(b){
    b.addEventListener('mousedown', function(e){ e.preventDefault(); });
  });
  document.querySelectorAll('.tb-colormenu .tb-sw, .tb-colormenu .tb-custom input').forEach(function(el){
    el.addEventListener('click', function(){ const d = el.closest('details'); if(d) setTimeout(function(){ d.open=false; }, 120); });
  });
})();
</script>

<?php else: ?>
<?php if (!$admin): ?>
<div class="panel glass">
  <h2>📋 Rédiger un document</h2>
  <p style="color:var(--ink-faint);font-size:13.5px;margin:-6px 0 14px">Choisissez le type de document. Chaque document est rédigé avec l'éditeur complet, puis envoyé à l'administrateur (une copie reste dans votre espace).</p>
  <div class="type-grid">
    <?php foreach ($TYPES as $k=>$info): ?>
    <a class="type-card" href="rapports.php?edit=new&type=<?= $k ?>">
      <span class="type-ic"><?= $info[1] ?></span>
      <span class="type-lb"><?= e($info[0]) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="panel glass">
  <h2>🗂️ Mes documents (<?= count($rapports) ?>)</h2>
  <?php if (!$rapports): ?>
    <div style="text-align:center;padding:30px;color:var(--ink-faint)">Aucun document pour l'instant.</div>
  <?php else:
    require_once __DIR__ . '/includes/rangement.php';
    $arbreR = rangement_par_mois($rapports, 'date_rapport');
  ?>
  <div class="rng-tree">
    <?php foreach ($arbreR as $annee => $mois): $nbA=0; foreach($mois as $ds) $nbA+=count($ds); ?>
    <details class="rng-annee" open>
      <summary><?= $annee ?><span class="cnt"><?= $nbA ?> doc<?= $nbA>1?'s':'' ?></span></summary>
      <?php foreach ($mois as $m => $docsM): ?>
      <details class="rng-mois" open>
        <summary><?= rangement_mois_fr((int)$m) ?><span class="cnt"><?= count($docsM) ?></span></summary>
        <div class="rng-docs">
          <?php foreach ($docsM as $r): $info = demande_type_info($r['type'] ?? 'rapport'); ?>
          <div class="rng-doc">
            <span class="num"><?= e($r['numero']) ?></span>
            <span class="badge <?= $info[5] ?>" style="font-size:11px"><?= $info[1] ?> <?= e($info[0]) ?></span>
            <span class="dt"><?= e($r['titre']) ?> · <?= date('d/m/Y', strtotime($r['date_rapport'])) ?></span>
            <span class="acts">
              <?php if ($r['statut']!=='envoye'): ?>
                <span class="badge badge-gold" style="font-size:11px">Brouillon</span>
              <?php elseif (demande_decidable($r['type']) && $r['decision']==='accepte'): ?>
                <span class="badge badge-teal" style="font-size:11px">✅ Acceptée</span>
              <?php elseif (demande_decidable($r['type']) && $r['decision']==='refuse'): ?>
                <span class="badge badge-danger" style="font-size:11px">❌ Refusée</span>
              <?php elseif (demande_decidable($r['type'])): ?>
                <span class="badge badge-gold" style="font-size:11px">⏳ En attente</span>
              <?php else: ?>
                <span class="badge badge-teal" style="font-size:11px">Envoyé</span>
              <?php endif; ?>
              <?php if ($r['statut']==='envoye'): ?>
              <a class="btn btn-glass btn-sm" href="rapport_print.php?id=<?= $r['id'] ?>" target="_blank" title="Voir / Imprimer">📄</a>
              <?php endif; ?>
              <?php if ($r['statut']==='brouillon'): ?>
              <a class="btn btn-glass btn-sm" href="rapports.php?edit=<?= $r['id'] ?>" title="Modifier le brouillon">✏️</a>
              <form method="post" data-confirm="Supprimer ce brouillon ?" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><button class="btn btn-danger btn-sm" name="supprimer" value="<?= $r['id'] ?>">✕</button></form>
              <?php elseif (demande_decidable($r['type']) && $r['decision']==='refuse'): ?>
              <form method="post" data-confirm="Supprimer cette demande refusée ?" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><button class="btn btn-danger btn-sm" name="supprimer" value="<?= $r['id'] ?>" title="Supprimer">🗑️</button></form>
              <?php endif; ?>
            </span>
          </div>
          <?php endforeach; ?>
        </div>
      </details>
      <?php endforeach; ?>
    </details>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php else: /* ===== ADMIN : boîte de réception ===== */ ?>
<div class="panel glass">
  <h2>📥 Rapports & demandes reçus (<?= count($rapports) ?>)
    <form method="get" style="margin-left:auto">
      <select class="input" name="t" onchange="this.form.submit()" style="padding:8px 12px">
        <option value="">Tous les types</option>
        <?php foreach ($TYPES as $k=>$info): ?><option value="<?= $k ?>" <?= ($_GET['t']??'')===$k?'selected':'' ?>><?= $info[1] ?> <?= e($info[0]) ?></option><?php endforeach; ?>
      </select>
    </form>
  </h2>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>N°</th><th>Type</th><th>Employé</th><th>Objet</th><th>Envoyé le</th><th>Décision</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        <?php foreach ($rapports as $r): $info = demande_type_info($r['type'] ?? 'rapport'); ?>
        <tr>
          <td><strong><?= e($r['numero']) ?></strong></td>
          <td><span class="badge <?= $info[5] ?>"><?= $info[1] ?> <?= e($info[0]) ?></span></td>
          <td><?= e($r['auteur'] ?: 'Ancien employé') ?></td>
          <td><?= e($r['titre']) ?><?php $p=fmt_periode($r); if($p): ?><br><small style="color:var(--ink-faint)"><?= $p ?><?= $r['hopital']?' · '.e($r['hopital']):'' ?></small><?php endif; ?></td>
          <td><?= $r['envoye_at'] ? date('d/m/Y H:i', strtotime($r['envoye_at'])) : '—' ?></td>
          <td>
            <?php if (!demande_decidable($r['type'])): ?>
              <span style="color:var(--ink-faint);font-size:12px">—</span>
            <?php else: ?>
              <?php if ($r['decision']==='accepte'): ?><div style="margin-bottom:5px"><span class="badge badge-teal">✅ Acceptée</span></div>
              <?php elseif ($r['decision']==='refuse'): ?><div style="margin-bottom:5px"><span class="badge badge-danger">❌ Refusée</span></div>
              <?php else: ?><div style="margin-bottom:5px"><span class="badge badge-gold">⏳ En attente</span></div><?php endif; ?>
              <div class="decision-btns">
                <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="rid" value="<?= $r['id'] ?>"><button class="btn btn-sm decision-ok" name="decision" value="accepte" title="Accepter">✅</button></form>
                <details style="display:inline-block;position:relative">
                  <summary class="btn btn-sm decision-no" title="Refuser">❌</summary>
                  <form method="post" class="glass" style="position:absolute;right:0;top:34px;z-index:10;padding:10px;border-radius:12px;width:210px;text-align:left">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="rid" value="<?= $r['id'] ?>">
                    <input class="input" name="decision_motif" placeholder="Motif du refus (facultatif)" style="font-size:12px;margin-bottom:6px" value="<?= e($r['decision_motif'] ?? '') ?>">
                    <button class="btn btn-danger btn-sm" name="decision" value="refuse" style="width:100%">Confirmer le refus</button>
                  </form>
                </details>
              </div>
            <?php endif; ?>
          </td>
          <td>
            <div class="td-actions">
              <a class="btn btn-glass btn-sm" href="rapport_print.php?id=<?= $r['id'] ?>" target="_blank" title="Voir">📄</a>
              
              <a class="btn btn-glass btn-sm" href="rapport_print.php?id=<?= $r['id'] ?>&auth=1" target="_blank" title="Document authentifiable (QR + signature)">🔐</a>
              <form method="post" data-confirm="Supprimer ?"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><button class="btn btn-danger btn-sm" name="supprimer" value="<?= $r['id'] ?>">✕</button></form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rapports): ?><tr><td colspan="7" style="text-align:center;padding:30px;color:var(--ink-faint)">Aucun document reçu.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>
<?php admin_footer(); ?>
