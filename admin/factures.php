<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
$devise = $settings['devise'] ?? 'FCFA';

// Contexte : facture ou proforma
$doc = (($_GET['doc'] ?? '') === 'proforma') ? 'proforma' : 'facture';
$isPro = $doc === 'proforma';
$NOM = $isPro ? 'proforma' : 'facture';
$NOMcap = $isPro ? 'Proforma' : 'Facture';
$backList = $isPro ? 'factures.php?doc=proforma' : 'factures.php';

/* ---------- Traitement ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ctx = ($_POST['doc_ctx'] ?? '') === 'proforma' ? '?doc=proforma' : '';

    if (isset($_POST['supprimer'])) {
        if (!is_admin()) { flash("Seul un administrateur peut supprimer un document.", 'error'); header('Location: factures.php'.$ctx); exit; }
        $fid = (int)$_POST['supprimer'];
        // Récupérer le numéro et le type pour nettoyer le coffre (documents authentifiés)
        $inf = $pdo->prepare('SELECT numero, type FROM factures WHERE id=?');
        $inf->execute([$fid]); $inf = $inf->fetch();
        // 1) Lignes de la facture
        $pdo->prepare('DELETE FROM facture_lignes WHERE facture_id=?')->execute([$fid]);
        // 2) Authentification / présence au coffre
        if ($inf) {
            $pdo->prepare('DELETE FROM documents_auth WHERE doc_id=? AND type IN (?,?)')
                ->execute([$fid, 'facture', 'proforma']);
            // 2b) Entrée comptable liée (si la facture était payée)
            $pdo->prepare("DELETE FROM transactions WHERE type='entree' AND libelle=?")
                ->execute(['Encaissement facture ' . $inf['numero']]);
        }
        // 3) La facture elle-même
        $pdo->prepare('DELETE FROM factures WHERE id=?')->execute([$fid]);
        journaliser($pdo, 'suppression', 'facture', (int)($_POST['supprimer'] ?? 0), 'Document supprimé');
        flash('Document supprimé définitivement (retiré aussi du coffre).');
        header('Location: factures.php'.$ctx); exit;
    }
    if (isset($_POST['convertir'])) {
        // Proforma -> Facture : on change le type et on attribue un numéro de facture
        $fid = (int)$_POST['convertir'];
        $numero = next_numero($pdo, 'factures', $settings['prefixe_facture'] ?? 'FAC');
        $pdo->prepare("UPDATE factures SET type='facture', numero=?, statut='brouillon' WHERE id=? AND type='proforma'")
            ->execute([$numero, $fid]);
        flash('Proforma convertie en facture ' . $numero . '.');
        header('Location: factures.php'); exit;
    }
    if (isset($_POST['envoyer_mail'])) {
        require_once __DIR__ . '/../config/mail.php';
        $fid = (int)$_POST['envoyer_mail'];
        $q = $pdo->prepare("SELECT f.numero, f.type, c.nom AS client, c.email
            FROM factures f LEFT JOIN clients c ON c.id=f.client_id WHERE f.id=?");
        $q->execute([$fid]); $fdoc = $q->fetch();
        if (!$fdoc || empty($fdoc['email'])) {
            flash("Ce client n'a pas d'adresse email enregistrée.", 'error');
        } else {
            $s = get_settings($pdo);
            $typeLbl = $fdoc['type'] === 'proforma' ? 'devis (proforma)' : 'facture';
            $siteUrl = rtrim($s['site_url'] ?? (defined('SITE_URL') ? SITE_URL : ''), '/');
            $lien = $siteUrl ? $siteUrl . '/admin/pdf.php?type=' . $fdoc['type'] . '&id=' . $fid : '';
            $corps = '<p>Bonjour <strong>' . htmlspecialchars($fdoc['client']) . '</strong>,</p>
                <p>Veuillez trouver votre ' . $typeLbl . ' <strong>' . htmlspecialchars($fdoc['numero']) . '</strong>.</p>'
                . ($lien ? '<p style="text-align:center;margin:24px 0"><a href="' . $lien . '" style="background:#d4a526;color:#0a1020;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:bold">Consulter le document</a></p>' : '')
                . '<p>Pour toute question, n\'hésitez pas à nous contacter.</p>
                <p>Cordialement,<br><strong>' . htmlspecialchars($s['nom_entreprise'] ?? 'Groupe Helisce') . '</strong></p>';
            if (@envoyer_email($pdo, $fdoc['email'], 'Votre ' . $typeLbl . ' ' . $fdoc['numero'], $corps)) {
                flash('Document envoyé par email à ' . $fdoc['email'] . '.');
            } else {
                flash("L'email n'a pas pu être envoyé. Vérifiez les réglages SMTP dans Paramètres.", 'error');
            }
        }
        header('Location: factures.php'.$ctx); exit;
    }

    if (isset($_POST['statut'], $_POST['id_statut'])) {
        $ok = ['brouillon','envoyee','payee','annulee'];
        if (in_array($_POST['statut'], $ok)) {
            $fid = (int)$_POST['id_statut'];
            $nouveau = $_POST['statut'];
            $pdo->prepare('UPDATE factures SET statut=? WHERE id=?')->execute([$nouveau, $fid]);

            // Synchronisation avec la comptabilité (table transactions)
            // Repère : libelle "Encaissement facture <numero>" pour éviter les doublons.
            $fInfo = $pdo->prepare('SELECT f.numero, f.client_id, f.tva_taux, f.tva_applicable, f.remise,
                (SELECT COALESCE(SUM(quantite*prix_unitaire),0) FROM facture_lignes WHERE facture_id=f.id) AS ht
                FROM factures f WHERE f.id=?');
            $fInfo->execute([$fid]); $fInfo = $fInfo->fetch();
            if ($fInfo) {
                $repere = 'Encaissement facture ' . $fInfo['numero'];
                // Nettoyer une éventuelle entrée précédente pour cette facture
                $pdo->prepare("DELETE FROM transactions WHERE type='entree' AND libelle=?")->execute([$repere]);
                if ($nouveau === 'payee') {
                    // Montant TTC = (HT - remise) * (1 + TVA si applicable)
                    $ht = (float)$fInfo['ht'] - (float)$fInfo['remise'];
                    if ($ht < 0) $ht = 0;
                    $ttc = $fInfo['tva_applicable'] ? $ht * (1 + (float)$fInfo['tva_taux']/100) : $ht;
                    $pdo->prepare("INSERT INTO transactions (type, categorie, libelle, montant, mode_paiement, client_id, date_operation, notes)
                                   VALUES ('entree', 'Ventes', ?, ?, ?, ?, CURDATE(), 'Généré automatiquement depuis la facture.')")
                        ->execute([$repere, round($ttc), 'Facture', $fInfo['client_id']]);
                    flash('Facture marquée payée et enregistrée en comptabilité (entrée).');
                } else {
                    flash('Statut mis à jour.');
                }
            } else {
                flash('Statut mis à jour.');
            }
        }
        header('Location: factures.php'.$ctx); exit;
    }

    // Création / modification
    $type = ($_POST['doc_type'] ?? 'facture') === 'proforma' ? 'proforma' : 'facture';
    $id = (int)($_POST['id'] ?? 0);
    $client_id = ($_POST['client_id'] ?? '') ?: null;
    $date_em = ($_POST['date_emission'] ?? '') ?: date('Y-m-d');
    $date_ech = ($_POST['date_echeance'] ?? '') ?: null;
    $tvaOn = (int)($_POST['tva_applicable'] ?? 1) === 1 ? 1 : 0;
    $tva = $tvaOn ? (float)($_POST['tva_taux'] ?? 18) : 0;
    $remise = max(0, (float)($_POST['remise'] ?? 0));
    $notes = mb_substr(trim($_POST['notes'] ?? ''), 0, 500);
    $mode_paie = mb_substr(trim($_POST['mode_paiement'] ?? ''), 0, 60);
    $activite  = mb_substr(trim($_POST['activite'] ?? ''), 0, 255);
    $date_evt  = ($_POST['date_evenement'] ?? '') ?: null;
    $nb_jours = max(1, min(60, (int)($_POST['nb_jours'] ?? 1)));
    $lieu      = mb_substr(trim($_POST['lieu'] ?? ''), 0, 255);

    $desigs = $_POST['designation'] ?? [];
    $qtes = $_POST['quantite'] ?? [];
    $prix = $_POST['prix_unitaire'] ?? [];
    $catsL = $_POST['categorie'] ?? [];
    $detsL = $_POST['details'] ?? [];
    $lignes = [];
    foreach ($desigs as $i => $d) {
        $d = trim($d);
        if ($d === '') continue;
        // Éléments inclus : une ligne par élément, sans prix
        $det = trim((string)($detsL[$i] ?? ''));
        $det = $det === '' ? null : mb_substr(implode("\n", array_filter(array_map('trim', preg_split('/\r?\n/', $det)))), 0, 2000);
        $lignes[] = [mb_substr($d, 0, 255), max(0, (float)($qtes[$i] ?? 1)), max(0, (float)($prix[$i] ?? 0)), mb_substr(trim($catsL[$i] ?? ''), 0, 120), $det];
    }
    $retour = $type === 'proforma' ? '?doc=proforma' : '';
    if (!$lignes) { flash('Ajoutez au moins une ligne.', 'error'); header('Location: factures.php'.($retour ?: '').'&edit='.($id?:'new')); exit; }

    if ($id) {
        $pdo->prepare('UPDATE factures SET client_id=?, date_emission=?, date_echeance=?, tva_taux=?, tva_applicable=?, remise=?, notes=?, mode_paiement=?, activite=?, date_evenement=?, nb_jours=?, lieu=? WHERE id=?')
            ->execute([$client_id, $date_em, $date_ech, $tva, $tvaOn, $remise, $notes, $mode_paie, $activite, $date_evt, $nb_jours, $lieu, $id]);
        $pdo->prepare('DELETE FROM facture_lignes WHERE facture_id=?')->execute([$id]);
        flash('Document modifié.');
    } else {
        $prefixe = $type === 'proforma' ? ($settings['prefixe_proforma'] ?? 'PRO') : ($settings['prefixe_facture'] ?? 'FAC');
        $numero = next_numero($pdo, 'factures', $prefixe);
        $pdo->prepare('INSERT INTO factures (numero, type, client_id, date_emission, date_echeance, tva_taux, tva_applicable, remise, notes, mode_paiement, activite, date_evenement, nb_jours, lieu, vu_client) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)')
            ->execute([$numero, $type, $client_id, $date_em, $date_ech, $tva, $tvaOn, $remise, $notes, $mode_paie, $activite, $date_evt, $nb_jours, $lieu]);
        $id = (int)$pdo->lastInsertId();
        flash(($type==='proforma'?'Proforma ':'Facture ') . $numero . ' créée.');
    }
    $ins = $pdo->prepare('INSERT INTO facture_lignes (facture_id, designation, quantite, prix_unitaire, categorie, details) VALUES (?,?,?,?,?,?)');
    foreach ($lignes as $l) $ins->execute([$id, $l[0], $l[1], $l[2], $l[3] ?: null, $l[4]]);
    header('Location: factures.php'.$retour); exit;
}

/* ---------- Formulaire édition ---------- */
$mode_form = false; $edit = null; $lignes = []; $pre_client = null;
if (isset($_GET['edit'])) {
    $mode_form = true;
    if ($_GET['edit'] !== 'new') {
        $stmt = $pdo->prepare('SELECT * FROM factures WHERE id=?');
        $stmt->execute([(int)$_GET['edit']]);
        $edit = $stmt->fetch();
        if ($edit) {
            $doc = $edit['type']; $isPro = $doc==='proforma'; $NOMcap = $isPro?'Proforma':'Facture'; $backList = $isPro?'factures.php?doc=proforma':'factures.php';
            $lg = $pdo->prepare('SELECT * FROM facture_lignes WHERE facture_id=? ORDER BY id');
            $lg->execute([$edit['id']]);
            $lignes = $lg->fetchAll();
        }
    }
    $pre_client = $_GET['client'] ?? ($edit['client_id'] ?? '');
}

$clients = $pdo->query('SELECT id, nom, entreprise FROM clients ORDER BY nom')->fetchAll();
// Menu traiteur : catégories + plats (pour l'ajout rapide de lignes)
$catsMenu = $pdo->query("SELECT id, nom, icone FROM categories WHERE actif=1 ORDER BY ordre, id")->fetchAll();
/* Les plats suivent l'ordre défini dans le Menu, et non l'ordre alphabétique :
   c'est celui que vous avez choisi et que vos clients connaissent. */
$platsMenu = $pdo->query("SELECT id, nom, prix, categorie_id FROM plats WHERE actif=1 ORDER BY ordre, id")->fetchAll();
$menuData = [];
foreach ($catsMenu as $c) $menuData[$c['id']] = ['nom' => $c['nom'], 'icone' => $c['icone'] ?: '🍽️', 'plats' => []];
foreach ($platsMenu as $p) if (isset($menuData[$p['categorie_id']])) $menuData[$p['categorie_id']]['plats'][] = ['nom' => $p['nom'], 'prix' => (float)$p['prix']];

/* ---------- Liste (filtrée par type) ---------- */
$stmt = $pdo->prepare("SELECT f.*, c.nom AS client, c.entreprise, c.type_client,
    (SELECT COALESCE(SUM(quantite*prix_unitaire),0) FROM facture_lignes WHERE facture_id=f.id) AS ht
    FROM factures f LEFT JOIN clients c ON c.id=f.client_id WHERE f.type=? ORDER BY f.date_emission DESC, f.id DESC");
$stmt->execute([$doc]);
$factures = $stmt->fetchAll();

/* Rangement : filtres (client/mois/année) + arborescence repliable */
require_once __DIR__ . '/includes/rangement.php';

/* Calcule le TTC (remise + TVA applicable) pour CHAQUE facture — sert au récap et aux vues */
foreach ($factures as &$fg) {
    $b = max(0, (float)($fg['ht'] ?? 0) - (float)($fg['remise'] ?? 0));
    $tvaOn = isset($fg['tva_applicable']) ? (int)$fg['tva_applicable'] : 1;
    $fg['_ttc'] = $tvaOn ? $b * (1 + (float)$fg['tva_taux']/100) : $b;
}
unset($fg);

$vueRng = ($_GET['vue'] ?? 'arbre') === 'liste' ? 'liste' : 'arbre';
$fRng = ['client' => (int)($_GET['fc'] ?? 0), 'mois' => (int)($_GET['fm'] ?? 0), 'annee' => (int)($_GET['fa'] ?? 0)];
// Filtre par statut (dont "impayees" = brouillon + envoyée)
$fStatut = $_GET['fs'] ?? '';
$statutsValides = ['brouillon','envoyee','payee','annulee','impayees'];
if (!in_array($fStatut, $statutsValides, true)) $fStatut = '';

// Récapitulatif financier (sur TOUTES les factures)
$recap = ['paye' => 0.0, 'encaisser' => 0.0, 'nb_paye' => 0, 'nb_encaisser' => 0];
if ($doc === 'facture') {
    foreach ($factures as $f) {
        $ttc = $f['_ttc'] ?? 0;
        $s = $f['statut'] ?? 'brouillon';
        if ($s === 'payee') { $recap['paye'] += $ttc; $recap['nb_paye']++; }
        elseif (in_array($s, ['brouillon','envoyee'], true)) { $recap['encaisser'] += $ttc; $recap['nb_encaisser']++; }
    }
}

$facturesAff = rangement_filtrer($factures, $fRng, 'date_emission', 'client_id');
// Appliquer le filtre de statut sur la liste affichée
if ($fStatut !== '' && $doc === 'facture') {
    $facturesAff = array_values(array_filter($facturesAff, function($f) use ($fStatut) {
        $s = $f['statut'] ?? 'brouillon';
        if ($fStatut === 'impayees') return in_array($s, ['brouillon','envoyee'], true);
        return $s === $fStatut;
    }));
}
$anneesRng = rangement_annees($factures, 'date_emission');

$badges = ['brouillon'=>'badge','envoyee'=>'badge-violet','payee'=>'badge-teal','annulee'=>'badge-danger'];
$labels = ['brouillon'=>'Brouillon','envoyee'=>'Envoyée','payee'=>'Payée','annulee'=>'Annulée'];

$modes_paiement = modes_paiement();
admin_header($isPro ? 'Proformas' : 'Factures', $isPro ? 'proformas' : 'factures', $pdo, $settings);
?>

<?php if ($mode_form): ?>
<!-- ============ FORMULAIRE ============ -->
<div class="panel glass">
  <h2><?= $edit ? '✏️ Modifier '.($isPro?'la proforma ':'la facture ').e($edit['numero']) : ($isPro?'📋 Nouvelle proforma':'🧾 Nouvelle facture') ?>
    <a href="<?= $backList ?>" class="btn btn-glass btn-sm" style="margin-left:auto">← Retour</a>
  </h2>
  <form method="post" id="factForm">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
    <input type="hidden" name="doc_type" value="<?= $doc ?>">
    <div class="form-grid" style="margin-bottom:22px">
      <div class="field"><label>Client</label>
        <select class="input" name="client_id">
          <option value="">— Client de passage —</option>
          <?php foreach ($clients as $c): ?>
          <option value="<?= $c['id'] ?>" <?= (string)$pre_client === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['nom']) ?><?= $c['entreprise'] ? ' ('.e($c['entreprise']).')' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Date d'émission</label><input class="input" type="date" name="date_emission" value="<?= e($edit['date_emission'] ?? date('Y-m-d')) ?>"></div>
      <div class="field"><label><?= $isPro ? "Valable jusqu'au" : "Date d'échéance" ?></label><input class="input" type="date" name="date_echeance" value="<?= e($edit['date_echeance'] ?? '') ?>"></div>
      <div class="field"><label>Mode de paiement</label>
        <select class="input" name="mode_paiement">
          <option value="">— Non précisé —</option>
          <?php foreach ($modes_paiement as $mp): ?>
          <option <?= ($edit['mode_paiement'] ?? '') === $mp ? 'selected' : '' ?>><?= e($mp) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field full"><label>Activité / Description de la prestation</label><input class="input" name="activite" placeholder="ex : Buffet mariage, Cocktail dînatoire, Séminaire…" value="<?= e($edit['activite'] ?? '') ?>"></div>
      <div class="field"><label>Date de l'événement</label><input class="input" type="date" name="date_evenement" value="<?= e($edit['date_evenement'] ?? '') ?>"></div>
      <div class="field"><label>Durée (jours)</label>
        <input class="input" type="number" name="nb_jours" min="1" max="60" value="<?= (int)($edit['nb_jours'] ?? 1) ?: 1 ?>">
        <span style="display:block;margin-top:4px;font-size:12px;color:var(--ink-faint)">1 pour une prestation d'une journée.</span>
      </div>
      <div class="field"><label>Lieu de l'événement</label><input class="input" name="lieu" placeholder="ex : Cocody, Salle des fêtes…" value="<?= e($edit['lieu'] ?? '') ?>"></div>
      <div class="field tva-field">
        <label>TVA</label>
        <?php $tvaOn = (int)($edit['tva_applicable'] ?? 1) === 1; ?>
        <div class="tva-choice">
          <label class="tva-opt <?= $tvaOn ? 'on' : '' ?>">
            <input type="radio" name="tva_applicable" value="1" <?= $tvaOn ? 'checked' : '' ?> onchange="majTva()">
            <span>Applicable</span>
          </label>
          <label class="tva-opt <?= $tvaOn ? '' : 'on' ?>">
            <input type="radio" name="tva_applicable" value="0" <?= $tvaOn ? '' : 'checked' ?> onchange="majTva()">
            <span>Non applicable</span>
          </label>
          <div class="tva-rate" id="tvaRate" style="<?= $tvaOn ? '' : 'display:none' ?>">
            <input class="input" type="number" name="tva_taux" id="tva" step="0.01" min="0" value="<?= e($edit['tva_taux'] ?? ($settings['tva_taux'] ?? 18)) ?>" oninput="calc()"><span>%</span>
          </div>
        </div>
      </div>
    </div>

    <?php if ($menuData): ?>
    <div class="glass" style="padding:16px 18px;border-radius:16px;margin-bottom:18px;border-left:3px solid var(--gold)">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
        <strong style="font-family:var(--font-display);font-size:15px">🍽️ Service traiteur — ajouter depuis le menu</strong>
        <span style="font-size:12.5px;color:var(--ink-faint)">Choisissez une catégorie, cochez les éléments à inclure, puis ajoutez-la au document. Le prix se saisit une seule fois, pour la prestation entière.</span>
      </div>
      <div class="menu-cats" id="menuCats">
        <?php foreach ($menuData as $cid => $c): if(!$c['plats']) continue; ?>
        <button type="button" class="btn btn-glass btn-sm menu-cat-btn" data-cat="<?= $cid ?>" onclick="showCat(<?= $cid ?>)"><?= e($c['icone']) ?> <?= e($c['nom']) ?> <span style="opacity:.6">(<?= count($c['plats']) ?>)</span></button>
        <?php endforeach; ?>
      </div>
      <div class="menu-plats" id="menuPlats" style="display:none;margin-top:12px"></div>
    </div>
    <?php endif; ?>

    <h3 style="font-family:var(--font-display);font-size:15px;margin-bottom:12px">Lignes</h3>
    <datalist id="catList"><?php foreach ($catsMenu as $c): ?><option value="<?= e($c['nom']) ?>"></option><?php endforeach; ?></datalist>
    <div class="tbl-wrap">
      <table id="lignesTable">
        <thead><tr><th style="width:52%">Prestation &amp; éléments inclus</th><th style="width:12%">Quantité</th><th style="width:18%">Prix unitaire</th><th style="text-align:right">Total</th><th></th></tr></thead>
        <tbody id="lignesBody">
          <?php $rows = $lignes ?: [['designation'=>'','categorie'=>'','details'=>'','quantite'=>1,'prix_unitaire'=>'']];
          foreach ($rows as $l): ?>
          <tr>
            <td>
              <input class="input l-desig" name="designation[]" value="<?= e($l['designation']) ?>" placeholder="Prestation (ex : Pause café du matin)">
              <input type="hidden" class="l-cat" name="categorie[]" value="<?= e($l['categorie'] ?? '') ?>">
              <textarea class="l-details" name="details[]" hidden><?= e($l['details'] ?? '') ?></textarea>
              <div class="incl-wrap">
                <div class="incl-list"></div>
                <div class="incl-add">
                  <input type="text" class="input incl-input" placeholder="+ ajouter un élément inclus" onkeydown="if(event.key==='Enter'){event.preventDefault();addIncl(this);}">
                </div>
              </div>
            </td>
            <td><input class="input l-qte" name="quantite[]" type="number" step="0.01" min="0" value="<?= e($l['quantite']) ?>" oninput="calc()" style="width:90px"></td>
            <td><input class="input l-pu" name="prix_unitaire[]" type="number" step="1" min="0" value="<?= e($l['prix_unitaire']) ?>" oninput="calc()" style="width:130px" placeholder="ex : 30000"></td>
            <td style="text-align:right" class="l-total">0</td>
            <td><button type="button" class="btn btn-danger btn-sm" onclick="delLigne(this)">✕</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <button type="button" class="btn btn-glass btn-sm" onclick="addLigne()" style="margin-top:12px">➕ Ajouter une ligne</button>

    <div style="display:flex;justify-content:flex-end;margin-top:24px">
      <div class="glass" style="padding:20px 24px;border-radius:16px;min-width:280px">
        <div style="display:flex;justify-content:space-between;padding:6px 0;color:var(--ink-dim)"><span>Total HT</span><strong id="v-ht" style="color:var(--ink)">0</strong></div>
        <div style="display:flex;justify-content:space-between;padding:6px 0;align-items:center;color:var(--ink-dim)"><span>Remise</span>
          <input class="input" type="number" name="remise" id="remise" min="0" step="100" value="<?= e($edit['remise'] ?? 0) ?>" oninput="calc()" style="width:120px;padding:6px 10px;text-align:right">
        </div>
        <div id="ligne-tva" style="display:flex;justify-content:space-between;padding:6px 0;color:var(--ink-dim)"><span>TVA (<span id="v-tvat">18</span>%)</span><strong id="v-tva" style="color:var(--ink)">0</strong></div>
        <div id="ligne-tva-non" style="display:none;justify-content:space-between;padding:6px 0;color:var(--ink-faint);font-style:italic;font-size:13px"><span>TVA non applicable</span><strong>—</strong></div>
        <div style="display:flex;justify-content:space-between;padding:12px 0 0;margin-top:8px;border-top:1px solid var(--glass-border);font-size:18px"><span style="font-weight:700">Total TTC</span><strong id="v-ttc" style="color:var(--gold)">0</strong></div>
      </div>
    </div>

    <div class="field full" style="margin-top:20px"><label>Notes / mentions</label><textarea class="input" name="notes" style="min-height:60px"><?= e($edit['notes'] ?? ($settings['mentions_facture'] ?? '')) ?></textarea></div>
    <div style="display:flex;gap:10px;margin-top:16px">
      <button class="btn btn-gold"><?= $edit ? 'Enregistrer' : ($isPro?'Créer la proforma':'Créer la facture') ?></button>
      <a class="btn btn-glass" href="<?= $backList ?>">Annuler</a>
    </div>
  </form>
</div>

<script>
const DEVISE = <?= json_encode($devise) ?>;
const MENU = <?= json_encode($menuData, JSON_UNESCAPED_UNICODE) ?>;
const TVA_DEFAUT = <?= json_encode((float)($settings['tva_taux'] ?? 18)) ?>;
function fmt(n){ return new Intl.NumberFormat('fr-FR').format(Math.round(n)) + ' ' + DEVISE; }
function showCat(cid){
  const box = document.getElementById('menuPlats');
  document.querySelectorAll('.menu-cat-btn').forEach(b=>b.classList.toggle('active', b.dataset.cat==cid));
  const cat = MENU[cid]; if(!cat){ box.style.display='none'; return; }
  box.innerHTML = '';
  const titre = document.createElement('div');
  titre.className = 'pick-head';
  titre.innerHTML = '<strong></strong><span>Cochez les éléments inclus dans cette prestation</span>';
  titre.children[0].textContent = cat.icone + ' ' + cat.nom;
  box.appendChild(titre);

  const grille = document.createElement('div');
  grille.className = 'pick-grid';
  cat.plats.forEach(function(p){
    const lab = document.createElement('label');
    lab.className = 'pick-item';
    const cb = document.createElement('input');
    cb.type = 'checkbox'; cb.checked = true; cb.value = p.nom;
    const sp = document.createElement('span'); sp.textContent = p.nom;
    lab.appendChild(cb); lab.appendChild(sp);
    grille.appendChild(lab);
  });
  box.appendChild(grille);

  const barre = document.createElement('div');
  barre.className = 'pick-actions';
  const tout = document.createElement('button');
  tout.type='button'; tout.className='btn btn-glass btn-sm'; tout.textContent='Tout cocher / décocher';
  tout.addEventListener('click', function(){
    const cbs = grille.querySelectorAll('input'); const tousCoches = [...cbs].every(c=>c.checked);
    cbs.forEach(c=>c.checked = !tousCoches);
  });
  const ajouter = document.createElement('button');
  ajouter.type='button'; ajouter.className='btn btn-gold btn-sm';
  ajouter.textContent = 'Ajouter « ' + cat.nom + ' » au document';
  ajouter.addEventListener('click', function(){
    const choisis = [...grille.querySelectorAll('input:checked')].map(c=>c.value);
    ajouterPrestation(cat.nom, choisis);
  });
  barre.appendChild(tout); barre.appendChild(ajouter);
  box.appendChild(barre);
  box.style.display='block';
}

/* Ajoute une prestation : la catégorie devient la ligne, les éléments sont listés dessous (sans prix) */
function ajouterPrestation(nomCat, elements){
  let cible = null;
  document.querySelectorAll('#lignesBody tr').forEach(tr=>{
    if(!cible){ const d=tr.querySelector('.l-desig'); if(d && d.value.trim()==='') cible=tr; }
  });
  if(!cible) cible = addLigne();
  cible.querySelector('.l-desig').value = nomCat;
  cible.querySelector('.l-details').value = elements.join('\n');
  if(!parseFloat(cible.querySelector('.l-qte').value)) cible.querySelector('.l-qte').value = 1;
  renderIncl(cible);
  cible.querySelector('.l-pu').focus();
  calc();
}

/* Affiche les éléments inclus sous forme de puces supprimables */
function renderIncl(tr){
  const zone = tr.querySelector('.incl-list');
  const champ = tr.querySelector('.l-details');
  const items = champ.value.split('\n').map(x=>x.trim()).filter(Boolean);
  zone.innerHTML = '';
  items.forEach(function(txt, i){
    const chip = document.createElement('span');
    chip.className = 'incl-chip';
    const t = document.createElement('span'); t.textContent = txt;
    const x = document.createElement('button');
    x.type='button'; x.className='incl-x'; x.textContent='×'; x.title='Retirer';
    x.addEventListener('click', function(){
      const rest = items.filter((_,k)=>k!==i);
      champ.value = rest.join('\n'); renderIncl(tr);
    });
    chip.appendChild(t); chip.appendChild(x);
    zone.appendChild(chip);
  });
  tr.querySelector('.incl-wrap').style.display = 'block';
}

/* Ajout manuel d'un élément inclus */
function addIncl(input){
  const tr = input.closest('tr');
  const v = input.value.trim(); if(!v) return;
  const champ = tr.querySelector('.l-details');
  champ.value = (champ.value ? champ.value + '\n' : '') + v;
  input.value = ''; renderIncl(tr);
}

function rowHTML(){
  return `<td>
      <input class="input l-desig" name="designation[]" placeholder="Prestation (ex : Pause café du matin)">
      <input type="hidden" class="l-cat" name="categorie[]" value="">
      <textarea class="l-details" name="details[]" hidden></textarea>
      <div class="incl-wrap">
        <div class="incl-list"></div>
        <div class="incl-add"><input type="text" class="input incl-input" placeholder="+ ajouter un élément inclus" onkeydown="if(event.key==='Enter'){event.preventDefault();addIncl(this);}"></div>
      </div>
    </td>
    <td><input class="input l-qte" name="quantite[]" type="number" step="0.01" min="0" value="1" oninput="calc()" style="width:90px"></td>
    <td><input class="input l-pu" name="prix_unitaire[]" type="number" step="1" min="0" value="" oninput="calc()" style="width:130px" placeholder="ex : 30000"></td>
    <td style="text-align:right" class="l-total">0</td>
    <td><button type="button" class="btn btn-danger btn-sm" onclick="delLigne(this)">✕</button></td>`;
}
function addLigne(){
  const tr = document.createElement('tr');
  tr.innerHTML = rowHTML();
  document.getElementById('lignesBody').appendChild(tr); calc(); return tr;
}
function delLigne(b){ const tb=document.getElementById('lignesBody'); if(tb.rows.length>1){ b.closest('tr').remove(); calc(); } }

/* TVA applicable / non applicable */
function majTva(){
  const on = document.querySelector('input[name="tva_applicable"]:checked').value === '1';
  const champ = document.getElementById('tva');
  // en repassant en « applicable », on remet le taux habituel s'il était à zéro
  if (on && !(parseFloat(champ.value) > 0)) champ.value = TVA_DEFAUT;
  document.getElementById('tvaRate').style.display = on ? '' : 'none';
  document.querySelectorAll('.tva-opt').forEach(function(l){
    l.classList.toggle('on', l.querySelector('input').checked);
  });
  calc();
}

function calc(){
  let ht = 0;
  document.querySelectorAll('#lignesBody tr').forEach(tr=>{
    const q = parseFloat(tr.querySelector('.l-qte').value)||0;
    const pu = parseFloat(tr.querySelector('.l-pu').value)||0;
    const t = q*pu; ht += t;
    tr.querySelector('.l-total').textContent = fmt(t);
  });
  const remise = parseFloat(document.getElementById('remise').value)||0;
  const tvaOn = document.querySelector('input[name="tva_applicable"]:checked').value === '1';
  const tvat = tvaOn ? (parseFloat(document.getElementById('tva').value)||0) : 0;
  const base = Math.max(0, ht - remise);
  const tva = base * tvat/100;
  document.getElementById('v-ht').textContent = fmt(ht);
  document.getElementById('ligne-tva').style.display = tvaOn ? 'flex' : 'none';
  document.getElementById('ligne-tva-non').style.display = tvaOn ? 'none' : 'flex';
  document.getElementById('v-tvat').textContent = tvat;
  document.getElementById('v-tva').textContent = fmt(tva);
  document.getElementById('v-ttc').textContent = fmt(base + tva);
}
/* Affiche les éléments déjà enregistrés à l'ouverture */
document.querySelectorAll('#lignesBody tr').forEach(function(tr){ renderIncl(tr); });
majTva();
calc();
</script>

<?php else: ?>
<!-- ============ LISTE RANGÉE ============ -->
<div class="panel glass">
  <h2><?= $isPro ? '📋 Proformas' : '🧾 Factures' ?> (<?= count($factures) ?>)
    <a href="factures.php<?= $isPro?'?doc=proforma&edit=new':'?edit=new' ?>" class="btn btn-gold btn-sm" style="margin-left:auto">➕ <?= $isPro?'Nouvelle proforma':'Nouvelle facture' ?></a>
  </h2>
  <?php
  $baseUrl = $isPro ? 'factures.php?doc=proforma' : 'factures.php';
  require __DIR__ . '/includes/rangement_vue.php';
  ?>
</div>

<?php include __DIR__ . '/includes/envoyer_modal.php'; ?>
<?php endif; ?>
<?php admin_footer(); ?>
