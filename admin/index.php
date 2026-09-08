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
    /* Deux origines pour une même réalité : le formulaire du site public
       (table commandes) et l'espace client (table commandes_client). Ne
       compter que la première laisserait invisibles les commandes de vos
       clients connectés. */
    'nouveaux'  => (int)$pdo->query("SELECT COUNT(*) FROM commandes WHERE statut='nouveau'")->fetchColumn()
                 + (int)$pdo->query("SELECT COUNT(*) FROM commandes_client WHERE statut='nouvelle'")->fetchColumn(),
    'en_cours'  => (int)$pdo->query("SELECT COUNT(*) FROM commandes WHERE statut IN('en_cours','confirme')")->fetchColumn()
                 + (int)$pdo->query("SELECT COUNT(*) FROM commandes_client WHERE statut IN('en_traitement','devis_envoye','confirmee')")->fetchColumn(),
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
/* Reste à encaisser : le montant des factures ouvertes MOINS ce que les clients
   ont déjà versé. Afficher le total brut ferait croire à un manque à gagner
   plus lourd qu'il ne l'est, alors qu'un acompte est peut-être déjà en caisse. */
$fact_impayees = 0.0;
try {
    $st = $pdo->query("SELECT id FROM factures WHERE type='facture' AND statut IN('envoyee','brouillon')");
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $fid) {
        $reste = facture_ttc($pdo, (int)$fid) - facture_deja_encaisse($pdo, (int)$fid);
        if ($reste > 0) $fact_impayees += $reste;
    }
} catch (Throwable $e) { $fact_impayees = 0.0; }
/* Les deux origines, présentées ensemble et triées par date. On garde
   l'origine pour que le lien mène à la bonne page. */
$dernieres = $pdo->query("
    SELECT id, nom, telephone, email, type_evenement, date_evenement, nb_invites,
           statut, created_at, 'site' AS origine, NULL AS numero
      FROM commandes
    UNION ALL
    SELECT cc.id, COALESCE(NULLIF(c.entreprise,''), c.nom) AS nom, c.telephone, c.email,
           COALESCE(NULLIF(cc.lieu,''), 'Commande espace client') AS type_evenement,
           cc.date_evenement, cc.nb_invites, cc.statut, cc.created_at, 'client' AS origine, cc.numero
      FROM commandes_client cc LEFT JOIN clients c ON c.id = cc.client_id
    ORDER BY created_at DESC LIMIT 6")->fetchAll();

$prochains = $pdo->query("
    SELECT id, nom, type_evenement, date_evenement, nb_invites, statut, 'site' AS origine
      FROM commandes
     WHERE date_evenement >= CURDATE() AND statut IN('en_cours','confirme')
    UNION ALL
    SELECT cc.id, COALESCE(NULLIF(c.entreprise,''), c.nom) AS nom,
           COALESCE(NULLIF(cc.lieu,''), 'Espace client') AS type_evenement,
           cc.date_evenement, cc.nb_invites, cc.statut, 'client' AS origine
      FROM commandes_client cc LEFT JOIN clients c ON c.id = cc.client_id
     WHERE cc.date_evenement >= CURDATE() AND cc.statut IN('en_traitement','devis_envoye','confirmee')
    ORDER BY date_evenement ASC LIMIT 6")->fetchAll();

$badges = ['nouveau'=>'badge-gold','en_cours'=>'badge-violet','confirme'=>'badge-teal',
           'termine'=>'badge','annule'=>'badge-danger',
           /* statuts propres aux commandes de l'espace client */
           'nouvelle'=>'badge-gold','en_traitement'=>'badge-violet','devis_envoye'=>'badge-teal',
           'confirmee'=>'badge-teal','terminee'=>'badge','annulee'=>'badge-danger'];
$labels = ['nouveau'=>'Nouveau','en_cours'=>'En cours','confirme'=>'Confirmé',
           'termine'=>'Terminé','annule'=>'Annulé',
           'nouvelle'=>'Nouvelle','en_traitement'=>'En traitement','devis_envoye'=>'Proforma envoyée',
           'confirmee'=>'Confirmée','terminee'=>'Terminée','annulee'=>'Annulée'];

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

// Messages rédigés par un employé, en attente d'approbation
if (is_admin()) {
    try {
        $nbMail = (int)$pdo->query("SELECT COUNT(*) FROM emails_envoyes WHERE statut='en_attente'")->fetchColumn();
        if ($nbMail > 0) {
            $alertes[] = ['attention', '✉️', $nbMail . ' message' . ($nbMail > 1 ? 's' : '') . ' à approuver',
                          'Rédigé' . ($nbMail > 1 ? 's' : '') . ' par un employé, en attente de votre accord',
                          'messages.php', 'Relire'];
        }
    } catch (Throwable $e) {}
}

// Charges récurrentes du mois pas encore enregistrées
if (is_admin() && can('comptabilite')) {
    try {
        $dues = array_filter(charges_du_mois($pdo, date('Y-m')), fn($ch) => !$ch['deja']);
        if ($dues) {
            $totalDues = array_sum(array_column($dues, 'montant'));
            $alertes[] = ['info', '🔁', count($dues) . ' charge' . (count($dues) > 1 ? 's' : '') . ' du mois à enregistrer',
                          money($totalDues, $settings['devise'] ?? 'FCFA') . ' — loyer, salaires, abonnements',
                          'comptabilite.php#charges', 'Enregistrer'];
        }
    } catch (Throwable $e) {}
}

// Proformas restées sans suite : une affaire qui dort est une affaire perdue
if (can('factures')) {
    try {
        $nbPro = (int)$pdo->query("SELECT COUNT(*) FROM factures
                                   WHERE type='proforma' AND statut='envoyee'
                                     AND date_emission < DATE_SUB(CURDATE(), INTERVAL 15 DAY)")->fetchColumn();
        if ($nbPro > 0) {
            $alertes[] = ['info', '📋', $nbPro . ' proforma' . ($nbPro > 1 ? 's' : '') . ' sans réponse',
                          'Envoyée' . ($nbPro > 1 ? 's' : '') . ' il y a plus de 15 jours — un rappel s\'impose peut-être',
                          'factures.php?doc=proforma', 'Relancer'];
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

/* ---- Marge mensuelle : la différence entre ce qui entre et ce qui sort ---- */
$pouls['marge'] = [];
foreach ($pouls['entrees'] as $i => $v) {
    $pouls['marge'][] = $v - ($pouls['depenses'][$i] ?? 0);
}

/* ---- Les trois prestations les plus rentables des 12 derniers mois ---- */
$poulsTop = [];
try {
    $st = $pdo->prepare("SELECT f.id, f.numero, f.activite,
                                COALESCE(NULLIF(c.entreprise,''), c.nom) AS client
                         FROM factures f LEFT JOIN clients c ON c.id = f.client_id
                         WHERE f.type='facture' AND f.statut <> 'annulee' AND f.date_emission >= ?
                         ORDER BY f.date_emission DESC LIMIT 40");
    $st->execute([$depuis]);
    foreach ($st->fetchAll() as $f2) {
        $r = rentabilite_activite($pdo, (int)$f2['id']);
        if ($r['ca'] <= 0) continue;
        $poulsTop[] = $f2 + $r;
    }
    usort($poulsTop, fn($a, $b) => $b['marge'] <=> $a['marge']);
    $poulsTop = array_slice($poulsTop, 0, 3);
} catch (Throwable $e) { $poulsTop = []; }

/* ---- Comparaison avec la période précédente : progresse-t-on ? ---- */
$sem1 = array_sum(array_slice($pouls['entrees'], 0, 6));    // mois 1 à 6
$sem2 = array_sum(array_slice($pouls['entrees'], 6, 6));    // mois 7 à 12
$poulsEvolution = $sem1 > 0 ? (($sem2 - $sem1) / $sem1) * 100 : ($sem2 > 0 ? 100 : 0);

/* ---- Où part l'argent : les cinq premiers postes de dépense ---- */
$poulsPostes = [];
try {
    $st = $pdo->prepare("SELECT categorie, SUM(montant) s FROM transactions
                         WHERE type='depense' AND date_operation >= ?
                         GROUP BY categorie ORDER BY s DESC LIMIT 5");
    $st->execute([$depuis]);
    $poulsPostes = array_map(fn($r) => ['nom' => $r['categorie'] ?: 'Divers', 'val' => (float)$r['s']], $st->fetchAll());
} catch (Throwable $e) {}

/* Quelques repères, animés à l'affichage */
$poulsCA      = array_sum($pouls['entrees']);
$poulsDep     = array_sum($pouls['depenses']);
$poulsDocs    = array_sum($pouls['docs']);
$poulsEvts    = array_sum($pouls['evts']);
$poulsMoyen   = $poulsDocs > 0 ? $poulsCA / $poulsDocs : 0;
$moisPlein    = $pouls['entrees'] ? array_search(max($pouls['entrees']), $pouls['entrees']) : 0;
$poulsMarge   = $poulsCA - $poulsDep;
$poulsTaux    = $poulsCA > 0 ? ($poulsMarge / $poulsCA) * 100 : 0;
$poulsMoisAct = count(array_filter($pouls['entrees'], fn($v) => $v > 0));

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
  <h2>📥 Dernières demandes de proforma <a href="commandes-client.php?vue=devis" class="btn btn-glass btn-sm" style="margin-left:auto">Tout voir →</a></h2>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Client</th><th>Événement</th><th>Date</th><th>Participants</th><th>Statut</th><th>Reçue le</th></tr></thead>
      <tbody>
        <?php foreach ($dernieres as $c): ?>
        <?php
          /* Une demande du site public et une commande de l'espace client
             n'ouvrent pas la même page : on garde le bon chemin. */
          $lien = $c['origine'] === 'client'
                ? 'commandes-client.php'
                : 'commandes.php';
        ?>
        <tr onclick="location.href='<?= $lien ?>'" style="cursor:pointer">
          <td><strong><?= e($c['nom']) ?></strong>
            <br><small><?= e($c['telephone'] ?: $c['email']) ?></small></td>
          <td><?= e($c['type_evenement']) ?>
            <div class="orig-src"><?= $c['origine'] === 'client' ? '👤 Espace client' : '🌐 Site public' ?><?= !empty($c['numero']) ? ' · ' . e($c['numero']) : '' ?></div></td>
          <td><?= $c['date_evenement'] ? date('d/m/Y', strtotime($c['date_evenement'])) : '—' ?></td>
          <td><?= $c['nb_invites'] ?: '—' ?></td>
          <td><span class="badge <?= $badges[$c['statut']] ?? 'badge' ?>"><?= $labels[$c['statut']] ?? e($c['statut']) ?></span></td>
          <td><?= date('d/m à H:i', strtotime($c['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$dernieres): ?><tr><td colspan="6" style="text-align:center;padding:28px">Aucune demande pour le moment. Elles apparaîtront ici dès qu'un client remplira le formulaire du site ou passera commande depuis son espace.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel glass">
  <h2>🗓️ Prochains événements confirmés</h2>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Date</th><th>Client</th><th>Événement</th><th>Participants</th><th>État</th></tr></thead>
      <tbody>
        <?php foreach ($prochains as $c): ?>
        <?php
          /* Combien de jours nous séparent de l'événement ? C'est l'information
             qui décide de l'urgence : « dans 3 jours » parle plus qu'une date. */
          $jours = (int)floor((strtotime($c['date_evenement']) - strtotime(date('Y-m-d'))) / 86400);
          $delai = $jours <= 0 ? "Aujourd'hui" : ($jours === 1 ? 'Demain' : 'Dans ' . $jours . ' jours');
          $urgence = $jours <= 2 ? 'proche' : ($jours <= 7 ? 'semaine' : '');
        ?>
        <tr onclick="location.href='<?= $c['origine'] === 'client' ? 'commandes-client.php' : 'commandes.php' ?>'" style="cursor:pointer">
          <td>
            <strong><?= date('d/m/Y', strtotime($c['date_evenement'])) ?></strong>
            <div class="ev-delai <?= $urgence ?>"><?= $delai ?></div>
          </td>
          <td><?= e($c['nom']) ?>
            <div class="orig-src"><?= $c['origine'] === 'client' ? '👤 Espace client' : '🌐 Site public' ?></div></td>
          <td><?= e($c['type_evenement']) ?></td>
          <td><?= $c['nb_invites'] ?: '—' ?></td>
          <td><span class="badge <?= $badges[$c['statut']] ?? 'badge' ?>"><?= $labels[$c['statut']] ?? e($c['statut']) ?></span></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$prochains): ?><tr><td colspan="5" style="text-align:center;padding:28px">Aucun événement à venir. Confirmez une demande pour la voir apparaître ici.</td></tr><?php endif; ?>
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
     Tout est dessiné en SVG au chargement : les aires se remplissent, les
     courbes se tracent, les chiffres défilent. Aucune bibliothèque externe —
     rien à télécharger, donc un affichage instantané même sur une connexion
     faible, ce qui compte en Côte d'Ivoire.
     ============================================================================ -->
<div class="pouls panel glass" id="pouls">

  <div class="pouls-tete">
    <div class="pt-titre">
      <span class="pt-coeur">💓</span>
      <div>
        <h2>Pouls de l'activité</h2>
        <p>Douze derniers mois — <?= (int)$poulsMoisAct ?> mois d'activité enregistrés</p>
      </div>
    </div>
    <?php if (abs($poulsEvolution) > 0.5): ?>
    <div class="pt-evol <?= $poulsEvolution >= 0 ? 'hausse' : 'baisse' ?>">
      <span class="pe-fleche"><?= $poulsEvolution >= 0 ? '▲' : '▼' ?></span>
      <div>
        <strong><?= ($poulsEvolution >= 0 ? '+' : '') . number_format($poulsEvolution, 0) ?> %</strong>
        <span>sur les 6 derniers mois</span>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ---------- Les quatre chiffres qui comptent ---------- -->
  <div class="pouls-cles">
    <div class="pc" data-teinte="or">
      <div class="pc-h"><span class="pc-i">💰</span><span class="pc-l">Encaissé</span></div>
      <div class="pc-v" data-val="<?= (int)$poulsCA ?>">0</div>
      <div class="pc-u"><?= e($devise) ?></div>
    </div>
    <div class="pc" data-teinte="rouge">
      <div class="pc-h"><span class="pc-i">📤</span><span class="pc-l">Dépensé</span></div>
      <div class="pc-v" data-val="<?= (int)$poulsDep ?>">0</div>
      <div class="pc-u"><?= e($devise) ?></div>
    </div>
    <div class="pc" data-teinte="<?= $poulsMarge >= 0 ? 'vert' : 'rouge' ?>">
      <div class="pc-h"><span class="pc-i"><?= $poulsMarge >= 0 ? '📈' : '📉' ?></span><span class="pc-l">Marge</span></div>
      <div class="pc-v" data-val="<?= (int)$poulsMarge ?>">0</div>
      <div class="pc-u"><?= number_format($poulsTaux, 0) ?> % du chiffre d'affaires</div>
    </div>
    <div class="pc" data-teinte="bleu">
      <div class="pc-h"><span class="pc-i">🧾</span><span class="pc-l">Panier moyen</span></div>
      <div class="pc-v" data-val="<?= (int)$poulsMoyen ?>">0</div>
      <div class="pc-u">sur <?= (int)$poulsDocs ?> document<?= $poulsDocs > 1 ? 's' : '' ?></div>
    </div>
  </div>

  <!-- ---------- Le graphique ---------- -->
  <div class="pouls-graphe">
    <div class="pg-barre">
      <div class="pg-series">
        <button type="button" class="pgs actif" data-serie="entrees">
          <i style="background:linear-gradient(135deg,#e9c15c,#d4a526)"></i>Encaissements</button>
        <button type="button" class="pgs actif" data-serie="depenses">
          <i style="background:linear-gradient(135deg,#fca5a5,#f87171)"></i>Dépenses</button>
        <button type="button" class="pgs" data-serie="marge">
          <i style="background:linear-gradient(135deg,#6ee7b7,#10b981)"></i>Marge</button>
      </div>
      <div class="pg-mode">
        <button type="button" class="pgm actif" data-mode="aire">Aires</button>
        <button type="button" class="pgm" data-mode="barres">Barres</button>
      </div>
    </div>
    <div class="pg-zone">
      <svg id="pg-svg" viewBox="0 0 900 320" preserveAspectRatio="none" aria-label="Évolution sur douze mois"></svg>
      <div id="pg-bulle" class="pg-bulle" hidden></div>
    </div>
  </div>

  <!-- ---------- Trois lectures complémentaires ---------- -->
  <div class="pouls-bas">

    <div class="pb-bloc">
      <div class="pb-t">💳 D'où vient l'argent</div>
      <?php if ($moyens): $totM = array_sum(array_column($moyens, 'val')) ?: 1; ?>
      <div class="pb-liste">
        <?php foreach ($moyens as $i => $m): $part = $m['val'] / $totM * 100; ?>
        <div class="pl">
          <div class="pl-h"><span><?= e($m['nom']) ?></span><b><?= number_format($part, 0) ?> %</b></div>
          <div class="pl-j"><span class="pl-f" data-largeur="<?= round($part, 1) ?>"
                style="background:linear-gradient(90deg,<?= ['#e9c15c','#7dd3fc','#a78bfa','#6ee7b7','#fbbf24','#f472b6'][$i % 6] ?>,<?= ['#d4a526','#38bdf8','#8b5cf6','#10b981','#f0b429','#ec4899'][$i % 6] ?>)"></span></div>
          <div class="pl-m"><?= money($m['val'], $devise) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?><p class="pb-vide">Aucun encaissement sur la période.</p><?php endif; ?>
    </div>

    <div class="pb-bloc">
      <div class="pb-t">📤 Où part l'argent</div>
      <?php if ($poulsPostes): $totP = array_sum(array_column($poulsPostes, 'val')) ?: 1; ?>
      <div class="pb-liste">
        <?php foreach ($poulsPostes as $i => $p): $part = $p['val'] / $totP * 100; ?>
        <div class="pl">
          <div class="pl-h"><span><?= e($p['nom']) ?></span><b><?= number_format($part, 0) ?> %</b></div>
          <div class="pl-j"><span class="pl-f" data-largeur="<?= round($part, 1) ?>"
                style="background:linear-gradient(90deg,#fca5a5,#f87171)"></span></div>
          <div class="pl-m"><?= money($p['val'], $devise) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?><p class="pb-vide">Aucune dépense sur la période.</p><?php endif; ?>
    </div>

    <div class="pb-bloc">
      <div class="pb-t">🏆 Vos prestations les plus rentables</div>
      <?php if ($poulsTop): ?>
      <div class="pb-podium">
        <?php foreach ($poulsTop as $rang => $a): ?>
        <a class="pp" href="finances.php">
          <span class="pp-r"><?= ['🥇','🥈','🥉'][$rang] ?></span>
          <div class="pp-t">
            <strong><?= e($a['activite'] ?: $a['numero']) ?></strong>
            <span><?= e($a['client'] ?: 'Client de passage') ?></span>
          </div>
          <div class="pp-m">
            <b><?= money($a['marge'], $devise) ?></b>
            <span><?= number_format($a['taux'], 0) ?> %</span>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <p class="pb-vide">Rattachez vos dépenses à une prestation pour voir apparaître
        ses bénéfices ici.</p>
      <?php endif; ?>
    </div>

  </div>
</div>

<script>
(function () {
  var svg   = document.getElementById('pg-svg');
  var bulle = document.getElementById('pg-bulle');
  if (!svg) return;

  var MOIS = <?= json_encode($pouls['mois'], JSON_UNESCAPED_UNICODE) ?>;
  var DATA = {
    entrees:  <?= json_encode(array_map('round', $pouls['entrees'])) ?>,
    depenses: <?= json_encode(array_map('round', $pouls['depenses'])) ?>,
    marge:    <?= json_encode(array_map('round', $pouls['marge'])) ?>
  };
  var COULEURS = {
    entrees:  ['#e9c15c', '#d4a526'],
    depenses: ['#fca5a5', '#f87171'],
    marge:    ['#6ee7b7', '#10b981']
  };
  var NOMS = { entrees: 'Encaissements', depenses: 'Dépenses', marge: 'Marge' };

  var actives = ['entrees', 'depenses'];
  var mode = 'aire';
  var anime = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var dejaVu = false;

  var L = 900, H = 320, MG = 58, MD = 18, MH = 22, MB = 40;
  var lg = L - MG - MD, ht = H - MH - MB;

  function fmt(v) {
    var a = Math.abs(v);
    if (a >= 1e9) return (v / 1e9).toFixed(1).replace('.0', '') + ' Md';
    if (a >= 1e6) return (v / 1e6).toFixed(1).replace('.0', '') + ' M';
    if (a >= 1e3) return Math.round(v / 1e3) + ' k';
    return String(Math.round(v));
  }
  function fmtLong(v) {
    return Math.round(v).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  }

  /* Échelle : on tient compte des valeurs négatives (une marge peut l'être). */
  function bornes() {
    var vals = [];
    actives.forEach(function (s) { vals = vals.concat(DATA[s]); });
    if (!vals.length) vals = [0];
    var mx = Math.max.apply(null, vals.concat([0]));
    var mn = Math.min.apply(null, vals.concat([0]));
    if (mx === mn) mx = mn + 1;
    return { mx: mx * 1.08, mn: mn < 0 ? mn * 1.12 : 0 };
  }

  function y(v, b) { return MH + ht - ((v - b.mn) / (b.mx - b.mn)) * ht; }
  function x(i) { return MG + (MOIS.length > 1 ? i * (lg / (MOIS.length - 1)) : lg / 2); }

  /* Courbe adoucie : des segments droits feraient « graphique de tableur ». */
  function chemin(vals, b) {
    var d = '';
    for (var i = 0; i < vals.length; i++) {
      var px = x(i), py = y(vals[i], b);
      if (i === 0) { d += 'M' + px + ',' + py; continue; }
      var pxp = x(i - 1), pyp = y(vals[i - 1], b), mx = (pxp + px) / 2;
      d += 'C' + mx + ',' + pyp + ' ' + mx + ',' + py + ' ' + px + ',' + py;
    }
    return d;
  }

  function dessiner() {
    var b = bornes();
    var el = '';

    /* Dégradés et filtres */
    el += '<defs>';
    Object.keys(COULEURS).forEach(function (s) {
      el += '<linearGradient id="g-' + s + '" x1="0" y1="0" x2="0" y2="1">'
          + '<stop offset="0%" stop-color="' + COULEURS[s][0] + '" stop-opacity=".42"/>'
          + '<stop offset="100%" stop-color="' + COULEURS[s][1] + '" stop-opacity="0"/></linearGradient>';
      el += '<linearGradient id="b-' + s + '" x1="0" y1="0" x2="0" y2="1">'
          + '<stop offset="0%" stop-color="' + COULEURS[s][0] + '"/>'
          + '<stop offset="100%" stop-color="' + COULEURS[s][1] + '"/></linearGradient>';
    });
    el += '<filter id="lueur"><feGaussianBlur stdDeviation="3.5" result="f"/>'
        + '<feMerge><feMergeNode in="f"/><feMergeNode in="SourceGraphic"/></feMerge></filter>';
    el += '</defs>';

    /* Repères horizontaux et échelle */
    for (var k = 0; k <= 4; k++) {
      var v = b.mn + (b.mx - b.mn) * (k / 4), py = y(v, b);
      el += '<line x1="' + MG + '" y1="' + py + '" x2="' + (L - MD) + '" y2="' + py
          + '" stroke="rgba(255,255,255,.07)" stroke-width="1"/>';
      el += '<text x="' + (MG - 10) + '" y="' + (py + 4) + '" text-anchor="end" '
          + 'fill="rgba(255,255,255,.36)" font-size="11">' + fmt(v) + '</text>';
    }
    /* La ligne du zéro, plus marquée : elle sépare le gain de la perte. */
    if (b.mn < 0) {
      el += '<line x1="' + MG + '" y1="' + y(0, b) + '" x2="' + (L - MD) + '" y2="' + y(0, b)
          + '" stroke="rgba(255,255,255,.22)" stroke-width="1.5" stroke-dasharray="4 4"/>';
    }

    /* Mois */
    MOIS.forEach(function (m, i) {
      el += '<text x="' + x(i) + '" y="' + (H - 14) + '" text-anchor="middle" '
          + 'fill="rgba(255,255,255,.42)" font-size="11.5">' + m + '</text>';
    });

    if (mode === 'barres') {
      var largeur = Math.max(6, (lg / MOIS.length) / (actives.length + 1));
      actives.forEach(function (s, si) {
        DATA[s].forEach(function (v, i) {
          var hb = Math.abs(y(v, b) - y(0, b));
          var py = v >= 0 ? y(v, b) : y(0, b);
          var px = x(i) - (largeur * actives.length) / 2 + si * largeur;
          el += '<rect class="pg-bar" x="' + px + '" y="' + py + '" width="' + (largeur - 2)
              + '" height="' + hb + '" rx="3" fill="url(#b-' + s + ')" '
              + 'style="transform-origin:' + px + 'px ' + y(0, b) + 'px"/>';
        });
      });
    } else {
      actives.forEach(function (s) {
        var d = chemin(DATA[s], b);
        var base = y(Math.max(0, b.mn), b);
        el += '<path d="' + d + ' L' + x(MOIS.length - 1) + ',' + base + ' L' + x(0) + ',' + base
            + ' Z" fill="url(#g-' + s + ')" class="pg-aire"/>';
        el += '<path d="' + d + '" fill="none" stroke="url(#b-' + s + ')" stroke-width="2.6" '
            + 'stroke-linecap="round" filter="url(#lueur)" class="pg-ligne"/>';
        DATA[s].forEach(function (v, i) {
          el += '<circle class="pg-pt" cx="' + x(i) + '" cy="' + y(v, b) + '" r="3.4" '
              + 'fill="' + COULEURS[s][1] + '" stroke="#0a1020" stroke-width="1.6"/>';
        });
      });
    }

    /* Zones de survol : une par mois, invisibles */
    MOIS.forEach(function (m, i) {
      var larg = lg / MOIS.length;
      el += '<rect class="pg-hit" data-i="' + i + '" x="' + (x(i) - larg / 2) + '" y="' + MH
          + '" width="' + larg + '" height="' + ht + '" fill="transparent"/>';
    });
    el += '<line id="pg-guide" x1="0" y1="' + MH + '" x2="0" y2="' + (MH + ht)
        + '" stroke="rgba(240,193,75,.5)" stroke-width="1" stroke-dasharray="3 3" opacity="0"/>';

    svg.innerHTML = el;
    brancherSurvol(b);

    if (anime) {
      svg.querySelectorAll('.pg-ligne').forEach(function (p, n) {
        var lgr = p.getTotalLength();
        p.style.strokeDasharray = lgr; p.style.strokeDashoffset = lgr;
        p.style.transition = 'stroke-dashoffset 1.5s cubic-bezier(.4,0,.2,1) ' + (n * .18) + 's';
        requestAnimationFrame(function () { p.style.strokeDashoffset = 0; });
      });
      svg.querySelectorAll('.pg-aire').forEach(function (a, n) {
        a.style.opacity = 0; a.style.transition = 'opacity .9s ease ' + (.35 + n * .18) + 's';
        requestAnimationFrame(function () { a.style.opacity = 1; });
      });
      svg.querySelectorAll('.pg-pt').forEach(function (c, n) {
        c.style.opacity = 0; c.style.transition = 'opacity .4s ease ' + (.7 + n * .022) + 's';
        requestAnimationFrame(function () { c.style.opacity = 1; });
      });
      svg.querySelectorAll('.pg-bar').forEach(function (r, n) {
        r.style.transform = 'scaleY(0)';
        r.style.transition = 'transform .8s cubic-bezier(.2,.9,.3,1.2) ' + (n * .025) + 's';
        requestAnimationFrame(function () { r.style.transform = 'scaleY(1)'; });
      });
    }
  }

  function brancherSurvol(b) {
    var guide = svg.querySelector('#pg-guide');
    svg.querySelectorAll('.pg-hit').forEach(function (z) {
      z.addEventListener('mouseenter', function () {
        var i = +this.dataset.i;
        if (guide) { guide.setAttribute('x1', x(i)); guide.setAttribute('x2', x(i)); guide.setAttribute('opacity', 1); }
        var h = '<div class="pb-mois">' + MOIS[i] + '</div>';
        actives.forEach(function (s) {
          h += '<div class="pb-l"><i style="background:' + COULEURS[s][1] + '"></i>'
             + '<span>' + NOMS[s] + '</span><b>' + fmtLong(DATA[s][i]) + '</b></div>';
        });
        bulle.innerHTML = h;
        bulle.hidden = false;
        var r = svg.getBoundingClientRect();
        var px = (x(i) / L) * r.width;
        bulle.style.left = Math.min(Math.max(px, 90), r.width - 90) + 'px';
      });
    });
    svg.addEventListener('mouseleave', function () {
      bulle.hidden = true;
      if (guide) guide.setAttribute('opacity', 0);
    });
  }

  /* Compteurs : les chiffres montent, ce qui attire l'œil sur l'essentiel. */
  function compter() {
    document.querySelectorAll('#pouls .pc-v').forEach(function (el) {
      var cible = parseFloat(el.dataset.val) || 0;
      if (!anime) { el.textContent = fmtLong(cible); return; }
      var t0 = null, duree = 1400;
      function pas(t) {
        if (!t0) t0 = t;
        var p = Math.min((t - t0) / duree, 1);
        var e = 1 - Math.pow(1 - p, 3);
        el.textContent = fmtLong(cible * e);
        if (p < 1) requestAnimationFrame(pas);
      }
      requestAnimationFrame(pas);
    });
    document.querySelectorAll('#pouls .pl-f').forEach(function (f, n) {
      var l = f.dataset.largeur + '%';
      if (!anime) { f.style.width = l; return; }
      f.style.width = '0';
      f.style.transition = 'width 1.1s cubic-bezier(.3,.9,.3,1) ' + (n * .07) + 's';
      requestAnimationFrame(function () { f.style.width = l; });
    });
  }

  /* Séries et mode d'affichage */
  document.querySelectorAll('#pouls .pgs').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var s = this.dataset.serie, i = actives.indexOf(s);
      if (i >= 0) { if (actives.length === 1) return; actives.splice(i, 1); this.classList.remove('actif'); }
      else { actives.push(s); this.classList.add('actif'); }
      dessiner();
    });
  });
  document.querySelectorAll('#pouls .pgm').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('#pouls .pgm').forEach(function (b2) { b2.classList.remove('actif'); });
      this.classList.add('actif');
      mode = this.dataset.mode;
      dessiner();
    });
  });

  /* On n'anime qu'au moment où la section entre à l'écran. */
  function lancer() { if (dejaVu) return; dejaVu = true; dessiner(); compter(); }
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (ents, obs) {
      ents.forEach(function (en) { if (en.isIntersecting) { lancer(); obs.disconnect(); } });
    }, { threshold: .18 }).observe(document.getElementById('pouls'));
  } else { lancer(); }

  var minuteur;
  window.addEventListener('resize', function () {
    clearTimeout(minuteur);
    minuteur = setTimeout(function () { if (dejaVu) { anime = false; dessiner(); } }, 200);
  });
})();
</script>

<?php endif; ?>

<?php admin_footer(); ?>
