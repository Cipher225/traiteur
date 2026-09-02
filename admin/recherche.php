<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';

/* ============================================================================
   RECHERCHE GLOBALE
   ----------------------------------------------------------------------------
   Un seul champ pour retrouver une facture, un client, un employé, un article
   ou un document, sans savoir dans quelle section chercher. Les résultats
   respectent les droits : un employé ne voit que les sections qui lui sont
   ouvertes.
   ============================================================================ */

$q = trim($_GET['q'] ?? '');
$resultats = [];
$total = 0;

if (mb_strlen($q) >= 2) {
    $like = '%' . $q . '%';

    /* Chaque source : titre, icône, permission requise, requête, mise en forme */
    $sources = [
        'factures' => ['🧾', 'Factures et proformas', 'factures',
            "SELECT f.id, f.numero, f.type, f.date_emission, f.statut,
                    COALESCE(NULLIF(c.entreprise,''), c.nom) AS client
             FROM factures f LEFT JOIN clients c ON c.id = f.client_id
             WHERE f.numero LIKE ? OR c.nom LIKE ? OR c.entreprise LIKE ? OR f.activite LIKE ?
             ORDER BY f.date_emission DESC LIMIT 12",
            4],

        'clients' => ['👥', 'Clients', 'clients',
            "SELECT id, nom, entreprise, telephone, email FROM clients
             WHERE nom LIKE ? OR entreprise LIKE ? OR telephone LIKE ? OR email LIKE ?
             ORDER BY nom LIMIT 12", 4],

        'recus' => ['💵', 'Entrées et sorties', 'bons_entree',
            "SELECT r.id, r.numero, r.type, r.montant, r.date_paiement, r.motif,
                    COALESCE(NULLIF(c.entreprise,''), c.nom) AS client
             FROM recus r LEFT JOIN clients c ON c.id = r.client_id
             WHERE r.numero LIKE ? OR r.motif LIKE ? OR c.nom LIKE ?
             ORDER BY r.date_paiement DESC LIMIT 12", 3],

        'employes' => ['🧑‍🍳', 'Employés', 'employes',
            "SELECT id, nom, poste, matricule, telephone FROM employes
             WHERE COALESCE(fiche_perso,0)=0 AND (nom LIKE ? OR poste LIKE ? OR matricule LIKE ?)
             ORDER BY nom LIMIT 12", 3],

        'stock' => ['📦', 'Stock', 'stock',
            "SELECT id, nom, categorie, quantite, unite FROM stock_articles
             WHERE nom LIKE ? OR categorie LIKE ? ORDER BY nom LIMIT 12", 2],

        'documents' => ['📝', 'Documents rédigés', 'documents',
            "SELECT id, titre, categorie, statut, updated_at FROM documents_texte
             WHERE titre LIKE ? OR categorie LIKE ? ORDER BY updated_at DESC LIMIT 12", 2],

        'commandes' => ['📦', 'Commandes clients', 'commandes_client',
            "SELECT cc.id, cc.numero, cc.date_evenement, cc.lieu, cc.statut,
                    COALESCE(NULLIF(c.entreprise,''), c.nom) AS client
             FROM commandes_client cc LEFT JOIN clients c ON c.id = cc.client_id
             WHERE cc.numero LIKE ? OR cc.lieu LIKE ? OR c.nom LIKE ?
             ORDER BY cc.date_evenement DESC LIMIT 12", 3],
    ];

    foreach ($sources as $cle => [$ico, $titre, $perm, $sql, $nbParams]) {
        if (!peut_acceder($perm)) continue;
        try {
            $st = $pdo->prepare($sql);
            $st->execute(array_fill(0, $nbParams, $like));
            $lignes = $st->fetchAll();
            if ($lignes) {
                $resultats[$cle] = ['ico' => $ico, 'titre' => $titre, 'lignes' => $lignes];
                $total += count($lignes);
            }
        } catch (Throwable $e) { /* table absente : on passe */ }
    }
}

admin_header('Recherche', 'recherche', $pdo, $settings);
$devise = $settings['devise'] ?? 'FCFA';
?>

<div class="panel glass" style="margin-bottom:14px">
  <h2>🔍 Recherche globale</h2>
  <form method="get" style="display:flex;gap:9px;flex-wrap:wrap">
    <input class="input" name="q" value="<?= e($q) ?>" autofocus
           placeholder="Numéro de facture, nom de client, article, employé…" style="flex:1;min-width:220px">
    <button class="btn btn-gold">Rechercher</button>
  </form>
  <?php if (mb_strlen($q) >= 2): ?>
  <p style="margin:12px 0 0;font-size:13px;color:var(--ink-faint)">
    <?= $total ?> résultat<?= $total > 1 ? 's' : '' ?> pour « <strong><?= e($q) ?></strong> »</p>
  <?php elseif ($q !== ''): ?>
  <p style="margin:12px 0 0;font-size:13px;color:#f0b429">Saisissez au moins 2 caractères.</p>
  <?php endif; ?>
</div>

<?php if (mb_strlen($q) >= 2 && !$resultats): ?>
<div class="panel glass">
  <p style="color:var(--ink-faint);margin:0">Aucun résultat. Essayez avec un autre terme,
     par exemple un numéro de facture ou un nom de famille.</p>
</div>
<?php endif; ?>

<?php foreach ($resultats as $cle => $bloc): ?>
<div class="panel glass" style="margin-bottom:12px">
  <h2><?= $bloc['ico'] ?> <?= e($bloc['titre']) ?> (<?= count($bloc['lignes']) ?>)</h2>
  <div class="rech-liste">
    <?php foreach ($bloc['lignes'] as $l): ?>
    <?php
      // Lien et description adaptés à chaque type
      switch ($cle) {
        case 'factures':
          $lien = ($l['type'] === 'proforma' ? 'factures.php?doc=proforma' : 'factures.php');
          $t = $l['numero']; $d = ($l['client'] ?? '—') . ' · ' . date('d/m/Y', strtotime($l['date_emission'])) . ' · ' . ucfirst($l['statut']);
          $a = 'print.php?type=' . ($l['type'] === 'proforma' ? 'proforma' : 'facture') . '&id=' . (int)$l['id'] . '&auth=1'; break;
        case 'clients':
          $lien = 'clients.php?edit=' . (int)$l['id'];
          $t = trim(($l['entreprise'] ?? '') !== '' ? $l['entreprise'] : $l['nom']);
          $d = trim(($l['telephone'] ?? '') . ' ' . ($l['email'] ?? '')) ?: 'Aucun contact'; $a = ''; break;
        case 'recus':
          $lien = 'recus.php' . ($l['type'] === 'entree' ? '?type=entree' : '');
          $t = $l['numero']; $d = ($l['client'] ?? '—') . ' · ' . number_format((float)$l['montant'], 0, ',', ' ') . ' ' . $devise
             . ' · ' . date('d/m/Y', strtotime($l['date_paiement']));
          $a = 'print.php?type=recu&id=' . (int)$l['id'] . '&auth=1'; break;
        case 'employes':
          $lien = 'employes.php?edit=' . (int)$l['id'];
          $t = $l['nom']; $d = trim(($l['poste'] ?? '') . ' · ' . ($l['matricule'] ?? '')); $a = ''; break;
        case 'stock':
          $lien = 'stock.php?edit=' . (int)$l['id'];
          $t = $l['nom']; $d = ($l['categorie'] ?? '') . ' · ' . (float)$l['quantite'] . ' ' . ($l['unite'] ?? ''); $a = ''; break;
        case 'documents':
          $lien = 'documents.php?edit=' . (int)$l['id'];
          $t = $l['titre']; $d = ($l['categorie'] ?? '') . ' · ' . ucfirst($l['statut'] ?? 'brouillon');
          $a = 'doc-imprimer.php?id=' . (int)$l['id']; break;
        default:
          $lien = 'commandes-client.php?voir=' . (int)$l['id'];
          $t = $l['numero']; $d = ($l['client'] ?? '—') . ' · ' . ($l['lieu'] ?: 'lieu non précisé')
             . ($l['date_evenement'] ? ' · ' . date('d/m/Y', strtotime($l['date_evenement'])) : ''); $a = '';
      }
    ?>
    <a class="rech-item" href="<?= $lien ?>">
      <div class="rech-txt">
        <div class="rech-titre"><?= e($t) ?></div>
        <div class="rech-desc"><?= e($d) ?></div>
      </div>
      <?php if ($a): ?><span class="rech-doc" onclick="event.preventDefault();window.open('<?= $a ?>','_blank')">🧾</span><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<?php admin_footer(); ?>
