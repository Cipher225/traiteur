<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
$me = (int)$_SESSION['admin_id'];
$admin = is_admin();
define('UP', __DIR__ . '/../uploads');

// Liste des interlocuteurs possibles
if ($admin) {
    // l'admin écrit à n'importe quel employé OU client actif
    $filtreType = $_GET['type'] ?? 'tous'; // tous | employe | client
    $where = "role IN ('employe','client')";
    if ($filtreType === 'employe') $where = "role='employe'";
    elseif ($filtreType === 'client') $where = "role='client'";
    $contacts = $pdo->query("SELECT id, nom, username, role FROM users WHERE $where AND actif=1 ORDER BY role, nom")->fetchAll();

    // Ajouter les anciens interlocuteurs (comptes supprimés) avec qui l'admin a un historique.
    // Leurs messages restent en base : on les affiche pour ne rien perdre.
    $actifsIds = array_column($contacts, 'id');
    $tousInterlocuteurs = $pdo->prepare("
        SELECT DISTINCT autre FROM (
            SELECT destinataire_id AS autre FROM messages WHERE expediteur_id=?
            UNION SELECT expediteur_id AS autre FROM messages WHERE destinataire_id=?
        ) t");
    $tousInterlocuteurs->execute([$me, $me]);
    foreach ($tousInterlocuteurs->fetchAll(PDO::FETCH_COLUMN) as $autreId) {
        $autreId = (int)$autreId;
        if ($autreId <= 0 || in_array($autreId, $actifsIds, true)) continue;
        // Ce contact n'est plus dans la liste active → c'est un ancien membre
        $existe = $pdo->prepare("SELECT 1 FROM users WHERE id=?"); $existe->execute([$autreId]);
        if ($existe->fetchColumn()) continue; // encore existant mais inactif : on ne le rajoute pas ici
        $contacts[] = ['id'=>$autreId, 'nom'=>nom_membre($pdo, $autreId).' (ancien)', 'username'=>'', 'role'=>'ancien'];
    }
} else {
    // l'employé ou le client écrit à l'administrateur
    $contacts = $pdo->query("SELECT id, nom, username, role FROM users WHERE role='admin' ORDER BY nom")->fetchAll();
    $filtreType = 'tous';
}
$contactIds = array_column($contacts, 'id');

// Envoi d'un message
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Supprimer un message : seulement le sien, et dans l'heure suivant l'envoi.
    // Passé 1 h, la suppression est bloquée pour préserver l'historique de l'entreprise.
    if (isset($_POST['supprimer_msg'])) {
        $mid = (int)$_POST['supprimer_msg'];
        $q = $pdo->prepare('SELECT expediteur_id, destinataire_id, fichier, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_sec FROM messages WHERE id=?');
        $q->execute([$mid]);
        $msg = $q->fetch();
        $retour = 'messagerie.php?u=' . (int)($_POST['to'] ?? 0);
        if (!$msg || (int)$msg['expediteur_id'] !== $me) {
            flash('Vous ne pouvez supprimer que vos propres messages.', 'error');
        } elseif ((int)$msg['age_sec'] > 3600) {
            flash('Trop tard : un message ne peut être supprimé que dans l\'heure suivant son envoi.', 'error');
        } else {
            // Supprimer le fichier joint éventuel du disque
            if (!empty($msg['fichier']) && is_file(UP . '/' . $msg['fichier'])) @unlink(UP . '/' . $msg['fichier']);
            $pdo->prepare('DELETE FROM messages WHERE id=?')->execute([$mid]);
            flash('Message supprimé.');
        }
        header('Location: ' . $retour . '#bas'); exit;
    }

    $to = (int)($_POST['to'] ?? 0);
    $texte = trim($_POST['contenu'] ?? '');
    if (!in_array($to, $contactIds, true)) { flash('Destinataire invalide.', 'error'); header('Location: messagerie.php'); exit; }
    $file = null; $fname = null;
    if (!empty($_FILES['fichier']['name'])) {
        $up = upload_message($_FILES['fichier'], UP);
        if ($up) { $file = $up['fichier']; $fname = $up['nom']; }
        else { flash('Fichier refusé (type non autorisé ou trop volumineux, 200 Mo max).', 'error'); header('Location: messagerie.php?u='.$to); exit; }
    }
    if ($texte === '' && !$file) { flash('Message vide.', 'error'); header('Location: messagerie.php?u='.$to); exit; }
    $pdo->prepare('INSERT INTO messages (expediteur_id, destinataire_id, contenu, fichier, fichier_nom) VALUES (?,?,?,?,?)')
        ->execute([$me, $to, mb_substr($texte,0,3000), $file, $fname]);
    header('Location: messagerie.php?u='.$to.'#bas'); exit;
}

// Interlocuteur sélectionné
$sel = (int)($_GET['u'] ?? ($contactIds[0] ?? 0));
if (!in_array($sel, $contactIds, true)) $sel = $contactIds[0] ?? 0;

// Marquer comme lus les messages reçus de cet interlocuteur
if ($sel) $pdo->prepare('UPDATE messages SET lu=1 WHERE destinataire_id=? AND expediteur_id=?')->execute([$me, $sel]);

// Fil de discussion
$thread = [];
if ($sel) {
    $st = $pdo->prepare('SELECT *, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_sec FROM messages WHERE (expediteur_id=? AND destinataire_id=?) OR (expediteur_id=? AND destinataire_id=?) ORDER BY created_at ASC, id ASC');
    $st->execute([$me, $sel, $sel, $me]); $thread = $st->fetchAll();
}
// Compteur de non-lus par interlocuteur
$unread = [];
$u = $pdo->prepare('SELECT expediteur_id, COUNT(*) n FROM messages WHERE destinataire_id=? AND lu=0 GROUP BY expediteur_id');
$u->execute([$me]); foreach ($u as $r) $unread[$r['expediteur_id']] = (int)$r['n'];

admin_header('Messagerie', 'messagerie', $pdo, $settings);
?>
<div class="chat-layout">
  <aside class="chat-contacts panel glass">
    <h2 style="font-size:15px;margin-bottom:10px"><?= $admin ? '💬 Destinataires' : '👤 Administration' ?></h2>
    <?php if ($admin): ?>
    <div class="msg-filtres">
      <a href="messagerie.php?type=tous" class="<?= $filtreType==='tous'?'on':'' ?>">Tous</a>
      <a href="messagerie.php?type=employe" class="<?= $filtreType==='employe'?'on':'' ?>">👷 Employés</a>
      <a href="messagerie.php?type=client" class="<?= $filtreType==='client'?'on':'' ?>">🧑 Clients</a>
    </div>
    <!-- Menu déroulant : choisir un destinataire avant d'écrire -->
    <select class="input msg-select" onchange="if(this.value) location.href='messagerie.php?u='+this.value+'&type=<?= $filtreType ?>'">
      <option value="">— Choisir un destinataire —</option>
      <?php
        $grpEmp = array_filter($contacts, fn($c) => ($c['role'] ?? '') === 'employe');
        $grpCli = array_filter($contacts, fn($c) => ($c['role'] ?? '') === 'client');
      ?>
      <?php if ($grpEmp): ?><optgroup label="👷 Employés">
        <?php foreach ($grpEmp as $c): ?><option value="<?= $c['id'] ?>" <?= $c['id']==$sel?'selected':'' ?>><?= e($c['nom']) ?></option><?php endforeach; ?>
      </optgroup><?php endif; ?>
      <?php if ($grpCli): ?><optgroup label="🧑 Clients">
        <?php foreach ($grpCli as $c): ?><option value="<?= $c['id'] ?>" <?= $c['id']==$sel?'selected':'' ?>><?= e($c['nom']) ?></option><?php endforeach; ?>
      </optgroup><?php endif; ?>
    </select>
    <?php endif; ?>
    <?php if (!$contacts): ?>
      <p style="color:var(--ink-faint);font-size:13px"><?= $admin ? "Aucun contact dans cette catégorie." : "Aucun administrateur disponible." ?></p>
    <?php endif; ?>
    <?php foreach ($contacts as $c): ?>
    <a class="chat-contact <?= $c['id']==$sel?'active':'' ?>" href="messagerie.php?u=<?= $c['id'] ?><?= $admin?'&type='.$filtreType:'' ?>">
      <span class="chat-ava <?= ($c['role']??'')==='client'?'ava-client':'ava-emp' ?>"><?= e(mb_strtoupper(mb_substr($c['nom'],0,1))) ?></span>
      <span class="chat-c-nom"><?= e($c['nom']) ?><?php if($admin): ?><small style="display:block;color:var(--ink-faint);font-size:10.5px"><?= ($c['role']??'')==='client'?'Client':'Employé' ?></small><?php endif; ?></span>
      <?php if (!empty($unread[$c['id']])): ?><span class="nav-badge"><?= $unread[$c['id']] ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </aside>

  <section class="chat-main panel glass">
    <?php if ($sel):
      $selNom = '';
      foreach ($contacts as $c) if ($c['id']==$sel) $selNom = $c['nom']; ?>
    <div class="chat-head"><span class="chat-ava"><?= e(mb_strtoupper(mb_substr($selNom,0,1))) ?></span><strong><?= e($selNom) ?></strong></div>
    <div class="chat-thread" id="thread">
      <?php if (!$thread): ?><div class="chat-empty">Aucun message. Écrivez le premier message ci-dessous 👇</div><?php endif; ?>
      <?php foreach ($thread as $m): $mine = $m['expediteur_id']==$me;
        $supprimable = $mine && ((int)$m['age_sec'] <= 3600);
        $lheure = date('d/m à H:i', strtotime($m['created_at']));
        // Le bloc heure + menu (réutilisé dans le cadre média ou sous le texte)
        ob_start(); ?>
        <div class="chat-time">
          <?php if ($supprimable): ?>
          <details class="msg-menu">
            <summary class="msg-menu-btn" title="Options du message">⋮</summary>
            <div class="msg-menu-pop">
              <form method="post" data-confirm="Supprimer ce message ? Il disparaîtra aussi chez le destinataire.">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="to" value="<?= $sel ?>">
                <button class="msg-menu-item danger" name="supprimer_msg" value="<?= $m['id'] ?>">🗑️ Supprimer</button>
              </form>
            </div>
          </details>
          <?php endif; ?>
          <span><?= $lheure ?></span>
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
    <form class="chat-composer" method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="to" value="<?= $sel ?>">
      <label class="chat-attach" title="Joindre un fichier">📎<input type="file" name="fichier" hidden onchange="this.parentNode.classList.toggle('has', this.files.length)"></label>
      <input class="input" name="contenu" placeholder="Votre message…" autocomplete="off">
      <button class="btn btn-gold">Envoyer</button>
    </form>
    <?php else: ?>
      <div class="chat-empty" style="margin:auto">Sélectionnez un interlocuteur pour démarrer la conversation.</div>
    <?php endif; ?>
  </section>
</div>
<script>var t=document.getElementById('thread'); if(t) t.scrollTop=t.scrollHeight;</script>
<?php admin_footer(); ?>
