<?php
/* ============================================================================
   AUDIT DU CIRCUIT FINANCIER
   Rejoue chaque chemin par lequel de l'argent entre ou sort, puis vérifie
   des invariants après chaque opération. Un invariant faux = un défaut.
   ============================================================================ */
putenv('DB_HOST=127.0.0.1');
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/mail.php';
require __DIR__ . '/../config/wave.php';
require __DIR__ . '/../admin/includes/documents.php';
require __DIR__ . '/../config/relances.php';

$S = get_settings($pdo);
$echecs = 0; $tests = 0;

function net($pdo) {
    foreach (['transactions','recus','paiements','facture_lignes','factures','fiches_paie','documents_auth'] as $t) {
        $pdo->exec("DELETE FROM $t");
    }
}
function fmt($v) { return number_format((float)$v, 0, ',', ' '); }

function verifier(string $quoi, $attendu, $obtenu) {
    global $echecs, $tests;
    $tests++;
    $ok = abs((float)$attendu - (float)$obtenu) < 1;
    if (!$ok) $echecs++;
    printf("  %-52s %14s %14s  %s\n", $quoi, fmt($attendu), fmt($obtenu), $ok ? 'ok' : '<<< ECART');
}
function verifierTexte(string $quoi, string $attendu, string $obtenu) {
    global $echecs, $tests;
    $tests++;
    $ok = $attendu === $obtenu;
    if (!$ok) $echecs++;
    printf("  %-52s %14s %14s  %s\n", $quoi, $attendu, $obtenu, $ok ? 'ok' : '<<< ECART');
}

/* Chaque action passe par un processus séparé qui exécute la VRAIE page de
   l'application : c'est le seul moyen de tester ce que fait réellement le
   logiciel, et non une reconstitution approximative. */
function page(string $fichier, array $post, array $get = []) {
    $script = '<?php putenv("DB_HOST=127.0.0.1"); session_start();'
            . '$_SESSION["admin_id"]=1; $_SESSION["admin_role"]="admin";'
            . '$_SESSION["admin_nom"]="Audit"; $_SESSION["admin_perms"]=[];'
            . '$_SESSION["csrf"]="t"; $_SERVER["REQUEST_METHOD"]="POST";'
            . '$_GET=' . var_export($get, true) . ';'
            . '$_POST=' . var_export(array_merge(['csrf' => 't'], $post), true) . ';'
            . 'chdir(__DIR__ . "/../admin"); ob_start(); require ' . var_export($fichier, true) . '; ob_end_clean();';
    $tmp = __DIR__ . '/_audit_tmp.php';
    file_put_contents($tmp, $script);
    exec('php ' . escapeshellarg($tmp) . ' 2>/dev/null');
    @unlink($tmp);
}
function bon(string $type, float $montant, array $extra = []) {
    page('recus.php', array_merge([
        'enregistrer' => '1', 'id' => '0', 'type' => $type,
        'montant' => (string)$montant, 'mode_paiement' => 'Especes',
        'motif' => 'Audit', 'date_paiement' => date('Y-m-d'), 'nb_jours' => '1',
    ], $extra), ['type' => $type]);
}
function statutFacture(int $id, string $statut) {
    page('factures.php', ['statut' => $statut, 'id_statut' => (string)$id]);
}
function supprimerBon(int $id, string $type) {
    page('recus.php', ['supprimer' => (string)$id, 'type' => $type], ['type' => $type]);
}
function supprimerFacture(int $id) { page('factures.php', ['supprimer' => (string)$id]); }
function statutBulletin(int $id, string $statut) {
    page('paie.php', ['statut' => $statut, 'id_statut' => (string)$id]);
}
function creerFacture(PDO $pdo, int $id, float $ht, float $remise = 0, int $tva = 0) {
    $pdo->prepare("INSERT INTO factures (id,numero,type,client_id,date_emission,date_echeance,statut,remise,tva_taux,tva_applicable,activite)
                   VALUES (?,?,'facture',1,CURDATE(),CURDATE(),'envoyee',?,?,?,?)")
        ->execute([$id, 'FAC-' . $id, $remise, $tva, $tva > 0 ? 1 : 0, 'Activite ' . $id]);
    $pdo->prepare("INSERT INTO facture_lignes (facture_id,designation,quantite,prix_unitaire) VALUES (?,?,1,?)")
        ->execute([$id, 'Prestation', $ht]);
}
function encaisse(PDO $pdo) { return (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM transactions WHERE type='entree'")->fetchColumn(); }
function depense(PDO $pdo) { return (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM transactions WHERE type='depense'")->fetchColumn(); }
function impayes(PDO $pdo) { $t = 0; foreach (factures_impayees($pdo, false) as $f) $t += $f['solde']; return $t; }

echo "\n";
echo "════════════════════════════════════════════════════════════════════════════════════════════\n";
echo "  AUDIT DU CIRCUIT FINANCIER" . str_repeat(' ', 32) . "ATTENDU        OBTENU\n";
echo "════════════════════════════════════════════════════════════════════════════════════════════\n";

/* ---------------------------------------------------------------- 1 */
echo "\n1. CALCUL DU MONTANT D'UNE FACTURE\n";
net($pdo);
creerFacture($pdo, 1, 3000000, 200000, 18);      // (3M - 200k) * 1,18
verifier("TTC avec remise en montant et TVA 18 %", 3304000, facture_ttc($pdo, 1));
creerFacture($pdo, 2, 3000000, 0, 0);
verifier("TTC sans TVA", 3000000, facture_ttc($pdo, 2));
verifier("le document affiche le meme montant", 3304000, get_facture($pdo, 1)['montant_ttc']);

/* ---------------------------------------------------------------- 2 */
echo "\n2. ACOMPTE ENCAISSE AU COMPTOIR\n";
net($pdo); creerFacture($pdo, 1, 3000000);
bon('entree', 2000000, ['client_id' => '1', 'facture_id' => '1']);
verifier("ecriture comptable creee", 2000000, encaisse($pdo));
verifier("deduit du solde impaye", 2000000, paiements_deja_regles($pdo, 1));
verifier("reste a encaisser", 1000000, impayes($pdo));

/* ---------------------------------------------------------------- 3 */
echo "\n3. FACTURE MARQUEE PAYEE APRES ACOMPTE\n";
statutFacture(1, 'payee');
verifier("total encaisse = TTC, sans doublon", 3000000, encaisse($pdo));
verifier("plus rien a recouvrer", 0, impayes($pdo));
statutFacture(1, 'envoyee');
verifier("retour en envoyee : le solde disparait", 2000000, encaisse($pdo));

/* ---------------------------------------------------------------- 4 */
echo "\n4. PAIEMENT EN LIGNE\n";
net($pdo); creerFacture($pdo, 1, 3000000);
$pdo->prepare("INSERT INTO paiements (reference,facture_id,client_id,montant,devise,session_id,statut) VALUES (?,?,?,?,?,?,?)")
    ->execute(['P1', 1, 1, 1200000, 'XOF', 's1', 'en_attente']);
paiement_finaliser($pdo, 'P1', $S, []);
verifier("un seul encaissement enregistre", 1200000, encaisse($pdo));
verifier("compte comme regle", 1200000, paiements_deja_regles($pdo, 1));
verifierTexte("facture non soldee", 'envoyee', (string)$pdo->query("SELECT statut FROM factures WHERE id=1")->fetchColumn());

/* ---------------------------------------------------------------- 5 */
echo "\n5. ACOMPTE COMPTOIR + PAIEMENT EN LIGNE\n";
bon('entree', 800000, ['client_id' => '1', 'facture_id' => '1']);
verifier("cumul des deux canaux", 2000000, paiements_deja_regles($pdo, 1));
verifier("comptabilite alignee", 2000000, encaisse($pdo));
$pdo->prepare("INSERT INTO paiements (reference,facture_id,client_id,montant,devise,session_id,statut) VALUES (?,?,?,?,?,?,?)")
    ->execute(['P2', 1, 1, 1000000, 'XOF', 's2', 'en_attente']);
paiement_finaliser($pdo, 'P2', $S, []);
verifier("solde couvert", 3000000, paiements_deja_regles($pdo, 1));
verifierTexte("facture soldee automatiquement", 'payee', (string)$pdo->query("SELECT statut FROM factures WHERE id=1")->fetchColumn());
verifier("aucun doublon en comptabilite", 3000000, encaisse($pdo));

/* ---------------------------------------------------------------- 6 */
echo "\n6. SUPPRESSION D'UN ACOMPTE\n";
net($pdo); creerFacture($pdo, 1, 3000000);
bon('entree', 1000000, ['client_id' => '1', 'facture_id' => '1']);
$rid = (int)$pdo->query("SELECT id FROM recus LIMIT 1")->fetchColumn();
statutFacture(1, 'payee');
verifier("avant suppression", 3000000, encaisse($pdo));
supprimerBon($rid, 'entree');
verifier("le solde se recalcule tout seul", 3000000, encaisse($pdo));
verifier("bons restants", 0, (int)$pdo->query("SELECT COUNT(*) FROM recus")->fetchColumn());

/* ---------------------------------------------------------------- 7 */
echo "\n7. DEPENSES ET RENTABILITE\n";
net($pdo); creerFacture($pdo, 1, 3000000);
statutFacture(1, 'payee');
bon('sortie', 1200000, ['categorie' => 'Approvisionnement', 'facture_id' => '1']);
bon('sortie', 400000, ['categorie' => 'Loyer', 'facture_id' => '1']);   // charge de structure
verifier("total des depenses", 1600000, depense($pdo));
$r = rentabilite_activite($pdo, 1);
verifier("depenses imputees a l'activite", 1200000, $r['depenses']);
verifier("le loyer reste en charge generale", 400000,
    (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM transactions WHERE type='depense' AND facture_id IS NULL")->fetchColumn());
verifier("marge de l'activite", 1800000, $r['marge']);
verifier("tresorerie", 1400000, encaisse($pdo) - depense($pdo));

/* ---------------------------------------------------------------- 8 */
echo "\n8. BULLETIN DE PAIE\n";
$pdo->exec("DELETE FROM fiches_paie");
$pdo->exec("INSERT IGNORE INTO employes (id,nom,poste,matricule,salaire_base) VALUES (9,'Audit','Poste','A9',200000)");
$pdo->prepare("INSERT INTO fiches_paie (numero,employe_id,periode,jours_travailles,salaire_base,cnps,impots,net_a_payer,statut,mode_paiement)
               VALUES ('BP-AUDIT',9,?,26,200000,12600,9000,178400,'brouillon','Virement')")->execute([date('Y-m')]);
$bid = (int)$pdo->query("SELECT id FROM fiches_paie LIMIT 1")->fetchColumn();
$avant = depense($pdo);
statutBulletin($bid, 'payee');
verifier("salaire verse devient une depense", $avant + 178400, depense($pdo));
statutBulletin($bid, 'brouillon');
verifier("retour en brouillon : la depense disparait", $avant, depense($pdo));

/* ---------------------------------------------------------------- 9 */
echo "\n9. SUPPRESSION D'UNE FACTURE PAYEE\n";
net($pdo); creerFacture($pdo, 1, 2000000);
statutFacture(1, 'payee');
bon('sortie', 500000, ['categorie' => 'Approvisionnement', 'facture_id' => '1']);
verifier("avant : encaisse", 2000000, encaisse($pdo));
supprimerFacture(1);
verifier("l'encaissement disparait avec la facture", 0, encaisse($pdo));
verifier("la depense reste : l'argent est bien sorti", 500000, depense($pdo));

/* ---------------------------------------------------------------- 10 */
echo "\n10. FACTURE ANNULEE\n";
net($pdo); creerFacture($pdo, 1, 1500000);
statutFacture(1, 'payee');
verifier("payee", 1500000, encaisse($pdo));
statutFacture(1, 'annulee');
verifier("annulee : l'encaissement est retire", 0, encaisse($pdo));
verifier("plus comptee dans les impayes", 0, impayes($pdo));

/* ---------------------------------------------------------------- 11 */
echo "\n11. CAS LIMITES\n";
net($pdo); creerFacture($pdo, 1, 1000000);
bon('entree', 1500000, ['client_id' => '1', 'facture_id' => '1']);   // le client verse trop
statutFacture(1, 'payee');
verifier("sur-paiement : pas de solde negatif", 1500000, encaisse($pdo));
verifier("aucun impaye", 0, impayes($pdo));

net($pdo); creerFacture($pdo, 1, 0);
statutFacture(1, 'payee');
verifier("facture a zero : aucune ecriture", 0, encaisse($pdo));

/* ---------------------------------------------------------------- 12 */
echo "\n12. UNE PROFORMA N'EST PAS UN REVENU\n";
net($pdo);
$pdo->exec("INSERT INTO factures (id,numero,type,client_id,date_emission,date_echeance,statut,remise,tva_taux,tva_applicable)
            VALUES (7,'PRO-7','proforma',1,CURDATE(),CURDATE(),'envoyee',0,0,0)");
$pdo->exec("INSERT INTO facture_lignes (facture_id,designation,quantite,prix_unitaire) VALUES (7,'Devis',1,900000)");
verifier("proforma exclue des impayes", 0, impayes($pdo));
verifier("proforma exclue du chiffre d'affaires", 0, encaisse($pdo));

/* ---------------------------------------------------------------- 13 */
echo "\n13. MODIFICATION D'UN ACOMPTE DEJA ENREGISTRE\n";
net($pdo); creerFacture($pdo, 1, 3000000);
bon('entree', 1000000, ['client_id' => '1', 'facture_id' => '1']);
$rid = (int)$pdo->query("SELECT id FROM recus LIMIT 1")->fetchColumn();
statutFacture(1, 'payee');
verifier("avant modification", 3000000, encaisse($pdo));
page('recus.php', ['enregistrer' => '1', 'id' => (string)$rid, 'type' => 'entree',
                   'montant' => '2500000', 'mode_paiement' => 'Especes', 'motif' => 'Acompte revu',
                   'client_id' => '1', 'facture_id' => '1',
                   'date_paiement' => date('Y-m-d'), 'nb_jours' => '1'], ['type' => 'entree']);
verifier("acompte porte a 2 500 000 : total inchange", 3000000, encaisse($pdo));
verifier("le solde s'est ajuste", 500000,
    (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM transactions WHERE categorie='Solde'")->fetchColumn());

/* ---------------------------------------------------------------- 14 */
echo "\n14. FACTURE MODIFIEE APRES AVOIR ETE PAYEE\n";
net($pdo); creerFacture($pdo, 1, 2000000);
statutFacture(1, 'payee');
verifier("encaisse initial", 2000000, encaisse($pdo));
/* Le montant de la facture change : la comptabilite doit suivre. */
/* On modifie la facture par la vraie page, comme le ferait l'utilisateur. */
page('factures.php', ['enregistrer' => '1', 'id' => '1', 'doc_type' => 'facture',
      'client_id' => '1', 'date_emission' => date('Y-m-d'), 'date_echeance' => date('Y-m-d'),
      'tva_taux' => '0', 'remise' => '0', 'nb_jours' => '1',
      'designation' => ['Prestation'], 'details' => [''], 'quantite' => ['1'],
      'prix_unitaire' => ['2500000'], 'unite' => ['']]);
verifier("montant porte a 2 500 000 : la compta suit", 2500000, encaisse($pdo));

/* ---------------------------------------------------------------- 15 */
echo "\n15. DEUX FACTURES EN PARALLELE\n";
net($pdo); creerFacture($pdo, 1, 1000000); creerFacture($pdo, 2, 2000000);
bon('entree', 400000, ['client_id' => '1', 'facture_id' => '1']);
bon('entree', 700000, ['client_id' => '1', 'facture_id' => '2']);
verifier("regle sur la facture 1", 400000, paiements_deja_regles($pdo, 1));
verifier("regle sur la facture 2", 700000, paiements_deja_regles($pdo, 2));
verifier("impayes cumules", 1900000, impayes($pdo));
statutFacture(1, 'payee');
verifier("solder la facture 1 n'affecte pas la 2", 700000, paiements_deja_regles($pdo, 2));
verifier("impayes restants", 1300000, impayes($pdo));

echo "\n════════════════════════════════════════════════════════════════════════════════════════════\n";
printf("  %d contrôles — %s\n", $tests, $echecs === 0 ? "AUCUN ECART" : "$echecs ECART(S) A CORRIGER");
echo "════════════════════════════════════════════════════════════════════════════════════════════\n\n";
