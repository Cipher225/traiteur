<?php
/* Ce fichier produit TOUS les documents de l'application. L'espace client l'utilise
   aussi (via espace-client/doc.php) : il n'existe donc qu'un seul modèle de mise en
   page, et toute correction s'applique partout. */
if (!defined('DOC_ESPACE_CLIENT')) {
    require __DIR__ . '/includes/auth.php';
}
require_once __DIR__ . '/includes/documents.php';
require_once __DIR__ . '/includes/doc_html.php';
require_once __DIR__ . '/../config/docauth.php';

$devise = $settings['devise'] ?? 'FCFA';
$type = $_GET['type'] ?? '';
$id   = (int)($_GET['id'] ?? 0);
$AUTH = isset($_GET['auth']) && (defined('DOC_ESPACE_CLIENT') || is_admin());
// Authentification : l'administrateur, et le client sur SES propres documents
// (espace-client/doc.php a déjà vérifié qu'il en est bien le destinataire).

function nf($n){ return number_format((float)$n, 0, ',', ' '); }
function moisfr(string $p): string {
    $m=[1=>'janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
    if (!preg_match('/^(\d{4})-(\d{2})/', $p, $x)) return $p;
    return ucfirst($m[(int)$x[2]] ?? '') . ' ' . $x[1];
}

$doc = null;
if ($type === 'facture' || $type === 'proforma') $doc = get_facture($pdo, $id);
elseif ($type === 'fiche') $doc = get_fiche($pdo, $id);
elseif ($type === 'recu')  $doc = get_recu($pdo, $id);
elseif ($type === 'livraison') $doc = get_facture($pdo, $id);
if (!$doc) { http_response_code(404); exit('Document introuvable.'); }
$estPro = ($type === 'facture' || $type === 'proforma') && (($doc['type'] ?? 'facture') === 'proforma');
$estLivraison = ($type === 'livraison');

/* Titre, bloc d'en-tête et parties selon le type */
if ($type === 'fiche') {
    $titre  = 'BULLETIN DE PAIE';
    $entete = [['N° Bulletin', $doc['numero']], ['Période', moisfr((string)$doc['periode'])],
               ['Établi le', date('d/m/Y', strtotime($doc['created_at'] ?: 'now'))]];
    $t1 = 'Salarié'; $l1 = [
        ['Nom & prénoms', $doc['employe_nom'] ?? ''], ['Matricule', $doc['matricule'] ?? ''],
        ['Emploi', $doc['poste'] ?? ''], ['Département', $doc['departement'] ?? ''],
        ['Catégorie', $doc['categorie'] ?? ''], ['N° CNPS', $doc['numero_cnps'] ?? '']];
    $t2 = 'Informations'; $l2 = [
        ['Période', moisfr((string)$doc['periode'])],
        ["Date d'embauche", !empty($doc['date_embauche']) ? date('d/m/Y', strtotime($doc['date_embauche'])) : ''],
        ['Mode de paiement', $doc['mode_paiement'] ?? ''],
        ['Banque', $doc['banque_eff'] ?? ($doc['banque'] ?? '')],
        ['N° de compte', $doc['compte_eff'] ?? ($doc['numero_compte'] ?? '')]];
} elseif ($type === 'recu') {
    $estEntree = ($doc['type'] ?? 'sortie') === 'entree';
    // Un encaissement issu d'un paiement en ligne est présenté comme un reçu de paiement :
    // c'est le document que reçoit le client, la formulation doit lui parler.
    $estPaiement = false;
    try {
        $vp = $pdo->prepare('SELECT COUNT(*) FROM paiements WHERE recu_id=? AND statut=?');
        $vp->execute([(int)$doc['id'], 'paye']);
        $estPaiement = (bool)$vp->fetchColumn();
    } catch (Throwable $e) { $estPaiement = false; }

    $titre  = $estPaiement ? 'REÇU DE PAIEMENT' : ($estEntree ? 'ENTRÉE' : 'SORTIE');
    $entete = [['N° ' . ($estPaiement ? 'Reçu' : ($estEntree ? 'Entrée' : 'Sortie')), $doc['numero']],
               ['Date', date('d/m/Y', strtotime($doc['date_paiement'] ?: $doc['created_at']))],
               ['Facture', $doc['facture_num'] ?: '—']];
    $t1 = ($estPaiement || !$estEntree) ? 'Client' : 'Fournisseur / Émetteur'; $l1 = [
        ['Nom / Société', $doc['client_nom'] ?? ''], ['Entreprise', $doc['entreprise'] ?? ''],
        ['Téléphone', $doc['client_tel'] ?? ''], ['Email', $doc['client_email'] ?? '']];
    $t2 = 'Informations'; $l2 = [['Référence', $doc['numero']], ['Mode de règlement', $doc['mode_paiement'] ?? '']];
    if (trim((string)($doc['activite'] ?? '')) !== '') $l2[] = ['Activité', $doc['activite']];
    if (!empty($doc['date_evenement'])) $l2[] = periode_evenement($doc);
    if (trim((string)($doc['lieu'] ?? '')) !== '') $l2[] = ['Lieu', $doc['lieu']];
    $l2[] = ['Facture liée', $doc['facture_num'] ?: '—'];
    $l2[] = ['Devise', $devise];
} elseif ($type === 'livraison') {
    $titre  = 'BON DE LIVRAISON';
    $entete = [];   // les references figurent dans le bandeau dedie, sous l'en-tete
    /* L'adresse est conservée : elle sert au livreur. Le reste va à l'essentiel,
       les montants et le détail figurant déjà sur la proforma et la facture. */
    $t1 = 'Livré à'; $l1 = [['Nom / Société', $doc['client_nom'] ?? '']];
    if (trim((string)($doc['client_adresse'] ?? '')) !== '') $l1[] = ['Adresse', $doc['client_adresse']];
    if (trim((string)($doc['client_tel'] ?? '')) !== '')     $l1[] = ['Téléphone', $doc['client_tel']];

    $t2 = 'Livraison'; $l2 = [];
    if (trim((string)($doc['activite'] ?? '')) !== '') $l2[] = ['Activité', $doc['activite']];
    if (!empty($doc['date_evenement']) && (int)($doc['nb_jours'] ?? 1) > 1) $l2[] = periode_evenement($doc);
    if (trim((string)($doc['lieu'] ?? '')) !== '') $l2[] = ['Lieu de livraison', $doc['lieu']];
    if (!$l2) $l2[] = ['Livreur', $settings['nom_entreprise'] ?? ''];
} else {
    $titre  = $estPro ? 'PROFORMA' : 'FACTURE';
    $entete = [['N° ' . ($estPro ? 'Proforma' : 'Facture'), $doc['numero']],
               ['Date', date('d/m/Y', strtotime($doc['date_emission']))],
               ['Échéance', $doc['date_echeance'] ? date('d/m/Y', strtotime($doc['date_echeance'])) : '—']];
    // Si le client est enregistre avec une entreprise, c'est la raison sociale qui figure
    // sur le document ; sinon, le nom de la personne.
    $nomAffiche = trim((string)($doc['entreprise'] ?? '')) !== ''
                ? $doc['entreprise'] : ($doc['client_nom'] ?? '');
    /* Bloc client resserré : l'adresse et le NCC figurent dans le dossier client,
       ils alourdissaient le document sans être utiles à la lecture. Le numéro de
       référence n'est plus répété : il est déjà dans le bandeau d'en-tête. */
    $t1 = 'Client'; $l1 = [['Nom / Société', $nomAffiche]];
    if (trim((string)($doc['client_tel'] ?? '')) !== '')   $l1[] = ['Téléphone', $doc['client_tel']];
    if (trim((string)($doc['client_email'] ?? '')) !== '') $l1[] = ['Email', $doc['client_email']];
    $l1[] = ['N° Client', $doc['client_id'] ? sprintf('CL-%04d', (int)$doc['client_id']) : ''];
    $t2 = 'Informations'; $l2 = [];
    if (trim((string)($doc['mode_paiement'] ?? '')) !== '') $l2[] = ['Mode de paiement', $doc['mode_paiement']];
    if (trim((string)($doc['activite'] ?? '')) !== '') $l2[] = ['Activité', $doc['activite']];
    if (!empty($doc['date_evenement'])) $l2[] = periode_evenement($doc);
    if (trim((string)($doc['lieu'] ?? '')) !== '') $l2[] = ['Lieu', $doc['lieu']];
    if (!$l2) { $l2[] = ['Échéance', $doc['date_echeance'] ? date('d/m/Y', strtotime($doc['date_echeance'])) : '—']; }
}

/* Une prestation peut se dérouler sur plusieurs jours : on affiche alors la
   période complète plutôt qu'une date isolée. */
function periode_evenement(array $doc): array {
    $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
             'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $deb = strtotime((string)$doc['date_evenement']);
    $nbj = max(1, (int)($doc['nb_jours'] ?? 1));

    if ($nbj <= 1) {
        return ["Date de l'événement",
                date('j', $deb) . ' ' . $mois[(int)date('n', $deb)] . ' ' . date('Y', $deb)];
    }

    /* Formulation compacte : on ne répète que ce qui change. Le mois et l'année
       ne sont écrits qu'une fois s'ils sont communs aux deux dates, ce qui évite
       une ligne trop longue qui déborderait de la carte.
         même mois  → « Du 2 au 5 septembre 2026 »
         même année → « Du 28 septembre au 2 octobre 2026 »
         sinon      → « Du 30 décembre 2026 au 2 janvier 2027 » */
    $fin = strtotime('+' . ($nbj - 1) . ' day', $deb);
    $jd = date('j', $deb); $jf = date('j', $fin);
    $md = (int)date('n', $deb); $mf = (int)date('n', $fin);
    $ad = date('Y', $deb); $af = date('Y', $fin);

    if ($ad === $af && $md === $mf) {
        $txt = 'Du ' . $jd . ' au ' . $jf . ' ' . $mois[$mf] . ' ' . $af;
    } elseif ($ad === $af) {
        $txt = 'Du ' . $jd . ' ' . $mois[$md] . ' au ' . $jf . ' ' . $mois[$mf] . ' ' . $af;
    } else {
        $txt = 'Du ' . $jd . ' ' . $mois[$md] . ' ' . $ad . ' au ' . $jf . ' ' . $mois[$mf] . ' ' . $af;
    }
    return ["Dates de l'événement", $txt];
}

/* Descriptions du menu : chaque élément détaillé d'une ligne peut porter sa
   description entre parenthèses, pour que le client sache exactement ce qu'il
   commande sans avoir à demander. Chargée une seule fois, en une requête. */
$descriptionsPlats = [];
try {
    foreach ($pdo->query("SELECT nom, description FROM plats WHERE description <> ''")->fetchAll() as $p) {
        $cle = mb_strtolower(trim((string)$p['nom']));
        if ($cle !== '') $descriptionsPlats[$cle] = trim((string)$p['description']);
    }
} catch (Throwable $e) { $descriptionsPlats = []; }

/* Renvoie « Nom (description) » si une description existe pour cet élément. */
function element_avec_description(string $item, array $descriptions): string {
    $d = $descriptions[mb_strtolower(trim($item))] ?? '';
    if ($d === '') return $item;
    if (mb_strlen($d) > 90) $d = mb_substr($d, 0, 88) . '…';
    return $item . ' (' . $d . ')';
}

/* Authentification */
$qrUri = $checksum = $token = null;
if ($AUTH) {
    $parts = $type === 'fiche'
        ? [$doc['numero'], (string)$doc['periode'], (string)(int)($doc['net_a_payer'] ?? 0)]
        : ($type === 'recu'
            ? [$doc['numero'], (string)($doc['date_paiement'] ?? ''), (string)(int)$doc['montant']]
            : [$doc['numero'], $doc['date_emission'], (string)(int)$doc['montant_ttc']]);
    $checksum = doc_checksum($parts);
    $token = doc_token($pdo, $estPro ? 'proforma' : $type, (int)$doc['id'], $doc['numero'], $checksum);
    $qrUri = qr_datauri(doc_verify_url($settings, $token));
}
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8">
<title><?= e($titre . ' ' . $doc['numero']) ?></title>
<style><?= doc_html_styles() ?>
</style></head><body>

<div class="barre">
  <button class="btn-p" onclick="imprimerDoc()">🖨️ Imprimer / Enregistrer en PDF</button>
  <a class="btn-g" href="javascript:history.back()">← Retour</a>
</div>

<div class="sheet">
  <table class="cadre-page"><tfoot><tr><td><div class="pied-reserve"></div></td></tr></tfoot><tbody><tr><td>
  <div class="flex-fill">
  <?= doc_html_header($settings, $titre, $entete, '..') ?>
  <?php if ($estLivraison): ?>
  <!-- ===== BON DE LIVRAISON : bandeau des references ===== -->
  <div class="bl-strip">
    <div class="bl-card"><div class="k">N° Bon de livraison</div><div class="v">BL-<?= e($doc['numero']) ?></div></div>
    <div class="bl-card"><div class="k">Date de livraison</div><div class="v"><?= e(date('d/m/Y', strtotime((string)(!empty($doc['date_evenement']) ? $doc['date_evenement'] : $doc['date_emission'])))) ?></div></div>
    <div class="bl-card"><div class="k">Réf. facture</div><div class="v"><?= e($doc['numero']) ?></div></div>
  </div>
  <!-- ===== Destinataire et details de la livraison ===== -->
  <div class="bl-duo">
    <div class="bl-box">
      <div class="hd">Livré à</div>
      <div class="bd">
        <?php
        $nomCli = trim((string)($doc['entreprise'] ?? '')) !== '' ? $doc['entreprise'] : ($doc['client_nom'] ?? '');
        /* Le livreur a besoin de savoir chez qui aller et comment le joindre.
           Le reste (email, n° client, montants) figure sur la facture. */
        $infosCli = [['Nom / Société', $nomCli], ['Adresse', $doc['client_adresse'] ?? ''],
                     ['Téléphone', $doc['client_tel'] ?? '']];
        foreach ($infosCli as [$lb, $vl]): if (trim((string)$vl) === '') continue; ?>
        <div class="rw"><span class="lb"><?= e($lb) ?></span><span class="vl"><?= e($vl) ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="bl-box">
      <div class="hd">Détails de la livraison</div>
      <div class="bd">
        <?php
        /* Même formulation que sur la facture et la proforma : le client retrouve
           la période au même endroit et sous la même forme sur les trois documents. */
        $infosLiv = [['Activité', $doc['activite'] ?? '']];
        if (!empty($doc['date_evenement'])) {
            $pe = periode_evenement($doc);
            $infosLiv[] = $pe;
        }
        $infosLiv[] = ['Lieu de livraison', $doc['lieu'] ?? ''];
        if (trim((string)($doc['mode_paiement'] ?? '')) !== '')
            $infosLiv[] = ['Mode de paiement', $doc['mode_paiement']];
        $infosLiv[] = ['Livreur', $settings['nom_entreprise'] ?? ''];
        foreach ($infosLiv as [$lb, $vl]): if (trim((string)$vl) === '') continue; ?>
        <div class="rw"><span class="lb"><?= e($lb) ?></span><span class="vl"><?= e($vl) ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php else: ?>
  <?= doc_html_parties($t1, $l1, $t2, $l2) ?>
  <?php endif; ?>

  <div style="padding:0 40px" class="tbl-scroll<?= $type==='fiche' ? ' fiche-full' : '' ?>">
  <?php if ($type === 'fiche'): ?>
    <?php
    /* Présentation normalisée d'un bulletin de paie : chaque rubrique porte son
       code, sa base de calcul et son taux, puis la colonne des gains ou celle
       des retenues. C'est la lecture attendue par un salarié, une banque ou
       l'inspection du travail. */
    $base = (float)$doc['salaire_base'];
    $gains = [
        ['100', 'Salaire de base',          $base,  null, (float)$doc['salaire_base']],
        ['110', 'Sursalaire',               null,   null, (float)($doc['sursalaire'] ?? 0)],
        ['120', 'Prime de transport',       null,   null, (float)($doc['prime_transport'] ?? 0)],
        ['130', "Prime d'ancienneté",       $base,  null, (float)($doc['prime_anciennete'] ?? 0)],
        ['140', 'Autres primes',            null,   null, (float)($doc['primes'] ?? 0)],
        ['150', 'Indemnités',               null,   null, (float)($doc['indemnites'] ?? 0)],
        ['160', 'Heures supplémentaires',   null,   null, (float)($doc['heures_sup'] ?? 0)],
    ];
    $brut = 0.0; foreach ($gains as $g) $brut += $g[4];

    $rets = [
        ['300', 'CNPS — part salariale',    $brut,  6.3,  (float)($doc['cnps'] ?? 0)],
        ['310', 'Impôt sur salaire (ITS)',  $brut,  null, (float)($doc['impots'] ?? 0)],
        ['320', 'Avances / acomptes',       null,   null, (float)($doc['avances'] ?? 0)],
        ['330', 'Autres retenues',          null,   null, (float)($doc['autres_deductions'] ?? 0)],
    ];
    $totalRet = 0.0; foreach ($rets as $r) $totalRet += $r[4];
    $tx = fn($v) => $v === null ? '' : rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',') . ' %';
    ?>
    <table class="paie">
      <thead><tr>
        <th style="width:46px">Code</th>
        <th class="l">Libellé</th>
        <th class="r" style="width:92px">Base</th>
        <th class="r" style="width:62px">Taux</th>
        <th class="r" style="width:104px">Gains</th>
        <th class="r" style="width:104px">Retenues</th>
      </tr></thead>
      <tbody>
        <?php foreach ($gains as $g): if ($g[4] == 0) continue; ?>
        <tr>
          <td class="c code"><?= e($g[0]) ?></td>
          <td><span class="des"><?= e($g[1]) ?></span></td>
          <td class="r"><?= $g[2] !== null ? nf($g[2]) : '' ?></td>
          <td class="r"><?= e($tx($g[3])) ?></td>
          <td class="r fort"><?= nf($g[4]) ?></td>
          <td class="r"></td>
        </tr>
        <?php endforeach; ?>
        <tr class="sous-tot">
          <td></td><td><span class="des">SALAIRE BRUT</span></td><td></td><td></td>
          <td class="r"><?= nf($brut) ?></td><td class="r"></td>
        </tr>
        <?php foreach ($rets as $r): if ($r[4] == 0) continue; ?>
        <tr>
          <td class="c code"><?= e($r[0]) ?></td>
          <td><span class="des"><?= e($r[1]) ?></span></td>
          <td class="r"><?= $r[2] !== null ? nf($r[2]) : '' ?></td>
          <td class="r"><?= e($tx($r[3])) ?></td>
          <td class="r"></td>
          <td class="r fort"><?= nf($r[4]) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="sous-tot">
          <td></td><td><span class="des">TOTAL DES RETENUES</span></td><td></td><td></td>
          <td class="r"></td><td class="r"><?= nf($totalRet) ?></td>
        </tr>
      </tbody>
    </table>

    <?php
    /* Charges patronales et cumuls : un bulletin professionnel les fait figurer,
       même s'ils n'entrent pas dans le net versé au salarié. */
    $patronale = (float)($doc['cnps_employeur'] ?? 0);
    $jours = (int)($doc['jours_travailles'] ?? 0);
    ?>
    <div class="paie-bas">
      <div class="pb-box">
        <div class="pb-hd">Charges patronales</div>
        <div class="pb-rw"><span>CNPS — part employeur</span><b><?= nf($patronale) ?></b></div>
        <div class="pb-rw"><span>Coût total employeur</span><b><?= nf($brut + $patronale) ?></b></div>
      </div>
      <div class="pb-box">
        <div class="pb-hd">Période</div>
        <div class="pb-rw"><span>Jours travaillés</span><b><?= $jours > 0 ? $jours : '—' ?></b></div>
        <div class="pb-rw"><span>Payé le</span><b><?= !empty($doc['date_paiement']) ? date('d/m/Y', strtotime($doc['date_paiement'])) : '—' ?></b></div>
      </div>
    </div>
  <?php elseif ($type === 'recu'): ?>
    <table>
      <thead><tr><th style="width:60px">N°</th><th class="l">Désignation</th><th class="r">Montant (<?= e($devise) ?>)</th></tr></thead>
      <tbody><tr><td class="c">1</td><td><span class="des"><?= e(trim((string)$doc['motif']) !== '' ? $doc['motif'] : ((($doc['type'] ?? 'sortie') === 'entree') ? 'Montant encaissé' : 'Montant décaissé')) ?></span></td><td class="r"><?= nf($doc['montant']) ?></td></tr></tbody>
    </table>
  <?php elseif ($estLivraison): ?>
    <table>
      <thead><tr><th style="width:52px">N°</th><th class="l">Désignation</th><th style="width:96px">Qté livrée</th></tr></thead>
      <tbody>
        <?php /* Le bon de livraison ne liste que les catégories et leurs quantités.
                 Le détail des prestations figure sur la proforma et la facture :
                 le répéter ici allongerait le document sans rien apporter au livreur. */
        $n=0; foreach ($doc['lignes'] as $l): $n++; ?>
        <tr>
          <td class="c"><?= $n ?></td>
          <td><span class="des"><?= e($l['designation']) ?></span>
          </td>
          <td class="c bl-qte"><?= qte_fmt($l['quantite']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <table>
      <thead><tr><th style="width:56px">N°</th><th class="l">Désignation</th><th style="width:70px">Qté</th><th class="r">Prix unit. (<?= e($devise) ?>)</th><th class="r">Montant (<?= e($devise) ?>)</th></tr></thead>
      <tbody>
        <?php $n=0; foreach ($doc['lignes'] as $l): $n++; $t=(float)$l['quantite']*(float)$l['prix_unitaire'];
          $incl = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)($l['details'] ?? ''))))); ?>
        <tr>
          <td class="c"><?= $n ?></td>
          <td><span class="des"><?= e($l['designation']) ?></span>
            <?php if ($incl): ?><ul class="incl"><?php foreach ($incl as $it): ?><li><?= e(element_avec_description($it, $descriptionsPlats)) ?></li><?php endforeach; ?></ul><?php endif; ?>
          </td>
          <td class="c"><?= qte_fmt($l['quantite']) ?></td>
          <td class="r"><?= nf($l['prix_unitaire']) ?></td>
          <td class="r"><?= nf($t) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  </div>

  <?php if ($estLivraison): ?>
  <div class="bl-recap">
    <div class="lab"><?= e(trim((string)($settings['mention_livraison'] ?? '')) !== '' ? $settings['mention_livraison'] : 'Marchandises livrées et reçues en bon état, conformément au bon de commande.') ?></div>
    <div class="num"><b><?= count($doc['lignes']) ?></b><span>Articles</span></div>
  </div>
  <?php else: ?>
  <div class="bottom">
    <div class="lettres">
      <?php
      if ($type === 'fiche') { $intro = 'Arrêté le présent bulletin à la somme nette de :'; $mt = (int)($doc['net_a_payer'] ?? 0); }
      elseif ($type === 'recu') {
          $intro = !empty($estPaiement)
                 ? 'Reçu du client la somme de :'
                 : 'Arrêté le présent ' . (($doc['type'] ?? 'sortie') === 'entree' ? "bon d'entrée" : 'bon de sortie') . ' à la somme de :';
          $mt = (int)$doc['montant'];
      }
      else { $intro = $estPro ? 'Arrêtée la présente proforma à la somme de :' : 'Arrêtée la présente facture à la somme de :'; $mt = (int)$doc['montant_ttc']; }
      ?>
      <p><?= e($intro) ?></p>
      <div class="dots"><?= e(ucfirst_fr(montant_lettres($mt)) . ' ' . $devise) ?></div>
    </div>
    <div class="tot">
      <?php if ($type === 'fiche'): ?>
        <div class="l"><span>Salaire brut</span><strong><?= nf($doc['total_gains'] ?? 0) ?> <?= e($devise) ?></strong></div>
        <div class="l"><span>Total retenues</span><strong><?= nf($doc['total_retenues'] ?? 0) ?> <?= e($devise) ?></strong></div>
        <div class="grand"><span>NET À PAYER</span><span><?= nf($doc['net_a_payer'] ?? 0) ?> <?= e($devise) ?></span></div>
      <?php elseif ($type === 'recu'): ?>
        <div class="grand"><span><?= !empty($estPaiement) ? 'MONTANT RÉGLÉ' : ((($doc['type'] ?? 'sortie') === 'entree') ? 'MONTANT ENTRÉ' : 'MONTANT SORTI') ?></span><span><?= nf($doc['montant']) ?> <?= e($devise) ?></span></div>
      <?php else: ?>
        <div class="l"><span>SOUS-TOTAL</span><strong><?= nf($doc['montant_ht']) ?> <?= e($devise) ?></strong></div>
        <?php if ((float)$doc['remise'] > 0): ?><div class="l"><span>Remise</span><strong>- <?= nf($doc['remise']) ?> <?= e($devise) ?></strong></div><?php endif; ?>
        <?php if ((int)($doc['tva_applicable'] ?? 1) === 0): ?>
        <div class="l"><span>TVA</span><strong style="font-style:italic;color:#8a9ab5">Non applicable</strong></div>
        <?php else: ?>
        <div class="l"><span>TVA (<?= rtrim(rtrim(nf($doc['tva_taux']),'0'),',') ?>%)</span><strong><?= nf($doc['montant_tva']) ?> <?= e($devise) ?></strong></div>
        <?php endif; ?>
        <div class="grand"><span>TOTAL TTC</span><span><?= nf($doc['montant_ttc']) ?> <?= e($devise) ?></span></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  
  <div class="cloture"><?php if ($type === 'facture' || $type === 'proforma'): ?><div class="merci"><?= e($type === 'facture' && trim((string)($settings['mentions_facture'] ?? '')) !== '' ? $settings['mentions_facture'] : 'Merci pour votre confiance !') ?></div><?php endif; ?>
  </div>
  </div>
  <?= doc_html_auth($settings, '..', $qrUri, $checksum, $token) ?>
  </td></tr></tbody></table>
  <?= doc_html_footer($settings) ?>
</div>


<script>
/* Place le bloc d'authentification (QR + tampon + signature) tout EN BAS de la DERNIERE page,
   juste au-dessus du pied de page. Le bloc est dans le flux : il n'apparait qu'une seule fois
   et n'est JAMAIS repete. Si ce script ne s'execute pas, le bloc reste sur la derniere page
   (juste apres le contenu) : aucun risque de casse. */
(function(){
  /* RESERVE_MM : hauteur que le cadre de page réserve au pied de page sur chaque
     page. Sans la retrancher, le calage placerait la signature trop bas et
     créerait une page vide. */
  var HAUT_MM = 8, BAS_MM = 16, RESERVE_MM = 19, PAGE_MM = 297;   // doit correspondre au CSS
  function placer(){
    var sheet = document.querySelector('.sheet');
    var auth  = document.querySelector('.auth');
    if(!sheet || !auth) return;
    var old = document.getElementById('pin-spacer');
    if(old && old.parentNode) old.parentNode.removeChild(old);
    // On bascule la page dans la mise en page EXACTE de l'impression avant de mesurer :
    // sinon, sur tablette/telephone, l'affichage a l'ecran fausse completement le calcul.
    document.body.classList.add('mode-mesure');
    void document.body.offsetHeight;

    // Hauteur UTILE d'une page (hors marges) : c'est elle qui decoupe le contenu en pages.
    var sonde = document.createElement('div');
    sonde.style.cssText = 'position:absolute;visibility:hidden;left:0;top:0;width:1px;height:'
                        + (PAGE_MM - HAUT_MM - BAS_MM - RESERVE_MM) + 'mm';
    document.body.appendChild(sonde);
    var pageH = sonde.getBoundingClientRect().height;
    if(sonde.parentNode) sonde.parentNode.removeChild(sonde);
    if(!pageH || pageH < 200){ document.body.classList.remove('mode-mesure'); return; }

    // ---- Simulation de la pagination reelle ----
    // Chrome ne coupe pas une ligne de tableau en deux et REPETE l'en-tete du tableau sur
    // chaque nouvelle page. On rejoue ces deux regles pour connaitre la position imprimee
    // exacte du bloc, au lieu de l'estimer avec une marge de securite approximative.
    var hautSheet = sheet.getBoundingClientRect().top;
    var thead = document.querySelector('table thead');
    var hEntete = thead ? thead.getBoundingClientRect().height : 0;

    var blocs = [];
    document.querySelectorAll('table tbody tr').forEach(function(el){ blocs.push({el:el, tab:true}); });
    ['.bl-recap', '.bottom', '.cloture', '.auth'].forEach(function(sel){
      document.querySelectorAll(sel).forEach(function(el){ blocs.push({el:el, tab:false}); });
    });
    blocs.forEach(function(b){
      var r = b.el.getBoundingClientRect();
      b.haut = r.top - hautSheet; b.bas = r.bottom - hautSheet;
    });
    blocs.sort(function(a,b){ return a.haut - b.haut; });

    var decalage = 0, basBloc = 0;
    for (var i = 0; i < blocs.length; i++) {
      var b = blocs[i];
      var haut = b.haut + decalage, bas = b.bas + decalage;
      var page = Math.floor(haut / pageH);
      if (bas > (page + 1) * pageH) {            // ne tient pas : passe a la page suivante
        decalage += ((page + 1) * pageH - haut) + (b.tab ? hEntete : 0);
        haut = b.haut + decalage; bas = b.bas + decalage;
      }
      if (b.el === auth) basBloc = bas;
    }
    if (!basBloc) {
      var ra = auth.getBoundingClientRect();
      basBloc = (ra.top - hautSheet) + ra.height + decalage;
    }

    var pages    = Math.max(1, Math.ceil(basBloc / pageH));
    // On mesure la hauteur REELLE du pied de page fixe (coordonnees + ligne de date) pour
    // reserver exactement la place qu'il occupe, plus un petit espace de respiration.
    var elDf = document.querySelector('.df'), elDp = document.querySelector('.df-page');
    var hPied = (elDf ? elDf.getBoundingClientRect().height : 0)
              + (elDp ? elDp.getBoundingClientRect().height : 0);
    /* 5 mm de dégagement : assez pour que la signature ne touche jamais le pied de
       page, assez peu pour ne pas provoquer de page supplémentaire. */
    var securite = pageH * (5 / (PAGE_MM - HAUT_MM - BAS_MM - RESERVE_MM));
    var espace   = (pages * pageH - securite) - basBloc;
    // Si le bloc est deja bas, on n'y touche pas : sa zone de degagement suffit a le
    // proteger du pied de page (le navigateur le deplace lui-meme si besoin).
    if (espace < 0) espace = 0;
    document.body.classList.remove('mode-mesure');     // retour a l'affichage normal
    if(!isFinite(espace) || espace < 6) return;        // rien a gagner : on ne touche a rien
    var cale = document.createElement('div');
    cale.id = 'pin-spacer';
    cale.setAttribute('aria-hidden','true');
    cale.style.height = espace + 'px';
    auth.parentNode.insertBefore(cale, auth);
  }
  function lancer(){ try { placer(); } catch(e){} }
  function lancerTout(){
    lancer();
    setTimeout(lancer, 200); setTimeout(lancer, 700);
    Array.prototype.slice.call(document.images).forEach(function(im){
      if(!im.complete){ im.addEventListener('load', lancer); im.addEventListener('error', lancer); }
    });
  }
  if(document.readyState === 'complete') lancerTout();
  else window.addEventListener('load', lancerTout);
  window.addEventListener('beforeprint', lancer);

  /* Sur tablette et téléphone, la mise en page change à la rotation de l'écran et
     l'impression passe souvent par « Partager », qui ne déclenche pas toujours
     l'événement d'impression. On recalcule donc aussi dans ces situations. */
  var minuteur = null;
  function recalage(){ clearTimeout(minuteur); minuteur = setTimeout(lancer, 180); }
  window.addEventListener('resize', recalage);
  window.addEventListener('orientationchange', function(){ setTimeout(lancer, 320); });
  document.addEventListener('visibilitychange', function(){ if(!document.hidden) recalage(); });
  if (window.matchMedia) {
    var mq = window.matchMedia('print');
    if (mq.addEventListener) mq.addEventListener('change', function(e){ if(e.matches) lancer(); });
    else if (mq.addListener) mq.addListener(function(e){ if(e.matches) lancer(); });
  }
  window.imprimerDoc = function(){ lancer(); setTimeout(function(){ lancer(); window.print(); }, 120); };
})();
</script>
</body></html>
