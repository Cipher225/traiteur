<?php
/* ============================================================================
   VÉRIFICATION D'UN MESSAGE
   Le destinataire saisit ou scanne la référence : le serveur confirme que ce
   message est bien parti de l'entreprise, à cette date, vers cette adresse.
   ============================================================================ */
require __DIR__ . '/config/config.php';
require __DIR__ . '/config/db.php';

$settings = get_settings($pdo);
$ref = preg_replace('/[^A-Z0-9\-]/', '', strtoupper(trim((string)($_GET['c'] ?? ''))));
$msg = null;

if ($ref !== '') {
    $st = $pdo->prepare('SELECT reference, destinataire, sujet, envoye_le, statut
                         FROM emails_envoyes WHERE reference = ? AND statut = "envoye"');
    $st->execute([$ref]);
    $msg = $st->fetch() ?: null;
}

/* On ne dévoile jamais l'adresse complète : c'est une donnée personnelle. */
$masque = function (string $mail): string {
    [$a, $b] = array_pad(explode('@', $mail, 2), 2, '');
    $debut = mb_substr($a, 0, 2);
    return $debut . str_repeat('•', max(2, mb_strlen($a) - 2)) . '@' . $b;
};
?>
<!DOCTYPE html>
<html lang="fr"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vérification d'un message — <?= e($settings['nom_entreprise'] ?? '') ?></title>
<style>
  body{margin:0;font-family:-apple-system,'Segoe UI',system-ui,sans-serif;background:#0a1020;
    color:#eaf0fb;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:22px}
  .b{max-width:520px;width:100%;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);
    border-radius:22px;padding:30px 28px;box-shadow:0 22px 60px rgba(0,0,0,.45)}
  .ent{font-size:18px;font-weight:800;color:#e9c15c;letter-spacing:.5px}
  .slg{font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:#b8870f;margin-top:4px}
  .st{display:flex;align-items:center;gap:13px;margin:24px 0;padding:16px 18px;border-radius:16px}
  .ok{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.35)}
  .ko{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.35)}
  .st .i{font-size:28px}
  .st strong{display:block;font-size:15px;margin-bottom:3px}
  .st span{font-size:12.5px;color:#a9b7d0;line-height:1.55}
  .rw{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.07);font-size:13px}
  .rw:last-child{border-bottom:none}
  .rw b{color:#eaf0fb;text-align:right}
  .rw span{color:#8fa0bd}
  form{display:flex;gap:8px;margin-top:18px}
  input{flex:1;padding:11px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.12);
    background:rgba(255,255,255,.05);color:#eaf0fb;font-size:14px}
  button{padding:11px 18px;border-radius:12px;border:none;cursor:pointer;font-weight:700;
    background:linear-gradient(135deg,#e9c15c,#d4a526);color:#0a1020}
  .note{margin-top:18px;font-size:11.5px;color:#7d879b;line-height:1.6}
</style></head>
<body><div class="b">
  <div class="ent"><?= e(mb_strtoupper($settings['nom_entreprise'] ?? '')) ?></div>
  <div class="slg"><?= e($settings['slogan'] ?? '') ?></div>

  <?php if ($ref === ''): ?>
    <div class="st ok" style="background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.1)">
      <span class="i">🔎</span>
      <div><strong>Vérifier un message</strong>
        <span>Saisissez la référence figurant au bas du message reçu.</span></div>
    </div>
  <?php elseif ($msg): ?>
    <div class="st ok">
      <span class="i">✅</span>
      <div><strong>Message authentique</strong>
        <span>Ce message a bien été envoyé par <?= e($settings['nom_entreprise'] ?? '') ?>.</span></div>
    </div>
    <div class="rw"><span>Référence</span><b><?= e($msg['reference']) ?></b></div>
    <div class="rw"><span>Objet</span><b><?= e($msg['sujet']) ?></b></div>
    <div class="rw"><span>Destinataire</span><b><?= e($masque($msg['destinataire'])) ?></b></div>
    <div class="rw"><span>Envoyé le</span><b><?= date('d/m/Y à H:i', strtotime($msg['envoye_le'])) ?></b></div>
  <?php else: ?>
    <div class="st ko">
      <span class="i">⚠️</span>
      <div><strong>Référence inconnue</strong>
        <span>Aucun message portant cette référence n'a été envoyé par nos services.
          Si vous avez reçu un message se réclamant de nous avec cette référence,
          soyez prudent et contactez-nous directement.</span></div>
    </div>
  <?php endif; ?>

  <form method="get">
    <input name="c" value="<?= e($ref) ?>" placeholder="ex : GH-2026-A4F91C" required>
    <button>Vérifier</button>
  </form>

  <div class="note">
    Chaque message que nous envoyons porte une référence unique, calculée à partir de son
    contenu. Cette page interroge directement nos registres : elle constitue la seule preuve
    fiable de l'origine d'un message.
    <?php if (!empty($settings['telephone'])): ?><br>En cas de doute : <?= e($settings['telephone']) ?>.<?php endif; ?>
  </div>
</div></body></html>
