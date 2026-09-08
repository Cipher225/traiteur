<?php
/* ============================================================================
   MOTEUR D'AUDIT FINANCIER
   ----------------------------------------------------------------------------
   Applique à VOS données les règles de contrôle qu'un comptable vérifierait.
   Chaque règle répond à une question simple : « cet écart peut-il exister
   légitimement ? ». Si la réponse est non, c'est une anomalie.

   Principe absolu : cet audit ne modifie RIEN. Il lit, il compare, il signale.
   Un outil de contrôle qui corrige tout seul masquerait la cause du problème.
   ============================================================================ */

require_once __DIR__ . '/../config/db.php';

/* Gravité d'une anomalie :
   critique  → vos chiffres sont faux, il faut agir
   attention → probablement une erreur de saisie
   info      → à vérifier, sans gravité immédiate */
function audit_financier(PDO $pdo): array
{
    $familles = [];

    /* ======================================================================
       FAMILLE 1 — COHÉRENCE DES ENCAISSEMENTS
       ====================================================================== */
    $regles = [];

    /* Une facture payée doit avoir été encaissée pour son montant exact. */
    $anomalies = [];
    try {
        foreach ($pdo->query("SELECT id, numero FROM factures WHERE type='facture' AND statut='payee'")->fetchAll() as $f) {
            $ttc  = facture_ttc($pdo, (int)$f['id']);
            $recu = facture_deja_encaisse($pdo, (int)$f['id']);
            $solde = (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM transactions
                                         WHERE type='entree' AND libelle='Solde facture " . addslashes($f['numero']) . "'")->fetchColumn();
            $total = $recu + $solde;
            if (abs($total - $ttc) > 1) {
                $anomalies[] = ['texte' => 'Facture ' . $f['numero'] . ' : encaissé ' . audit_mt($total)
                                         . ' pour un montant de ' . audit_mt($ttc),
                                'ecart' => $total - $ttc,
                                'lien'  => 'factures.php'];
            }
        }
    } catch (Throwable $e) {}
    $regles[] = ['nom' => 'Chaque facture payée est encaissée pour son montant exact',
                 'pourquoi' => "Un écart signifie que votre chiffre d'affaires est faux, dans un sens ou dans l'autre.",
                 'gravite' => 'critique', 'anomalies' => $anomalies];

    /* Une facture non soldée ne doit pas être déjà entièrement encaissée. */
    $anomalies = [];
    try {
        foreach ($pdo->query("SELECT id, numero FROM factures
                              WHERE type='facture' AND statut IN ('envoyee','brouillon')")->fetchAll() as $f) {
            $ttc  = facture_ttc($pdo, (int)$f['id']);
            $recu = facture_deja_encaisse($pdo, (int)$f['id']);
            if ($ttc > 0 && $recu >= $ttc - 1) {
                $anomalies[] = ['texte' => 'Facture ' . $f['numero'] . ' : intégralement réglée ('
                                         . audit_mt($recu) . ') mais toujours marquée non payée',
                                'lien' => 'factures.php'];
            }
        }
    } catch (Throwable $e) {}
    $regles[] = ['nom' => 'Aucune facture réglée ne reste marquée impayée',
                 'pourquoi' => "Vous relanceriez un client qui a déjà payé, et vos impayés seraient surévalués.",
                 'gravite' => 'attention', 'anomalies' => $anomalies];

    /* Un client ne devrait pas avoir versé plus que le montant dû. */
    $anomalies = [];
    try {
        foreach ($pdo->query("SELECT id, numero FROM factures WHERE type='facture' AND statut <> 'annulee'")->fetchAll() as $f) {
            $ttc  = facture_ttc($pdo, (int)$f['id']);
            $recu = facture_deja_encaisse($pdo, (int)$f['id']);
            if ($ttc > 0 && $recu > $ttc + 1) {
                $anomalies[] = ['texte' => 'Facture ' . $f['numero'] . ' : trop-perçu de ' . audit_mt($recu - $ttc),
                                'lien' => 'recus.php?type=entree'];
            }
        }
    } catch (Throwable $e) {}
    $regles[] = ['nom' => 'Aucun trop-perçu non justifié',
                 'pourquoi' => "Soit le client a payé en trop et vous lui devez un remboursement, soit un encaissement a été saisi deux fois.",
                 'gravite' => 'attention', 'anomalies' => $anomalies];

    $familles[] = ['titre' => 'Cohérence des encaissements', 'icone' => '💰', 'regles' => $regles];

    /* ======================================================================
       FAMILLE 2 — DOUBLONS ET SAISIES RÉPÉTÉES
       ====================================================================== */
    $regles = [];

    $anomalies = [];
    try {
        $st = $pdo->query("SELECT type, montant, date_operation, COUNT(*) n,
                                  GROUP_CONCAT(DISTINCT libelle SEPARATOR ' / ') libelles
                           FROM transactions
                           GROUP BY type, montant, date_operation
                           HAVING n > 1 AND montant > 0
                           ORDER BY montant DESC LIMIT 25");
        foreach ($st->fetchAll() as $d) {
            $anomalies[] = ['texte' => $d['n'] . ' écritures identiques de ' . audit_mt($d['montant'])
                                     . ' le ' . date('d/m/Y', strtotime($d['date_operation']))
                                     . ' — ' . mb_substr($d['libelles'], 0, 70),
                            'lien' => 'comptabilite.php'];
        }
    } catch (Throwable $e) {}
    $regles[] = ['nom' => 'Aucune opération enregistrée deux fois le même jour',
                 'pourquoi' => "Deux écritures identiques le même jour sont presque toujours la même opération saisie deux fois.",
                 'gravite' => 'attention', 'anomalies' => $anomalies];

    $anomalies = [];
    try {
        $st = $pdo->query("SELECT libelle, COUNT(*) n FROM transactions
                           WHERE libelle LIKE 'Solde facture %' OR libelle LIKE 'Salaire %'
                           GROUP BY libelle HAVING n > 1");
        foreach ($st->fetchAll() as $d) {
            $anomalies[] = ['texte' => mb_substr($d['libelle'], 0, 60) . ' apparaît ' . $d['n'] . ' fois',
                            'lien' => 'comptabilite.php'];
        }
    } catch (Throwable $e) {}
    $regles[] = ['nom' => 'Aucun encaissement ou salaire automatique en double',
                 'pourquoi' => "Ces écritures sont générées par l'application : elles doivent être uniques.",
                 'gravite' => 'critique', 'anomalies' => $anomalies];

    $anomalies = [];
    try {
        $st = $pdo->query("SELECT libelle, DATE_FORMAT(date_operation,'%Y-%m') m, COUNT(*) n
                           FROM transactions
                           WHERE libelle IN (SELECT libelle FROM charges_recurrentes)
                           GROUP BY libelle, m HAVING n > 1");
        foreach ($st->fetchAll() as $d) {
            $anomalies[] = ['texte' => 'Charge « ' . $d['libelle'] . ' » enregistrée ' . $d['n'] . ' fois en ' . $d['m'],
                            'lien' => 'comptabilite.php#charges'];
        }
    } catch (Throwable $e) {}
    $regles[] = ['nom' => 'Chaque charge récurrente n\'est enregistrée qu\'une fois par mois',
                 'pourquoi' => "Un loyer payé une fois ne doit apparaître qu'une fois.",
                 'gravite' => 'critique', 'anomalies' => $anomalies];

    $familles[] = ['titre' => 'Doublons et saisies répétées', 'icone' => '🔁', 'regles' => $regles];

    /* ======================================================================
       FAMILLE 3 — ÉCRITURES ORPHELINES
       ====================================================================== */
    $regles = [];

    $anomalies = [];
    try {
        $st = $pdo->query("SELECT t.id, t.libelle, t.montant FROM transactions t
                           WHERE t.recu_id IS NOT NULL
                             AND NOT EXISTS (SELECT 1 FROM recus r WHERE r.id = t.recu_id) LIMIT 25");
        foreach ($st->fetchAll() as $d) {
            $anomalies[] = ['texte' => 'Écriture « ' . mb_substr($d['libelle'], 0, 50) . ' » de '
                                     . audit_mt($d['montant']) . ' : son bon de caisse n\'existe plus',
                            'lien' => 'comptabilite.php'];
        }
    } catch (Throwable $e) {}
    $regles[] = ['nom' => 'Aucune écriture sans son bon de caisse',
                 'pourquoi' => "Une écriture sans justificatif ne peut pas être défendue devant un contrôle.",
                 'gravite' => 'critique', 'anomalies' => $anomalies];

    $anomalies = [];
    try {
        $st = $pdo->query("SELECT r.id, r.numero, r.montant FROM recus r
                           WHERE NOT EXISTS (SELECT 1 FROM transactions t WHERE t.recu_id = r.id) LIMIT 25");
        foreach ($st->fetchAll() as $d) {
            $anomalies[] = ['texte' => 'Bon ' . $d['numero'] . ' (' . audit_mt($d['montant'])
                                     . ') sans écriture comptable',
                            'lien' => 'recus.php?type=entree'];
        }
    } catch (Throwable $e) {}
    $regles[] = ['nom' => 'Chaque bon de caisse a son écriture comptable',
                 'pourquoi' => "De l'argent est passé par la caisse sans apparaître dans vos comptes.",
                 'gravite' => 'critique', 'anomalies' => $anomalies];

    $anomalies = [];
    try {
        $st = $pdo->query("SELECT r.id, r.numero FROM recus r
                           WHERE r.facture_id IS NOT NULL
                             AND NOT EXISTS (SELECT 1 FROM factures f WHERE f.id = r.facture_id) LIMIT 25");
        foreach ($st->fetchAll() as $d) {
            $anomalies[] = ['texte' => 'Bon ' . $d['numero'] . ' rattaché à une facture supprimée',
                            'lien' => 'recus.php?type=entree'];
        }
    } catch (Throwable $e) {}
    $regles[] = ['nom' => 'Aucun bon rattaché à une facture disparue',
                 'pourquoi' => "Le rattachement fausse le calcul de rentabilité de l'activité.",
                 'gravite' => 'attention', 'anomalies' => $anomalies];

    $anomalies = [];
    try {
        $st = $pdo->query("SELECT p.reference, p.montant FROM paiements p
                           WHERE p.statut='paye' AND (p.recu_id IS NULL
                             OR NOT EXISTS (SELECT 1 FROM recus r WHERE r.id = p.recu_id)) LIMIT 25");
        foreach ($st->fetchAll() as $d) {
            $anomalies[] = ['texte' => 'Paiement en ligne ' . $d['reference'] . ' (' . audit_mt($d['montant'])
                                     . ') sans reçu',
                            'lien' => 'paiements.php'];
        }
    } catch (Throwable $e) {}
    $regles[] = ['nom' => 'Chaque paiement en ligne a produit son reçu',
                 'pourquoi' => "Le client a payé mais ne dispose d'aucun justificatif.",
                 'gravite' => 'critique', 'anomalies' => $anomalies];

    $familles[] = ['titre' => 'Écritures orphelines', 'icone' => '🔗', 'regles' => $regles];

    /* ======================================================================
       FAMILLE 4 — RÈGLES DE TENUE DE COMPTES
       ====================================================================== */
    $regles = [];

    $anomalies = [];
    try {
        $st = $pdo->query("SELECT id, libelle, montant FROM transactions WHERE montant <= 0 LIMIT 25");
        foreach ($st->fetchAll() as $d) {
            $anomalies[] = ['texte' => 'Écriture « ' . mb_substr($d['libelle'], 0, 50) . ' » de '
                                     . audit_mt($d['montant']),
                            'lien' => 'comptabilite.php'];
        }
    } catch (Throwable $e) {}
    $regles[] = ['nom' => 'Aucun montant nul ou négatif',
                 'pourquoi' => "Un montant négatif s'exprime par le sens de l'opération — entrée ou sortie — jamais par un signe.",
                 'gravite' => 'critique', 'anomalies' => $anomalies];

    $anomalies = [];
    try {
        $structurelles = charges_structurelles();
        $trous = implode(',', array_fill(0, count($structurelles), '?'));
        $st = $pdo->prepare("SELECT t.id, t.categorie, t.montant, f.numero
                             FROM transactions t JOIN factures f ON f.id = t.facture_id
                             WHERE t.type='depense' AND t.categorie IN ($trous) LIMIT 25");
        $st->execute($structurelles);
        foreach ($st->fetchAll() as $d) {
            $anomalies[] = ['texte' => $d['categorie'] . ' de ' . audit_mt($d['montant'])
                                     . ' imputé à la facture ' . $d['numero'],
                            'lien' => 'recus.php?type=sortie'];
        }
    } catch (Throwable $e) {}
    $regles[] = ['nom' => "Aucune charge de structure imputée à une prestation",
                 'pourquoi' => "Le loyer court même sans événement : l'imputer à une prestation fausse sa rentabilité.",
                 'gravite' => 'attention', 'anomalies' => $anomalies];

    $anomalies = [];
    try {
        $st = $pdo->query("SELECT id, libelle, montant FROM transactions
                           WHERE COALESCE(categorie,'') = '' LIMIT 25");
        foreach ($st->fetchAll() as $d) {
            $anomalies[] = ['texte' => 'Écriture « ' . mb_substr($d['libelle'], 0, 50) . ' » sans catégorie',
                            'lien' => 'comptabilite.php'];
        }
    } catch (Throwable $e) {}
    $regles[] = ['nom' => 'Chaque écriture porte une catégorie',
                 'pourquoi' => "Sans catégorie, impossible de savoir où part votre argent.",
                 'gravite' => 'info', 'anomalies' => $anomalies];

    $anomalies = [];
    try {
        $st = $pdo->query("SELECT id, libelle, date_operation FROM transactions
                           WHERE date_operation > CURDATE() LIMIT 25");
        foreach ($st->fetchAll() as $d) {
            $anomalies[] = ['texte' => '« ' . mb_substr($d['libelle'], 0, 45) . ' » datée du '
                                     . date('d/m/Y', strtotime($d['date_operation'])),
                            'lien' => 'comptabilite.php'];
        }
    } catch (Throwable $e) {}
    $regles[] = ['nom' => 'Aucune opération datée dans le futur',
                 'pourquoi' => "Une opération future gonfle vos résultats du mois avec de l'argent qui n'est pas encore là.",
                 'gravite' => 'attention', 'anomalies' => $anomalies];

    $familles[] = ['titre' => 'Règles de tenue de comptes', 'icone' => '📐', 'regles' => $regles];

    /* ======================================================================
       FAMILLE 5 — COMPLÉTUDE
       ====================================================================== */
    $regles = [];

    $anomalies = [];
    try {
        $st = $pdo->query("SELECT fp.numero, fp.net_a_payer, e.nom
                           FROM fiches_paie fp LEFT JOIN employes e ON e.id = fp.employe_id
                           WHERE fp.statut='payee'
                             AND NOT EXISTS (SELECT 1 FROM transactions t
                                             WHERE t.type='depense' AND t.libelle = CONCAT('Salaire ', fp.numero))
                           LIMIT 25");
        foreach ($st->fetchAll() as $d) {
            $anomalies[] = ['texte' => 'Bulletin ' . $d['numero'] . ' (' . $d['nom'] . ', '
                                     . audit_mt($d['net_a_payer']) . ') payé sans dépense enregistrée',
                            'lien' => 'paie.php'];
        }
    } catch (Throwable $e) {}
    $regles[] = ['nom' => 'Chaque salaire versé figure en dépense',
                 'pourquoi' => "Sans cette écriture, votre bénéfice est surévalué du montant des salaires.",
                 'gravite' => 'critique', 'anomalies' => $anomalies];

    $anomalies = [];
    try {
        foreach (charges_du_mois($pdo, date('Y-m')) as $ch) {
            if (!$ch['deja']) {
                $anomalies[] = ['texte' => $ch['libelle'] . ' (' . audit_mt($ch['montant'])
                                         . ') pas encore enregistrée ce mois-ci',
                                'lien' => 'comptabilite.php#charges'];
            }
        }
    } catch (Throwable $e) {}
    $regles[] = ['nom' => 'Les charges du mois sont enregistrées',
                 'pourquoi' => "Une charge oubliée fait croire à un bénéfice qui n'existe pas.",
                 'gravite' => 'info', 'anomalies' => $anomalies];

    $anomalies = [];
    try {
        $st = $pdo->query("SELECT numero, date_echeance, DATEDIFF(CURDATE(), date_echeance) r
                           FROM factures WHERE type='facture' AND statut='envoyee'
                             AND date_echeance IS NOT NULL AND date_echeance < DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                           ORDER BY date_echeance LIMIT 25");
        foreach ($st->fetchAll() as $d) {
            $anomalies[] = ['texte' => 'Facture ' . $d['numero'] . ' impayée depuis ' . (int)$d['r'] . ' jours',
                            'lien' => 'relances.php'];
        }
    } catch (Throwable $e) {}
    $regles[] = ['nom' => 'Aucune créance de plus de deux mois',
                 'pourquoi' => "Passé deux mois, une créance devient difficile à recouvrer. Elle mérite une décision.",
                 'gravite' => 'attention', 'anomalies' => $anomalies];

    $familles[] = ['titre' => 'Complétude', 'icone' => '✅', 'regles' => $regles];

    /* ---- Synthèse ---- */
    $total = $ok = 0;
    $parGravite = ['critique' => 0, 'attention' => 0, 'info' => 0];
    foreach ($familles as &$fam) {
        $fam['ok'] = 0; $fam['total'] = 0; $fam['anomalies'] = 0;
        foreach ($fam['regles'] as &$r) {
            $fam['total']++; $total++;
            $n = count($r['anomalies']);
            $r['nb'] = $n;
            if ($n === 0) { $fam['ok']++; $ok++; }
            else { $fam['anomalies'] += $n; $parGravite[$r['gravite']] += $n; }
        }
        unset($r);
    }
    unset($fam);

    return ['familles' => $familles, 'total' => $total, 'ok' => $ok,
            'score' => $total > 0 ? round($ok / $total * 100) : 100,
            'gravite' => $parGravite];
}

function audit_mt($v): string {
    return number_format((float)$v, 0, ',', ' ');
}
