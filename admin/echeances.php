<?php
/* ============================================================================
   ÉCHÉANCES RÉCURRENTES

   Les obligations qui reviennent : déclarations, cotisations, renouvellements.
   Un cadran annuel les place sur les douze mois, une aiguille marque le jour,
   et la liste détaille ce qui réclame votre attention.
   ============================================================================ */
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/../config/echeances.php';

if (!peut_acceder('echeances')) { header('Location: index.php'); exit; }

$CATS = ech_categories();
$RECS = ech_recurrences();

/* ---------------------------------------------------------------- Actions -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    /* Création ou modification d'une échéance. */
    if (isset($_POST['enregistrer'])) {
        $id  = (int)($_POST['id'] ?? 0);
        $lib = trim((string)($_POST['libelle'] ?? ''));
        $rec = in_array($_POST['recurrence'] ?? '', array_keys($RECS), true)
             ? $_POST['recurrence'] : 'mensuelle';

        if ($lib === '') {
            flash('Le libellé est obligatoire.', 'error');
        } else {
            $data = [
                mb_substr($lib, 0, 160),
                in_array($_POST['categorie'] ?? '', array_keys($CATS), true) ? $_POST['categorie'] : 'autre',
                mb_substr(trim((string)($_POST['description'] ?? '')), 0, 800),
                mb_substr(trim((string)($_POST['organisme'] ?? '')), 0, 120),
                $rec,
                max(1, min(31, (int)($_POST['jour_du_mois'] ?? 15))),
                $rec === 'mensuelle' ? null : max(1, min(12, (int)($_POST['mois'] ?? 1))),
                $rec === 'unique' && !empty($_POST['date_unique']) ? $_POST['date_unique'] : null,
                max(0, min(180, (int)($_POST['preavis_jours'] ?? 10))),
                max(0, (float)str_replace([' ', ','], ['', '.'], (string)($_POST['montant_estime'] ?? 0))),
                ($_POST['responsable_id'] ?? '') ?: null,
                isset($_POST['actif']) ? 1 : 0,
            ];

            if ($id) {
                $pdo->prepare("UPDATE echeances SET libelle=?, categorie=?, description=?, organisme=?,
                               recurrence=?, jour_du_mois=?, mois=?, date_unique=?, preavis_jours=?,
                               montant_estime=?, responsable_id=?, actif=? WHERE id=?")
                    ->execute([...$data, $id]);
                flash('Échéance modifiée.');
            } else {
                $pdo->prepare("INSERT INTO echeances (libelle, categorie, description, organisme,
                               recurrence, jour_du_mois, mois, date_unique, preavis_jours,
                               montant_estime, responsable_id, actif)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")->execute($data);
                flash('Échéance ajoutée. Elle apparaît désormais sur le cadran.');
            }
            journaliser($pdo, $id ? 'modification' : 'creation', 'échéance', $id ?: null, $lib);
        }
        header('Location: echeances.php'); exit;
    }

    /* Accomplissement d'une occurrence. */
    if (isset($_POST['marquer'])) {
        $ok = ech_marquer($pdo, (int)$_POST['marquer'], (string)$_POST['periode'],
                          (string)$_POST['echue_le'],
                          (float)str_replace([' ', ','], ['', '.'], (string)($_POST['montant_reel'] ?? 0)),
                          (string)($_POST['note'] ?? ''));
        flash($ok ? 'Échéance marquée comme accomplie. ✅' : 'Enregistrement impossible.', $ok ? 'success' : 'error');
        header('Location: echeances.php'); exit;
    }

    if (isset($_POST['annuler'])) {
        ech_annuler($pdo, (int)$_POST['annuler'], (string)$_POST['periode']);
        flash('Marquage annulé : l\'échéance redevient à traiter.');
        header('Location: echeances.php'); exit;
    }

    if (isset($_POST['supprimer'])) {
        $pdo->prepare('DELETE FROM echeances WHERE id=?')->execute([(int)$_POST['supprimer']]);
        flash('Échéance supprimée.');
        header('Location: echeances.php'); exit;
    }

    /* Installation des modèles, au premier lancement. */
    if (isset($_POST['modeles'])) {
        $ins = $pdo->prepare("INSERT INTO echeances (libelle, categorie, organisme, recurrence,
                              jour_du_mois, mois, preavis_jours, actif, ordre)
                              VALUES (?,?,?,?,?,?,?,0,?)");
        $n = 0;
        foreach (ech_modeles() as $i => [$lib, $cat, $org, $rec, $jour, $mois, $preavis]) {
            $ins->execute([$lib, $cat, $org, $rec, $jour, $mois, $preavis, $i]);
            $n++;
        }
        flash($n . ' modèles ajoutés — INACTIFS. Vérifiez chaque date auprès de votre comptable, '
            . 'puis activez ceux qui vous concernent.');
        header('Location: echeances.php'); exit;
    }
}

/* ---------------------------------------------------------------- Lecture -- */
$annee = (int)($_GET['a'] ?? date('Y'));
if ($annee < 2000 || $annee > 2100) $annee = (int)date('Y');

$toutes   = ech_annee($pdo, $annee, false);
$aTraiter = ech_a_traiter($pdo);
$compteur = ech_compteur($pdo);

$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM echeances WHERE id=?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
}

$employes = [];
try {
    $employes = $pdo->query("SELECT id, nom FROM users WHERE role IN ('admin','employe') AND actif=1
                             ORDER BY nom")->fetchAll();
} catch (Throwable $e) {}

$moisFr = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
           'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
$moisAbr = ['', 'JAN', 'FÉV', 'MAR', 'AVR', 'MAI', 'JUIN',
            'JUIL', 'AOÛT', 'SEP', 'OCT', 'NOV', 'DÉC'];

/* Occurrences réparties par mois, pour le cadran. */
$parMois = array_fill(1, 12, []);
foreach ($toutes as $e) {
    if (empty($e['actif'])) continue;
    foreach ($e['occurrences'] as $o) {
        $m = (int)date('n', strtotime($o['date']));
        $parMois[$m][] = $o + ['libelle' => $e['libelle'], 'categorie' => $e['categorie'],
                               'id' => $e['id'], 'organisme' => $e['organisme']];
    }
}

$devise = $settings['devise'] ?? 'FCFA';
admin_header('Échéances & Rappels', 'echeances', $pdo, $settings);
?>

<!-- ====================== BANDEAU D'ALERTE ====================== -->
<?php if ($compteur['total'] > 0): ?>
<div class="ech-alerte <?= $compteur['retard'] > 0 ? 'rouge' : ($compteur['aujourdhui'] > 0 ? 'ambre' : 'bleu') ?>">
  <span class="ea-ico"><?= $compteur['retard'] > 0 ? '⚠️' : '🔔' ?></span>
  <div>
    <strong>
      <?php
        $bouts = [];
        if ($compteur['retard'])     $bouts[] = $compteur['retard'] . ' en retard';
        if ($compteur['aujourdhui']) $bouts[] = $compteur['aujourdhui'] . " aujourd'hui";
        if ($compteur['proche'])     $bouts[] = $compteur['proche'] . ' bientôt';
        echo implode(' · ', $bouts);
      ?>
    </strong>
    <span>Ces obligations attendent votre action.</span>
  </div>
</div>
<?php endif; ?>

<div class="ech-haut">

  <!-- ====================== CADRAN ANNUEL ====================== -->
  <div class="panel glass ech-cadran-bloc">
    <div class="mod-tete">
      <h2 style="margin:0">🕓 L'année <?= $annee ?></h2>
      <div class="ech-nav">
        <a class="btn btn-glass btn-sm" href="?a=<?= $annee - 1 ?>">←</a>
        <?php if ($annee !== (int)date('Y')): ?>
        <a class="btn btn-glass btn-sm" href="echeances.php">Cette année</a>
        <?php endif; ?>
        <a class="btn btn-glass btn-sm" href="?a=<?= $annee + 1 ?>">→</a>
      </div>
    </div>

    <?php
      /* ------------------------------------------------------------------
         L'HORLOGE

         Deux lectures superposées dans un même cadran :

           — la COURONNE extérieure porte l'année : douze secteurs, une
             graduation par jour, et les échéances posées à leur date ;
           — le CŒUR est une véritable horloge dont les aiguilles tournent
             en direct.

         C'est le même geste : lever les yeux vers une horloge pour savoir
         où l'on en est. Ici on apprend l'heure ET la place dans l'année.

         Tout est tracé en SVG — aucune bibliothèque à charger.
         ------------------------------------------------------------------ */
      $cx = 250; $cy = 250;
      $R        = 236;   // bord extérieur
      $rMois    = 208;   // anneau des noms de mois
      $rJours   = 190;   // graduations journalières
      $rPoints  = 176;   // premier anneau d'échéances
      $rHorloge = 118;   // cadran de l'horloge

      $jourAn   = (int)date('z') + 1;
      $totalJrs = ((int)date('L')) ? 366 : 365;
      $estCetteAnnee = $annee === (int)date('Y');
      $partAnnee = $jourAn / $totalJrs;
    ?>
    <div class="ech-horloge" id="horloge">
      <svg viewBox="0 0 500 500" aria-label="Horloge des échéances — année <?= $annee ?>">
        <defs>
          <radialGradient id="hFond" cx="50%" cy="46%">
            <stop offset="0%"   stop-color="rgba(212,165,38,.05)"/>
            <stop offset="62%"  stop-color="rgba(255,255,255,0)"/>
            <stop offset="100%" stop-color="rgba(212,165,38,.09)"/>
          </radialGradient>
          <linearGradient id="hLaiton" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%"   stop-color="#f7dd9a"/>
            <stop offset="45%"  stop-color="#d4a526"/>
            <stop offset="100%" stop-color="#8a6a12"/>
          </linearGradient>
          <linearGradient id="hParcouru" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%"   stop-color="rgba(240,193,75,.75)"/>
            <stop offset="100%" stop-color="rgba(212,165,38,.3)"/>
          </linearGradient>
          <filter id="hLueur" x="-60%" y="-60%" width="220%" height="220%">
            <feGaussianBlur stdDeviation="4" result="f"/>
            <feMerge><feMergeNode in="f"/><feMergeNode in="SourceGraphic"/></feMerge>
          </filter>
          <filter id="hOmbre" x="-40%" y="-40%" width="180%" height="180%">
            <feDropShadow dx="0" dy="3" stdDeviation="4" flood-color="#000" flood-opacity=".5"/>
          </filter>

          <?php /* Le cadran est un objet, pas un calque : il porte sa propre
                   matière sombre. Sans elle, en thème clair, les graduations
                   et les noms de mois — tracés en blanc — disparaissaient
                   dans la page. Une horloge murale ne change pas de couleur
                   selon le mur sur lequel on l'accroche. */ ?>
          <radialGradient id="hCadran" cx="50%" cy="40%">
            <stop offset="0%"   stop-color="#1b2740"/>
            <stop offset="58%"  stop-color="#111c31"/>
            <stop offset="100%" stop-color="#070e1c"/>
          </radialGradient>
        </defs>

        <?php /* Boîtier : le disque de fond, puis trois cercles concentriques
                 qui lui donnent son épaisseur. */ ?>
        <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $R ?>" fill="url(#hCadran)"
                filter="url(#hOmbre)"/>
        <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $R ?>" fill="url(#hFond)"/>
        <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $R ?>" fill="none"
                stroke="url(#hLaiton)" stroke-width="2.5" opacity=".55"/>
        <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $R - 7 ?>" fill="none"
                stroke="rgba(255,255,255,.07)" stroke-width="1"/>
        <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $rJours ?>" fill="none"
                stroke="rgba(255,255,255,.08)" stroke-width="1"/>

        <?php
        /* Arc de l'année écoulée : on voit d'un coup ce qui reste devant soi. */
        if ($estCetteAnnee):
            $rArc = $R - 3.5;
            $angFin = $partAnnee * 360 - 90;
            $xF = $cx + $rArc * cos(deg2rad($angFin));
            $yF = $cy + $rArc * sin(deg2rad($angFin));
            $grand = $partAnnee > 0.5 ? 1 : 0;
        ?>
        <path d="M <?= $cx ?> <?= $cy - $rArc ?>
                 A <?= $rArc ?> <?= $rArc ?> 0 <?= $grand ?> 1 <?= round($xF,1) ?> <?= round($yF,1) ?>"
              fill="none" stroke="url(#hParcouru)" stroke-width="4" stroke-linecap="round"
              filter="url(#hLueur)"/>
        <?php endif; ?>

        <?php
        /* Graduation journalière : 365 traits fins. Le premier de chaque mois
           est plus long — c'est ce qui donne l'aspect d'un instrument. */
        for ($j = 0; $j < $totalJrs; $j++):
            $a = deg2rad($j / $totalJrs * 360 - 90);
            $estDebutMois = false;
            $ts = mktime(0, 0, 0, 1, $j + 1, $annee);
            if ((int)date('j', $ts) === 1) $estDebutMois = true;
            $len = $estDebutMois ? 13 : (($j % 5 === 0) ? 7 : 4);
            $op  = $estDebutMois ? '.55' : (($j % 5 === 0) ? '.26' : '.13');
        ?>
        <line x1="<?= round($cx + $rJours * cos($a), 1) ?>"
              y1="<?= round($cy + $rJours * sin($a), 1) ?>"
              x2="<?= round($cx + ($rJours - $len) * cos($a), 1) ?>"
              y2="<?= round($cy + ($rJours - $len) * sin($a), 1) ?>"
              stroke="#f0c14b" stroke-width="<?= $estDebutMois ? '1.8' : '1' ?>" opacity="<?= $op ?>"/>
        <?php endfor; ?>

        <?php
        /* Noms des mois, posés au milieu de leur secteur. */
        for ($m = 1; $m <= 12; $m++):
            $debutM = (int)date('z', mktime(0,0,0,$m,1,$annee));
            $nbJ    = (int)date('t', mktime(0,0,0,$m,1,$annee));
            $am = deg2rad(($debutM + $nbJ / 2) / $totalJrs * 360 - 90);
            $courant = $estCetteAnnee && $m === (int)date('n');
        ?>
        <text x="<?= round($cx + $rMois * cos($am), 1) ?>"
              y="<?= round($cy + $rMois * sin($am) + 4, 1) ?>"
              text-anchor="middle" font-size="<?= $courant ? '13' : '11.5' ?>"
              letter-spacing="1"
              fill="<?= $courant ? '#f7dd9a' : 'rgba(255,255,255,.42)' ?>"
              font-weight="<?= $courant ? '700' : '400' ?>"><?= $moisAbr[$m] ?></text>
        <?php endfor; ?>

        <?php
        /* Les échéances. Chacune est posée à sa date exacte, sur l'un des
           trois anneaux disponibles pour éviter les recouvrements.
           Sa couleur est celle de sa catégorie ; son état la nuance. */
        $compteJour = [];
        foreach ($parMois as $m => $occ):
            foreach ($occ as $o):
                $ts = strtotime($o['date']);
                $jz = (int)date('z', $ts);
                $ang = deg2rad($jz / $totalJrs * 360 - 90);

                $cle = $m . '-' . (int)date('j', $ts);
                $rang = $compteJour[$cle] ?? 0;
                $compteJour[$cle] = $rang + 1;
                $r = $rPoints - ($rang % 3) * 21;

                $coulEtat = ['retard' => '#ff4d5e', 'aujourdhui' => '#f0b429',
                             'proche' => '#f0c14b', 'fait' => '#10b981'][$o['etat']] ?? null;
                $coul = $coulEtat ?: ech_couleur($o['categorie']);
                $x = round($cx + $r * cos($ang), 1);
                $y = round($cy + $r * sin($ang), 1);
        ?>
          <?php /* Tige reliant le point à la couronne : on lit la date sans effort. */ ?>
          <line x1="<?= round($cx + ($rJours - 4) * cos($ang), 1) ?>"
                y1="<?= round($cy + ($rJours - 4) * sin($ang), 1) ?>"
                x2="<?= $x ?>" y2="<?= $y ?>"
                stroke="<?= $coul ?>" stroke-width="1" opacity=".22"/>
          <circle class="ec-pt <?= e($o['etat']) ?>" cx="<?= $x ?>" cy="<?= $y ?>" r="6"
                  fill="<?= $coul ?>" filter="url(#hLueur)"
                  data-lib="<?= e($o['libelle']) ?>"
                  data-date="<?= date('j', $ts) . ' ' . mb_strtolower($moisFr[$m]) ?>"
                  data-etat="<?= e(ech_delai($o['jours'])) ?>">
            <title><?= e($o['libelle']) ?> — <?= date('d/m/Y', $ts) ?></title>
          </circle>
        <?php endforeach; endforeach; ?>

        <?php /* ---------- L'HORLOGE, au cœur ---------- */ ?>
        <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $rHorloge ?>"
                fill="rgba(4,10,22,.72)" stroke="url(#hLaiton)" stroke-width="1.6"
                opacity=".95" filter="url(#hOmbre)"/>
        <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $rHorloge - 6 ?>" fill="none"
                stroke="rgba(255,255,255,.06)" stroke-width="1"/>

        <?php
        /* Graduations de l'horloge : soixante minutes, douze heures marquées. */
        for ($k = 0; $k < 60; $k++):
            $a = deg2rad($k * 6 - 90);
            $heure = $k % 5 === 0;
            $r1 = $rHorloge - 12;
            $r2 = $rHorloge - ($heure ? 24 : 18);
        ?>
        <line x1="<?= round($cx + $r1 * cos($a), 1) ?>" y1="<?= round($cy + $r1 * sin($a), 1) ?>"
              x2="<?= round($cx + $r2 * cos($a), 1) ?>" y2="<?= round($cy + $r2 * sin($a), 1) ?>"
              stroke="<?= $heure ? '#f0c14b' : 'rgba(255,255,255,.4)' ?>"
              stroke-width="<?= $heure ? '2.4' : '1' ?>"
              opacity="<?= $heure ? '.8' : '.3' ?>" stroke-linecap="round"/>
        <?php endfor; ?>

        <?php
        /* Chiffres des heures. */
        for ($h = 1; $h <= 12; $h++):
            $a = deg2rad($h * 30 - 90);
            $rc = $rHorloge - 40;
        ?>
        <text x="<?= round($cx + $rc * cos($a), 1) ?>"
              y="<?= round($cy + $rc * sin($a) + 4.5, 1) ?>"
              text-anchor="middle" font-size="12.5" fill="rgba(255,255,255,.5)"
              font-weight="500"><?= $h ?></text>
        <?php endfor; ?>

        <?php
        /* Les aiguilles, en fuseaux.

           Elles étaient d'abord de simples lignes verticales — et l'aiguille
           des heures restait invisible : un dégradé ne peut pas peindre une
           forme dont la largeur est nulle, ce qui est le cas d'une ligne
           strictement verticale. Un fuseau a une vraie surface, donc un vrai
           dégradé, et il a l'allure d'une aiguille d'horloge.

           Leur rotation est pilotée par le script. */
        $ph = $cy - $rHorloge + 50;   // pointe des heures
        $pm = $cy - $rHorloge + 22;   // pointe des minutes
        ?>
        <g id="aig-h" style="transform-origin:<?= $cx ?>px <?= $cy ?>px" filter="url(#hOmbre)">
          <path d="M <?= $cx ?> <?= $ph ?>
                   L <?= $cx + 4.5 ?> <?= $cy - 18 ?>
                   L <?= $cx + 3 ?> <?= $cy + 16 ?>
                   L <?= $cx - 3 ?> <?= $cy + 16 ?>
                   L <?= $cx - 4.5 ?> <?= $cy - 18 ?> Z"
                fill="url(#hLaiton)" stroke="rgba(0,0,0,.25)" stroke-width=".5"/>
        </g>
        <g id="aig-m" style="transform-origin:<?= $cx ?>px <?= $cy ?>px" filter="url(#hOmbre)">
          <path d="M <?= $cx ?> <?= $pm ?>
                   L <?= $cx + 3 ?> <?= $cy - 22 ?>
                   L <?= $cx + 2 ?> <?= $cy + 20 ?>
                   L <?= $cx - 2 ?> <?= $cy + 20 ?>
                   L <?= $cx - 3 ?> <?= $cy - 22 ?> Z"
                fill="#f7dd9a" stroke="rgba(0,0,0,.2)" stroke-width=".5"/>
        </g>
        <g id="aig-s" style="transform-origin:<?= $cx ?>px <?= $cy ?>px">
          <?php /* La trotteuse garde un contrepoids, comme sur une vraie montre. */ ?>
          <rect x="<?= $cx - 0.8 ?>" y="<?= $cy - $rHorloge + 14 ?>" width="1.6"
                height="<?= $rHorloge + 14 ?>" fill="#ff4d5e" rx=".8"/>
          <circle cx="<?= $cx ?>" cy="<?= $cy + 26 ?>" r="4" fill="#ff4d5e"/>
        </g>

        <?php /* Axe central, en dernier pour couvrir le pied des aiguilles. */ ?>
        <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="7" fill="url(#hLaiton)"/>
        <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="2.6" fill="#0a1020"/>

        <?php
        /* L'aiguille de l'année : la plus lente de toutes, elle pointe le jour
           sur la couronne. Elle ne bouge visiblement qu'une fois par jour. */
        if ($estCetteAnnee):
            $angAn = deg2rad($partAnnee * 360 - 90);
        ?>
        <line x1="<?= round($cx + ($rHorloge + 4) * cos($angAn), 1) ?>"
              y1="<?= round($cy + ($rHorloge + 4) * sin($angAn), 1) ?>"
              x2="<?= round($cx + ($rJours - 2) * cos($angAn), 1) ?>"
              y2="<?= round($cy + ($rJours - 2) * sin($angAn), 1) ?>"
              stroke="#f0c14b" stroke-width="2.4" stroke-linecap="round"
              opacity=".9" filter="url(#hLueur)"/>
        <circle cx="<?= round($cx + $rJours * cos($angAn), 1) ?>"
                cy="<?= round($cy + $rJours * sin($angAn), 1) ?>" r="4.5" fill="#f7dd9a"/>
        <?php endif; ?>
      </svg>

      <div class="ec-bulle" id="ec-bulle" hidden></div>
    </div>

    <?php
      /* Sous le cadran, l'essentiel en clair. Le guichet de date était placé
         dans l'horloge, à la façon d'une montre — mais les aiguilles le
         traversaient. Ici rien ne le couvre, et l'on gagne l'information que
         cette page doit donner : ce qui vient ensuite.

         $aTraiter est déjà trié du plus urgent au moins urgent. */
      $suivante = $aTraiter[0] ?? null;
      if (!$suivante) {
          foreach ($toutes as $eL) {
              if (empty($eL['actif'])) continue;
              foreach ($eL['occurrences'] as $oL) {
                  if ($oL['etat'] === ECH_A_VENIR
                      && ($suivante === null || $oL['jours'] < $suivante['jours'])) {
                      $suivante = $eL + $oL;
                  }
              }
          }
      }
    ?>
    <div class="ec-barre">
      <div class="eb-jour">
        <span class="eb-n" id="eb-heure"><?= date('H:i') ?></span>
        <span class="eb-l"><?= $estCetteAnnee
            ? date('j') . ' ' . mb_strtolower($moisFr[(int)date('n')]) . ' ' . date('Y')
            : 'Année ' . $annee ?></span>
      </div>
      <?php if ($suivante): ?>
      <div class="eb-suite <?= e($suivante['etat']) ?>">
        <span class="eb-t">Prochaine échéance</span>
        <strong><?= e($suivante['libelle']) ?></strong>
        <span class="eb-q"><?= e(ech_delai($suivante['jours'])) ?>
          · <?= date('d/m/Y', strtotime($suivante['date'])) ?></span>
      </div>
      <?php else: ?>
      <div class="eb-suite calme">
        <span class="eb-t">Prochaine échéance</span>
        <strong>Rien en vue</strong>
        <span class="eb-q">Aucune obligation enregistrée pour cette période</span>
      </div>
      <?php endif; ?>
    </div>

    <div class="ec-legende">
      <span><i style="background:#ff4d5e"></i>En retard</span>
      <span><i style="background:#f0b429"></i>Imminent</span>
      <span><i class="cat"></i>À venir (couleur de sa catégorie)</span>
      <span><i style="background:#10b981"></i>Accompli</span>
    </div>
  </div>

  <!-- ====================== À TRAITER ====================== -->
  <div class="panel glass">
    <div class="mod-tete">
      <h2 style="margin:0">🔔 À traiter <span class="cnt"><?= count($aTraiter) ?></span></h2>
    </div>

    <?php if (!$aTraiter): ?>
    <p class="ech-vide">
      <?= $toutes ? 'Rien ne presse : aucune échéance dans les prochains jours.'
                  : 'Aucune échéance enregistrée. Ajoutez-en une ci-dessous.' ?>
    </p>
    <?php else: ?>
    <div class="ech-liste defilant">
      <?php foreach ($aTraiter as $o): [$ic, $lbl] = $CATS[$o['categorie']] ?? ['📌', 'Autre']; ?>
      <div class="eo <?= e($o['etat']) ?>">
        <span class="eo-ico"><?= $ic ?></span>
        <div class="eo-c">
          <div class="eo-t"><?= e($o['libelle']) ?></div>
          <div class="eo-m">
            <span class="eo-d"><?= date('d/m/Y', strtotime($o['date'])) ?></span>
            <span class="eo-q"><?= e(ech_delai($o['jours'])) ?></span>
            <?php if ($o['organisme']): ?><span class="eo-o"><?= e($o['organisme']) ?></span><?php endif; ?>
            <?php if ($o['montant_estime'] > 0): ?>
            <span class="eo-mt">≈ <?= number_format((float)$o['montant_estime'], 0, ',', ' ') ?> <?= e($devise) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <form method="post" class="eo-f">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="periode" value="<?= e($o['periode']) ?>">
          <input type="hidden" name="echue_le" value="<?= e($o['date']) ?>">
          <button class="eo-b" name="marquer" value="<?= (int)$o['id'] ?>" title="Marquer comme fait">✓</button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ====================== LISTE COMPLÈTE ====================== -->
<div class="panel glass">
  <div class="mod-tete">
    <h2 style="margin:0">📋 Toutes les échéances <span class="cnt"><?= count($toutes) ?></span></h2>
    <?php if (!$toutes): ?>
    <form method="post" style="margin-left:auto">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <button class="btn btn-glass btn-sm" name="modeles" value="1">
        📥 Installer des modèles courants</button>
    </form>
    <?php endif; ?>
  </div>

  <?php if (!$toutes): ?>
  <p class="ech-vide">
    Aucune échéance. Les modèles vous donnent une base — ils sont créés
    <strong>inactifs</strong>, avec des dates à confirmer auprès de votre comptable.
  </p>
  <?php else: ?>
  <div class="ech-liste defilant">
    <?php foreach ($toutes as $e):
      [$ic, $lbl] = $CATS[$e['categorie']] ?? ['📌', 'Autre'];
      /* Prochaine occurrence non accomplie : c'est elle qui intéresse. */
      $prochaine = null;
      foreach ($e['occurrences'] as $o) {
        if ($o['etat'] !== ECH_FAIT && ($prochaine === null || $o['jours'] < $prochaine['jours'])) {
          if ($o['jours'] >= 0 || $prochaine === null) $prochaine = $o;
        }
      }
      $faites = count(array_filter($e['occurrences'], fn($o) => $o['etat'] === ECH_FAIT));
    ?>
    <div class="ee <?= empty($e['actif']) ? 'inactive' : '' ?>">
      <span class="ee-ico"><?= $ic ?></span>
      <div class="ee-c">
        <div class="ee-t">
          <strong><?= e($e['libelle']) ?></strong>
          <?php if (empty($e['actif'])): ?><span class="ee-off">Inactive</span><?php endif; ?>
        </div>
        <div class="ee-m">
          <span><?= e($RECS[$e['recurrence']] ?? $e['recurrence']) ?></span>
          <?php if ($e['organisme']): ?><span>· <?= e($e['organisme']) ?></span><?php endif; ?>
          <?php if ($e['responsable']): ?><span>· <?= e($e['responsable']) ?></span><?php endif; ?>
          <span>· préavis <?= (int)$e['preavis_jours'] ?> j</span>
          <?php if ($faites): ?><span class="ee-ok">· <?= $faites ?> accomplie<?= $faites > 1 ? 's' : '' ?> en <?= $annee ?></span><?php endif; ?>
        </div>
      </div>
      <?php if ($prochaine && !empty($e['actif'])): ?>
      <span class="ee-p <?= e($prochaine['etat']) ?>"><?= date('d/m', strtotime($prochaine['date'])) ?></span>
      <?php endif; ?>
      <div class="ee-a">
        <a class="eo-b" href="?edit=<?= (int)$e['id'] ?>#form" title="Modifier">✏️</a>
        <form method="post" style="display:inline"
              data-confirm="Supprimer « <?= e($e['libelle']) ?> » ? L'historique des accomplissements sera effacé aussi.">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <button class="eo-b sup" name="supprimer" value="<?= (int)$e['id'] ?>" title="Supprimer">✕</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ====================== FORMULAIRE ====================== -->
<?php /* Le formulaire reste replié : la page s'ouvre sur ce qui compte —
         l'horloge et les échéances — pas sur une vingtaine de champs vides.
         Une modification en cours le déplie d'office. */ ?>
<details class="panel glass ech-form" id="form" <?= $edit ? 'open' : '' ?>>
  <summary class="ech-form-tete">
    <span class="ef-plus">+</span>
    <span class="ef-t"><?= $edit ? 'Modifier « ' . e($edit['libelle']) . ' »' : 'Ajouter une échéance' ?></span>
    <span class="ef-chev">▾</span>
  </summary>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>

    <div class="field full"><label>Libellé *</label>
      <input class="input" name="libelle" required maxlength="160"
             value="<?= e($edit['libelle'] ?? '') ?>"
             placeholder="ex : Déclaration de TVA"></div>

    <div class="field"><label>Catégorie</label>
      <select class="input" name="categorie">
        <?php foreach ($CATS as $k => [$i, $l]): ?>
        <option value="<?= $k ?>" <?= ($edit['categorie'] ?? 'fiscal') === $k ? 'selected' : '' ?>>
          <?= $i ?> <?= $l ?></option>
        <?php endforeach; ?>
      </select></div>

    <div class="field"><label>Organisme</label>
      <input class="input" name="organisme" maxlength="120"
             value="<?= e($edit['organisme'] ?? '') ?>" placeholder="ex : DGI, CNPS, Mairie"></div>

    <div class="field"><label>Périodicité</label>
      <select class="input" name="recurrence" id="recSel">
        <?php foreach ($RECS as $k => $l): ?>
        <option value="<?= $k ?>" <?= ($edit['recurrence'] ?? 'mensuelle') === $k ? 'selected' : '' ?>>
          <?= $l ?></option>
        <?php endforeach; ?>
      </select></div>

    <div class="field" id="champMois" hidden><label>Mois</label>
      <select class="input" name="mois">
        <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>" <?= (int)($edit['mois'] ?? 1) === $m ? 'selected' : '' ?>><?= $moisFr[$m] ?></option>
        <?php endfor; ?>
      </select>
      <span class="ech-aide" id="aideMois"></span></div>

    <div class="field" id="champJour"><label>Jour du mois</label>
      <input class="input" type="number" name="jour_du_mois" min="1" max="31"
             value="<?= (int)($edit['jour_du_mois'] ?? 15) ?>">
      <span class="ech-aide">Le 31 devient automatiquement le dernier jour des mois plus courts.</span></div>

    <div class="field" id="champDate" hidden><label>Date</label>
      <input class="input" type="date" name="date_unique" value="<?= e($edit['date_unique'] ?? '') ?>"></div>

    <div class="field"><label>Prévenir combien de jours avant</label>
      <input class="input" type="number" name="preavis_jours" min="0" max="180"
             value="<?= (int)($edit['preavis_jours'] ?? 10) ?>">
      <span class="ech-aide">L'alerte apparaît à partir de ce délai.</span></div>

    <div class="field"><label>Montant estimé</label>
      <input class="input" name="montant_estime" inputmode="numeric"
             value="<?= (float)($edit['montant_estime'] ?? 0) ?: '' ?>" placeholder="facultatif"></div>

    <div class="field"><label>Responsable</label>
      <select class="input" name="responsable_id">
        <option value="">— Personne en particulier —</option>
        <?php foreach ($employes as $em): ?>
        <option value="<?= (int)$em['id'] ?>" <?= (int)($edit['responsable_id'] ?? 0) === (int)$em['id'] ? 'selected' : '' ?>>
          <?= e($em['nom']) ?></option>
        <?php endforeach; ?>
      </select></div>

    <div class="field full"><label>Note</label>
      <textarea class="input" name="description" style="min-height:70px"
                placeholder="Pièces à fournir, référence du dossier, contact…"><?= e($edit['description'] ?? '') ?></textarea></div>

    <div class="field full">
      <label class="check">
        <input type="checkbox" name="actif" value="1" <?= ($edit === null || !empty($edit['actif'])) ? 'checked' : '' ?>>
        <span>Active — elle apparaît sur le cadran et déclenche les alertes</span>
      </label>
    </div>

    <div class="full" style="display:flex;gap:9px;flex-wrap:wrap">
      <button class="btn btn-gold"><?= $edit ? 'Enregistrer' : 'Ajouter' ?></button>
      <?php if ($edit): ?><a class="btn btn-glass" href="echeances.php">Annuler</a><?php endif; ?>
      <button type="submit" name="enregistrer" value="1" hidden></button>
    </div>
    <input type="hidden" name="enregistrer" value="1">
  </form>
</details>

<script>
(function () {
  /* Les champs proposés suivent la périodicité : demander un mois pour une
     échéance mensuelle n'aurait pas de sens. */
  var sel = document.getElementById('recSel');
  if (!sel) return;

  var champMois = document.getElementById('champMois');
  var champJour = document.getElementById('champJour');
  var champDate = document.getElementById('champDate');
  var aideMois  = document.getElementById('aideMois');

  var aides = {
    trimestrielle: 'Mois de départ : les trois autres suivent de trois en trois.',
    semestrielle:  'Mois de départ : la seconde tombe six mois plus tard.',
    annuelle:      'Le mois où l\'obligation tombe.'
  };

  function ajuster() {
    var r = sel.value;
    champMois.hidden = (r === 'mensuelle' || r === 'unique');
    champJour.hidden = (r === 'unique');
    champDate.hidden = (r !== 'unique');
    if (aideMois) aideMois.textContent = aides[r] || '';
  }
  sel.addEventListener('change', ajuster);
  ajuster();
})();

(function () {
  /* ------------------------------------------------------------------
     LES AIGUILLES

     Elles tournent réellement. L'heure vient du poste de l'utilisateur,
     pas du serveur : c'est l'heure qu'il a sous les yeux ailleurs sur son
     écran, et un décalage serait déroutant.

     L'aiguille des secondes avance en continu plutôt que par à-coups —
     le mouvement est plus doux et coûte le même effort.
     ------------------------------------------------------------------ */
  var aH = document.getElementById('aig-h');
  var aM = document.getElementById('aig-m');
  var aS = document.getElementById('aig-s');
  if (!aH || !aM || !aS) return;

  var doux = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var horloge = document.getElementById('horloge');
  var image = null;

  function placer() {
    var d = new Date();
    var s = d.getSeconds() + d.getMilliseconds() / 1000;
    var m = d.getMinutes() + s / 60;
    var h = (d.getHours() % 12) + m / 60;

    aH.style.transform = 'rotate(' + (h * 30) + 'deg)';
    aM.style.transform = 'rotate(' + (m * 6) + 'deg)';
    aS.style.transform = 'rotate(' + (s * 6) + 'deg)';
  }

  /* L'heure en clair, sous le cadran, suit les aiguilles. */
  var champHeure = document.getElementById('eb-heure');
  var derniereMin = -1;
  function majHeure() {
    var d = new Date();
    if (d.getMinutes() === derniereMin) return;
    derniereMin = d.getMinutes();
    if (champHeure) {
      champHeure.textContent = ('0' + d.getHours()).slice(-2) + ':'
                             + ('0' + d.getMinutes()).slice(-2);
    }
  }

  function boucle() { placer(); majHeure(); image = requestAnimationFrame(boucle); }

  placer();
  if (doux) {
    image = requestAnimationFrame(boucle);

    /* On arrête tout quand l'onglet passe en arrière-plan : animer une
       horloge que personne ne regarde consomme de la batterie pour rien. */
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) { cancelAnimationFrame(image); }
      else { image = requestAnimationFrame(boucle); }
    });
  } else {
    /* Mouvement réduit : on se contente d'un rafraîchissement par minute. */
    setInterval(function(){ placer(); majHeure(); }, 60000);
  }
})();

(function () {
  /* Infobulle du cadran : au survol d'un point, on nomme l'échéance. */
  var bulle = document.getElementById('ec-bulle');
  if (!bulle) return;

  document.querySelectorAll('.ec-pt').forEach(function (p) {
    p.addEventListener('mouseenter', function () {
      bulle.innerHTML = '<strong>' + this.dataset.lib + '</strong>'
                      + '<span>' + this.dataset.date + ' — ' + this.dataset.etat + '</span>';
      bulle.hidden = false;
      var r = this.getBoundingClientRect();
      var c = this.closest('.ech-cadran').getBoundingClientRect();
      bulle.style.left = (r.left - c.left + r.width / 2) + 'px';
      bulle.style.top  = (r.top - c.top - 12) + 'px';
    });
    p.addEventListener('mouseleave', function () { bulle.hidden = true; });
  });
})();
</script>

<?php admin_footer(); ?>
