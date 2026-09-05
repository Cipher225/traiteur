<?php
require __DIR__ . '/inc.php';
require __DIR__ . '/../config/wave.php';
require __DIR__ . '/../admin/includes/documents.php';

/* ============================================================================
   RETOUR DEPUIS WAVE
   Le client revient ici après avoir payé. On ne se fie JAMAIS au paramètre
   d'URL : le paiement est revérifié directement auprès de Wave avant d'être
   validé. C'est ce qui empêche qu'un lien trafiqué fasse passer une facture
   pour réglée.
   ============================================================================ */

$reference = trim($_GET['ref'] ?? '');
$paiement  = null;
$etat      = 'inconnu';
$message   = '';

if ($reference !== '') {
    $st = $pdo->prepare('SELECT * FROM paiements WHERE reference=? AND client_id=?');
    $st->execute([$reference, (int)$CLIENT['id']]);
    $paiement = $st->fetch();
}

if (!$paiement) {
    $etat = 'introuvable';
    $message = "Ce paiement est introuvable.";
} elseif ($paiement['statut'] === 'paye') {
    $etat = 'succes';                                   // déjà confirmé (webhook ou rafraîchissement)
} else {
    $verif = wave_verifier($pdo, $paiement);
    if (!$verif['ok']) {
        $etat = 'attente';
        $message = $verif['erreur'] . " Si le montant a été débité, votre reçu sera émis dès la confirmation de Wave.";
    } elseif (!empty($verif['paye'])) {
        $res = paiement_finaliser($pdo, $reference, $settings, [
            'transaction_id' => $verif['data']['transaction_id'] ?? ($verif['data']['id'] ?? ''),
            'source' => 'retour_client',
        ]);
        if ($res['ok']) {
            $etat = 'succes';
            $st = $pdo->prepare('SELECT * FROM paiements WHERE reference=?');
            $st->execute([$reference]);
            $paiement = $st->fetch();
        } else {
            $etat = 'attente';
            $message = $res['erreur'];
        }
    } else {
        $etat = ($_GET['resultat'] ?? '') === 'echec' ? 'echec' : 'attente';
        if ($etat === 'echec') {
            $pdo->prepare("UPDATE paiements SET statut='echoue', detail=? WHERE reference=? AND statut='en_attente'")
                ->execute(['Paiement non abouti', $reference]);
            $message = "Le paiement n'a pas abouti. Aucun montant n'a été prélevé.";
        } else {
            $message = "Votre paiement est en cours de traitement chez Wave. Cette page se met à jour automatiquement.";
        }
    }
}

// Le reçu associé, s'il existe
$recu = null;
if ($paiement && !empty($paiement['recu_id'])) {
    $r = $pdo->prepare('SELECT id, numero FROM recus WHERE id=?');
    $r->execute([(int)$paiement['recu_id']]);
    $recu = $r->fetch();
}

client_header('Paiement', 'payer', $settings, $CLIENT);
$devise = $settings['devise'] ?? 'FCFA';
?>

<div class="panel glass pay-retour pr-<?= e($etat) ?>">
  <?php if ($etat === 'succes'): ?>
    <div class="pr-ico">✅</div>
    <h2>Paiement confirmé</h2>
    <p class="pr-txt">Merci ! Votre règlement de
      <strong><?= number_format((float)$paiement['montant'], 0, ',', ' ') ?> <?= e($devise) ?></strong>
      a bien été enregistré.</p>
    <?php if ($recu): ?>
    <p class="pr-txt">Votre reçu <strong><?= e($recu['numero']) ?></strong> est disponible ci-dessous.
       Une copie vous a également été envoyée par email.</p>
    <div class="pr-actions">
      <a class="btn btn-gold" href="doc-pdf.php?type=recu&id=<?= (int)$recu['id'] ?>" target="_blank">🧾 Voir mon reçu</a>
      <a class="btn btn-glass" href="payer.php">Retour à mes factures</a>
    </div>
    <?php else: ?>
    <div class="pr-actions"><a class="btn btn-glass" href="payer.php">Retour à mes factures</a></div>
    <?php endif; ?>

  <?php elseif ($etat === 'echec'): ?>
    <div class="pr-ico">⚠️</div>
    <h2>Paiement non abouti</h2>
    <p class="pr-txt"><?= e($message) ?></p>
    <div class="pr-actions"><a class="btn btn-gold" href="payer.php">Réessayer</a></div>

  <?php elseif ($etat === 'attente'): ?>
    <div class="pr-ico">⏳</div>
    <h2>Paiement en cours de vérification</h2>
    <p class="pr-txt"><?= e($message) ?></p>
    <p class="pr-txt" style="font-size:12.5px">Référence : <?= e($reference) ?></p>
    <div class="pr-actions"><a class="btn btn-glass" href="payer.php">Mes factures</a></div>
    <script>setTimeout(function(){ location.reload(); }, 6000);</script>

  <?php else: ?>
    <div class="pr-ico">❓</div>
    <h2>Paiement introuvable</h2>
    <p class="pr-txt"><?= e($message) ?></p>
    <div class="pr-actions"><a class="btn btn-glass" href="payer.php">Mes factures</a></div>
  <?php endif; ?>
</div>

<?php client_footer(); ?>
