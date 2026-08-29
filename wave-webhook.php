<?php
/* ============================================================================
   WEBHOOK WAVE
   Wave appelle cette adresse pour confirmer un paiement, même si le client a
   fermé son navigateur. C'est la garantie qu'aucun règlement ne soit perdu.
   L'adresse à déclarer chez Wave est :  https://votre-domaine/wave-webhook.php
   ============================================================================ */

require __DIR__ . '/config/db.php';
require __DIR__ . '/config/mail.php';
require __DIR__ . '/config/wave.php';
require __DIR__ . '/admin/includes/documents.php';

header('Content-Type: application/json');

$corps  = file_get_contents('php://input') ?: '';
$entete = $_SERVER['HTTP_WAVE_SIGNATURE'] ?? ($_SERVER['HTTP_X_WAVE_SIGNATURE'] ?? '');
$cfg    = wave_config($pdo);

// Journal minimal, utile en cas de litige
$journal = function (string $texte) {
    $f = __DIR__ . '/uploads/wave-webhook.log';
    @file_put_contents($f, date('Y-m-d H:i:s') . ' | ' . $texte . "\n", FILE_APPEND);
};

if (!$cfg['actif']) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'Paiement en ligne désactivé']);
    exit;
}

// La signature est obligatoire : sans elle, n'importe qui pourrait déclarer un paiement
if (!wave_signature_valide($corps, $entete, $cfg['secret'])) {
    $journal('SIGNATURE REFUSEE');
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Signature invalide']);
    exit;
}

$data = json_decode($corps, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Contenu illisible']);
    exit;
}

$type   = $data['type'] ?? '';
$objet  = $data['data'] ?? [];
$refCli = $objet['client_reference'] ?? '';
$session= $objet['id'] ?? '';

// On retrouve le paiement par notre référence, sinon par l'identifiant de session
$p = null;
if ($refCli !== '') {
    $st = $pdo->prepare('SELECT * FROM paiements WHERE reference=?');
    $st->execute([$refCli]); $p = $st->fetch();
}
if (!$p && $session !== '') {
    $st = $pdo->prepare('SELECT * FROM paiements WHERE session_id=?');
    $st->execute([$session]); $p = $st->fetch();
}
if (!$p) {
    $journal('PAIEMENT INCONNU ref=' . $refCli . ' session=' . $session);
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Paiement inconnu']);
    exit;
}

$settings = get_settings($pdo);

if (in_array($type, ['checkout.session.completed', 'checkout.session.payment_succeeded'], true)) {
    // On revérifie malgré tout auprès de Wave : ceinture et bretelles
    $verif = wave_verifier($pdo, $p);
    if ($verif['ok'] && empty($verif['paye'])) {
        $journal('NON CONFIRME PAR L API ref=' . $p['reference']);
        echo json_encode(['ok' => true, 'message' => 'Ignoré : non confirmé']);
        exit;
    }
    $res = paiement_finaliser($pdo, $p['reference'], $settings, [
        'transaction_id' => $objet['transaction_id'] ?? ($objet['id'] ?? ''),
        'source' => 'webhook',
    ]);
    $journal(($res['ok'] ? 'OK' : 'ECHEC') . ' ref=' . $p['reference'] . (isset($res['deja']) ? ' (déjà traité)' : ''));
    echo json_encode(['ok' => (bool)$res['ok']]);
    exit;
}

if ($type === 'checkout.session.payment_failed') {
    $pdo->prepare("UPDATE paiements SET statut='echoue', detail=? WHERE id=? AND statut='en_attente'")
        ->execute(['Échec signalé par Wave', (int)$p['id']]);
    $journal('ECHEC ref=' . $p['reference']);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => true, 'message' => 'Événement ignoré']);
