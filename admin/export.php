<?php
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/documents.php';
require_once __DIR__ . '/../config/wave.php';   // pour connaître les montants déjà réglés

/* ============================================================================
   EXPORT COMPTABLE
   ----------------------------------------------------------------------------
   Produit un fichier CSV directement exploitable par Excel et par les logiciels
   comptables : séparateur point-virgule et marqueur d'encodage, pour que les
   accents s'affichent correctement sans manipulation.
   ============================================================================ */

if (!is_admin()) { http_response_code(403); exit('Export réservé à l\'administrateur.'); }

$type   = $_GET['t'] ?? 'transactions';
$debut  = $_GET['du'] ?? date('Y-01-01');
$fin    = $_GET['au'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut)) $debut = date('Y-01-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin))   $fin   = date('Y-m-d');

$devise = $settings['devise'] ?? 'FCFA';

/* ---------- Contenu selon le type demandé ---------- */
switch ($type) {

    case 'factures':
        $titre = 'factures';
        $entetes = ['Numéro', 'Type', 'Date', 'Échéance', 'Client', 'Entreprise', 'NCC',
                    'Montant HT', 'TVA', 'Montant TTC', 'Déjà réglé', 'Restant dû', 'Statut', 'Mode de paiement'];
        $st = $pdo->prepare("SELECT f.*, c.nom AS cnom, c.entreprise, c.ncc
                             FROM factures f LEFT JOIN clients c ON c.id = f.client_id
                             WHERE f.date_emission BETWEEN ? AND ? ORDER BY f.date_emission, f.id");
        $st->execute([$debut, $fin]);
        $lignes = [];
        foreach ($st->fetchAll() as $f) {
            $doc = get_facture($pdo, (int)$f['id']);
            if (!$doc) continue;
            $regle = paiements_deja_regles($pdo, (int)$f['id']);
            $lignes[] = [
                $f['numero'], $f['type'] === 'proforma' ? 'Proforma' : 'Facture',
                date('d/m/Y', strtotime($f['date_emission'])),
                $f['date_echeance'] ? date('d/m/Y', strtotime($f['date_echeance'])) : '',
                $f['cnom'] ?? '', $f['entreprise'] ?? '', $f['ncc'] ?? '',
                (int)$doc['base'], (int)$doc['montant_tva'], (int)$doc['montant_ttc'],
                (int)$regle, (int)max(0, $doc['montant_ttc'] - $regle),
                ucfirst($f['statut']), $f['mode_paiement'] ?? '',
            ];
        }
        break;

    case 'paiements':
        $titre = 'paiements-en-ligne';
        $entetes = ['Date', 'Référence', 'Facture', 'Client', 'Montant', 'Moyen', 'État', 'Reçu', 'Transaction'];
        $st = $pdo->prepare("SELECT p.*, f.numero AS facture, r.numero AS recu,
                                    COALESCE(NULLIF(c.entreprise,''), c.nom) AS client
                             FROM paiements p
                             LEFT JOIN factures f ON f.id = p.facture_id
                             LEFT JOIN recus r ON r.id = p.recu_id
                             LEFT JOIN clients c ON c.id = p.client_id
                             WHERE DATE(p.created_at) BETWEEN ? AND ? ORDER BY p.created_at");
        $st->execute([$debut, $fin]);
        $lignes = array_map(fn($p) => [
            date('d/m/Y H:i', strtotime($p['created_at'])), $p['reference'], $p['facture'] ?? '',
            $p['client'] ?? '', (int)$p['montant'], 'Wave',
            ['en_attente' => 'En attente', 'paye' => 'Payé', 'echoue' => 'Échoué', 'annule' => 'Annulé'][$p['statut']] ?? $p['statut'],
            $p['recu'] ?? '', $p['transaction_id'] ?? '',
        ], $st->fetchAll());
        break;

    case 'clients':
        $titre = 'clients';
        $entetes = ['Nom', 'Entreprise', 'NCC', 'Téléphone', 'Email', 'Adresse', 'Type',
                    'Nb factures', 'Total facturé', 'Total réglé', 'Restant dû'];
        $lignes = [];
        foreach ($pdo->query("SELECT * FROM clients ORDER BY nom")->fetchAll() as $c) {
            $nb = 0; $fact = 0; $regle = 0;
            $st = $pdo->prepare("SELECT id FROM factures WHERE client_id=? AND type='facture' AND statut<>'annulee'");
            $st->execute([(int)$c['id']]);
            foreach ($st->fetchAll() as $f) {
                $doc = get_facture($pdo, (int)$f['id']);
                if (!$doc) continue;
                $nb++; $fact += (float)$doc['montant_ttc'];
                $regle += paiements_deja_regles($pdo, (int)$f['id']);
            }
            $lignes[] = [$c['nom'], $c['entreprise'] ?? '', $c['ncc'] ?? '', $c['telephone'] ?? '',
                         $c['email'] ?? '', $c['adresse'] ?? '', $c['type_client'] ?? 'individuel',
                         $nb, (int)$fact, (int)$regle, (int)max(0, $fact - $regle)];
        }
        break;

    default:   // transactions comptables
        $titre = 'comptabilite';
        $entetes = ['Date', 'Sens', 'Catégorie', 'Libellé', 'Montant', 'Moyen de paiement', 'Client', 'Notes'];
        $st = $pdo->prepare("SELECT t.*, COALESCE(NULLIF(c.entreprise,''), c.nom) AS client
                             FROM transactions t LEFT JOIN clients c ON c.id = t.client_id
                             WHERE t.date_operation BETWEEN ? AND ? ORDER BY t.date_operation, t.id");
        $st->execute([$debut, $fin]);
        $lignes = array_map(fn($t) => [
            date('d/m/Y', strtotime($t['date_operation'])),
            $t['type'] === 'entree' ? 'Entrée' : 'Dépense',
            $t['categorie'], $t['libelle'], (int)$t['montant'],
            $t['mode_paiement'], $t['client'] ?? '', $t['notes'] ?? '',
        ], $st->fetchAll());
}

/* ---------- Écriture du fichier ---------- */
$nom = $titre . '-' . $debut . '_' . $fin . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nom . '"');
header('Cache-Control: no-store');

$sortie = fopen('php://output', 'w');
fwrite($sortie, "\xEF\xBB\xBF");                     // marqueur reconnu par Excel

fputcsv($sortie, [mb_strtoupper($titre) . ' — ' . ($settings['nom_entreprise'] ?? '')], ';');
fputcsv($sortie, ['Période du ' . date('d/m/Y', strtotime($debut)) . ' au ' . date('d/m/Y', strtotime($fin))
                  . ' — montants en ' . $devise], ';');
fputcsv($sortie, [], ';');
fputcsv($sortie, $entetes, ';');

$totaux = [];
foreach ($lignes as $l) {
    fputcsv($sortie, $l, ';');
    foreach ($l as $i => $v) if (is_int($v)) $totaux[$i] = ($totaux[$i] ?? 0) + $v;
}

if ($lignes && $totaux) {
    $ligneTotal = array_fill(0, count($entetes), '');
    $ligneTotal[0] = 'TOTAL (' . count($lignes) . ' ligne' . (count($lignes) > 1 ? 's' : '') . ')';
    foreach ($totaux as $i => $v) $ligneTotal[$i] = $v;
    fputcsv($sortie, [], ';');
    fputcsv($sortie, $ligneTotal, ';');
}

fclose($sortie);

if (function_exists('journaliser')) {
    journaliser($pdo, 'export', $titre, null, 'Export du ' . $debut . ' au ' . $fin . ' (' . count($lignes) . ' lignes)');
}
