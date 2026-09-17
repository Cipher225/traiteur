<?php
/* ============================================================================
   CHAT — POINT D'ENTRÉE DES VISITEURS

   Appelé par la bulle de discussion du site. Renvoie du JSON.

   Aucune erreur technique ne remonte jusqu'au visiteur : en cas de panne ou
   de plafond atteint, on répond avec la base de connaissances. Le visiteur ne
   doit pas voir que quelque chose ne va pas.
   ============================================================================ */
require __DIR__ . '/config/db.php';
require __DIR__ . '/config/ia.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function repondre(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') repondre(['ok' => false]);

$brut = file_get_contents('php://input');
$entree = json_decode((string)$brut, true);
if (!is_array($entree)) $entree = $_POST;

$action = (string)($entree['action'] ?? 'message');

/* ----------------------------------------------------------- Transmission --
   Le visiteur confirme ses coordonnées dans un petit formulaire plutôt que
   de les laisser deviner dans le fil. Un numéro mal lu fait perdre la
   demande, et personne ne s'en aperçoit avant le rappel manqué.
   ---------------------------------------------------------------------------- */
if ($action === 'transmettre') {
    $jetonT = preg_replace('/[^a-f0-9]/', '', (string)($entree['jeton'] ?? ''));
    if (strlen($jetonT) !== 32) repondre(['ok' => false]);

    $conv = ia_conversation($pdo, $jetonT);
    if (!$conv) repondre(['ok' => false]);

    if (!empty($conv['transmise'])) {
        repondre(['ok' => true, 'deja' => true,
                  'message' => 'Votre demande a déjà été transmise.']);
    }

    $nom   = trim((string)($entree['nom'] ?? ''));
    $tel   = ia_valider_tel((string)($entree['tel'] ?? ''));
    $email = ia_valider_email((string)($entree['email'] ?? ''));

    $manque = [];
    if ($nom === '')      $manque[] = 'votre nom';
    if ($tel === null)    $manque[] = 'un numéro WhatsApp valide';
    if ($email === null)  $manque[] = 'une adresse email valide';

    if ($manque) {
        repondre(['ok' => false,
                  'message' => 'Il manque ' . implode(', ', $manque) . '.']);
    }

    $err = null;
    $fait = ia_transmettre($pdo, (int)$conv['id'], $nom, $tel, $email, $err);

    repondre($fait
        ? ['ok' => true, 'transmis' => true, 'message' => ia_promesse($pdo)]
        : ['ok' => false, 'message' => $err ?: "La transmission n'a pas abouti."]);
}

/* Le jeton identifie la conversation, pas le visiteur : il vit dans son
   navigateur et disparaît avec. */
$jeton = preg_replace('/[^a-f0-9]/', '', (string)($entree['jeton'] ?? ''));
if (strlen($jeton) !== 32) $jeton = bin2hex(random_bytes(16));

$r = ia_reglages($pdo);

/* ---------------------------------------------------------- Ouverture ---- */
if ($action === 'ouvrir') {
    repondre(['ok' => true, 'jeton' => $jeton, 'accueil' => $r['accueil']]);
}

/* ---------------------------------------------------------- Message ------ */
$question = trim((string)($entree['message'] ?? ''));
if ($question === '') repondre(['ok' => false]);

/* Longueur : sans cette borne, on peut coller un livre pour faire monter la
   facture. */
if (mb_strlen($question) > IA_LONGUEUR_MAX) {
    $question = mb_substr($question, 0, IA_LONGUEUR_MAX);
}

$conv = ia_conversation($pdo, $jeton);
if (!$conv) repondre(['ok' => false]);

/* Nombre de messages : un échange de vingt tours n'avance plus, il tourne. */
$msgMax = max(4, (int)$r['msg_max']);
if ((int)$conv['nb_messages'] >= $msgMax * 2) {
    $s = get_settings($pdo);
    repondre(['ok' => true, 'jeton' => $jeton, 'fin' => true,
              'reponse' => 'Nous avons bien échangé — la suite ira plus vite de vive voix. '
                         . 'Appelez-nous au ' . ($s['telephone'] ?? '')
                         . ', un conseiller reprendra votre projet depuis le début.']);
}

ia_enregistrer_message($pdo, (int)$conv['id'], 'visiteur', $question);

/* ------------------------------------------------- Réponse de l'assistant  */
$sortie = null;
$erreur = null;

if (ia_disponible($pdo) && !ia_plafond_atteint($pdo)) {
    $historique = ia_historique($pdo, (int)$conv['id']);
    $rep = ia_appeler($pdo, $historique, $erreur);
    if ($rep) $sortie = ia_extraire($rep['texte']);
}

/* Panne, plafond, ou assistant éteint : on répond quand même. */
if ($sortie === null) $sortie = ia_repli($pdo, $question);

ia_enregistrer_message($pdo, (int)$conv['id'], 'assistant', $sortie['texte'],
                       !empty($sortie['sans_reponse']));

/* --------------------------------------------------------- Documents ----- */
$documents = [];
if (!empty($sortie['documents'])) {
    $ids = array_slice(array_map('intval', $sortie['documents']), 0, 2);
    $marques = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $pdo->prepare("SELECT id, titre, description, taille
                             FROM ia_documents WHERE actif=1 AND id IN ($marques)");
        $st->execute($ids);
        foreach ($st->fetchAll() as $d) {
            $documents[] = [
                'titre'  => $d['titre'],
                'detail' => $d['description'],
                'poids'  => $d['taille'] > 0 ? round($d['taille'] / 1048576, 1) . ' Mo' : '',
                'lien'   => 'ia-document.php?d=' . (int)$d['id'],
            ];
        }
    } catch (Throwable $e) {}
}

/* ---------------------------------------------------- Demande de contact --
   L'assistant a jugé qu'il avait de quoi transmettre. Plutôt que de deviner
   les coordonnées dans le fil, on affiche un formulaire pré-rempli de ce
   qu'on a pu reconnaître : le visiteur corrige et confirme.
   ---------------------------------------------------------------------------- */
$formulaire = null;
if (!empty($sortie['contact']) && empty($conv['transmise'])) {
    $pre = ['nom' => '', 'tel' => '', 'email' => ''];
    try {
        $st = $pdo->prepare("SELECT contenu FROM ia_messages
                             WHERE conversation_id=? AND role='visiteur'
                             ORDER BY id DESC LIMIT 8");
        $st->execute([(int)$conv['id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $m) {
            if ($pre['email'] === '' && preg_match('/[\w.+-]+@[\w-]+\.[\w.]{2,}/', $m, $mm)) {
                $pre['email'] = ia_valider_email($mm[0]) ?? '';
            }
            if ($pre['tel'] === '' && preg_match('/(\+?\d[\d\s\-\.]{7,})/', $m, $mm)) {
                $pre['tel'] = ia_valider_tel($mm[1]) ?? '';
            }
        }
    } catch (Throwable $e) {}

    $formulaire = $pre + ['promesse' => ia_promesse($pdo)];
}

repondre([
    'ok'         => true,
    'jeton'      => $jeton,
    'reponse'    => $sortie['texte'],
    'documents'  => $documents,
    'formulaire' => $formulaire,
    'repli'      => !empty($sortie['repli']),
]);
