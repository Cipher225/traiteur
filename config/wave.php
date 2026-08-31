<?php
/* ============================================================================
   PAIEMENT EN LIGNE — WAVE
   Toute la logique de paiement est regroupée ici : création de la session de
   paiement, vérification auprès de Wave, et finalisation (reçu, comptabilité,
   emails). Aucun montant n'est jamais lu depuis le navigateur : il est toujours
   recalculé à partir de la facture, côté serveur.
   ============================================================================ */

require_once __DIR__ . '/docauth.php';   // empreintes et jetons d'authentification

const WAVE_API = 'https://api.wave.com/v1/checkout/sessions';

/* ---------- Réglages ---------- */
function wave_config(PDO $pdo): array {
    $s = get_settings($pdo);
    return [
        'actif'   => ($s['wave_actif'] ?? '0') === '1',
        'cle'     => trim((string)($s['wave_api_key'] ?? '')),
        'mode'    => ($s['wave_mode'] ?? 'test'),
        'secret'  => trim((string)($s['wave_webhook_secret'] ?? '')),
        'devise'  => 'XOF',
        'site'    => rtrim((string)($s['site_url'] ?? ''), '/'),
    ];
}

/* Le paiement n'est proposé que si l'option est active ET la clé renseignée. */
function wave_disponible(PDO $pdo): bool {
    $c = wave_config($pdo);
    return $c['actif'] && $c['cle'] !== '';
}

/* ---------- Appel HTTP vers Wave ---------- */
function wave_appel(string $url, string $cle, string $methode = 'GET', ?array $donnees = null): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'erreur' => "L'extension cURL de PHP est absente sur le serveur."];
    }
    $ch = curl_init($url);
    $entetes = ['Authorization: Bearer ' . $cle, 'Content-Type: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => $entetes,
        CURLOPT_CUSTOMREQUEST  => $methode,
    ]);
    if ($donnees !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($donnees));
    $reponse = curl_exec($ch);
    $code    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err     = curl_error($ch);
    curl_close($ch);

    if ($reponse === false) return ['ok' => false, 'erreur' => 'Connexion à Wave impossible : ' . $err];
    $json = json_decode((string)$reponse, true);
    if ($code >= 200 && $code < 300) return ['ok' => true, 'data' => is_array($json) ? $json : []];
    $msg = is_array($json) ? ($json['message'] ?? ($json['error_message'] ?? '')) : '';
    return ['ok' => false, 'erreur' => 'Wave a refusé la demande (code ' . $code . ')' . ($msg ? ' : ' . $msg : '')];
}

/* ---------- Création d'une session de paiement ---------- */
function wave_creer_paiement(PDO $pdo, array $facture, array $client, array $settings): array {
    $cfg = wave_config($pdo);
    if (!$cfg['actif'])      return ['ok' => false, 'erreur' => "Le paiement en ligne est désactivé."];
    if ($cfg['cle'] === '')  return ['ok' => false, 'erreur' => "La clé Wave n'est pas renseignée dans les paramètres."];
    if ($cfg['site'] === '') return ['ok' => false, 'erreur' => "L'adresse du site doit être renseignée dans les paramètres."];

    // Le montant vient TOUJOURS de la facture, jamais du navigateur
    $montant = (int)round((float)$facture['montant_ttc'] - paiements_deja_regles($pdo, (int)$facture['id']));
    if ($montant <= 0) return ['ok' => false, 'erreur' => 'Cette facture est déjà réglée.'];

    $reference = 'PAY-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

    $pdo->prepare('INSERT INTO paiements (reference, facture_id, client_id, montant, devise, statut, ip)
                   VALUES (?,?,?,?,?,?,?)')
        ->execute([$reference, (int)$facture['id'], (int)($client['id'] ?? 0) ?: null, $montant,
                   $cfg['devise'], 'en_attente', mb_substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 64)]);
    $idPaiement = (int)$pdo->lastInsertId();

    $retour = $cfg['site'] . '/espace-client/paiement-retour.php?ref=' . urlencode($reference);
    $res = wave_appel(WAVE_API, $cfg['cle'], 'POST', [
        'amount'              => (string)$montant,
        'currency'            => $cfg['devise'],
        'error_url'           => $retour . '&resultat=echec',
        'success_url'         => $retour . '&resultat=succes',
        'client_reference'    => $reference,
        'checkout_intent'     => 'web_payment',
    ]);

    if (!$res['ok']) {
        $pdo->prepare("UPDATE paiements SET statut='echoue', detail=? WHERE id=?")
            ->execute([$res['erreur'], $idPaiement]);
        return ['ok' => false, 'erreur' => $res['erreur']];
    }

    $session = $res['data']['id'] ?? '';
    $url     = $res['data']['wave_launch_url'] ?? ($res['data']['launch_url'] ?? '');
    if ($session === '' || $url === '') {
        $pdo->prepare("UPDATE paiements SET statut='echoue', detail=? WHERE id=?")
            ->execute(['Réponse inattendue de Wave.', $idPaiement]);
        return ['ok' => false, 'erreur' => "Réponse inattendue de Wave."];
    }

    $pdo->prepare('UPDATE paiements SET session_id=?, url_paiement=?, detail=? WHERE id=?')
        ->execute([$session, $url, json_encode($res['data']), $idPaiement]);

    return ['ok' => true, 'url' => $url, 'reference' => $reference, 'montant' => $montant];
}

/* Somme déjà encaissée sur une facture (paiements en ligne confirmés). */
function paiements_deja_regles(PDO $pdo, int $factureId): float {
    $st = $pdo->prepare("SELECT COALESCE(SUM(montant),0) FROM paiements WHERE facture_id=? AND statut='paye'");
    $st->execute([$factureId]);
    return (float)$st->fetchColumn();
}

/* ---------- Vérification auprès de Wave ---------- */
function wave_verifier(PDO $pdo, array $paiement): array {
    $cfg = wave_config($pdo);
    if ($paiement['session_id'] === '') return ['ok' => false, 'erreur' => 'Session de paiement inconnue.'];
    $res = wave_appel(WAVE_API . '/' . rawurlencode($paiement['session_id']), $cfg['cle']);
    if (!$res['ok']) return $res;
    $d = $res['data'];
    $etat = $d['payment_status'] ?? ($d['status'] ?? '');
    return ['ok' => true, 'paye' => in_array($etat, ['succeeded', 'success', 'paid', 'complete'], true),
            'etat' => $etat, 'data' => $d];
}

/* ---------- Finalisation : reçu, comptabilité, emails ----------
   Cette fonction est protégée contre les doubles exécutions : si le paiement
   est déjà confirmé, elle ne fait rien. Le client peut donc rafraîchir la page
   ou le webhook arriver deux fois sans créer deux reçus.                        */
function paiement_finaliser(PDO $pdo, string $reference, array $settings, array $infoWave = []): array {
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM paiements WHERE reference=? FOR UPDATE');
        $st->execute([$reference]);
        $p = $st->fetch();
        if (!$p) { $pdo->rollBack(); return ['ok' => false, 'erreur' => 'Paiement introuvable.']; }
        if ($p['statut'] === 'paye') { $pdo->commit(); return ['ok' => true, 'deja' => true, 'paiement' => $p]; }

        $numero = next_numero($pdo, 'recus', ($settings['prefixe_recu'] ?? 'REC'));
        $pdo->prepare("INSERT INTO recus (numero, type, client_id, facture_id, montant, mode_paiement, motif, date_paiement, notes, vu_client)
                       VALUES (?,?,?,?,?,?,?,?,?,0)")
            ->execute([$numero, 'entree', $p['client_id'], $p['facture_id'], $p['montant'],
                       'Wave', 'Paiement en ligne', date('Y-m-d'),
                       'Référence du paiement : ' . $p['reference']
                       . (!empty($infoWave['transaction_id']) ? ' — transaction Wave : ' . $infoWave['transaction_id'] : '')]);
        $recuId = (int)$pdo->lastInsertId();

        // Comptabilité
        $pdo->prepare("INSERT INTO transactions (type, categorie, libelle, montant, mode_paiement, client_id, date_operation, notes)
                       VALUES ('entree','Ventes',?,?,?,?,?,?)")
            ->execute(['Paiement en ligne — reçu ' . $numero, $p['montant'], 'Wave', $p['client_id'], date('Y-m-d'),
                       'Règlement Wave, référence ' . $p['reference']
                       . (!empty($p['facture_id']) ? ' — facture n° ' . (int)$p['facture_id'] : '')]);

        // La facture est marquée réglée si le solde est couvert
        if (!empty($p['facture_id'])) {
            $f = $pdo->prepare('SELECT * FROM factures WHERE id=?');
            $f->execute([(int)$p['facture_id']]);
            if ($fac = $f->fetch()) {
                $regle = paiements_deja_regles($pdo, (int)$p['facture_id']) + (float)$p['montant'];
                $doc   = get_facture($pdo, (int)$p['facture_id']);
                if ($doc && $regle >= (float)$doc['montant_ttc'] - 1) {
                    $pdo->prepare("UPDATE factures SET statut='payee' WHERE id=?")->execute([(int)$p['facture_id']]);
                }
            }
        }

        // Le reçu est authentifiable immédiatement : son empreinte est calculée dès l'émission.
        // C'est ce qui le fait aussi apparaître dans le coffre à documents.
        try {
            $emp = doc_checksum([$numero, date('Y-m-d'), (string)(int)$p['montant']]);
            doc_token($pdo, 'recu', $recuId, $numero, $emp);
        } catch (Throwable $e) { /* l'authentification ne doit jamais bloquer un encaissement */ }

        $pdo->prepare("UPDATE paiements SET statut='paye', paye_le=NOW(), recu_id=?, transaction_id=?, detail=? WHERE id=?")
            ->execute([$recuId, mb_substr((string)($infoWave['transaction_id'] ?? ''), 0, 120),
                       json_encode($infoWave), (int)$p['id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'erreur' => 'Erreur pendant l\'enregistrement : ' . $e->getMessage()];
    }

    // Les emails sont envoyés hors transaction : un échec d'envoi ne doit jamais
    // remettre en cause un paiement déjà encaissé.
    journaliser($pdo, 'paiement', 'recu', $recuId ?? null,
        'Encaissement Wave ' . $reference . ' — ' . (int)$p['montant']);
    paiement_notifier($pdo, $reference, $settings);
    $st = $pdo->prepare('SELECT * FROM paiements WHERE reference=?');
    $st->execute([$reference]);
    return ['ok' => true, 'paiement' => $st->fetch()];
}

/* ---------- Emails : reçu au client, alerte à l'administration ---------- */
function paiement_notifier(PDO $pdo, string $reference, array $settings): void {
    $st = $pdo->prepare("SELECT p.*, r.numero AS recu_num, f.numero AS facture_num,
                                c.nom AS client_nom, c.email AS client_email, c.entreprise
                         FROM paiements p
                         LEFT JOIN recus r ON r.id = p.recu_id
                         LEFT JOIN factures f ON f.id = p.facture_id
                         LEFT JOIN clients c ON c.id = p.client_id
                         WHERE p.reference=?");
    $st->execute([$reference]);
    $p = $st->fetch();
    if (!$p) return;

    $montant = number_format((float)$p['montant'], 0, ',', ' ') . ' ' . ($settings['devise'] ?? 'FCFA');
    $nomCli  = trim((string)($p['entreprise'] ?? '')) !== '' ? $p['entreprise'] : ($p['client_nom'] ?? 'Cher client');
    $site    = rtrim((string)($settings['site_url'] ?? ''), '/');

    // 1) Reçu au client
    if (!empty($p['client_email'])) {
        $corps = '<p>Bonjour <strong>' . htmlspecialchars($nomCli) . '</strong>,</p>'
               . '<p>Nous confirmons la bonne réception de votre paiement.</p>'
               . '<table style="width:100%;border-collapse:collapse;margin:18px 0">'
               . '<tr><td style="padding:7px 0;color:#6e7685">Montant réglé</td><td style="padding:7px 0;text-align:right;font-weight:bold;color:#0a1f44">' . $montant . '</td></tr>'
               . '<tr><td style="padding:7px 0;color:#6e7685">Moyen de paiement</td><td style="padding:7px 0;text-align:right">Wave</td></tr>'
               . ($p['facture_num'] ? '<tr><td style="padding:7px 0;color:#6e7685">Facture</td><td style="padding:7px 0;text-align:right">' . htmlspecialchars($p['facture_num']) . '</td></tr>' : '')
               . ($p['recu_num'] ? '<tr><td style="padding:7px 0;color:#6e7685">N° de reçu</td><td style="padding:7px 0;text-align:right"><strong>' . htmlspecialchars($p['recu_num']) . '</strong></td></tr>' : '')
               . '<tr><td style="padding:7px 0;color:#6e7685">Référence</td><td style="padding:7px 0;text-align:right">' . htmlspecialchars($p['reference']) . '</td></tr>'
               . '</table>'
               . ($site ? '<p style="text-align:center;margin:24px 0"><a href="' . $site . '/espace-client/" style="background:#d4a526;color:#0a1020;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:bold">Voir mon reçu</a></p>' : '')
               . '<p>Merci de votre confiance.</p>';
        @envoyer_email($pdo, $p['client_email'], 'Reçu de paiement ' . ($p['recu_num'] ?? ''), $corps);
    }

    // 2) Alerte à l'administration
    $dest = trim((string)($settings['email'] ?? ''));
    if ($dest !== '') {
        $corps = '<p>Un paiement en ligne vient d\'être encaissé.</p>'
               . '<table style="width:100%;border-collapse:collapse;margin:18px 0">'
               . '<tr><td style="padding:7px 0;color:#6e7685">Client</td><td style="padding:7px 0;text-align:right"><strong>' . htmlspecialchars($nomCli) . '</strong></td></tr>'
               . '<tr><td style="padding:7px 0;color:#6e7685">Montant</td><td style="padding:7px 0;text-align:right;font-weight:bold">' . $montant . '</td></tr>'
               . ($p['facture_num'] ? '<tr><td style="padding:7px 0;color:#6e7685">Facture</td><td style="padding:7px 0;text-align:right">' . htmlspecialchars($p['facture_num']) . '</td></tr>' : '')
               . ($p['recu_num'] ? '<tr><td style="padding:7px 0;color:#6e7685">Reçu émis</td><td style="padding:7px 0;text-align:right">' . htmlspecialchars($p['recu_num']) . '</td></tr>' : '')
               . '<tr><td style="padding:7px 0;color:#6e7685">Référence</td><td style="padding:7px 0;text-align:right">' . htmlspecialchars($p['reference']) . '</td></tr>'
               . '</table>'
               . '<p>La comptabilité a été mise à jour automatiquement.</p>';
        @envoyer_email($pdo, $dest, 'Paiement reçu — ' . $montant, $corps);
    }
}

/* ---------- Vérification de la signature du webhook ---------- */
function wave_signature_valide(string $corps, string $entete, string $secret): bool {
    if ($secret === '' || $entete === '') return false;
    // En-tête au format « t=timestamp, v1=signature »
    $t = ''; $sigs = [];
    foreach (explode(',', $entete) as $part) {
        $part = trim($part);
        if (strpos($part, 't=') === 0)  $t = substr($part, 2);
        if (strpos($part, 'v1=') === 0) $sigs[] = substr($part, 3);
    }
    if ($t === '' || !$sigs) return false;
    if (abs(time() - (int)$t) > 600) return false;          // rejette les requêtes trop anciennes
    $attendue = hash_hmac('sha256', $t . $corps, $secret);
    foreach ($sigs as $s) if (hash_equals($attendue, $s)) return true;
    return false;
}
