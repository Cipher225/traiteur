<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
$me = (int)$_SESSION['admin_id'];
$admin = is_admin();
/* La visite du forum remet le compteur de notifications à zéro */
$pdo->prepare('UPDATE users SET forum_vu_at=NOW() WHERE id=?')->execute([$me]);
define('UP', __DIR__ . '/../uploads');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['supprimer'])) {
        $pid = (int)$_POST['supprimer'];
        if ($admin) {
            // L'administrateur modère : il peut supprimer n'importe quel message
            $pdo->prepare('DELETE FROM forum_posts WHERE id=?')->execute([$pid]);
            flash('Message supprimé.');
        } else {
            // Un employé ne supprime que son propre message, dans l'heure suivant l'envoi
            $q = $pdo->prepare('SELECT auteur_id, fichier, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_sec FROM forum_posts WHERE id=?');
            $q->execute([$pid]);
            $p = $q->fetch();
            if (!$p || (int)$p['auteur_id'] !== $me) {
                flash('Vous ne pouvez supprimer que vos propres messages.', 'error');
            } elseif ((int)$p['age_sec'] > 3600) {
                flash('Trop tard : suppression possible seulement dans l\'heure suivant l\'envoi.', 'error');
            } else {
                if (!empty($p['fichier']) && is_file(UP . '/' . $p['fichier'])) @unlink(UP . '/' . $p['fichier']);
                $pdo->prepare('DELETE FROM forum_posts WHERE id=?')->execute([$pid]);
                flash('Message supprimé.');
            }
        }
        header('Location: forum.php#bas'); exit;
    }
    if (isset($_POST['epingler']) && $admin) {
        $pdo->prepare('UPDATE forum_posts SET epingle=1-epingle WHERE id=?')->execute([(int)$_POST['epingler']]);
        header('Location: forum.php#bas'); exit;
    }
    // Nouveau message dans la conversation
    $texte = trim($_POST['contenu'] ?? '');
    $file = null; $fname = null;
    if (!empty($_FILES['fichier']['name'])) {
        $up = upload_message($_FILES['fichier'], UP);
        if ($up) { $file=$up['fichier']; $fname=$up['nom']; }
        else { flash('Fichier refusé (type non autorisé ou trop volumineux, 200 Mo max).', 'error'); header('Location: forum.php'); exit; }
    }
    if ($texte==='' && !$file) { header('Location: forum.php'); exit; }
    $pdo->prepare('INSERT INTO forum_posts (auteur_id, contenu, fichier, fichier_nom) VALUES (?,?,?,?)')
        ->execute([$me, mb_substr($texte,0,4000), $file, $fname]);
    header('Location: forum.php#bas'); exit;
}

// Messages épinglés (bannière) + fil chronologique
$pins = $pdo->query("SELECT p.*, COALESCE(u.nom, am.nom) AS auteur FROM forum_posts p LEFT JOIN users u ON u.id=p.auteur_id LEFT JOIN anciens_membres am ON am.user_id=p.auteur_id WHERE p.epingle=1 ORDER BY p.created_at DESC")->fetchAll();
$msgs = $pdo->query("SELECT p.*, COALESCE(u.nom, am.nom) AS auteur, u.role AS auteur_role, TIMESTAMPDIFF(SECOND, p.created_at, NOW()) AS age_sec FROM forum_posts p LEFT JOIN users u ON u.id=p.auteur_id LEFT JOIN anciens_membres am ON am.user_id=p.auteur_id ORDER BY p.created_at ASC, p.id ASC")->fetchAll();

// Couleur d'avatar stable par auteur
function ava_col($id){ $h = ($id*47)%360; return "hsl($h,55%,45%)"; }

admin_header("Forum d'équipe", 'forum', $pdo, $settings);
$csrf = csrf_token();
?>
<div class="chat-main panel glass forum-conv">
  <div class="chat-head" style="justify-content:space-between">
    <div style="display:flex;align-items:center;gap:10px"><span style="font-size:22px">📣</span><div><strong>Forum d'équipe</strong><div style="font-size:12px;color:var(--ink-faint)">Conversation partagée par toute l'équipe</div></div></div>
  </div>

  <?php if ($pins): ?>
  <div class="forum-pins">
    <?php foreach ($pins as $p): ?>
    <div class="forum-pin">📌 <strong><?= e($p['auteur'] ?: '—') ?> :</strong> <?= e(mb_strimwidth(strip_tags($p['contenu']),0,140,'…')) ?>
      <?php if ($admin): ?><form method="post" style="display:inline;margin-left:6px"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="pin-x" name="epingler" value="<?= $p['id'] ?>" title="Désépingler">✕</button></form><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="chat-thread" id="thread">
    <?php if (!$msgs): ?><div class="chat-empty">Aucun message. Lancez la conversation ci-dessous 👇</div><?php endif; ?>
    <?php $lastAuthor = null; foreach ($msgs as $m): $mine = $m['auteur_id']==$me; $showName = !$mine && $m['auteur_id']!==$lastAuthor; ?>
    <div class="fmsg <?= $mine?'me':'them' ?>">
      <?php if (!$mine): ?><span class="fmsg-ava" style="background:<?= ava_col((int)$m['auteur_id']) ?>"><?= e(mb_strtoupper(mb_substr($m['auteur'] ?? '?',0,1))) ?></span><?php endif; ?>
      <div class="fmsg-content">
        <?php if ($showName): ?><div class="fmsg-name"><?= e($m['auteur'] ?: 'Utilisateur') ?><?php if(($m['auteur_role']??'')==='admin'): ?> <span class="badge badge-gold" style="font-size:9px">Admin</span><?php endif; ?></div><?php endif; ?>
        <div class="fmsg-bulle">
          <?php if (trim((string)$m['contenu'])!==''): ?><span class="fmsg-txt"><?= nl2br(e($m['contenu'])) ?></span><?php endif; ?>
          <?php if ($m['fichier']): ?><?= apercu_fichier($m['fichier'], $m['fichier_nom'], '../uploads') ?><?php endif; ?>
          <div class="fmsg-foot">
            <?php $peutSupprimer = $admin || ($mine && (int)$m['age_sec'] <= 3600); ?>
            <?php if ($peutSupprimer): ?>
            <details class="msg-menu">
              <summary class="msg-menu-btn" title="Options du message">⋮</summary>
              <div class="msg-menu-pop">
                <form method="post" data-confirm="Supprimer ce message ?">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>">
                  <button class="msg-menu-item danger" name="supprimer" value="<?= $m['id'] ?>">🗑️ Supprimer</button>
                </form>
              </div>
            </details>
            <?php endif; ?>
            <?php if ($admin): ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="fmsg-ic" name="epingler" value="<?= $m['id'] ?>" title="Épingler"><?= $m['epingle']?'📌':'📍' ?></button></form><?php endif; ?>
            <span class="chat-time"><?= date('d/m H:i', strtotime($m['created_at'])) ?></span>
          </div>
        </div>
      </div>
    </div>
    <?php $lastAuthor = $m['auteur_id']; endforeach; ?>
    <span id="bas"></span>
  </div>

  <form class="chat-composer" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <label class="chat-attach" title="Joindre un fichier">📎<input type="file" name="fichier" hidden onchange="this.parentNode.classList.toggle('has', this.files.length)"></label>
    <input class="input" name="contenu" placeholder="Écrire dans le forum…" autocomplete="off">
    <button class="btn btn-gold">Envoyer</button>
  </form>
</div>
<script>var t=document.getElementById('thread'); if(t) t.scrollTop=t.scrollHeight;</script>
<?php admin_footer(); ?>
