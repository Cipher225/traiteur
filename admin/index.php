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

/* ============================================================================
   POULS DE L'ACTIVITÉ — données des 12 derniers mois
   Une seule requête par série : on reste léger même avec des milliers de lignes.
   ============================================================================ */
$pouls = ['mois' => [], 'entrees' => [], 'depenses' => [], 'docs' => [], 'evts' => []];
$nomsCourts = ['', 'Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
$cles = [];
for ($i = 11; $i >= 0; $i--) {
    $k = date('Y-m', strtotime("-$i month"));
    $cles[$k] = 11 - $i;
    $pouls['mois'][]     = $nomsCourts[(int)date('n', strtotime($k . '-01'))];
    $pouls['entrees'][]  = 0.0;
    $pouls['depenses'][] = 0.0;
    $pouls['docs'][]     = 0;
    $pouls['evts'][]     = 0;
}
$depuis = date('Y-m-01', strtotime('-11 month'));

try {
    $st = $pdo->prepare("SELECT DATE_FORMAT(date_operation,'%Y-%m') k, type, SUM(montant) s
                         FROM transactions WHERE date_operation >= ? GROUP BY k, type");
    $st->execute([$depuis]);
    foreach ($st->fetchAll() as $r) {
        if (!isset($cles[$r['k']])) continue;
        $pouls[$r['type'] === 'entree' ? 'entrees' : 'depenses'][$cles[$r['k']]] = (float)$r['s'];
    }
} catch (Throwable $e) {}

try {
    $st = $pdo->prepare("SELECT DATE_FORMAT(date_emission,'%Y-%m') k, COUNT(*) n
                         FROM factures WHERE date_emission >= ? AND statut <> 'annulee' GROUP BY k");
    $st->execute([$depuis]);
    foreach ($st->fetchAll() as $r) if (isset($cles[$r['k']])) $pouls['docs'][$cles[$r['k']]] = (int)$r['n'];
} catch (Throwable $e) {}

try {
    $st = $pdo->prepare("SELECT DATE_FORMAT(date_evenement,'%Y-%m') k, COUNT(*) n
                         FROM commandes_client WHERE date_evenement >= ? GROUP BY k");
    $st->execute([$depuis]);
    foreach ($st->fetchAll() as $r) if (isset($cles[$r['k']])) $pouls['evts'][$cles[$r['k']]] = (int)$r['n'];
} catch (Throwable $e) {}

/* Répartition des encaissements par moyen de paiement (12 mois) */
$moyens = [];
try {
    $st = $pdo->prepare("SELECT mode_paiement m, SUM(montant) s FROM transactions
                         WHERE type='entree' AND date_operation >= ?
                         GROUP BY m ORDER BY s DESC LIMIT 6");
    $st->execute([$depuis]);
    $moyens = array_map(fn($r) => ['nom' => $r['m'] ?: 'Non précisé', 'val' => (float)$r['s']], $st->fetchAll());
} catch (Throwable $e) {}

/* Quelques repères, animés à l'affichage */
$poulsCA      = array_sum($pouls['entrees']);
$poulsDep     = array_sum($pouls['depenses']);
$poulsDocs    = array_sum($pouls['docs']);
$poulsEvts    = array_sum($pouls['evts']);
$poulsMoyen   = $poulsDocs > 0 ? $poulsCA / $poulsDocs : 0;
$moisPlein    = $pouls['entrees'] ? array_search(max($pouls['entrees']), $pouls['entrees']) : 0;

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
  <div class="dcard glass teal dcard-online">
    <div class="dcard-head">
      <span class="dcard-ico"><span class="online-pulse"></span></span>
      <span class="dcard-titre">Connectés maintenant</span>
      <span class="dcard-count"><?= count($enLigne) ?></span>
    </div>
    <?php if (!$enLigne): ?>
      <p style="color:var(--ink-faint);font-size:12.5px;margin:12px 0 0">Personne connecté.</p>
    <?php else: ?>
      <?php $visibles = array_slice($enLigne, 0, 6); $reste = count($enLigne) - count($visibles); ?>
      <div class="online-avatars">
        <?php foreach ($visibles as $u): ?>
        <span class="online-ava sm <?= $u['role']==='client'?'ava-client':($u['role']==='admin'?'ava-admin':'ava-emp') ?>" title="<?= e($u['nom']) ?>"><?= e(mb_strtoupper(mb_substr($u['nom'],0,1))) ?></span>
        <?php endforeach; ?>
        <?php if ($reste > 0): ?><span class="online-ava sm ava-plus">+<?= $reste ?></span><?php endif; ?>
      </div>

      <!-- Le détail s'ouvre en panneau flottant : la carte garde sa taille,
           et la mise en page ne bouge pas, quel que soit le nombre de connectés. -->
      <button type="button" class="online-btn" id="btn-online" aria-expanded="false" aria-controls="pan-online">
        Voir le détail <span class="ob-fl">▾</span>
      </button>
      <div class="online-pan" id="pan-online" hidden>
        <div class="op-tete">
          <strong><?= count($enLigne) ?> connecté<?= count($enLigne) > 1 ? 's' : '' ?></strong>
          <button type="button" class="op-fermer" id="close-online" aria-label="Fermer">✕</button>
        </div>
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
      </div>
      <script>
      (function(){
        var b=document.getElementById('btn-online'), p=document.getElementById('pan-online'),
            f=document.getElementById('close-online');
        if(!b||!p) return;
        /* Une carte en verre applique un flou d'arrière-plan, ce qui piège tout
           élément « fixe » à l'intérieur d'elle. On rattache donc le panneau
           directement à la page : il se superpose alors librement. */
        document.body.appendChild(p);
        function placer(){
          /* Le panneau est positionné au niveau de la page : il se superpose à tout
             sans jamais déformer la carte ni les blocs voisins. */
          /* On mesure APRÈS avoir rendu le panneau visible : tant qu'il est masqué,
             sa largeur vaut zéro et le calcul de position serait faux. */
          var r = b.getBoundingClientRect();
          var largeur = p.offsetWidth || 290;
          var l = Math.min(r.left, window.innerWidth - largeur - 16);
          p.style.left = Math.max(16, l) + 'px';
          var sousLeBouton = r.bottom + 8;
          if (sousLeBouton + p.offsetHeight > window.innerHeight - 12) {
            p.style.top = Math.max(12, r.top - p.offsetHeight - 8) + 'px';
          } else {
            p.style.top = sousLeBouton + 'px';
          }
        }
        function ouvrir(o){
          p.hidden=!o; b.setAttribute('aria-expanded', o?'true':'false'); b.classList.toggle('ouvert', o);
          if (o) { p.style.visibility='hidden'; placer(); p.style.visibility=''; }
        }
        window.addEventListener('resize', function(){ if(!p.hidden) placer(); });
        window.addEventListener('scroll', function(){ if(!p.hidden) placer(); }, {passive:true});
        b.addEventListener('click', function(e){ e.stopPropagation(); ouvrir(p.hidden); });
        if(f) f.addEventListener('click', function(){ ouvrir(false); });
        document.addEventListener('click', function(e){ if(!p.hidden && !p.contains(e.target)) ouvrir(false); });
        document.addEventListener('keydown', function(e){ if(e.key==='Escape') ouvrir(false); });
      })();
      </script>
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

<?php if (can('comptabilite')): ?>
<!-- ============================================================================
     POULS DE L'ACTIVITÉ
     Tout se dessine en SVG au chargement : les courbes se tracent, les aires se
     remplissent, les chiffres défilent. Aucune bibliothèque : rien à télécharger,
     donc un affichage instantané, même sur une connexion faible.
     ============================================================================ -->
<div class="pouls panel glass">
  <div class="pouls-tete">
    <div>
      <h2 style="margin:0">💓 Pouls de l'activité</h2>
      <p class="pouls-sous">Douze derniers mois — encaissements, dépenses, documents et événements</p>
    </div>
    <div class="pouls-legende">
      <button type="button" class="pl-item actif" data-serie="entrees"><i style="background:#10b981"></i>Encaissements</button>
      <button type="button" class="pl-item actif" data-serie="depenses"><i style="background:#f87171"></i>Dépenses</button>
      <button type="button" class="pl-item" data-serie="docs"><i style="background:#d4a526"></i>Factures</button>
      <button type="button" class="pl-item" data-serie="evts"><i style="background:#60a5fa"></i>Événements</button>
    </div>
  </div>

  <div class="pouls-corps">
    <div class="pouls-graphe">
      <svg id="pouls-svg" viewBox="0 0 820 260" preserveAspectRatio="none" role="img"
           aria-label="Évolution de l'activité sur douze mois"></svg>
      <div class="pouls-bulle" id="pouls-bulle" hidden></div>
    </div>

    <div class="pouls-cote">
      <div class="pouls-donut">
        <svg viewBox="0 0 120 120" id="pouls-donut" aria-label="Répartition par moyen de paiement"></svg>
        <div class="pd-centre">
          <span class="pd-val" data-compte="<?= (int)$poulsCA ?>">0</span>
          <span class="pd-lbl"><?= e($devise) ?> encaissés</span>
        </div>
      </div>
      <div class="pouls-moyens" id="pouls-moyens"></div>
    </div>
  </div>

  <div class="pouls-reperes">
    <div class="pr-item"><span class="pr-val" data-compte="<?= (int)$poulsDocs ?>">0</span><span class="pr-lbl">factures émises</span></div>
    <div class="pr-item"><span class="pr-val" data-compte="<?= (int)$poulsEvts ?>">0</span><span class="pr-lbl">événements</span></div>
    <div class="pr-item"><span class="pr-val" data-compte="<?= (int)$poulsMoyen ?>">0</span><span class="pr-lbl">panier moyen</span></div>
    <div class="pr-item"><span class="pr-val pr-txt"><?= e($pouls['mois'][$moisPlein] ?? '—') ?></span><span class="pr-lbl">meilleur mois</span></div>
  </div>
</div>

<script>
(function(){
  var D = <?= json_encode([
      'mois' => $pouls['mois'],
      'series' => [
        'entrees'  => array_map('floatval', $pouls['entrees']),
        'depenses' => array_map('floatval', $pouls['depenses']),
        'docs'     => array_map('intval',   $pouls['docs']),
        'evts'     => array_map('intval',   $pouls['evts']),
      ],
      'moyens' => $moyens,
      'devise' => $devise,
  ], JSON_UNESCAPED_UNICODE) ?>;

  var svg = document.getElementById('pouls-svg');
  if (!svg) return;
  var NS = 'http://www.w3.org/2000/svg';
  var W = 820, H = 260, ML = 8, MR = 8, MT = 18, MB = 30;
  var douce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  var couleurs = { entrees:'#10b981', depenses:'#f87171', docs:'#d4a526', evts:'#60a5fa' };
  var actives  = { entrees:true, depenses:true, docs:false, evts:false };

  function fmt(n){ return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
  function el(t, a){ var e = document.createElementNS(NS, t); for (var k in a) e.setAttribute(k, a[k]); return e; }

  /* Courbe lissée : des segments droits feraient « graphique de tableur »,
     une courbe douce se lit mieux et fait vivante. */
  function chemin(pts){
    if (!pts.length) return '';
    var d = 'M' + pts[0][0] + ',' + pts[0][1];
    for (var i = 0; i < pts.length - 1; i++) {
      var x0 = pts[i][0], y0 = pts[i][1], x1 = pts[i+1][0], y1 = pts[i+1][1];
      var cx = (x0 + x1) / 2;
      d += ' C' + cx + ',' + y0 + ' ' + cx + ',' + y1 + ' ' + x1 + ',' + y1;
    }
    return d;
  }

  function dessiner(){
    svg.innerHTML = '';
    var n = D.mois.length;
    var largeur = W - ML - MR, hauteur = H - MT - MB;
    var pas = largeur / Math.max(1, n - 1);

    // Échelle : chaque famille garde sa propre lecture (argent vs nombre)
    var maxArgent = 1, maxNb = 1;
    ['entrees','depenses'].forEach(function(s){ if(actives[s]) D.series[s].forEach(function(v){ if(v>maxArgent) maxArgent=v; }); });
    ['docs','evts'].forEach(function(s){ if(actives[s]) D.series[s].forEach(function(v){ if(v>maxNb) maxNb=v; }); });

    // Lignes de repère
    var defs = el('defs', {});
    for (var g = 0; g <= 4; g++) {
      var y = MT + hauteur * g / 4;
      svg.appendChild(el('line', {x1:ML, y1:y, x2:W-MR, y2:y,
        stroke:'currentColor', 'stroke-opacity':.07, 'stroke-width':1}));
    }

    ['entrees','depenses','docs','evts'].forEach(function(nom, idx){
      if (!actives[nom]) return;
      var vals = D.series[nom];
      var mx = (nom === 'docs' || nom === 'evts') ? maxNb : maxArgent;
      var pts = vals.map(function(v, i){
        return [ML + i * pas, MT + hauteur - (v / mx) * hauteur * 0.92];
      });
      var d = chemin(pts);

      // Aire dégradée sous la courbe
      var grad = el('linearGradient', {id:'g-'+nom, x1:'0', y1:'0', x2:'0', y2:'1'});
      var s1 = el('stop', {offset:'0%'});  s1.setAttribute('stop-color', couleurs[nom]); s1.setAttribute('stop-opacity','.30');
      var s2 = el('stop', {offset:'100%'});s2.setAttribute('stop-color', couleurs[nom]); s2.setAttribute('stop-opacity','0');
      grad.appendChild(s1); grad.appendChild(s2); defs.appendChild(grad);

      var aire = el('path', {d: d + ' L' + (ML + (n-1)*pas) + ',' + (MT+hauteur) + ' L' + ML + ',' + (MT+hauteur) + ' Z',
                             fill:'url(#g-'+nom+')', opacity:'0'});
      svg.appendChild(aire);

      var ligne = el('path', {d:d, fill:'none', stroke:couleurs[nom], 'stroke-width':'2.4',
                              'stroke-linecap':'round', 'stroke-linejoin':'round'});
      svg.appendChild(ligne);

      // Tracé progressif de la courbe, puis remplissage de l'aire
      if (!douce) {
        var L = ligne.getTotalLength();
        ligne.style.strokeDasharray = L; ligne.style.strokeDashoffset = L;
        ligne.style.transition = 'stroke-dashoffset 1.5s cubic-bezier(.4,0,.2,1) ' + (idx*.12) + 's';
        aire.style.transition = 'opacity .8s ease ' + (0.7 + idx*.12) + 's';
        requestAnimationFrame(function(){ ligne.style.strokeDashoffset = '0'; aire.style.opacity = '1'; });
      } else { aire.style.opacity = '1'; }

      // Points, révélés après le tracé
      pts.forEach(function(p, i){
        var c = el('circle', {cx:p[0], cy:p[1], r:'3.2', fill:couleurs[nom],
                              stroke:'rgba(10,16,32,.9)', 'stroke-width':'1.6', opacity: douce ? '1':'0'});
        if (!douce) {
          c.style.transition = 'opacity .3s ease ' + (0.9 + i*.03) + 's';
          requestAnimationFrame(function(){ c.style.opacity = '1'; });
        }
        svg.appendChild(c);
      });
    });

    svg.appendChild(defs);

    // Mois
    D.mois.forEach(function(m, i){
      var t = el('text', {x: ML + i*pas, y: H - 8, 'text-anchor':'middle',
                          fill:'currentColor', 'fill-opacity':'.45', 'font-size':'11'});
      t.textContent = m; svg.appendChild(t);
    });

    // Zone sensible au survol : un repère vertical suit le curseur
    var trait = el('line', {y1:MT, y2:MT+hauteur, stroke:'currentColor', 'stroke-opacity':'.25',
                            'stroke-width':1, 'stroke-dasharray':'3 3', opacity:'0'});
    svg.appendChild(trait);

    var bulle = document.getElementById('pouls-bulle');
    var zone = svg.parentNode;
    zone.onmousemove = function(e){
      var r = svg.getBoundingClientRect();
      var x = (e.clientX - r.left) / r.width * W;
      var i = Math.max(0, Math.min(n-1, Math.round((x - ML) / pas)));
      trait.setAttribute('x1', ML + i*pas); trait.setAttribute('x2', ML + i*pas);
      trait.setAttribute('opacity', '1');
      var h = '<strong>' + D.mois[i] + '</strong>';
      if (actives.entrees)  h += '<span><i style="background:#10b981"></i>Encaissé <b>' + fmt(D.series.entrees[i]) + '</b></span>';
      if (actives.depenses) h += '<span><i style="background:#f87171"></i>Dépensé <b>' + fmt(D.series.depenses[i]) + '</b></span>';
      if (actives.docs)     h += '<span><i style="background:#d4a526"></i>Factures <b>' + D.series.docs[i] + '</b></span>';
      if (actives.evts)     h += '<span><i style="background:#60a5fa"></i>Événements <b>' + D.series.evts[i] + '</b></span>';
      bulle.innerHTML = h; bulle.hidden = false;
      var px = (ML + i*pas) / W * r.width;
      bulle.style.left = Math.min(Math.max(px, 70), r.width - 70) + 'px';
    };
    zone.onmouseleave = function(){ trait.setAttribute('opacity','0'); bulle.hidden = true; };
  }

  /* Anneau des moyens de paiement */
  function donut(){
    var s = document.getElementById('pouls-donut');
    var liste = document.getElementById('pouls-moyens');
    if (!s || !D.moyens.length) { if (liste) liste.innerHTML = '<div class="pm-vide">Aucun encaissement enregistré</div>'; return; }
    s.innerHTML = '';
    var total = D.moyens.reduce(function(a,b){ return a + b.val; }, 0) || 1;
    var teintes = ['#d4a526','#10b981','#60a5fa','#a78bfa','#f0b429','#94a3b8'];
    var R = 46, C = 2 * Math.PI * R, debut = 0, html = '';

    D.moyens.forEach(function(m, i){
      var part = m.val / total;
      var arc = el('circle', {cx:60, cy:60, r:R, fill:'none', stroke:teintes[i % 6],
        'stroke-width':'13', 'stroke-linecap':'butt',
        'stroke-dasharray': (C*part - 1.5) + ' ' + (C - C*part + 1.5),
        'stroke-dashoffset': -C*debut, transform:'rotate(-90 60 60)'});
      if (!douce) {
        arc.style.opacity = '0';
        arc.style.transition = 'opacity .5s ease ' + (0.4 + i*.12) + 's';
        requestAnimationFrame(function(){ arc.style.opacity = '1'; });
      }
      s.appendChild(arc);
      html += '<div class="pm-ligne"><i style="background:' + teintes[i % 6] + '"></i>' +
              '<span class="pm-nom">' + m.nom + '</span>' +
              '<span class="pm-pct">' + Math.round(part*100) + '%</span></div>';
      debut += part;
    });
    liste.innerHTML = html;
  }

  /* Les chiffres défilent jusqu'à leur valeur : le mouvement attire l'œil
     sur les repères, sans être tapageur. */
  function compter(){
    document.querySelectorAll('[data-compte]').forEach(function(e){
      var cible = parseInt(e.dataset.compte, 10) || 0;
      if (douce || cible === 0) { e.textContent = fmt(cible); return; }
      var t0 = null, duree = 1300;
      function pas(t){
        if (!t0) t0 = t;
        var p = Math.min(1, (t - t0) / duree);
        var e2 = 1 - Math.pow(1 - p, 3);          // ralentit en fin de course
        e.textContent = fmt(cible * e2);
        if (p < 1) requestAnimationFrame(pas);
      }
      requestAnimationFrame(pas);
    });
  }

  document.querySelectorAll('.pl-item').forEach(function(b){
    b.addEventListener('click', function(){
      var s = this.dataset.serie;
      actives[s] = !actives[s];
      this.classList.toggle('actif', actives[s]);
      if (!Object.keys(actives).some(function(k){ return actives[k]; })) {
        actives[s] = true; this.classList.add('actif');   // au moins une série visible
      }
      dessiner();
    });
  });

  /* On ne lance l'animation que lorsque le bloc entre à l'écran */
  var lance = false;
  function demarrer(){
    if (lance) return; lance = true;
    dessiner(); donut(); compter();
  }
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function(ents){
      ents.forEach(function(en){ if (en.isIntersecting) { demarrer(); io.disconnect(); } });
    }, {threshold:.2});
    io.observe(document.querySelector('.pouls'));
  } else { demarrer(); }

  window.addEventListener('resize', function(){ if (lance) dessiner(); });
})();
</script>
<?php endif; ?>

<?php admin_footer(); ?>
