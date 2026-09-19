<?php
require __DIR__ . '/config/db.php';
require __DIR__ . '/config/docauth.php';
$settings = get_settings($pdo);
$ent = $settings['nom_entreprise'] ?? 'Groupe Helisce';

/* Une seule adresse de vérification pour TOUT : documents et messages.
   Le code saisi peut être un jeton de document ou une référence d'email —
   le client n'a qu'une page à retenir. */
$code = trim((string)($_GET['c'] ?? ($_POST['c'] ?? '')));
$code = preg_replace('/\s+/', '', $code);
$auth = $code !== '' ? doc_verify($pdo, $code) : null;

/* Référence d'email : GH-2026-A4F91C */
$mail = null;
if (!$auth && $code !== '') {
    try {
        /* Seuls les messages envoyés AVEC authentification sont vérifiables :
           les autres n'ont jamais communiqué de référence. */
        $st = $pdo->prepare('SELECT reference, destinataire, sujet, envoye_le
                             FROM emails_envoyes
                             WHERE reference = ? AND statut = "envoye" AND COALESCE(authentifie,1) = 1');
        $st->execute([strtoupper($code)]);
        $mail = $st->fetch() ?: null;
    } catch (Throwable $e) { $mail = null; }
}

/* L'adresse n'est jamais dévoilée en entier : c'est une donnée personnelle. */
$masquer = function (string $m): string {
    [$a, $b] = array_pad(explode('@', $m, 2), 2, '');
    return mb_substr($a, 0, 2) . str_repeat('•', max(2, mb_strlen($a) - 2)) . '@' . $b;
};

// Détails du document authentifié
$typeLabels = [
    'facture'=>'Facture','proforma'=>'Facture proforma','livraison'=>'Bon de livraison','recu'=>'Reçu de paiement','fiche'=>'Bulletin de paie',
    'rapport'=>'Rapport','permission'=>'Demande de permission','conge'=>'Demande de congé',
    'explication'=>"Réponse à une demande d'explication",'conge_maladie'=>'Demande de congé maladie',
];
$details = [];
if ($auth) {
    $t = $auth['type']; $did = (int)$auth['doc_id']; $devise = $settings['devise'] ?? 'FCFA';
    try {
        if ($t==='facture' || $t==='proforma' || $t==='livraison') {
            $st=$pdo->prepare("SELECT f.numero,f.date_emission,f.statut,f.tva_taux,f.remise,COALESCE(NULLIF(c.entreprise,''), c.nom) clientnom,
                (SELECT COALESCE(SUM(quantite * IF(par_jour = 1, GREATEST(1, f.nb_jours), 1) * prix_unitaire),0) FROM facture_lignes WHERE facture_id=f.id) AS ht
                FROM factures f LEFT JOIN clients c ON c.id=f.client_id WHERE f.id=?");
            $st->execute([$did]);
            if($d=$st->fetch()){
                $base = max(0, (float)$d['ht'] - (float)$d['remise']);
                $ttc = $base + $base * (float)$d['tva_taux']/100;
                $details=['Numéro'=>$d['numero'],'Date'=>date('d/m/Y',strtotime($d['date_emission'])),'Client'=>$d['clientnom']?:'—','Montant'=>money($ttc,$devise)];
            }
        } elseif ($t==='recu') {
            $st=$pdo->prepare("SELECT r.numero,r.date_paiement,r.montant,COALESCE(NULLIF(c.entreprise,''), c.nom) clientnom FROM recus r LEFT JOIN clients c ON c.id=r.client_id WHERE r.id=?");
            $st->execute([$did]); if($d=$st->fetch()){ $details=['Numéro'=>$d['numero'],'Date'=>date('d/m/Y',strtotime($d['date_paiement'])),'Client'=>$d['clientnom']?:'—','Montant'=>money($d['montant'],$devise)]; }
        } elseif ($t==='fiche') {
            $st=$pdo->prepare("SELECT fp.numero,fp.periode,e.nom empnom FROM fiches_paie fp LEFT JOIN employes e ON e.id=fp.employe_id WHERE fp.id=?");
            $st->execute([$did]); if($d=$st->fetch()){ $details=['Numéro'=>$d['numero'],'Période'=>$d['periode'],'Salarié'=>$d['empnom']?:'—']; }
        } else {
            $st=$pdo->prepare("SELECT r.numero,r.titre,r.date_rapport,u.nom auteur FROM rapports r LEFT JOIN users u ON u.id=r.employe_user_id WHERE r.id=?");
            $st->execute([$did]); if($d=$st->fetch()){ $details=['Numéro'=>$d['numero'],'Objet'=>$d['titre'],'Date'=>date('d/m/Y',strtotime($d['date_rapport'])),'Auteur'=>$d['auteur']?:'—']; }
        }
    } catch (\Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="fr" data-space="public">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Vérification d'authenticité — <?= e($ent) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('assets/css/glass.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
<script src="<?= asset('assets/js/theme.js') ?>"></script>
<style>
  /* Le QR se scanne au téléphone : la carte ne doit jamais dépasser l'écran.
     Avec « display:grid » et « place-items:center », la colonne implicite se
     dimensionnait sur le contenu maximal de la carte — 520 px — quelle que
     soit la largeur du téléphone, et tout le côté droit passait hors champ.
     Un centrage en « flex » laisse la carte se réduire. */
  body{min-height:100svh;display:flex;align-items:center;justify-content:center;
       padding:24px 16px}
  .verif{width:100%;max-width:520px;padding:34px 28px;border-radius:26px}

  /* Un objet d'e-mail ou une adresse n'ont pas la longueur d'un numéro de
     facture : ils doivent pouvoir se couper, sinon ils élargissent la
     colonne et débordent à leur tour. */
  .verif, .vtable td{overflow-wrap:anywhere}
  .vbrand{display:flex;flex-direction:column;align-items:center;gap:10px;text-align:center;font-family:var(--font-display);font-weight:800;font-size:20px;color:var(--gold);margin-bottom:20px}
  .vbrand .vlogo{width:64px;height:64px;object-fit:contain;display:block}
  .vbrand span.vlogo{display:grid;place-items:center;font-size:40px;width:64px;height:64px;border-radius:16px;background:radial-gradient(circle,#fff,#eef1f6)}
  .vhead{text-align:center;margin-bottom:20px}
  .vhead .ic{font-size:56px}
  .vhead h1{font-family:var(--font-display);font-size:23px;margin:8px 0 4px}
  .vhead.ok h1{color:#3edbc1}.vhead.no h1{color:#e57373}
  .vhead p{color:var(--ink-dim);font-size:14px;line-height:1.5}
  .vtable{border-collapse:collapse;width:100%;margin:16px 0;table-layout:fixed}
  .vtable td{padding:10px 12px;border-bottom:1px solid var(--glass-border)}
  .vtable td.k{color:var(--ink-faint);width:38%;font-size:13px}
  .vtable td.v{font-weight:700}

  /* Sur un écran étroit, deux colonnes ne laissent plus la place d'écrire :
     l'intitulé passe au-dessus de sa valeur. */
  @media (max-width:430px){
    .verif{padding:26px 18px;border-radius:20px}
    .vtable, .vtable tbody, .vtable tr, .vtable td{display:block;width:auto}
    .vtable tr{padding:9px 0;border-bottom:1px solid var(--glass-border)}
    .vtable tr:last-child{border-bottom:none}
    .vtable td{padding:0;border-bottom:none}
    .vtable td.k{width:auto;font-size:11px;letter-spacing:.06em;text-transform:uppercase}
    .vtable td.v{margin-top:2px;font-size:14.5px}
    .vhead h1{font-size:20px}
    .vhead .ic{font-size:46px}
    .vseal{font-size:12.5px;padding:9px 10px}
  }
  .vfoot{text-align:center;color:var(--ink-faint);font-size:12.5px;margin-top:16px;line-height:1.6}
  .vform{display:flex;gap:8px;margin-top:14px}
  .vseal{display:flex;align-items:center;gap:8px;justify-content:center;background:rgba(62,219,193,.1);border:1px solid rgba(62,219,193,.35);color:#3edbc1;padding:10px;border-radius:12px;font-size:13.5px;font-weight:600;margin-bottom:8px}
</style>
</head>
<body>
<div class="aurora"></div>
<div class="verif glass-strong">
  <div class="vbrand"><?= logo_html('.', 'vlogo') ?><span><?= e($ent) ?></span></div>
  <?php if ($code === ''): ?>
    <div class="vhead"><div class="ic">🔎</div><h1>Vérifier un document</h1><p>Saisissez le code d'authentification figurant sur le document, ou scannez le QR code.</p></div>
    <form method="get" class="vform">
      <input class="input" name="c" placeholder="Code du document ou référence du message" required style="flex:1">
      <button class="btn btn-gold">Vérifier</button>
    </form>
  <?php elseif ($auth): ?>
    <div class="vhead ok"><div class="ic">✅</div><h1>Document authentique</h1><p>Ce document a bien été émis par <strong><?= e($ent) ?></strong>. Les informations officielles ci-dessous font foi.</p></div>
    <div class="vseal">🔐 Émis par <?= e($ent) ?></div>
    <table class="vtable">
      <?php
      /* Une ligne vide sur une page d'authenticité inquiète plus qu'elle ne
         renseigne : l'empreinte est facultative à l'enregistrement, on ne
         l'affiche donc que si elle existe. */
      $lignes = ['Type de document' => $typeLabels[$auth['type']] ?? $auth['type']]
              + $details
              + ['Empreinte'     => (string)($auth['checksum'] ?? ''),
                 'Authentifié le' => date('d/m/Y', strtotime($auth['created_at']))];
      foreach ($lignes as $k => $v):
        if (trim((string)$v) === '') continue; ?>
      <tr><td class="k"><?= e($k) ?></td><td class="v"><?= e((string)$v) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <p class="vfoot">Comparez ces informations avec votre exemplaire papier. En cas de différence (montant, nom, date…), le document en votre possession est une falsification.</p>
  <?php elseif ($mail): ?>
    <div class="vhead ok"><div class="ic">✅</div><h1>Message authentique</h1>
      <p>Ce message a bien été envoyé par <strong><?= e($ent) ?></strong>.</p></div>
    <div class="vseal">🔐 Envoyé par <?= e($ent) ?></div>
    <table class="vtable">
      <tr><td class="k">Type</td><td class="v">Message électronique</td></tr>
      <tr><td class="k">Référence</td><td class="v"><?= e($mail['reference']) ?></td></tr>
      <tr><td class="k">Objet</td><td class="v"><?= e($mail['sujet']) ?></td></tr>
      <tr><td class="k">Destinataire</td><td class="v"><?= e($masquer($mail['destinataire'])) ?></td></tr>
      <tr><td class="k">Envoyé le</td><td class="v"><?= date('d/m/Y à H:i', strtotime($mail['envoye_le'])) ?></td></tr>
    </table>
    <p class="vfoot">Si le message que vous avez reçu diffère de ces informations (objet, date),
      soyez prudent : il pourrait s'agir d'une contrefaçon.</p>

  <?php else: ?>
    <div class="vhead no"><div class="ic">⚠️</div><h1>Code non reconnu</h1><p>Aucun document ni message authentique ne correspond à ce code. Il pourrait s'agir d'une falsification, ou le code a été mal saisi.</p></div>
    <form method="get" class="vform">
      <input class="input" name="c" placeholder="Ressaisir le code" style="flex:1">
      <button class="btn btn-gold">Réessayer</button>
    </form>
  <?php endif; ?>
  <p class="vfoot"><a href="index.php" style="color:var(--ink-faint)">← Retour au site</a></p>
</div>
</body>
</html>
