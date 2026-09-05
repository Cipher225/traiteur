<?php
/* ============================================================================
   GABARIT HTML — même papier à en-tête que les PDF.
   Utilisé par l'impression admin, l'espace client et les documents employés.
   $base = préfixe de chemin vers la racine ('..' depuis /admin ou /espace-client)
   ============================================================================ */

function doc_logo_url(array $s, string $base): string {
    if (!empty($s['logo'])) return $base . '/uploads/' . rawurlencode($s['logo']);
    return $base . '/assets/img/logo.png';
}

function doc_html_styles(): string {
    return <<<CSS
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',-apple-system,Arial,sans-serif;background:#eef1f6;color:#2d3442;padding:24px 14px;-webkit-print-color-adjust:exact;print-color-adjust:exact;-webkit-text-size-adjust:100%;text-size-adjust:100%}
  .sheet{width:210mm;min-height:297mm;margin:0 auto;background:#fff;box-shadow:0 12px 40px rgba(10,31,68,.13);display:block}
  .footer-group{page-break-inside:avoid;break-inside:avoid}
  .flex-fill{flex:1 0 auto}      /* pousse le pied de page tout en bas de la feuille */
  .pad{padding:26px 30px}

  /* ---------- En-tête ---------- */
  /* En-tête resserré : le logo et le titre partagent la même bande, et les
     références se placent sous le titre plutôt que dans un cartouche isolé.
     Le tableau démarre ainsi bien plus haut sur la page. */
  .dh{display:flex;justify-content:space-between;align-items:center;gap:20px;padding:6px 30px 4px;min-height:0}
  .dh-left{text-align:center;width:210px;flex-shrink:0}
  .dh-left img{max-height:66px;max-width:140px;display:block;margin:0 auto 5px}
  .dh-left .ent{font-size:15px;font-weight:800;letter-spacing:.05em;color:#0a1f44;line-height:1.2}
  .dh-left .slg{font-size:7.5px;font-weight:600;letter-spacing:.09em;color:#b8870f;text-transform:uppercase;margin-top:3px;line-height:1.35}
  .dh-right{flex:1;text-align:right;padding-top:0}
  .dh-title{font-family:Georgia,'Times New Roman',serif;font-size:27px;font-weight:700;letter-spacing:.15em;
    color:#0a1f44;line-height:1.05;white-space:nowrap;text-transform:uppercase}
  .dh-title.long{font-size:20px;letter-spacing:.1em}
  .dh-title.xlong{font-size:15px;letter-spacing:.06em}
  .dh-rule{height:2px;background:#d4a526;margin:7px 0 6px;position:relative}
  .dh-rule::after{content:'';position:absolute;right:0;top:-1px;width:120px;height:4px;background:#b8870f}
  /* Références en ligne, séparées par des points médians : trois lignes gagnées */
  .dh-info{margin-top:0;display:block;text-align:right}
  .dh-info .r{display:inline-flex;gap:5px;font-size:11.5px;padding:0;margin-left:14px}
  .dh-info .r b{min-width:0;font-weight:400;color:#4a5568}
  .dh-info .r i{display:none}
  .dh-info .r span{font-weight:700;color:#0a1f44}
  .dh-sep{height:1.6px;background:#d4a526;margin:5px 30px 0}

  /* Cadre de page : le pied du tableau réserve, sur chaque page, la hauteur
     occupée par le pied de page fixe. Le contenu ne peut donc jamais passer
     dessous, quelle que soit la longueur du document. */
  .cadre-page{width:100%;border-collapse:collapse}
  .cadre-page > tbody > tr > td, .cadre-page > tfoot > tr > td{padding:0;border:none}
  .pied-reserve{height:auto}

  /* ---------- Blocs Client / Informations ---------- */
  .parts{display:flex;gap:24px;padding:9px 30px 2px}
  .part{flex:1 1 0;min-width:0;box-sizing:border-box}
  .parts .part:first-child{padding-right:0}
  .part h3{font-size:10.5px;font-weight:800;letter-spacing:.11em;color:#b8870f;text-transform:uppercase}
  .part .ul{width:28px;height:2px;background:#d4a526;margin:4px 0 7px}
  .part .row{display:flex;align-items:baseline;gap:6px;font-size:11.5px;padding:1.5px 0}
  .part .row .lb{min-width:86px;color:#4a5568;flex-shrink:0}
  .part .row .cl{color:#8a9ab5}
  .part .row .vl{flex:1;font-weight:700;color:#0a1f44;padding-bottom:1px;min-height:13px;
    overflow:hidden;text-overflow:ellipsis}

  /* ---------- Tableau ---------- */
  /* Tableau resserré : une catégorie peut compter une dizaine d'éléments,
     chacun avec sa description. On gagne de la place sans nuire à la lecture. */
  table{width:100%;border-collapse:collapse;margin:4px 0 0}
  thead th{background:#0a1f44;color:#fff;font-size:9.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:7px 10px;text-align:center}
  thead th.l{text-align:left}
  thead th.r{text-align:right}
  tbody td{padding:4px 10px;border-bottom:1px solid #e8ecf2;font-size:11.5px;vertical-align:top}
  tbody tr:nth-child(even) td{background:#f8f9fb}
  td.c{text-align:center}
  td.r{text-align:right}
  td .des{font-weight:700;color:#0a1f44;font-size:12px}
  ul.incl{list-style:none;margin:3px 0 0;padding:0}
  ul.incl li{font-size:9.8px;color:#6e7685;line-height:1.42;padding-left:10px;position:relative;
    margin-bottom:1px;break-inside:avoid}
  ul.incl li::before{content:'';position:absolute;left:1px;top:5px;width:3.5px;height:3.5px;background:#d4a526}

  /* ---------- Bulletin de paie ---------- */
  table.paie tbody td{padding:3.5px 10px;font-size:11px}
  table.paie .code{font-family:'Courier New',monospace;font-size:9.5px;color:#8a9ab5}
  table.paie td.fort{font-weight:700;color:#0a1f44}
  table.paie tr.sous-tot td{background:#f2f5fa;border-top:1px solid #cdd6e4;border-bottom:1px solid #cdd6e4;
    font-weight:800;font-size:10.5px;letter-spacing:.04em;color:#0a1f44;padding:5px 10px}
  table.paie tr.sous-tot .des{font-weight:800}
  .paie-bas{display:flex;gap:13px;padding:8px 30px 0}
  .pb-box{flex:1;border:1px solid #e2c98b;border-radius:9px;overflow:hidden;background:#fff}
  .pb-hd{color:#b8870f;font-size:9px;letter-spacing:.16em;text-transform:uppercase;font-weight:800;
    padding:6px 12px 4px;border-bottom:1px solid #f0e2bf}
  .pb-rw{display:flex;justify-content:space-between;gap:10px;padding:4px 12px;font-size:11px;color:#4a5568}
  .pb-rw b{color:#0a1f44}
  .pb-rw + .pb-rw{border-top:1px solid #f5f7fa}

  /* ---------- Totaux ---------- */
  .bottom{display:flex;gap:22px;padding:5px 30px 0;align-items:flex-start}
  .lettres{flex:1;padding-top:6px}
  .lettres p{font-size:12.5px;color:#2d3442;margin-bottom:10px}
  .lettres .dots{min-height:8px;margin-bottom:3px;font-style:italic;font-weight:700;color:#0a1f44;font-size:12px}
  .tot{width:330px;flex-shrink:0}
  .tot .l{display:flex;justify-content:space-between;padding:6px 14px;font-size:12px;background:#fafbfc;border-bottom:1px solid #e8ecf2}
  .tot .l span{color:#4a5568}
  .tot .l strong{color:#0a1f44}
  .tot .grand{display:flex;justify-content:space-between;padding:8px 14px;font-size:14.5px;font-weight:800;color:#0a1f44;
    background:linear-gradient(135deg,#c9971f 0%,#e8b93f 18%,#f7dd8f 38%,#fff3c4 50%,#f0c14b 64%,#d4a526 82%,#c9971f 100%)}
  .merci{padding:5px 30px 0;font-size:12.5px;font-style:italic;font-weight:700;color:#0a1f44}

  /* ---------- Authentification / signature ---------- */
  /* Bloc d'authentification compact : il doit tenir dans le bas de page sans
     jamais provoquer une page supplémentaire sur une facture courte. */
  .auth{display:flex;justify-content:space-between;align-items:flex-start;gap:22px;margin:5px 30px 0;padding-top:4px;border-top:1px solid #e8ecf2}
  .auth-qr{display:flex;gap:11px;align-items:flex-start}
  .auth-qr img{width:60px;height:60px}
  .auth-qr .t{font-size:8.5px;font-weight:800;letter-spacing:.07em;color:#b8870f;text-transform:uppercase}
  .auth-qr .s{font-size:8.5px;color:#6e7685;line-height:1.45;margin-top:2px}
  /* Le nom du signataire est annoncé en haut, le trait le souligne, puis le
     tampon et le paraphe viennent en dessous. */
  .sign{text-align:center;min-width:215px}
  .sign .fn{font-size:11px;color:#6e7685}
  .sign .line{font-size:10.5px;font-weight:700;color:#0a1f44;padding-bottom:2px;
    border-bottom:1px solid #b4bac6;margin-bottom:2px}
  .sign .imgs{position:relative;height:0;margin:0}
  .sign .imgs.has{height:50px;margin:0}
  .sign .imgs img.tampon{width:112px;height:auto;position:absolute;left:50%;top:0;transform:translateX(-50%);opacity:.95}
  .sign .imgs img.sig{width:76px;height:auto;position:absolute;left:50%;top:17px;transform:translateX(-50%);opacity:.95}

  /* ---------- Pied de page ---------- */
  .df{margin-top:18px;border-top:1.6px solid #d4a526;display:flex;padding:9px 30px 6px;flex-shrink:0;background:#fff}
  .df .c{flex:1;display:flex;gap:8px;align-items:flex-start;padding-right:10px;border-right:1px solid #e8ecf2;min-width:0}
  .df .c:last-child{border-right:none;padding-right:0}
  .df .dot{width:16px;height:16px;border:1.4px solid #d4a526;border-radius:50%;flex-shrink:0;position:relative;margin-top:1px}
  .df .dot::after{content:'';position:absolute;inset:5px;background:#d4a526;border-radius:50%}
  .df .tx{font-size:9px;line-height:1.55;color:#6e7685;word-break:break-word;min-width:0}
  .df .tx b{display:block;color:#0a1f44;font-size:9.5px;font-weight:700}
  .df-page{text-align:center;font-size:8px;color:#a6acb8;padding:0 0 8px;font-style:italic;background:#fff}

  .barre{max-width:850px;margin:0 auto 16px;display:flex;gap:10px;justify-content:flex-end}
  .btn-d{background:#0a1f44;color:#fff}
  .btn-p{background:#d4a526;color:#0a1f44}
  .barre a,.barre button{border:none;padding:10px 18px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}
  .btn-g{background:#e8ecf2;color:#2d3442}

  /* ---------- Adaptation aux petits écrans ---------- */
  
  /* Bulletin de paie : lignes aérées pour occuper toute la page A4 */
  .fiche-full tbody td{padding-top:9px;padding-bottom:9px}
  .fiche-full thead th{padding-top:9px;padding-bottom:9px}


  /* ===== BON DE LIVRAISON : design dedie (memes couleurs navy & or) ===== */
  .bl-strip{display:flex;gap:12px;padding:14px 40px 2px}
  .bl-card{flex:1;border:1px solid #e3e8f0;border-left:4px solid #d4a526;border-radius:8px;padding:9px 13px;background:#fbfcfe}
  .bl-card .k{font-size:8.5px;letter-spacing:.14em;text-transform:uppercase;color:#a0872f;font-weight:800}
  .bl-card .v{font-size:13.5px;font-weight:800;color:#0a1f44;margin-top:3px}
  .bl-duo{display:flex;gap:14px;padding:14px 40px 4px}
  .doc-duo{padding:7px 30px 2px;gap:13px}
  .doc-duo .bl-box{flex:1 1 0}
  /* Cartes sobres : un contour doré fin plutôt qu'un aplat de couleur.
     Le document respire davantage et s'imprime mieux en noir et blanc. */
  .bl-box{flex:0 0 50%;box-sizing:border-box;border:1px solid #e2c98b;border-radius:9px;overflow:hidden;background:#fff}
  .bl-box + .bl-box{margin-left:0}
  .bl-box .hd{background:transparent;color:#b8870f;font-size:9px;letter-spacing:.16em;
    text-transform:uppercase;font-weight:800;padding:7px 13px 5px;border-bottom:1px solid #f0e2bf}
  .bl-box .bd{padding:8px 13px 9px}
  /* Une ligne fine sépare chaque information : la lecture est plus rapide
     qu'avec des lignes collées, sans alourdir la carte. */
  .bl-box .rw{display:flex;font-size:11.5px;padding:3.5px 0;line-height:1.4;
    border-bottom:1px solid #f0f3f8}
  .bl-box .rw:last-child{border-bottom:none;padding-bottom:0}
  .bl-box .rw .lb{color:#6e7685;min-width:96px;padding-right:10px;flex-shrink:0}
  .bl-box .rw .vl{color:#0a1f44;font-weight:700;word-break:break-word;flex:1;text-align:right}
  .bl-recap{display:flex;align-items:stretch;margin:8px 40px 0;border-radius:9px;overflow:hidden;border:1px solid #e3e8f0}
  .bl-recap .lab{flex:1;background:#f6f8fb;padding:9px 15px;font-size:12px;color:#41485a;line-height:1.5}
  .bl-recap .num{background:linear-gradient(135deg,#d4a526,#e9c15c 55%,#b8870f);color:#0a1020;
                 padding:9px 20px;text-align:center;min-width:92px;display:flex;flex-direction:column;justify-content:center}
  .bl-recap .num b{font-size:19px;line-height:1}
  .bl-recap .num span{font-size:8.5px;letter-spacing:.12em;text-transform:uppercase;font-weight:800;margin-top:2px}
  /* Sur un bon de livraison, la quantité est l'information principale */
  .bl-qte{font-weight:800;font-size:13.5px;color:#0a1f44}
  .bl-incl li{font-size:9.5px}
  .bl-recu{width:17px;height:17px;border:1.5px solid #1f9d55;border-radius:4px;display:inline-flex;
           align-items:center;justify-content:center;color:#1f9d55;font-size:12px;font-weight:900;
           line-height:1;background:#eefaf2}

  @media screen and (max-width:900px){
    body:not(.mode-mesure) body{padding:12px 8px}
    body:not(.mode-mesure) .sheet{width:auto;max-width:100%;min-height:0}
    body:not(.mode-mesure) .barre{max-width:100%;flex-wrap:wrap}
    body:not(.mode-mesure) .dh{flex-direction:column;align-items:center;text-align:center;gap:14px;padding:20px 18px 10px}
    body:not(.mode-mesure) .dh-left{width:auto}
    body:not(.mode-mesure) .dh-right{text-align:center;width:100%;padding-top:0}
    body:not(.mode-mesure) .dh-title{font-size:26px;letter-spacing:.1em;white-space:normal}
    body:not(.mode-mesure) .dh-title.long{font-size:20px}
    body:not(.mode-mesure) .dh-title.xlong{font-size:16px}
    body:not(.mode-mesure) .dh-info{text-align:left;width:100%}
    body:not(.mode-mesure) .dh-info .r b{min-width:92px}
    body:not(.mode-mesure) .dh-sep{margin:6px 18px 0}
    body:not(.mode-mesure) .parts{flex-direction:column;gap:16px;padding:16px 18px 4px}
    body:not(.mode-mesure) .part .row{flex-wrap:wrap}
    body:not(.mode-mesure) .part .row .lb{min-width:92px}
    body:not(.mode-mesure) .tbl-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
    body:not(.mode-mesure) table{font-size:11.5px;min-width:520px}
    body:not(.mode-mesure) thead th{font-size:9px;padding:9px 8px}
    body:not(.mode-mesure) tbody td{padding:7px 8px}
    body:not(.mode-mesure) .bottom{flex-direction:column;gap:14px;padding:14px 18px 0}
    body:not(.mode-mesure) .tot{width:100%}
    body:not(.mode-mesure) .merci{padding:12px 18px 0}
    body:not(.mode-mesure) .auth{flex-direction:column;align-items:stretch;gap:16px;margin:16px 18px 0}
    body:not(.mode-mesure) .sign{min-width:0}
    body:not(.mode-mesure) .df{flex-wrap:wrap;padding:14px 18px 10px;gap:12px 0}
    body:not(.mode-mesure) .df .c{flex:1 1 46%;border-right:none;padding-right:8px}
    body:not(.mode-mesure) .content{padding:4px 18px 0}
  }
  @media screen and (max-width:430px){
    body:not(.mode-mesure) .dh-title{font-size:22px}
    body:not(.mode-mesure) .df .c{flex:1 1 100%}
  }
  /* MODE MESURE : reproduit exactement la mise en page d'impression, quel que soit
     l'appareil (telephone, tablette, PC). Utilise le temps d'un calcul, puis retire. */
  body.mode-mesure{padding:0;margin:0;background:#fff}
  body.mode-mesure .sheet{width:210mm;min-height:0;box-shadow:none;display:block;margin:0;padding-bottom:0}
  body.mode-mesure .barre{display:none}
  /* Le mode mesure doit refléter EXACTEMENT la mise en page d'impression :
     un dégagement fantôme fausserait le calage et provoquerait des pages inutiles. */
  body.mode-mesure .auth{margin:9px 30px 0;padding-bottom:1mm}
  body.mode-mesure table{min-width:0}
  body.mode-mesure .df, body.mode-mesure .df-page{position:absolute;left:0;top:0;width:100%;visibility:hidden}

  @media print{
    /* 14 mm d'air en HAUT de chaque page (donc aussi page 2, 3...) et 16 mm reserves
       en BAS pour le pied de page societe (fixe, repete sur chaque page). */
    @page{ size:A4; margin:8mm 0 16mm 0; }
    body{background:#fff;padding:0;margin:0;
         -webkit-text-size-adjust:100%;text-size-adjust:100%}
    .sheet{box-shadow:none;width:auto;display:block;min-height:0;padding-bottom:0}
    .barre{display:none}
    /* PIED DE PAGE SOCIETE : fixe, sur CHAQUE page */
    /* Pied de page fixe, en bas de la zone imprimable de CHAQUE page. La place qu'il occupe
       est reservee par le calcul de calage (variable "securite"), d'ou l'absence de chevauchement. */
    .cadre-page tfoot{display:table-footer-group}
    .cadre-page > tbody > tr > td{vertical-align:top}
    /* Le pied de page vit DANS le pied du cadre : il se répète sur chaque page et
       reste collé au bas de la zone de contenu. Contrairement à un élément fixé sur
       la feuille, il suit la marge réellement accordée par le navigateur — y compris
       lorsque celui-ci réserve de la place pour ses propres mentions. */
    .pied-reserve{height:auto;min-height:17mm;padding-top:2mm}
    .df{position:static;margin:0;background:#fff}
    .df-page{position:static;margin:0;padding:0;background:#fff}
    /* BLOC AUTH (QR + tampon + signature) : dans le flux -> apparait UNE fois, sur la derniere page */
    /* Le bloc embarque sa propre zone de degagement (22 mm) : comme il est insecable,
       le navigateur le bascule tout seul sur la page suivante s'il ne tient pas
       au-dessus du pied de page. Plus aucun chevauchement possible. */
    .auth{page-break-inside:avoid;break-inside:avoid;margin:9px 30px 0;padding-bottom:1mm}
    /* aucune partie du bloc (QR, tampon, signature, nom) ne peut etre coupee */
    .auth > *, .sign, .sign .imgs, .sign .line, .auth-qr{page-break-inside:avoid;break-inside:avoid}
    .sign .line, .sign .imgs{page-break-before:avoid;break-before:avoid}
    /* aération et coupures propres */
    .content{page-break-inside:auto}
    .content p{page-break-inside:avoid;orphans:3;widows:3}
    table{page-break-inside:auto}
    /* L'en-tête du tableau se répète en haut de chaque page, mais jamais seul :
       s'il n'a plus de ligne à annoncer, il ne doit pas apparaître. */
    thead{display:table-header-group}
    thead{break-after:avoid;page-break-after:avoid}
    tbody tr{break-inside:avoid;page-break-inside:avoid}
    /* Les totaux et la mention finale ne se coupent jamais entre deux pages. */
    .bottom, .tot, .merci, .lettres{break-inside:avoid;page-break-inside:avoid}
    .bottom,.parts,.cloture{page-break-inside:avoid}
  }

CSS;
}

/* En-tête : logo + identité à gauche, titre + informations à droite */
function doc_html_header(array $s, string $titre, array $entete, string $base): string {
    $h  = '<div class="dh"><div class="dh-left">';
    $h .= '<img src="' . e(doc_logo_url($s, $base)) . '" alt="">';
    $h .= '<div class="ent">' . e(mb_strtoupper($s['nom_entreprise'] ?? '')) . '</div>';
    if (trim((string)($s['slogan'] ?? '')) !== '') $h .= '<div class="slg">' . e($s['slogan']) . '</div>';
    $n = mb_strlen($titre);
    $cls = $n > 22 ? ' xlong' : ($n > 12 ? ' long' : '');
    $h .= '</div><div class="dh-right"><div class="dh-title' . $cls . '">' . e($titre) . '</div><div class="dh-rule"></div>';
    $h .= '<div class="dh-info">';
    foreach ($entete as $r) {
        $h .= '<div class="r"><b>' . e($r[0]) . '</b><i>:</i><span>' . e($r[1]) . '</span></div>';
    }
    $h .= '</div></div></div><div class="dh-sep"></div>';
    return $h;
}

/* Deux blocs de coordonnées avec conduites pointillées */
/* Deux cartes à en-tête plein, côte à côte : la même présentation que le bon de
   livraison. Elles occupent toute la largeur, se lisent d'un coup d'œil et
   donnent la même identité à tous les documents. Les lignes vides sont
   ignorées : une carte ne montre jamais un champ non renseigné. */
function doc_html_parties(string $t1, array $l1, string $t2, array $l2): string {
    $carte = function (string $t, array $rows) {
        $o = '<div class="bl-box"><div class="hd">' . e($t) . '</div><div class="bd">';
        $vide = true;
        foreach ($rows as $r) {
            if (trim((string)$r[1]) === '') continue;
            $vide = false;
            $o .= '<div class="rw"><span class="lb">' . e($r[0]) . '</span>'
                . '<span class="vl">' . e((string)$r[1]) . '</span></div>';
        }
        if ($vide) $o .= '<div class="rw"><span class="vl" style="color:#8a9ab5">—</span></div>';
        return $o . '</div></div>';
    };
    return '<div class="bl-duo doc-duo">' . $carte($t1, $l1) . $carte($t2, $l2) . '</div>';
}

/* Pied de page : quatre colonnes avec pastilles dorées */
function doc_html_footer(array $s, int $page = 1): string {
    $site = preg_replace('#^https?://#', '', (string)($s['site_url'] ?? ''));
    $cols = [
        ['<b>' . e(mb_strtoupper($s['nom_entreprise'] ?? '') . ' ' . ($s['forme_juridique'] ?? '')) . '</b>' . e($s['adresse'] ?? '')],
        [e($s['telephone'] ?? '') . ($s['whatsapp'] ?? '' ? '<br>' . e($s['whatsapp']) : '')],
        [e($s['email'] ?? '') . ($site ? '<br>' . e($site) : '')],
        [($s['rccm'] ?? '' ? 'RC : ' . e($s['rccm']) : '') . ($s['ncc'] ?? '' ? '<br>N° Contribuable : ' . e($s['ncc']) : '')],
    ];
    $o = '<div class="df">';
    foreach ($cols as $c) $o .= '<div class="c"><div class="dot"></div><div class="tx">' . $c[0] . '</div></div>';
    $o .= '</div><div class="df-page">Document émis le ' . date('d/m/Y à H:i') . '</div>';
    return $o;
}

/* Zone QR + signature/tampon */
function doc_html_auth(array $s, string $base, ?string $qrDataUri, ?string $checksum, ?string $token): string {
    $o = '<div class="auth">';
    if ($qrDataUri) {
        $o .= '<div class="auth-qr"><img src="' . $qrDataUri . '" alt="QR">'
            . '<div><div class="t">Document authentifiable</div><div class="s">'
            . "Scannez le code pour vérifier l'authenticité<br>Empreinte : " . e((string)$checksum)
            . '<br>Code : ' . e(mb_strtoupper(substr((string)$token, 0, 12))) . '…</div></div></div>';
    } else {
        $o .= '<div></div>';
    }
    $aImg = !empty($s['tampon_img']) || !empty($s['signature_img']);
    // Un seul texte pour le signataire (nom et/ou fonction), place SOUS la signature.
    // Compatibilite : si l'ancien champ "nom" existe encore, on l'affiche a la suite.
    $sigTxt = trim((string)($s['signataire_fonction'] ?? ''));
    $ancienNom = trim((string)($s['signataire_nom'] ?? ''));
    if ($ancienNom !== '' && mb_stripos($sigTxt, $ancienNom) === false) {
        $sigTxt = $sigTxt !== '' ? $ancienNom . ', ' . $sigTxt : $ancienNom;
    }
    if ($sigTxt === '') $sigTxt = (string)($s['nom_entreprise'] ?? 'La Direction');
    /* Le signataire est annoncé AVANT sa signature : on lit d'abord qui signe,
       puis on voit le tampon et le paraphe en dessous — comme sur un courrier. */
    $o .= '<div class="sign">'
        . '<div class="line">' . e($sigTxt) . '</div>'
        . '<div class="imgs' . ($aImg ? ' has' : '') . '">';
    if (!empty($s['tampon_img']))    $o .= '<img class="tampon" src="' . $base . '/uploads/' . e($s['tampon_img']) . '" alt="">';
    if (!empty($s['signature_img'])) $o .= '<img class="sig" src="' . $base . '/uploads/' . e($s['signature_img']) . '" alt="">';
    $o .= '</div></div>';
    return $o . '</div>';
}
