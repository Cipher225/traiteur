<?php
/* Récupère toutes les données d'un document pour PDF / impression */

/* Formate une quantité : 50,00 -> "50" ; 2,50 -> "2,5" ; 1200,00 -> "1 200" */
function qte_fmt($q): string {
    $s = number_format((float)$q, 2, ',', ' ');
    $s = preg_replace('/,00$/', '', $s);
    $s = preg_replace('/(,\d)0$/', '$1', $s);
    return $s;
}

/* Regroupe les lignes par catégorie, en conservant l'ordre de première apparition.
   Renvoie [ 'NomCatégorie' => [lignes...], '' => [lignes sans catégorie...] ] */
function lignes_par_categorie(array $lignes): array {
    $groupes = [];
    foreach ($lignes as $l) {
        $cat = trim((string)($l['categorie'] ?? ''));
        $groupes[$cat][] = $l;
    }
    return $groupes;
}

function get_facture(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT f.*, c.nom AS client_nom, c.entreprise, c.adresse AS client_adresse, c.telephone AS client_tel, c.email AS client_email, c.ncc AS client_ncc
                           FROM factures f LEFT JOIN clients c ON c.id=f.client_id WHERE f.id=?');
    $stmt->execute([$id]);
    $f = $stmt->fetch();
    if (!$f) return null;
    $lg = $pdo->prepare('SELECT * FROM facture_lignes WHERE facture_id=? ORDER BY id');
    $lg->execute([$id]);
    $f['lignes'] = $lg->fetchAll();
    $ht = 0;
    foreach ($f['lignes'] as $l) $ht += (float)$l['quantite'] * (float)$l['prix_unitaire'];
    $f['montant_ht'] = $ht;
    $f['base'] = max(0, $ht - (float)$f['remise']);
    $f['montant_tva'] = $f['base'] * (float)$f['tva_taux'] / 100;
    $f['montant_ttc'] = $f['base'] + $f['montant_tva'];
    return $f;
}

function get_fiche(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT fp.*, e.nom AS employe_nom, e.poste, e.matricule, e.categorie, e.departement, e.numero_cnps, e.date_embauche, e.telephone AS employe_tel, e.banque AS emp_banque, e.numero_compte AS emp_compte
                           FROM fiches_paie fp LEFT JOIN employes e ON e.id=fp.employe_id WHERE fp.id=?');
    $stmt->execute([$id]);
    $fp = $stmt->fetch();
    if (!$fp) return null;
    $fp['total_gains'] = (float)$fp['salaire_base'] + (float)($fp['sursalaire'] ?? 0) + (float)$fp['primes']
        + (float)($fp['prime_transport'] ?? 0) + (float)($fp['prime_anciennete'] ?? 0)
        + (float)($fp['indemnites'] ?? 0) + (float)$fp['heures_sup'];
    $fp['total_retenues'] = (float)$fp['cnps'] + (float)$fp['impots'] + (float)$fp['autres_deductions'] + (float)($fp['avances'] ?? 0);
    // Filet de sécurité : recalculer le net si absent
    if (empty($fp['net_a_payer']) || (float)$fp['net_a_payer'] == 0) {
        $fp['net_a_payer'] = $fp['total_gains'] - $fp['total_retenues'];
    }
    // Coordonnées bancaires : celles de la fiche sinon celles de l'employé
    $fp['banque_eff'] = $fp['banque'] ?: ($fp['emp_banque'] ?? '');
    $fp['compte_eff'] = $fp['numero_compte'] ?: ($fp['emp_compte'] ?? '');
    return $fp;
}

/* Conversion UTF-8 -> latin1 pour FPDF (core fonts) */
function pdf_txt(string $s): string {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s) ?: $s;
}

function get_recu(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT r.*, c.nom AS client_nom, c.entreprise, c.telephone AS client_tel, c.email AS client_email,
                           f.numero AS facture_num
                           FROM recus r LEFT JOIN clients c ON c.id=r.client_id LEFT JOIN factures f ON f.id=r.facture_id WHERE r.id=?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/* Montant en toutes lettres (français, entiers) pour les reçus */
/* Première lettre en majuscule, en tenant compte des accents :
   ucfirst() de PHP abîmerait un mot commençant par « é » ou « à ». */
function ucfirst_fr(string $t): string {
    $t = ltrim($t);
    if ($t === '') return $t;
    return mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
}

function montant_lettres(int $n): string {
    if ($n === 0) return 'zéro';
    $u = ['','un','deux','trois','quatre','cinq','six','sept','huit','neuf','dix','onze','douze','treize','quatorze','quinze','seize','dix-sept','dix-huit','dix-neuf'];
    $d = ['','','vingt','trente','quarante','cinquante','soixante','soixante','quatre-vingt','quatre-vingt'];
    $conv = function($x) use (&$conv, $u, $d) {
        if ($x < 20) return $u[$x];
        if ($x < 100) {
            $q = intdiv($x,10); $r = $x%10;
            if ($q==7||$q==9){ return $d[$q].'-'.$conv(10+$r); }
            $s = $d[$q];
            if ($r==1 && ($q==2||$q==3||$q==4||$q==5||$q==6)) return $s.'-et-un';
            if ($q==8 && $r==0) return $s.'s';
            return $s.($r? '-'.$u[$r] : '');
        }
        if ($x < 1000) {
            $c = intdiv($x,100); $r = $x%100;
            $s = ($c>1? $u[$c].' ':'').'cent'.($c>1 && $r==0?'s':'');
            return $s.($r? ' '.$conv($r):'');
        }
        if ($x < 1000000) {
            $m = intdiv($x,1000); $r = $x%1000;
            $s = ($m>1? $conv($m).' ':'').'mille';
            return $s.($r? ' '.$conv($r):'');
        }
        $mi = intdiv($x,1000000); $r = $x%1000000;
        $s = $conv($mi).' million'.($mi>1?'s':'');
        return $s.($r? ' '.$conv($r):'');
    };
    return $conv($n);
}
