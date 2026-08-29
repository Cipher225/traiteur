<?php
require __DIR__ . '/../config/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../index.php'); exit; }
csrf_check();

$nom = trim($_POST['nom'] ?? '');
$tel = trim($_POST['telephone'] ?? '');
$email = trim($_POST['email'] ?? '');
$type = trim($_POST['type_evenement'] ?? '');

if ($nom === '' || $tel === '' || $type === '') {
    flash('Merci de remplir les champs obligatoires (nom, téléphone, type d\'événement).', 'error');
    header('Location: ../index.php#devis'); exit;
}

// 1) Enregistrer la demande de devis
$pdo->prepare('INSERT INTO commandes (nom, telephone, email, type_evenement, date_evenement, nb_invites, message) VALUES (?,?,?,?,?,?,?)')
    ->execute([
        mb_substr($nom,0,100), mb_substr($tel,0,30), mb_substr($email,0,120), mb_substr($type,0,80),
        ($_POST['date_evenement'] ?? '') ?: null, max(0,(int)($_POST['nb_invites'] ?? 0)), mb_substr(trim($_POST['message'] ?? ''),0,2000),
    ]);

// 2) Créer (ou retrouver) la fiche client -> apparait dans l'admin
$telDigits = preg_replace('/\D/', '', $tel);
$client = null;
if ($telDigits !== '') {
    $st = $pdo->prepare("SELECT * FROM clients WHERE REPLACE(REPLACE(telephone,' ',''),'-','') LIKE ? LIMIT 1");
    $st->execute(['%'.$telDigits.'%']); $client = $st->fetch();
}
if (!$client && $email !== '') {
    $st = $pdo->prepare("SELECT * FROM clients WHERE email=? LIMIT 1"); $st->execute([$email]); $client = $st->fetch();
}
if ($client) {
    $cid = (int)$client['id'];
} else {
    $pdo->prepare('INSERT INTO clients (nom, telephone, email, notes) VALUES (?,?,?,?)')
        ->execute([mb_substr($nom,0,120), mb_substr($tel,0,30), mb_substr($email,0,120), 'Créé automatiquement depuis une demande de devis.']);
    $cid = (int)$pdo->lastInsertId();
}

// Le client a-t-il déjà un compte ?
$hasAccount = false;
$acc = $pdo->prepare("SELECT id FROM users WHERE client_id=? AND role='client'"); $acc->execute([$cid]);
$hasAccount = (bool)$acc->fetchColumn();

// 3) Emails automatiques (confirmation au client + alerte à l'administrateur)
require_once __DIR__ . '/../config/mail.php';
$s = get_settings($pdo);
$dateEv = ($_POST['date_evenement'] ?? '') ?: 'à préciser';
$nbInv  = max(0,(int)($_POST['nb_invites'] ?? 0));

// → Email de confirmation au client (s'il a laissé un email)
if ($email !== '') {
    $corpsClient = '<p>Bonjour <strong>' . htmlspecialchars($nom) . '</strong>,</p>
        <p>Nous avons bien reçu votre demande de devis. Merci de votre confiance !</p>
        <p style="background:#f4f6fb;border-left:3px solid #d4a526;padding:12px 16px;border-radius:6px">
          <strong>Récapitulatif de votre demande :</strong><br>
          Type d\'événement : ' . htmlspecialchars($type) . '<br>
          Date : ' . htmlspecialchars($dateEv) . '<br>
          Nombre de participants : ' . ($nbInv ?: 'à préciser') . '
        </p>
        <p>Notre équipe étudie votre demande et vous contactera très rapidement pour vous proposer une offre adaptée.</p>
        <p>À très bientôt,<br><strong>L\'équipe ' . htmlspecialchars($s['nom_entreprise'] ?? 'Groupe Helisce') . '</strong></p>';
    @envoyer_email($pdo, $email, 'Votre demande de devis a bien été reçue', $corpsClient);
}

// → Alerte à l'administrateur (email de l'entreprise)
$mailAdmin = $s['email'] ?? '';
if ($mailAdmin !== '') {
    $corpsAdmin = '<p><strong>Nouvelle demande de devis reçue via le site.</strong></p>
        <p style="background:#f4f6fb;border-left:3px solid #d4a526;padding:12px 16px;border-radius:6px">
          Client : <strong>' . htmlspecialchars($nom) . '</strong><br>
          Téléphone : ' . htmlspecialchars($tel) . '<br>
          Email : ' . htmlspecialchars($email ?: '—') . '<br>
          Type : ' . htmlspecialchars($type) . '<br>
          Date souhaitée : ' . htmlspecialchars($dateEv) . '<br>
          Participants : ' . ($nbInv ?: '—') . '
        </p>
        ' . (trim($_POST['message'] ?? '') !== '' ? '<p><em>Message :</em><br>' . nl2br(htmlspecialchars(mb_substr($_POST['message'],0,2000))) . '</p>' : '') . '
        <p>Connectez-vous à votre espace d\'administration pour traiter cette demande.</p>';
    @envoyer_email($pdo, $mailAdmin, 'Nouvelle demande de devis — ' . $nom, $corpsAdmin, $email);
}

// 4) Préparer l'invitation à créer un compte
$_SESSION['devis_ok'] = [
    'client_id' => $cid,
    'nom' => $nom,
    'email' => $email,
    'tel' => $tel,
    'has_account' => $hasAccount,
];
header('Location: ../merci-devis.php'); exit;
