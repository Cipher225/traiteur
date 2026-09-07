<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/docauth.php';
require_once __DIR__ . '/../config/signature_mail.php';

/* ============================================================================
   MESSAGERIE SORTANTE
   ----------------------------------------------------------------------------
   Rien ne part sans une action explicite : vous choisissez le destinataire,
   vous rédigez, vous envoyez. Chaque message emporte la signature de
   l'entreprise et sa référence de vérification.
   ============================================================================ */

$erreurs = [];
$envoyes = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['envoyer'])) {
    csrf_check();

    $sujet = trim($_POST['sujet'] ?? '');
    $corps = doc_nettoyer_html((string)($_POST['corps'] ?? ''));

    /* Destinataires : ceux cochés dans la liste, plus ceux saisis à la main. */
    $liste = [];
    foreach ((array)($_POST['clients'] ?? []) as $cid) {
        $st = $pdo->prepare('SELECT id, nom, entreprise, email FROM clients WHERE id=? AND email<>""');
        $st->execute([(int)$cid]);
        if ($c = $st->fetch()) {
            $liste[mb_strtolower($c['email'])] = [
                'email' => $c['email'],
                'nom'   => trim((string)$c['entreprise']) !== '' ? $c['entreprise'] : $c['nom'],
                'client_id' => (int)$c['id'],
            ];
        }
    }
    foreach ((array)($_POST['manuels_liste'] ?? []) as $adr) {
        $adr = trim($adr);
        if ($adr !== '' && filter_var($adr, FILTER_VALIDATE_EMAIL)) {
            $liste[mb_strtolower($adr)] = ['email' => $adr, 'nom' => '', 'client_id' => null];
        }
    }

    /* ---- Pièces jointes ----
       Deux origines : les documents de l'application, régénérés en PDF au
       moment de l'envoi, et les fichiers choisis sur l'ordinateur ou le
       téléphone. */
    $pieces   = [];   // tout ce qui sera joint à l'envoi
    $fichiers = [];   // uniquement les fichiers venant de l'appareil
    $tempo    = [];
    $dossierPJ = __DIR__ . '/../uploads/tmp';
    if (!is_dir($dossierPJ)) @mkdir($dossierPJ, 0775, true);

    foreach ((array)($_POST['docs'] ?? []) as $ref) {
        if (!preg_match('/^(facture|proforma|livraison|recu|fiche):(\d+)$/', (string)$ref, $m)) continue;
        $chemin = $dossierPJ . '/pj-' . $m[1] . '-' . $m[2] . '-' . bin2hex(random_bytes(3)) . '.pdf';
        $cmdType = $m[1]; $cmdId = (int)$m[2];

        /* On appelle le générateur de PDF en interne : le client reçoit
           exactement le document qu'il verrait à l'écran. */
        $_GET['type'] = $cmdType; $_GET['id'] = (string)$cmdId;
        ob_start();
        try { include __DIR__ . '/pdf-piece.php'; } catch (Throwable $e) {}
        $pdf = ob_get_clean();

        if ($pdf !== '' && strncmp($pdf, '%PDF', 4) === 0) {
            file_put_contents($chemin, $pdf);
            $noms = ['facture' => 'Facture', 'proforma' => 'Proforma', 'livraison' => 'Bon-de-livraison',
                     'recu' => 'Recu', 'fiche' => 'Bulletin'];
            $pieces[] = ['chemin' => $chemin, 'nom' => ($noms[$cmdType] ?? 'Document') . '-' . $cmdId . '.pdf',
                         'type' => 'application/pdf'];
            $tempo[] = $chemin;
        }
    }

    /* Fichiers envoyés depuis l'appareil */
    if (!empty($_FILES['fichiers']['name'][0])) {
        $autorises = ['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx','txt','zip'];
        foreach ($_FILES['fichiers']['name'] as $i => $nom) {
            if ($_FILES['fichiers']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
            if (!in_array($ext, $autorises, true)) {
                $erreurs[] = 'Format non autorisé : ' . e($nom); continue;
            }
            if ($_FILES['fichiers']['size'][$i] > 8 * 1024 * 1024) {
                $erreurs[] = 'Fichier trop lourd (8 Mo maximum) : ' . e($nom); continue;
            }
            $dest = $dossierPJ . '/pj-' . bin2hex(random_bytes(5)) . '.' . $ext;
            if (move_uploaded_file($_FILES['fichiers']['tmp_name'][$i], $dest)) {
                $p = ['chemin' => $dest, 'nom' => preg_replace('/[^\w.\-]/u', '_', $nom)];
                $pieces[]   = $p;
                $fichiers[] = $p;
                $tempo[]    = $dest;
            }
        }
    }

    if ($sujet === '')                       $erreurs[] = "L'objet du message est obligatoire.";
    if (trim(strip_tags($corps)) === '')     $erreurs[] = 'Le message est vide.';
    if (!$liste)                             $erreurs[] = 'Aucun destinataire valide sélectionné.';
    if (empty($settings['smtp_hote']))       $erreurs[] = "Aucun serveur d'envoi configuré (Paramètres → Emails).";

    /* Un employé rédige et soumet ; seul l'administrateur déclenche l'envoi.
       Le message est mis en attente, avec la liste des pièces à joindre. */
    $soumission = !is_admin();

    if (!$erreurs && $soumission) {
        /* Les fichiers choisis sur l'appareil sont mis de côté : ils seront
           joints au moment où l'administrateur approuvera le message. */
        $attente = __DIR__ . '/../uploads/tmp/attente';
        if (!is_dir($attente)) @mkdir($attente, 0775, true);
        $gardes = [];
        foreach ($fichiers as $p) {
            if (empty($p['chemin']) || !is_file($p['chemin'])) continue;
            $dest = $attente . '/' . bin2hex(random_bytes(6)) . '-' . preg_replace('/[^\w.\-]/u', '_', $p['nom']);
            if (@copy($p['chemin'], $dest)) $gardes[] = ['chemin' => $dest, 'nom' => $p['nom']];
        }
        foreach ($tempo as $t) { if (is_file($t)) @unlink($t); }
        $noms = ['facture' => 'Facture', 'proforma' => 'Proforma', 'livraison' => 'Bon-de-livraison',
                 'recu' => 'Recu', 'fiche' => 'Bulletin'];
        $libelles = [];
        foreach ((array)($_POST['docs'] ?? []) as $ref) {
            [$t, $i] = array_pad(explode(':', (string)$ref, 2), 2, '');
            $libelles[] = ($noms[$t] ?? 'Document') . '-' . (int)$i . '.pdf';
        }
        foreach ($gardes as $g) $libelles[] = $g['nom'];
        $listeNoms = implode(', ', $libelles);

        foreach ($liste as $d) {
            $pdo->prepare('INSERT INTO emails_envoyes (reference, empreinte, destinataire, destinataire_nom,
                           client_id, sujet, corps, piece_jointe, pieces_ref, fichiers, envoye_par, envoye_par_nom,
                           statut, envoye_le, authentifie)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([signature_reference($pdo), '', $d['email'], $d['nom'], $d['client_id'],
                           $sujet, $corps, mb_substr($listeNoms, 0, 190),
                           implode(',', (array)($_POST['docs'] ?? [])),
                           $gardes ? json_encode($gardes, JSON_UNESCAPED_UNICODE) : null,
                           (int)($_SESSION['admin_id'] ?? 0) ?: null, (string)($_SESSION['admin_nom'] ?? ''),
                           'en_attente', date('Y-m-d H:i:s'), !empty($_POST['authentifier']) ? 1 : 0]);
        }
        journaliser($pdo, 'soumission', 'message', null,
                    count($liste) . ' message(s) soumis à validation — ' . mb_substr($sujet, 0, 60));
        flash(count($liste) . ' message' . (count($liste) > 1 ? 's soumis' : ' soumis')
              . " à l'approbation de l'administrateur. ⏳");
        header('Location: messages.php'); exit;
    }

    if (!$erreurs) {
        $dossier = __DIR__ . '/../uploads/signatures';
        if (!is_dir($dossier)) @mkdir($dossier, 0775, true);

        /* L'authentification se décide message par message. Une confirmation de
           rendez-vous n'en a pas besoin ; une proforma ou une facture, si. */
        $authentifier    = !empty($_POST['authentifier']);
        $signaturesTempo = [];

        foreach ($liste as $d) {
            $date      = date('Y-m-d H:i:s');
            $reference = signature_reference($pdo);
            $empreinte = signature_empreinte($d['email'], $sujet, $corps, $date);

            /* La signature accompagne TOUJOURS le message : elle porte les
               coordonnées de l'entreprise. Seul le cartouche de vérification
               (référence, empreinte, QR) dépend du choix d'authentification. */
            $fichier = $dossier . '/' . $reference . '.png';
            signature_image($settings, $reference, $empreinte, $fichier, $authentifier);
            if (!$authentifier) $signaturesTempo[] = $fichier;

            $site = adresse_site($settings);
            $urlSig = $site . '/signature-mail.php?c=' . urlencode($reference);
            $urlVerif = $site . '/verifier.php?c=' . urlencode($reference);

            /* La signature et le logo voyagent AVEC le message : une image
               chargée depuis Internet serait bloquée par la plupart des
               messageries, et n'apparaîtrait pas chez le destinataire. */
            $images = [['cid' => 'signature-entreprise', 'chemin' => $fichier]];
            $cheminLogo = __DIR__ . '/../uploads/' . (string)($settings['logo'] ?? '');
            if (!empty($settings['logo']) && is_file($cheminLogo)) {
                $images[] = ['cid' => 'logo-entreprise', 'chemin' => $cheminLogo];
            }

            $message = $corps
                . '<div style="margin-top:26px;border-top:1px solid #e8ecf2;padding-top:16px">'
                . '<img src="cid:signature-entreprise" alt="' . e($settings['nom_entreprise'] ?? '') . '" style="max-width:100%;height:auto;display:block">'
                . ($authentifier
                    ? '<p style="margin:10px 0 0;font-size:11px;color:#8a9ab5;line-height:1.6">'
                      . 'Référence de ce message : <strong style="color:#0a1f44">' . e($reference) . '</strong>'
                      . ($site !== ''
                          ? ' — <a href="' . e($urlVerif) . '" style="color:#b8870f">vérifier son authenticité</a>' : '')
                      . '</p>'
                    : '')
                . '</div>';

            $motif = null;
            $ok = envoyer_email($pdo, $d['email'], $sujet, $message,
                                (string)($settings['email'] ?? ''), $pieces, $motif, $images);

            $listePJ = implode(', ', array_map(fn($p) => $p['nom'], $pieces));
            $pdo->prepare('INSERT INTO emails_envoyes (reference, empreinte, destinataire, destinataire_nom,
                           client_id, sujet, corps, piece_jointe, envoye_par, envoye_par_nom,
                           statut, envoye_le, authentifie)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$reference, $empreinte, $d['email'], $d['nom'], $d['client_id'], $sujet, $corps,
                           mb_substr($listePJ, 0, 190),
                           (int)($_SESSION['admin_id'] ?? 0) ?: null, (string)($_SESSION['admin_nom'] ?? ''),
                           $ok ? 'envoye' : 'echoue', $date, $authentifier ? 1 : 0]);
            if (!$ok && $motif) {
                $pdo->prepare('UPDATE emails_envoyes SET erreur=? WHERE reference=?')
                    ->execute([mb_substr($motif, 0, 250), $reference]);
            }

            if ($ok) { $envoyes++; }
            else { $erreurs[] = "Échec vers " . e($d['email']) . " — " . e($motif ?: 'cause inconnue.'); }
        }

        foreach ($tempo as $t) { if (is_file($t)) @unlink($t); }   // fichiers temporaires
        /* Seules les signatures authentifiées sont conservées : elles servent
           à la vérification. Les autres n'ont plus d'utilité une fois parties. */
        if (!$authentifier) {
            foreach ($signaturesTempo as $sg) { if (is_file($sg)) @unlink($sg); }
        }
        journaliser($pdo, 'email', 'message', null, $envoyes . ' message(s) — ' . mb_substr($sujet, 0, 80));
        if ($envoyes) flash($envoyes . ' message' . ($envoyes > 1 ? 's envoyés' : ' envoyé') . '. ✉️');
        if (!$erreurs) { header('Location: messages.php'); exit; }
    }
}

/* ---- Approbation d'un message soumis par un employé ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['decider'])) {
    csrf_check();
    if (!is_admin()) { flash("Action réservée à l'administrateur.", 'error'); header('Location: messages.php'); exit; }

    $id  = (int)($_POST['message_id'] ?? 0);
    $st  = $pdo->prepare('SELECT * FROM emails_envoyes WHERE id=? AND statut="en_attente"');
    $st->execute([$id]);
    $msg = $st->fetch();

    if (!$msg) {
        flash('Message introuvable ou déjà traité.', 'error');
    } elseif ($_POST['decider'] === 'refuser') {
        $pdo->prepare('UPDATE emails_envoyes SET statut="refuse", approuve_par=?, approuve_le=NOW() WHERE id=?')
            ->execute([(int)$_SESSION['admin_id'], $id]);
        foreach (json_decode((string)$msg['fichiers'], true) ?: [] as $g) {
            if (!empty($g['chemin']) && is_file($g['chemin'])) @unlink($g['chemin']);
        }
        journaliser($pdo, 'refus', 'message', $id, 'Message refusé — ' . mb_substr($msg['sujet'], 0, 60));
        flash('Message refusé. Il ne sera pas envoyé.');
    } else {
        /* Approuvé : on l'envoie réellement, avec sa signature et ses pièces. */
        $dossier = __DIR__ . '/../uploads/signatures';
        if (!is_dir($dossier)) @mkdir($dossier, 0775, true);

        $date      = date('Y-m-d H:i:s');
        $authOk    = !empty($msg['authentifie']);
        $empreinte = signature_empreinte($msg['destinataire'], $msg['sujet'], $msg['corps'], $date);
        $fichier   = $dossier . '/' . $msg['reference'] . '.png';
        signature_image($settings, $msg['reference'], $empreinte, $fichier, $authOk);

        $site     = adresse_site($settings);
        $urlVerif = $site . '/verifier.php?c=' . urlencode($msg['reference']);

        $images = [['cid' => 'signature-entreprise', 'chemin' => $fichier]];
        $cheminLogo = __DIR__ . '/../uploads/' . (string)($settings['logo'] ?? '');
        if (!empty($settings['logo']) && is_file($cheminLogo)) {
            $images[] = ['cid' => 'logo-entreprise', 'chemin' => $cheminLogo];
        }

        $message = $msg['corps']
            . '<div style="margin-top:26px;border-top:1px solid #e8ecf2;padding-top:16px">'
            . '<img src="cid:signature-entreprise" alt="' . e($settings['nom_entreprise'] ?? '') . '" style="max-width:100%;height:auto;display:block">'
            . ($authOk
                ? '<p style="margin:10px 0 0;font-size:11px;color:#8a9ab5;line-height:1.6">'
                  . 'Référence de ce message : <strong style="color:#0a1f44">' . e($msg['reference']) . '</strong>'
                  . ($site !== '' ? ' — <a href="' . e($urlVerif) . '" style="color:#b8870f">vérifier son authenticité</a>' : '')
                  . '</p>'
                : '')
            . '</div>';

        /* Pièces jointes : les documents de l'application sont régénérés, les
           fichiers déposés par l'employé sont repris tels quels. */
        $pieces = []; $tempo = [];
        foreach (json_decode((string)$msg['fichiers'], true) ?: [] as $g) {
            if (!empty($g['chemin']) && is_file($g['chemin'])) {
                $pieces[] = ['chemin' => $g['chemin'], 'nom' => $g['nom'] ?? basename($g['chemin'])];
            }
        }
        foreach (array_filter(explode(',', (string)$msg['pieces_ref'])) as $ref) {
            if (!preg_match('/^(facture|proforma|livraison|recu|fiche):(\d+)$/', trim($ref), $m)) continue;
            $chemin = __DIR__ . '/../uploads/tmp/pj-' . $m[1] . '-' . $m[2] . '-' . bin2hex(random_bytes(3)) . '.pdf';
            $_GET['type'] = $m[1]; $_GET['id'] = $m[2];
            ob_start(); try { include __DIR__ . '/pdf-piece.php'; } catch (Throwable $e) {} $pdf = ob_get_clean();
            if ($pdf !== '' && strncmp($pdf, '%PDF', 4) === 0) {
                file_put_contents($chemin, $pdf);
                $noms = ['facture' => 'Facture', 'proforma' => 'Proforma', 'livraison' => 'Bon-de-livraison',
                         'recu' => 'Recu', 'fiche' => 'Bulletin'];
                $pieces[] = ['chemin' => $chemin, 'nom' => ($noms[$m[1]] ?? 'Document') . '-' . $m[2] . '.pdf',
                             'type' => 'application/pdf'];
                $tempo[] = $chemin;
            }
        }

        $motif = null;
        $ok = envoyer_email($pdo, $msg['destinataire'], $msg['sujet'], $message,
                            (string)($settings['email'] ?? ''), $pieces, $motif, $images);

        $pdo->prepare('UPDATE emails_envoyes SET statut=?, empreinte=?, erreur=?, envoye_le=?,
                       approuve_par=?, approuve_le=NOW(),
                       piece_jointe=? WHERE id=?')
            ->execute([$ok ? 'envoye' : 'echoue', $empreinte, mb_substr((string)$motif, 0, 250), $date,
                       (int)$_SESSION['admin_id'],
                       mb_substr(implode(', ', array_map(fn($p) => $p['nom'], $pieces)), 0, 190), $id]);

        foreach ($tempo as $t) { if (is_file($t)) @unlink($t); }
        foreach (json_decode((string)$msg['fichiers'], true) ?: [] as $g) {
            if (!empty($g['chemin']) && is_file($g['chemin'])) @unlink($g['chemin']);
        }
        if (!$authOk && is_file($fichier)) @unlink($fichier);

        journaliser($pdo, 'approbation', 'message', $id, ($ok ? 'Envoyé' : 'Échec') . ' — ' . mb_substr($msg['sujet'], 0, 60));
        flash($ok ? 'Message approuvé et envoyé. ✉️' : 'Échec de l\'envoi : ' . e($motif ?: 'cause inconnue.'),
              $ok ? 'success' : 'error');
    }
    header('Location: messages.php'); exit;
}

/* ---- Historique : l'administrateur peut le purger ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purger_historique'])) {
    csrf_check();
    if (!is_admin()) { flash('Action réservée à l\'administrateur.', 'error'); }
    else {
        $quoi = $_POST['purger_historique'];
        if ($quoi === 'tout') {
            $n = (int)$pdo->query('SELECT COUNT(*) FROM emails_envoyes')->fetchColumn();
            $pdo->exec('DELETE FROM emails_envoyes');
            journaliser($pdo, 'purge', 'messagerie', null, $n . ' message(s) effacé(s) de l\'historique');
            flash($n . ' message(s) effacé(s) de l\'historique.');
        } else {
            $id = (int)$quoi;
            $pdo->prepare('DELETE FROM emails_envoyes WHERE id=?')->execute([$id]);
            journaliser($pdo, 'purge', 'messagerie', $id, 'Message retiré de l\'historique');
            flash('Message retiré de l\'historique.');
        }
    }
    header('Location: messages.php'); exit;
}

$clients = $pdo->query("SELECT id, nom, entreprise, email, type_client FROM clients
                        WHERE email <> '' ORDER BY COALESCE(NULLIF(entreprise,''), nom)")->fetchAll();
/* Un employé ne voit que ses propres messages ; l'administrateur voit tout. */
if (is_admin()) {
    $enAttente  = $pdo->query("SELECT * FROM emails_envoyes WHERE statut='en_attente' ORDER BY envoye_le")->fetchAll();
    $historique = $pdo->query("SELECT * FROM emails_envoyes WHERE statut<>'en_attente'
                               ORDER BY envoye_le DESC LIMIT 30")->fetchAll();
} else {
    $uid = (int)($_SESSION['admin_id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM emails_envoyes WHERE envoye_par=? ORDER BY envoye_le DESC LIMIT 30");
    $st->execute([$uid]);
    $historique = $st->fetchAll();
    $enAttente  = [];
}

admin_header('E-mail', 'messages', $pdo, $settings);
?>

<?php
/* On rappelle toujours depuis quelle adresse partiront les messages : c'est la
   première question qu'on se pose, et la première cause d'échec. */
$expediteur = trim((string)($settings['email'] ?? ''));
$serveur    = trim((string)($settings['smtp_hote'] ?? ''));
$fournisseurs = ['smtp.gmail.com' => 'Gmail', 'smtp.hostinger.com' => 'Hostinger',
                 'smtp.office365.com' => 'Outlook', 'ssl0.ovh.net' => 'OVH',
                 'smtp.mail.yahoo.com' => 'Yahoo', 'smtp.zoho.com' => 'Zoho'];
$nomFournisseur = $fournisseurs[$serveur] ?? ($serveur !== '' ? $serveur : '');
?>

<div class="panel glass expediteur">
  <?php if ($serveur === '' || $expediteur === ''): ?>
    <span class="ex-ico">⚠️</span>
    <div class="ex-t">
      <strong>Aucune adresse d'envoi configurée</strong>
      <div><?php if (is_admin()): ?>Vos messages ne peuvent pas partir. Renseignez votre compte dans
        <a href="parametres.php?section=<?= substr(md5('Emails'), 0, 8) ?>">Paramètres → Emails</a>,
        puis lancez le test d'envoi.<?php else: ?>L'envoi n'est pas encore configuré.
        Signalez-le à votre administrateur.<?php endif; ?></div>
    </div>
  <?php else: ?>
    <span class="ex-ico">📤</span>
    <div class="ex-t">
      <strong>Vos messages partiront de <?= e($expediteur) ?></strong>
      <?php /* Les réglages du serveur d'envoi ne regardent que l'administrateur. */ ?>
      <?php if (is_admin()): ?>
      <div>Via <?= e($nomFournisseur) ?><?php if (!empty($settings['smtp_port'])): ?> — port <?= e($settings['smtp_port']) ?><?php endif; ?>.
        <a href="parametres.php?section=<?= substr(md5('Emails'), 0, 8) ?>">Changer de compte ou tester l'envoi</a></div>
      <?php else: ?>
      <div>L'adresse d'expédition est réglée par votre administrateur.</div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if (!is_admin()): ?>
<div class="panel glass expediteur" style="border-left:4px solid var(--gold)">
  <span class="ex-ico">⏳</span>
  <div class="ex-t">
    <strong>Vos messages passent par une validation</strong>
    <div>Rédigez votre message : il sera soumis à l'administrateur, qui l'enverra après vérification.
      Vous suivrez son état ci-dessous.</div>
  </div>
</div>
<?php endif; ?>

<?php if ($erreurs): ?>
<div class="panel glass" style="margin-bottom:14px;border-left:4px solid #f87171">
  <?php foreach ($erreurs as $er): ?><div style="font-size:13px;color:#f87171">⚠️ <?= $er ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post" id="form-mail" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">

<div class="mail-duo">
  <!-- ============ Destinataires ============ -->
  <div class="panel glass">
    <h2>👥 Destinataires</h2>
    <p class="mail-aide">Choisissez un client dans la liste, ou saisissez une adresse. Rien ne part sans votre validation.</p>

    <div class="field">
      <label>Client</label>
      <div class="mail-ajout">
        <select class="input" id="sel-client">
          <option value="">— Choisir un client —</option>
          <?php foreach ($clients as $cl):
            $nom = trim((string)$cl['entreprise']) !== '' ? $cl['entreprise'] : $cl['nom']; ?>
          <option value="<?= (int)$cl['id'] ?>" data-email="<?= e($cl['email']) ?>" data-nom="<?= e($nom) ?>">
            <?= ($cl['type_client'] ?? '') === 'entreprise' ? '🏢 ' : '👤 ' ?><?= e($nom) ?> — <?= e($cl['email']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <button type="button" class="btn btn-glass" id="add-client">Ajouter</button>
      </div>
    </div>

    <div class="field">
      <label>Autre adresse</label>
      <div class="mail-ajout">
        <input class="input" type="email" id="mail-libre" placeholder="adresse@exemple.ci">
        <button type="button" class="btn btn-glass" id="add-libre">Ajouter</button>
      </div>
    </div>

    <label style="display:block;margin:10px 0 6px;font-size:12.5px;font-weight:600;color:var(--ink-dim)">
      Destinataires retenus <span class="mail-compte" id="compte-sel">0</span></label>
    <div class="mail-jetons" id="jetons-dest">
      <span class="mj-vide">Aucun destinataire pour l'instant.</span>
    </div>
    <div id="champs-dest"></div>

    <!-- ============ Pièces jointes ============ -->
    <h2 style="margin-top:20px">📎 Pièces jointes</h2>
    <p class="mail-aide">Les documents du client sélectionné, ou un fichier de votre appareil.</p>

    <div class="field">
      <label>Document du client</label>
      <div class="mail-ajout">
        <select class="input" id="sel-doc" disabled>
          <option value="">— Choisissez d'abord un client —</option>
        </select>
        <button type="button" class="btn btn-glass" id="add-doc">Joindre</button>
      </div>
    </div>

    <div class="field">
      <label>Fichier de l'ordinateur ou du téléphone</label>
      <input class="input" type="file" name="fichiers[]" id="fichiers" multiple
             accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.txt,.zip">
      <span class="mail-note">8 Mo maximum par fichier.</span>
    </div>

    <div class="mail-jetons" id="jetons-pj">
      <span class="mj-vide">Aucune pièce jointe.</span>
    </div>
    <div id="champs-pj"></div>
  </div>

  <!-- ============ Signature ============ -->
  <div class="panel glass">
    <h2>🔏 Signature de l'entreprise</h2>

    <?php $authDefaut = ($settings['signature_auth_defaut'] ?? '1') !== '0'; ?>
    <?php $authCoche = !empty($_POST['authentifier']) || (empty($_POST['envoyer']) && $authDefaut); ?>
    <label class="bascule <?= $authCoche ? 'actif' : '' ?>">
      <input type="checkbox" name="authentifier" value="1" id="bascule-auth"
             <?= (!empty($_POST['authentifier']) || (empty($_POST['envoyer']) && $authDefaut)) ? 'checked' : '' ?>>
      <span class="bc-piste"><span class="bc-rond"></span></span>
      <span class="bc-txt">
        <strong>Signature authentifiable</strong>
        <span>Référence unique, QR de vérification et empreinte du message.</span>
      </span>
    </label>

    <div id="zone-sig" <?= $authCoche ? '' : 'hidden' ?>>
    <p class="mail-aide" style="margin-top:12px">Voici ce que verra votre destinataire.</p>
    <div class="mail-sig">
      <img src="signature-apercu.php" alt="Signature" style="width:100%;height:auto;border-radius:8px">
    </div>
    <div class="mail-secu">
      <div class="ms-t">Comment fonctionne l'authentification</div>
      <div class="ms-l"><b>1.</b> Chaque message reçoit une <b>référence unique</b> et une empreinte calculée sur son contenu.</div>
      <div class="ms-l"><b>2.</b> La signature est une <b>image fabriquée par le serveur</b> : elle ne peut pas être modifiée dans un client de messagerie.</div>
      <div class="ms-l"><b>3.</b> Le destinataire scanne le code ou clique le lien : le serveur <b>confirme</b> que ce message vient bien de vous.</div>
      <div class="ms-n">Une copie de l'image reste possible — c'est vrai de toute image. Mais un message contrefait n'aura pas de référence valide : la vérification échouera et la fraude sera visible.</div>
    </div>
    </div>

    <div id="zone-simple" <?= $authCoche ? 'hidden' : '' ?>>
      <p class="mail-aide" style="margin-top:12px">Voici ce que verra votre destinataire.</p>
      <div class="mail-sig">
        <img src="signature-apercu.php?auth=0" alt="Signature" style="width:100%;height:auto;border-radius:8px">
      </div>
      <p class="mail-note" style="margin-top:9px">
        Vos coordonnées restent présentes : seul le cartouche de vérification disparaît —
        ni référence, ni QR. À réserver aux messages courants comme une confirmation de
        rendez-vous. Pour une proforma ou une facture, laissez l'authentification active.
      </p>
    </div>
  </div>
</div>

<!-- ============ Rédaction ============ -->
<div class="panel glass" style="margin-top:14px">
  <h2>✍️ Message</h2>

  <div class="field">
    <label>Objet *</label>
    <input class="input" name="sujet" required maxlength="255" value="<?= e($_POST['sujet'] ?? '') ?>" placeholder="ex : Votre proforma pour le séminaire du 12 septembre">
  </div>

  <label style="display:block;margin:12px 0 6px;font-size:12.5px;font-weight:600;color:var(--ink-dim)">Contenu</label>
  <div class="ed-barre">
    <button type="button" data-cmd="bold" title="Gras"><b>G</b></button>
    <button type="button" data-cmd="italic" title="Italique"><i>I</i></button>
    <button type="button" data-cmd="underline" title="Souligné"><u>S</u></button>
    <span class="ed-sep"></span>
    <select data-cmd="formatBlock" title="Style">
      <option value="p">Paragraphe</option>
      <option value="h2">Titre</option>
      <option value="h3">Sous-titre</option>
    </select>
    <select data-cmd="fontSize" title="Taille">
      <option value="2">Petit</option>
      <option value="3" selected>Normal</option>
      <option value="5">Grand</option>
    </select>
    <span class="ed-sep"></span>
    <button type="button" data-cmd="insertUnorderedList" title="Liste à puces">•</button>
    <button type="button" data-cmd="insertOrderedList" title="Liste numérotée">1.</button>
    <span class="ed-sep"></span>
    <button type="button" data-cmd="justifyLeft" title="Aligner à gauche">⬅</button>
    <button type="button" data-cmd="justifyCenter" title="Centrer">↔</button>
    <button type="button" data-cmd="justifyFull" title="Justifier">☰</button>
    <span class="ed-sep"></span>
    <button type="button" id="ed-lien" title="Insérer un lien">🔗</button>
    <button type="button" id="ed-couleur" title="Couleur du texte">🎨</button>
    <span class="ed-sep"></span>
    <button type="button" id="ed-modele" title="Modèles de message">📄 Modèles</button>
  </div>

  <div class="ed-zone" id="editeur" contenteditable="true" lang="fr" spellcheck="true"><?= $_POST['corps'] ?? '<p>Bonjour,</p><p><br></p><p>Cordialement,</p>' ?></div>
  <textarea name="corps" id="corps" hidden></textarea>

  <div style="display:flex;gap:9px;flex-wrap:wrap;margin-top:14px;align-items:center">
    <button class="btn btn-gold" name="envoyer" value="1" id="btn-envoi">
      <?= is_admin() ? '✉️ Envoyer le message' : "⏳ Soumettre à l'approbation" ?></button>
    <span class="mail-note" id="resume-envoi"></span>
  </div>
</div>
</form>

<?php if (is_admin() && $enAttente): ?>
<div class="panel glass" style="margin-top:14px;border-left:4px solid #f0b429">
  <h2>⏳ Messages en attente de votre accord <span class="cnt"><?= count($enAttente) ?></span></h2>
  <p class="mail-aide">Rédigés par vos employés. Rien ne part sans votre validation.</p>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Rédigé par</th><th>Destinataire</th><th>Objet</th><th>Message</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($enAttente as $m): ?>
        <tr>
          <td style="font-size:12.5px"><?= e($m['envoye_par_nom']) ?>
            <div style="font-size:11px;color:var(--ink-faint)"><?= date('d/m/Y H:i', strtotime($m['envoye_le'])) ?></div></td>
          <td><?= e($m['destinataire_nom'] ?: $m['destinataire']) ?>
            <div style="font-size:11px;color:var(--ink-faint)"><?= e($m['destinataire']) ?></div></td>
          <td style="font-size:12.5px"><?= e(mb_substr($m['sujet'], 0, 45)) ?></td>
          <td style="font-size:11.5px;color:var(--ink-faint);max-width:300px">
            <?php /* Le message se lit en entier avant décision : on n'approuve pas
                     un texte qu'on n'a pas lu. */ ?>
            <details class="lire-msg">
              <summary>📄 Lire le message</summary>
              <div class="lm-corps"><?= $m['corps'] ?></div>
              <?php
                $nbDocs = count(array_filter(explode(',', (string)$m['pieces_ref'])));
                $fic = json_decode((string)$m['fichiers'], true) ?: [];
              ?>
              <?php if ($nbDocs || $fic): ?>
              <div class="lm-pj">
                <strong>Pièces jointes</strong>
                <?php foreach (array_filter(explode(',', (string)$m['pieces_ref'])) as $r): ?>
                  <?php [$t, $i] = array_pad(explode(':', trim($r), 2), 2, ''); ?>
                  <a href="pdf.php?type=<?= e($t) ?>&id=<?= (int)$i ?>" target="_blank">📄 <?= e(ucfirst($t)) ?> n°<?= (int)$i ?></a>
                <?php endforeach; ?>
                <?php foreach ($fic as $g): ?>
                  <span>📎 <?= e($g['nom'] ?? '') ?></span>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
            </details>
            <?php if ($nbDocs || $fic): ?>
            <div style="color:var(--gold);margin-top:4px">📎 <?= $nbDocs + count($fic) ?> pièce(s)</div>
            <?php endif; ?></td>
          <td style="white-space:nowrap">
            <form method="post" style="display:inline" onsubmit="return confirm('Envoyer ce message ?')">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="message_id" value="<?= (int)$m['id'] ?>">
              <button class="btn btn-gold btn-sm" name="decider" value="approuver">✓ Approuver</button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Refuser ce message ?')">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="message_id" value="<?= (int)$m['id'] ?>">
              <button class="btn btn-danger btn-sm" name="decider" value="refuser">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($historique): ?>
<div class="panel glass" style="margin-top:14px">
  <h2><?= is_admin() ? '📜 Messages envoyés' : '📜 Mes messages' ?></h2>
  <?php if (is_admin()): ?>
  <form method="post" style="margin:-40px 0 12px;display:flex;justify-content:flex-end"
        onsubmit="return confirm('Effacer tout l\'historique des messages envoyés ? Cette action est définitive.')">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <button class="btn btn-glass btn-sm" name="purger_historique" value="tout">🧹 Vider l'historique</button>
  </form>
  <?php endif; ?>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Date</th><th>Destinataire</th><th>Objet</th><th>Pièces</th><th>Référence</th><th>État</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($historique as $h): ?>
        <tr>
          <td style="white-space:nowrap"><?= date('d/m/Y H:i', strtotime($h['envoye_le'])) ?></td>
          <td><?= e($h['destinataire_nom'] ?: $h['destinataire']) ?>
            <div style="font-size:11px;color:var(--ink-faint)"><?= e($h['destinataire']) ?></div></td>
          <td style="font-size:12.5px"><?= e(mb_substr($h['sujet'], 0, 50)) ?></td>
          <td style="font-size:11.5px;color:var(--ink-faint)">
            <?= trim((string)$h['piece_jointe']) !== '' ? '📎 ' . e(mb_substr($h['piece_jointe'], 0, 40)) : '—' ?></td>
          <td style="font-family:monospace;font-size:11.5px">
            <?= empty($h['authentifie']) ? '<span style="font-family:inherit;color:var(--ink-faint)">non authentifié</span>' : e($h['reference']) ?></td>
          <td><span class="etat-pay <?= $h['statut'] === 'envoye' ? 'ep-paye' : ($h['statut'] === 'en_attente' ? 'ep-attente' : 'ep-echoue') ?>">
            <?= ['envoye'=>'Envoyé','echoue'=>'Échec','en_attente'=>'En attente','refuse'=>'Refusé'][$h['statut']] ?? $h['statut'] ?></span></td>
          <td><?php if (is_admin()): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Retirer ce message de l\'historique ?')">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <button class="btn btn-danger btn-sm" name="purger_historique" value="<?= (int)$h['id'] ?>">✕</button>
            </form>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  var ed = document.getElementById('editeur');
  var champ = document.getElementById('corps');

  /* Barre d'outils : chaque bouton agit sur la sélection en cours. */
  document.querySelectorAll('.ed-barre [data-cmd]').forEach(function (b) {
    var cmd = b.dataset.cmd;
    if (b.tagName === 'SELECT') {
      b.addEventListener('change', function () { ed.focus(); document.execCommand(cmd, false, this.value); });
    } else {
      b.addEventListener('click', function () { ed.focus(); document.execCommand(cmd, false, null); });
    }
  });

  document.getElementById('ed-lien').addEventListener('click', function () {
    var u = prompt('Adresse du lien :', 'https://');
    if (u) { ed.focus(); document.execCommand('createLink', false, u); }
  });
  document.getElementById('ed-couleur').addEventListener('click', function () {
    var c = prompt('Couleur (nom ou code) :', '#d4a526');
    if (c) { ed.focus(); document.execCommand('foreColor', false, c); }
  });

  /* Modèles : un point de départ, jamais un envoi automatique. */
  var modeles = {
    'Devis à valider': '<p>Bonjour,</p><p>Veuillez trouver ci-joint votre proforma. Il reste valable 15 jours.</p><p>Je reste à votre disposition pour tout ajustement.</p><p>Cordialement,</p>',
    'Confirmation de commande': '<p>Bonjour,</p><p>Nous confirmons votre commande. Notre équipe sera sur place comme convenu.</p><p>Cordialement,</p>',
    'Rappel de facture': '<p>Bonjour,</p><p>Sauf erreur de notre part, la facture jointe reste à régler.</p><p>Merci de votre confiance.</p>',
    'Remerciement après événement': '<p>Bonjour,</p><p>Merci de nous avoir fait confiance pour votre événement. Ce fut un plaisir.</p><p>Au plaisir de vous servir à nouveau,</p>'
  };
  document.getElementById('ed-modele').addEventListener('click', function () {
    var noms = Object.keys(modeles);
    var choix = prompt('Modèle :\n' + noms.map(function (n, i) { return (i + 1) + '. ' + n; }).join('\n'), '1');
    var i = parseInt(choix, 10) - 1;
    if (noms[i]) ed.innerHTML = modeles[noms[i]];
  });

  /* ---------------------------------------------------------------
     Destinataires et pièces jointes sous forme de jetons : on voit
     d'un coup d'œil qui recevra le message et ce qu'il contiendra.
     --------------------------------------------------------------- */
  var dests = [];   // { email, nom, client }
  var pjs   = [];   // { ref, libelle }

  function dessinerJetons() {
    var zd = document.getElementById('jetons-dest');
    var cd = document.getElementById('champs-dest');
    zd.innerHTML = ''; cd.innerHTML = '';
    if (!dests.length) {
      zd.innerHTML = '<span class="mj-vide">Aucun destinataire pour l\'instant.</span>';
    } else {
      dests.forEach(function (d, i) {
        var j = document.createElement('span');
        j.className = 'mj';
        j.innerHTML = '<b>' + (d.nom || d.email) + '</b>' + (d.nom ? '<i>' + d.email + '</i>' : '') +
                      '<button type="button" data-i="' + i + '" class="mj-x">✕</button>';
        zd.appendChild(j);
        var h = document.createElement('input');
        h.type = 'hidden'; h.name = d.client ? 'clients[]' : 'manuels_liste[]';
        h.value = d.client ? d.client : d.email;
        cd.appendChild(h);
      });
    }
    document.getElementById('compte-sel').textContent = dests.length;

    var zp = document.getElementById('jetons-pj');
    var cp = document.getElementById('champs-pj');
    zp.innerHTML = ''; cp.innerHTML = '';
    if (!pjs.length) {
      zp.innerHTML = '<span class="mj-vide">Aucune pièce jointe.</span>';
    } else {
      pjs.forEach(function (p, i) {
        var j = document.createElement('span');
        j.className = 'mj mj-pj';
        j.innerHTML = '📄 <b>' + p.libelle + '</b><button type="button" data-p="' + i + '" class="mj-x">✕</button>';
        zp.appendChild(j);
        var h = document.createElement('input');
        h.type = 'hidden'; h.name = 'docs[]'; h.value = p.ref;
        cp.appendChild(h);
      });
    }

    var n = dests.length;
    document.getElementById('resume-envoi').textContent =
      n ? 'Le message partira à ' + n + ' destinataire(s).' : 'Aucun destinataire choisi.';
  }

  document.addEventListener('click', function (e) {
    if (!e.target.classList.contains('mj-x')) return;
    if (e.target.dataset.i !== undefined) dests.splice(+e.target.dataset.i, 1);
    if (e.target.dataset.p !== undefined) pjs.splice(+e.target.dataset.p, 1);
    dessinerJetons();

  });

  var selClient = document.getElementById('sel-client');

  document.getElementById('add-client').addEventListener('click', function () {
    var o = selClient.options[selClient.selectedIndex];
    if (!o || !o.value) return;
    if (dests.some(function (d) { return d.email === o.dataset.email; })) return;
    dests.push({ email: o.dataset.email, nom: o.dataset.nom, client: o.value });
    dessinerJetons();
  });

  document.getElementById('add-libre').addEventListener('click', function () {
    var champ = document.getElementById('mail-libre');
    var v = champ.value.trim();
    if (!v) return;
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v)) { alert('Adresse email invalide.'); return; }
    if (!dests.some(function (d) { return d.email === v; })) dests.push({ email: v, nom: '', client: null });
    champ.value = '';
    dessinerJetons();
  });
  document.getElementById('mail-libre').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('add-libre').click(); }
  });

  /* Documents du client : chargés dès qu'on le choisit. */
  var selDoc = document.getElementById('sel-doc');
  selClient.addEventListener('change', function () {
    var cid = this.value;
    selDoc.innerHTML = '<option value="">Chargement…</option>';
    selDoc.disabled = true;
    if (!cid) { selDoc.innerHTML = '<option value="">— Choisissez d\'abord un client —</option>'; return; }

    fetch('documents-client.php?client=' + encodeURIComponent(cid), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (liste) {
        if (!liste.length) { selDoc.innerHTML = '<option value="">Aucun document pour ce client</option>'; return; }
        selDoc.innerHTML = '<option value="">— Choisir un document —</option>';
        liste.forEach(function (d) {
          var o = document.createElement('option');
          o.value = d.type + ':' + d.id;
          o.textContent = d.libelle;
          selDoc.appendChild(o);
        });
        selDoc.disabled = false;
      })
      .catch(function () { selDoc.innerHTML = '<option value="">Chargement impossible</option>'; });
  });

  document.getElementById('add-doc').addEventListener('click', function () {
    var o = selDoc.options[selDoc.selectedIndex];
    if (!o || !o.value) return;
    if (pjs.some(function (p) { return p.ref === o.value; })) return;
    pjs.push({ ref: o.value, libelle: o.textContent.trim() });
    dessinerJetons();
  });

  /* Fichiers de l'appareil : on affiche simplement leur nom. */
  document.getElementById('fichiers').addEventListener('change', function () {
    var noms = Array.prototype.map.call(this.files, function (f) { return f.name; });
    var info = document.getElementById('jetons-pj');
    if (noms.length) {
      var s = document.createElement('span');
      s.className = 'mj mj-fichier';
      s.innerHTML = '📎 <b>' + noms.join(', ') + '</b>';
      info.appendChild(s);
    }
  });

  dessinerJetons();

  /* Bascule de l'authentification : l'aperçu montre aussitôt ce que recevra
     le destinataire selon le choix. */
  var bascule = document.getElementById('bascule-auth');
  function majSignature() {
    var actif = bascule.checked;
    document.getElementById('zone-sig').hidden = !actif;
    document.getElementById('zone-simple').hidden = actif;
    bascule.closest('.bascule').classList.toggle('actif', actif);
  }
  bascule.addEventListener('change', majSignature);

  /* Confirmation avant envoi : un message parti ne se rattrape pas. */
  document.getElementById('form-mail').addEventListener('submit', function (e) {
    champ.value = ed.innerHTML;
    if (!dests.length) { alert('Choisissez au moins un destinataire.'); e.preventDefault(); return; }
    var pj = pjs.length + document.getElementById('fichiers').files.length;
    var texte = 'Envoyer ce message à ' + dests.length + ' destinataire(s)'
              + (pj ? ' avec ' + pj + ' pièce(s) jointe(s)' : '') + ' ?';
    if (!confirm(texte)) e.preventDefault();
  });
})();
</script>

<?php admin_footer(); ?>
