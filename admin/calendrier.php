<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';

/* ============================================================================
   CALENDRIER DES ÉVÉNEMENTS
   Vue mensuelle des commandes clients (événements traiteur) placées sur leur
   date d'événement, colorées selon leur statut. Navigation mois précédent/suivant.
   ============================================================================ */

// Mois affiché (par défaut : mois courant)
$moisParam = $_GET['m'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $moisParam)) $moisParam = date('Y-m');
$annee = (int)substr($moisParam, 0, 4);
$mois  = (int)substr($moisParam, 5, 2);
$premierJour = mktime(0, 0, 0, $mois, 1, $annee);
$nbJours     = (int)date('t', $premierJour);
// Jour de la semaine du 1er (0=dimanche → on cale sur lundi=0)
$decalage = ((int)date('N', $premierJour)) - 1; // 0 (lundi) … 6 (dimanche)

// Mois précédent / suivant
$moisPrec = date('Y-m', mktime(0,0,0,$mois-1,1,$annee));
$moisSuiv = date('Y-m', mktime(0,0,0,$mois+1,1,$annee));

// Récupérer les événements du mois (commandes clients avec une date d'événement)
$debut = sprintf('%04d-%02d-01', $annee, $mois);
$fin   = sprintf('%04d-%02d-%02d', $annee, $mois, $nbJours);
$stmt = $pdo->prepare("
    SELECT cc.id, cc.numero, cc.date_evenement, cc.nb_invites, cc.lieu, cc.statut,
           COALESCE(NULLIF(c.entreprise,''), c.nom, 'Client') AS client,
           c.type_client, c.entreprise
    FROM commandes_client cc
    LEFT JOIN clients c ON c.id = cc.client_id
    WHERE cc.date_evenement BETWEEN ? AND ?
    ORDER BY cc.date_evenement, cc.id");
$stmt->execute([$debut, $fin]);
$evenements = $stmt->fetchAll();

// ---- Filtres de sources (memorises dans l'URL) ----
$sourcesDispo = [
    'evenements' => ['Événements',   '🎉'],
    'factures'   => ['Échéances',    '🧾'],
    'mouvements' => ['Entrées / Sorties', '💵'],
    'taches'     => ['Tâches',       '✅'],
];
$actives = isset($_GET['s']) ? array_intersect(explode(',', (string)$_GET['s']), array_keys($sourcesDispo))
                             : array_keys($sourcesDispo);
if (!$actives) $actives = array_keys($sourcesDispo);

// Regrouper par jour : chaque entree = [jour, type, titre, sous-titre, lien, classe]
$parJour = [];
$ajouter = function(?string $date, array $item) use (&$parJour, $debut, $fin) {
    if (!$date || $date < $debut || $date > $fin) return;
    $parJour[(int)date('j', strtotime($date))][] = $item;
};

if (in_array('evenements', $actives, true)) {
    foreach ($evenements as $ev) {
        $ajouter($ev['date_evenement'], [
            'type' => 'evenements', 'ico' => '🎉',
            'titre' => $ev['client'],
            'sous'  => ($ev['nb_invites'] ? '👥 ' . (int)$ev['nb_invites'] : ''),
            'lien'  => 'commandes-client.php?voir=' . (int)$ev['id'],
            'cls'   => $statutCls[$ev['statut']] ?? '',
            'info'  => ($statutLbl[$ev['statut']] ?? '') . ($ev['lieu'] ? ' — ' . $ev['lieu'] : ''),
        ]);
    }
}
// Echeances de factures et proformas non reglees
if (in_array('factures', $actives, true)) {
    $st = $pdo->prepare("SELECT f.id, f.numero, f.type, f.date_echeance, f.statut,
                                COALESCE(NULLIF(c.entreprise,''), c.nom, 'Client') AS client
                         FROM factures f LEFT JOIN clients c ON c.id = f.client_id
                         WHERE f.date_echeance BETWEEN ? AND ? AND f.statut <> 'annulee'");
    $st->execute([$debut, $fin]);
    foreach ($st->fetchAll() as $fa) {
        $ajouter($fa['date_echeance'], [
            'type' => 'factures', 'ico' => $fa['type'] === 'proforma' ? '📋' : '🧾',
            'titre' => $fa['numero'], 'sous' => $fa['client'],
            'lien'  => 'factures.php' . ($fa['type'] === 'proforma' ? '?doc=proforma' : ''),
            'cls'   => $fa['statut'] === 'payee' ? 'ev-terminee' : 'ev-devis',
            'info'  => 'Échéance — ' . ($fa['statut'] === 'payee' ? 'réglée' : 'à encaisser'),
        ]);
    }
}
// Entrees et sorties de caisse
if (in_array('mouvements', $actives, true)) {
    $st = $pdo->prepare("SELECT r.id, r.numero, r.type, r.date_paiement, r.montant,
                                COALESCE(NULLIF(c.entreprise,''), c.nom, '') AS client
                         FROM recus r LEFT JOIN clients c ON c.id = r.client_id
                         WHERE r.date_paiement BETWEEN ? AND ?");
    $st->execute([$debut, $fin]);
    foreach ($st->fetchAll() as $mv) {
        $entree = ($mv['type'] ?? '') === 'entree';
        $ajouter($mv['date_paiement'], [
            'type' => 'mouvements', 'ico' => $entree ? '📥' : '📤',
            'titre' => ($entree ? 'Entrée ' : 'Sortie ') . $mv['numero'],
            'sous'  => number_format((float)$mv['montant'], 0, ',', ' '),
            'lien'  => 'recus.php' . ($entree ? '?type=entree' : ''),
            'cls'   => $entree ? 'ev-confirmee' : 'ev-traitement',
            'info'  => $mv['client'],
        ]);
    }
}
// Taches a echeance
if (in_array('taches', $actives, true)) {
    $st = $pdo->prepare("SELECT id, titre, statut, date_limite FROM taches
                         WHERE date_limite BETWEEN ? AND ?");
    $st->execute([$debut, $fin]);
    foreach ($st->fetchAll() as $ta) {
        $ajouter($ta['date_limite'], [
            'type' => 'taches', 'ico' => $ta['statut'] === 'termine' ? '✅' : '⏳',
            'titre' => $ta['titre'], 'sous' => '',
            'lien'  => 'taches.php',
            'cls'   => $ta['statut'] === 'termine' ? 'ev-terminee' : 'ev-nouvelle',
            'info'  => 'Tâche — ' . str_replace('_', ' ', (string)$ta['statut']),
        ]);
    }
}
ksort($parJour);

// Statuts : libellés et couleurs
$statutLbl = [
    'nouvelle'=>'Nouvelle', 'en_traitement'=>'En traitement', 'devis_envoye'=>'Devis envoyé',
    'confirmee'=>'Confirmée', 'terminee'=>'Terminée', 'annulee'=>'Annulée'
];
$statutCls = [
    'nouvelle'=>'ev-nouvelle', 'en_traitement'=>'ev-traitement', 'devis_envoye'=>'ev-devis',
    'confirmee'=>'ev-confirmee', 'terminee'=>'ev-terminee', 'annulee'=>'ev-annulee'
];

$moisFr = ['', 'Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
$joursFr = ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
$auj = date('Y-m-d');

// Statistiques du mois
$nbConfirmes = count(array_filter($evenements, fn($e) => $e['statut']==='confirmee'));
$nbTotal = count($evenements);
$nbFiches = 0; foreach ($parJour as $lst) $nbFiches += count($lst);
$totalInvites = array_sum(array_column(array_filter($evenements, fn($e)=>in_array($e['statut'],['confirmee','terminee'])), 'nb_invites'));

admin_header('Calendrier', 'calendrier', $pdo, $settings);
?>

<div class="panel glass">
  <div class="cal-head">
    <a class="btn btn-glass" href="calendrier.php?m=<?= $moisPrec ?>">← <?= $moisFr[(int)date('n', mktime(0,0,0,$mois-1,1,$annee))] ?></a>
    <div class="cal-titre">
      <h2>📅 <?= $moisFr[$mois] ?> <?= $annee ?></h2>
      <div class="cal-stats">
        <span class="cal-stat"><strong><?= $nbFiches ?></strong> fiche<?= $nbFiches>1?'s':'' ?></span>
        <span class="cal-stat"><strong><?= $nbTotal ?></strong> événement<?= $nbTotal>1?'s':'' ?></span>
        <span class="cal-stat cal-stat-ok"><strong><?= $nbConfirmes ?></strong> confirmé<?= $nbConfirmes>1?'s':'' ?></span>
        <?php if ($totalInvites>0): ?><span class="cal-stat"><strong><?= number_format($totalInvites,0,',',' ') ?></strong> participants</span><?php endif; ?>
      </div>
    </div>
    <a class="btn btn-glass" href="calendrier.php?m=<?= $moisSuiv ?>"><?= $moisFr[(int)date('n', mktime(0,0,0,$mois+1,1,$annee))] ?> →</a>
  </div>

  <?php if ($moisParam !== date('Y-m')): ?>
  <div style="text-align:center;margin-bottom:12px"><a class="btn btn-gold btn-sm" href="calendrier.php">Revenir à aujourd'hui</a></div>
  <?php endif; ?>

  <div class="cal-filtres">
    <?php foreach ($sourcesDispo as $k => [$lbl, $ico]):
      $on = in_array($k, $actives, true);
      $autres = $on ? array_values(array_diff($actives, [$k])) : array_values(array_unique(array_merge($actives, [$k])));
      if (!$autres) $autres = array_keys($sourcesDispo);
      $url = 'calendrier.php?m=' . e($moisParam) . '&s=' . implode(',', $autres); ?>
    <a class="cal-filtre <?= $on ? 'on '.$k : '' ?>" href="<?= $url ?>"><?= $ico ?> <?= e($lbl) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="cal-grid">
    <?php foreach ($joursFr as $jn): ?><div class="cal-jour-nom"><?= $jn ?></div><?php endforeach; ?>

    <?php for ($i = 0; $i < $decalage; $i++): ?><div class="cal-case cal-vide"></div><?php endfor; ?>

    <?php for ($j = 1; $j <= $nbJours; $j++):
      $dateJ = sprintf('%04d-%02d-%02d', $annee, $mois, $j);
      $estAuj = ($dateJ === $auj);
      $evs = $parJour[$j] ?? [];
    ?>
    <div class="cal-case <?= $estAuj ? 'cal-auj' : '' ?> <?= !empty($evs) ? 'cal-plein' : '' ?>">
      <div class="cal-num"><?= $j ?><?= $estAuj ? ' <span class="cal-auj-tag">Aujourd\'hui</span>' : '' ?></div>
      <?php foreach (array_slice($evs, 0, 3) as $ev): ?>
      <a class="cal-ev <?= $ev['cls'] ?>" href="<?= $ev['lien'] ?>"
         title="<?= e($ev['titre']) ?><?= $ev['info'] ? ' — '.e($ev['info']) : '' ?>">
        <span class="cal-ev-client"><?= $ev['ico'] ?> <?= e($ev['titre']) ?></span>
        <?php if ($ev['sous'] !== ''): ?><span class="cal-ev-inv"><?= e($ev['sous']) ?></span><?php endif; ?>
      </a>
      <?php endforeach; ?>
      <?php if (count($evs) > 3): ?><span class="cal-plus">+<?= count($evs)-3 ?> autre<?= count($evs)-3>1?'s':'' ?></span><?php endif; ?>
    </div>
    <?php endfor; ?>
  </div>

  <!-- Légende des statuts -->
  <div class="cal-legende">
    <?php foreach ($statutLbl as $sv => $sl): ?>
    <span class="cal-leg-item"><span class="cal-leg-pastille <?= $statutCls[$sv] ?>"></span><?= $sl ?></span>
    <?php endforeach; ?>
  </div>
</div>

<?php admin_footer(); ?>
