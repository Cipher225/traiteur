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
admin_header('Échéances', 'echeances', $pdo, $settings);
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
      /* Le cadran : douze secteurs, un par mois, et une aiguille sur le jour.
         Tracé en SVG pur — aucune bibliothèque à charger. */
      $R = 200; $cx = 230; $cy = 230;
      $jourAn   = (int)date('z') + 1;
      $totalJrs = (int)date('L') ? 366 : 365;
      $estCetteAnnee = $annee === (int)date('Y');
    ?>
    <div class="ech-cadran">
      <svg viewBox="0 0 460 460" aria-label="Cadran des échéances de l'année <?= $annee ?>">
        <defs>
          <radialGradient id="ecFond" cx="50%" cy="50%">
            <stop offset="60%" stop-color="rgba(255,255,255,0)"/>
            <stop offset="100%" stop-color="rgba(212,165,38,.07)"/>
          </radialGradient>
        </defs>

        <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $R ?>" fill="url(#ecFond)"/>
        <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $R - 46 ?>" fill="none"
                stroke="rgba(255,255,255,.07)" stroke-width="1"/>
        <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $R ?>" fill="none"
                stroke="rgba(255,255,255,.09)" stroke-width="1"/>

        <?php
        /* Séparateurs et noms des mois. Le premier janvier est en haut ;
           l'année tourne dans le sens des aiguilles d'une montre. */
        for ($m = 1; $m <= 12; $m++):
            $a0 = deg2rad(($m - 1) * 30 - 90);
            $am = deg2rad(($m - 1) * 30 + 15 - 90);
        ?>
          <line x1="<?= round($cx + ($R - 46) * cos($a0), 1) ?>"
                y1="<?= round($cy + ($R - 46) * sin($a0), 1) ?>"
                x2="<?= round($cx + $R * cos($a0), 1) ?>"
                y2="<?= round($cy + $R * sin($a0), 1) ?>"
                stroke="rgba(255,255,255,.1)" stroke-width="1"/>
          <text x="<?= round($cx + ($R - 23) * cos($am), 1) ?>"
                y="<?= round($cy + ($R - 23) * sin($am) + 4, 1) ?>"
                text-anchor="middle" font-size="11.5"
                fill="<?= $estCetteAnnee && $m === (int)date('n') ? '#f0c14b' : 'rgba(255,255,255,.45)' ?>"
                font-weight="<?= $estCetteAnnee && $m === (int)date('n') ? '700' : '400' ?>">
            <?= $moisAbr[$m] ?></text>
        <?php endfor; ?>

        <?php
        /* Chaque occurrence devient un point, posé à sa date exacte sur
           l'anneau. Sa couleur dit son état : rouge en retard, or imminent,
           vert accompli. */
        $rayons = [];
        foreach ($parMois as $m => $occ):
            foreach ($occ as $i => $o):
                $jour = (int)date('j', strtotime($o['date']));
                $dansMois = (int)date('t', strtotime($o['date']));
                $ang = deg2rad(($m - 1) * 30 + ($jour - 1) / $dansMois * 30 - 90);
                /* Plusieurs échéances le même mois se répartissent sur trois
                   anneaux, pour ne pas se recouvrir. */
                $r = $R - 62 - ($i % 3) * 19;
                $coul = ['retard' => '#f87171', 'aujourdhui' => '#f0b429',
                         'proche' => '#f0c14b', 'fait' => '#10b981',
                         'a_venir' => 'rgba(125,211,252,.75)'][$o['etat']] ?? '#94a3b8';
                $x = round($cx + $r * cos($ang), 1);
                $y = round($cy + $r * sin($ang), 1);
        ?>
          <circle class="ec-pt <?= e($o['etat']) ?>" cx="<?= $x ?>" cy="<?= $y ?>" r="5.5"
                  fill="<?= $coul ?>"
                  data-lib="<?= e($o['libelle']) ?>"
                  data-date="<?= date('j', strtotime($o['date'])) . ' ' . $moisFr[$m] ?>"
                  data-etat="<?= e(ech_delai($o['jours'])) ?>">
            <title><?= e($o['libelle']) ?> — <?= date('d/m/Y', strtotime($o['date'])) ?></title>
          </circle>
        <?php endforeach; endforeach; ?>

        <?php if ($estCetteAnnee):
          /* L'aiguille du jour : elle situe l'instant présent dans l'année. */
          $angJour = deg2rad(($jourAn - 1) / $totalJrs * 360 - 90);
        ?>
        <line x1="<?= $cx ?>" y1="<?= $cy ?>"
              x2="<?= round($cx + ($R - 8) * cos($angJour), 1) ?>"
              y2="<?= round($cy + ($R - 8) * sin($angJour), 1) ?>"
              stroke="#f0c14b" stroke-width="2" stroke-linecap="round" opacity=".85"/>
        <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="5" fill="#f0c14b"/>
        <?php endif; ?>

        <text x="<?= $cx ?>" y="<?= $cy - 14 ?>" text-anchor="middle" font-size="13"
              fill="rgba(255,255,255,.4)"><?= $estCetteAnnee ? "AUJOURD'HUI" : 'ANNÉE' ?></text>
        <text x="<?= $cx ?>" y="<?= $cy + 16 ?>" text-anchor="middle" font-size="21"
              fill="#fff" font-weight="700">
          <?= $estCetteAnnee ? date('j') . ' ' . mb_strtolower($moisAbr[(int)date('n')]) : $annee ?></text>
      </svg>

      <div class="ec-bulle" id="ec-bulle" hidden></div>
    </div>

    <div class="ec-legende">
      <span><i style="background:#f87171"></i>En retard</span>
      <span><i style="background:#f0b429"></i>Imminent</span>
      <span><i style="background:rgba(125,211,252,.75)"></i>À venir</span>
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
<div class="panel glass" id="form">
  <h2><?= $edit ? '✏️ Modifier l\'échéance' : '➕ Nouvelle échéance' ?></h2>
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
</div>

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
