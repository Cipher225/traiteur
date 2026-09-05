<?php
/* ============================================================================
   RELANCE DES FACTURES IMPAYÉES
   ----------------------------------------------------------------------------
   Repère les factures échues et non réglées, puis envoie un rappel courtois au
   client. Trois niveaux, du simple rappel à la mise en demeure, avec un délai
   minimum entre deux envois pour ne jamais harceler un client.
   ============================================================================ */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail.php';

/* Paliers de relance : jours de retard → niveau */
function relance_niveaux(): array {
    return [
        1 => ['jours' => 3,  'titre' => 'Rappel amical',
              'intro' => "Sauf erreur de notre part, la facture suivante reste à régler. Il s'agit peut-être d'un simple oubli."],
        2 => ['jours' => 15, 'titre' => 'Relance',
              'intro' => "Malgré notre précédent message, nous n'avons pas encore reçu votre règlement."],
        3 => ['jours' => 30, 'titre' => 'Relance — dernier rappel',
              'intro' => "Votre facture demeure impayée malgré nos relances. Nous vous remercions de procéder au règlement dans les meilleurs délais."],
    ];
}

/* Délai minimum entre deux relances d'une même facture (jours) */
const RELANCE_INTERVALLE = 7;

/* ---------- Factures à relancer ---------- */
function factures_impayees(PDO $pdo, bool $seulementDues = true): array {
    $sql = "SELECT f.*, COALESCE(NULLIF(c.entreprise,''), c.nom) AS client_nom, c.email AS client_email,
                   DATEDIFF(CURDATE(), f.date_echeance) AS retard,
                   (SELECT COUNT(*) FROM relances r WHERE r.facture_id = f.id) AS nb_relances,
                   (SELECT MAX(r.envoye_le) FROM relances r WHERE r.facture_id = f.id) AS derniere_relance
            FROM factures f
            LEFT JOIN clients c ON c.id = f.client_id
            WHERE f.type = 'facture'
              AND f.statut NOT IN ('payee','annulee','brouillon')
              AND f.date_echeance IS NOT NULL";
    if ($seulementDues) $sql .= " AND f.date_echeance < CURDATE()";
    $sql .= " ORDER BY f.date_echeance ASC";

    $liste = [];
    foreach ($pdo->query($sql)->fetchAll() as $f) {
        $doc = get_facture($pdo, (int)$f['id']);
        if (!$doc) continue;
        $regle = paiements_deja_regles($pdo, (int)$f['id']);
        $solde = (float)$doc['montant_ttc'] - $regle;
        if ($solde <= 1) continue;                        // déjà réglée
        $f['montant_ttc'] = $doc['montant_ttc'];
        $f['solde']  = $solde;
        $f['niveau'] = relance_niveau_pour((int)$f['retard']);
        $f['relancable'] = relance_possible($f);
        $liste[] = $f;
    }
    return $liste;
}

function relance_niveau_pour(int $retard): int {
    $n = 0;
    foreach (relance_niveaux() as $niv => $def) if ($retard >= $def['jours']) $n = $niv;
    return $n;
}

/* Une facture n'est relançable que si le palier est atteint et le délai respecté. */
function relance_possible(array $f): bool {
    if ((int)$f['niveau'] < 1) return false;
    if (empty($f['client_email'])) return false;
    if (!empty($f['derniere_relance'])) {
        $jours = (time() - strtotime($f['derniere_relance'])) / 86400;
        if ($jours < RELANCE_INTERVALLE) return false;
    }
    return (int)$f['nb_relances'] < count(relance_niveaux());
}

/* ---------- Envoi ---------- */
function envoyer_relance(PDO $pdo, array $f, array $settings, string $origine = 'manuelle'): array {
    if (empty($f['client_email'])) return ['ok' => false, 'erreur' => "Ce client n'a pas d'adresse email."];

    $niveau = max(1, (int)$f['niveau']);
    $def    = relance_niveaux()[$niveau];
    $devise = $settings['devise'] ?? 'FCFA';
    $site   = rtrim((string)($settings['site_url'] ?? ''), '/');
    $montant = number_format((float)$f['solde'], 0, ',', ' ') . ' ' . $devise;

    $corps = '<p>Bonjour <strong>' . htmlspecialchars($f['client_nom'] ?? 'Cher client') . '</strong>,</p>'
           . '<p>' . htmlspecialchars($def['intro']) . '</p>'
           . '<table style="width:100%;border-collapse:collapse;margin:18px 0">'
           . '<tr><td style="padding:7px 0;color:#6e7685">Facture</td><td style="padding:7px 0;text-align:right;font-weight:bold;color:#0a1f44">' . htmlspecialchars($f['numero']) . '</td></tr>'
           . '<tr><td style="padding:7px 0;color:#6e7685">Date d\'échéance</td><td style="padding:7px 0;text-align:right">' . date('d/m/Y', strtotime($f['date_echeance'])) . '</td></tr>'
           . '<tr><td style="padding:7px 0;color:#6e7685">Retard</td><td style="padding:7px 0;text-align:right">' . (int)$f['retard'] . ' jour' . ((int)$f['retard'] > 1 ? 's' : '') . '</td></tr>'
           . '<tr><td style="padding:7px 0;color:#6e7685">Montant restant dû</td><td style="padding:7px 0;text-align:right;font-weight:bold;font-size:16px;color:#0a1f44">' . $montant . '</td></tr>'
           . '</table>';

    if ($site !== '' && wave_disponible($pdo)) {
        $corps .= '<p style="text-align:center;margin:22px 0">'
                . '<a href="' . $site . '/espace-client/payer.php" style="background:#d4a526;color:#0a1020;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:bold">Régler en ligne</a></p>';
    }
    $corps .= '<p>Si le règlement a été effectué entre-temps, merci de ne pas tenir compte de ce message.</p>'
            . '<p>Nous restons à votre disposition pour toute question.</p>';

    $ok = envoyer_email($pdo, $f['client_email'],
                        $def['titre'] . ' — facture ' . $f['numero'], $corps);

    try {
        $pdo->prepare('INSERT INTO relances (facture_id, client_id, niveau, montant, destinataire, origine, succes)
                       VALUES (?,?,?,?,?,?,?)')
            ->execute([(int)$f['id'], $f['client_id'] ?: null, $niveau, (int)$f['solde'],
                       mb_substr($f['client_email'], 0, 190), $origine, $ok ? 1 : 0]);
    } catch (Throwable $e) {}

    if (function_exists('journaliser')) {
        journaliser($pdo, 'relance', 'facture', (int)$f['id'],
                    'Relance niveau ' . $niveau . ' — ' . $f['numero'] . ' (' . $montant . ')');
    }
    return $ok ? ['ok' => true] : ['ok' => false, 'erreur' => "L'email n'a pas pu être envoyé."];
}

/* ---------- Passage automatique (appelé chaque jour) ---------- */
/* Les relances ne partent JAMAIS toutes seules : rien ne s'envoie sans une
   action explicite de votre part. Cette fonction n'est conservée que pour un
   éventuel usage manuel groupé depuis la page Impayés. */
function relances_automatiques(PDO $pdo): array {
    return ['actif' => false, 'envoyees' => 0];
}

function relances_groupees(PDO $pdo): array {
    $settings = get_settings($pdo);
    $n = 0;
    foreach (factures_impayees($pdo) as $f) {
        if (!$f['relancable']) continue;
        $r = envoyer_relance($pdo, $f, $settings, 'automatique');
        if (!empty($r['ok'])) $n++;
    }
    return ['actif' => true, 'envoyees' => $n];
}
