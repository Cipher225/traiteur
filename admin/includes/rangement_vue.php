<?php
/* ============================================================================
   COMPOSANT D'AFFICHAGE DU RANGEMENT (filtres + arborescence)
   Attend en variables :
     $facturesAff (docs filtrés, avec _ttc), $factures (tous, pour le compteur),
     $clients, $anneesRng, $fRng, $vueRng, $doc, $isPro, $devise,
     $badges, $labels, $baseUrl (ex: 'factures.php' ou 'factures.php?doc=proforma')
   ============================================================================ */
$sep = strpos($baseUrl, '?') !== false ? '&' : '?';
$moisFr = fn($m) => rangement_mois_fr((int)$m);

/* Rendu d'un document (ligne compacte, réutilisé dans arbre et liste) */
$renderDoc = function($f) use ($doc, $devise) {
    $ttc = $f['_ttc'] ?? 0;
    $statutsLbl = ['brouillon'=>'Brouillon','envoyee'=>'Envoyée','payee'=>'Payée','annulee'=>'Annulée'];
    $statutsBadge = ['brouillon'=>'badge','envoyee'=>'badge-violet','payee'=>'badge-teal','annulee'=>'badge-danger'];
    $stAct = $f['statut'] ?? 'brouillon';
    ob_start(); ?>
    <div class="rng-doc">
      <span class="num"><?= e($f['numero']) ?></span>
      <span class="dt"><?= date('d/m/Y', strtotime($f['date_emission'])) ?></span>
      <span class="mt"><?= money($ttc, $devise) ?></span>
      <?php if ($doc !== 'proforma'): ?>
      <span class="badge <?= $statutsBadge[$stAct] ?? 'badge' ?> st-badge"><?= $statutsLbl[$stAct] ?? $stAct ?></span>
      <?php endif; ?>
      <span class="acts">
        <?php if($doc !== 'proforma' && is_admin()): ?>
        <form method="post" style="display:inline" class="st-form">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="id_statut" value="<?= $f['id'] ?>">
          <input type="hidden" name="doc_ctx" value="<?= $doc ?>">
          <select class="st-select" name="statut" onchange="this.form.submit()" title="Changer le statut">
            <?php foreach ($statutsLbl as $sv => $sl): ?>
            <option value="<?= $sv ?>" <?= $stAct===$sv?'selected':'' ?>><?= $sl ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php endif; ?>
        <a class="btn btn-glass btn-sm" href="print.php?type=<?= $doc ?>&id=<?= $f['id'] ?>" target="_blank" title="Voir / Imprimer">📄</a>
        <?php if(is_admin()): ?><a class="btn btn-glass btn-sm" href="print.php?type=<?= $doc ?>&id=<?= $f['id'] ?>&auth=1" target="_blank" title="Authentifiable">🔐</a><?php endif; ?>
        <?php if($doc==='facture'): ?><a class="btn btn-glass btn-sm" href="print.php?type=livraison&id=<?= $f['id'] ?><?= is_admin() ? '&auth=1' : '' ?>" target="_blank" title="Bon de livraison">🚚</a><?php endif; ?>
        <?php /* PDF fabriqué par le serveur : rendu identique sur tous les appareils */ ?>
        <a class="btn btn-gold btn-sm" href="pdf.php?type=<?= $doc ?>&id=<?= $f['id'] ?>&dl=1" title="Télécharger en PDF">⬇️</a>
        <a class="btn btn-glass btn-sm" href="<?= $doc==='proforma'?'factures.php?doc=proforma&':'factures.php?' ?>edit=<?= $f['id'] ?>" title="Modifier">✏️</a>
        <?php if(is_admin()): ?>
        <form method="post" style="display:inline" data-confirm="Envoyer ce document par email au client ?">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="doc_ctx" value="<?= $doc ?>">
          <button class="btn btn-glass btn-sm" name="envoyer_mail" value="<?= $f['id'] ?>" title="Envoyer par email au client">✉️</button>
        </form>
        <?php endif; ?>
        <?php if($doc==='proforma'): ?>
        <form method="post" style="display:inline" data-confirm="Convertir cette proforma en facture définitive ?">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <button class="btn btn-gold btn-sm" name="convertir" value="<?= $f['id'] ?>" title="Transformer en facture">🧾 Facturer</button>
        </form>
        <?php endif; ?>
        <?php if(is_admin()): ?>
        <form method="post" style="display:inline" data-confirm="Supprimer définitivement ce document ? Il disparaîtra aussi du coffre. Cette action est irréversible.">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="doc_ctx" value="<?= $doc ?>">
          <button class="btn btn-danger btn-sm" name="supprimer" value="<?= $f['id'] ?>" title="Supprimer">🗑️</button>
        </form>
        <?php endif; ?>
      </span>
    </div>
    <?php return ob_get_clean();
};
?>

<?php if (!$isPro && !empty($recap) && ($recap['nb_paye'] + $recap['nb_encaisser']) > 0): ?>
<div class="fact-recap">
  <div class="fr-item fr-encaisser">
    <span class="fr-lbl">🧾 À encaisser</span>
    <span class="fr-val"><?= money($recap['encaisser'], $devise) ?></span>
    <span class="fr-nb"><?= $recap['nb_encaisser'] ?> facture<?= $recap['nb_encaisser']>1?'s':'' ?></span>
  </div>
  <div class="fr-item fr-paye">
    <span class="fr-lbl">✅ Encaissé</span>
    <span class="fr-val"><?= money($recap['paye'], $devise) ?></span>
    <span class="fr-nb"><?= $recap['nb_paye'] ?> facture<?= $recap['nb_paye']>1?'s':'' ?></span>
  </div>
</div>
<?php endif; ?>

<!-- Filtres rapides -->
<form method="get" class="rng-filtres">
  <?php if ($isPro): ?><input type="hidden" name="doc" value="proforma"><?php endif; ?>
  <input type="hidden" name="vue" value="<?= e($vueRng) ?>">
  <div class="f">
    <label>Client</label>
    <select class="input" name="fc" onchange="this.form.submit()">
      <option value="0">Tous les clients</option>
      <?php foreach ($clients as $c): ?>
      <option value="<?= $c['id'] ?>" <?= $fRng['client']==$c['id']?'selected':'' ?>><?= e($c['nom']) ?><?= $c['entreprise']?' ('.e($c['entreprise']).')':'' ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="f">
    <label>Mois</label>
    <select class="input" name="fm" onchange="this.form.submit()">
      <option value="0">Tous</option>
      <?php for($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $fRng['mois']==$m?'selected':'' ?>><?= $moisFr($m) ?></option><?php endfor; ?>
    </select>
  </div>
  <?php if (!$isPro): ?>
  <div class="f">
    <label>Statut</label>
    <select class="input" name="fs" onchange="this.form.submit()">
      <option value="">Tous les statuts</option>
      <option value="impayees" <?= ($fStatut ?? '')==='impayees'?'selected':'' ?>>🧾 À encaisser</option>
      <option value="payee" <?= ($fStatut ?? '')==='payee'?'selected':'' ?>>✅ Payées</option>
      <option value="envoyee" <?= ($fStatut ?? '')==='envoyee'?'selected':'' ?>>Envoyées</option>
      <option value="brouillon" <?= ($fStatut ?? '')==='brouillon'?'selected':'' ?>>Brouillons</option>
      <option value="annulee" <?= ($fStatut ?? '')==='annulee'?'selected':'' ?>>Annulées</option>
    </select>
  </div>
  <?php endif; ?>
  <div class="f">
    <label>Année</label>
    <select class="input" name="fa" onchange="this.form.submit()">
      <option value="0">Toutes</option>
      <?php foreach ($anneesRng as $a): ?><option value="<?= $a ?>" <?= $fRng['annee']==$a?'selected':'' ?>><?= $a ?></option><?php endforeach; ?>
    </select>
  </div>
  <?php if ($fRng['client'] || $fRng['mois'] || $fRng['annee']): ?>
  <div class="f"><label>&nbsp;</label><a class="btn btn-glass btn-sm" href="<?= $baseUrl ?><?= $sep ?>vue=<?= $vueRng ?>">✕ Réinitialiser</a></div>
  <?php endif; ?>
  <div class="rng-vue">
    <a href="<?= $baseUrl ?><?= $sep ?>vue=arbre<?= $fRng['client']?'&fc='.$fRng['client']:'' ?><?= $fRng['mois']?'&fm='.$fRng['mois']:'' ?><?= $fRng['annee']?'&fa='.$fRng['annee']:'' ?>" class="<?= $vueRng==='arbre'?'on':'' ?>">🗂️ Arborescence</a>
    <a href="<?= $baseUrl ?><?= $sep ?>vue=liste<?= $fRng['client']?'&fc='.$fRng['client']:'' ?><?= $fRng['mois']?'&fm='.$fRng['mois']:'' ?><?= $fRng['annee']?'&fa='.$fRng['annee']:'' ?>" class="<?= $vueRng==='liste'?'on':'' ?>">📋 Liste</a>
  </div>
</form>

<?php if (!$facturesAff): ?>
  <div style="text-align:center;padding:40px;color:var(--ink-faint)">Aucun document ne correspond.</div>
<?php elseif ($vueRng === 'liste'): ?>
  <!-- Vue liste simple (filtrée) -->
  <div class="rng-docs" style="margin-left:0">
    <?php foreach ($facturesAff as $f) echo $renderDoc($f); ?>
  </div>
<?php else: ?>
  <!-- Vue arborescence : Année → Mois → Entreprise (ou nom du particulier) -->
  <?php $arbre = rangement_arbre($facturesAff, 'date_emission', '_rangement'); ?>
  <div class="rng-tree">
    <?php foreach ($arbre as $annee => $mois): $nbA = 0; foreach($mois as $cl) foreach($cl as $ds) $nbA += count($ds); ?>
    <details class="rng-annee" open>
      <summary><?= $annee ?><span class="cnt"><?= $nbA ?> doc<?= $nbA>1?'s':'' ?></span></summary>
      <?php foreach ($mois as $m => $clients): $nbM = 0; foreach($clients as $ds) $nbM += count($ds); ?>
      <details class="rng-mois" open>
        <summary><?= $moisFr($m) ?><span class="cnt"><?= $nbM ?></span></summary>
        <?php foreach ($clients as $client => $docs):
          $estEntreprise = (($docs[0]['type_client'] ?? '') === 'entreprise') || (trim((string)($docs[0]['entreprise'] ?? '')) !== '' && ($docs[0]['entreprise'] === $client));
          $ico = $estEntreprise ? '🏢' : '👤';
        ?>
        <details class="rng-client" open>
          <summary><?= $ico ?> <?= e($client) ?><span class="cnt"><?= count($docs) ?></span></summary>
          <div class="rng-docs">
            <?php foreach ($docs as $f) echo $renderDoc($f); ?>
          </div>
        </details>
        <?php endforeach; ?>
      </details>
      <?php endforeach; ?>
    </details>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
