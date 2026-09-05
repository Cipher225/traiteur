<?php
require __DIR__ . '/inc.php';
$pdo->prepare('UPDATE factures SET vu_client=1 WHERE client_id=? AND vu_client=0')->execute([(int)$CLIENT['id']]);
$pdo->prepare('UPDATE recus SET vu_client=1 WHERE client_id=? AND vu_client=0')->execute([(int)$CLIENT['id']]);
require __DIR__ . '/../admin/includes/documents.php';
$devise = $settings['devise'] ?? 'FCFA';
$cid = (int)$CLIENT['id'];

// Soumission d'un avis
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['avis'])) {
    csrf_check();
    $texte = trim($_POST['texte'] ?? '');
    $note = min(5, max(1, (int)($_POST['note'] ?? 5)));
    if ($texte === '') { flash('Votre avis ne peut pas être vide.', 'error'); }
    else {
        $pdo->prepare("INSERT INTO temoignages (nom, email, texte, note, statut, actif) VALUES (?,?,?,?, 'en_attente', 0)")
            ->execute([mb_substr($CLIENT['nom'],0,100), mb_substr($CLIENT['email'] ?? '',0,120), mb_substr($texte,0,800), $note]);
        flash('Merci beaucoup pour votre avis ! 🙏');
    }
    header('Location: index.php#avis'); exit;
}

// Documents du client
$factures = $pdo->prepare("SELECT * FROM factures WHERE client_id=? ORDER BY date_emission DESC, id DESC");
$factures->execute([$cid]); $factures = $factures->fetchAll();
$recus = $pdo->prepare("SELECT * FROM recus WHERE client_id=? ORDER BY date_paiement DESC, id DESC");
$recus->execute([$cid]); $recus = $recus->fetchAll();

$total_fac = 0; $nb_fac = 0; $nb_pro = 0;
foreach ($factures as $k => $f) {
    $doc = get_facture($pdo, (int)$f['id']);
    $ttc = $doc['montant_ttc'] ?? 0;
    $factures[$k]['_ttc'] = $ttc;
    if (($f['type'] ?? 'facture') === 'proforma') $nb_pro++; else { $nb_fac++; $total_fac += $ttc; }
}
$total_recu = array_sum(array_map(fn($r)=>(float)$r['montant'], $recus));

$statutBadge = ['brouillon'=>'badge-gold','envoyee'=>'badge-violet','payee'=>'badge-teal','annulee'=>'badge-danger'];
$statutLabel = ['brouillon'=>'Brouillon','envoyee'=>'Envoyée','payee'=>'Payée','annulee'=>'Annulée'];

// Mon dernier avis
$monAvis = $pdo->prepare("SELECT * FROM temoignages WHERE nom=? ORDER BY id DESC LIMIT 1");
$monAvis->execute([$CLIENT['nom']]); $monAvis = $monAvis->fetch();

client_header('Mon espace', 'accueil', $settings, $CLIENT);
?>
<div class="stats">
  <div class="stat glass violet"><div class="s-ico">🧾</div><div class="s-num"><?= $nb_fac ?></div><div class="s-label">Factures</div></div>
  <div class="stat glass gold"><div class="s-ico">📋</div><div class="s-num"><?= $nb_pro ?></div><div class="s-label">Devis (proforma)</div></div>
  <div class="stat glass teal"><div class="s-ico">💳</div><div class="s-num" style="font-size:20px"><?= money($total_recu, $devise) ?></div><div class="s-label">Total réglé</div></div>
  <div class="stat glass rose"><div class="s-ico">📄</div><div class="s-num"><?= count($recus) ?></div><div class="s-label">Sorties</div></div>
</div>

<?php
/* Séparer les documents par type puis regrouper par année/mois */
require_once __DIR__ . '/../admin/includes/rangement.php';
$mesFactures = array_values(array_filter($factures, fn($f)=>($f['type'] ?? 'facture')==='facture'));
$mesProformas = array_values(array_filter($factures, fn($f)=>($f['type'] ?? 'facture')==='proforma'));

/* Rendu d'une section rangée par date pour l'espace client */
$sectionClient = function($titre, $icone, $docs, $dateKey, $typeParam) use ($devise, $statutBadge, $statutLabel) {
    if (!$docs) return;
    $arbre = rangement_par_mois($docs, $dateKey);
    ?>
    <div class="panel glass">
      <h2><?= $icone ?> <?= e($titre) ?> (<?= count($docs) ?>)</h2>
      <div class="rng-tree">
        <?php foreach ($arbre as $annee => $mois): $nbA=0; foreach($mois as $ds) $nbA+=count($ds); ?>
        <details class="rng-annee" open>
          <summary><?= $annee ?><span class="cnt"><?= $nbA ?></span></summary>
          <?php foreach ($mois as $m => $docsM): ?>
          <details class="rng-mois" open>
            <summary><?= rangement_mois_fr((int)$m) ?><span class="cnt"><?= count($docsM) ?></span></summary>
            <div class="rng-docs">
              <?php foreach ($docsM as $d): ?>
              <div class="rng-doc">
                <span class="num"><?= e($d['numero']) ?></span>
                <span class="dt"><?= date('d/m/Y', strtotime($d[$dateKey])) ?></span>
                <?php if (isset($d['_ttc'])): ?><span class="mt"><?= money($d['_ttc'], $devise) ?></span>
                <?php elseif (isset($d['montant'])): ?><span class="mt"><?= money($d['montant'], $devise) ?></span><?php endif; ?>
                <span class="acts"><a class="btn btn-glass btn-sm" href="doc-pdf.php?type=<?= $typeParam ?>&id=<?= $d['id'] ?>" target="_blank">📄 Voir</a>
                  <a class="btn btn-gold btn-sm" href="doc-pdf.php?type=<?= $typeParam ?>&id=<?= $d['id'] ?>&dl=1">⬇️ Télécharger</a></span>
              </div>
              <?php endforeach; ?>
            </div>
          </details>
          <?php endforeach; ?>
        </details>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
};
?>

<div id="documents">
  <?php
  $sectionClient('Mes factures', '🧾', $mesFactures, 'date_emission', 'facture');
  $sectionClient('Mes devis (proformas)', '📋', $mesProformas, 'date_emission', 'proforma');
  $sectionClient('Mes bons de sortie', '📄', $recus, 'date_paiement', 'recu');
  if (!$factures && !$recus): ?>
    <div class="panel glass"><div style="text-align:center;padding:34px;color:var(--ink-faint)">Aucun document pour le moment.</div></div>
  <?php endif; ?>
</div>

<div class="panel glass" id="avis">
  <h2>⭐ Laisser un avis</h2>
  <?php if ($monAvis): ?>
    <div class="flash" style="margin-bottom:16px">
      Votre dernier avis (<?= str_repeat('★', (int)$monAvis['note']) ?>) :
      <?php if ($monAvis['statut']==='valide'): ?><span class="badge badge-teal">Publié sur le site</span>
      <?php elseif ($monAvis['statut']==='rejete'): ?><span class="badge badge-danger">Non retenu</span>
      <?php else: ?><span class="badge badge-gold">En attente de validation</span><?php endif; ?>
    </div>
  <?php endif; ?>
  <p style="color:var(--ink-dim);font-size:13.5px;margin-bottom:14px">Partagez votre expérience avec nous.</p>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="avis" value="1">
    <div class="field"><label>Votre note</label>
      <select class="input" name="note">
        <?php for($i=5;$i>=1;$i--): ?><option value="<?= $i ?>"><?= str_repeat('★',$i) ?> (<?= $i ?>/5)</option><?php endfor; ?>
      </select>
    </div>
    <div class="field full"><label>Votre message</label><textarea class="input" name="texte" required placeholder="Racontez-nous votre expérience avec nos services…"></textarea></div>
    <div class="full"><button class="btn btn-gold">Envoyer mon avis</button></div>
  </form>
</div>
<?php client_footer(); ?>
