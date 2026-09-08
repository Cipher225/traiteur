<?php
/* Configuration : identifiants de base de données et adresse du site.
   Le fichier config/config.php est le seul à adapter pour l'hébergement. */
require __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . (defined('DB_PORT') ? DB_PORT : '3306') . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    /* Cohérence de l'encodage (accents corrects) et du fuseau côté MySQL */
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    /* Aligner l'heure de MySQL sur celle de PHP (évite les décalages sur NOW(),
       délais de suppression des messages, horaires de travail, etc.) */
    try {
        $offset = (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('P'); // ex : +00:00
        $stmtTz = $pdo->prepare("SET time_zone = ?");
        $stmtTz->execute([$offset]);
    } catch (\Throwable $e) { /* certains hébergeurs bloquent SET time_zone : sans gravité */ }
} catch (PDOException $e) {
    http_response_code(500);
    die('Erreur de connexion à la base de données. Vérifiez les identifiants dans config/config.php '
      . '(hôte, nom de base, utilisateur, mot de passe) et que la base a bien été importée.');
}

if (session_status() === PHP_SESSION_NONE) session_start();

/* Création automatique des dossiers de stockage (utile lors du 1er déploiement en ligne).
   Les fichiers téléversés (logo, signature, tampon, photos, documents) y sont sauvegardés. */
if (!defined('UPLOAD_DIR')) define('UPLOAD_DIR', __DIR__ . '/../uploads');
foreach ([UPLOAD_DIR, UPLOAD_DIR . '/coffre'] as $__d) {
    if (!is_dir($__d)) @mkdir($__d, 0775, true);
}

/* ---------- Helpers ---------- */
function e($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }

function get_settings(PDO $pdo): array {
    static $s = null;
    if ($s === null) {
        $s = [];
        foreach ($pdo->query('SELECT cle, valeur FROM settings') as $row) $s[$row['cle']] = $row['valeur'];
    }
    return $s;
}

/* Identifiants Google : priorité aux Paramètres (base), repli sur config.php / variables d'env.
   Permet de configurer la connexion Google directement dans l'interface admin. */
function google_client_id(PDO $pdo): string {
    $s = get_settings($pdo);
    $v = trim($s['google_client_id'] ?? '');
    if ($v !== '') return $v;
    return defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : '';
}
function google_client_secret(PDO $pdo): string {
    $s = get_settings($pdo);
    $v = trim($s['google_client_secret'] ?? '');
    if ($v !== '') return $v;
    return defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : '';
}
function google_actif(PDO $pdo): bool {
    return google_client_id($pdo) !== '';
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_check() {
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? null)) { http_response_code(403); die('Session expirée. Rechargez la page.'); }
}

function upload_image(array $file, string $dir): ?string {
    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) return null;
    if ($file['size'] > 5 * 1024 * 1024) return null; // 5 Mo max
    $name = uniqid('img_') . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], rtrim($dir,'/') . '/' . $name)) return $name;
    return null;
}

/**
 * Upload d'une image AVEC redimensionnement automatique et sortie en PNG (transparence gérée).
 * $mode : 'cover' = remplit le cadre et recadre au centre (photos, plats)
 *         'contain' = image entière avec marges transparentes (logos, sans couper)
 * Retourne le nom du fichier .png, ou null si échec.
 * Repli : si GD est absent, enregistre l'image telle quelle (sans redimensionnement).
 */
function upload_image_redim(array $file, string $dir, int $largeur, int $hauteur, string $mode = 'cover'): ?string {
    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) return null;
    if ($file['size'] > 8 * 1024 * 1024) return null; // 8 Mo max en entrée

    $dir = rtrim($dir, '/');
    $name = uniqid('img_') . '.png';
    $dest = $dir . '/' . $name;

    // Repli si GD indisponible : on conserve le fichier, avec la bonne extension selon son type réel
    if (!function_exists('imagecreatetruecolor')) {
        $info = @getimagesize($file['tmp_name']);
        $vrai_ext = $ext;
        if ($info && isset($info[2])) {
            $map = [IMAGETYPE_PNG=>'png', IMAGETYPE_JPEG=>'jpg', IMAGETYPE_GIF=>'gif', IMAGETYPE_WEBP=>'webp'];
            if (isset($map[$info[2]])) $vrai_ext = $map[$info[2]];
        }
        $fallback = uniqid('img_') . '.' . $vrai_ext;
        return move_uploaded_file($file['tmp_name'], $dir . '/' . $fallback) ? $fallback : null;
    }

    // Charger l'image source selon son type réel
    $info = @getimagesize($file['tmp_name']);
    if (!$info) return null;
    switch ($info[2]) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($file['tmp_name']); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($file['tmp_name']);  break;
        case IMAGETYPE_GIF:  $src = @imagecreatefromgif($file['tmp_name']);  break;
        case IMAGETYPE_WEBP: $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : null; break;
        default: $src = null;
    }
    if (!$src) return null;

    $sw = imagesx($src); $sh = imagesy($src);

    // Canevas de destination transparent
    $dst = imagecreatetruecolor($largeur, $hauteur);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $largeur, $hauteur, $transparent);
    imagealphablending($dst, true);

    if ($mode === 'contain') {
        // Image entière, centrée, avec marges transparentes
        $ratio = min($largeur / $sw, $hauteur / $sh);
        $nw = (int)round($sw * $ratio); $nh = (int)round($sh * $ratio);
        $dx = (int)(($largeur - $nw) / 2); $dy = (int)(($hauteur - $nh) / 2);
        imagecopyresampled($dst, $src, $dx, $dy, 0, 0, $nw, $nh, $sw, $sh);
    } else {
        // 'cover' : remplit le cadre, recadre au centre
        $ratio = max($largeur / $sw, $hauteur / $sh);
        $nw = (int)round($sw * $ratio); $nh = (int)round($sh * $ratio);
        $sx = (int)(($nw - $largeur) / 2 / $ratio);
        $sy = (int)(($nh - $hauteur) / 2 / $ratio);
        $cropW = (int)round($largeur / $ratio);
        $cropH = (int)round($hauteur / $ratio);
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $largeur, $hauteur, $cropW, $cropH);
    }

    $ok = imagepng($dst, $dest, 6);
    imagedestroy($src); imagedestroy($dst);
    return $ok ? $name : null;
}

function upload_video(array $file, string $dir): ?string {
    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['mp4','webm','ogg','mov'])) return null;
    if ($file['size'] > 60 * 1024 * 1024) return null; // 60 Mo max
    $name = uniqid('vid_') . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], rtrim($dir,'/') . '/' . $name)) return $name;
    return null;
}

/* Transforme une URL YouTube/Vimeo en URL d'intégration (embed) */
function video_embed(string $url): string {
    if (preg_match('~youtu(?:be\.com/watch\?v=|\.be/)([\w-]{11})~', $url, $m)) return 'https://www.youtube.com/embed/' . $m[1];
    if (preg_match('~youtube\.com/embed/([\w-]{11})~', $url, $m)) return 'https://www.youtube.com/embed/' . $m[1];
    if (preg_match('~vimeo\.com/(\d+)~', $url, $m)) return 'https://player.vimeo.com/video/' . $m[1];
    return $url;
}

/* Nettoyage du HTML redige dans le traitement de texte : on garde la mise en forme
   mais on retire tout ce qui pourrait etre dangereux (scripts, evenements, iframes). */
function doc_nettoyer_html(string $html): string {
    $html = preg_replace('~<\s*(script|style|iframe|object|embed|form|input|button)\b[^>]*>.*?<\s*/\s*\1\s*>~is', '', $html);
    $html = preg_replace('~<\s*(script|style|iframe|object|embed|form|input|button)\b[^>]*/?>~i', '', $html);
    $html = preg_replace('~\son[a-z]+\s*=\s*"[^"]*"~i', '', $html);
    $html = preg_replace("~\son[a-z]+\s*=\s*'[^']*'~i", '', $html);
    $html = preg_replace('~\son[a-z]+\s*=\s*[^\s>]+~i', '', $html);
    $html = preg_replace('~(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2~i', '$1="#"', $html);
    return mb_substr($html, 0, 800000);
}

/* ============================================================================
   SÉCURITÉ — protection contre les tentatives de connexion en série
   ----------------------------------------------------------------------------
   La protection s'appuie sur la base de données et non sur la session : un
   robot qui n'accepte pas les cookies est ainsi bloqué lui aussi.
   ============================================================================ */

function adresse_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return mb_substr($ip, 0, 64);
        }
    }
    return 'inconnue';
}

/* Nombre d'essais autorisés avant blocage, et durée du blocage */
const CONNEXION_MAX_ESSAIS = 6;
const CONNEXION_BLOCAGE_MIN = 15;

function enregistrer_tentative(PDO $pdo, string $identifiant, bool $reussi): void {
    try {
        $pdo->prepare('INSERT INTO tentatives_connexion (ip, identifiant, reussi) VALUES (?,?,?)')
            ->execute([adresse_ip(), mb_substr($identifiant, 0, 120), $reussi ? 1 : 0]);
        // Ménage : on ne conserve pas d'historique inutile
        if (random_int(1, 20) === 1) {
            $pdo->exec("DELETE FROM tentatives_connexion WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        }
    } catch (Throwable $e) { /* la connexion ne doit jamais échouer à cause du journal */ }
}

/* Renvoie le nombre de minutes restantes si l'adresse est bloquée, 0 sinon. */
function connexion_bloquee(PDO $pdo): int {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM tentatives_connexion
                             WHERE ip = ? AND reussi = 0
                               AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)");
        $st->execute([adresse_ip(), CONNEXION_BLOCAGE_MIN]);
        $echecs = (int)$st->fetchColumn();
        if ($echecs < CONNEXION_MAX_ESSAIS) return 0;

        $st = $pdo->prepare("SELECT TIMESTAMPDIFF(MINUTE, NOW(), DATE_ADD(MAX(created_at), INTERVAL ? MINUTE))
                             FROM tentatives_connexion WHERE ip = ? AND reussi = 0");
        $st->execute([CONNEXION_BLOCAGE_MIN, adresse_ip()]);
        return max(1, (int)$st->fetchColumn());
    } catch (Throwable $e) { return 0; }
}

/* Après une connexion réussie, on efface les échecs de cette adresse. */
function reinitialiser_tentatives(PDO $pdo): void {
    try {
        $pdo->prepare('DELETE FROM tentatives_connexion WHERE ip = ? AND reussi = 0')
            ->execute([adresse_ip()]);
    } catch (Throwable $e) {}
}

/* ============================================================================
   JOURNAL DES ACTIONS
   Trace les opérations sensibles : qui, quoi, quand. Indispensable dès que
   plusieurs personnes ont accès à la facturation.
   ============================================================================ */
function journaliser(PDO $pdo, string $action, string $cible = '', ?int $cibleId = null, string $detail = ''): void {
    try {
        $uid = (int)($_SESSION['admin_id'] ?? 0) ?: null;
        $nom = (string)($_SESSION['admin_nom'] ?? '');
        $role = (string)($_SESSION['admin_role'] ?? '');
        $pdo->prepare('INSERT INTO journal (user_id, acteur, role, action, cible, cible_id, detail, ip)
                       VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$uid, mb_substr($nom, 0, 120), mb_substr($role, 0, 20),
                       mb_substr($action, 0, 60), mb_substr($cible, 0, 60), $cibleId,
                       mb_substr($detail, 0, 255), adresse_ip()]);
    } catch (Throwable $e) { /* une action réussie ne doit jamais échouer à cause du journal */ }
}

/* Groupes de la barre laterale et des permissions (une seule source de verite) */
function groupes_modules(): array {
    return [
        'activite'   => 'Activité',
        'commercial' => 'Commercial',
        'finances'   => 'Finances',
        'equipe'     => 'Équipe',
        'docs'       => 'Documents',
        'echange'    => 'Échanges',
        'site'       => 'Site vitrine',
    ];
}

/* ============================================================================
   ENTRÉES / SORTIES DE CAISSE → COMPTABILITÉ
   Chaque bon d'entrée ou de sortie génère son écriture comptable, et une seule :
   la liaison par « recu_id » garantit qu'une modification met à jour l'écriture
   existante au lieu d'en créer une seconde.
   ============================================================================ */
function ecriture_pour_recu(PDO $pdo, int $recuId, string $type, string $numero,
                            float $montant, string $mode, string $motif, string $date,
                            ?int $clientId, string $activite = '', string $nature = '',
                            ?int $factureId = null): void {
    try {
        $sens = ($type === 'entree') ? 'entree' : 'depense';
        /* La nature choisie sur le bon devient la catégorie comptable :
           les états financiers reflètent ainsi la réalité des dépenses. */
        $categorie = trim($nature) !== '' ? $nature : (($type === 'entree') ? 'Ventes' : 'Divers');
        $libelle  = mb_substr(trim(($type === 'entree' ? 'Entrée ' : 'Sortie ') . $numero
                    . ($motif !== '' ? ' — ' . $motif : '')), 0, 200);
        $notes    = trim($activite !== '' ? 'Activité : ' . $activite : '');

        $st = $pdo->prepare('SELECT id FROM transactions WHERE recu_id=?');
        $st->execute([$recuId]);
        $existe = $st->fetchColumn();

        if ($existe) {
            $pdo->prepare('UPDATE transactions SET type=?, categorie=?, libelle=?, montant=?,
                           mode_paiement=?, client_id=?, date_operation=?, notes=?, facture_id=? WHERE id=?')
                ->execute([$sens, $categorie, $libelle, $montant, $mode, $clientId, $date, $notes,
                           $factureId, (int)$existe]);
        } else {
            $pdo->prepare('INSERT INTO transactions (type, categorie, libelle, montant, mode_paiement,
                           client_id, date_operation, notes, recu_id, facture_id)
                           VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$sens, $categorie, $libelle, $montant, $mode, $clientId, $date, $notes,
                           $recuId, $factureId]);
        }
    } catch (Throwable $e) { /* la caisse ne doit jamais échouer à cause de la comptabilité */ }
}

/* ----------------------------------------------------------------------------
   Catégories de dépense. Toute sortie d'argent n'est pas liée à un client :
   le loyer, les salaires ou le carburant sont des charges de l'entreprise.
   ---------------------------------------------------------------------------- */
function categories_depense(): array {
    return [
        'Approvisionnement' => '🛒',   // denrées, boissons, consommables
        'Salaires'          => '👥',
        'Prestataires'      => '🤝',   // extras, serveurs, cuisiniers ponctuels
        'Location matériel' => '🎪',
        'Transport'         => '🚚',
        'Loyer'             => '🏠',
        'Électricité & eau' => '💡',
        'Téléphone & Internet' => '📶',
        'Entretien'         => '🧽',
        'Impôts & taxes'    => '🏛️',
        'Banque & frais'    => '🏦',
        'Divers'            => '📌',
    ];
}

/* ----------------------------------------------------------------------------
   Charges qui ne se rattachent JAMAIS à une prestation.
   Le loyer court, que vous ayez un mariage ce mois-ci ou aucun. L'imputer à
   une activité fausserait sa rentabilité et laisserait croire qu'une
   prestation coûte plus cher qu'en réalité.

   À l'inverse, l'approvisionnement, les extras ou la location de matériel
   sont engagés POUR une prestation précise : ceux-là restent rattachables.
   ---------------------------------------------------------------------------- */
function charges_structurelles(): array {
    return ['Salaires', 'Loyer', 'Électricité & eau', 'Téléphone & Internet',
            'Impôts & taxes', 'Banque & frais'];
}

function charge_rattachable(string $categorie): bool {
    return !in_array(trim($categorie), charges_structurelles(), true);
}

/* Catégories d'encaissement */
function categories_recette(): array {
    return ['Ventes' => '💰', 'Acompte' => '🤝', 'Solde' => '✅', 'Autre recette' => '📌'];
}

/* ----------------------------------------------------------------------------
   Montant TTC d'une facture. Calculé en UN SEUL endroit : la remise est un
   pourcentage, et l'appliquer comme un montant fixe ailleurs fausserait les
   comptes sans que rien ne le signale.
   ---------------------------------------------------------------------------- */
function facture_ttc(PDO $pdo, int $factureId): float {
    try {
        $st = $pdo->prepare("SELECT f.remise, f.tva_taux, f.tva_applicable,
                    (SELECT COALESCE(SUM(quantite * prix_unitaire), 0)
                       FROM facture_lignes WHERE facture_id = f.id) AS ht
                 FROM factures f WHERE f.id = ?");
        $st->execute([$factureId]);
        $f = $st->fetch();
        if (!$f) return 0.0;
        /* La remise est un MONTANT retranché du total, pas un pourcentage :
           c'est ce que fait le formulaire de facturation. */
        $base = max(0, (float)$f['ht'] - (float)$f['remise']);
        return $f['tva_applicable'] ? $base * (1 + (float)$f['tva_taux'] / 100) : $base;
    } catch (Throwable $e) { return 0.0; }
}

/* Ce que le client a déjà réglé pour cette facture : acomptes encaissés par
   bon d'entrée, et paiements en ligne. */
function facture_deja_encaisse(PDO $pdo, int $factureId, ?int $saufTransaction = null): float {
    $total = 0.0;
    try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) FROM recus
                             WHERE facture_id = ? AND type = 'entree'");
        $st->execute([$factureId]);
        $total = (float)$st->fetchColumn();
    } catch (Throwable $e) {}
    return $total;
}

/* ----------------------------------------------------------------------------
   Encaissement automatique d'une facture passée à « payée ».
   On n'enregistre QUE le solde restant : si le client a déjà versé un acompte
   par bon d'entrée, le compter une seconde fois gonflerait le chiffre
   d'affaires. C'est l'erreur la plus fréquente en comptabilité de caisse.
   ---------------------------------------------------------------------------- */
function encaissement_auto_facture(PDO $pdo, int $factureId, string $statut): array {
    $repere = null;
    try {
        $st = $pdo->prepare('SELECT numero, client_id FROM factures WHERE id = ?');
        $st->execute([$factureId]);
        $f = $st->fetch();
        if (!$f) return ['ok' => false, 'montant' => 0, 'message' => ''];

        $repere = 'Solde facture ' . $f['numero'];

        /* On efface l'écriture automatique précédente : le statut peut changer
           plusieurs fois, et seule la dernière situation compte. */
        $pdo->prepare("DELETE FROM transactions WHERE type='entree' AND libelle IN (?, ?)")
            ->execute([$repere, 'Encaissement facture ' . $f['numero']]);

        if ($statut !== 'payee') return ['ok' => true, 'montant' => 0, 'message' => 'Statut mis à jour.'];

        $ttc   = facture_ttc($pdo, $factureId);
        $recu  = facture_deja_encaisse($pdo, $factureId);
        $reste = round($ttc - $recu);

        if ($reste <= 0) {
            return ['ok' => true, 'montant' => 0,
                    'message' => 'Facture marquée payée. Les encaissements étaient déjà enregistrés '
                               . '(' . number_format($recu, 0, ',', ' ') . ') : rien n\'a été ajouté.'];
        }

        $pdo->prepare("INSERT INTO transactions (type, categorie, libelle, montant, mode_paiement,
                       client_id, date_operation, notes, facture_id)
                       VALUES ('entree', 'Solde', ?, ?, 'Facture', ?, CURDATE(), ?, ?)")
            ->execute([$repere, $reste, $f['client_id'],
                       $recu > 0
                         ? 'Solde après acomptes déjà encaissés (' . number_format($recu, 0, ',', ' ') . ').'
                         : 'Enregistré automatiquement au passage en « payée ».',
                       $factureId]);

        return ['ok' => true, 'montant' => $reste,
                'message' => 'Facture payée. Solde de ' . number_format($reste, 0, ',', ' ')
                           . ' enregistré en comptabilité.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'montant' => 0, 'message' => 'Statut mis à jour.'];
    }
}

/* ----------------------------------------------------------------------------
   Charges récurrentes : celles qui ne sont pas encore enregistrées ce mois-ci.
   On compare sur le libellé et le mois, pour ne jamais proposer deux fois la
   même charge.
   ---------------------------------------------------------------------------- */
function charges_du_mois(PDO $pdo, string $mois): array {
    $liste = [];
    try {
        foreach ($pdo->query("SELECT * FROM charges_recurrentes WHERE actif=1 ORDER BY jour_du_mois, libelle")->fetchAll() as $ch) {
            $st = $pdo->prepare("SELECT id FROM transactions
                                 WHERE libelle = ? AND DATE_FORMAT(date_operation,'%Y-%m') = ? LIMIT 1");
            $st->execute([$ch['libelle'], $mois]);
            $ch['deja'] = (int)($st->fetchColumn() ?: 0);
            $liste[] = $ch;
        }
    } catch (Throwable $e) {}
    return $liste;
}

/* ----------------------------------------------------------------------------
   Dépense automatique d'un bulletin de paie réglé.
   Un salaire versé est une sortie d'argent : sans cette écriture, la
   trésorerie et le bénéfice seraient surévalués du montant des salaires.
   ---------------------------------------------------------------------------- */
function depense_auto_bulletin(PDO $pdo, int $bulletinId, string $statut): string {
    try {
        $st = $pdo->prepare("SELECT fp.numero, fp.periode, fp.net_a_payer, fp.mode_paiement,
                                    fp.date_paiement, e.nom AS employe
                             FROM fiches_paie fp LEFT JOIN employes e ON e.id = fp.employe_id
                             WHERE fp.id = ?");
        $st->execute([$bulletinId]);
        $b = $st->fetch();
        if (!$b) return 'Statut mis à jour.';

        $repere = 'Salaire ' . $b['numero'];

        /* On efface l'écriture précédente : le statut peut changer plusieurs
           fois, seule la situation actuelle compte. */
        $pdo->prepare("DELETE FROM transactions WHERE type='depense' AND libelle=?")->execute([$repere]);

        if ($statut !== 'payee') return 'Statut mis à jour.';

        $montant = round((float)$b['net_a_payer']);
        if ($montant <= 0) return 'Statut mis à jour.';

        $pdo->prepare("INSERT INTO transactions (type, categorie, libelle, montant, mode_paiement,
                       date_operation, notes)
                       VALUES ('depense','Salaires',?,?,?,?,?)")
            ->execute([$repere, $montant,
                       $b['mode_paiement'] ?: 'Virement',
                       $b['date_paiement'] ?: date('Y-m-d'),
                       'Bulletin de ' . ($b['employe'] ?? '') . ' — période ' . $b['periode'] . '.']);

        return 'Bulletin marqué payé. Salaire de ' . number_format($montant, 0, ',', ' ')
             . ' enregistré en dépense.';
    } catch (Throwable $e) { return 'Statut mis à jour.'; }
}

/* ----------------------------------------------------------------------------
   Recalcule le solde automatique d'une facture déjà payée.
   Quand un acompte est ajouté, modifié ou supprimé, le solde enregistré au
   passage en « payée » n'est plus juste : on le refait, sinon les comptes
   gardent une trace d'une situation qui n'existe plus.
   ---------------------------------------------------------------------------- */
function recalculer_solde_facture(PDO $pdo, int $factureId): void {
    if ($factureId <= 0) return;
    try {
        $st = $pdo->prepare('SELECT statut FROM factures WHERE id=?');
        $st->execute([$factureId]);
        $statut = (string)$st->fetchColumn();
        if ($statut === '') return;
        encaissement_auto_facture($pdo, $factureId, $statut);
    } catch (Throwable $e) { /* un recalcul raté ne doit pas bloquer la saisie */ }
}

/* ----------------------------------------------------------------------------
   Détection d'un doublon comptable.
   Deux opérations de même sens, de même montant et à quelques jours d'écart
   sont presque toujours la même chose saisie deux fois : une fois par le bon
   de caisse, une fois à la main. On alerte plutôt que de laisser fausser les
   comptes — sans bloquer, car un cas légitime existe (deux achats identiques).
   ---------------------------------------------------------------------------- */
function ecriture_doublon(PDO $pdo, string $type, float $montant, string $date, int $jours = 3): ?array {
    try {
        $st = $pdo->prepare("SELECT id, libelle, montant, date_operation, recu_id
                             FROM transactions
                             WHERE type = ? AND ABS(montant - ?) < 1
                               AND ABS(DATEDIFF(date_operation, ?)) <= ?
                             ORDER BY ABS(DATEDIFF(date_operation, ?)) LIMIT 1");
        $st->execute([$type, $montant, $date, $jours, $date]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

/* ----------------------------------------------------------------------------
   Rentabilité d'une activité : ce qu'elle a rapporté, ce qu'elle a coûté.
   Le chiffre d'affaires est le montant de la facture ; les dépenses sont
   toutes les sorties rattachées à cette activité.
   ---------------------------------------------------------------------------- */
function rentabilite_activite(PDO $pdo, int $factureId): array {
    $r = ['ca' => 0.0, 'encaisse' => 0.0, 'depenses' => 0.0, 'marge' => 0.0, 'taux' => 0.0];
    try {
        /* Un seul calcul du TTC dans toute l'application : deux formules
           divergentes donneraient deux chiffres d'affaires différents. */
        $r['ca'] = facture_ttc($pdo, $factureId);

        /* Encaissements réellement reçus pour cette activité */
        $st = $pdo->prepare("SELECT COALESCE(SUM(montant),0) FROM recus WHERE facture_id=? AND type='entree'");
        $st->execute([$factureId]);
        $r['encaisse'] = (float)$st->fetchColumn();

        /* Dépenses rattachées */
        $st = $pdo->prepare("SELECT COALESCE(SUM(montant),0) FROM transactions WHERE facture_id=? AND type='depense'");
        $st->execute([$factureId]);
        $r['depenses'] = (float)$st->fetchColumn();
    } catch (Throwable $e) { /* on renvoie des zéros plutôt qu'une erreur */ }

    $r['marge'] = $r['ca'] - $r['depenses'];
    $r['taux']  = $r['ca'] > 0 ? ($r['marge'] / $r['ca']) * 100 : 0;
    return $r;
}

/* Modes de paiement proposes dans toute l'application */
function modes_paiement(): array {
    return ['Espèces', 'Wave', 'Orange Money', 'MTN Money', 'Moov Money',
            'Virement bancaire', 'Chèque', 'Carte bancaire', 'À la livraison'];
}

/* ---------- Comptes & permissions ---------- */
/* Catalogue central des modules de l'admin.
   [clé => [libellé, icône, url, groupe, admin_seulement]] */
function all_modules(): array {
    return [
        'commandes_client' => ['Commandes clients', '📦', 'commandes-client.php', 'activite', false, false],
        'calendrier'   => ['Calendrier',     '📅', 'calendrier.php',   'activite', false, false],
        'recherche'    => ['Recherche', '🔍', 'recherche.php', 'activite', false, true],
        'messages'     => ['E-mail', '✉️', 'messages.php', 'echange', false, false],
        'annuaire'     => ['Annuaire', '📇', 'annuaire.php', 'commercial', false, false],
        'clients'      => ['Clients',        '👥', 'clients.php',      'commercial', false, false],
        'paiements'    => ['Paiements en ligne', '💳', 'paiements.php', 'finances', false, false],
        'finances'     => ['Bilan financier', '📊', 'finances.php', 'finances', true, false],
        'relances'     => ['Impayés', '✉️', 'relances.php', 'finances', false, false],
        'comptabilite' => ['Comptabilité',   '💰', 'comptabilite.php', 'finances', false, false],
        'stock'        => ['Stock',          '📦', 'stock.php',        'activite', false, false],
        'factures'     => ['Factures',       '🧾', 'factures.php',     'commercial', false, false],
        'proformas'    => ['Proformas',      '📋', 'factures.php?doc=proforma', 'commercial', false, false],
        'bons_sortie'  => ['Sorties',        '📤', 'recus.php',                'finances', false, false],
        'bons_entree'  => ['Entrées',        '📥', 'recus.php?type=entree',    'finances', false, false],
        'paie'         => ['Bulletins de paie', '📄', 'paie.php',      'finances', false, false],
        'employes'     => ['Employés & accès', '🧑‍🍳', 'employes.php',   'equipe', true,  false],
        'comptes'      => ['Comptes & accès', '🔑', 'comptes.php', 'equipe', true,  false],
        'badges'       => ['Badges & cartes', '🪪', 'badges.php',    'equipe', true,  false],
        'externes'     => ['Membres externes', '🧳', 'externes.php',  'equipe', true,  false],
        'documents'    => ['Éditeur de texte', '📝', 'documents.php', 'docs', false, true],
        'coffre'       => ['Coffre', '🗄️', 'coffre.php',      'docs', false, false],
        'taches'       => ['Tâches',         '✅', 'taches.php',       'equipe', false, true],
        'journal'      => ['Journal', '📖', 'journal.php', 'equipe', true, false],
        'rapports'     => ['Rapports & demandes',       '📝', 'rapports.php',     'equipe', false, true],
        'messagerie'   => ['Messagerie',     '💬', 'messagerie.php',   'echange', false, true],
        'forum'        => ['Forum d\'équipe','📣', 'forum.php',        'echange', false, true],
        'menu'         => ['Menu',           '🍽️', 'menu.php',         'site',    false, false],
        'services'     => ['Services',       '✨', 'services.php',     'site',    false, false],
        'galerie'      => ['Galerie',        '📸', 'galerie.php',      'site',    false, false],
        'videos'       => ['Vidéos',         '🎬', 'videos.php',       'site',    false, false],
        'temoignages'  => ['Témoignages',    '💬', 'temoignages.php',  'site',    false, false],
        'parametres'   => ['Paramètres',     '⚙️', 'parametres.php',   'site',    true,  false],
    ];
}

function current_user(): array {
    return [
        'id'    => $_SESSION['admin_id'] ?? 0,
        'nom'   => $_SESSION['admin_nom'] ?? '',
        'role'  => $_SESSION['admin_role'] ?? 'admin',
        'perms' => $_SESSION['admin_perms'] ?? [],
    ];
}

function is_admin(): bool { return (current_user()['role']) === 'admin'; }

/* Cette personne a-t-elle accès à ce module ?
   L'administrateur voit tout ; l'employé, uniquement ce qui lui a été accordé
   ainsi que les sections déclarées « toujours accessibles ». */
function peut_acceder(string $module): bool {
    if (is_admin()) return true;
    $m = all_modules()[$module] ?? null;
    if (!$m) return false;
    if (!empty($m[4])) return false;                    // réservé à l'administrateur
    if (!empty($m[5])) return true;                     // toujours accessible
    return in_array($module, current_user()['perms'] ?? [], true);
}
function is_client(): bool { return (current_user()['role']) === 'client'; }

/* Vérifie si l'heure actuelle est dans les horaires de travail autorisés (pour les employés) */
function within_work_hours(array $settings): bool {
    if (($settings['work_hours_actif'] ?? '1') !== '1') return true; // contrôle désactivé
    $jours = array_filter(array_map('intval', explode(',', $settings['work_jours'] ?? '1,2,3,4,5,6')));
    $jourAuj = (int)date('N'); // 1 (lundi) .. 7 (dimanche)
    if (!in_array($jourAuj, $jours, true)) return false;
    $debut = $settings['work_debut'] ?? '08:00';
    $fin   = $settings['work_fin']   ?? '17:00';
    $now = date('H:i');
    return ($now >= $debut && $now <= $fin);
}

/* Message décrivant les horaires autorisés */
function work_hours_label(array $settings): string {
    $noms = [1=>'Lundi',2=>'Mardi',3=>'Mercredi',4=>'Jeudi',5=>'Vendredi',6=>'Samedi',7=>'Dimanche'];
    $jours = array_filter(array_map('intval', explode(',', $settings['work_jours'] ?? '1,2,3,4,5,6')));
    sort($jours);
    $libJours = $jours ? ($noms[reset($jours)] . ' au ' . $noms[end($jours)]) : '—';
    return $libJours . ', de ' . ($settings['work_debut'] ?? '08:00') . ' à ' . ($settings['work_fin'] ?? '17:00');
}

/* Upload d'un fichier joint (messagerie, forum) : images + documents courants */
function upload_doc(array $file, string $dir): ?array {
    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $ok = ['jpg','jpeg','png','webp','gif','pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','zip'];
    if (!in_array($ext, $ok)) return null;
    if ($file['size'] > 15 * 1024 * 1024) return null; // 15 Mo max
    $name = uniqid('file_') . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], rtrim($dir,'/') . '/' . $name)) {
        return ['fichier' => $name, 'nom' => mb_substr(basename($file['name']), 0, 200)];
    }
    return null;
}

/* Upload pour la messagerie : documents, images, vidéos, DWG… jusqu'à 200 Mo */
function upload_message(array $file, string $dir): ?array {
    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $ok = ['jpg','jpeg','png','webp','gif','bmp','svg','heic',
           'pdf','doc','docx','odt','rtf','txt','md',
           'xls','xlsx','ods','csv','tsv',
           'ppt','pptx','odp',
           'zip','rar','7z','tar','gz',
           'mp3','wav','ogg','m4a',
           'mp4','mov','avi','webm','mkv',
           'dwg','dxf','dwf','skp','stl','step','stp','igs','iges'];
    if (!in_array($ext, $ok, true)) return null;
    if ($file['size'] > 200 * 1024 * 1024) return null; // 200 Mo max
    $name = uniqid('msg_') . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], rtrim($dir,'/') . '/' . $name)) {
        return ['fichier' => $name, 'nom' => mb_substr(basename($file['name']), 0, 200)];
    }
    return null;
}

/* Upload pour le coffre-fort documentaire : quasiment tous types, 30 Mo */
function upload_coffre(array $file, string $dir): ?array {
    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $ok = ['jpg','jpeg','png','webp','gif','bmp','svg','heic',
           'pdf','doc','docx','odt','rtf','txt','md',
           'xls','xlsx','ods','csv','tsv',
           'ppt','pptx','odp',
           'zip','rar','7z','tar','gz',
           'mp3','wav','mp4','mov','avi','webm'];
    if (!in_array($ext, $ok)) return null;
    if ($file['size'] > 30 * 1024 * 1024) return null; // 30 Mo max
    $name = uniqid('doc_') . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], rtrim($dir,'/') . '/' . $name)) {
        return ['fichier' => $name, 'nom' => mb_substr(basename($file['name']), 0, 200), 'taille' => (int)$file['size']];
    }
    return null;
}

/* Taille lisible (Ko, Mo) */
function taille_lisible(int $o): string {
    if ($o >= 1048576) return number_format($o/1048576, 1, ',', ' ') . ' Mo';
    if ($o >= 1024) return number_format($o/1024, 0, ',', ' ') . ' Ko';
    return $o . ' o';
}

/* L'utilisateur courant a-t-il accès à un module ? */
function can(string $key): bool {
    $u = current_user();
    if ($u['role'] === 'admin') return true;      // l'admin voit tout
    if ($key === 'dashboard') return true;         // tableau de bord toujours accessible
    $mods = all_modules();
    if (($mods[$key][5] ?? false)) return true;    // module « core » (tâches, rapports) : tout employé
    if (($mods[$key][4] ?? false)) return false;   // module réservé à l'admin
    if (in_array($key, $u['perms'], true)) return true;
    // Compatibilité : anciens comptes ayant "plats" ou "categories" avant la fusion en "Menu"
    if ($key === 'menu' && (in_array('plats', $u['perms'], true) || in_array('categories', $u['perms'], true))) return true;
    return false;
}

/* Bloque l'accès à une page si l'employé n'a pas la permission */
function require_perm(string $key): void {
    if (!can($key)) {
        flash("Vous n'avez pas accès à cette section.", 'error');
        header('Location: index.php');
        exit;
    }
}

/* Nettoie le HTML d'un rapport (garde la mise en forme, retire le dangereux) */
function clean_report_html(string $html): string {
    $allowed = '<p><br><b><strong><i><em><u><s><strike><h1><h2><h3><h4><ul><ol><li><a><span><div><blockquote><font><table><tr><td><th><thead><tbody><hr>';
    $html = strip_tags($html, $allowed);
    $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    $html = preg_replace('/(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2/i', '$1=$2#$2', $html);
    return $html;
}

function flash(?string $msg = null, string $type = 'success') {
    if ($msg !== null) { $_SESSION['flash'] = ['msg' => $msg, 'type' => $type]; return; }
    if (!empty($_SESSION['flash'])) { $f = $_SESSION['flash']; unset($_SESSION['flash']); return $f; }
    return null;
}

/* ---------- Gestion / comptabilité ---------- */
function money($n, string $devise = 'FCFA'): string {
    return number_format((float)$n, 0, ',', ' ') . ' ' . $devise;
}

/* Génère un numéro de document séquentiel : PREFIXE-ANNÉE-0001 */
/* Numéro du bon de livraison : attribué à la première édition, puis conservé.
   Il suit sa propre séquence et ne dépend pas du numéro de facture. */
function numero_bon_livraison(PDO $pdo, int $factureId): string {
    $st = $pdo->prepare('SELECT bl_numero FROM factures WHERE id=?');
    $st->execute([$factureId]);
    $num = trim((string)$st->fetchColumn());
    if ($num !== '') return $num;

    $annee = date('Y');
    /* Le préfixe est réglable dans Paramètres → Facturation. */
    $prefixe = 'BL';
    try {
        $p = $pdo->query("SELECT valeur FROM settings WHERE cle='prefixe_livraison'")->fetchColumn();
        if (trim((string)$p) !== '') $prefixe = strtoupper(trim((string)$p));
    } catch (Throwable $e) {}

    $st = $pdo->prepare("SELECT bl_numero FROM factures WHERE bl_numero LIKE ?
                         ORDER BY bl_numero DESC LIMIT 1");
    $st->execute([$prefixe . '-' . $annee . '-%']);
    $dernier = $st->fetchColumn();
    $n = $dernier ? ((int)substr($dernier, -4)) + 1 : 1;
    $num = sprintf('%s-%s-%04d', $prefixe, $annee, $n);

    try {
        $pdo->prepare('UPDATE factures SET bl_numero=? WHERE id=?')->execute([$num, $factureId]);
    } catch (Throwable $e) { /* l'édition ne doit jamais échouer pour un numéro */ }
    return $num;
}

/* ----------------------------------------------------------------------------
   Adresse publique du site, utilisée dans les emails et les QR de vérification.
   Ordre de priorité : la variable SITE_URL du serveur, puis le réglage saisi,
   puis le domaine réellement utilisé. Une adresse « localhost » est écartée :
   elle ne fonctionnerait chez aucun destinataire.
   ---------------------------------------------------------------------------- */
function adresse_site(array $settings = []): string {
    $candidats = [];
    if (defined('SITE_URL') && trim((string)SITE_URL) !== '') $candidats[] = (string)SITE_URL;
    if (!empty($settings['site_url']))                        $candidats[] = (string)$settings['site_url'];

    foreach ($candidats as $u) {
        $u = rtrim(trim($u), '/');
        if ($u === '') continue;
        if (!preg_match('#^https?://#i', $u)) $u = 'https://' . $u;
        if (preg_match('#//(localhost|127\.0\.0\.1|::1)#i', $u)) continue;   // inutilisable en dehors du serveur
        return $u;
    }

    /* Dernier recours : le domaine par lequel on accède à l'application. */
    if (!empty($_SERVER['HTTP_HOST'])) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $hote = $_SERVER['HTTP_HOST'];
        if (!preg_match('#^(localhost|127\.0\.0\.1|::1)#i', $hote)) {
            return ($https ? 'https://' : 'http://') . $hote;
        }
    }
    return '';
}

function next_numero(PDO $pdo, string $table, string $prefixe): string {
    $annee = date('Y');
    $like = $prefixe . '-' . $annee . '-%';
    $stmt = $pdo->prepare("SELECT numero FROM $table WHERE numero LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$like]);
    $dernier = $stmt->fetchColumn();
    $n = $dernier ? ((int)substr($dernier, -4)) + 1 : 1;
    return sprintf('%s-%s-%04d', $prefixe, $annee, $n);
}

/* Ajoute la date de modification du fichier à l'URL d'un asset
   → le navigateur recharge automatiquement CSS/JS après une mise à jour */
function asset(string $chemin): string {
    $abs = __DIR__ . '/../' . ltrim(preg_replace('#^(\.\./)+#', '', $chemin), '/');
    $v = is_file($abs) ? filemtime($abs) : time();
    return $chemin . '?v=' . $v;
}

/* ============================================================================
   NOTIFICATIONS — une liste unique, adaptée au rôle de la personne connectée.
   Renvoie ['total' => int, 'items' => [['icone','texte','url','n'], …]]
   ============================================================================ */
function notifications(PDO $pdo, array $u, string $prefixe = ''): array {
    $items = []; $uid = (int)($u['id'] ?? 0); $role = $u['role'] ?? 'admin';
    $q = function (string $sql) use ($pdo) {
        try { return (int)$pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; }
    };
    $add = function ($icone, $texte, $url, $n) use (&$items) {
        if ($n > 0) $items[] = ['icone' => $icone, 'texte' => $texte, 'url' => $url, 'n' => $n];
    };

    if ($role === 'client') {
        $cid = (int)($u['client_id'] ?? 0);
        if ($cid) {
            $add('📄', 'devis disponible(s)', 'mes-commandes.php',
                 $q("SELECT COUNT(*) FROM commandes_client WHERE client_id=$cid AND statut='devis_envoye' AND vu_client=0"));
            $add('📦', 'commande(s) mise(s) à jour', 'mes-commandes.php',
                 $q("SELECT COUNT(*) FROM commandes_client WHERE client_id=$cid AND vu_client=0 AND statut<>'devis_envoye'"));
            $add('🧾', 'nouvelle(s) facture(s)', 'index.php',
                 $q("SELECT COUNT(*) FROM factures WHERE client_id=$cid AND type='facture' AND vu_client=0"));
            $add('📤', 'nouveau(x) bon(s) de sortie', 'index.php',
                 $q("SELECT COUNT(*) FROM recus WHERE client_id=$cid AND vu_client=0"));
        }
    } elseif ($role === 'admin') {
        $add('📝', 'rapport(s) & demande(s) reçu(s)', $prefixe . 'rapports.php',
             $q("SELECT COUNT(*) FROM rapports WHERE statut='envoye' AND lu_par_admin=0"));
        $add('📦', 'nouvelle(s) commande(s) client', $prefixe . 'commandes-client.php',
             $q("SELECT COUNT(*) FROM commandes_client WHERE statut='nouvelle'"));
        $add('📮', 'demande(s) de devis du site', $prefixe . 'commandes-client.php?vue=devis',
             $q("SELECT COUNT(*) FROM commandes WHERE statut='nouveau'"));
        $add('💬', 'message(s) non lu(s)', $prefixe . 'messagerie.php',
             $q("SELECT COUNT(*) FROM messages WHERE destinataire_id=$uid AND lu=0"));
        $add('⭐', 'avis en attente de validation', $prefixe . 'temoignages.php',
             $q("SELECT COUNT(*) FROM temoignages WHERE statut='en_attente'"));
    } else { /* employé */
        $add('✅', 'nouvelle(s) tâche(s)', $prefixe . 'taches.php',
             $q("SELECT COUNT(*) FROM taches WHERE assigne_a=$uid AND vue=0"));
        $add('📝', 'réponse(s) à mes demandes', $prefixe . 'rapports.php',
             $q("SELECT COUNT(*) FROM rapports WHERE employe_user_id=$uid AND vu_par_employe=0 AND decision IS NOT NULL AND decision<>'en_attente'"));
        $add('💬', 'message(s) non lu(s)', $prefixe . 'messagerie.php',
             $q("SELECT COUNT(*) FROM messages WHERE destinataire_id=$uid AND lu=0"));
    }

    /* Forum : messages postés depuis la dernière visite (admin et employés) */
    if ($role !== 'client') {
        try {
            $st = $pdo->prepare('SELECT forum_vu_at FROM users WHERE id=?'); $st->execute([$uid]);
            $depuis = $st->fetchColumn();
            $sql = "SELECT COUNT(*) FROM forum_posts WHERE auteur_id<>$uid"
                 . ($depuis ? " AND created_at > " . $pdo->quote($depuis) : '');
            $add('📣', 'message(s) sur le forum', $prefixe . 'forum.php', (int)$pdo->query($sql)->fetchColumn());
        } catch (Throwable $e) {}
    }

    $total = 0; foreach ($items as $i) $total += $i['n'];
    return ['total' => $total, 'items' => $items];
}

/* Aperçu d'un fichier joint (messagerie, forum) — style WhatsApp.
   Affiche directement : image, vidéo (lecteur), audio (lecteur), PDF (aperçu).
   Pour les autres types, un lien de téléchargement élégant avec icône.
   $base = préfixe vers le dossier uploads (ex: '../uploads'). */
function apercu_fichier(string $fichier, string $nom, string $base = '../uploads'): string {
    if ($fichier === '') return '';
    $ext = strtolower(pathinfo($fichier, PATHINFO_EXTENSION));
    $url = $base . '/' . rawurlencode($fichier);
    $nomAff = e($nom ?: 'Fichier');

    $images = ['jpg','jpeg','png','webp','gif','bmp','svg'];
    $videos = ['mp4','mov','webm','m4v','ogv'];       // types lisibles nativement dans le navigateur
    $videosLourdes = ['avi','mkv','wmv','flv','3gp'];  // pas toujours lisibles : lien + tentative
    $audios = ['mp3','wav','ogg','m4a','aac'];

    // Image → aperçu cliquable (ouvre en grand)
    if (in_array($ext, $images, true)) {
        return '<a href="' . e($url) . '" target="_blank" rel="noopener" class="chat-media chat-media-img">'
             . '<img src="' . e($url) . '" alt="' . $nomAff . '" loading="lazy"></a>';
    }

    // Vidéo lisible → lecteur intégré
    if (in_array($ext, $videos, true)) {
        return '<div class="chat-media chat-media-video">'
             . '<video controls preload="metadata" playsinline>'
             . '<source src="' . e($url) . '" type="' . e(mime_video($ext)) . '">'
             . 'Votre navigateur ne peut pas lire cette vidéo.'
             . '</video></div>';
    }

    // Audio → lecteur intégré
    if (in_array($ext, $audios, true)) {
        return '<div class="chat-media chat-media-audio">'
             . '<div class="chat-audio-nom">🎵 ' . $nomAff . '</div>'
             . '<audio controls preload="metadata"><source src="' . e($url) . '">'
             . 'Lecture audio non supportée.</audio></div>';
    }

    // PDF → grande carte façon WhatsApp : aperçu visuel d'une page + nom lisible
    if ($ext === 'pdf') {
        return '<a href="' . e($url) . '" target="_blank" rel="noopener" class="chat-pdf-card" title="Ouvrir le PDF">'
             . '<div class="chat-pdf-preview">'
             .   '<div class="chat-pdf-page">'
             .     '<span class="chat-pdf-corner"></span>'
             .     '<span class="l w90"></span><span class="l w75"></span><span class="l w85"></span>'
             .     '<span class="l w60"></span><span class="l sp"></span>'
             .     '<span class="l w80"></span><span class="l w70"></span><span class="l w88"></span>'
             .     '<span class="l w55"></span>'
             .   '</div>'
             .   '<span class="chat-pdf-badge">PDF</span>'
             . '</div>'
             . '<div class="chat-pdf-foot"><span class="chat-pdf-ico">📄</span>'
             .   '<span class="chat-pdf-nom">' . $nomAff . '</span><span class="chat-pdf-open">Ouvrir ↗</span></div>'
             . '</a>';
    }

    // Vidéo au format moins courant → on tente le lecteur, avec lien de repli
    if (in_array($ext, $videosLourdes, true)) {
        return '<div class="chat-media chat-media-video">'
             . '<video controls preload="metadata" playsinline><source src="' . e($url) . '">'
             . '</video>'
             . '<a href="' . e($url) . '" target="_blank" rel="noopener" download="' . $nomAff . '" class="chat-file chat-file-sous">⬇️ Télécharger si la lecture échoue</a></div>';
    }

    // Autres (documents, archives, plans…) → lien de téléchargement avec icône adaptée
    $icone = icone_fichier($ext);
    return '<a class="chat-file" href="' . e($url) . '" target="_blank" rel="noopener" download="' . $nomAff . '">'
         . '<span class="chat-file-ico">' . $icone . '</span>'
         . '<span class="chat-file-nom">' . $nomAff . '</span>'
         . '<span class="chat-file-dl">⬇️</span></a>';
}

/* Type MIME d'une vidéo selon l'extension (pour la balise <source>) */
function mime_video(string $ext): string {
    return [
        'mp4'=>'video/mp4', 'm4v'=>'video/mp4', 'mov'=>'video/quicktime',
        'webm'=>'video/webm', 'ogv'=>'video/ogg',
    ][$ext] ?? 'video/mp4';
}

/* Icône selon le type de fichier (pour les documents non prévisualisables) */
function icone_fichier(string $ext): string {
    $map = [
        'doc'=>'📝','docx'=>'📝','odt'=>'📝','rtf'=>'📝','txt'=>'📄','md'=>'📄',
        'xls'=>'📊','xlsx'=>'📊','ods'=>'📊','csv'=>'📊','tsv'=>'📊',
        'ppt'=>'📑','pptx'=>'📑','odp'=>'📑',
        'zip'=>'🗜️','rar'=>'🗜️','7z'=>'🗜️','tar'=>'🗜️','gz'=>'🗜️',
        'dwg'=>'📐','dxf'=>'📐','dwf'=>'📐','skp'=>'📐','stl'=>'📐','step'=>'📐','stp'=>'📐','igs'=>'📐','iges'=>'📐',
    ];
    return $map[$ext] ?? '📎';
}

/* Génère le numéro de référence de traçabilité d'un membre externe : REF-2026-001.
   La séquence repart à 1 chaque année. */
function externe_generer_reference(PDO $pdo, array $settings = []): string {
    // Format unifié Groupe Helisce : GH-EX01-2608 (EX01 = n° externe continu, 2608 = année+mois)
    $prefixe = trim((string)($settings['badge_prefixe'] ?? 'GH')) ?: 'GH';
    $ym = date('y') . date('m'); // 2608
    // Numéro continu : on prend le plus grand numéro déjà utilisé, +1
    $st = $pdo->query("SELECT reference FROM externes WHERE reference LIKE '" . $prefixe . "-EX%'");
    $max = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ref) {
        if (preg_match('/-EX(\d+)/', $ref, $m)) { $n = (int)$m[1]; if ($n > $max) $max = $n; }
    }
    $seq = $max + 1;
    // Anti-collision
    for ($essai = 0; $essai < 10000; $essai++) {
        $num = str_pad((string)$seq, 2, '0', STR_PAD_LEFT);
        $ref = $prefixe . '-EX' . $num . '-' . $ym;
        $chk = $pdo->prepare("SELECT 1 FROM externes WHERE reference=?");
        $chk->execute([$ref]);
        if (!$chk->fetchColumn()) return $ref;
        $seq++;
    }
    return $prefixe . '-EX' . substr(uniqid(), -4) . '-' . $ym;
}

/* Retourne le nom d'un membre à partir de son user_id.
   Si le compte a été supprimé, retrouve le nom dans l'archive des anciens membres.
   Garantit que les données historiques (messages, forum…) gardent un auteur nommé. */
function nom_membre(PDO $pdo, $userId, string $defaut = 'Ancien membre'): string {
    $userId = (int)$userId;
    if ($userId <= 0) return $defaut;
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];
    $st = $pdo->prepare("SELECT nom FROM users WHERE id=?");
    $st->execute([$userId]);
    $nom = $st->fetchColumn();
    if ($nom === false || $nom === null || $nom === '') {
        // Compte supprimé → chercher dans l'archive
        try {
            $st = $pdo->prepare("SELECT nom FROM anciens_membres WHERE user_id=?");
            $st->execute([$userId]);
            $arch = $st->fetchColumn();
            $nom = ($arch !== false && $arch !== null && $arch !== '') ? $arch : $defaut;
        } catch (\Throwable $e) { $nom = $defaut; }
    }
    $cache[$userId] = $nom;
    return $nom;
}

/* Archive le nom d'un compte avant sa suppression, puis le prépare pour suppression.
   Appelé juste avant DELETE FROM users, pour préserver l'identité historique. */
function archiver_membre(PDO $pdo, int $userId): void {
    if ($userId <= 0) return;
    $st = $pdo->prepare("SELECT nom, role FROM users WHERE id=?");
    $st->execute([$userId]);
    $u = $st->fetch();
    if ($u) {
        try {
            $pdo->prepare("INSERT INTO anciens_membres (user_id, nom, role) VALUES (?,?,?)
                           ON DUPLICATE KEY UPDATE nom=VALUES(nom), role=VALUES(role)")
                ->execute([$userId, $u['nom'] ?: 'Ancien membre', $u['role'] ?? null]);
        } catch (\Throwable $e) { /* table absente : ignore */ }
    }
}

/* Logo de l'entreprise en HTML (image si disponible, sinon emoji de secours).
   $base = préfixe vers la racine ('.', '..', '' selon l'emplacement). */
function logo_html(string $base = '.', string $classe = 'app-logo', string $secours = '🍽️'): string {
    $s = $GLOBALS['settings'] ?? [];
    if (!$s && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        try { $s = get_settings($GLOBALS['pdo']); } catch (\Throwable $e) { $s = []; }
    }
    $fichier = trim((string)($s['logo'] ?? ''));
    $aImage = false; $src = $base . '/assets/img/logo.png';
    if ($fichier !== '' && is_file(__DIR__ . '/../uploads/' . $fichier)) {
        $src = $base . '/uploads/' . rawurlencode($fichier);
        $aImage = true;
    } elseif (is_file(__DIR__ . '/../assets/img/logo.png')) {
        $aImage = true;
    }
    if ($aImage) {
        // Vrai logo : dans une pastille claire pour rester visible sur fond dore.
        return '<span class="' . e($classe) . ' logo-img"><img src="' . e($src) . '" alt="Logo"></span>';
    }
    // Pas de logo : emoji de secours sur le fond de la charte.
    return '<span class="' . e($classe) . '">' . $secours . '</span>';
}

/* Récupère l'IP réelle du visiteur (gère les proxys). */
function ip_reelle(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/* Ville approximative à partir de l'IP (géolocalisation, best-effort). */
function ville_depuis_ip(string $ip): string {
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) return '';
    // IP locale / privée : pas de géolocalisation possible
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return 'Réseau local';
    $ctx = stream_context_create(['http' => ['timeout' => 3]]);
    $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,city,country&lang=fr';
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return '';
    $d = json_decode($raw, true);
    if (($d['status'] ?? '') !== 'success') return '';
    $ville = trim(($d['city'] ?? '') . (isset($d['country']) ? ', ' . $d['country'] : ''), ', ');
    return mb_substr($ville, 0, 120);
}

/* Enregistre l'IP et la ville de connexion d'un utilisateur. */
function capturer_connexion(PDO $pdo, int $userId): void {
    $ip = ip_reelle();
    $ville = ville_depuis_ip($ip);
    $pdo->prepare("UPDATE users SET last_ip=?, last_ville=? WHERE id=?")
        ->execute([mb_substr($ip,0,45), $ville, $userId]);
}
