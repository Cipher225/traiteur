<?php
/* ============================================================================
   PROTOCOLE DE RETRAIT D'UN ADMINISTRATEUR

   Un administrateur voit tout, signe tout, et peut tout défaire. Laisser l'un
   d'eux en écarter un autre seul, c'est laisser une brouille d'un après-midi
   effacer un associé de l'application — et il n'y a personne au-dessus pour
   revenir en arrière.

   D'où la règle : aucun retrait ne se fait à une seule main.

     1. Un administrateur DEMANDE le retrait d'un autre, en écrivant son motif.
        Le motif est obligatoire : c'est ce que le second lira pour trancher.
     2. Un AUTRE administrateur approuve. La personne visée compte parmi eux :
        si elle accepte son propre départ, l'accord est bien mutuel.
     3. Sans approbation, la demande expire au bout de trois jours. Une menace
        qui traîne indéfiniment n'est pas un protocole.

   Trois gardes-fous complètent la règle :

     - Le dernier administrateur actif ne peut jamais être retiré. Sans lui,
       l'entreprise perd l'accès à sa propre application.
     - Désactiver un compte ou le rétrograder en employé prive des mêmes accès
       qu'une suppression : ces actions suivent le même chemin. Sans cela, le
       protocole ne serait qu'un décor qu'il suffit de contourner.
     - La personne visée est prévenue dès le dépôt de la demande. On ne retire
       pas quelqu'un à son insu.
   ============================================================================ */

/* Trois jours pour se prononcer. */
const ADMIN_RETRAIT_DELAI_H = 72;

/* Ce que l'on peut demander, et ce que cela veut dire en clair. */
function admin_actions_retrait(): array {
    return [
        'supprimer'   => ['Supprimer le compte',
                          "Le compte disparaît. Les messages, rapports et documents restent."],
        'desactiver'  => ['Désactiver le compte',
                          "La personne ne peut plus se connecter. Tout est conservé, c'est réversible."],
        'retrograder' => ['Retirer les droits d’administrateur',
                          "Le compte devient un compte employé, avec les seules sections accordées."],
    ];
}

/* ---------------------------------------------------------------------------
   Combien d'administrateurs actifs reste-t-il ?
   --------------------------------------------------------------------------- */
function admin_nombre(PDO $pdo): int {
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND actif=1")
                        ->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

function admin_est_dernier(PDO $pdo, int $uid): bool {
    try {
        $st = $pdo->prepare("SELECT role, actif FROM users WHERE id=?");
        $st->execute([$uid]);
        $u = $st->fetch();
        if (!$u || $u['role'] !== 'admin' || empty($u['actif'])) return false;
        return admin_nombre($pdo) <= 1;
    } catch (Throwable $e) { return true; }   // dans le doute, on protège
}

/* Ce compte est-il un administrateur, donc soumis au protocole ? */
function admin_protege(PDO $pdo, int $uid): bool {
    try {
        $st = $pdo->prepare("SELECT role FROM users WHERE id=?");
        $st->execute([$uid]);
        return $st->fetchColumn() === 'admin';
    } catch (Throwable $e) { return false; }
}

/* ---------------------------------------------------------------------------
   Les demandes périmées ne doivent pas rester à traîner : on les ferme avant
   toute lecture, pour que personne n'approuve une demande d'il y a un mois.
   --------------------------------------------------------------------------- */
function admin_purger_expirees(PDO $pdo): void {
    try {
        $pdo->exec("UPDATE admin_retraits SET statut='expiree', traitee_le=NOW()
                    WHERE statut='attente' AND expire_le < NOW()");
    } catch (Throwable $e) {}
}

/* La demande en cours visant ce compte, s'il y en a une. */
function admin_retrait_en_cours(PDO $pdo, int $cibleId): ?array {
    admin_purger_expirees($pdo);
    try {
        $st = $pdo->prepare("SELECT * FROM admin_retraits
                             WHERE cible_id=? AND statut='attente'
                             ORDER BY id DESC LIMIT 1");
        $st->execute([$cibleId]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

function admin_retraits(PDO $pdo, string $filtre = 'attente', int $max = 40): array {
    admin_purger_expirees($pdo);
    $where = match ($filtre) {
        'attente'  => "statut='attente'",
        'histoire' => "statut<>'attente'",
        default    => '1=1',
    };
    try {
        return $pdo->query("SELECT * FROM admin_retraits WHERE $where
                            ORDER BY id DESC LIMIT " . (int)$max)->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* Ce que l'administrateur connecté doit voir sur son tableau de bord. */
function admin_retraits_compteur(PDO $pdo, int $moi): array {
    admin_purger_expirees($pdo);
    $c = ['attente' => 0, 'a_moi' => 0, 'contre_moi' => 0];
    try {
        $r = $pdo->query("SELECT
              COUNT(*) attente,
              SUM(demandeur_id <> $moi) a_moi,
              SUM(cible_id = $moi) contre_moi
            FROM admin_retraits WHERE statut='attente'")->fetch();
        if ($r) $c = ['attente' => (int)$r['attente'], 'a_moi' => (int)$r['a_moi'],
                      'contre_moi' => (int)$r['contre_moi']];
    } catch (Throwable $e) {}
    return $c;
}

/* ---------------------------------------------------------------------------
   Déposer une demande.
   --------------------------------------------------------------------------- */
function admin_retrait_demander(PDO $pdo, int $cibleId, string $action,
                                string $motif, ?string &$erreur = null): bool {
    $moi = (int)($_SESSION['admin_id'] ?? 0);
    $motif = trim($motif);

    if (!isset(admin_actions_retrait()[$action])) {
        $erreur = 'Action inconnue.'; return false;
    }
    if ($cibleId === $moi) {
        $erreur = "Pour quitter vos propres fonctions, demandez à un autre administrateur "
                . "de déposer la demande : un retrait ne se décide jamais seul.";
        return false;
    }
    if (!admin_protege($pdo, $cibleId)) {
        $erreur = "Ce compte n'est pas un compte administrateur.";
        return false;
    }
    if (admin_est_dernier($pdo, $cibleId)) {
        $erreur = "C'est le dernier administrateur actif. Le retirer fermerait "
                . "l'application à tout le monde — nommez d'abord quelqu'un d'autre.";
        return false;
    }
    if (mb_strlen($motif) < 10) {
        $erreur = "Écrivez le motif — c'est ce que le second administrateur lira "
                . "pour se prononcer (10 caractères au minimum).";
        return false;
    }
    if (admin_retrait_en_cours($pdo, $cibleId)) {
        $erreur = "Une demande est déjà en cours pour ce compte.";
        return false;
    }

    try {
        $st = $pdo->prepare("SELECT nom FROM users WHERE id=?");
        $st->execute([$cibleId]);
        $cibleNom = (string)($st->fetchColumn() ?: '');

        $pdo->prepare("INSERT INTO admin_retraits (cible_id, cible_nom, action, motif,
                       demandeur_id, demandeur_nom, statut, expire_le)
                       VALUES (?,?,?,?,?,?,'attente',?)")
            ->execute([$cibleId, mb_substr($cibleNom, 0, 120), $action,
                       mb_substr($motif, 0, 2000), $moi,
                       mb_substr((string)($_SESSION['admin_nom'] ?? ''), 0, 120),
                       date('Y-m-d H:i:s', time() + ADMIN_RETRAIT_DELAI_H * 3600)]);
    } catch (Throwable $e) {
        $erreur = "La demande n'a pas pu être enregistrée.";
        return false;
    }

    journaliser($pdo, 'admin_retrait_demande', 'utilisateur', $cibleId,
                admin_actions_retrait()[$action][0] . ' — motif : ' . mb_substr($motif, 0, 300));

    /* La personne visée l'apprend tout de suite, et les autres administrateurs
       aussi : c'est à eux de se prononcer. */
    admin_retrait_prevenir($pdo, $cibleId, $action, $motif);
    return true;
}

/* ---------------------------------------------------------------------------
   Approbation — c'est ici que le retrait devient réel.
   --------------------------------------------------------------------------- */
function admin_retrait_approuver(PDO $pdo, int $demandeId, ?string &$erreur = null): bool {
    $moi = (int)($_SESSION['admin_id'] ?? 0);
    admin_purger_expirees($pdo);

    try {
        $st = $pdo->prepare("SELECT * FROM admin_retraits WHERE id=? AND statut='attente'");
        $st->execute([$demandeId]);
        $d = $st->fetch();
    } catch (Throwable $e) { $d = null; }

    if (!$d) { $erreur = "Cette demande n'est plus en attente."; return false; }

    /* Le cœur du protocole : jamais la même main deux fois. */
    if ((int)$d['demandeur_id'] === $moi) {
        $erreur = "Vous avez déposé cette demande — c'est à un autre administrateur "
                . "de l'approuver. C'est tout l'objet du protocole.";
        return false;
    }
    if (admin_est_dernier($pdo, (int)$d['cible_id'])) {
        $erreur = "Ce compte est devenu le dernier administrateur actif : "
                . "le retirer fermerait l'application.";
        return false;
    }

    if (!admin_retrait_appliquer($pdo, $d, $erreur)) return false;

    try {
        $pdo->prepare("UPDATE admin_retraits SET statut='approuvee', approbateur_id=?,
                       approbateur_nom=?, traitee_le=NOW() WHERE id=?")
            ->execute([$moi, mb_substr((string)($_SESSION['admin_nom'] ?? ''), 0, 120), $demandeId]);
    } catch (Throwable $e) {}

    journaliser($pdo, 'admin_retrait_approuve', 'utilisateur', (int)$d['cible_id'],
                'Retrait approuvé (' . $d['action'] . ') — demandé par ' . $d['demandeur_nom']);
    return true;
}

function admin_retrait_refuser(PDO $pdo, int $demandeId, string $motif = ''): void {
    $moi = (int)($_SESSION['admin_id'] ?? 0);
    try {
        $pdo->prepare("UPDATE admin_retraits SET statut='refusee', approbateur_id=?,
                       approbateur_nom=?, reponse_motif=?, traitee_le=NOW()
                       WHERE id=? AND statut='attente' AND demandeur_id<>?")
            ->execute([$moi, mb_substr((string)($_SESSION['admin_nom'] ?? ''), 0, 120),
                       mb_substr(trim($motif), 0, 1000), $demandeId, $moi]);
    } catch (Throwable $e) {}
    journaliser($pdo, 'admin_retrait_refuse', 'demande', $demandeId, $motif);
}

/* Le demandeur, lui, peut toujours retirer sa demande. */
function admin_retrait_annuler(PDO $pdo, int $demandeId): void {
    $moi = (int)($_SESSION['admin_id'] ?? 0);
    try {
        $pdo->prepare("UPDATE admin_retraits SET statut='annulee', traitee_le=NOW()
                       WHERE id=? AND statut='attente' AND demandeur_id=?")
            ->execute([$demandeId, $moi]);
    } catch (Throwable $e) {}
    journaliser($pdo, 'admin_retrait_annule', 'demande', $demandeId);
}

/* ---------------------------------------------------------------------------
   Exécution du retrait approuvé.
   --------------------------------------------------------------------------- */
function admin_retrait_appliquer(PDO $pdo, array $d, ?string &$erreur = null): bool {
    $uid = (int)$d['cible_id'];
    try {
        switch ($d['action']) {
            case 'desactiver':
                $pdo->prepare("UPDATE users SET actif=0, session_id=NULL WHERE id=?")
                    ->execute([$uid]);
                break;

            case 'retrograder':
                /* Rétrogradé, il garde un compte mais plus aucun droit tant que
                   personne ne lui en accorde : on ne suppose pas à sa place. */
                $pdo->prepare("UPDATE users SET role='employe', permissions='[]', session_id=NULL
                               WHERE id=?")->execute([$uid]);
                break;

            case 'supprimer':
                /* Le nom est archivé avant la suppression : sans cela, ses
                   messages et rapports deviendraient anonymes. */
                archiver_membre($pdo, $uid);
                $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
                break;

            default:
                $erreur = 'Action inconnue.';
                return false;
        }
    } catch (Throwable $e) {
        $erreur = "Le retrait n'a pas pu être appliqué.";
        return false;
    }
    return true;
}

/* ---------------------------------------------------------------------------
   Prévenir : la personne visée d'abord, les autres administrateurs ensuite.
   Une notification interne pour qui est devant l'application, un email pour
   qui ne l'est pas — aucun des deux n'est fiable seul.
   --------------------------------------------------------------------------- */
function admin_retrait_prevenir(PDO $pdo, int $cibleId, string $action, string $motif): void {
    $libelle = admin_actions_retrait()[$action][0] ?? $action;
    $auteur  = (string)($_SESSION['admin_nom'] ?? 'Un administrateur');
    $moi     = (int)($_SESSION['admin_id'] ?? 0);

    try {
        $st = $pdo->prepare("SELECT id, nom, email FROM users
                             WHERE role='admin' AND actif=1 AND id<>?");
        $st->execute([$moi]);
        $destinataires = $st->fetchAll();
    } catch (Throwable $e) { return; }

    foreach ($destinataires as $u) {
        $vise = ((int)$u['id'] === $cibleId);
        $texte = $vise
            ? $auteur . ' a demandé votre retrait de l’administration (' . $libelle
              . '). Motif : ' . mb_substr($motif, 0, 400)
              . ' — rien ne sera fait sans l’accord d’un second administrateur, et vous '
              . 'pouvez vous prononcer vous-même.'
            : $auteur . ' demande le retrait d’un administrateur (' . $libelle
              . '). Votre accord est nécessaire pour qu’il prenne effet.';

        try {
            $pdo->prepare("INSERT INTO messages (expediteur_id, destinataire_id, contenu, lu)
                           VALUES (?,?,?,0)")
                ->execute([$moi ?: (int)$u['id'], (int)$u['id'],
                           '🔐 Protocole administrateur — ' . $texte]);
        } catch (Throwable $e) {}
    }
}
