<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/documents.php';   // montants des factures
require_once __DIR__ . '/../config/wave.php';      // sommes déjà encaissées

/* ============================================================
   TABLEAU DE BORD EMPLOYÉ (espace personnel)
   ============================================================ */
if (!is_admin()) {
    $uid = (int)$_SESSION['admin_id'];
    $prenom = trim(explode(' ', (string)($_SESSION['admin_nom'] ?? 'vous'))[0]) ?: 'vous';

    // Statistiques de mes tâches
    $tc = ['a_faire'=>0, 'en_cours'=>0, 'termine'=>0, 'non_vues'=>0, 'total'=>0, 'retard'=>0];
    try {
        $st = $pdo->prepare("SELECT statut, COUNT(*) n, SUM(vue=0) nv, SUM(statut<>'termine' AND date_limite IS NOT NULL AND date_limite < CURDATE()) rt FROM taches WHERE assigne_a=? GROUP BY statut");
        $st->execute([$uid]);
        foreach ($st as $r) {
            $tc[$r['statut']] = (int)$r['n'];
            $tc['total'] += (int)$r['n'];
            $tc['non_vues'] += (int)$r['nv'];
            $tc['retard'] += (int)$r['rt'];
        }
    } catch (\Throwable $e) {}

    // Mes tâches à traiter (non terminées), les plus urgentes d'abord
    $mesTaches = [];
    try {
        $st = $pdo->prepare("SELECT * FROM taches WHERE assigne_a=? AND statut<>'termine' ORDER BY (date_limite IS NULL), date_limite ASC, FIELD(priorite,'haute','normale','basse') LIMIT 6");
        $st->execute([$uid]); $mesTaches = $st->fetchAll();
    } catch (\Throwable $e) {}

    // Mes rapports
    $rc = ['total'=>0, 'brouillon'=>0, 'envoye'=>0]; $dernierRap = null;
    try {
        $st = $pdo->prepare("SELECT statut, COUNT(*) n FROM rapports WHERE employe_user_id=? GROUP BY statut");
        $st->execute([$uid]);
        foreach ($st as $r) { $rc[$r['statut']] = (int)$r['n']; $rc['total'] += (int)$r['n']; }
        $st = $pdo->prepare("SELECT numero, titre, date_rapport, statut FROM rapports WHERE employe_user_id=? ORDER BY created_at DESC LIMIT 1");
        $st->execute([$uid]); $dernierRap = $st->fetch();
    } catch (\Throwable $e) {}

    $prioBadge = ['haute'=>'badge-danger', 'normale'=>'badge-gold', 'basse'=>'badge-teal'];
    $prioLabel = ['haute'=>'Haute', 'normale'=>'Normale', 'basse'=>'Basse'];
    $statBadge = ['a_faire'=>'badge-gold', 'en_cours'=>'badge-violet', 'termine'=>'badge-teal'];
    $statLabel = ['a_faire'=>'À faire', 'en_cours'=>'En cours', 'termine'=>'Terminé'];

    admin_header('Mon espace', 'dashboard', $pdo, $settings);
    ?>
    <div class="panel glass" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <div style="font-size:34px">👋</div>
      <div style="flex:1;min-width:220px">
        <h2 style="margin:0;border:0;padding:0">Bonjour <?= e($prenom) ?> !</h2>
        <p style="margin:4px 0 0;color:var(--ink-dim)">
          <?php if ($tc['non_vues']>0): ?>Vous avez <strong style="color:var(--gold)"><?= $tc['non_vues'] ?> nouvelle<?= $tc['non_vues']>1?'s':'' ?> tâche<?= $tc['non_vues']>1?'s':'' ?></strong> à découvrir.
          <?php elseif ($tc['a_faire']+$tc['en_cours']>0): ?>Vous avez <strong><?= $tc['a_faire']+$tc['en_cours'] ?> tâche<?= ($tc['a_faire']+$tc['en_cours'])>1?'s':'' ?></strong> en cours. Bon courage 💪
          <?php else: ?>Tout est à jour, aucune tâche en attente ✨<?php endif; ?>
        </p>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a href="taches.php" class="btn btn-glass">✅ Mes tâches</a>
        <a href="rapports.php?edit=new" class="btn btn-gold">📝 Rédiger un rapport</a>
      </div>
    </div>

    <div class="stats">
      <div class="stat glass gold"><div class="s-ico">📋</div><div class="s-num"><?= $tc['a_faire'] ?></div><div class="s-label">À faire</div></div>
      <div class="stat glass violet"><div class="s-ico">🔄</div><div class="s-num"><?= $tc['en_cours'] ?></div><div class="s-label">En cours</div></div>
      <div class="stat glass teal"><div class="s-ico">✅</div><div class="s-num"><?= $tc['termine'] ?></div><div class="s-label">Terminées</div></div>
      <div class="stat glass <?= $tc['retard']>0?'rose':'teal' ?>"><div class="s-ico"><?= $tc['retard']>0?'⏰':'👍' ?></div><div class="s-num"><?= $tc['retard'] ?></div><div class="s-label">En retard</div></div>
    </div>

    <div class="panel glass">
      <h2>✅ Mes tâches à traiter <a href="taches.php" class="btn btn-glass btn-sm" style="margin-left:auto">Tout voir →</a></h2>
      <div class="tbl-wrap">
        <table>
          <thead><tr><th>Tâche</th><th>Priorité</th><th>Échéance</th><th>Statut</th></tr></thead>
          <tbody>
            <?php foreach ($mesTaches as $t):
              $enRetard = $t['date_limite'] && $t['date_limite'] < date('Y-m-d') && $t['statut']!=='termine'; ?>
            <tr>
              <td><strong><?= e($t['titre']) ?></strong><?php if ($t['vue']==0): ?> <span class="badge badge-gold" style="font-size:10px">Nouveau</span><?php endif; ?></td>
              <td><span class="badge <?= $prioBadge[$t['priorite']] ?>"><?= $prioLabel[$t['priorite']] ?></span></td>
              <td><?= $t['date_limite'] ? '<span style="'.($enRetard?'color:var(--rose,#e57373);font-weight:600':'').'">'.date('d/m/Y', strtotime($t['date_limite'])).'</span>' : '—' ?></td>
              <td><span class="badge <?= $statBadge[$t['statut']] ?>"><?= $statLabel[$t['statut']] ?></span></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$mesTaches): ?><tr><td colspan="4" style="text-align:center;padding:28px;color:var(--ink-faint)">Aucune tâche en attente. Profitez-en ! ✨</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="panel glass">
      <h2>📝 Mes rapports <a href="rapports.php" class="btn btn-glass btn-sm" style="margin-left:auto">Voir mes rapports →</a></h2>
      <div class="stats" style="margin-bottom:0">
        <div class="stat glass teal"><div class="s-ico">📤</div><div class="s-num"><?= $rc['envoye'] ?></div><div class="s-label">Envoyés à l'admin</div></div>
        <div class="stat glass gold"><div class="s-ico">📄</div><div class="s-num"><?= $rc['brouillon'] ?></div><div class="s-label">Brouillons</div></div>
        <div class="stat glass violet" style="grid-column:span 2;text-align:left;align-items:flex-start">
          <div class="s-label" style="margin-bottom:4px">Dernier rapport</div>
          <?php if ($dernierRap): ?>
            <div class="s-num" style="font-size:16px"><?= e($dernierRap['titre']) ?></div>
            <div class="s-label"><?= e($dernierRap['numero']) ?> · <?= date('d/m/Y', strtotime($dernierRap['date_rapport'])) ?> · <?= $dernierRap['statut']==='envoye'?'Envoyé ✅':'Brouillon' ?></div>
          <?php else: ?>
            <div class="s-num" style="font-size:16px">Aucun rapport</div>
            <div class="s-label"><a href="rapports.php?edit=new" style="color:var(--gold)">Rédiger le premier →</a></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
    admin_footer();
    exit;
}

$stats = [
    'nouveaux'  => (int)$pdo->query("SELECT COUNT(*) FROM commandes WHERE statut='nouveau'")->fetchColumn(),
    'en_cours'  => (int)$pdo->query("SELECT COUNT(*) FROM commandes WHERE statut IN('en_cours','confirme')")->fetchColumn(),
    'plats'     => (int)$pdo->query("SELECT COUNT(*) FROM plats WHERE actif=1")->fetchColumn(),
    'total'     => (int)$pdo->query("SELECT COUNT(*) FROM commandes")->fetchColumn(),
];
$devise = $settings['devise'] ?? 'FCFA';
$mois = date('Y-m');
$fin = $pdo->prepare("SELECT type, COALESCE(SUM(montant),0) s FROM transactions WHERE DATE_FORMAT(date_operation,'%Y-%m')=? GROUP BY type");
$fin->execute([$mois]);
$m_entrees = $m_depenses = 0;
foreach ($fin as $r) { if ($r['type']==='entree') $m_entrees=(float)$r['s']; else $m_depenses=(float)$r['s']; }
$treso = (float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN type='entree' THEN montant ELSE -montant END),0) FROM transactions")->fetchColumn();
$nb_clients = (int)$pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$fact_impayees = (float)$pdo->query("SELECT COALESCE(SUM(
        GREATEST((SELECT COALESCE(SUM(quantite*prix_unitaire),0) FROM facture_lignes WHERE facture_id=f.id) - COALESCE(f.remise,0), 0)
        * (1 + IF(f.tva_applicable=1, f.tva_taux/100, 0))
    ),0)
    FROM factures f
    WHERE f.type='facture' AND f.statut IN('envoyee','brouillon')")->fetchColumn();
$dernieres = $pdo->query("SELECT * FROM commandes ORDER BY created_at DESC LIMIT 6")->fetchAll();
$prochains = $pdo->query("SELECT * FROM commandes WHERE date_evenement >= CURDATE() AND statut IN('en_cours','confirme') ORDER BY date_evenement ASC LIMIT 5")->fetchAll();

$badges = ['nouveau'=>'badge-gold','en_cours'=>'badge-violet','confirme'=>'badge-teal','termine'=>'badge','annule'=>'badge-danger'];
$labels = ['nouveau'=>'Nouveau','en_cours'=>'En cours','confirme'=>'Confirmé','termine'=>'Terminé','annule'=>'Annulé'];

admin_header('Tableau de bord', 'dashboard', $pdo, $settings);

/* Comptes actuellement en ligne (activité dans les 5 dernières minutes) */
$enLigne = $pdo->query("SELECT nom, username, role, last_ip, last_ville, last_activity
    FROM users
    WHERE last_activity IS NOT NULL AND last_activity >= (NOW() - INTERVAL 5 MINUTE)
    ORDER BY last_activity DESC")->fetchAll();

/* ============================================================================
   POINTS D'ATTENTION
   Ce qui mérite votre regard aujourd'hui. Chaque alerte est un lien direct
   vers l'endroit où la traiter. S'il n'y a rien à signaler, rien ne s'affiche.
   ============================================================================ */
$alertes = [];

// Factures échues non réglées
if (can('factures')) {
    try {
        $nbRetard = 0; $montantRetard = 0.0;
        $st = $pdo->query("SELECT id FROM factures
                           WHERE type='facture' AND statut NOT IN ('payee','annulee','brouillon')
                             AND date_echeance IS NOT NULL AND date_echeance < CURDATE()");
        foreach ($st->fetchAll() as $f) {
            $doc = get_facture($pdo, (int)$f['id']);
            if (!$doc) continue;
            $solde = (float)$doc['montant_ttc'] - paiements_deja_regles($pdo, (int)$f['id']);
            if ($solde > 1) { $nbRetard++; $montantRetard += $solde; }
        }
        if ($nbRetard > 0) {
            $alertes[] = ['urgent', '💰', $nbRetard . ' facture' . ($nbRetard > 1 ? 's' : '') . ' en retard',
                          money($montantRetard, $devise) . ' à encaisser', 'relances.php', 'Relancer'];
        }
    } catch (Throwable $e) {}
}

// Articles sous le seuil d'alerte
if (can('stock')) {
    try {
        $bas = $pdo->query("SELECT nom, quantite, unite, seuil_alerte FROM stock_articles
                            WHERE seuil_alerte > 0 AND quantite <= seuil_alerte
                            ORDER BY quantite ASC LIMIT 5")->fetchAll();
        if ($bas) {
            $noms = implode(', ', array_map(fn($a) => $a['nom'] . ' (' . rtrim(rtrim(number_format((float)$a['quantite'], 2, ',', ' '), '0'), ',') . ' ' . $a['unite'] . ')', array_slice($bas, 0, 3)));
            $alertes[] = ['attention', '📦', count($bas) . ' article' . (count($bas) > 1 ? 's' : '') . ' à réapprovisionner',
                          $noms, 'stock.php', 'Voir le stock'];
        }
    } catch (Throwable $e) {}
}

// Tâches à échéance aujourd'hui ou dépassée
try {
    $st = $pdo->query("SELECT titre, date_limite FROM taches
                       WHERE statut <> 'termine' AND date_limite IS NOT NULL AND date_limite <= CURDATE()
                       ORDER BY date_limite ASC LIMIT 5");
    $tachesDues = $st->fetchAll();
    if ($tachesDues) {
        $alertes[] = ['attention', '✅', count($tachesDues) . ' tâche' . (count($tachesDues) > 1 ? 's' : '') . ' à traiter',
                      $tachesDues[0]['titre'] . (count($tachesDues) > 1 ? ' et ' . (count($tachesDues) - 1) . ' autre(s)' : ''),
                      'taches.php', 'Ouvrir'];
    }
} catch (Throwable $e) {}

// Documents rédigés en attente de validation
if (is_admin()) {
    try {
        $nbDoc = (int)$pdo->query("SELECT COUNT(*) FROM documents_texte WHERE statut='termine'")->fetchColumn();
        if ($nbDoc > 0) {
            $alertes[] = ['info', '📝', $nbDoc . ' document' . ($nbDoc > 1 ? 's' : '') . ' à valider',
                          'Terminé' . ($nbDoc > 1 ? 's' : '') . ' par un employé, en attente de votre accord', 'documents.php', 'Vérifier'];
        }
    } catch (Throwable $e) {}
}

// Paiements en ligne restés en attente
if (is_admin()) {
    try {
        $nbPay = (int)$pdo->query("SELECT COUNT(*) FROM paiements WHERE statut='en_attente'
                                   AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)")->fetchColumn();
        if ($nbPay > 0) {
            $alertes[] = ['info', '💳', $nbPay . ' paiement' . ($nbPay > 1 ? 's' : '') . ' à vérifier',
                          'En attente depuis plus de 30 minutes', 'paiements.php', 'Contrôler'];
        }
    } catch (Throwable $e) {}
}

/* Encaissements des 6 derniers mois, pour la tendance */
$tendance = [];
if (can('comptabilite')) {
    try {
        for ($i = 5; $i >= 0; $i--) {
            $m = date('Y-m', strtotime("-$i month"));
            $st = $pdo->prepare("SELECT COALESCE(SUM(montant),0) FROM transactions
                                 WHERE type='entree' AND DATE_FORMAT(date_operation,'%Y-%m')=?");
            $st->execute([$m]);
            $abrevFr = ['', 'Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
            $tendance[] = ['mois' => $abrevFr[(int)date('n', strtotime($m . '-01'))], 'val' => (float)$st->fetchColumn()];
        }
    } catch (Throwable $e) { $tendance = []; }
}
$maxTend = $tendance ? max(1, max(array_column($tendance, 'val'))) : 1;
?>

<?php if ($alertes): ?>
<div class="alertes-bande">
  <?php foreach ($alertes as [$niv, $ico, $titre, $detail, $lien, $action]): ?>
  <a class="alerte alerte-<?= $niv ?>" href="<?= $lien ?>">
    <span class="al-ico"><?= $ico ?></span>
    <span class="al-txt">
      <span class="al-titre"><?= e($titre) ?></span>
      <span class="al-detail"><?= e($detail) ?></span>
    </span>
    <span class="al-action"><?= e($action) ?> →</span>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="actions-rapides">
  <?php if (can('factures')): ?><a href="factures.php?edit=new">🧾 Nouvelle facture</a><?php endif; ?>
  <?php if (can('clients')): ?><a href="clients.php#form">👥 Nouveau client</a><?php endif; ?>
  <?php if (can('bons_entree')): ?><a href="recus.php?type=entree">💵 Encaisser</a><?php endif; ?>
  <?php if (can('commandes_client')): ?><a href="commandes-client.php">📦 Commandes</a><?php endif; ?>
  <a href="recherche.php">🔍 Rechercher</a>
</div>

<div class="dash-cards">
  <!-- Carte horloge + météo -->
  <div class="dcard glass gold">
    <div class="dcard-head">
      <span class="dcard-ico">🕐</span>
      <span class="dcard-weather" id="dc-weather" hidden><span id="dc-w-ico"></span> <span id="dc-w-temp"></span></span>
    </div>
    <div class="dcard-clock" id="dc-time">--:--<span class="dc-sec" id="dc-sec">00</span></div>
    <div class="dcard-sub">
      <span id="dc-date">—</span>
      <span class="dcard-city" id="dc-city" hidden></span>
    </div>
  </div>

  <!-- Carte comptes en ligne -->
  <div class="dcard glass teal">
    <div class="dcard-head">
      <span class="dcard-ico"><span class="online-pulse"></span></span>
      <span class="dcard-titre">Connectés maintenant</span>
      <span class="dcard-count"><?= count($enLigne) ?></span>
    </div>
    <?php if (!$enLigne): ?>
      <p style="color:var(--ink-faint);font-size:12.5px;margin:12px 0 0">Personne connecté.</p>
    <?php else: ?>
      <div class="online-avatars">
        <?php foreach ($enLigne as $u): ?>
        <span class="online-ava sm <?= $u['role']==='client'?'ava-client':($u['role']==='admin'?'ava-admin':'ava-emp') ?>" title="<?= e($u['nom']) ?>"><?= e(mb_strtoupper(mb_substr($u['nom'],0,1))) ?></span>
        <?php endforeach; ?>
      </div>
      <details class="online-details">
        <summary>Détail</summary>
        <div class="online-list">
          <?php foreach ($enLigne as $u): ?>
          <div class="online-row">
            <span class="online-ava <?= $u['role']==='client'?'ava-client':($u['role']==='admin'?'ava-admin':'ava-emp') ?>"><?= e(mb_strtoupper(mb_substr($u['nom'],0,1))) ?></span>
            <div class="online-info">
              <strong><?= e($u['nom']) ?></strong>
              <small><?= $u['role']==='client'?'Client':($u['role']==='admin'?'Administrateur':'Employé') ?><?= $u['last_ip']?' · '.e($u['last_ip']):'' ?></small>
            </div>
            <?php if ($u['last_ville'] && $u['last_ville']!=='Réseau local'): ?>
            <a class="online-map" href="https://www.openstreetmap.org/search?query=<?= urlencode($u['last_ville']) ?>" target="_blank" rel="noopener" title="Localiser : <?= e($u['last_ville']) ?>">📍</a>
            <?php else: ?>
            <span class="online-map off" title="Localisation indisponible">📍</span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>
  </div>
</div>

<div class="stats">
  <a class="stat glass gold" href="commandes-client.php"><div class="s-ico">📥</div><div class="s-num"><?= $stats['nouveaux'] ?></div><div class="s-label">Nouvelles demandes</div></a>
  <a class="stat glass violet" href="calendrier.php"><div class="s-ico">🔄</div><div class="s-num"><?= $stats['en_cours'] ?></div><div class="s-label">Événements en cours</div></a>
  <a class="stat glass teal" href="clients.php"><div class="s-ico">👥</div><div class="s-num"><?= $nb_clients ?></div><div class="s-label">Clients enregistrés</div></a>
  <a class="stat glass rose" href="menu.php"><div class="s-ico">🍛</div><div class="s-num"><?= $stats['plats'] ?></div><div class="s-label">Plats actifs au menu</div></a>
</div>

<?php if (can('comptabilite') || can('factures')): ?>
<div class="panel glass">
  <h2>💰 Aperçu financier — <?= date('m/Y') ?> <?php if (can('comptabilite')): ?><a href="comptabilite.php" class="btn btn-glass btn-sm" style="margin-left:auto">Comptabilité →</a><?php endif; ?></h2>
  <?php if ($tendance && can('comptabilite')): ?>
  <div class="mini-tendance">
    <?php foreach ($tendance as $t):
      $h = $maxTend > 0 ? max(4, round($t['val'] / $maxTend * 100)) : 4; ?>
    <div class="mt-col" title="<?= e($t['mois']) ?> — <?= money($t['val'], $devise) ?>">
      <div class="mt-barre" style="height:<?= $h ?>%"></div>
      <div class="mt-mois"><?= e($t['mois']) ?></div>
    </div>
    <?php endforeach; ?>
    <div class="mt-legende">Encaissements<br><span>6 derniers mois</span></div>
  </div>
  <?php endif; ?>

  <div class="stats" style="margin-bottom:0">
    <?php if (can('comptabilite')): ?>
    <div class="stat glass teal"><div class="s-ico">📈</div><div class="s-num" style="font-size:22px"><?= money($m_entrees, $devise) ?></div><div class="s-label">Entrées du mois</div></div>
    <div class="stat glass rose"><div class="s-ico">📉</div><div class="s-num" style="font-size:22px"><?= money($m_depenses, $devise) ?></div><div class="s-label">Dépenses du mois</div></div>
    <div class="stat glass <?= $treso>=0?'gold':'rose' ?>"><div class="s-ico">🏦</div><div class="s-num" style="font-size:22px"><?= money($treso, $devise) ?></div><div class="s-label">Trésorerie globale</div></div>
    <?php endif; ?>
    <?php if (can('factures')): ?>
    <a class="stat glass violet" href="factures.php?fs=impayees" style="text-decoration:none" title="Voir les factures à encaisser"><div class="s-ico">🧾</div><div class="s-num" style="font-size:22px"><?= money($fact_impayees, $devise) ?></div><div class="s-label">Factures à encaisser →</div></a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="panel glass">
  <h2>📥 Dernières demandes de devis <a href="commandes-client.php?vue=devis" class="btn btn-glass btn-sm" style="margin-left:auto">Tout voir →</a></h2>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Client</th><th>Événement</th><th>Date</th><th>Participants</th><th>Statut</th><th>Reçue le</th></tr></thead>
      <tbody>
        <?php foreach ($dernieres as $c): ?>
        <tr>
          <td><strong><?= e($c['nom']) ?></strong><br><small><?= e($c['telephone']) ?></small></td>
          <td><?= e($c['type_evenement']) ?></td>
          <td><?= $c['date_evenement'] ? date('d/m/Y', strtotime($c['date_evenement'])) : '—' ?></td>
          <td><?= $c['nb_invites'] ?: '—' ?></td>
          <td><span class="badge <?= $badges[$c['statut']] ?>"><?= $labels[$c['statut']] ?></span></td>
          <td><?= date('d/m à H:i', strtotime($c['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$dernieres): ?><tr><td colspan="6" style="text-align:center;padding:28px">Aucune demande pour le moment. Elles apparaîtront ici dès qu'un client remplira le formulaire du site.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel glass">
  <h2>🗓️ Prochains événements confirmés</h2>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Date</th><th>Client</th><th>Événement</th><th>Participants</th></tr></thead>
      <tbody>
        <?php foreach ($prochains as $c): ?>
        <tr>
          <td><strong><?= date('d/m/Y', strtotime($c['date_evenement'])) ?></strong></td>
          <td><?= e($c['nom']) ?> — <?= e($c['telephone']) ?></td>
          <td><?= e($c['type_evenement']) ?></td>
          <td><?= $c['nb_invites'] ?: '—' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$prochains): ?><tr><td colspan="4" style="text-align:center;padding:28px">Aucun événement à venir. Confirmez une demande pour la voir apparaître ici.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
(function(){
  var mois=['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
  var jours=['dimanche','lundi','mardi','mercredi','jeudi','vendredi','samedi'];
  function tick(){
    var d=new Date();
    var t=document.getElementById('dc-time'), sec=document.getElementById('dc-sec'), dt=document.getElementById('dc-date');
    if(t){ t.childNodes[0].nodeValue=String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0'); }
    if(sec) sec.textContent=String(d.getSeconds()).padStart(2,'0');
    if(dt) dt.textContent=jours[d.getDay()]+' '+d.getDate()+' '+mois[d.getMonth()];
  }
  tick(); setInterval(tick,1000);

  var wIco={0:'☀️',1:'🌤️',2:'⛅',3:'☁️',45:'🌫️',48:'🌫️',51:'🌦️',53:'🌦️',55:'🌧️',61:'🌧️',63:'🌧️',65:'🌧️',71:'🌨️',73:'🌨️',75:'❄️',80:'🌦️',81:'🌧️',82:'⛈️',95:'⛈️',96:'⛈️',99:'⛈️'};
  /* La météo n'apparaît que si la donnée arrive vraiment : mieux vaut ne rien
     afficher qu'un tiret permanent, qui donne une impression d'inachevé. */
  function setWeather(temp,code,ville){
    if (temp === undefined || temp === null || isNaN(temp)) return;
    var w=document.getElementById('dc-weather');
    var i=document.getElementById('dc-w-ico'), tp=document.getElementById('dc-w-temp');
    if(i) i.textContent=wIco[code]||'🌡️';
    if(tp) tp.textContent=Math.round(temp)+'°';
    if(w) w.hidden=false;
    if(ville){
      var c=document.getElementById('dc-city');
      if(c){ c.textContent='📍 '+ville; c.hidden=false; }
    }
  }
  function fetchWeather(lat,lon,ville){
    fetch('https://api.open-meteo.com/v1/forecast?latitude='+lat+'&longitude='+lon+'&current=temperature_2m,weather_code')
      .then(r=>r.json()).then(function(j){ if(j.current) setWeather(j.current.temperature_2m,j.current.weather_code,ville); }).catch(function(){});
  }
  if(navigator.geolocation){
    navigator.geolocation.getCurrentPosition(function(pos){
      fetchWeather(pos.coords.latitude.toFixed(3),pos.coords.longitude.toFixed(3),null);
      fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat='+pos.coords.latitude+'&lon='+pos.coords.longitude+'&zoom=10')
        .then(r=>r.json()).then(function(j){ var a=j.address||{}; var v=a.city||a.town||a.village||a.state||''; if(v){ var c=document.getElementById('dc-city'); if(c) c.textContent='📍 '+v; } }).catch(function(){});
    }, function(){ fetchWeather(5.36,-4.01,'Abidjan'); }, {timeout:8000});
  } else { fetchWeather(5.36,-4.01,'Abidjan'); }
})();
</script>
<?php admin_footer(); ?>
