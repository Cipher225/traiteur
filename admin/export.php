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
/* ----------------------------------------------------------------------------
   Deux formats. Le CSV se relit par n'importe quel logiciel comptable. Le
   tableau mis en forme s'ouvre directement dans Excel, avec l'en-tête de
   l'entreprise, des couleurs et les totaux : c'est celui qu'on transmet à un
   comptable ou qu'on archive.
   ---------------------------------------------------------------------------- */
$totaux = [];
foreach ($lignes as $l) {
    foreach ($l as $i => $v) if (is_int($v)) $totaux[$i] = ($totaux[$i] ?? 0) + $v;
}

if (($_GET['f'] ?? '') === 'tableau') {

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/\.csv$/', '.xls', $nom) . '"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";

    $ent   = $settings['nom_entreprise'] ?? '';
    $nbCol = max(1, count($entetes));
    $ident = array_filter([
        trim((string)($settings['adresse'] ?? '')),
        trim((string)($settings['telephone'] ?? '')),
        trim((string)($settings['email'] ?? '')),
        !empty($settings['rccm']) ? 'RCCM : ' . $settings['rccm'] : '',
        !empty($settings['ncc']) ? 'N° CC : ' . $settings['ncc'] : '',
    ]);
    ?>
<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<style>
  table { border-collapse: collapse; font-family: Calibri, Arial, sans-serif; font-size: 11pt; }
  .marque   { background:#0A1F44; color:#FFFFFF; font-size:16pt; font-weight:bold;
              height:34pt; vertical-align:middle; padding:8px 12px; }
  .slogan   { background:#0A1F44; color:#E9C15C; font-size:9pt; padding:0 12px 8px; }
  .ident    { background:#F6F8FB; color:#4A5568; font-size:8.5pt; padding:6px 12px; }
  .periode  { background:#D4A526; color:#0A1020; font-weight:bold; font-size:10.5pt; padding:7px 12px; }
  th        { background:#0A1F44; color:#FFFFFF; font-size:9.5pt; font-weight:bold;
              border:1px solid #0A1F44; padding:7px 9px; text-align:left; }
  td        { border:1px solid #DDE3EC; padding:5px 9px; vertical-align:top; }
  .paire td { background:#F7F9FC; }
  .num      { text-align:right; mso-number-format:"\#\,\#\#0"; }
  .total td { background:#E9C15C; color:#0A1020; font-weight:bold; border-color:#D4A526; }
  .pied     { color:#8A9AB5; font-size:8pt; padding-top:10px; }
</style></head><body>
<table>
  <tr><td class="marque" colspan="<?= $nbCol ?>"><?= e(mb_strtoupper($ent)) ?></td></tr>
  <?php if (!empty($settings['slogan'])): ?>
  <tr><td class="slogan" colspan="<?= $nbCol ?>"><?= e($settings['slogan']) ?></td></tr>
  <?php endif; ?>
  <?php if ($ident): ?>
  <tr><td class="ident" colspan="<?= $nbCol ?>"><?= e(implode('  ·  ', $ident)) ?></td></tr>
  <?php endif; ?>
  <tr><td class="periode" colspan="<?= $nbCol ?>">
    <?= e(mb_strtoupper(str_replace('-', ' ', $titre))) ?> —
    du <?= date('d/m/Y', strtotime($debut)) ?> au <?= date('d/m/Y', strtotime($fin)) ?>
    · montants en <?= e($devise) ?></td></tr>
  <tr><td colspan="<?= $nbCol ?>" style="height:6pt"></td></tr>

  <tr><?php foreach ($entetes as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr>

  <?php foreach ($lignes as $n => $l): ?>
  <tr class="<?= $n % 2 ? 'paire' : '' ?>">
    <?php foreach ($l as $v): ?>
    <td class="<?= is_int($v) ? 'num' : '' ?>"><?= is_int($v) ? $v : e((string)$v) ?></td>
    <?php endforeach; ?>
  </tr>
  <?php endforeach; ?>

  <?php if ($lignes && $totaux): ?>
  <tr class="total">
    <?php for ($i = 0; $i < $nbCol; $i++): ?>
    <td class="<?= isset($totaux[$i]) ? 'num' : '' ?>">
      <?= $i === 0 ? 'TOTAL (' . count($lignes) . ' ligne' . (count($lignes) > 1 ? 's' : '') . ')'
                   : (isset($totaux[$i]) ? $totaux[$i] : '') ?></td>
    <?php endfor; ?>
  </tr>
  <?php endif; ?>

  <tr><td class="pied" colspan="<?= $nbCol ?>">
    Document généré le <?= date('d/m/Y à H:i') ?> depuis l'application de gestion
    <?= e($ent) ?>. Les montants sont exprimés en <?= e($devise) ?>.
  </td></tr>
</table>
</body></html>
    <?php

} else {

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

    foreach ($lignes as $l) fputcsv($sortie, $l, ';');

    if ($lignes && $totaux) {
        $ligneTotal = array_fill(0, count($entetes), '');
        $ligneTotal[0] = 'TOTAL (' . count($lignes) . ' ligne' . (count($lignes) > 1 ? 's' : '') . ')';
        foreach ($totaux as $i => $v) $ligneTotal[$i] = $v;
        fputcsv($sortie, [], ';');
        fputcsv($sortie, $ligneTotal, ';');
    }

    fclose($sortie);
}

if (function_exists('journaliser')) {
    journaliser($pdo, 'export', $titre, null, 'Export du ' . $debut . ' au ' . $fin . ' (' . count($lignes) . ' lignes)');
}
