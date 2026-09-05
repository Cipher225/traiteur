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
    foreach (preg_split('/[\s,;]+/', (string)($_POST['manuels'] ?? '')) as $adr) {
        $adr = trim($adr);
        if ($adr !== '' && filter_var($adr, FILTER_VALIDATE_EMAIL)) {
            $liste[mb_strtolower($adr)] = ['email' => $adr, 'nom' => '', 'client_id' => null];
        }
    }

    if ($sujet === '')                       $erreurs[] = "L'objet du message est obligatoire.";
    if (trim(strip_tags($corps)) === '')     $erreurs[] = 'Le message est vide.';
    if (!$liste)                             $erreurs[] = 'Aucun destinataire valide sélectionné.';
    if (empty($settings['smtp_hote']))       $erreurs[] = "Aucun serveur d'envoi configuré (Paramètres → Emails).";

    if (!$erreurs) {
        $dossier = __DIR__ . '/../uploads/signatures';
        if (!is_dir($dossier)) @mkdir($dossier, 0775, true);

        foreach ($liste as $d) {
            $date      = date('Y-m-d H:i:s');
            $reference = signature_reference($pdo);
            $empreinte = signature_empreinte($d['email'], $sujet, $corps, $date);

            /* L'image de signature est propre à CE message : elle porte sa
               référence et son empreinte. */
            $fichier = $dossier . '/' . $reference . '.png';
            signature_image($settings, $reference, $empreinte, $fichier);

            $site = rtrim((string)($settings['site_url'] ?? ''), '/');
            $urlSig = $site . '/signature-mail.php?c=' . urlencode($reference);
            $urlVerif = $site . '/verifier-email.php?c=' . urlencode($reference);

            $message = $corps
                . '<div style="margin-top:26px;border-top:1px solid #e8ecf2;padding-top:16px">'
                . '<img src="' . e($urlSig) . '" alt="' . e($settings['nom_entreprise'] ?? '') . '" style="max-width:100%;height:auto;display:block">'
                . '<p style="margin:10px 0 0;font-size:11px;color:#8a9ab5;line-height:1.6">'
                . 'Référence de ce message : <strong style="color:#0a1f44">' . e($reference) . '</strong> — '
                . '<a href="' . e($urlVerif) . '" style="color:#b8870f">vérifier son authenticité</a>'
                . '</p></div>';

            $ok = envoyer_email($pdo, $d['email'], $sujet, $message, (string)($settings['email'] ?? ''));

            $pdo->prepare('INSERT INTO emails_envoyes (reference, empreinte, destinataire, destinataire_nom,
                           client_id, sujet, corps, envoye_par, envoye_par_nom, statut, envoye_le)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$reference, $empreinte, $d['email'], $d['nom'], $d['client_id'], $sujet, $corps,
                           (int)($_SESSION['admin_id'] ?? 0) ?: null, (string)($_SESSION['admin_nom'] ?? ''),
                           $ok ? 'envoye' : 'echoue', $date]);

            if ($ok) $envoyes++; else $erreurs[] = "L'envoi à " . e($d['email']) . " a échoué.";
        }

        journaliser($pdo, 'email', 'message', null, $envoyes . ' message(s) — ' . mb_substr($sujet, 0, 80));
        if ($envoyes) flash($envoyes . ' message' . ($envoyes > 1 ? 's envoyés' : ' envoyé') . '. ✉️');
        if (!$erreurs) { header('Location: messages.php'); exit; }
    }
}

$clients = $pdo->query("SELECT id, nom, entreprise, email, type_client FROM clients
                        WHERE email <> '' ORDER BY COALESCE(NULLIF(entreprise,''), nom)")->fetchAll();
$historique = $pdo->query("SELECT * FROM emails_envoyes ORDER BY envoye_le DESC LIMIT 30")->fetchAll();

admin_header('Messagerie', 'messages', $pdo, $settings);
?>

<?php if ($erreurs): ?>
<div class="panel glass" style="margin-bottom:14px;border-left:4px solid #f87171">
  <?php foreach ($erreurs as $er): ?><div style="font-size:13px;color:#f87171">⚠️ <?= $er ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post" id="form-mail">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">

<div class="mail-duo">
  <!-- ============ Destinataires ============ -->
  <div class="panel glass">
    <h2>👥 Destinataires</h2>
    <p class="mail-aide">Cochez vos clients, ou saisissez des adresses libres. Rien ne part sans votre validation.</p>

    <input class="input" id="filtre-clients" placeholder="Filtrer la liste…" style="margin-bottom:9px">

    <div class="mail-clients" id="liste-clients">
      <?php foreach ($clients as $c):
        $nom = trim((string)$c['entreprise']) !== '' ? $c['entreprise'] : $c['nom']; ?>
      <label class="mc-l" data-cle="<?= e(mb_strtolower($nom . ' ' . $c['email'])) ?>">
        <input type="checkbox" name="clients[]" value="<?= (int)$c['id'] ?>">
        <span class="mc-ic"><?= ($c['type_client'] ?? '') === 'entreprise' ? '🏢' : '👤' ?></span>
        <span class="mc-t"><span class="mc-n"><?= e($nom) ?></span><span class="mc-e"><?= e($c['email']) ?></span></span>
      </label>
      <?php endforeach; ?>
      <?php if (!$clients): ?><div class="mail-vide">Aucun client n'a d'adresse email enregistrée.</div><?php endif; ?>
    </div>

    <div style="display:flex;gap:8px;margin:9px 0">
      <button type="button" class="btn btn-glass btn-sm" id="tout-cocher">Tout cocher</button>
      <button type="button" class="btn btn-glass btn-sm" id="tout-decocher">Tout décocher</button>
      <span class="mail-compte" id="compte-sel">0 sélectionné</span>
    </div>

    <div class="field">
      <label>Adresses libres</label>
      <textarea class="input" name="manuels" rows="2" placeholder="adresse@exemple.ci, autre@exemple.ci"><?= e($_POST['manuels'] ?? '') ?></textarea>
      <span class="mail-note">Séparez par une virgule, un point-virgule ou un retour à la ligne.</span>
    </div>
  </div>

  <!-- ============ Signature ============ -->
  <div class="panel glass">
    <h2>🔏 Signature de l'entreprise</h2>
    <p class="mail-aide">Ajoutée automatiquement au bas de chaque message.</p>
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
</div>

<!-- ============ Rédaction ============ -->
<div class="panel glass" style="margin-top:14px">
  <h2>✍️ Message</h2>

  <div class="field">
    <label>Objet *</label>
    <input class="input" name="sujet" required maxlength="255" value="<?= e($_POST['sujet'] ?? '') ?>" placeholder="ex : Votre devis pour le séminaire du 12 septembre">
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
    <button class="btn btn-gold" name="envoyer" value="1" id="btn-envoi">✉️ Envoyer le message</button>
    <span class="mail-note" id="resume-envoi"></span>
  </div>
</div>
</form>

<?php if ($historique): ?>
<div class="panel glass" style="margin-top:14px">
  <h2>📜 Messages envoyés</h2>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Date</th><th>Destinataire</th><th>Objet</th><th>Référence</th><th>Par</th><th>État</th></tr></thead>
      <tbody>
      <?php foreach ($historique as $h): ?>
        <tr>
          <td style="white-space:nowrap"><?= date('d/m/Y H:i', strtotime($h['envoye_le'])) ?></td>
          <td><?= e($h['destinataire_nom'] ?: $h['destinataire']) ?>
            <div style="font-size:11px;color:var(--ink-faint)"><?= e($h['destinataire']) ?></div></td>
          <td style="font-size:12.5px"><?= e(mb_substr($h['sujet'], 0, 60)) ?></td>
          <td style="font-family:monospace;font-size:11.5px"><?= e($h['reference']) ?></td>
          <td style="font-size:12px"><?= e($h['envoye_par_nom']) ?></td>
          <td><span class="etat-pay <?= $h['statut'] === 'envoye' ? 'ep-paye' : 'ep-echoue' ?>">
            <?= $h['statut'] === 'envoye' ? 'Envoyé' : 'Échec' ?></span></td>
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
    'Devis à valider': '<p>Bonjour,</p><p>Veuillez trouver ci-joint votre devis. Il reste valable 15 jours.</p><p>Je reste à votre disposition pour tout ajustement.</p><p>Cordialement,</p>',
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

  /* Filtre de la liste des clients */
  var filtre = document.getElementById('filtre-clients');
  filtre.addEventListener('input', function () {
    var v = this.value.toLowerCase();
    document.querySelectorAll('.mc-l').forEach(function (l) {
      l.style.display = l.dataset.cle.indexOf(v) >= 0 ? '' : 'none';
    });
  });

  function cases() { return Array.prototype.slice.call(document.querySelectorAll('input[name="clients[]"]')); }
  function majCompte() {
    var n = cases().filter(function (c) { return c.checked; }).length;
    document.getElementById('compte-sel').textContent = n + ' sélectionné' + (n > 1 ? 's' : '');
    var m = document.querySelector('textarea[name="manuels"]').value.trim();
    var libres = m ? m.split(/[\s,;]+/).filter(Boolean).length : 0;
    document.getElementById('resume-envoi').textContent =
      (n + libres) > 0 ? 'Le message partira à ' + (n + libres) + ' destinataire(s).' : 'Aucun destinataire choisi.';
  }
  document.addEventListener('change', function (e) {
    if (e.target.name === 'clients[]') majCompte();
  });
  document.querySelector('textarea[name="manuels"]').addEventListener('input', majCompte);
  document.getElementById('tout-cocher').addEventListener('click', function () {
    cases().forEach(function (c) { if (c.closest('.mc-l').style.display !== 'none') c.checked = true; }); majCompte();
  });
  document.getElementById('tout-decocher').addEventListener('click', function () {
    cases().forEach(function (c) { c.checked = false; }); majCompte();
  });
  majCompte();

  /* Confirmation avant envoi : un message parti ne se rattrape pas. */
  document.getElementById('form-mail').addEventListener('submit', function (e) {
    champ.value = ed.innerHTML;
    var n = cases().filter(function (c) { return c.checked; }).length;
    var m = document.querySelector('textarea[name="manuels"]').value.trim();
    var libres = m ? m.split(/[\s,;]+/).filter(Boolean).length : 0;
    var total = n + libres;
    if (total === 0) { alert('Choisissez au moins un destinataire.'); e.preventDefault(); return; }
    if (!confirm('Envoyer ce message à ' + total + ' destinataire(s) ?')) e.preventDefault();
  });
})();
</script>

<?php admin_footer(); ?>
