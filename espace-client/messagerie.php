<?php
require __DIR__ . '/inc.php';
$me = (int)$_SESSION['admin_id'];
define('UP', __DIR__ . '/../uploads');

/* Le client échange avec l'administration */
$contacts = $pdo->query("SELECT id, nom FROM users WHERE role='admin' ORDER BY nom")->fetchAll();
$contactIds = array_column($contacts, 'id');

/* Envoi d'un message */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Supprimer son propre message, dans l'heure suivant l'envoi
    if (isset($_POST['supprimer_msg'])) {
        $mid = (int)$_POST['supprimer_msg'];
        $q = $pdo->prepare('SELECT expediteur_id, fichier, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_sec FROM messages WHERE id=?');
        $q->execute([$mid]);
        $msg = $q->fetch();
        if (!$msg || (int)$msg['expediteur_id'] !== $me) {
            flash('Vous ne pouvez supprimer que vos propres messages.', 'error');
        } elseif ((int)$msg['age_sec'] > 3600) {
            flash('Trop tard : suppression possible seulement dans l\'heure suivant l\'envoi.', 'error');
        } else {
            if (!empty($msg['fichier']) && is_file(UP . '/' . $msg['fichier'])) @unlink(UP . '/' . $msg['fichier']);
            $pdo->prepare('DELETE FROM messages WHERE id=?')->execute([$mid]);
            flash('Message supprimé.');
        }
        header('Location: messagerie.php#bas'); exit;
    }

    $to = (int)($_POST['to'] ?? 0);
    if (!in_array($to, $contactIds, true)) { $to = $contactIds[0] ?? 0; }
    $texte = trim($_POST['contenu'] ?? '');
    $file = null; $fname = null;
    if (!empty($_FILES['fichier']['name'])) {
        $up = upload_message($_FILES['fichier'], UP);
        if ($up) { $file = $up['fichier']; $fname = $up['nom']; }
        else { flash('Fichier refusé (type non autorisé ou trop volumineux, 200 Mo max).', 'error'); header('Location: messagerie.php'); exit; }
    }
    if ($to && ($texte !== '' || $file)) {
        $pdo->prepare('INSERT INTO messages (expediteur_id, destinataire_id, contenu, fichier, fichier_nom) VALUES (?,?,?,?,?)')
            ->execute([$me, $to, mb_substr($texte,0,3000), $file, $fname]);
    }
    header('Location: messagerie.php#bas'); exit;
}

/* Interlocuteur = l'administration (premier admin) */
$sel = $contactIds[0] ?? 0;

/* Marquer comme lus les messages reçus de l'administration */
if ($sel) {
    $ph = implode(',', array_fill(0, count($contactIds), '?'));
    $pdo->prepare("UPDATE messages SET lu=1 WHERE destinataire_id=? AND expediteur_id IN ($ph)")
        ->execute(array_merge([$me], $contactIds));
}

/* Fil de discussion avec tous les admins */
$thread = [];
if ($contactIds) {
    $ph = implode(',', array_fill(0, count($contactIds), '?'));
    $st = $pdo->prepare("SELECT *, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_sec FROM messages WHERE (expediteur_id=? AND destinataire_id IN ($ph)) OR (destinataire_id=? AND expediteur_id IN ($ph)) ORDER BY created_at ASC, id ASC");
    $st->execute(array_merge([$me], $contactIds, [$me], $contactIds));
    $thread = $st->fetchAll();
}

client_header('Messagerie', 'messagerie', $settings, $CLIENT);
?>
<div class="chat-main panel glass" style="max-width:820px;margin:0 auto">
  <div class="chat-head">
    <span class="chat-ava ava-emp">💬</span>
    <div><strong>Administration — <?= e($settings['nom_entreprise'] ?? 'Groupe Helisce') ?></strong>
      <small style="display:block;color:var(--ink-faint);font-size:11.5px">Échangez directement avec notre équipe</small>
    </div>
  </div>

  <div class="chat-thread" id="thread">
    <?php if (!$thread): ?>
      <div class="chat-empty" style="margin:auto;text-align:center;color:var(--ink-faint)">
        Aucun message pour l'instant.<br>Écrivez-nous, nous vous répondrons rapidement.
      </div>
    <?php endif; ?>
    <?php foreach ($thread as $m): $mine = $m['expediteur_id']==$me;
      $supprimable = $mine && ((int)$m['age_sec'] <= 3600);
      ob_start(); ?>
      <div class="chat-time">
        <?php if ($supprimable): ?>
        <details class="msg-menu">
          <summary class="msg-menu-btn" title="Options du message">⋮</summary>
          <div class="msg-menu-pop">
            <form method="post" data-confirm="Supprimer ce message ? Il disparaîtra aussi chez le destinataire.">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <button class="msg-menu-item danger" name="supprimer_msg" value="<?= $m['id'] ?>">🗑️ Supprimer</button>
            </form>
          </div>
        </details>
        <?php endif; ?>
        <span><?= date('d/m à H:i', strtotime($m['created_at'])) ?></span>
      </div>
      <?php $pied = ob_get_clean(); ?>
    <div class="chat-msg <?= $mine?'me':'them' ?>">
      <?php if (trim((string)$m['contenu'])!==''): ?><div class="chat-bulle"><?= nl2br(e($m['contenu'])) ?></div><?php endif; ?>
      <?php if ($m['fichier']): ?>
      <div class="chat-media-frame">
        <?= apercu_fichier($m['fichier'], $m['fichier_nom'], '../uploads') ?>
        <?= $pied ?>
      </div>
      <?php else: ?>
      <?= $pied ?>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <span id="bas"></span>
  </div>

  <?php if ($sel): ?>
  <form class="chat-composer" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="to" value="<?= $sel ?>">
    <label class="chat-attach" title="Joindre un fichier (200 Mo max)">📎<input type="file" name="fichier" hidden onchange="this.parentNode.classList.toggle('has', this.files.length)"></label>
    <input class="input" name="contenu" placeholder="Votre message…" autocomplete="off">
    <button class="btn btn-gold">Envoyer</button>
  </form>
  <?php else: ?>
    <div class="chat-empty" style="margin:auto">La messagerie n'est pas disponible pour le moment.</div>
  <?php endif; ?>
</div>
<script>var b=document.getElementById('bas'); if(b) b.scrollIntoView();</script>
<?php client_footer(); ?>
