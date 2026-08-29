<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';

// Contenu du site regroupé par thème (tout est éditable ici)
$groupes = [
    'Identité & marque' => ['🏷️', [
        'nom_entreprise'    => "Nom de l'entreprise",
        'slogan'            => 'Slogan',
        'footer_description'=> 'Description (bas de page)',
    ]],
    "Page d'accueil" => ['🏠', [
        'hero_eyebrow'   => 'Petit texte au-dessus du titre',
        'hero_titre'     => 'Titre principal (le dernier mot est mis en couleur)',
        'hero_texte'     => "Texte d'accroche",
        'cta_devis'      => 'Bouton principal',
        'cta_menu'       => 'Bouton secondaire',
        'hero_chip1'     => 'Bulle sous le visuel (⭐)',
    ]],
    'Titres des sections' => ['📑', [
        'sec_services_eyebrow'=> 'Services — sur-titre',
        'sec_services_titre'  => 'Services — titre',
        'apropos'             => 'Services — texte de présentation',
        'sec_menu_eyebrow'    => 'Menu — sur-titre',
        'sec_menu_titre'      => 'Menu — titre',
        'sec_menu_texte'      => 'Menu — texte',
        'sec_galerie_eyebrow' => 'Galerie — sur-titre',
        'sec_galerie_titre'   => 'Galerie — titre',
        'sec_videos_eyebrow'  => 'Vidéos — sur-titre',
        'sec_videos_titre'    => 'Vidéos — titre',
        'sec_videos_texte'    => 'Vidéos — texte',
        'sec_avis_eyebrow'    => 'Avis — sur-titre',
        'sec_avis_titre'      => 'Avis — titre',
        'sec_devis_eyebrow'   => 'Devis — sur-titre',
        'sec_devis_titre'     => 'Devis — titre',
        'sec_devis_texte'     => 'Devis — texte',
    ]],
    'Contact & réseaux' => ['📞', [
        'telephone' => 'Téléphone',
        'email'     => 'E-mail',
        'adresse'   => 'Adresse',
        'horaires'  => 'Horaires',
        'whatsapp'  => 'Numéro WhatsApp',
        'facebook'  => 'Lien Facebook',
        'instagram' => 'Lien Instagram',
    ]],
    'Informations légales' => ['⚖️', [
        'forme_juridique' => 'Forme juridique (SARL, SA…)',
        'capital'         => 'Capital social',
        'rccm'            => 'N° RCCM',
        'ncc'             => 'N° Compte Contribuable (NCC)',
        'siege_social'    => 'Siège social',
        'compte_bancaire' => 'Compte bancaire',
        'mentions_legales'=> 'Mentions légales (bas de page)',
    ]],
    'Paie & social' => ['📄', [
        'cnps_employeur'        => 'N° CNPS employeur',
        'convention_collective' => 'Convention collective applicable',
        'banque_entreprise'     => 'Banque de l\'entreprise',
    ]],
    'Facturation' => ['🧾', [
        'devise'          => 'Devise (ex : FCFA)',
        'tva_taux'        => 'Taux de TVA par défaut (%)',
        'prefixe_facture' => 'Préfixe des factures (ex : FAC)',
        'prefixe_fiche'   => 'Préfixe des fiches de paie (ex : PAIE)',
        'mentions_facture'=> 'Mentions par défaut sur les factures',
        'mention_livraison'=> 'Mention sur le bon de livraison',
    ]],

];
// Liste plate de toutes les clés
$champs = [];
foreach ($groupes as [$ico, $items]) foreach ($items as $cle => $label) $champs[$cle] = $label;
$longs = ['hero_texte','apropos','footer_description','sec_videos_texte','sec_devis_texte','sec_menu_texte','mentions_legales','mentions_facture', 'mention_livraison'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['maj_settings'])) {
        // insère la clé si absente, sinon met à jour
        $up = $pdo->prepare('INSERT INTO settings (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)');
        foreach ($champs as $cle => $label) {
            if (isset($_POST[$cle])) $up->execute([$cle, mb_substr(trim($_POST[$cle]), 0, 2000)]);
        }
        flash('Paramètres enregistrés. Le site est à jour ✨');
    } elseif (isset($_POST['maj_signature'])) {
        $up = $pdo->prepare('INSERT INTO settings (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)');
        // Le signataire tient maintenant dans un seul champ. Si un ancien "nom du signataire"
        // existe encore, on le fusionne une fois pour toutes puis on le vide.
        if (isset($_POST['signataire_fonction'])) {
            $sigTxt = mb_substr(trim($_POST['signataire_fonction']), 0, 300);
            $ancien = trim((string)(get_settings($pdo)['signataire_nom'] ?? ''));
            if ($ancien !== '' && mb_stripos($sigTxt, $ancien) === false) {
                $sigTxt = $sigTxt !== '' ? $ancien . ', ' . $sigTxt : $ancien;
            }
            $up->execute(['signataire_fonction', mb_substr($sigTxt, 0, 300)]);
            $up->execute(['signataire_nom', '']);
        }
        if (isset($_POST['site_url'])) $up->execute(['site_url', mb_substr(trim($_POST['site_url']), 0, 300)]);
        // Uploads image (logo / signature / tampon) — redimensionnés en PNG, sans être coupés
        $taillesImg = ['logo' => [512, 512], 'signature_img' => [400, 200], 'tampon_img' => [300, 300]];
        foreach (['logo','signature_img','tampon_img'] as $champ) {
            if (!empty($_FILES[$champ]['name'])) {
                [$lw, $lh] = $taillesImg[$champ];
                $img = upload_image_redim($_FILES[$champ], UPLOAD_DIR, $lw, $lh, 'contain');
                if ($img) $up->execute([$champ, $img]);
                else flash('Image refusée pour ' . $champ . ' (formats : jpg, png, webp).', 'error');
            }
            if (isset($_POST['supprimer_'.$champ])) $up->execute([$champ, '']);
        }
        flash('Signature et authentification mises à jour ✍️');
    } elseif (isset($_POST['maj_wave'])) {
        $up = $pdo->prepare('INSERT INTO settings (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)');
        foreach (['wave_actif','wave_mode','wave_api_key','wave_webhook_secret'] as $k) {
            if (isset($_POST[$k])) $up->execute([$k, mb_substr(trim($_POST[$k]), 0, 300)]);
        }
        flash('Réglages du paiement en ligne enregistrés.');
        header('Location: parametres.php?section=wave'); exit;
    } elseif (isset($_POST['maj_email'])) {
        $up = $pdo->prepare('INSERT INTO settings (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)');
        $champs = ['smtp_hote','smtp_port','smtp_user','smtp_secure','email'];
        foreach ($champs as $k) { if (isset($_POST[$k])) $up->execute([$k, mb_substr(trim($_POST[$k]), 0, 200)]); }
        // Mot de passe SMTP : ne l'écraser que si un nouveau est saisi
        if (!empty($_POST['smtp_pass'])) $up->execute(['smtp_pass', trim($_POST['smtp_pass'])]);
        $up->execute(['emails_actifs', isset($_POST['emails_actifs']) ? '1' : '0']);
        flash('Réglages email enregistrés.');
    } elseif (isset($_POST['maj_google'])) {
        $up = $pdo->prepare('INSERT INTO settings (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)');
        $up->execute(['google_client_id', mb_substr(trim($_POST['google_client_id'] ?? ''), 0, 255)]);
        // Le secret : ne l'écraser que si un nouveau est saisi
        if (!empty($_POST['google_client_secret'])) {
            $up->execute(['google_client_secret', trim($_POST['google_client_secret'])]);
        }
        if (isset($_POST['effacer_google_secret'])) $up->execute(['google_client_secret', '']);
        flash('Identifiants Google enregistrés.');
    } elseif (isset($_POST['maj_mdp'])) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
        $stmt->execute([$_SESSION['admin_id']]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($_POST['ancien'] ?? '', $user['password'])) {
            flash('Ancien mot de passe incorrect.', 'error');
        } elseif (strlen($_POST['nouveau'] ?? '') < 8) {
            flash('Le nouveau mot de passe doit contenir au moins 8 caractères.', 'error');
        } elseif (($_POST['nouveau'] ?? '') !== ($_POST['confirme'] ?? '')) {
            flash('La confirmation ne correspond pas.', 'error');
        } else {
            $pdo->prepare('UPDATE users SET password=? WHERE id=?')
                ->execute([password_hash($_POST['nouveau'], PASSWORD_DEFAULT), $user['id']]);
            flash('Mot de passe modifié avec succès 🔒');
        }
    }
    header('Location: parametres.php'); exit;
}

$s = get_settings($pdo);
admin_header('Paramètres', 'parametres', $pdo, $settings);

// Descriptifs courts de chaque rubrique de contenu
$descriptifs = [
    'Identité & marque'    => 'Nom, slogan, description',
    "Page d'accueil"       => 'Bannière, accroches, boutons',
    'Titres des sections'  => 'Intitulés affichés sur le site',
    'Contact & réseaux'    => 'Téléphone, email, réseaux sociaux',
    'Informations légales' => 'RCCM, contribuable, siège…',
    'Paie & social'        => 'CNPS, convention, banque',
    'Badges & matricules'  => 'Préfixe et format des matricules',
    'Facturation'          => 'Devise, TVA, préfixes, mentions',
];
$avancees = [
    'signature' => ['✍️', 'Signature & documents', 'Logo, signature, tampon, QR'],
    'email'     => ['✉️', 'Emails automatiques', 'Configuration SMTP'],
    'google'    => ['🔗', 'Connexion Google', 'Identifiants OAuth'],
    'wave'      => ['💳', 'Paiement en ligne', 'Clé Wave, mode et sécurité'],
    'motdepasse'=> ['🔒', 'Mot de passe', 'Changer votre mot de passe'],
];
function param_slug($titre){ return substr(md5($titre), 0, 8); }
$sectionOuverte = $_GET['section'] ?? '';
$titreOuvert = null;
foreach ($groupes as $t => $g) { if (param_slug($t) === $sectionOuverte) { $titreOuvert = $t; break; } }
$avanceeOuverte = array_key_exists($sectionOuverte, $avancees) ? $sectionOuverte : null;
?>

<?php if ($titreOuvert !== null): [$ico, $items] = $groupes[$titreOuvert]; ?>
<a href="parametres.php" class="btn btn-glass btn-sm" style="margin-bottom:14px">‹ Toutes les rubriques</a>
<div class="panel glass">
  <h2><?= $ico ?> <?= e($titreOuvert) ?></h2>
  <p style="color:var(--ink-faint);font-size:13.5px;margin:-8px 0 16px"><?= e($descriptifs[$titreOuvert] ?? '') ?></p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div class="form-grid">
      <?php foreach ($items as $cle => $label): ?>
      <div class="field <?= in_array($cle, $longs) ? 'full' : '' ?>">
        <label><?= e($label) ?></label>
        <?php if (in_array($cle, $longs)): ?>
        <textarea class="input" name="<?= $cle ?>" style="min-height:70px"><?= e($s[$cle] ?? '') ?></textarea>
        <?php else: ?>
        <input class="input" name="<?= $cle ?>" value="<?= e($s[$cle] ?? '') ?>"<?= $cle==='badge_prefixe' ? ' id="champ-prefixe" oninput="majApercuMatricule()"' : '' ?>>
        <?php if ($cle === 'badge_prefixe'): ?>
        <div style="margin-top:10px;font-size:13px;color:var(--ink-dim);line-height:1.9">
          Format automatique (non modifiable) :<br>
          • Employé : <strong id="apercu-emp" style="font-family:monospace;color:var(--gold);letter-spacing:.06em"></strong> <span style="font-size:11px;color:var(--ink-faint)">(n° employé · année+mois · lettres)</span><br>
          • Externe : <strong id="apercu-ext" style="font-family:monospace;color:var(--gold);letter-spacing:.06em"></strong> <span style="font-size:11px;color:var(--ink-faint)">(n° externe · année+mois)</span>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:18px"><button class="btn btn-gold" name="maj_settings" value="1">💾 Enregistrer</button></div>
  </form>
</div>

<?php elseif ($avanceeOuverte === 'signature'): ?>
<a href="parametres.php" class="btn btn-glass btn-sm" style="margin-bottom:14px">‹ Toutes les rubriques</a>
<div class="panel glass">
  <h2>✍️ Signature, tampon & authentification des documents</h2>
  <p style="color:var(--ink-faint);font-size:13.5px;margin:-6px 0 16px">Téléversez la signature et le tampon de l'entreprise (idéalement en PNG à fond transparent). L'administrateur pourra les ajouter aux documents. Le <strong>QR code d'authentification</strong> renvoie vers votre site pour prouver qu'un document est authentique.</p>
  <form method="post" enctype="multipart/form-data" class="form-grid">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div class="field"><label>Adresse du site (pour le QR de vérification)</label><input class="input" name="site_url" value="<?= e($s['site_url'] ?? '') ?>" placeholder="https://www.mon-site.ci ou http://localhost/traiteur"></div>
    <div class="field full"><label>Signataire (nom et fonction)</label>
      <input class="input" name="signataire_fonction" value="<?= e($s['signataire_fonction'] ?? 'La Direction') ?>" placeholder="ex : Urbaine KOUAKOUSSUI, Directrice Générale">
      <div style="margin-top:6px;font-size:12.5px;color:var(--ink-faint)">Ce texte s'affiche sous la signature et le tampon sur tous les documents.</div>
    </div>
    <div class="field">
      <label>Signature (image)</label>
      <div class="field full" style="border-bottom:1px solid var(--glass-border-soft);padding-bottom:16px;margin-bottom:6px">
        <label>Logo de l'entreprise <span style="color:var(--ink-faint);font-weight:400">— apparaît en tête de tous les documents (PNG ou JPG, fond transparent conseillé)</span></label>
        <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-top:8px">
          <div style="background:#fff;border:1px solid var(--glass-border);border-radius:12px;padding:10px;display:inline-block">
            <img src="<?= !empty($s['logo']) ? '../uploads/'.e($s['logo']) : '../assets/img/logo.png' ?>" style="max-height:74px;display:block">
          </div>
          <div style="flex:1;min-width:220px">
            <input class="input" type="file" name="logo" accept="image/*" data-redim="512x512" data-redim-mode="contain">
            <?php if (!empty($s['logo'])): ?>
            <label class="switch" style="font-size:12px;margin-top:8px"><input type="checkbox" name="supprimer_logo"><span></span> Revenir au logo par défaut</label>
            <?php else: ?>
            <p style="font-size:12px;color:var(--ink-faint);margin-top:8px">Logo par défaut utilisé. Téléversez le vôtre pour le remplacer partout.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php if (!empty($s['signature_img'])): ?><div style="margin-bottom:8px;background:#fff;border-radius:10px;padding:8px;display:inline-block"><img src="../uploads/<?= e($s['signature_img']) ?>" style="max-height:60px"></div><label class="switch" style="font-size:12px"><input type="checkbox" name="supprimer_signature_img"> Retirer</label><?php endif; ?>
      <input class="input" type="file" name="signature_img" accept="image/*" data-redim="400x200" data-redim-mode="contain">
    </div>
    <div class="field">
      <label>Tampon / cachet (image)</label>
      <?php if (!empty($s['tampon_img'])): ?><div style="margin-bottom:8px;background:#fff;border-radius:10px;padding:8px;display:inline-block"><img src="../uploads/<?= e($s['tampon_img']) ?>" style="max-height:70px"></div><label class="switch" style="font-size:12px"><input type="checkbox" name="supprimer_tampon_img"> Retirer</label><?php endif; ?>
      <input class="input" type="file" name="tampon_img" accept="image/*" data-redim="300x300" data-redim-mode="contain">
    </div>
    <div class="full"><button class="btn btn-gold" name="maj_signature" value="1">Enregistrer signature & authentification</button></div>
  </form>
</div>

<?php elseif ($avanceeOuverte === 'email'): ?>
<a href="parametres.php" class="btn btn-glass btn-sm" style="margin-bottom:14px">‹ Toutes les rubriques</a>
<div class="panel glass">
  <h2>✉️ Emails automatiques (SMTP)</h2>
  <p style="color:var(--ink-dim);font-size:13.5px;margin-top:-6px">
    Configurez un compte email pour l'envoi automatique (confirmations de devis, factures…).
    Choisissez votre fournisseur ci-dessous pour remplir automatiquement les réglages techniques,
    puis saisissez simplement votre adresse et votre mot de passe.
  </p>

  <!-- Boutons de préréglage : remplissent serveur / port / sécurité automatiquement -->
  <div class="smtp-presets">
    <span class="smtp-presets-lbl">Préréglage rapide :</span>
    <button type="button" class="btn btn-glass btn-sm" onclick="smtpPreset('hostinger')">🏆 Hostinger</button>
    <button type="button" class="btn btn-glass btn-sm" onclick="smtpPreset('gmail')">📧 Gmail</button>
    <button type="button" class="btn btn-glass btn-sm" onclick="smtpPreset('outlook')">📨 Outlook</button>
    <button type="button" class="btn btn-glass btn-sm" onclick="smtpPreset('ovh')">🇫🇷 OVH</button>
  </div>
  <div id="smtp-aide" class="smtp-aide"></div>

  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <label class="chk-line full" style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
      <input type="checkbox" name="emails_actifs" value="1" <?= (($s['emails_actifs'] ?? '1') !== '0') ? 'checked' : '' ?>>
      <span>Activer l'envoi automatique d'emails</span>
    </label>
    <div class="field"><label>Email expéditeur (adresse d'envoi)</label><input class="input" type="email" id="smtp_email" name="email" value="<?= e($s['email'] ?? '') ?>" placeholder="contact@mondomaine.com"></div>
    <div class="field"><label>Serveur SMTP</label><input class="input" id="smtp_hote" name="smtp_hote" value="<?= e($s['smtp_hote'] ?? '') ?>" placeholder="smtp.hostinger.com"></div>
    <div class="field"><label>Port</label><input class="input" id="smtp_port" name="smtp_port" value="<?= e($s['smtp_port'] ?? '587') ?>" placeholder="587"></div>
    <div class="field"><label>Sécurité</label>
      <select class="input" id="smtp_secure" name="smtp_secure">
        <option value="tls" <?= (($s['smtp_secure'] ?? 'tls')==='tls')?'selected':'' ?>>TLS (port 587)</option>
        <option value="ssl" <?= (($s['smtp_secure'] ?? '')==='ssl')?'selected':'' ?>>SSL (port 465)</option>
        <option value="none" <?= (($s['smtp_secure'] ?? '')==='none')?'selected':'' ?>>Aucune</option>
      </select>
    </div>
    <div class="field"><label>Identifiant SMTP (souvent = l'email)</label><input class="input" id="smtp_user" name="smtp_user" value="<?= e($s['smtp_user'] ?? '') ?>" placeholder="contact@mondomaine.com"></div>
    <div class="field"><label>Mot de passe SMTP <span style="color:var(--ink-faint);font-weight:400">(laisser vide pour ne pas changer)</span></label><input class="input" type="password" name="smtp_pass" value="" placeholder="••••••••" autocomplete="new-password"></div>
    <div class="full" style="margin-top:6px">
      <button class="btn btn-gold" name="maj_email" value="1">💾 Enregistrer les réglages email</button>
    </div>
    <p class="full" style="color:var(--ink-faint);font-size:12px;margin-top:4px">
      💡 Cliquez sur un préréglage ci-dessus (Hostinger, Gmail…) pour remplir automatiquement le serveur et le port.
      Il ne vous restera qu'à saisir votre adresse et votre mot de passe.
      Si aucun SMTP n'est configuré, l'application tentera l'envoi via la fonction mail() de l'hébergeur.
    </p>
  </form>
</div>

<script>
/* Préréglages SMTP : remplit serveur / port / sécurité selon le fournisseur */
function smtpPreset(fournisseur) {
  var presets = {
    hostinger: { hote:'smtp.hostinger.com', port:'587', secure:'tls',
      aide:'Sur Hostinger : créez une adresse email (hPanel → Emails), puis utilisez cette adresse comme <strong>identifiant</strong> et son mot de passe. L\'expéditeur et l\'identifiant sont votre adresse complète (ex : contact@votredomaine.com).' },
    gmail: { hote:'smtp.gmail.com', port:'587', secure:'tls',
      aide:'Pour Gmail : activez la <strong>validation en deux étapes</strong> sur votre compte Google, puis créez un <strong>« mot de passe d\'application »</strong> (Compte Google → Sécurité → Mots de passe des applications). Utilisez ce mot de passe de 16 caractères ici, PAS votre mot de passe Gmail habituel.' },
    outlook: { hote:'smtp.office365.com', port:'587', secure:'tls',
      aide:'Pour Outlook / Office 365 : utilisez votre adresse complète comme identifiant et votre mot de passe. Un mot de passe d\'application peut être requis si la double authentification est active.' },
    ovh: { hote:'ssl0.ovh.net', port:'587', secure:'tls',
      aide:'Pour OVH : utilisez l\'adresse email créée dans votre espace client OVH comme identifiant, avec son mot de passe.' }
  };
  var p = presets[fournisseur]; if (!p) return;
  document.getElementById('smtp_hote').value = p.hote;
  document.getElementById('smtp_port').value = p.port;
  document.getElementById('smtp_secure').value = p.secure;
  // Recopier l'email dans l'identifiant s'il est vide
  var email = document.getElementById('smtp_email').value;
  var user = document.getElementById('smtp_user');
  if (email && !user.value) user.value = email;
  document.getElementById('smtp-aide').innerHTML = '💡 ' + p.aide;
  document.getElementById('smtp-aide').style.display = 'block';
}
/* Si on tape l'email, le recopier dans l'identifiant s'il est vide */
document.getElementById('smtp_email').addEventListener('blur', function () {
  var user = document.getElementById('smtp_user');
  if (this.value && !user.value) user.value = this.value;
});
</script>

<?php elseif ($avanceeOuverte === 'google'): ?>
<a href="parametres.php" class="btn btn-glass btn-sm" style="margin-bottom:14px">‹ Toutes les rubriques</a>
<div class="panel glass">
  <h2>🔗 Connexion avec Google</h2>
  <p style="color:var(--ink-dim);font-size:13.5px;margin-top:-6px">
    Permet à vos clients de se connecter en un clic avec leur compte Google.
    Renseignez les identifiants obtenus dans la <a href="https://console.cloud.google.com/apis/credentials" target="_blank" style="color:var(--gold)">console Google Cloud</a>
    (identifiants OAuth 2.0), puis enregistrez.
  </p>
  <?php $googleOn = trim($s['google_client_id'] ?? '') !== ''; ?>
  <div class="google-etat <?= $googleOn ? 'on' : 'off' ?>">
    <?= $googleOn ? '✅ La connexion Google est active.' : 'ℹ️ La connexion Google est inactive (le bouton n\'apparaît pas sur la page de connexion).' ?>
  </div>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div class="field full"><label>Client ID Google</label>
      <input class="input" name="google_client_id" value="<?= e($s['google_client_id'] ?? '') ?>" placeholder="123456789-xxxxxxxx.apps.googleusercontent.com">
    </div>
    <div class="field full"><label>Client Secret Google <span style="color:var(--ink-faint);font-weight:400">(laisser vide pour ne pas changer)</span></label>
      <input class="input" type="password" name="google_client_secret" value="" placeholder="<?= (trim($s['google_client_secret'] ?? '') !== '') ? '•••••••• (déjà enregistré)' : 'GOCSPX-...' ?>" autocomplete="new-password">
    </div>
    <?php if (trim($s['google_client_secret'] ?? '') !== ''): ?>
    <label class="chk-line full" style="display:flex;align-items:center;gap:10px;font-size:13px;color:var(--ink-dim)">
      <input type="checkbox" name="effacer_google_secret" value="1"> Effacer le secret enregistré
    </label>
    <?php endif; ?>
    <div class="full" style="margin-top:6px">
      <button class="btn btn-gold" name="maj_google" value="1">💾 Enregistrer les identifiants Google</button>
    </div>
    <p class="full" style="color:var(--ink-faint);font-size:12px;margin-top:4px;line-height:1.6">
      💡 Dans la console Google, l'<strong>URI de redirection autorisée</strong> doit être exactement :<br>
      <code style="background:rgba(255,255,255,.06);padding:3px 8px;border-radius:6px;color:var(--gold)"><?= e(rtrim($s['site_url'] ?? 'https://votre-domaine.com', '/')) ?>/google-callback.php</code><br>
      (Pensez à renseigner l'adresse du site dans le premier panneau si ce n'est pas déjà fait.)
    </p>
  </form>
</div>

<?php elseif ($avanceeOuverte === 'wave'): ?>
<a href="parametres.php" class="btn btn-glass btn-sm" style="margin-bottom:14px">‹ Toutes les rubriques</a>
<div class="panel glass">
  <h2>💳 Paiement en ligne (Wave)</h2>
  <p style="color:var(--ink-faint);font-size:13.5px;margin:-6px 0 12px">
    Ces informations vous sont fournies par Wave dans votre espace professionnel.</p>
  <?php if (($s['wave_actif'] ?? '0') === '1' && trim((string)($s['wave_api_key'] ?? '')) === ''): ?>
  <div style="padding:11px 15px;margin-bottom:16px;border-radius:12px;font-size:13px;font-weight:600;
              color:#f0b429;background:rgba(240,180,41,.12);border:1px solid rgba(240,180,41,.35)">
    ⚠️ L'option est activée mais la clé API est vide : le bouton de paiement <strong>n'apparaît pas encore</strong>
    dans l'espace de vos clients. Renseignez la clé ci-dessous pour le rendre visible.
  </div>
  <?php endif; ?>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div class="field">
      <label>Paiement en ligne</label>
      <select class="input" name="wave_actif">
        <option value="0" <?= ($s['wave_actif'] ?? '0') === '0' ? 'selected' : '' ?>>Désactivé</option>
        <option value="1" <?= ($s['wave_actif'] ?? '0') === '1' ? 'selected' : '' ?>>Activé</option>
      </select>
    </div>
    <div class="field">
      <label>Mode</label>
      <select class="input" name="wave_mode">
        <option value="test" <?= ($s['wave_mode'] ?? 'test') === 'test' ? 'selected' : '' ?>>Test (aucun argent réel)</option>
        <option value="live" <?= ($s['wave_mode'] ?? '') === 'live' ? 'selected' : '' ?>>Production (paiements réels)</option>
      </select>
    </div>
    <div class="field full">
      <label>Clé API Wave</label>
      <input class="input" name="wave_api_key" value="<?= e($s['wave_api_key'] ?? '') ?>" placeholder="wave_sn_prod_...">
    </div>
    <div class="field full">
      <label>Clé de signature des notifications (webhook secret)</label>
      <input class="input" name="wave_webhook_secret" value="<?= e($s['wave_webhook_secret'] ?? '') ?>" placeholder="whsec_...">
      <div style="margin-top:6px;font-size:12.5px;color:var(--ink-faint)">
        Adresse à déclarer chez Wave :
        <code style="background:rgba(255,255,255,.06);padding:3px 8px;border-radius:6px;color:var(--gold)"><?= e(rtrim($s['site_url'] ?? 'https://votre-domaine', '/')) ?>/wave-webhook.php</code><br>
        Sans cette clé, les confirmations envoyées par Wave sont refusées par sécurité.
      </div>
    </div>
    <div class="full"><button class="btn btn-gold" name="maj_wave" value="1">💾 Enregistrer</button></div>
  </form>
</div>

<?php elseif ($avanceeOuverte === 'motdepasse'): ?>
<a href="parametres.php" class="btn btn-glass btn-sm" style="margin-bottom:14px">‹ Toutes les rubriques</a>
<div class="panel glass">
  <h2>🔒 Changer mon mot de passe</h2>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div class="field"><label>Ancien mot de passe</label><input class="input" type="password" name="ancien" required></div>
    <div class="field"><label>Nouveau mot de passe (8 caractères min.)</label><input class="input" type="password" name="nouveau" required minlength="8"></div>
    <div class="field"><label>Confirmer le nouveau</label><input class="input" type="password" name="confirme" required minlength="8"></div>
    <div class="full"><button class="btn btn-gold" name="maj_mdp" value="1">Mettre à jour le mot de passe</button></div>
  </form>
</div>

<?php else: ?>
<div class="panel glass" style="margin-bottom:14px">
  <h2>⚙️ Contenu du site</h2>
  <p style="color:var(--ink-faint);font-size:13.5px;margin:-8px 0 2px">Choisissez une rubrique. Chaque rubrique a son propre bouton d'enregistrement.</p>
</div>
<div class="ios-list">
  <?php foreach ($groupes as $titre => [$ico, $items]): ?>
  <a class="ios-row" href="parametres.php?section=<?= param_slug($titre) ?>">
    <span class="ios-ic"><?= $ico ?></span>
    <span class="ios-tx"><span class="ios-t"><?= e($titre) ?></span><span class="ios-s"><?= e($descriptifs[$titre] ?? '') ?></span></span>
    <span class="ios-cnt"><?= count($items) ?></span>
    <span class="ios-go">›</span>
  </a>
  <?php endforeach; ?>
</div>
<div class="panel glass" style="margin:18px 0 14px">
  <h2>🔧 Configuration avancée</h2>
  <p style="color:var(--ink-faint);font-size:13.5px;margin:-8px 0 2px">Réglages techniques et sécurité.</p>
</div>
<div class="ios-list">
  <?php foreach ($avancees as $slug => [$ico, $nom, $desc]): ?>
  <a class="ios-row" href="parametres.php?section=<?= $slug ?>">
    <span class="ios-ic"><?= $ico ?></span>
    <span class="ios-tx"><span class="ios-t"><?= e($nom) ?></span><span class="ios-s"><?= e($desc) ?></span></span>
    <span class="ios-go">›</span>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
function majApercuMatricule(){
  var pfx = ((document.getElementById('champ-prefixe')||{}).value || 'GH').trim() || 'GH';
  var now = new Date();
  var aa = String(now.getFullYear()).slice(-2);
  var mm = String(now.getMonth()+1).padStart(2,'0');
  var ym = aa + mm;
  var emp = document.getElementById('apercu-emp');
  var ext = document.getElementById('apercu-ext');
  if(emp) emp.textContent = pfx + '01-' + ym + '-AA';
  if(ext) ext.textContent = pfx + '-EX01-' + ym;
}
majApercuMatricule();
</script>

<?php admin_footer(); ?>
