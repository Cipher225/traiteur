<?php
/* ============================================================================
   GÉNÉRATION PDF CÔTÉ SERVEUR
   ----------------------------------------------------------------------------
   Le PDF est fabriqué par le serveur, pas par le navigateur. Le résultat est
   donc rigoureusement identique pour tout le monde : plus de mentions ajoutées
   par le navigateur, plus de marges qui varient d'un appareil à l'autre.

   Dompdf ne gère pas les mises en page souples (flexbox) : ce gabarit est donc
   construit en tableaux, seule structure qu'il rend fidèlement.
   ============================================================================ */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/docauth.php';
require_once __DIR__ . '/includes/documents.php';
require_once __DIR__ . '/../config/wave.php';

/* Petites fonctions de mise en forme, partagées avec la version imprimable. */
if (!function_exists('nf')) {
    function nf($n) { return number_format((float)$n, 0, ',', ' '); }
}
if (!function_exists('moisfr')) {
    function moisfr(string $p): string {
        $m = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
              'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
        $x = explode('-', $p);
        return isset($x[1]) ? ($m[(int)$x[1]] ?? '') . ' ' . $x[0] : $p;
    }
}

/* ---------------------------------------------------------------------------
   Construit le HTML d'un document, dans une forme que Dompdf sait rendre.
   --------------------------------------------------------------------------- */
function pdf_document_html(PDO $pdo, array $settings, string $type, array $doc,
                           ?string $qrFichier = null, ?string $empreinte = null, ?string $code = null): string {

    $devise = $settings['devise'] ?? 'FCFA';
    $base   = realpath(__DIR__ . '/..');
    $img    = function ($nom) use ($base) {
        if (!$nom) return '';
        $p = $base . '/uploads/' . $nom;
        return is_file($p) ? $p : '';
    };

    $logo      = $img($settings['logo'] ?? '');
    $tampon    = $img($settings['tampon_img'] ?? '');
    $signature = $img($settings['signature_img'] ?? '');

    /* ---- Titre et références ---- */
    $titres = ['facture' => 'FACTURE', 'proforma' => 'PROFORMA', 'livraison' => 'BON DE LIVRAISON',
               'recu' => 'REÇU', 'fiche' => 'BULLETIN DE PAIE'];
    /* Un rapport ou une demande porte le libellé de son propre type
       (rapport, permission, congé…), défini dans le module des demandes. */
    $titre = $type === 'rapport'
        ? mb_strtoupper((string)($doc['type_libelle'] ?? 'RAPPORT'))
        : ($titres[$type] ?? 'DOCUMENT');

    $refs = [];
    if ($type === 'livraison') {
        $refs[] = ['N° Bon', numero_bon_livraison($pdo, (int)$doc['id'])];
        $refs[] = ['Date', date('d/m/Y', strtotime($doc['date_emission']))];
        $refs[] = ['Réf. facture', $doc['numero']];

    } elseif ($type === 'rapport') {
        $refs[] = ['N° Document', $doc['numero']];
        $refs[] = ['Date', date('d/m/Y', strtotime($doc['date_rapport']))];
        if (!empty($doc['envoye_at'])) $refs[] = ['Transmis le', date('d/m/Y', strtotime($doc['envoye_at']))];
    } elseif ($type === 'fiche') {
        $refs[] = ['N° Bulletin', $doc['numero']];
        $refs[] = ['Période', moisfr((string)$doc['periode'])];
    } else {
        $refs[] = ['N° ' . ucfirst($type === 'proforma' ? 'proforma' : 'facture'), $doc['numero']];
        $refs[] = ['Date', date('d/m/Y', strtotime($doc['date_emission']))];
        if (!empty($doc['date_echeance'])) $refs[] = ['Échéance', date('d/m/Y', strtotime($doc['date_echeance']))];
    }

    /* ---- Deux cartes d'informations ---- */
    [$t1, $l1, $t2, $l2] = pdf_blocs_infos($settings, $type, $doc);

    ob_start(); ?>
<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><style>
  @page { margin: 12mm 10mm 22mm 10mm; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 8.5pt; color: #2d3442; margin: 0; }
  table { width: 100%; border-collapse: collapse; }
  .r { text-align: right; } .c { text-align: center; }

  /* En-tête */
  .ent td { vertical-align: middle; padding: 0 0 4px; }
  .ent .soc { font-size: 11.5pt; font-weight: bold; color: #0a1f44; letter-spacing: .5px; }
  .ent .slg { font-size: 6pt; color: #b8870f; letter-spacing: .8px; text-transform: uppercase; }
  .ent .ttl { font-size: 16pt; font-weight: bold; color: #0a1f44; letter-spacing: 3.5px;
               text-align: right; }
  .filet { border-bottom: 1.6pt solid #d4a526; height: 2px; margin: 3px 0 4px; }
  .sep { border-bottom: 1.2pt solid #d4a526; height: 1px; margin: 0 0 7px; }
  .refs { text-align: right; font-size: 8pt; color: #4a5568; }
  .refs b { color: #0a1f44; }

  /* Cartes */
  .cartes td { width: 50%; vertical-align: top; padding: 0; }
  .cartes td.gauche { padding-right: 6px; }
  .carte { border: .8pt solid #e2c98b; border-radius: 4px; padding: 0; }
  .carte .hd { color: #b8870f; font-size: 6.5pt; font-weight: bold; letter-spacing: 1.4px;
               text-transform: uppercase; padding: 5px 9px 3px; border-bottom: .6pt solid #f0e2bf; }
  .carte .bd { padding: 4px 9px 6px; }
  .carte .lb { color: #6e7685; font-size: 8pt; width: 38%; }
  .carte .vl { color: #0a1f44; font-weight: bold; font-size: 8pt; text-align: right; width: 62%; }
  .carte tr td { padding: 2px 0; border-bottom: .5pt solid #f2f5f9; }
  .carte tr:last-child td { border-bottom: none; }

  /* Présentation sobre (bon de livraison, bulletin de paie) */
  .simple td { vertical-align: top; padding: 0; }
  .sbloc .stit { font-size: 6.5pt; font-weight: bold; letter-spacing: 1.4px; text-transform: uppercase;
                 color: #b8870f; border-bottom: .8pt solid #d4a526; padding-bottom: 3px; margin-bottom: 4px; }
  .sbloc table { width: 100%; }
  .sbloc .slb { color: #6e7685; font-size: 8pt; width: 40%; padding: 2px 0; }
  .sbloc .svl { color: #0a1f44; font-weight: bold; font-size: 8pt; width: 60%; padding: 2px 0; }

  /* Tableau des lignes */
  .lignes { margin-top: 8px; }
  .lignes thead th { background: #0a1f44; color: #fff; font-size: 7pt; font-weight: bold;
                     letter-spacing: .7px; text-transform: uppercase; padding: 5px 7px; }
  .lignes tbody td { padding: 4px 7px; border-bottom: .5pt solid #e8ecf2; font-size: 8.5pt;
                     vertical-align: top; }
  .lignes tbody tr.paire td { background: #f8f9fb; }
  .lignes .des { font-weight: bold; color: #0a1f44; }
  .lignes .det { font-size: 7pt; color: #6e7685; }

  .mention-liv { margin-top: 9px; padding: 8px 12px; font-size: 8.5pt; color: #41485a;
                 background: #f6f8fb; border: .5pt solid #e3e8f0; border-radius: 5px; }

  /* Rapports et demandes */
  .titre-doc { font-size: 11pt; font-weight: bold; color: #0a1f44; text-align: center;
               margin: 12px 0 8px; letter-spacing: .3px; }
  .texte { font-size: 9pt; line-height: 1.55; color: #2d3442; text-align: justify; }
  .texte p { margin: 0 0 7px; }
  .texte h1 { font-size: 12pt; color: #0a1f44; margin: 10px 0 6px; }
  .texte h2 { font-size: 10.5pt; color: #0a1f44; margin: 9px 0 5px; }
  .texte ul, .texte ol { margin: 0 0 7px 16px; }
  .texte table { width: 100%; border-collapse: collapse; margin: 6px 0; }
  .texte table td, .texte table th { border: .5pt solid #dbe1ea; padding: 4px 6px; font-size: 8.5pt; }
  .texte img { max-width: 100%; }

  /* Totaux */
  .bas { margin-top: 7px; }
  .bas td { vertical-align: top; }
  .lettres { font-size: 8pt; color: #2d3442; padding-right: 12px; }
  .lettres .mt { font-weight: bold; font-style: italic; color: #0a1f44; font-size: 8.5pt; }
  .tot td { padding: 5px 10px; font-size: 8.5pt; background: #fafbfc; border-bottom: .5pt solid #e8ecf2; }
  .tot .grand td { background: #d4a526; color: #0a1020; font-weight: bold; font-size: 10pt; padding: 7px 10px; }
  .merci { font-size: 8pt; font-style: italic; font-weight: bold; color: #0a1f44; padding-top: 7px; }

  /* Authentification, tampon et signature */
  .auth { margin-top: 10px; border-top: .6pt solid #e8ecf2; padding-top: 5px; }
  .auth td { vertical-align: top; }
  .auth .qtxt { font-size: 6.5pt; color: #6e7685; line-height: 1.35; }
  .auth .qtit { font-size: 7pt; font-weight: bold; color: #b8870f; letter-spacing: .8px;
                text-transform: uppercase; }
  .auth .sig { text-align: center; }
  .auth .sig .nom { font-size: 8pt; font-weight: bold; color: #0a1f44;
                    border-bottom: .6pt solid #b4bac6; padding-bottom: 2px; }

  /* Pied de page : répété automatiquement sur chaque page */
  .pied { position: fixed; bottom: -16mm; left: 0; right: 0; height: 16mm;
          border-top: 1.4pt solid #d4a526; padding-top: 4px; }
  .pied td { font-size: 6.5pt; color: #6e7685; vertical-align: top; padding: 0 5px; line-height: 1.4; }
  .pied .mention td { padding: 0; }
  .pied .pastille { width: 16px; vertical-align: top; }
  .pied .cercle { width: 9px; height: 9px; border: .7pt solid #d4a526; border-radius: 5px;
                  margin-top: 2px; padding: 2px; }
  .pied .point { width: 3px; height: 3px; background: #d4a526; border-radius: 2px; }
  .pied .txt { padding-left: 3px; }
  .pied b { color: #0a1f44; font-size: 6.8pt; }
  .pied .emis { text-align: center; font-size: 5.8pt; color: #9aa3b4; font-style: italic; padding-top: 3px; }
</style></head><body>

<!-- ================= PIED DE PAGE (sur toutes les pages) ================= -->
<?php
/* Chaque mention légale est précédée d'une pastille dorée : un cercle fin avec
   un point plein au centre, comme sur le papier à en-tête. */
$site = preg_replace('#^https?://#', '', (string)($settings['site_url'] ?? ''));
$colonnes = [
    ['27%', '<b>' . e(mb_strtoupper(($settings['nom_entreprise'] ?? '') . ' ' . ($settings['forme_juridique'] ?? ''))) . '</b><br>' . e($settings['adresse'] ?? '')],
    ['23%', e($settings['telephone'] ?? '') . (!empty($settings['whatsapp']) ? '<br>' . e($settings['whatsapp']) : '')],
    ['27%', e($settings['email'] ?? '') . ($site ? '<br>' . e($site) : '')],
    ['23%', (!empty($settings['rccm']) ? 'RC : ' . e($settings['rccm']) : '')
          . (!empty($settings['ncc']) ? '<br>N° Contribuable : ' . e($settings['ncc']) : '')],
];
?>
<div class="pied">
  <table><tr>
    <?php foreach ($colonnes as [$largeur, $contenu]): ?>
    <td width="<?= $largeur ?>">
      <table class="mention"><tr>
        <td width="16" class="pastille"><div class="cercle"><div class="point"></div></div></td>
        <td class="txt"><?= $contenu ?></td>
      </tr></table>
    </td>
    <?php endforeach; ?>
  </tr></table>
  <div class="emis">Document émis le <?= date('d/m/Y à H:i') ?></div>
</div>

<!-- ================= PAPIER À EN-TÊTE =================
     Le filet doré part du titre et s'arrête avant le logo, puis les références
     se placent dessous. Un second filet ferme l'en-tête : c'est la disposition
     d'un papier à en-tête classique. -->
<table class="ent"><tr>
  <td width="40%" class="c">
    <?php if ($logo): ?><img src="<?= $logo ?>" style="max-height:46px"><br><?php endif; ?>
    <span class="soc"><?= e(mb_strtoupper($settings['nom_entreprise'] ?? '')) ?></span><br>
    <span class="slg"><?= e($settings['slogan'] ?? '') ?></span>
  </td>
  <td width="60%">
    <div class="ttl"><?= e($titre) ?></div>
    <div class="filet"></div>
    <div class="refs">
      <?php foreach ($refs as $i => $r): ?>
        <?= $i ? ' &nbsp;&nbsp;&nbsp; ' : '' ?><?= e($r[0]) ?> <b><?= e($r[1]) ?></b>
      <?php endforeach; ?>
    </div>
  </td>
</tr></table>
<div class="sep"></div>

<!-- ================= INFORMATIONS =================
     Bon de livraison et bulletin de paie adoptent une présentation sobre, sans
     encadré : deux colonnes de lignes simples, comme sur un relevé bancaire.
     Les factures et proformas conservent leurs cartes. -->
<?php if (in_array($type, ['livraison', 'fiche', 'rapport'], true)): ?>
<table class="simple"><tr>
  <td width="49%"><?= pdf_bloc_simple($t1, $l1) ?></td>
  <td width="2%"></td>
  <td width="49%"><?= pdf_bloc_simple($t2, $l2) ?></td>
</tr></table>
<?php else: ?>
<table class="cartes"><tr>
  <td class="gauche"><?= pdf_carte($t1, $l1) ?></td>
  <td><?= pdf_carte($t2, $l2) ?></td>
</tr></table>
<?php endif; ?>

<?= pdf_corps($type, $doc, $devise) ?>

<?php /* Le bloc d'authentification n'est pas placé ici : il est dessiné sur la
         DERNIÈRE page uniquement, juste au-dessus du pied de page. Cette zone
         vide garantit que le contenu ne vienne jamais s'y superposer. */ ?>
<div style="height:38mm"></div>

</body></html>
<?php
    return ob_get_clean();
}

/* ---- Une carte d'informations ---- */
function pdf_carte(string $titre, array $lignes): string {
    $o = '<div class="carte"><div class="hd">' . e($titre) . '</div><div class="bd"><table>';
    $vide = true;
    foreach ($lignes as $l) {
        if (trim((string)$l[1]) === '') continue;
        $vide = false;
        $o .= '<tr><td class="lb">' . e($l[0]) . '</td><td class="vl">' . e((string)$l[1]) . '</td></tr>';
    }
    if ($vide) $o .= '<tr><td class="lb">—</td><td class="vl"></td></tr>';
    return $o . '</table></div></div>';
}

/* ---- Bloc sobre, sans encadré : un titre souligné puis des lignes simples ---- */
function pdf_bloc_simple(string $titre, array $lignes): string {
    $o = '<div class="sbloc"><div class="stit">' . e($titre) . '</div><table>';
    foreach ($lignes as $l) {
        if (trim((string)$l[1]) === '') continue;
        $o .= '<tr><td class="slb">' . e($l[0]) . '</td><td class="svl">' . e((string)$l[1]) . '</td></tr>';
    }
    return $o . '</table></div>';
}

/* ---- Contenu des deux cartes, selon le type de document ---- */
function pdf_blocs_infos(array $s, string $type, array $doc): array {
    $nom = trim((string)($doc['entreprise'] ?? '')) !== '' ? $doc['entreprise'] : ($doc['client_nom'] ?? '');

    if ($type === 'rapport') {
        $l1 = [['Auteur', $doc['auteur'] ?? ''], ['Type', $doc['type_libelle'] ?? 'Rapport'],
               ['Date', !empty($doc['date_rapport']) ? date('d/m/Y', strtotime($doc['date_rapport'])) : '']];
        $l2 = [];
        if (!empty($doc['date_debut'])) $l2[] = ['Du', date('d/m/Y', strtotime($doc['date_debut']))];
        if (!empty($doc['date_fin']))   $l2[] = ['Au', date('d/m/Y', strtotime($doc['date_fin']))];
        if (trim((string)($doc['lieu'] ?? '')) !== '')   $l2[] = ['Lieu', $doc['lieu']];
        if (trim((string)($doc['motif'] ?? '')) !== '')  $l2[] = ['Motif', $doc['motif']];
        if (trim((string)($doc['statut'] ?? '')) !== '') $l2[] = ['État', ucfirst((string)$doc['statut'])];
        if (!$l2) $l2[] = ['Référence', $doc['numero'] ?? ''];
        return ['Émetteur', $l1, 'Informations', $l2];
    }

    if ($type === 'fiche') {
        return ['Salarié', [
            ['Nom & prénoms', $doc['employe_nom'] ?? ''], ['Matricule', $doc['matricule'] ?? ''],
            ['Emploi', $doc['poste'] ?? ''], ['Département', $doc['departement'] ?? ''],
            ['N° CNPS', $doc['numero_cnps'] ?? '']],
            'Informations', [
            ['Période', moisfr((string)$doc['periode'])],
            ["Date d'embauche", !empty($doc['date_embauche']) ? date('d/m/Y', strtotime($doc['date_embauche'])) : ''],
            ['Mode de paiement', $doc['mode_paiement'] ?? ''],
            ['Banque', $doc['banque'] ?? ''],
            ['N° de compte', $doc['numero_compte'] ?? '']]];
    }

    $l1 = [['Nom / Société', $nom]];
    if (trim((string)($doc['client_adresse'] ?? '')) !== '' && $type === 'livraison')
        $l1[] = ['Adresse', $doc['client_adresse']];
    if (trim((string)($doc['client_tel'] ?? '')) !== '')   $l1[] = ['Téléphone', $doc['client_tel']];
    if ($type !== 'livraison' && trim((string)($doc['client_email'] ?? '')) !== '')
        $l1[] = ['Email', $doc['client_email']];
    $l1[] = ['N° Client', $doc['client_id'] ? sprintf('CL-%04d', (int)$doc['client_id']) : ''];

    $l2 = [];
    if (trim((string)($doc['mode_paiement'] ?? '')) !== '') $l2[] = ['Mode de paiement', $doc['mode_paiement']];
    if (trim((string)($doc['activite'] ?? '')) !== '')      $l2[] = ['Activité', $doc['activite']];
    if (!empty($doc['date_evenement'])) {
        /* Le client souhaite lire la date de l'événement, puis sa durée sur une
           ligne distincte : c'est plus clair qu'une période écrite d'un bloc. */
        $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
                 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $deb = strtotime((string)$doc['date_evenement']);
        $l2[] = ["Date de l'événement",
                 date('j', $deb) . ' ' . $mois[(int)date('n', $deb)] . ' ' . date('Y', $deb)];
        $nbj = max(1, (int)($doc['nb_jours'] ?? 1));
        $l2[] = ['Nombre de jours', $nbj . ($nbj > 1 ? ' jours' : ' jour')];
    }
    if (trim((string)($doc['lieu'] ?? '')) !== '') $l2[] = ['Lieu', $doc['lieu']];
    if ($type === 'livraison') $l2[] = ['Livreur', $s['nom_entreprise'] ?? ''];
    if (!$l2) $l2[] = ['Devise', $s['devise'] ?? 'FCFA'];

    return [$type === 'livraison' ? 'Livré à' : 'Client', $l1, $type === 'livraison' ? 'Livraison' : 'Informations', $l2];
}

/* ---- Date ou période de l'événement ---- */
function pdf_periode(array $doc): array {
    $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
             'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $deb = strtotime((string)$doc['date_evenement']);
    $nbj = max(1, (int)($doc['nb_jours'] ?? 1));
    if ($nbj <= 1) return ["Date de l'événement", date('j', $deb) . ' ' . $mois[(int)date('n', $deb)] . ' ' . date('Y', $deb)];

    $fin = strtotime('+' . ($nbj - 1) . ' day', $deb);
    $jd = date('j', $deb); $jf = date('j', $fin);
    $md = (int)date('n', $deb); $mf = (int)date('n', $fin);
    $ad = date('Y', $deb); $af = date('Y', $fin);
    if ($ad === $af && $md === $mf) $t = "Du $jd au $jf {$mois[$mf]} $af";
    elseif ($ad === $af)            $t = "Du $jd {$mois[$md]} au $jf {$mois[$mf]} $af";
    else                            $t = "Du $jd {$mois[$md]} $ad au $jf {$mois[$mf]} $af";
    return ["Dates de l'événement", $t];
}

/* ---- Corps : tableau des lignes puis totaux ---- */
function pdf_corps(string $type, array $doc, string $devise): string {
    ob_start();

    if ($type === 'fiche') { echo pdf_corps_paie($doc, $devise); return ob_get_clean(); }

    if ($type === 'rapport') {
        /* Le contenu est du texte mis en forme dans l'éditeur : on le reprend tel
           quel, après nettoyage, dans un cadre de lecture confortable. */
        echo '<div class="titre-doc">' . e($doc['titre'] ?? '') . '</div>';
        echo '<div class="texte">' . doc_nettoyer_html((string)($doc['contenu'] ?? '')) . '</div>';
        return ob_get_clean();
    }

    $estLivraison = ($type === 'livraison');
    ?>
    <table class="lignes">
      <thead><tr>
        <th width="6%">N°</th>
        <th style="text-align:left">Désignation</th>
        <th width="12%">Qté</th>
        <?php if (!$estLivraison): ?>
        <th width="20%" class="r">Prix unit. (<?= e($devise) ?>)</th>
        <th width="20%" class="r">Montant (<?= e($devise) ?>)</th>
        <?php endif; ?>
      </tr></thead>
      <tbody>
      <?php $n = 0; foreach ($doc['lignes'] as $l): $n++;
        $det = $estLivraison ? [] : array_values(array_filter(array_map('trim',
               preg_split('/\r?\n/', (string)($l['details'] ?? ''))))); ?>
        <tr class="<?= $n % 2 === 0 ? 'paire' : '' ?>">
          <td class="c"><?= $n ?></td>
          <td><span class="des"><?= e($l['designation']) ?></span>
            <?php foreach ($det as $d): ?><br><span class="det">• <?= e($d) ?></span><?php endforeach; ?>
          </td>
          <td class="c"><?= qte_fmt($l['quantite']) ?></td>
          <?php if (!$estLivraison): ?>
          <td class="r"><?= nf($l['prix_unitaire']) ?></td>
          <td class="r"><?= nf((float)$l['quantite'] * (float)$l['prix_unitaire']) ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php if (!$estLivraison): ?>
    <table class="bas"><tr>
      <td width="52%" class="lettres">
        Arrêtée la présente <?= $type === 'proforma' ? 'proforma' : 'facture' ?> à la somme de :<br>
        <span class="mt"><?= e(ucfirst_fr(montant_lettres((int)$doc['montant_ttc'])) . ' ' . $devise) ?></span>
        <div class="merci"><?= $type === 'proforma' ? 'Merci pour votre confiance !' : 'Paiement à réception. Merci de votre confiance.' ?></div>
      </td>
      <td width="48%">
        <table class="tot">
          <tr><td>Sous-total</td><td class="r"><b><?= nf($doc['base']) ?> <?= e($devise) ?></b></td></tr>
          <?php if ((float)($doc['montant_tva'] ?? 0) > 0): ?>
          <tr><td>TVA (<?= (float)$doc['tva_taux'] ?>%)</td><td class="r"><b><?= nf($doc['montant_tva']) ?> <?= e($devise) ?></b></td></tr>
          <?php endif; ?>
          <tr class="grand"><td>TOTAL TTC</td><td class="r"><?= nf($doc['montant_ttc']) ?> <?= e($devise) ?></td></tr>
        </table>
      </td>
    </tr></table>
    <?php else: ?>
    <div class="mention-liv">
      <?= e($GLOBALS['settings']['mention_livraison'] ?? 'Marchandises livrées et reçues en bon état.') ?>
    </div>
    <?php endif;

    return ob_get_clean();
}

/* ---- Corps d'un bulletin de paie ---- */
function pdf_corps_paie(array $doc, string $devise): string {
    $base = (float)$doc['salaire_base'];
    $gains = [
        ['100', 'Salaire de base',        $base, null, (float)$doc['salaire_base']],
        ['110', 'Sursalaire',             null,  null, (float)($doc['sursalaire'] ?? 0)],
        ['120', 'Prime de transport',     null,  null, (float)($doc['prime_transport'] ?? 0)],
        ['130', "Prime d'ancienneté",     $base, null, (float)($doc['prime_anciennete'] ?? 0)],
        ['140', 'Autres primes',          null,  null, (float)($doc['primes'] ?? 0)],
        ['150', 'Indemnités',             null,  null, (float)($doc['indemnites'] ?? 0)],
        ['160', 'Heures supplémentaires', null,  null, (float)($doc['heures_sup'] ?? 0)],
    ];
    $brut = 0.0; foreach ($gains as $g) $brut += $g[4];
    $rets = [
        ['300', 'CNPS — part salariale',   $brut, 6.3,  (float)($doc['cnps'] ?? 0)],
        ['310', 'Impôt sur salaire (ITS)', $brut, null, (float)($doc['impots'] ?? 0)],
        ['320', 'Avances / acomptes',      null,  null, (float)($doc['avances'] ?? 0)],
        ['330', 'Autres retenues',         null,  null, (float)($doc['autres_deductions'] ?? 0)],
    ];
    $totRet = 0.0; foreach ($rets as $r) $totRet += $r[4];
    $tx = fn($v) => $v === null ? '' : rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',') . ' %';

    ob_start(); ?>
    <table class="lignes">
      <thead><tr>
        <th width="7%">Code</th><th style="text-align:left">Libellé</th>
        <th width="15%" class="r">Base</th><th width="10%" class="r">Taux</th>
        <th width="16%" class="r">Gains</th><th width="16%" class="r">Retenues</th>
      </tr></thead>
      <tbody>
        <?php foreach ($gains as $g): if ($g[4] == 0) continue; ?>
        <tr><td class="c"><?= e($g[0]) ?></td><td><span class="des"><?= e($g[1]) ?></span></td>
          <td class="r"><?= $g[2] !== null ? nf($g[2]) : '' ?></td>
          <td class="r"><?= e($tx($g[3])) ?></td>
          <td class="r"><b><?= nf($g[4]) ?></b></td><td></td></tr>
        <?php endforeach; ?>
        <tr class="paire"><td></td><td><span class="des">SALAIRE BRUT</span></td><td></td><td></td>
          <td class="r"><b><?= nf($brut) ?></b></td><td></td></tr>
        <?php foreach ($rets as $r): if ($r[4] == 0) continue; ?>
        <tr><td class="c"><?= e($r[0]) ?></td><td><span class="des"><?= e($r[1]) ?></span></td>
          <td class="r"><?= $r[2] !== null ? nf($r[2]) : '' ?></td>
          <td class="r"><?= e($tx($r[3])) ?></td>
          <td></td><td class="r"><b><?= nf($r[4]) ?></b></td></tr>
        <?php endforeach; ?>
        <tr class="paire"><td></td><td><span class="des">TOTAL DES RETENUES</span></td><td></td><td></td>
          <td></td><td class="r"><b><?= nf($totRet) ?></b></td></tr>
      </tbody>
    </table>

    <table class="bas"><tr>
      <td width="52%" class="lettres">
        Arrêté le présent bulletin à la somme nette de :<br>
        <span class="mt"><?= e(ucfirst_fr(montant_lettres((int)$doc['net_a_payer'])) . ' ' . $devise) ?></span>
      </td>
      <td width="48%">
        <table class="tot">
          <tr><td>Salaire brut</td><td class="r"><b><?= nf($brut) ?> <?= e($devise) ?></b></td></tr>
          <tr><td>Total retenues</td><td class="r"><b><?= nf($totRet) ?> <?= e($devise) ?></b></td></tr>
          <tr class="grand"><td>NET À PAYER</td><td class="r"><?= nf($doc['net_a_payer']) ?> <?= e($devise) ?></td></tr>
        </table>
      </td>
    </tr></table>
    <?php
    return ob_get_clean();
}
