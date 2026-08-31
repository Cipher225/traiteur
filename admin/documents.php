<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';

/* ============================================================================
   TRAITEMENT DE TEXTE
   Rédaction libre de tout document d'entreprise (contrats, attestations,
   courriers, notes internes, procédures…) sur une vraie page A4, avec
   impression PDF reprenant l'en-tête et le pied de page de la société.
   ============================================================================ */

$CATEGORIES = ['Document', 'Contrat', 'Attestation', 'Courrier', 'Note interne',
               'Procédure', 'Compte rendu', 'Convocation', 'Décharge', 'Rapport'];

/* Modèles de départ : structure prête à remplir */
function modeles_documents(): array {
    return [
        'vierge' => ['📄 Page vierge', 'Document', ''],
        'courrier' => ['✉️ Courrier', 'Courrier',
            '<p style="text-align:right">Abidjan, le [date]</p><p><br></p>'
            . '<p><strong>À l\'attention de</strong><br>[Nom du destinataire]<br>[Adresse]</p><p><br></p>'
            . '<p><strong>Objet :</strong> [objet du courrier]</p><p><br></p>'
            . '<p>Madame, Monsieur,</p><p><br></p><p>[Corps du courrier]</p><p><br></p>'
            . '<p>Veuillez agréer, Madame, Monsieur, l\'expression de nos salutations distinguées.</p>'],
        'contrat' => ['📜 Contrat de prestation', 'Contrat',
            '<h1 style="text-align:center">CONTRAT DE PRESTATION DE SERVICE</h1><p><br></p>'
            . '<p><strong>ENTRE LES SOUSSIGNÉS :</strong></p>'
            . '<p>[Votre société], ci-après dénommée « le Prestataire »,</p><p>d\'une part,</p><p><br></p>'
            . '<p>ET [Nom du client], ci-après dénommé « le Client »,</p><p>d\'autre part.</p><p><br></p>'
            . '<h2>Article 1 — Objet</h2><p>[Description de la prestation]</p>'
            . '<h2>Article 2 — Date et lieu</h2><p>[Date et lieu de la prestation]</p>'
            . '<h2>Article 3 — Prix et modalités de paiement</h2><p>[Montant, acompte, solde]</p>'
            . '<h2>Article 4 — Obligations du Prestataire</h2><p>[…]</p>'
            . '<h2>Article 5 — Obligations du Client</h2><p>[…]</p>'
            . '<h2>Article 6 — Annulation</h2><p>[…]</p>'
            . '<p><br></p><p>Fait à Abidjan, le [date], en deux exemplaires originaux.</p>'],
        'attestation' => ['🏅 Attestation', 'Attestation',
            '<h1 style="text-align:center">ATTESTATION</h1><p><br></p>'
            . '<p>Je soussigné(e), [Nom et qualité du signataire], atteste par la présente que :</p><p><br></p>'
            . '<p>[Objet de l\'attestation]</p><p><br></p>'
            . '<p>Cette attestation est délivrée à l\'intéressé(e) pour servir et valoir ce que de droit.</p>'
            . '<p><br></p><p>Fait à Abidjan, le [date].</p>'],
        'note' => ['📌 Note interne', 'Note interne',
            '<h1 style="text-align:center">NOTE DE SERVICE</h1><p><br></p>'
            . '<p><strong>De :</strong> La Direction<br><strong>À :</strong> [Destinataires]<br>'
            . '<strong>Date :</strong> [date]<br><strong>Objet :</strong> [objet]</p><p><br></p>'
            . '<p>[Contenu de la note]</p><p><br></p><p>Merci de votre implication.</p>'],
        'procedure' => ['⚙️ Procédure', 'Procédure',
            '<h1 style="text-align:center">PROCÉDURE : [TITRE]</h1><p><br></p>'
            . '<h2>1. Objectif</h2><p>[Pourquoi cette procédure existe]</p>'
            . '<h2>2. Personnes concernées</h2><p>[Qui applique la procédure]</p>'
            . '<h2>3. Déroulement</h2><ol><li>[Étape 1]</li><li>[Étape 2]</li><li>[Étape 3]</li></ol>'
            . '<h2>4. Points de vigilance</h2><ul><li>[…]</li></ul>'],
        'compte_rendu' => ['🗒️ Compte rendu', 'Compte rendu',
            '<h1 style="text-align:center">COMPTE RENDU DE RÉUNION</h1><p><br></p>'
            . '<p><strong>Date :</strong> [date]<br><strong>Lieu :</strong> [lieu]<br>'
            . '<strong>Participants :</strong> [noms]</p><p><br></p>'
            . '<h2>Ordre du jour</h2><ol><li>[Point 1]</li><li>[Point 2]</li></ol>'
            . '<h2>Décisions prises</h2><ul><li>[…]</li></ul>'
            . '<h2>Actions à mener</h2>'
            . '<table><tr><th>Action</th><th>Responsable</th><th>Échéance</th></tr>'
            . '<tr><td>[…]</td><td>[…]</td><td>[…]</td></tr></table>'],
        'decharge' => ['🤝 Décharge', 'Décharge',
            '<h1 style="text-align:center">DÉCHARGE</h1><p><br></p>'
            . '<p>Je soussigné(e), [Nom], reconnais avoir reçu de [Votre société] :</p><p><br></p>'
            . '<p>[Description de ce qui est remis]</p><p><br></p>'
            . '<p>Fait à Abidjan, le [date].</p><p><br></p>'
            . '<p>Signature du bénéficiaire :</p>'],
    ];
}
$MODELES = modeles_documents();

/* Archive une copie FIGEE du document dans le coffre : le fichier reste lisible
   tel quel meme si le document est modifie ou supprime par la suite. */
function archiver_document_coffre(PDO $pdo, array $doc, array $s, ?int $dossier, ?int $par): ?int {
    $dir = __DIR__ . '/../uploads/coffre';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return null;

    $entete = '<div style="border-bottom:2px solid #d4a526;padding-bottom:10px;margin-bottom:18px">'
            . '<div style="font-size:18px;font-weight:bold;color:#0a1f44">' . e($s['nom_entreprise'] ?? '') . '</div>'
            . (trim((string)($s['slogan'] ?? '')) !== ''
               ? '<div style="font-size:11px;color:#b8870f;letter-spacing:1px;text-transform:uppercase">' . e($s['slogan']) . '</div>' : '')
            . '</div>';
    $pied = '<div style="margin-top:26px;border-top:1px solid #d7dde8;padding-top:8px;font-size:11px;color:#6e7685">'
          . 'Copie archivée le ' . date('d/m/Y à H:i') . ' — document « ' . e($doc['titre']) . ' »</div>';

    $html = '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>' . e($doc['titre']) . '</title>'
          . '<style>body{font-family:Georgia,serif;font-size:13px;line-height:1.5;color:#2d3442;max-width:800px;margin:24px auto;padding:0 20px}'
          . 'h1{font-size:19px;color:#0a1f44}h2{font-size:15px;color:#0a1f44}'
          . 'table{width:100%;border-collapse:collapse;margin:10px 0}th,td{border:1px solid #d7dde8;padding:6px 9px;font-size:12px}'
          . 'th{background:#0a1f44;color:#fff}img{max-width:100%}'
          . '.zone-texte{border:1px solid #c8d0de;border-radius:6px;padding:10px 13px;margin:12px 0;background:#fbfcfe}'
          . '.zone-encadree{border-left:4px solid #d4a526;background:#faf7ef;padding:10px 14px;margin:12px 0}'
          . '</style></head><body>' . $entete . $doc['contenu'] . $pied . '</body></html>';

    $nomFichier = 'doc-' . (int)$doc['id'] . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.html';
    if (@file_put_contents($dir . '/' . $nomFichier, $html) === false) return null;

    $titre = mb_substr(($doc['titre'] !== '' ? $doc['titre'] : 'Document'), 0, 200);
    $pdo->prepare('INSERT INTO coffre_documents (dossier_id, titre, description, fichier, fichier_nom, taille, uploaded_by) VALUES (?,?,?,?,?,?,?)')
        ->execute([$dossier, $titre, 'Copie archivée depuis le traitement de texte — ' . $doc['categorie'],
                   $nomFichier, $titre . '.html', strlen($html), $par]);
    return (int)$pdo->lastInsertId();
}

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (isset($_POST['enregistrer'])) {
        $id       = (int)($_POST['id'] ?? 0);
        // Un document terminé ou validé est verrouillé : seul l'administrateur peut encore le corriger
        if ($id > 0 && !is_admin()) {
            $v = $pdo->prepare('SELECT statut FROM documents_texte WHERE id=?');
            $v->execute([$id]);
            if (($v->fetchColumn() ?: 'brouillon') !== 'brouillon') {
                if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'verrouille'=>true]); exit; }
                flash('Ce document est terminé : il ne peut plus être modifié sans l\'accord de la direction.', 'error');
                header('Location: documents.php?edit=' . $id); exit;
            }
        }
        $titre    = mb_substr(trim($_POST['titre'] ?? ''), 0, 200) ?: 'Document sans titre';
        $cat      = in_array($_POST['categorie'] ?? '', $CATEGORIES, true) ? $_POST['categorie'] : 'Document';
        $contenu  = doc_nettoyer_html($_POST['contenu'] ?? '');
        $entete   = isset($_POST['avec_entete']) ? 1 : 0;
        $signat   = isset($_POST['avec_signature']) ? 1 : 0;

        if ($id > 0) {
            $pdo->prepare('UPDATE documents_texte SET titre=?, categorie=?, contenu=?, avec_entete=?, avec_signature=? WHERE id=?')
                ->execute([$titre, $cat, $contenu, $entete, $signat, $id]);
        } else {
            $pdo->prepare('INSERT INTO documents_texte (titre, categorie, contenu, avec_entete, avec_signature, auteur_id) VALUES (?,?,?,?,?,?)')
                ->execute([$titre, $cat, $contenu, $entete, $signat, (int)($_SESSION['admin_id'] ?? 0) ?: null]);
            $id = (int)$pdo->lastInsertId();
        }
        // Enregistrement discret depuis l'éditeur (sans rechargement)
        if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'id'=>$id]); exit; }
        flash('Document enregistré.');
        header('Location: documents.php?edit=' . $id); exit;
    }

    // La suppression est reservee a l'administrateur
    if (isset($_POST['supprimer'])) {
        if (!is_admin()) { flash("Seul un administrateur peut supprimer un document.", 'error'); header('Location: documents.php'); exit; }
        $pdo->prepare('DELETE FROM documents_texte WHERE id=?')->execute([(int)$_POST['supprimer']]);
        journaliser($pdo, 'suppression', 'document', (int)$_POST['supprimer'], 'Document rédigé supprimé');
        flash('Document supprimé.');
        header('Location: documents.php'); exit;
    }

    // L'auteur declare son document termine : il ne pourra plus le modifier
    if (isset($_POST['terminer'])) {
        $id = (int)$_POST['terminer'];
        $pdo->prepare("UPDATE documents_texte SET statut='termine', termine_le=NOW(), termine_par=?, motif_refus='' WHERE id=? AND statut='brouillon'")
            ->execute([(int)($_SESSION['admin_id'] ?? 0) ?: null, $id]);
        flash('Document marqué comme terminé. Il est maintenant verrouillé et soumis à la direction. 🔒');
        header('Location: documents.php?edit=' . $id); exit;
    }

    // L'administrateur renvoie le document en correction
    if (isset($_POST['renvoyer'])) {
        if (!is_admin()) { flash("Action réservée à l'administrateur.", 'error'); header('Location: documents.php'); exit; }
        $id = (int)$_POST['renvoyer'];
        $motif = mb_substr(trim($_POST['motif'] ?? ''), 0, 255);
        $pdo->prepare("UPDATE documents_texte SET statut='brouillon', termine_le=NULL, termine_par=NULL, valide_le=NULL, valide_par=NULL, motif_refus=? WHERE id=?")
            ->execute([$motif, $id]);
        flash('Document renvoyé à son auteur pour correction.');
        header('Location: documents.php?edit=' . $id); exit;
    }

    // L'administrateur valide le document
    if (isset($_POST['valider'])) {
        if (!is_admin()) { flash("Action réservée à l'administrateur.", 'error'); header('Location: documents.php'); exit; }
        $id = (int)$_POST['valider'];
        $pdo->prepare("UPDATE documents_texte SET statut='valide', valide_le=NOW(), valide_par=?, motif_refus='' WHERE id=?")
            ->execute([(int)($_SESSION['admin_id'] ?? 0) ?: null, $id]);
        flash('Document validé. ✅');
        header('Location: documents.php?edit=' . $id); exit;
    }

    // L'administrateur archive une copie figee dans le coffre a documents
    if (isset($_POST['archiver'])) {
        if (!is_admin()) { flash("Action réservée à l'administrateur.", 'error'); header('Location: documents.php'); exit; }
        $id = (int)$_POST['archiver'];
        $st = $pdo->prepare('SELECT * FROM documents_texte WHERE id=?');
        $st->execute([$id]);
        $doc = $st->fetch();
        if ($doc) {
            $dossier = (int)($_POST['dossier_id'] ?? 0) ?: null;
            $res = archiver_document_coffre($pdo, $doc, $settings, $dossier, (int)($_SESSION['admin_id'] ?? 0) ?: null);
            if ($res) {
                $pdo->prepare('UPDATE documents_texte SET coffre_doc_id=? WHERE id=?')->execute([$res, $id]);
                flash('Copie archivée dans le coffre à documents. 🗄️');
            } else {
                flash("L'archivage a échoué : dossier du coffre inaccessible.", 'error');
            }
        }
        header('Location: documents.php?edit=' . $id); exit;
    }

    if (isset($_POST['dupliquer'])) {
        $st = $pdo->prepare('SELECT * FROM documents_texte WHERE id=?');
        $st->execute([(int)$_POST['dupliquer']]);
        if ($d = $st->fetch()) {
            $pdo->prepare('INSERT INTO documents_texte (titre, categorie, contenu, avec_entete, avec_signature, auteur_id) VALUES (?,?,?,?,?,?)')
                ->execute([mb_substr($d['titre'].' (copie)',0,200), $d['categorie'], $d['contenu'],
                           $d['avec_entete'], $d['avec_signature'], (int)($_SESSION['admin_id'] ?? 0) ?: null]);
            flash('Copie créée.');
        }
        header('Location: documents.php'); exit;
    }
}

/* ---------- Chargement ---------- */
$edit = null;
if (isset($_GET['edit'])) {
    if ($_GET['edit'] === 'new') {
        $m = $MODELES[$_GET['modele'] ?? 'vierge'] ?? $MODELES['vierge'];
        $edit = ['id'=>0, 'titre'=>'', 'categorie'=>$m[1], 'contenu'=>$m[2], 'avec_entete'=>1, 'avec_signature'=>0,
                 'statut'=>'brouillon', 'coffre_doc_id'=>null, 'motif_refus'=>'', 'termine_le'=>null, 'valide_le'=>null];
    } else {
        $st = $pdo->prepare('SELECT * FROM documents_texte WHERE id=?');
        $st->execute([(int)$_GET['edit']]);
        $edit = $st->fetch() ?: null;
    }
}

$dossiersCoffre = [];
if (is_admin()) {
    try { $dossiersCoffre = $pdo->query('SELECT id, nom, icone FROM coffre_dossiers ORDER BY ordre, id')->fetchAll(); }
    catch (Throwable $e) { $dossiersCoffre = []; }
}

$recherche = trim($_GET['q'] ?? '');
if ($recherche !== '') {
    $st = $pdo->prepare("SELECT d.*, u.nom AS auteur FROM documents_texte d
                         LEFT JOIN users u ON u.id = d.auteur_id
                         WHERE d.titre LIKE ? OR d.categorie LIKE ? ORDER BY d.updated_at DESC");
    $st->execute(['%'.$recherche.'%', '%'.$recherche.'%']);
} else {
    $st = $pdo->query("SELECT d.*, u.nom AS auteur FROM documents_texte d
                       LEFT JOIN users u ON u.id = d.auteur_id ORDER BY d.updated_at DESC");
}
$documents = $st->fetchAll();

admin_header('Traitement de texte', 'documents', $pdo, $settings);
?>

<?php if ($edit !== null): ?>
<!-- ==================== ÉDITEUR ==================== -->
<?php
$statut     = $edit['statut'] ?? 'brouillon';
$verrouille = ($statut !== 'brouillon') && !is_admin();   // verrouille pour tout le monde sauf l'administrateur
$libStatut  = ['brouillon'=>'Brouillon', 'termine'=>'Terminé — en attente de validation', 'valide'=>'Validé par la direction'][$statut];
?>
<form method="post" id="form-doc">
  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
  <input type="hidden" name="contenu" id="champ-contenu">

  <div class="panel glass" style="margin-bottom:12px">
    <div class="doc-bar-haut">
      <a href="documents.php" class="btn btn-glass btn-sm">‹ Mes documents</a>
      <input class="input doc-titre" name="titre" placeholder="Titre du document"
             value="<?= e($edit['titre']) ?>" maxlength="200">
      <select class="input doc-cat" name="categorie">
        <?php foreach ($CATEGORIES as $c): ?>
        <option <?= $edit['categorie'] === $c ? 'selected' : '' ?>><?= e($c) ?></option>
        <?php endforeach; ?>
      </select>
      <label class="switch"><input type="checkbox" name="avec_entete" <?= $edit['avec_entete'] ? 'checked' : '' ?>> En-tête société</label>
      <label class="switch"><input type="checkbox" name="avec_signature" <?= $edit['avec_signature'] ? 'checked' : '' ?>> Tampon &amp; signature</label>
      <span class="doc-etat" id="doc-etat"></span>
      <?php if (!$verrouille): ?><button class="btn btn-gold btn-sm" name="enregistrer" value="1">💾 Enregistrer</button><?php endif; ?>
      <?php if ((int)$edit['id'] > 0): ?>
      <a class="btn btn-glass btn-sm" href="doc-imprimer.php?id=<?= (int)$edit['id'] ?>" target="_blank">🖨️ Imprimer / PDF</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ((int)$edit['id'] > 0): ?>
  <div class="doc-statut st-<?= $statut ?>">
    <span class="pastille"></span>
    <div class="txt-statut">
      <strong><?= e($libStatut) ?></strong>
      <?php if ($statut === 'termine' && !empty($edit['termine_le'])): ?>
        <span>Verrouillé le <?= date('d/m/Y à H:i', strtotime($edit['termine_le'])) ?></span>
      <?php elseif ($statut === 'valide' && !empty($edit['valide_le'])): ?>
        <span>Validé le <?= date('d/m/Y à H:i', strtotime($edit['valide_le'])) ?></span>
      <?php elseif (trim((string)($edit['motif_refus'] ?? '')) !== ''): ?>
        <span>Renvoyé pour correction : <?= e($edit['motif_refus']) ?></span>
      <?php endif; ?>
      <?php if (!empty($edit['coffre_doc_id'])): ?><span>🗄️ Une copie est archivée dans le coffre</span><?php endif; ?>
    </div>
    <div class="doc-actions">
      <?php if ($statut === 'brouillon'): ?>
        <button class="btn btn-glass btn-sm" name="terminer" value="<?= (int)$edit['id'] ?>"
                onclick="return confirm('Marquer ce document comme terminé ? Vous ne pourrez plus le modifier sans l\'accord de la direction.')">🔒 Terminer</button>
      <?php endif; ?>
      <?php if (is_admin()): ?>
        <?php if ($statut === 'termine'): ?>
          <button class="btn btn-gold btn-sm" name="valider" value="<?= (int)$edit['id'] ?>">✅ Valider</button>
        <?php endif; ?>
        <?php if ($statut === 'termine'): ?>
          <button class="btn btn-glass btn-sm" name="renvoyer" value="<?= (int)$edit['id'] ?>"
                  onclick="var m=prompt('Motif du renvoi (facultatif) :',''); if(m===null) return false; document.getElementById('champ-motif').value=m; return true;">↩️ Renvoyer en correction</button>
          <input type="hidden" name="motif" id="champ-motif" value="">
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($verrouille): ?>
  <div class="doc-verrou">🔒 Ce document est verrouillé. Seule la direction peut le rouvrir pour correction.</div>
  <?php endif; ?>

  <!-- Barre d'outils -->
  <div class="doc-outils" id="doc-outils"<?= $verrouille ? ' style="display:none"' : '' ?>>
    <span class="dgrp">
      <button type="button" class="dbtn" data-cmd="undo" title="Annuler">↶</button>
      <button type="button" class="dbtn" data-cmd="redo" title="Rétablir">↷</button>
    </span>
    <span class="dgrp">
      <select class="dsel" id="sel-bloc" title="Style de paragraphe">
        <option value="p">Normal</option>
        <option value="h1">Titre 1</option>
        <option value="h2">Titre 2</option>
        <option value="h3">Titre 3</option>
        <option value="blockquote">Citation</option>
        <option value="pre">Code</option>
      </select>
      <select class="dsel" id="sel-police" title="Police">
        <option value="">Police</option>
        <option value="Georgia, serif">Georgia</option>
        <option value="'Times New Roman', serif">Times New Roman</option>
        <option value="Arial, sans-serif">Arial</option>
        <option value="'Segoe UI', sans-serif">Segoe UI</option>
        <option value="'Courier New', monospace">Courier New</option>
      </select>
      <select class="dsel" id="sel-taille" title="Taille">
        <option value="">Taille</option>
        <option value="1">8</option><option value="2">10</option>
        <option value="3">12</option><option value="4">14</option>
        <option value="5">18</option><option value="6">24</option><option value="7">36</option>
      </select>
    </span>
    <span class="dgrp">
      <button type="button" class="dbtn" data-cmd="bold" title="Gras"><b>G</b></button>
      <button type="button" class="dbtn" data-cmd="italic" title="Italique"><i>I</i></button>
      <button type="button" class="dbtn" data-cmd="underline" title="Souligné"><u>S</u></button>
      <button type="button" class="dbtn" data-cmd="strikeThrough" title="Barré"><s>B</s></button>
      <label class="dbtn dcol" title="Couleur du texte">A<input type="color" id="col-texte" value="#0a1f44"></label>
      <label class="dbtn dcol" title="Surlignage">🖍<input type="color" id="col-fond" value="#ffe680"></label>
    </span>
    <span class="dgrp">
      <button type="button" class="dbtn" data-cmd="justifyLeft" title="Aligner à gauche">G</button>
      <button type="button" class="dbtn" data-cmd="justifyCenter" title="Centrer">C</button>
      <button type="button" class="dbtn" data-cmd="justifyRight" title="Aligner à droite">D</button>
      <button type="button" class="dbtn" data-cmd="justifyFull" title="Justifier">J</button>
    </span>
    <span class="dgrp">
      <button type="button" class="dbtn" data-cmd="insertUnorderedList" title="Liste à puces">• —</button>
      <button type="button" class="dbtn" data-cmd="insertOrderedList" title="Liste numérotée">1.</button>
      <select class="dsel" id="sel-interligne" title="Interligne">
        <option value="">Interligne</option>
        <option value="1.15">Simple</option>
        <option value="1.45">Normal</option>
        <option value="1.8">1,5 ligne</option>
        <option value="2.3">Double</option>
      </select>
      <button type="button" class="dbtn" data-cmd="outdent" title="Diminuer le retrait">◄|</button>
      <button type="button" class="dbtn" data-cmd="indent" title="Augmenter le retrait">|►</button>
    </span>
    <span class="dgrp">
      <button type="button" class="dbtn" id="btn-tableau" title="Insérer un tableau">Tableau</button>
      <button type="button" class="dbtn" id="btn-image" title="Insérer une image">🖼️</button>
      <button type="button" class="dbtn" id="btn-lien" title="Insérer un lien">🔗</button>
      <button type="button" class="dbtn" data-cmd="insertHorizontalRule" title="Trait de séparation">Trait</button>
      <button type="button" class="dbtn" id="btn-zone" title="Insérer une zone de texte encadrée">Zone de texte</button>
      <button type="button" class="dbtn" id="btn-encadre" title="Insérer un encadré mis en avant">Encadré</button>
      <button type="button" class="dbtn" id="btn-date" title="Insérer la date du jour">Date</button>
      <button type="button" class="dbtn" data-cmd="superscript" title="Exposant">x²</button>
      <button type="button" class="dbtn" data-cmd="subscript" title="Indice">x₂</button>
      <button type="button" class="dbtn" id="btn-saut" title="Saut de page">Saut de page</button>
    </span>
    <span class="dgrp">
      <button type="button" class="dbtn" data-cmd="removeFormat" title="Effacer la mise en forme">Effacer style</button>
    </span>
    <span class="dgrp">
      <button type="button" class="dbtn dbtn-actif" id="btn-correcteur" title="Activer ou désactiver le correcteur orthographique">✓ Correcteur</button>
      <select class="dsel" id="sel-modele" title="Appliquer un modèle">
        <option value="">Insérer un modèle…</option>
        <?php foreach ($MODELES as $cle => $m): if ($cle === 'vierge') continue; ?>
        <option value="<?= $cle ?>"><?= e($m[0]) ?></option>
        <?php endforeach; ?>
      </select>
    </span>
  </div>

  <!-- Feuilles A4 : le texte passe sur une nouvelle feuille quand la page est pleine -->
  <div class="doc-zone">
    <div class="doc-feuille" id="doc-feuille">
      <div class="doc-fond" id="doc-fond"></div>
      <div class="doc-page" id="doc-page" lang="fr" contenteditable="<?= $verrouille ? 'false' : 'true' ?>" spellcheck="true" autocorrect="on" autocapitalize="sentences"><?= $edit['contenu'] ?: '<p><br></p>' ?></div>
    </div>
  </div>
</form>

<?php if (is_admin() && (int)$edit['id'] > 0): ?>
<form method="post" class="panel glass" style="margin-top:14px">
  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
  <h2>🗄️ Coffre à documents</h2>
  <?php if (!empty($edit['coffre_doc_id'])): ?>
    <p style="color:var(--ink-faint);font-size:13.5px;margin:-8px 0 12px">
      Une copie de ce document est déjà archivée. Vous pouvez en archiver une nouvelle version : l'ancienne copie est conservée.</p>
  <?php else: ?>
    <p style="color:var(--ink-faint);font-size:13.5px;margin:-8px 0 12px">
      Vous pouvez ranger une copie figée de ce document dans le coffre. Elle restera lisible telle quelle,
      même si le document est modifié ou supprimé par la suite.</p>
  <?php endif; ?>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <select class="input" name="dossier_id" style="max-width:260px">
      <option value="">📁 Aucun dossier (racine)</option>
      <?php foreach ($dossiersCoffre as $dc): ?>
      <option value="<?= (int)$dc['id'] ?>"><?= e($dc['icone'] ?? '📁') ?> <?= e($dc['nom']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-gold btn-sm" name="archiver" value="<?= (int)$edit['id'] ?>">🗄️ Archiver une copie</button>
    <?php if (!empty($edit['coffre_doc_id'])): ?>
    <a class="btn btn-glass btn-sm" href="coffre.php">Ouvrir le coffre</a>
    <?php endif; ?>
  </div>
</form>
<?php endif; ?>

<input type="file" id="fichier-image" accept="image/*" style="display:none">

<script>
var MODELES_DOC = <?= json_encode(array_map(fn($m) => ['nom' => $m[0], 'cat' => $m[1], 'html' => $m[2]], $MODELES), JSON_UNESCAPED_UNICODE) ?>;
(function(){
  var page = document.getElementById('doc-page');
  var etat = document.getElementById('doc-etat');
  var champ = document.getElementById('champ-contenu');
  var form = document.getElementById('form-doc');
  document.execCommand('styleWithCSS', false, true);

  function focusPage(){ page.focus(); }
  function marquerModifie(){ etat.textContent = '● Modifications non enregistrées'; etat.className = 'doc-etat modif'; }

  // Boutons simples
  document.querySelectorAll('#doc-outils .dbtn[data-cmd]').forEach(function(b){
    b.addEventListener('click', function(){
      focusPage(); document.execCommand(b.dataset.cmd, false, null); marquerModifie();
    });
  });
  // Listes deroulantes
  document.getElementById('sel-bloc').addEventListener('change', function(){
    focusPage(); document.execCommand('formatBlock', false, this.value); this.selectedIndex = 0; marquerModifie();
  });
  document.getElementById('sel-police').addEventListener('change', function(){
    if(!this.value) return; focusPage(); document.execCommand('fontName', false, this.value); this.selectedIndex = 0; marquerModifie();
  });
  document.getElementById('sel-taille').addEventListener('change', function(){
    if(!this.value) return; focusPage(); document.execCommand('fontSize', false, this.value); this.selectedIndex = 0; marquerModifie();
  });
  document.getElementById('col-texte').addEventListener('input', function(){
    focusPage(); document.execCommand('foreColor', false, this.value); marquerModifie();
  });
  document.getElementById('col-fond').addEventListener('input', function(){
    focusPage(); document.execCommand('hiliteColor', false, this.value); marquerModifie();
  });

  // Tableau
  document.getElementById('btn-tableau').addEventListener('click', function(){
    var l = parseInt(prompt('Nombre de lignes ?', '3'), 10);
    var c = parseInt(prompt('Nombre de colonnes ?', '3'), 10);
    if(!l || !c || l < 1 || c < 1) return;
    var h = '<table><tbody>';
    for(var i = 0; i < l; i++){
      h += '<tr>';
      for(var j = 0; j < c; j++) h += (i === 0 ? '<th>Titre</th>' : '<td>&nbsp;</td>');
      h += '</tr>';
    }
    h += '</tbody></table><p><br></p>';
    focusPage(); document.execCommand('insertHTML', false, h); marquerModifie();
  });

  // Image : depuis l'ordinateur, integree au document
  var champImage = document.getElementById('fichier-image');
  document.getElementById('btn-image').addEventListener('click', function(){ champImage.click(); });
  champImage.addEventListener('change', function(){
    var f = this.files && this.files[0]; if(!f) return;
    var lecteur = new FileReader();
    lecteur.onload = function(ev){
      focusPage();
      document.execCommand('insertHTML', false, '<img src="' + ev.target.result + '" style="max-width:100%">');
      marquerModifie();
    };
    lecteur.readAsDataURL(f);
    this.value = '';
  });

  // Zone de texte encadree
  document.getElementById('btn-zone').addEventListener('click', function(){
    focusPage();
    document.execCommand('insertHTML', false,
      '<div class="zone-texte"><p>Saisissez votre texte ici…</p></div><p><br></p>');
    marquerModifie(); paginer();
  });
  // Encadre mis en avant
  document.getElementById('btn-encadre').addEventListener('click', function(){
    focusPage();
    document.execCommand('insertHTML', false,
      '<div class="zone-encadree"><p>Point important…</p></div><p><br></p>');
    marquerModifie(); paginer();
  });
  // Date du jour
  document.getElementById('btn-date').addEventListener('click', function(){
    var d = new Date();
    var mois = ['janvier','février','mars','avril','mai','juin','juillet','août',
                'septembre','octobre','novembre','décembre'];
    focusPage();
    document.execCommand('insertText', false, d.getDate() + ' ' + mois[d.getMonth()] + ' ' + d.getFullYear());
    marquerModifie();
  });

  // Lien
  document.getElementById('btn-lien').addEventListener('click', function(){
    var u = prompt('Adresse du lien :', 'https://');
    if(u) { focusPage(); document.execCommand('createLink', false, u); marquerModifie(); }
  });
  // Saut de page
  document.getElementById('btn-saut').addEventListener('click', function(){
    focusPage();
    document.execCommand('insertHTML', false, '<div class="saut-page"></div><p><br></p>');
    marquerModifie();
  });

  // Correcteur orthographique du navigateur : activation / desactivation
  var btnCorr = document.getElementById('btn-correcteur');
  if (btnCorr) btnCorr.addEventListener('click', function(){
    var actif = page.getAttribute('spellcheck') !== 'false';
    page.setAttribute('spellcheck', actif ? 'false' : 'true');
    this.classList.toggle('dbtn-actif', !actif);
    this.textContent = actif ? '✕ Correcteur' : '✓ Correcteur';
    // on force le navigateur a re-analyser le texte
    var pos = page.innerHTML; page.blur(); page.innerHTML = pos; page.focus();
  });

  // Interligne : s'applique aux paragraphes de la selection
  var selInter = document.getElementById('sel-interligne');
  if (selInter) selInter.addEventListener('change', function(){
    var v = this.value; this.selectedIndex = 0;
    if (!v) return;
    var sel = window.getSelection();
    var blocs = [];
    if (sel && sel.rangeCount) {
      var r = sel.getRangeAt(0);
      Array.prototype.forEach.call(page.children, function(el){
        var er = el.getBoundingClientRect();
        if (r.intersectsNode ? r.intersectsNode(el) : true) blocs.push(el);
      });
    }
    if (!blocs.length) blocs = Array.prototype.slice.call(page.children);
    blocs.forEach(function(el){ el.style.lineHeight = v; });
    marquerModifie(); paginer();
  });

  // Appliquer un modele dans le document en cours
  var selModele = document.getElementById('sel-modele');
  if (selModele) selModele.addEventListener('change', function(){
    var m = MODELES_DOC[this.value];
    this.selectedIndex = 0;
    if (!m) return;
    var vide = page.textContent.trim() === '';
    if (!vide && !confirm('Remplacer le contenu actuel par le modèle « ' + m.nom + ' » ?')) return;
    page.innerHTML = m.html || '<p><br></p>';
    var champCat = document.querySelector('select[name="categorie"]');
    if (champCat) {
      for (var i = 0; i < champCat.options.length; i++) {
        if (champCat.options[i].text === m.cat) { champCat.selectedIndex = i; break; }
      }
    }
    marquerModifie();
    paginer();
    page.focus();
  });

  /* ---- Pagination : le texte passe sur une nouvelle feuille quand la page est pleine ---- */
  var feuille = document.getElementById('doc-feuille');
  var fond    = document.getElementById('doc-fond');

  function mm(v){
    var d = document.createElement('div');
    d.style.cssText = 'position:absolute;visibility:hidden;height:' + v + 'mm;width:1px';
    document.body.appendChild(d);
    var h = d.getBoundingClientRect().height;
    d.parentNode.removeChild(d);
    return h;
  }

  function paginer(){
    if (!feuille || !fond) return;
    var styles  = getComputedStyle(page);
    var MARGE   = parseFloat(styles.paddingTop) || 0;     // marge haute et basse de la feuille
    var PAGE_H  = mm(297);
    var ECART   = 18;                                     // espace visible entre deux feuilles
    var PAS     = PAGE_H + ECART;
    if (!PAGE_H) return;

    var hautFeuille = feuille.getBoundingClientRect().top;
    var enfants = Array.prototype.slice.call(page.children);

    // on repart d'une mise en page propre
    enfants.forEach(function(el){
      if (el.dataset.saut) { el.style.marginTop = el.dataset.margeOrig || ''; delete el.dataset.saut; }
    });

    enfants.forEach(function(el){
      var r = el.getBoundingClientRect();
      var haut = r.top - hautFeuille;
      var bas  = haut + r.height;
      var page_i = Math.floor(haut / PAS);
      var basZone = page_i * PAS + PAGE_H - MARGE;        // bas de la zone de texte de cette feuille
      if (bas > basZone && r.height < (PAGE_H - 2 * MARGE)) {
        var pousser = ((page_i + 1) * PAS + MARGE) - haut;
        var actuelle = parseFloat(getComputedStyle(el).marginTop) || 0;
        el.dataset.margeOrig = el.style.marginTop || '';
        el.dataset.saut = '1';
        el.style.marginTop = (actuelle + pousser) + 'px';
      }
    });

    // dessiner autant de feuilles blanches que necessaire
    var total = page.getBoundingClientRect().height;
    var nb = Math.max(1, Math.ceil((total + ECART) / PAS));
    feuille.style.height = (nb * PAGE_H + (nb - 1) * ECART) + 'px';
    if (fond.childElementCount !== nb) {
      fond.innerHTML = '';
      for (var i = 0; i < nb; i++) {
        var f = document.createElement('div');
        f.className = 'feuille-blanche';
        f.style.top = (i * PAS) + 'px';
        f.style.height = PAGE_H + 'px';
        fond.appendChild(f);
      }
    }
    var num = document.querySelectorAll('.feuille-blanche');
    for (var k = 0; k < num.length; k++) num[k].setAttribute('data-num', (k + 1) + ' / ' + nb);
  }

  var minuteur = null;
  function paginerBientot(){ clearTimeout(minuteur); minuteur = setTimeout(paginer, 220); }

  page.addEventListener('input', function(){ marquerModifie(); paginerBientot(); });
  window.addEventListener('resize', paginerBientot);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(paginer);
  setTimeout(paginer, 60);

  // Envoi : on recopie le contenu dans le champ cache
  form.addEventListener('submit', function(){ champ.value = page.innerHTML; });

  // Enregistrement automatique toutes les 30 secondes
  var dernier = page.innerHTML;
  setInterval(function(){
    if(page.innerHTML === dernier) return;
    dernier = page.innerHTML;
    var d = new FormData(form);
    d.set('contenu', page.innerHTML);
    d.set('enregistrer', '1');
    d.set('ajax', '1');
    fetch('documents.php', {method:'POST', body:d})
      .then(function(r){ return r.json(); })
      .then(function(j){
        if(j && j.ok){
          etat.textContent = '✓ Enregistré automatiquement';
          etat.className = 'doc-etat ok';
          var champId = form.querySelector('input[name="id"]');
          if(champId && !parseInt(champId.value, 10)) champId.value = j.id;
        }
      }).catch(function(){});
  }, 30000);

  // Ctrl+S
  document.addEventListener('keydown', function(e){
    if((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's'){
      e.preventDefault(); champ.value = page.innerHTML; form.querySelector('[name="enregistrer"]').click();
    }
  });
})();
</script>

<?php else: ?>
<!-- ==================== LISTE ==================== -->
<div class="panel glass" style="margin-bottom:14px">
  <h2>📝 Nouveau document</h2>
  <p style="color:var(--ink-faint);font-size:13.5px;margin:-8px 0 14px">Partez d'une page vierge ou d'un modèle prêt à remplir.</p>
  <div class="doc-modeles">
    <?php foreach ($MODELES as $cle => $m): ?>
    <a class="doc-modele" href="documents.php?edit=new&amp;modele=<?= $cle ?>"><?= $m[0] ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="panel glass">
  <h2>📚 Mes documents (<?= count($documents) ?>)</h2>
  <form method="get" style="margin-bottom:14px">
    <input class="input" name="q" value="<?= e($recherche) ?>" placeholder="Rechercher un document…" style="max-width:340px">
  </form>

  <?php if (!$documents): ?>
  <p style="color:var(--ink-faint)">Aucun document pour le moment.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Titre</th><th>Catégorie</th><th>État</th><th>Auteur</th><th>Modifié le</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($documents as $d): ?>
        <tr>
          <td><a href="documents.php?edit=<?= (int)$d['id'] ?>" style="font-weight:700;color:var(--ink)"><?= e($d['titre']) ?></a></td>
          <td><span class="badge"><?= e($d['categorie']) ?></span></td>
          <td><?php $st = $d['statut'] ?? 'brouillon'; ?>
            <span class="etat-doc et-<?= $st ?>"><?= ['brouillon'=>'Brouillon','termine'=>'Terminé','valide'=>'Validé'][$st] ?></span>
            <?php if (!empty($d['coffre_doc_id'])): ?><span title="Archivé dans le coffre">🗄️</span><?php endif; ?>
          </td>
          <td><?= e($d['auteur'] ?? '—') ?></td>
          <td><?= date('d/m/Y H:i', strtotime($d['updated_at'])) ?></td>
          <td style="text-align:right;white-space:nowrap">
            <a class="btn btn-glass btn-sm" href="documents.php?edit=<?= (int)$d['id'] ?>">✏️</a>
            <a class="btn btn-glass btn-sm" href="doc-imprimer.php?id=<?= (int)$d['id'] ?>" target="_blank">🖨️</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Créer une copie de ce document ?')">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <button class="btn btn-glass btn-sm" name="dupliquer" value="<?= (int)$d['id'] ?>" title="Dupliquer">Copie</button>
            </form>
            <?php if (is_admin()): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Supprimer définitivement ce document ?')">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <button class="btn btn-danger btn-sm" name="supprimer" value="<?= (int)$d['id'] ?>">✕</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
