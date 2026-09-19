<?php
/* ============================================================================
   ASSISTANT DU SITE — ADMINISTRATION

   Trois choses ici : ce que l'assistant sait, ce qu'il peut faire télécharger,
   et ce que les visiteurs lui ont demandé.
   ============================================================================ */
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/../config/ia.php';

if (!is_admin()) { header('Location: index.php'); exit; }

$DOSSIER = realpath(__DIR__ . '/..') . '/uploads/documents';
if (!is_dir($DOSSIER)) @mkdir($DOSSIER, 0775, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    /* ---- Réglages ---- */
    if (isset($_POST['reglages'])) {
        $up = $pdo->prepare("INSERT INTO settings (cle, valeur) VALUES (?,?)
                             ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)");
        foreach ([
            'ia_active'        => empty($_POST['ia_active']) ? '0' : '1',
            'ia_cle'           => trim((string)($_POST['ia_cle'] ?? '')),
            'ia_plafond_jour'  => max(10, min(5000, (int)($_POST['ia_plafond_jour'] ?? 300))),
            'ia_msg_max'       => max(4, min(60, (int)($_POST['ia_msg_max'] ?? 20))),
            'ia_secretariat'   => (int)($_POST['ia_secretariat'] ?? 0),
            'ia_accueil'       => mb_substr(trim((string)($_POST['ia_accueil'] ?? '')), 0, 300),
            'ia_declenchement' => ($_POST['ia_declenchement'] ?? 'bulle') === 'invitation' ? 'invitation' : 'bulle',
            'ia_delai_invitation' => max(2, min(180, (int)($_POST['ia_delai_invitation'] ?? 12))),
            'ia_invitation_texte' => mb_substr(trim((string)($_POST['ia_invitation_texte'] ?? '')), 0, 120),
            'ia_enregistrer'   => empty($_POST['ia_enregistrer']) ? '0' : '1',

            /* Horaires : l'assistant ne promet un rappel « dans la journée »
               que pendant ces heures. En dehors, il annonce la prochaine
               ouverture — une promesse tenue vaut mieux qu'une promesse rapide. */
            'ia_jours'         => implode(',', array_values(array_filter(
                                    array_map('intval', (array)($_POST['ia_jours'] ?? [])),
                                    fn($j) => $j >= 0 && $j <= 6))) ?: '1,2,3,4,5,6',
            'ia_heure_debut'   => max(0, min(23, (int)($_POST['ia_heure_debut'] ?? 8))),
            'ia_heure_fin'     => max(1, min(24, (int)($_POST['ia_heure_fin'] ?? 17))),
            'ia_delai_reponse' => max(15, min(2880, (int)($_POST['ia_delai_reponse'] ?? 120))),
        ] as $k => $v) $up->execute([$k, (string)$v]);

        flash("Réglages de l'assistant enregistrés.");
        header('Location: assistant.php#reglages'); exit;
    }

    /* ---- Section de connaissances ---- */
    if (isset($_POST['section'])) {
        $id = (int)($_POST['id'] ?? 0);
        $t  = trim((string)($_POST['titre'] ?? ''));
        $c  = trim((string)($_POST['contenu'] ?? ''));
        if ($t === '' || $c === '') {
            flash('Titre et contenu sont obligatoires.', 'error');
        } else {
            $data = [mb_substr($t, 0, 160), mb_substr($c, 0, 6000),
                     mb_substr(trim((string)($_POST['mots_cles'] ?? '')), 0, 255),
                     (int)($_POST['ordre'] ?? 0), isset($_POST['actif']) ? 1 : 0];
            if ($id) {
                $pdo->prepare("UPDATE ia_connaissances SET titre=?, contenu=?, mots_cles=?,
                               ordre=?, actif=? WHERE id=?")->execute([...$data, $id]);
                flash('Section modifiée.');
            } else {
                $pdo->prepare("INSERT INTO ia_connaissances (titre, contenu, mots_cles, ordre, actif)
                               VALUES (?,?,?,?,?)")->execute($data);
                flash("Section ajoutée — l'assistant en tient compte immédiatement.");
            }
        }
        header('Location: assistant.php#savoir'); exit;
    }

    if (isset($_POST['supprimer_section'])) {
        $pdo->prepare('DELETE FROM ia_connaissances WHERE id=?')
            ->execute([(int)$_POST['supprimer_section']]);
        flash('Section supprimée.');
        header('Location: assistant.php#savoir'); exit;
    }

    /* ---- Document téléchargeable ---- */
    if (isset($_POST['document'])) {
        $t = trim((string)($_POST['doc_titre'] ?? ''));
        if ($t === '' || empty($_FILES['doc_fichier']['name'])) {
            flash('Un titre et un fichier sont nécessaires.', 'error');
        } else {
            $f = $_FILES['doc_fichier'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $permis = ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx'];

            if ($f['error'] !== UPLOAD_ERR_OK) {
                flash('Le transfert a échoué.', 'error');
            } elseif (!in_array($ext, $permis, true)) {
                flash('Format non accepté (' . e($ext) . '). PDF, image, Word ou Excel.', 'error');
            } elseif ($f['size'] > 25 * 1024 * 1024) {
                flash('Fichier trop lourd : 25 Mo au maximum.', 'error');
            } else {
                $nom = 'doc_' . bin2hex(random_bytes(6)) . '.' . $ext;
                if (move_uploaded_file($f['tmp_name'], $DOSSIER . '/' . $nom)) {
                    $pdo->prepare("INSERT INTO ia_documents (titre, description, fichier,
                                   fichier_nom, taille, mots_cles, ordre, actif)
                                   VALUES (?,?,?,?,?,?,?,1)")
                        ->execute([mb_substr($t, 0, 160),
                                   mb_substr(trim((string)($_POST['doc_description'] ?? '')), 0, 400),
                                   $nom, mb_substr(basename($f['name']), 0, 255), (int)$f['size'],
                                   mb_substr(trim((string)($_POST['doc_mots'] ?? '')), 0, 255),
                                   (int)($_POST['doc_ordre'] ?? 0)]);
                    flash("Document ajouté. L'assistant pourra le proposer.");
                } else {
                    flash("Le fichier n'a pas pu être enregistré.", 'error');
                }
            }
        }
        header('Location: assistant.php#docs'); exit;
    }

    if (isset($_POST['supprimer_doc'])) {
        $st = $pdo->prepare('SELECT fichier FROM ia_documents WHERE id=?');
        $st->execute([(int)$_POST['supprimer_doc']]);
        if ($d = $st->fetch()) @unlink($DOSSIER . '/' . $d['fichier']);
        $pdo->prepare('DELETE FROM ia_documents WHERE id=?')->execute([(int)$_POST['supprimer_doc']]);
        flash('Document retiré.');
        header('Location: assistant.php#docs'); exit;
    }

    /* ========================================================================
       SUIVI DES DEMANDES

       Une demande transmise appartient à quelqu'un dès qu'il la prend : sans
       cela, deux personnes rappellent le même visiteur et une troisième
       suppose que c'est fait.
       ======================================================================== */

    /* ---- Prise en charge ---- */
    if (isset($_POST['prendre'])) {
        $id = (int)$_POST['prendre'];
        $pdo->prepare("UPDATE ia_conversations
                       SET statut='prise', pris_par=?, pris_le=NOW()
                       WHERE id=? AND transmise=1 AND statut='transmise'")
            ->execute([(int)$_SESSION['admin_id'], $id]);
        ia_suivre($pdo, $id, 'prise');
        flash('Demande prise en charge — elle est à votre nom.');
        header('Location: assistant.php?d=' . $id . '#demandes'); exit;
    }

    /* ---- Remise dans la file ---- */
    if (isset($_POST['rendre'])) {
        $id = (int)$_POST['rendre'];
        $pdo->prepare("UPDATE ia_conversations
                       SET statut='transmise', pris_par=NULL, pris_le=NULL
                       WHERE id=? AND transmise=1")->execute([$id]);
        ia_suivre($pdo, $id, 'rendue');
        flash('Demande remise dans la file.');
        header('Location: assistant.php#demandes'); exit;
    }

    /* ---- Réponse par email ---- */
    if (isset($_POST['repondre_email'])) {
        $id    = (int)$_POST['repondre_email'];
        $texte = trim((string)($_POST['message_email'] ?? ''));

        $st = $pdo->prepare('SELECT * FROM ia_conversations WHERE id=? AND transmise=1');
        $st->execute([$id]);
        $dem = $st->fetch();

        if (!$dem || $texte === '') {
            flash('Écrivez le message avant de l’envoyer.', 'error');
        } elseif (empty($dem['visiteur_email'])) {
            flash("Ce visiteur n'a pas laissé d'adresse email.", 'error');
        } else {
            require_once __DIR__ . '/../config/mail.php';
            require_once __DIR__ . '/../config/signature_mail.php';

            $sujet = trim((string)($_POST['sujet_email'] ?? ''));
            if ($sujet === '') $sujet = 'Votre demande — ' . ($settings['nom_entreprise'] ?? '');

            $corps = '<p>Bonjour ' . e((string)$dem['visiteur_nom']) . ',</p>'
                   . '<div style="font-size:15px;line-height:1.7">' . nl2br(e($texte)) . '</div>';

            $images = []; $aSupprimer = [];
            $message = email_signe($pdo, $settings, $corps, $images, $aSupprimer, null,
                                   (string)$dem['visiteur_email'], $sujet, null,
                                   (string)$dem['visiteur_nom']);

            $motif = null;
            $ok = envoyer_email($pdo, (string)$dem['visiteur_email'], $sujet, $message,
                                (string)($settings['email'] ?? ''), [], $motif, $images);

            foreach ($aSupprimer as $x) { if (is_file($x)) @unlink($x); }

            if ($ok) {
                $pdo->prepare("UPDATE ia_conversations SET canal_reponse='email',
                               statut=IF(statut='transmise','prise',statut),
                               pris_par=COALESCE(pris_par,?), pris_le=COALESCE(pris_le,NOW())
                               WHERE id=?")->execute([(int)$_SESSION['admin_id'], $id]);
                ia_suivre($pdo, $id, 'reponse', 'email', $texte);
                flash('Message envoyé à ' . e((string)$dem['visiteur_email']) . '.');
            } else {
                flash("L'envoi a échoué" . ($motif ? ' : ' . e($motif) : '.'), 'error');
            }
        }
        header('Location: assistant.php?d=' . $id . '#demandes'); exit;
    }

    /* ---- Réponse par WhatsApp ----
       Le message part depuis le téléphone ou WhatsApp Web du conseiller : on
       ne peut que consigner qu'il a été ouvert, et ouvrir la conversation
       avec le texte déjà rédigé. */
    if (isset($_POST['repondre_whatsapp'])) {
        $id    = (int)$_POST['repondre_whatsapp'];
        $texte = trim((string)($_POST['message_whatsapp'] ?? ''));

        $st = $pdo->prepare('SELECT visiteur_tel FROM ia_conversations WHERE id=? AND transmise=1');
        $st->execute([$id]);
        $tel = (string)($st->fetchColumn() ?: '');

        if ($tel === '') {
            flash("Ce visiteur n'a pas laissé de numéro WhatsApp.", 'error');
            header('Location: assistant.php#demandes'); exit;
        }

        $pdo->prepare("UPDATE ia_conversations SET canal_reponse='whatsapp',
                       statut=IF(statut='transmise','prise',statut),
                       pris_par=COALESCE(pris_par,?), pris_le=COALESCE(pris_le,NOW())
                       WHERE id=?")->execute([(int)$_SESSION['admin_id'], $id]);
        ia_suivre($pdo, $id, 'reponse', 'whatsapp', $texte);

        header('Location: ' . ia_lien_whatsapp($tel, $texte)); exit;
    }

    /* ---- Note interne ---- */
    if (isset($_POST['note_demande'])) {
        $id   = (int)$_POST['note_demande'];
        $note = trim((string)($_POST['note'] ?? ''));
        if ($note !== '') {
            $pdo->prepare('UPDATE ia_conversations SET note_interne=? WHERE id=?')
                ->execute([mb_substr($note, 0, 2000), $id]);
            ia_suivre($pdo, $id, 'note', '', $note);
            flash('Note enregistrée.');
        }
        header('Location: assistant.php?d=' . $id . '#demandes'); exit;
    }

    /* ---- Clôture ---- */
    if (isset($_POST['traiter'])) {
        $id = (int)$_POST['traiter'];
        $pdo->prepare("UPDATE ia_conversations SET statut='traitee', traite_le=NOW(),
                       pris_par=COALESCE(pris_par,?), pris_le=COALESCE(pris_le,NOW())
                       WHERE id=? AND transmise=1")
            ->execute([(int)$_SESSION['admin_id'], $id]);
        ia_suivre($pdo, $id, 'traitee');
        flash('Demande close.');
        header('Location: assistant.php#demandes'); exit;
    }

    if (isset($_POST['rouvrir'])) {
        $id = (int)$_POST['rouvrir'];
        $pdo->prepare("UPDATE ia_conversations SET statut='prise', traite_le=NULL WHERE id=?")
            ->execute([$id]);
        ia_suivre($pdo, $id, 'rouverte');
        flash('Demande rouverte.');
        header('Location: assistant.php?d=' . $id . '#demandes'); exit;
    }

    /* ========================================================================
       CONVERSATIONS

       Ce que les visiteurs ont écrit leur appartient autant qu'à vous : on
       doit pouvoir l'effacer. Une conversation déjà transmise au secrétariat
       n'est pas supprimée à la légère — sa demande reste, mais le fil qui l'a
       produite disparaît avec elle.
       ======================================================================== */
    if (isset($_POST['suppr_conv'])) {
        $id = (int)$_POST['suppr_conv'];
        try {
            /* Les messages et le suivi partent en cascade (clé étrangère) ;
               la demande transmise au module Commandes, elle, reste. */
            $pdo->prepare('DELETE FROM ia_conversations WHERE id=?')->execute([$id]);
            journaliser($pdo, 'suppression', 'ia_conversation', $id, 'Conversation effacée');
            flash('Conversation supprimée.');
        } catch (Throwable $e) {
            flash("La suppression n'a pas abouti.", 'error');
        }
        header('Location: assistant.php#convs'); exit;
    }

    if (isset($_POST['purger_convs'])) {
        $quoi = (string)($_POST['purge_quoi'] ?? 'anciennes');
        try {
            [$sql, $args, $mot] = match ($quoi) {
                'toutes'      => ['1=1', [], 'toutes les conversations'],
                'non_transmises' => ['transmise = 0', [], 'les conversations sans demande'],
                default       => ['created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
                                  [max(1, (int)($_POST['purge_jours'] ?? 30))],
                                  'les conversations de plus de '
                                    . max(1, (int)($_POST['purge_jours'] ?? 30)) . ' jours'],
            };
            $st = $pdo->prepare("DELETE FROM ia_conversations WHERE $sql");
            $st->execute($args);
            $n = $st->rowCount();
            journaliser($pdo, 'purge', 'ia_conversation', null, $n . ' conversation(s) — ' . $mot);
            flash($n > 0 ? $n . ' conversation' . ($n > 1 ? 's' : '') . ' supprimée' . ($n > 1 ? 's' : '') . '.'
                         : 'Aucune conversation ne correspondait.');
        } catch (Throwable $e) {
            flash("La purge n'a pas abouti.", 'error');
        }
        header('Location: assistant.php#convs'); exit;
    }

    /* ---- Essai ---- */
    if (isset($_POST['essai'])) {
        $q = trim((string)($_POST['essai_question'] ?? ''));
        if ($q === '') {
            flash("Posez une question pour l'essai.", 'error');
        } else {
            $err = null;
            $rep = ia_appeler($pdo, [['role' => 'user', 'content' => $q]], $err);
            if ($rep) {
                $s = ia_extraire($rep['texte']);
                $_SESSION['ia_essai'] = ['q' => $q, 'r' => $s['texte'],
                                         'docs' => $s['documents'],
                                         'jetons' => $rep['entree'] + $rep['sortie']];
                flash('Essai effectué.');
            } else {
                flash('Essai impossible : ' . e((string)$err), 'error');
            }
        }
        header('Location: assistant.php'); exit;
    }
}

$r = ia_reglages($pdo);
$conso = ia_consommation($pdo);
$essai = $_SESSION['ia_essai'] ?? null;
unset($_SESSION['ia_essai']);

$sections = []; $docs = []; $convs = []; $sansReponse = [];
try {
    $sections = $pdo->query("SELECT * FROM ia_connaissances ORDER BY ordre, id")->fetchAll();
    $docs = $pdo->query("SELECT * FROM ia_documents ORDER BY ordre, id")->fetchAll();
    $convs = $pdo->query("SELECT * FROM ia_conversations ORDER BY maj DESC LIMIT 60")->fetchAll();
    $convsTotal = (int)$pdo->query("SELECT COUNT(*) FROM ia_conversations")->fetchColumn();
    $sansReponse = $pdo->query("SELECT contenu, created_at FROM ia_messages
                                WHERE sans_reponse = 1 ORDER BY id DESC LIMIT 20")->fetchAll();
} catch (Throwable $e) {}

$editSection = null;
if (isset($_GET['section'])) {
    $st = $pdo->prepare('SELECT * FROM ia_connaissances WHERE id=?');
    $st->execute([(int)$_GET['section']]);
    $editSection = $st->fetch() ?: null;
}

$employes = [];
try {
    $employes = $pdo->query("SELECT id, nom FROM users WHERE role IN ('admin','employe')
                             AND actif=1 ORDER BY nom")->fetchAll();
} catch (Throwable $e) {}

/* ---------------------------------------------------------------- Demandes --
   La demande ouverte est dépliée avec tout son fil ; les autres restent
   repliées. Charger les messages de toutes les demandes affichées rendrait la
   page illisible et lente pour rien.
   ---------------------------------------------------------------------------- */
$filtre   = (string)($_GET['f'] ?? 'actives');
if (!in_array($filtre, ['actives', 'attente', 'retard', 'prises', 'traitees'], true)) {
    $filtre = 'actives';
}
$demandes = ia_demandes($pdo, $filtre);
$compteur = ia_compteur_demandes($pdo);
$horaires = ia_horaires($pdo);

/* La conversation qu'on veut relire, avec son fil. */
$convOuverte = (int)($_GET['c'] ?? 0);
$convFil = [];
if ($convOuverte > 0) {
    try {
        $st = $pdo->prepare("SELECT role, contenu, created_at FROM ia_messages
                             WHERE conversation_id=? ORDER BY id");
        $st->execute([$convOuverte]);
        $convFil = $st->fetchAll();
    } catch (Throwable $e) {}
}

$ouverte = (int)($_GET['d'] ?? 0);
$fil = []; $suivi = [];
if ($ouverte > 0) {
    try {
        $st = $pdo->prepare("SELECT role, contenu, created_at FROM ia_messages
                             WHERE conversation_id=? ORDER BY id");
        $st->execute([$ouverte]);
        $fil = $st->fetchAll();

        $st = $pdo->prepare("SELECT * FROM ia_suivi WHERE conversation_id=? ORDER BY id");
        $st->execute([$ouverte]);
        $suivi = $st->fetchAll();
    } catch (Throwable $e) {}
}

/* Comment dire le temps restant sans faire compter le lecteur. */
function ia_delai_texte(?string $echeance, string $statut): array {
    if ($echeance === null || $statut !== 'transmise') return ['', ''];
    $reste = strtotime($echeance) - time();
    if ($reste < 0) {
        $h = (int)floor(-$reste / 3600);
        return ['retard', $h >= 24 ? 'En retard de ' . (int)floor($h / 24) . ' j'
                                   : ($h >= 1 ? 'En retard de ' . $h . ' h' : 'En retard')];
    }
    $h = (int)floor($reste / 3600);
    $m = (int)floor(($reste % 3600) / 60);
    return [$h < 1 ? 'urgent' : 'ok',
            $h >= 1 ? 'À traiter sous ' . $h . ' h' : 'À traiter sous ' . max(1, $m) . ' min'];
}

$ETIQ = ['transmise' => ['En attente', 'att'], 'prise' => ['Prise en charge', 'pri'],
         'traitee'   => ['Close', 'tra'],      'classee' => ['Classée', 'tra']];

admin_header("Assistant du site", 'assistant', $pdo, $settings);
?>

<!-- ====================== ÉTAT ====================== -->
<?php
$enService = $r['active'] && $r['cle'] !== '';
$part = max(1, (int)$r['plafond_jour']);
$pourcent = min(100, round((int)$conso['appels'] / $part * 100));
$jetons = (int)($conso['jetons_entree'] ?? 0) + (int)($conso['jetons_sortie'] ?? 0);
?>
<div class="ia-noyau <?= $enService ? 'on' : 'off' ?>">

  <?php /* Le cœur : la marque de l'assistant, entourée de ses anneaux. Ils
           tournent tant qu'il est en service, et s'arrêtent sinon — l'état se
           lit avant le texte. */ ?>
  <div class="in-coeur">
    <span class="in-anneau"></span>
    <span class="in-anneau in-a2"></span>
    <span class="in-signe"><?= ia_marque() ?></span>
  </div>

  <div class="in-c">
    <div class="in-t">
      <strong><?= $enService ? 'Assistant en service' : 'Assistant hors service' ?></strong>
      <span class="in-pastille <?= $enService ? 'vive' : '' ?>">
        <?= $enService ? 'en ligne' : 'arrêté' ?></span>
    </div>
    <p class="in-d">
      <?php if ($enService): ?>
        Il répond aux visiteurs à partir de vos <?= count($sections) ?> section<?= count($sections) > 1 ? 's' : '' ?>
        de connaissances, et de rien d'autre.
      <?php else: ?>
        La bulle est absente du site. Vos sections restent enregistrées —
        indiquez une clé d'accès et activez l'assistant pour le remettre en service.
      <?php endif; ?>
    </p>

    <?php if ($enService): ?>
    <div class="in-mesures">
      <div class="in-m">
        <span class="im-l">Modèle</span>
        <strong class="im-v"><?= e($r['modele']) ?></strong>
      </div>
      <div class="in-m in-jauge">
        <span class="im-l">Échanges du jour</span>
        <strong class="im-v"><?= (int)$conso['appels'] ?> <em>/ <?= $part ?></em></strong>
        <span class="im-b"><i style="width:<?= $pourcent ?>%"
              class="<?= $pourcent >= 90 ? 'plein' : ($pourcent >= 60 ? 'haut' : '') ?>"></i></span>
      </div>
      <?php if ($jetons > 0): ?>
      <div class="in-m">
        <span class="im-l">Jetons consommés</span>
        <strong class="im-v"><?= number_format($jetons, 0, ',', ' ') ?></strong>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if (count($sections) === 0): ?>
  <a class="in-avert" href="#form-savoir">⚠️ Aucune connaissance saisie — commencez par là</a>
  <?php endif; ?>
</div>

<!-- ====================== DEMANDES TRANSMISES ====================== -->
<div class="panel glass" id="demandes">
  <div class="mod-tete">
    <h2 style="margin:0">📨 Demandes à rappeler
      <?php if ($compteur['total'] > 0): ?><span class="cnt"><?= (int)$compteur['total'] ?></span><?php endif; ?>
    </h2>
    <span class="ia-heures">
      🕐 <?= implode(', ', array_map(fn($j) => ia_jours_noms()[$j], $horaires['jours'])) ?>
      · <?= (int)$horaires['debut'] ?> h – <?= (int)$horaires['fin'] ?> h
      · <span class="<?= ia_ouvert($pdo) ? 'ih-on' : 'ih-off' ?>">
          <?= ia_ouvert($pdo) ? 'ouvert' : 'fermé' ?></span>
    </span>
  </div>

  <?php if ($compteur['retard'] > 0): ?>
  <div class="ia-retard">
    ⚠️ <strong><?= (int)$compteur['retard'] ?></strong>
    demande<?= $compteur['retard'] > 1 ? 's ont' : ' a' ?> dépassé le délai de rappel
    de <?= (int)$horaires['delai'] ?> minutes. Le visiteur attend toujours.
  </div>
  <?php endif; ?>

  <div class="ia-filtres">
    <?php foreach ([
      'actives'  => ['Actives',    $compteur['total']],
      'attente'  => ['En attente', $compteur['attente']],
      'retard'   => ['En retard',  $compteur['retard']],
      'prises'   => ['Prises',     $compteur['prises']],
      'traitees' => ['Closes',     null],
    ] as $k => $lib): ?>
    <a class="if-b <?= $filtre === $k ? 'on' : '' ?><?= $k === 'retard' && $compteur['retard'] > 0 ? ' alerte' : '' ?>"
       href="?f=<?= $k ?>#demandes"><?= e($lib[0]) ?><?php if ($lib[1] !== null && $lib[1] > 0): ?>
      <span><?= (int)$lib[1] ?></span><?php endif; ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$demandes): ?>
  <p class="ia-vide">
    <?= $filtre === 'traitees' ? 'Aucune demande close pour le moment.'
        : 'Aucune demande en cours. L’assistant transmet ici dès qu’un visiteur laisse ses coordonnées.' ?>
  </p>
  <?php else: ?>

  <?php /* La liste défile tant qu'on la parcourt. Dès qu'une demande est
           ouverte, le cadre s'efface : on ne lit pas une conversation par
           une fenêtre de six cents pixels. */ ?>
  <div class="ia-dem <?= $ouverte > 0 ? 'deployee' : 'defilant' ?>">
    <?php foreach ($demandes as $d):
      [$cls, $txt] = ia_delai_texte($d['echeance_reponse'], (string)$d['statut']);
      $etq = $ETIQ[(string)$d['statut']] ?? ['—', 'att'];
      $estOuverte = ((int)$d['id'] === $ouverte);
      $waDefaut = 'Bonjour ' . $d['visiteur_nom'] . ', ' . ($settings['nom_entreprise'] ?? '')
                . ' vous recontacte suite à votre message sur notre site.';
    ?>
    <div class="dem <?= $cls ?> <?= $estOuverte ? 'ouverte' : '' ?>" id="dem-<?= (int)$d['id'] ?>">

      <div class="dem-tete">
        <span class="dem-ico"><?= $cls === 'retard' ? '🔴' : ($d['statut'] === 'prise' ? '🟡' : '🟢') ?></span>
        <div class="dem-id">
          <div class="dem-nom"><?= e((string)$d['visiteur_nom'] ?: 'Visiteur') ?>
            <span class="dem-etat <?= $etq[1] ?>"><?= e($etq[0]) ?></span>
            <?php if ($txt !== ''): ?><span class="dem-delai <?= $cls ?>"><?= e($txt) ?></span><?php endif; ?>
          </div>
          <div class="dem-coord">
            <?php if ($d['visiteur_tel']): ?>
            <a href="<?= e(ia_lien_whatsapp((string)$d['visiteur_tel'], $waDefaut)) ?>"
               target="_blank" rel="noopener" class="dc-wa">📱 <?= e((string)$d['visiteur_tel']) ?></a>
            <?php endif; ?>
            <?php if ($d['visiteur_email']): ?>
            <a href="mailto:<?= e((string)$d['visiteur_email']) ?>" class="dc-ml">✉️ <?= e((string)$d['visiteur_email']) ?></a>
            <?php endif; ?>
            <span class="dc-t">Transmise le
              <?= $d['transmise_le'] ? date('d/m/Y à H:i', strtotime((string)$d['transmise_le'])) : '—' ?></span>
            <?php if ($d['responsable']): ?>
            <span class="dc-r">👤 <?= e((string)$d['responsable']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <a class="dem-plus" href="<?= $estOuverte ? '?f=' . $filtre . '#demandes'
             : '?f=' . $filtre . '&d=' . (int)$d['id'] . '#dem-' . (int)$d['id'] ?>">
          <?= $estOuverte ? 'Replier' : 'Ouvrir' ?>
        </a>
      </div>

      <?php if ($estOuverte): ?>
      <div class="dem-corps">

        <!-- ---- Le fil, tel que le visiteur l'a vécu ---- -->
        <div class="dem-fil">
          <?php if (!$fil): ?>
          <p class="ia-vide" style="margin:0">Conversation non conservée
            (option « Conserver les conversations » désactivée au moment de l’échange).</p>
          <?php endif; ?>
          <?php foreach ($fil as $m): ?>
          <div class="df-m <?= $m['role'] === 'visiteur' ? 'v' : 'a' ?>">
            <span class="df-q"><?= $m['role'] === 'visiteur' ? 'Visiteur' : 'Assistant' ?>
              · <?= date('H:i', strtotime((string)$m['created_at'])) ?></span>
            <p><?= nl2br(e((string)$m['contenu'])) ?></p>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- ---- Ce qui a été fait ---- -->
        <?php if ($suivi): ?>
        <div class="dem-suivi">
          <?php
          $libelles = ['transmise' => 'Transmise par l’assistant', 'prise' => 'Prise en charge',
                       'rendue' => 'Remise dans la file', 'reponse' => 'Réponse envoyée',
                       'note' => 'Note interne', 'traitee' => 'Close', 'rouverte' => 'Rouverte',
                       'relance' => 'Relance'];
          foreach ($suivi as $sv): ?>
          <div class="ds-l">
            <span class="ds-p"></span>
            <span class="ds-d"><?= date('d/m à H:i', strtotime((string)$sv['created_at'])) ?></span>
            <span class="ds-a"><?= e($libelles[$sv['action']] ?? $sv['action']) ?>
              <?php if ($sv['canal']): ?><em>par <?= e((string)$sv['canal']) ?></em><?php endif; ?></span>
            <span class="ds-w"><?= e((string)$sv['acteur_nom']) ?></span>
            <?php if ($sv['detail']): ?>
            <span class="ds-x"><?= e(mb_substr((string)$sv['detail'], 0, 220)) ?></span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ---- Agir ---- -->
        <div class="dem-actes">
          <?php if ($d['statut'] === 'transmise'): ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <button class="btn btn-gold btn-sm" name="prendre" value="<?= (int)$d['id'] ?>">
              ✋ Prendre en charge</button>
          </form>
          <?php elseif ($d['statut'] === 'prise'): ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <button class="btn btn-glass btn-sm" name="rendre" value="<?= (int)$d['id'] ?>">
              ↩︎ Remettre dans la file</button>
          </form>
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <button class="btn btn-gold btn-sm" name="traiter" value="<?= (int)$d['id'] ?>">
              ✓ Marquer close</button>
          </form>
          <?php else: ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <button class="btn btn-glass btn-sm" name="rouvrir" value="<?= (int)$d['id'] ?>">
              ↺ Rouvrir</button>
          </form>
          <?php endif; ?>
        </div>

        <div class="dem-canaux">
          <!-- ---- WhatsApp ---- -->
          <?php if ($d['visiteur_tel']): ?>
          <form method="post" target="_blank" class="dem-canal wa">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <label>📱 Répondre par WhatsApp</label>
            <textarea class="input" name="message_whatsapp" rows="3"><?= e($waDefaut) ?></textarea>
            <button class="btn btn-glass btn-sm" name="repondre_whatsapp" value="<?= (int)$d['id'] ?>">
              Ouvrir WhatsApp</button>
            <span class="ia-aide">Le message s’ouvre déjà rédigé. L’envoi reste de votre main.</span>
          </form>
          <?php endif; ?>

          <!-- ---- Email ---- -->
          <?php if ($d['visiteur_email']): ?>
          <form method="post" class="dem-canal ml">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <label>✉️ Répondre par email</label>
            <input class="input" name="sujet_email" maxlength="200"
                   value="Votre demande — <?= e((string)($settings['nom_entreprise'] ?? '')) ?>">
            <textarea class="input" name="message_email" rows="5"
              placeholder="Bonjour, nous avons bien reçu votre demande…"></textarea>
            <button class="btn btn-gold btn-sm" name="repondre_email" value="<?= (int)$d['id'] ?>">
              Envoyer</button>
            <span class="ia-aide">Part avec la signature de l’entreprise, comme depuis le module E-mail.</span>
          </form>
          <?php endif; ?>
        </div>

        <!-- ---- Note ---- -->
        <form method="post" class="dem-note">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <label>🗒️ Note interne <span class="ia-aide">— visible de l’équipe seule</span></label>
          <textarea class="input" name="note" rows="2"
            placeholder="Rappelé, sans réponse. À relancer demain."><?= e((string)($d['note_interne'] ?? '')) ?></textarea>
          <button class="btn btn-glass btn-sm" name="note_demande" value="<?= (int)$d['id'] ?>">
            Enregistrer la note</button>
        </form>

      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($essai): ?>
<div class="panel glass ia-essai">
  <h2>🧪 Résultat de l'essai</h2>
  <p class="ie-q"><?= e($essai['q']) ?></p>
  <p class="ie-r"><?= nl2br(e($essai['r'])) ?></p>
  <span class="ie-j"><?= (int)$essai['jetons'] ?> jetons consommés<?= $essai['docs'] ? ' · document proposé' : '' ?></span>
</div>
<?php endif; ?>

<!-- ====================== BASE DE CONNAISSANCES ====================== -->
<div class="panel glass" id="savoir">
  <div class="mod-tete">
    <h2 style="margin:0"><span class="h2-mq"><?= ia_marque('', false) ?></span>
      Ce que l'assistant sait <span class="cnt"><?= count($sections) ?></span></h2>
  </div>
  <p class="ia-aide">
    L'assistant répond <strong>uniquement</strong> à partir de ces sections et de votre carte.
    Hors de là, il dit qu'il ne sait pas et propose le contact — jamais une réponse inventée.
  </p>

  <?php if ($sections): ?>
  <div class="ia-liste defilant">
    <?php foreach ($sections as $s): ?>
    <div class="is <?= empty($s['actif']) ? 'inactive' : '' ?>">
      <span class="is-ico">📘</span>
      <div class="is-c">
        <div class="is-t"><?= e($s['titre']) ?>
          <?php if (empty($s['actif'])): ?><span class="is-off">Inactive</span><?php endif; ?></div>
        <div class="is-d"><?= e(mb_substr($s['contenu'], 0, 150)) ?><?= mb_strlen($s['contenu']) > 150 ? '…' : '' ?></div>
      </div>
      <div class="is-a">
        <a class="eo-b" href="?section=<?= (int)$s['id'] ?>#form-savoir" title="Modifier">✏️</a>
        <form method="post" style="display:inline" data-confirm="Supprimer « <?= e($s['titre']) ?> » ?">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <button class="eo-b sup" name="supprimer_section" value="<?= (int)$s['id'] ?>">✕</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <p class="ia-vide">Rien encore. Commencez par une section « Nos prestations »,
    puis « Zones desservies », « Délais de commande »…</p>
  <?php endif; ?>
</div>

<div class="panel glass" id="form-savoir">
  <h2><?= $editSection ? '✏️ Modifier la section' : '➕ Ajouter une section' ?></h2>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="section" value="1">
    <?php if ($editSection): ?><input type="hidden" name="id" value="<?= (int)$editSection['id'] ?>"><?php endif; ?>

    <div class="field full"><label>Titre de la section *</label>
      <input class="input" name="titre" required maxlength="160"
             value="<?= e($editSection['titre'] ?? '') ?>"
             placeholder="ex : Nos prestations traiteur"></div>

    <div class="field full"><label>Contenu *</label>
      <textarea class="input" name="contenu" required style="min-height:160px"
        placeholder="Écrivez comme si vous expliquiez à un nouveau collègue. L'assistant reprendra vos mots."><?= e($editSection['contenu'] ?? '') ?></textarea>
      <span class="ia-aide">Évitez les prix : l'assistant a pour consigne de ne jamais en donner.</span></div>

    <div class="field"><label>Mots-clés</label>
      <input class="input" name="mots_cles" maxlength="255"
             value="<?= e($editSection['mots_cles'] ?? '') ?>"
             placeholder="mariage, réception, buffet">
      <span class="ia-aide">Servent au repli quand l'assistant est indisponible.</span></div>

    <div class="field"><label>Ordre</label>
      <input class="input" type="number" name="ordre" value="<?= (int)($editSection['ordre'] ?? 0) ?>"></div>

    <div class="field full"><label class="check">
      <input type="checkbox" name="actif" value="1" <?= ($editSection === null || !empty($editSection['actif'])) ? 'checked' : '' ?>>
      <span>Active</span></label></div>

    <div class="full" style="display:flex;gap:9px;flex-wrap:wrap">
      <button class="btn btn-gold"><?= $editSection ? 'Enregistrer' : 'Ajouter' ?></button>
      <?php if ($editSection): ?><a class="btn btn-glass" href="assistant.php#savoir">Annuler</a><?php endif; ?>
    </div>
  </form>
</div>

<!-- ====================== DOCUMENTS ====================== -->
<div class="panel glass" id="docs">
  <div class="mod-tete">
    <h2 style="margin:0">📎 Documents proposables <span class="cnt"><?= count($docs) ?></span></h2>
  </div>
  <p class="ia-aide">
    L'assistant les propose au téléchargement quand la demande s'y prête —
    catalogue, plaquette, fiche technique. Le fichier arrive directement dans la discussion.
  </p>

  <?php if ($docs): ?>
  <div class="ia-liste">
    <?php foreach ($docs as $d): ?>
    <div class="is">
      <span class="is-ico">📄</span>
      <div class="is-c">
        <div class="is-t"><?= e($d['titre']) ?></div>
        <div class="is-d">
          <?= e($d['fichier_nom']) ?><?= $d['taille'] > 0 ? ' · ' . round($d['taille'] / 1048576, 1) . ' Mo' : '' ?>
          <?php if ($d['telechargements'] > 0): ?>
          · <?= (int)$d['telechargements'] ?> téléchargement<?= $d['telechargements'] > 1 ? 's' : '' ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="is-a">
        <a class="eo-b" href="../ia-document.php?d=<?= (int)$d['id'] ?>" target="_blank" title="Télécharger">⬇️</a>
        <form method="post" style="display:inline" data-confirm="Retirer « <?= e($d['titre']) ?> » ?">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <button class="eo-b sup" name="supprimer_doc" value="<?= (int)$d['id'] ?>">✕</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="form-grid" style="margin-top:14px">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="document" value="1">
    <div class="field"><label>Titre *</label>
      <input class="input" name="doc_titre" required maxlength="160"
             placeholder="ex : Catalogue Groupe Helisce"></div>
    <div class="field"><label>Fichier *</label>
      <input class="input" type="file" name="doc_fichier" required
             accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx">
      <span class="ia-aide">PDF, image, Word ou Excel — 25 Mo au maximum.</span></div>
    <div class="field full"><label>Description</label>
      <input class="input" name="doc_description" maxlength="400"
             placeholder="Ce que le visiteur y trouvera"></div>
    <div class="field full"><label>Quand le proposer</label>
      <input class="input" name="doc_mots" maxlength="255"
             placeholder="catalogue, brochure, présentation, activités">
      <span class="ia-aide">Mots qui, dans la question du visiteur, rendent ce document pertinent.</span></div>
    <div class="full"><button class="btn btn-gold">Ajouter le document</button></div>
  </form>
</div>

<!-- ====================== CE QU'ON LUI DEMANDE ====================== -->
<?php if ($sansReponse): ?>
<div class="panel glass">
  <div class="mod-tete">
    <h2 style="margin:0">❓ Questions restées sans réponse <span class="cnt"><?= count($sansReponse) ?></span></h2>
  </div>
  <p class="ia-aide">L'assistant n'a pas su répondre. Chacune est une section à ajouter ci-dessus.</p>
  <div class="ia-liste defilant">
    <?php foreach ($sansReponse as $q): ?>
    <div class="is">
      <span class="is-ico">💬</span>
      <div class="is-c">
        <div class="is-t"><?= e(mb_substr($q['contenu'], 0, 180)) ?></div>
        <div class="is-d"><?= date('d/m/Y à H:i', strtotime($q['created_at'])) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ====================== CONVERSATIONS ====================== -->
<?php if ($convs): ?>
<div class="panel glass" id="convs">
  <div class="mod-tete">
    <h2 style="margin:0">💬 Conversations <span class="cnt"><?= (int)$convsTotal ?></span></h2>
    <details class="conv-purge">
      <summary class="btn btn-glass btn-sm">🧹 Faire le ménage</summary>
      <form method="post" class="cp-f"
            data-confirm="Supprimer ces conversations ? Les messages partent avec elles.">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <label>Que supprimer ?</label>
        <select class="input" name="purge_quoi">
          <option value="anciennes">Les conversations de plus de…</option>
          <option value="non_transmises">Celles qui n'ont donné aucune demande</option>
          <option value="toutes">Toutes, sans exception</option>
        </select>
        <div class="cp-j">
          <input class="input" type="number" name="purge_jours" min="1" max="3650" value="30">
          <span>jours</span>
        </div>
        <button class="btn btn-danger btn-sm" name="purger_convs" value="1">Supprimer</button>
        <span class="ia-aide">Les demandes déjà transmises au secrétariat restent
          dans le module Commandes : seul le fil de discussion disparaît.</span>
      </form>
    </details>
  </div>
  <p class="ia-aide">
    Ce que les visiteurs écrivent leur appartient autant qu'à vous.
    Ouvrez un échange pour le relire, effacez-le quand il n'a plus lieu d'être.
  </p>

  <div class="ia-liste conv-liste<?= $convOuverte ? '' : ' defilant' ?>">
    <?php foreach ($convs as $c):
      $estOuverte = ((int)$c['id'] === $convOuverte); ?>
    <div class="conv-bloc <?= $estOuverte ? 'ouverte' : '' ?>">
      <div class="is">
        <span class="is-ico"><?= $c['transmise'] ? '📨' : '💬' ?></span>
        <div class="is-c">
          <div class="is-t">
            <?= $c['visiteur_nom'] ? e($c['visiteur_nom']) : 'Visiteur' ?>
            <?php if ($c['visiteur_tel']): ?><span class="is-tel"><?= e($c['visiteur_tel']) ?></span><?php endif; ?>
            <?php if ($c['transmise']): ?><span class="is-ok">Transmise</span><?php endif; ?>
          </div>
          <div class="is-d">
            <?= (int)$c['nb_messages'] ?> message<?= $c['nb_messages'] > 1 ? 's' : '' ?>
            · <?= date('d/m/Y à H:i', strtotime($c['created_at'])) ?>
            <?php if ($c['ip']): ?> · <?= e($c['ip']) ?><?php endif; ?>
          </div>
        </div>
        <div class="is-a">
          <a class="eo-b" href="<?= $estOuverte ? '#convs' : '?c=' . (int)$c['id'] . '#conv-' . (int)$c['id'] ?>"
             title="<?= $estOuverte ? 'Replier' : 'Lire l’échange' ?>"><?= $estOuverte ? '▾' : '👁' ?></a>
          <form method="post" style="display:inline"
                data-confirm="Supprimer cet échange<?= $c['visiteur_nom'] ? ' avec ' . e($c['visiteur_nom']) : '' ?> ? Les messages seront effacés.">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <button class="eo-b sup" name="suppr_conv" value="<?= (int)$c['id'] ?>" title="Supprimer">✕</button>
          </form>
        </div>
      </div>

      <?php if ($estOuverte): ?>
      <div class="conv-fil" id="conv-<?= (int)$c['id'] ?>">
        <?php if (!$convFil): ?>
        <p class="ia-vide" style="margin:0">Aucun message conservé pour cet échange.</p>
        <?php endif; ?>
        <?php foreach ($convFil as $m): ?>
        <div class="df-m <?= $m['role'] === 'visiteur' ? 'v' : 'a' ?>">
          <span class="df-q"><?= $m['role'] === 'visiteur' ? 'Visiteur' : 'Assistant' ?>
            · <?= date('H:i', strtotime((string)$m['created_at'])) ?></span>
          <p><?= nl2br(e((string)$m['contenu'])) ?></p>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if ($convsTotal > count($convs)): ?>
  <p class="ia-aide" style="margin:11px 0 0">
    Les <?= count($convs) ?> plus récentes sur <?= (int)$convsTotal ?>.
    Le ménage ci-dessus agit sur toutes.
  </p>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ====================== RÉGLAGES ====================== -->
<div class="panel glass" id="reglages">
  <h2>⚙️ Réglages</h2>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="reglages" value="1">

    <div class="field full"><label class="check">
      <input type="checkbox" name="ia_active" value="1" <?= $r['active'] ? 'checked' : '' ?>>
      <span>Activer l'assistant sur le site</span></label>
      <span class="ia-aide">Décoché, la bulle disparaît du site. Vos sections restent enregistrées.</span></div>

    <div class="field full"><label>Clé d'accès Anthropic</label>
      <input class="input" type="password" name="ia_cle" value="<?= e($r['cle']) ?>"
             placeholder="sk-ant-…" autocomplete="off">
      <span class="ia-aide">
        À créer sur <a href="https://console.anthropic.com/" target="_blank" rel="noopener">console.anthropic.com</a>.
        Elle reste sur votre serveur et n'est jamais envoyée au navigateur du visiteur.
      </span></div>

    <div class="field"><label>Plafond d'échanges par jour</label>
      <input class="input" type="number" name="ia_plafond_jour" min="10" max="5000"
             value="<?= (int)$r['plafond_jour'] ?>">
      <span class="ia-aide">Atteint, l'assistant bascule sur vos réponses enregistrées.</span></div>

    <div class="field"><label>Échanges par conversation</label>
      <input class="input" type="number" name="ia_msg_max" min="4" max="60"
             value="<?= (int)$r['msg_max'] ?>">
      <span class="ia-aide">Au-delà, il invite à appeler.</span></div>

    <div class="field"><label>Secrétariat</label>
      <select class="input" name="ia_secretariat">
        <option value="0">— Personne en particulier —</option>
        <?php foreach ($employes as $em): ?>
        <option value="<?= (int)$em['id'] ?>" <?= $r['secretariat'] === (int)$em['id'] ? 'selected' : '' ?>>
          <?= e($em['nom']) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="ia-aide">Reçoit les demandes transmises par l'assistant.</span></div>

    <div class="field full ia-sep"><span>🕐 Horaires et délai de rappel</span>
      <span class="ia-aide">L’assistant ne promet jamais un rappel hors de ces heures :
        il annonce la prochaine ouverture. Une promesse tenue vaut mieux qu’une promesse rapide.</span></div>

    <div class="field full"><label>Jours d’ouverture</label>
      <div class="ia-jours">
        <?php foreach (ia_jours_noms() as $n => $nom): ?>
        <label class="ij <?= in_array($n, $horaires['jours'], true) ? 'on' : '' ?>">
          <input type="checkbox" name="ia_jours[]" value="<?= $n ?>"
                 <?= in_array($n, $horaires['jours'], true) ? 'checked' : '' ?>>
          <span><?= e(mb_substr($nom, 0, 3)) ?></span></label>
        <?php endforeach; ?>
      </div></div>

    <div class="field"><label>Ouverture</label>
      <input class="input" type="number" name="ia_heure_debut" min="0" max="23"
             value="<?= (int)$horaires['debut'] ?>"></div>

    <div class="field"><label>Fermeture</label>
      <input class="input" type="number" name="ia_heure_fin" min="1" max="24"
             value="<?= (int)$horaires['fin'] ?>"></div>

    <div class="field full"><label>Délai de rappel promis (minutes ouvrées)</label>
      <input class="input" type="number" name="ia_delai_reponse" min="15" max="2880"
             value="<?= (int)$horaires['delai'] ?>">
      <span class="ia-aide">Le compte ne tourne que pendant les heures d’ouverture :
        une demande reçue samedi soir n’est pas en retard le dimanche matin.
        Passé ce délai, la demande apparaît en rouge et remonte sur le tableau de bord.</span></div>

    <div class="field full ia-sep"><span>🌐 Sur le site</span></div>

    <div class="field"><label>Apparition sur le site</label>
      <select class="input" name="ia_declenchement" id="ia-declenchement">
        <option value="bulle" <?= $r['declenchement'] === 'bulle' ? 'selected' : '' ?>>
          Bulle seule — le visiteur vient à lui</option>
        <option value="invitation" <?= $r['declenchement'] === 'invitation' ? 'selected' : '' ?>>
          Invitation — l'assistant parle le premier</option>
      </select>
      <span class="ia-aide">L'invitation convertit mieux, elle agace aussi davantage.
        Elle n'apparaît qu'une fois par visite, et plus du tout si le visiteur l'a écartée.</span></div>

    <div class="field" id="ia-champ-delai">
      <label>Après combien de secondes</label>
      <input class="input" type="number" name="ia_delai_invitation" min="2" max="180"
             value="<?= (int)$r['delai_invitation'] ?>">
      <span class="ia-aide">Trop tôt, on dérange quelqu'un qui lit ; trop tard, il est parti.
        Une douzaine de secondes est un bon point de départ.</span></div>

    <div class="field full" id="ia-champ-texte">
      <label>Ce que dit l'invitation</label>
      <input class="input" name="ia_invitation_texte" maxlength="120"
             value="<?= e($r['invitation_texte']) ?>"
             placeholder="Une question sur nos prestations ?"></div>

    <div class="field full"><label>Message d'accueil</label>
      <input class="input" name="ia_accueil" maxlength="300" value="<?= e($r['accueil']) ?>"></div>

    <div class="field full"><label class="check">
      <input type="checkbox" name="ia_enregistrer" value="1" <?= $r['enregistrer'] ? 'checked' : '' ?>>
      <span>Conserver les conversations</span></label>
      <span class="ia-aide">Elles vous montrent ce que les visiteurs demandent vraiment.</span></div>

    <div class="full"><button class="btn btn-gold">Enregistrer les réglages</button></div>
  </form>

  <?php if ($r['active'] && $r['cle'] !== ''): ?>
  <form method="post" class="ia-test">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input class="input" name="essai_question" placeholder="Posez une question comme le ferait un visiteur…">
    <button class="btn btn-glass btn-sm" name="essai" value="1">🧪 Essayer</button>
  </form>
  <?php endif; ?>
</div>

<script>
(function () {
  /* Le nombre de jours n'a de sens que pour « les plus anciennes » : affiché
     à côté des deux autres choix, il laisse croire qu'il les limite. */
  /* Le délai et le texte de l'invitation ne concernent que ce mode : affichés
     en permanence, ils laissent croire que la bulle seule les utilise aussi. */
  var mode = document.getElementById('ia-declenchement');
  var champs = [document.getElementById('ia-champ-delai'),
                document.getElementById('ia-champ-texte')];
  if (mode) {
    var ajusterMode = function () {
      var invit = (mode.value === 'invitation');
      champs.forEach(function (c) { if (c) c.hidden = !invit; });
    };
    mode.addEventListener('change', ajusterMode);
    ajusterMode();
  }

  var sel = document.querySelector('.cp-f select[name=purge_quoi]');
  var jours = document.querySelector('.cp-f .cp-j');
  if (!sel || !jours) return;
  function ajuster() { jours.hidden = (sel.value !== 'anciennes'); }
  sel.addEventListener('change', ajuster);
  ajuster();
})();
</script>

<?php admin_footer(); ?>
