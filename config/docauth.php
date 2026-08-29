<?php
/* Authentification des documents : QR code sécurisé + jetons de vérification.
   Rend les documents infalsifiables : le QR pointe vers une page de vérification
   qui affiche les données officielles issues de la base. */

require_once __DIR__ . '/../lib/qrcode.php';

/* Génère (ou récupère) un jeton unique pour un document donné. */
function doc_token(PDO $pdo, string $type, int $docId, string $numero = '', string $checksum = ''): string {
    $st = $pdo->prepare("SELECT token FROM documents_auth WHERE type=? AND doc_id=?");
    $st->execute([$type, $docId]);
    $tok = $st->fetchColumn();
    if ($tok) return $tok;
    // jeton fort : 32 hexa (128 bits) + préfixe lisible
    $token = strtoupper(bin2hex(random_bytes(16)));
    try {
        $par = $_SESSION['admin_id'] ?? null;
        $pdo->prepare("INSERT INTO documents_auth (type, doc_id, numero, authentifie_par, token, checksum) VALUES (?,?,?,?,?,?)")
            ->execute([$type, $docId, mb_substr($numero,0,60), $par, $token, mb_substr($checksum,0,16)]);
    } catch (\Throwable $e) {
        // en cas de course, relire
        $st->execute([$type, $docId]); $token = $st->fetchColumn() ?: $token;
    }
    return $token;
}

/* Recherche un document par son jeton (page de vérification). */
function doc_verify(PDO $pdo, string $token): ?array {
    $token = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $token));
    if ($token === '') return null;
    $st = $pdo->prepare("SELECT * FROM documents_auth WHERE token=?");
    $st->execute([$token]);
    $row = $st->fetch();
    return $row ?: null;
}

/* Empreinte courte lisible d'un document (affichée sur le doc ET la page de vérif). */
function doc_checksum(array $parts): string {
    $h = strtoupper(substr(hash('sha256', implode('|', $parts)), 0, 10));
    return substr($h,0,5) . '-' . substr($h,5,5);
}

/* URL complète de vérification encodée dans le QR. */
function doc_verify_url(array $settings, string $token): string {
    $base = rtrim($settings['site_url'] ?? '', '/');
    if ($base === '') return 'VERIF:' . $token;
    return $base . '/verifier.php?c=' . $token;
}

/* Matrice QR (tableau 2D de booléens). */
function qr_matrix(string $text): array {
    /* On calcule d'abord la version minimale nécessaire (sinon la lib reste
       en version 1 et déborde pour les contenus longs comme une vCard). */
    $mode = QRUtil::getMode($text);
    $len  = strlen($text);
    $version = 10;   // valeur de repli confortable
    for ($tn = 1; $tn <= 40; $tn++) {
        $max = @QRUtil::getMaxLength($tn, $mode, QR_ERROR_CORRECT_LEVEL_M);
        if ($max && $len <= $max) { $version = $tn; break; }
    }
    $qr = new QRCode();
    $qr->setErrorCorrectLevel(QR_ERROR_CORRECT_LEVEL_M);
    $qr->setTypeNumber($version);
    $qr->addData($text, $mode);
    $qr->make();
    $n = $qr->getModuleCount();
    $m = [];
    for ($r = 0; $r < $n; $r++) {
        $row = [];
        for ($c = 0; $c < $n; $c++) $row[] = $qr->isDark($r, $c);
        $m[] = $row;
    }
    return $m;
}

/* Rendu SVG du QR (pour Dompdf / HTML), fond blanc + marge. */
function qr_svg(string $text, int $scale = 4, int $quiet = 2): string {
    $m = qr_matrix($text);
    $n = count($m);
    $size = ($n + 2 * $quiet) * $scale;
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'" viewBox="0 0 '.$size.' '.$size.'">';
    $svg .= '<rect width="'.$size.'" height="'.$size.'" fill="#ffffff"/>';
    $svg .= '<g fill="#000000">';
    for ($r = 0; $r < $n; $r++) for ($c = 0; $c < $n; $c++) {
        if ($m[$r][$c]) {
            $x = ($c + $quiet) * $scale; $y = ($r + $quiet) * $scale;
            $svg .= '<rect x="'.$x.'" y="'.$y.'" width="'.$scale.'" height="'.$scale.'"/>';
        }
    }
    $svg .= '</g></svg>';
    return $svg;
}

/* QR en data-URI (pour <img> dans Dompdf). */
function qr_datauri(string $text, int $scale = 4): string {
    return 'data:image/svg+xml;base64,' . base64_encode(qr_svg($text, $scale));
}

