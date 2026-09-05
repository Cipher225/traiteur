<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/icones.php';
$me = (int)$_SESSION['admin_id'];
$COFFRE = __DIR__ . '/../uploads/coffre';
if (!is_dir($COFFRE)) @mkdir($COFFRE, 0775, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Dossiers
    if (isset($_POST['ajouter_dossier'])) {
        $nom = trim($_POST['nom'] ?? '');
        if ($nom !== '') { $pdo->prepare('INSERT INTO coffre_dossiers (nom, icone, ordre) VALUES (?,?,?)')
            ->execute([mb_substr($nom,0,120), mb_substr(trim($_POST['icone'] ?? '📁'),0,10) ?: '📁', 99]); flash('Dossier créé.'); }
        header('Location: coffre.php'); exit;
    }
    if (isset($_POST['renommer_dossier'])) {
        $pdo->prepare('UPDATE coffre_dossiers SET nom=?, icone=? WHERE id=?')
            ->execute([mb_substr(trim($_POST['nom'] ?? ''),0,120), mb_substr(trim($_POST['icone'] ?? '📁'),0,10) ?: '📁', (int)$_POST['renommer_dossier']]);
        flash('Dossier renommé.'); header('Location: coffre.php?d='.(int)$_POST['renommer_dossier']); exit;
    }
    if (isset($_POST['supprimer_dossier'])) {
        $did = (int)$_POST['supprimer_dossier'];
        // supprimer les fichiers physiques du dossier
        $docs = $pdo->prepare('SELECT fichier FROM coffre_documents WHERE dossier_id=?'); $docs->execute([$did]);
        foreach ($docs as $d) @unlink($COFFRE.'/'.$d['fichier']);
        $pdo->prepare('DELETE FROM coffre_documents WHERE dossier_id=?')->execute([$did]);
        $pdo->prepare('DELETE FROM coffre_dossiers WHERE id=?')->execute([$did]);
        flash('Dossier et son contenu supprimés.'); header('Location: coffre.php'); exit;
    }
    // Documents
    if (isset($_POST['supprimer_doc'])) {
        $doc = $pdo->prepare('SELECT fichier, dossier_id FROM coffre_documents WHERE id=?'); $doc->execute([(int)$_POST['supprimer_doc']]); $doc = $doc->fetch();
        if ($doc) { @unlink($COFFRE.'/'.$doc['fichier']); $pdo->prepare('DELETE FROM coffre_documents WHERE id=?')->execute([(int)$_POST['supprimer_doc']]); }
        flash('Document supprimé.'); header('Location: coffre.php?d='.(int)($doc['dossier_id'] ?? 0)); exit;
    }
    if (isset($_POST['uploader'])) {
        $titre = trim($_POST['titre'] ?? '');
        $did = (int)($_POST['dossier_id'] ?? 0) ?: null;
        $up = !empty($_FILES['fichier']['name']) ? upload_coffre($_FILES['fichier'], $COFFRE) : null;
        if (!$up) { flash('Fichier manquant ou non autorisé (30 Mo max).', 'error'); header('Location: coffre.php?d='.(int)($did ?? 0)); exit; }
        if ($titre === '') $titre = $up['nom'];
        $pdo->prepare('INSERT INTO coffre_documents (dossier_id, titre, description, fichier, fichier_nom, taille, uploaded_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([$did, mb_substr($titre,0,200), mb_substr(trim($_POST['description'] ?? ''),0,1000), $up['fichier'], $up['nom'], $up['taille'], $me]);
        flash('Document ajouté au coffre. 🔐'); header('Location: coffre.php?d='.(int)($did ?? 0)); exit;
    }
}

$dossiers = $pdo->query("SELECT d.*, (SELECT COUNT(*) FROM coffre_documents WHERE dossier_id=d.id) AS n FROM coffre_dossiers d ORDER BY d.ordre, d.id")->fetchAll();
$totalDocs = (int)$pdo->query("SELECT COUNT(*) FROM coffre_documents")->fetchColumn();
$totalTaille = (int)$pdo->query("SELECT COALESCE(SUM(taille),0) FROM coffre_documents")->fetchColumn();

$q = trim($_GET['q'] ?? '');
$dsel = (int)($_GET['d'] ?? 0);
if ($q !== '') {
    $st = $pdo->prepare("SELECT doc.*, dos.nom AS dossier_nom, dos.icone AS dossier_icone FROM coffre_documents doc LEFT JOIN coffre_dossiers dos ON dos.id=doc.dossier_id WHERE doc.titre LIKE ? OR doc.description LIKE ? OR doc.fichier_nom LIKE ? ORDER BY doc.created_at DESC");
    $st->execute(["%$q%","%$q%","%$q%"]); $documents = $st->fetchAll(); $dsel = 0;
} elseif ($dsel) {
    $st = $pdo->prepare("SELECT doc.*, dos.nom AS dossier_nom, dos.icone AS dossier_icone FROM coffre_documents doc LEFT JOIN coffre_dossiers dos ON dos.id=doc.dossier_id WHERE doc.dossier_id=? ORDER BY doc.created_at DESC");
    $st->execute([$dsel]); $documents = $st->fetchAll();
} else {
    $st = $pdo->query("SELECT doc.*, dos.nom AS dossier_nom, dos.icone AS dossier_icone FROM coffre_documents doc LEFT JOIN coffre_dossiers dos ON dos.id=doc.dossier_id ORDER BY doc.created_at DESC LIMIT 50");
    $documents = $st->fetchAll();
}
$dossierActif = null; foreach ($dossiers as $d) if ($d['id']==$dsel) $dossierActif = $d;

// ===== Dossiers système : détection automatique des documents de l'application =====
/* Le coffre ne montre QUE les documents qu'un administrateur a déjà authentifiés
   (présents dans la table documents_auth). */
$sysDefs = [
    'factures'  => ['🧾','Factures',            "SELECT f.id, f.numero, f.date_emission AS d, c.nom AS client, c.entreprise, c.type_client FROM factures f JOIN documents_auth a ON a.type='facture' AND a.doc_id=f.id LEFT JOIN clients c ON c.id=f.client_id WHERE f.type='facture' ORDER BY f.date_emission DESC, f.id DESC", 'pdf.php?type=facture&id='],
    'proformas' => ['📋','Proformas (devis)',    "SELECT f.id, f.numero, f.date_emission AS d, c.nom AS client, c.entreprise, c.type_client FROM factures f JOIN documents_auth a ON a.type='proforma' AND a.doc_id=f.id LEFT JOIN clients c ON c.id=f.client_id WHERE f.type='proforma' ORDER BY f.date_emission DESC, f.id DESC", 'pdf.php?type=proforma&id='],
    'paiements' => ['💳','Reçus de paiement',  "SELECT r.id, r.numero, r.date_paiement AS d, c.nom AS client, c.entreprise, c.type_client FROM recus r JOIN paiements p ON p.recu_id = r.id AND p.statut='paye' JOIN documents_auth a ON a.type='recu' AND a.doc_id=r.id LEFT JOIN clients c ON c.id=r.client_id ORDER BY r.date_paiement DESC, r.id DESC", 'pdf.php?type=recu&id='],
    'bs'        => ['📤','Sorties',       "SELECT r.id, r.numero, r.date_paiement AS d, c.nom AS client, c.entreprise, c.type_client FROM recus r JOIN documents_auth a ON a.type='recu' AND a.doc_id=r.id LEFT JOIN clients c ON c.id=r.client_id WHERE r.type='sortie' ORDER BY r.date_paiement DESC, r.id DESC", 'pdf.php?type=recu&id='],
    'be'        => ['📥','Entrées',        "SELECT r.id, r.numero, r.date_paiement AS d, c.nom AS client, c.entreprise, c.type_client FROM recus r JOIN documents_auth a ON a.type='recu' AND a.doc_id=r.id LEFT JOIN clients c ON c.id=r.client_id WHERE r.type='entree' AND NOT EXISTS (SELECT 1 FROM paiements p WHERE p.recu_id=r.id AND p.statut='paye') ORDER BY r.date_paiement DESC, r.id DESC", 'pdf.php?type=recu&id='],
    'fiches'    => ['📄','Bulletins de paie',     "SELECT p.id, p.numero, CONCAT(p.periode,'-01') AS d FROM fiches_paie p JOIN documents_auth a ON a.type='fiche' AND a.doc_id=p.id ORDER BY p.periode DESC, p.id DESC", 'pdf.php?type=fiche&id='],
    'rapports'  => ['📝','Rapports & demandes',   "SELECT r.id, r.numero, r.date_rapport AS d FROM rapports r JOIN documents_auth a ON a.doc_id=r.id AND a.type=r.type WHERE r.statut='envoye' ORDER BY r.date_rapport DESC, r.id DESC", 'rapport_pdf.php?id='],
];
$sysCounts = [];
foreach ($sysDefs as $k=>$def) {
    try { $sysCounts[$k] = (int)$pdo->query('SELECT COUNT(*) FROM (' . $def[2] . ') x')->fetchColumn(); }
    catch (\Throwable $e) { $sysCounts[$k] = 0; }
}
$sysSel = $_GET['sys'] ?? '';
$sysDocs = [];
if ($sysSel !== '' && isset($sysDefs[$sysSel])) {
    try { $sysDocs = $pdo->query($sysDefs[$sysSel][2])->fetchAll(); } catch (\Throwable $e) { $sysDocs = []; }
    $dsel = 0; $q = '';
}

function doc_icone($nom) {
    $ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
    $map = ['pdf'=>'📕','doc'=>'📘','docx'=>'📘','odt'=>'📘','rtf'=>'📘','txt'=>'📄','md'=>'📄',
        'xls'=>'📗','xlsx'=>'📗','ods'=>'📗','csv'=>'📊','tsv'=>'📊',
        'ppt'=>'📙','pptx'=>'📙','odp'=>'📙',
        'jpg'=>'🖼️','jpeg'=>'🖼️','png'=>'🖼️','gif'=>'🖼️','webp'=>'🖼️','bmp'=>'🖼️','svg'=>'🖼️','heic'=>'🖼️',
        'zip'=>'🗜️','rar'=>'🗜️','7z'=>'🗜️','tar'=>'🗜️','gz'=>'🗜️',
        'mp3'=>'🎵','wav'=>'🎵','mp4'=>'🎬','mov'=>'🎬','avi'=>'🎬','webm'=>'🎬'];
    return $map[$ext] ?? '📎';
}

admin_header('Coffre à documents', 'coffre', $pdo, $settings);
$csrf = csrf_token();
?>
<div class="panel glass tone-blue" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
  <div style="font-size:34px">🗄️</div>
  <div style="flex:1;min-width:220px">
    <h2 style="border:0;margin:0;padding:0">Coffre à documents de l'entreprise</h2>
    <p style="color:var(--ink-dim);margin:4px 0 0;font-size:13.5px">Rangez et retrouvez tous vos documents : juridique, RH, comptabilité, contrats… <strong><?= $totalDocs ?></strong> document<?= $totalDocs>1?'s':'' ?> · <?= taille_lisible($totalTaille) ?></p>
  </div>
  <form method="get" style="display:flex;gap:8px">
    <input class="input" name="q" value="<?= e($q) ?>" placeholder="Rechercher un document…" style="width:220px">
    <button class="btn btn-glass btn-sm">🔍</button>
  </form>
</div>

<div class="coffre-layout">
  <aside class="coffre-folders panel glass">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
      <strong style="font-size:14px">📁 Dossiers</strong>
    </div>
    <a class="coffre-folder <?= (!$dsel && $q==='' && $sysSel==='')?'active':'' ?>" href="coffre.php">🗂️ <span class="cf-nom">Tous les récents</span></a>
    <?php foreach ($dossiers as $d): ?>
    <a class="coffre-folder <?= $dsel==$d['id']?'active':'' ?>" href="coffre.php?d=<?= $d['id'] ?>">
      <?= e($d['icone']) ?> <span class="cf-nom"><?= e($d['nom']) ?></span><span class="cf-count"><?= $d['n'] ?></span>
    </a>
    <?php endforeach; ?>

    <div style="margin:14px 0 6px;font-size:11px;letter-spacing:.5px;color:var(--ink-faint);text-transform:uppercase;font-weight:700">📥 Documents de l'application</div>
    <?php foreach ($sysDefs as $k=>$def): ?>
    <a class="coffre-folder <?= $sysSel===$k?'active':'' ?>" href="coffre.php?sys=<?= $k ?>">
      <?= $def[0] ?> <span class="cf-nom"><?= e($def[1]) ?></span><span class="cf-count"><?= $sysCounts[$k] ?></span>
    </a>
    <?php endforeach; ?>
    <details class="coffre-newfolder">
      <summary class="btn btn-glass btn-sm" style="margin-top:10px;width:100%">➕ Nouveau dossier</summary>
      <form method="post" style="margin-top:10px;display:flex;gap:6px">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="ajouter_dossier" value="1">
        <?= champ_icone('icone', '', 'Icône', '📁') ?>
        <input class="input" name="nom" placeholder="Nom du dossier" required>
        <button class="btn btn-gold btn-sm">OK</button>
      </form>
    </details>
  </aside>

  <section class="coffre-main">
    <?php if ($sysSel !== '' && isset($sysDefs[$sysSel])): $def = $sysDefs[$sysSel];
      require_once __DIR__ . '/includes/rangement.php';
      // ces types portent un client : ils sont rangés année > mois > client
      $aClient = in_array($sysSel, ['factures','proformas','paiements','be','bs'], true);
      /* Type attendu par le générateur PDF, déduit de la rubrique consultée */
      $typePdf = ['factures' => 'facture', 'proformas' => 'proforma', 'paiements' => 'recu',
                  'be' => 'recu', 'bs' => 'recu', 'fiches' => 'fiche'][$sysSel] ?? 'facture';
    ?>
    <div class="panel glass">
      <h2><?= $def[0] ?> <?= e($def[1]) ?> — rangés automatiquement (<?= count($sysDocs) ?>)</h2>
      <p style="color:var(--ink-faint);font-size:13px;margin:-6px 0 14px">Ces documents authentifiés sont rangés par année<?= $aClient ? ', mois puis client' : ' et par mois' ?>. Cliquez pour consulter ou télécharger.</p>
      <?php if (!$sysDocs): ?>
        <div style="text-align:center;color:var(--ink-faint);padding:34px">Aucun document de ce type pour l'instant.</div>
      <?php else: ?>
      <div class="rng-tree">
        <?php
        if ($aClient) { $arbreC = rangement_arbre($sysDocs, 'd', '_rangement'); }
        else { $arbreC = rangement_par_mois($sysDocs, 'd'); }
        foreach ($arbreC as $annee => $mois):
          $nbA = 0;
          if ($aClient) { foreach($mois as $cl) foreach($cl as $ds) $nbA += count($ds); }
          else { foreach($mois as $ds) $nbA += count($ds); }
        ?>
        <details class="rng-annee" open>
          <summary><?= $annee ?><span class="cnt"><?= $nbA ?> doc<?= $nbA>1?'s':'' ?></span></summary>
          <?php foreach ($mois as $m => $contenu):
            $nbM = $aClient ? array_sum(array_map('count', $contenu)) : count($contenu); ?>
          <details class="rng-mois" open>
            <summary><?= rangement_mois_fr((int)$m) ?><span class="cnt"><?= $nbM ?></span></summary>
            <?php if ($aClient): foreach ($contenu as $client => $docs):
              $estEnt = (($docs[0]['type_client'] ?? '') === 'entreprise') || (trim((string)($docs[0]['entreprise'] ?? '')) !== '' && ($docs[0]['entreprise'] === $client));
            ?>
            <details class="rng-client" open>
              <summary><?= $estEnt ? '🏢' : '👤' ?> <?= e($client) ?><span class="cnt"><?= count($docs) ?></span></summary>
              <div class="rng-docs">
                <?php foreach ($docs as $doc): ?>
                <div class="rng-doc">
                  <span class="num"><?= e($doc['numero']) ?></span>
                  <span class="dt"><?= $doc['d'] ? date('d/m/Y', strtotime($doc['d'])) : '' ?></span>
                  <span class="acts"><a class="btn btn-glass btn-sm" href="<?= e($def[3].$doc['id']) ?>" target="_blank" title="Consulter">📄</a>
                    <a class="btn btn-gold btn-sm" href="pdf.php?type=<?= e($typePdf) ?>&id=<?= (int)$doc['id'] ?>&dl=1" title="Télécharger en PDF">⬇️</a></span>
                </div>
                <?php endforeach; ?>
              </div>
            </details>
            <?php endforeach; else: ?>
            <div class="rng-docs">
              <?php foreach ($contenu as $doc): ?>
              <div class="rng-doc">
                <span class="num"><?= e($doc['numero']) ?></span>
                <span class="dt"><?= $doc['d'] ? date('d/m/Y', strtotime($doc['d'])) : '' ?></span>
                <span class="acts"><a class="btn btn-glass btn-sm" href="<?= e($def[3].$doc['id']) ?>" target="_blank" title="Consulter">📄</a>
                    <a class="btn btn-gold btn-sm" href="pdf.php?type=<?= e($typePdf) ?>&id=<?= (int)$doc['id'] ?>&dl=1" title="Télécharger en PDF">⬇️</a></span>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </details>
          <?php endforeach; ?>
        </details>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="panel glass">
      <h2>⬆️ Ajouter un document<?= $dossierActif ? ' — '.e($dossierActif['icone']).' '.e($dossierActif['nom']) : '' ?></h2>
      <form method="post" enctype="multipart/form-data" class="form-grid">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="uploader" value="1">
        <div class="field"><label>Titre du document</label><input class="input" name="titre" placeholder="(facultatif — sinon le nom du fichier)"></div>
        <div class="field"><label>Dossier</label>
          <select class="input" name="dossier_id">
            <option value="">— Sans dossier —</option>
            <?php foreach ($dossiers as $d): ?><option value="<?= $d['id'] ?>" <?= $dsel==$d['id']?'selected':'' ?>><?= e($d['icone']) ?> <?= e($d['nom']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field full"><label>Description (facultatif)</label><input class="input" name="description" placeholder="Note, référence, date d'échéance…"></div>
        <div class="field full">
          <label>Fichier * <span style="color:var(--ink-faint);font-weight:400">(PDF, Word, Excel, PowerPoint, images, archives, audio/vidéo… 30 Mo max)</span></label>
          <input class="input" type="file" name="fichier" required>
        </div>
        <div class="full"><button class="btn btn-gold">🔐 Ranger dans le coffre</button></div>
      </form>
    </div>

    <div class="panel glass">
      <h2>
        <?php if ($q!==''): ?>🔍 Résultats pour « <?= e($q) ?> » (<?= count($documents) ?>)
        <?php elseif ($dossierActif): ?><?= e($dossierActif['icone']) ?> <?= e($dossierActif['nom']) ?> (<?= count($documents) ?>)
        <?php else: ?>🕐 Documents récents<?php endif; ?>
        <?php if ($dossierActif): ?>
        <span style="margin-left:auto;display:flex;gap:6px">
          <details style="position:relative"><summary class="btn btn-glass btn-sm">✏️ Renommer</summary>
            <form method="post" class="glass" style="position:absolute;right:0;top:38px;z-index:5;padding:12px;border-radius:12px;display:flex;gap:6px;width:280px">
              <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="renommer_dossier" value="<?= $dossierActif['id'] ?>">
              <?= champ_icone('icone', $dossierActif['icone'], 'Icône', '📁') ?>
              <input class="input" name="nom" value="<?= e($dossierActif['nom']) ?>" required>
              <button class="btn btn-gold btn-sm">OK</button>
            </form>
          </details>
          <form method="post" data-confirm="Supprimer ce dossier et TOUS ses documents ?"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="btn btn-danger btn-sm" name="supprimer_dossier" value="<?= $dossierActif['id'] ?>">🗑️</button></form>
        </span>
        <?php endif; ?>
      </h2>
      <div class="doc-grid">
        <?php foreach ($documents as $doc): ?>
        <div class="doc-card">
          <div class="doc-ic"><?= doc_icone($doc['fichier_nom']) ?></div>
          <div class="doc-info">
            <strong><?= e($doc['titre']) ?></strong>
            <?php if (trim((string)$doc['description'])!==''): ?><p class="doc-desc"><?= e($doc['description']) ?></p><?php endif; ?>
            <div class="doc-meta">
              <?php if ($q!=='' && $doc['dossier_nom']): ?><?= e($doc['dossier_icone']) ?> <?= e($doc['dossier_nom']) ?> · <?php endif; ?>
              <?= taille_lisible((int)$doc['taille']) ?> · <?= date('d/m/Y', strtotime($doc['created_at'])) ?>
            </div>
          </div>
          <div class="doc-act">
            <a class="btn btn-glass btn-sm" href="../uploads/coffre/<?= e($doc['fichier']) ?>" target="_blank" download="<?= e($doc['fichier_nom']) ?>" title="Télécharger">⬇️</a>
            <form method="post" data-confirm="Supprimer ce document ?"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="btn btn-danger btn-sm" name="supprimer_doc" value="<?= $doc['id'] ?>">✕</button></form>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$documents): ?><div style="text-align:center;color:var(--ink-faint);padding:34px;grid-column:1/-1"><?= $q!==''?'Aucun document trouvé.':'Ce dossier est vide. Ajoutez votre premier document ci-dessus.' ?></div><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </section>
</div>
<?php admin_footer(); ?>
