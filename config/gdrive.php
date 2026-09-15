<?php
/* ============================================================================
   GOOGLE DRIVE

   Envoie les documents du coffre vers VOTRE Google Drive.

   ----------------------------------------------------------------------------
   POURQUOI CETTE PORTÉE-LÀ ET PAS UNE AUTRE

   On demande la permission « drive.file » : elle donne accès UNIQUEMENT aux
   fichiers créés par cette application. Le reste de votre Drive — vos photos,
   vos documents personnels — reste hors de portée, même en cas de faille.

   C'est aussi un choix pratique : cette permission n'est pas classée sensible
   par Google, donc aucune procédure de vérification n'est exigée pour mettre
   l'application en service. Les portées plus larges demandent un audit de
   sécurité payant et plusieurs semaines de délai.

   ----------------------------------------------------------------------------
   CE QUI EST CONSERVÉ

   Le jeton de rafraîchissement, obtenu une seule fois lors de l'autorisation.
   Il permet de demander un jeton d'accès valable une heure, sans vous
   redemander votre mot de passe. Il se révoque depuis votre compte Google,
   ou depuis le bouton « Déconnecter » de cette application.
   ============================================================================ */

const GDRIVE_PORTEE     = 'https://www.googleapis.com/auth/drive.file';
const GDRIVE_AUTH       = 'https://accounts.google.com/o/oauth2/v2/auth';
const GDRIVE_JETON      = 'https://oauth2.googleapis.com/token';
const GDRIVE_FICHIERS   = 'https://www.googleapis.com/drive/v3/files';
const GDRIVE_ENVOI      = 'https://www.googleapis.com/upload/drive/v3/files';

/* ----------------------------------------------------------------------------
   Requête HTTP.

   cURL quand il est là — c'est le cas sur la quasi-totalité des hébergements.
   Sinon on retombe sur les flux natifs de PHP, moins bavards en cas d'erreur
   mais suffisants.
   ---------------------------------------------------------------------------- */
function gdrive_http(string $url, array $o = []): array {
    $methode = $o['methode'] ?? 'GET';
    $entetes = $o['entetes'] ?? [];
    $corps   = $o['corps'] ?? null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $methode,
            CURLOPT_HTTPHEADER     => $entetes,
            CURLOPT_TIMEOUT        => $o['delai'] ?? 60,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => !empty($o['avec_entetes']),
        ]);
        if ($corps !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $corps);

        $rep  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        $tailleEntetes = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($rep === false) return ['code' => 0, 'corps' => '', 'entetes' => '', 'erreur' => $err];

        if (!empty($o['avec_entetes'])) {
            return ['code' => $code, 'entetes' => substr($rep, 0, $tailleEntetes),
                    'corps' => substr($rep, $tailleEntetes), 'erreur' => ''];
        }
        return ['code' => $code, 'corps' => $rep, 'entetes' => '', 'erreur' => ''];
    }

    $ctx = stream_context_create(['http' => [
        'method'        => $methode,
        'header'        => implode("\r\n", $entetes),
        'content'       => $corps,
        'timeout'       => $o['delai'] ?? 60,
        'ignore_errors' => true,
    ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);

    $rep = @file_get_contents($url, false, $ctx);
    $code = 0;
    $brut = '';
    if (isset($http_response_header)) {
        $brut = implode("\r\n", $http_response_header);
        if (preg_match('~HTTP/\S+\s+(\d{3})~', $http_response_header[0], $m)) $code = (int)$m[1];
    }
    return ['code' => $code, 'corps' => $rep === false ? '' : $rep,
            'entetes' => $brut, 'erreur' => $rep === false ? 'Appel sortant impossible' : ''];
}

/* ----------------------------------------------------------------------------
   Réglages enregistrés.
   ---------------------------------------------------------------------------- */
function gdrive_reglages(PDO $pdo): array {
    $s = get_settings($pdo);
    return [
        'client_id'     => trim((string)($s['gdrive_client_id'] ?? '')),
        'client_secret' => trim((string)($s['gdrive_client_secret'] ?? '')),
        'refresh'       => trim((string)($s['gdrive_refresh_token'] ?? '')),
        'acces'         => trim((string)($s['gdrive_acces'] ?? '')),
        'acces_fin'     => (int)($s['gdrive_acces_fin'] ?? 0),
        'dossier_id'    => trim((string)($s['gdrive_dossier_id'] ?? '')),
        'dossier_nom'   => trim((string)($s['gdrive_dossier_nom'] ?? 'Coffre — documents')),
        'compte'        => trim((string)($s['gdrive_compte'] ?? '')),
        'auto'          => ($s['gdrive_auto'] ?? '0') === '1',
    ];
}

function gdrive_enregistrer(PDO $pdo, array $valeurs): void {
    $st = $pdo->prepare("INSERT INTO settings (cle, valeur) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)");
    foreach ($valeurs as $k => $v) $st->execute([$k, (string)$v]);
}

function gdrive_connecte(PDO $pdo): bool {
    $r = gdrive_reglages($pdo);
    return $r['client_id'] !== '' && $r['client_secret'] !== '' && $r['refresh'] !== '';
}

/* Adresse de retour, déclarée à l'identique dans la console Google. */
function gdrive_retour(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $hote   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    /* On part du dossier /admin quel que soit le point d'entrée. */
    if (substr($base, -6) !== '/admin') $base = rtrim($base, '/') . '/admin';
    return $scheme . '://' . $hote . $base . '/gdrive-retour.php';
}

/* ----------------------------------------------------------------------------
   Étape 1 : envoyer l'utilisateur donner son accord.
   ---------------------------------------------------------------------------- */
function gdrive_url_autorisation(PDO $pdo, string $etat): string {
    $r = gdrive_reglages($pdo);
    return GDRIVE_AUTH . '?' . http_build_query([
        'client_id'     => $r['client_id'],
        'redirect_uri'  => gdrive_retour(),
        'response_type' => 'code',
        'scope'         => GDRIVE_PORTEE,
        /* « offline » est indispensable : sans lui, Google ne délivre pas de
           jeton de rafraîchissement et la connexion serait perdue au bout
           d'une heure. « consent » force l'écran d'accord, seul moment où ce
           jeton est remis. */
        'access_type'   => 'offline',
        'prompt'        => 'consent',
        'include_granted_scopes' => 'true',
        'state'         => $etat,
    ]);
}

/* ----------------------------------------------------------------------------
   Étape 2 : échanger le code reçu contre les jetons.
   ---------------------------------------------------------------------------- */
function gdrive_echanger_code(PDO $pdo, string $code, ?string &$erreur = null): bool {
    $r = gdrive_reglages($pdo);
    $rep = gdrive_http(GDRIVE_JETON, [
        'methode' => 'POST',
        'entetes' => ['Content-Type: application/x-www-form-urlencoded'],
        'corps'   => http_build_query([
            'code'          => $code,
            'client_id'     => $r['client_id'],
            'client_secret' => $r['client_secret'],
            'redirect_uri'  => gdrive_retour(),
            'grant_type'    => 'authorization_code',
        ]),
    ]);

    $d = json_decode($rep['corps'], true);
    if ($rep['code'] !== 200 || empty($d['access_token'])) {
        $erreur = $d['error_description'] ?? ($d['error'] ?? ($rep['erreur'] ?: 'Réponse inattendue de Google'));
        return false;
    }

    $valeurs = [
        'gdrive_acces'     => $d['access_token'],
        'gdrive_acces_fin' => time() + (int)($d['expires_in'] ?? 3600) - 60,
    ];
    /* Le jeton de rafraîchissement n'arrive qu'à la première autorisation :
       on ne l'écrase jamais par une valeur vide. */
    if (!empty($d['refresh_token'])) $valeurs['gdrive_refresh_token'] = $d['refresh_token'];

    gdrive_enregistrer($pdo, $valeurs);
    gdrive_identifier_compte($pdo);
    return true;
}

/* ----------------------------------------------------------------------------
   Jeton d'accès valide, renouvelé au besoin.
   ---------------------------------------------------------------------------- */
function gdrive_jeton_acces(PDO $pdo, ?string &$erreur = null): ?string {
    $r = gdrive_reglages($pdo);
    if ($r['refresh'] === '') { $erreur = 'Aucun compte Google connecté.'; return null; }
    if ($r['acces'] !== '' && $r['acces_fin'] > time()) return $r['acces'];

    $rep = gdrive_http(GDRIVE_JETON, [
        'methode' => 'POST',
        'entetes' => ['Content-Type: application/x-www-form-urlencoded'],
        'corps'   => http_build_query([
            'client_id'     => $r['client_id'],
            'client_secret' => $r['client_secret'],
            'refresh_token' => $r['refresh'],
            'grant_type'    => 'refresh_token',
        ]),
    ]);

    $d = json_decode($rep['corps'], true);
    if ($rep['code'] !== 200 || empty($d['access_token'])) {
        $erreur = $d['error_description'] ?? ($d['error'] ?? ($rep['erreur'] ?: 'Renouvellement refusé'));
        /* « invalid_grant » signifie que l'accès a été révoqué côté Google :
           inutile de réessayer, il faut se reconnecter. */
        if (($d['error'] ?? '') === 'invalid_grant') {
            gdrive_enregistrer($pdo, ['gdrive_refresh_token' => '', 'gdrive_acces' => '', 'gdrive_acces_fin' => 0]);
            $erreur = "L'autorisation a été retirée depuis votre compte Google. Reconnectez-vous.";
        }
        return null;
    }

    gdrive_enregistrer($pdo, [
        'gdrive_acces'     => $d['access_token'],
        'gdrive_acces_fin' => time() + (int)($d['expires_in'] ?? 3600) - 60,
    ]);
    return $d['access_token'];
}

/* Adresse du compte connecté, affichée pour lever toute ambiguïté. */
function gdrive_identifier_compte(PDO $pdo): void {
    $jeton = gdrive_jeton_acces($pdo);
    if (!$jeton) return;
    $rep = gdrive_http('https://www.googleapis.com/drive/v3/about?fields=user(emailAddress)',
                       ['entetes' => ['Authorization: Bearer ' . $jeton]]);
    $d = json_decode($rep['corps'], true);
    if (!empty($d['user']['emailAddress'])) {
        gdrive_enregistrer($pdo, ['gdrive_compte' => $d['user']['emailAddress']]);
    }
}

/* ----------------------------------------------------------------------------
   Dossier de destination : créé une fois, puis réutilisé.
   ---------------------------------------------------------------------------- */
function gdrive_dossier(PDO $pdo, ?string &$erreur = null): ?string {
    $r = gdrive_reglages($pdo);
    $jeton = gdrive_jeton_acces($pdo, $erreur);
    if (!$jeton) return null;

    /* Le dossier a pu être supprimé ou mis à la corbeille entre-temps. */
    if ($r['dossier_id'] !== '') {
        $rep = gdrive_http(GDRIVE_FICHIERS . '/' . rawurlencode($r['dossier_id']) . '?fields=id,trashed',
                           ['entetes' => ['Authorization: Bearer ' . $jeton]]);
        $d = json_decode($rep['corps'], true);
        if ($rep['code'] === 200 && empty($d['trashed'])) return $r['dossier_id'];
    }

    $rep = gdrive_http(GDRIVE_FICHIERS . '?fields=id', [
        'methode' => 'POST',
        'entetes' => ['Authorization: Bearer ' . $jeton, 'Content-Type: application/json'],
        'corps'   => json_encode([
            'name'     => $r['dossier_nom'] !== '' ? $r['dossier_nom'] : 'Coffre — documents',
            'mimeType' => 'application/vnd.google-apps.folder',
        ], JSON_UNESCAPED_UNICODE),
    ]);

    $d = json_decode($rep['corps'], true);
    if ($rep['code'] >= 300 || empty($d['id'])) {
        $erreur = $d['error']['message'] ?? 'Création du dossier refusée';
        return null;
    }
    gdrive_enregistrer($pdo, ['gdrive_dossier_id' => $d['id']]);
    return $d['id'];
}

/* ----------------------------------------------------------------------------
   Envoi d'un fichier.

   En deçà de 5 Mo, un envoi en une seule requête suffit. Au-delà, on passe par
   un envoi fractionné : une requête unique de 300 Mo dépasserait la mémoire et
   le temps d'exécution de la plupart des hébergements mutualisés.
   ---------------------------------------------------------------------------- */
function gdrive_envoyer(PDO $pdo, string $chemin, string $nom, ?string &$erreur = null): ?array {
    if (!is_file($chemin)) { $erreur = 'Fichier introuvable sur le serveur.'; return null; }

    $jeton = gdrive_jeton_acces($pdo, $erreur);
    if (!$jeton) return null;

    $dossier = gdrive_dossier($pdo, $erreur);
    if (!$dossier) return null;

    $taille = filesize($chemin);
    $type   = gdrive_type_mime($chemin, $nom);
    $meta   = ['name' => $nom, 'parents' => [$dossier]];

    if ($taille <= 5 * 1024 * 1024) {
        $limite = '-------gh' . bin2hex(random_bytes(8));
        $corps  = "--$limite\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n"
                . json_encode($meta, JSON_UNESCAPED_UNICODE) . "\r\n"
                . "--$limite\r\nContent-Type: $type\r\n\r\n"
                . file_get_contents($chemin) . "\r\n--$limite--";

        $rep = gdrive_http(GDRIVE_ENVOI . '?uploadType=multipart&fields=id,webViewLink,name', [
            'methode' => 'POST',
            'entetes' => ['Authorization: Bearer ' . $jeton,
                          'Content-Type: multipart/related; boundary=' . $limite],
            'corps'   => $corps,
            'delai'   => 120,
        ]);
    } else {
        /* Envoi fractionné : on ouvre une session, puis on pousse le fichier. */
        $rep = gdrive_http(GDRIVE_ENVOI . '?uploadType=resumable&fields=id,webViewLink,name', [
            'methode'      => 'POST',
            'entetes'      => ['Authorization: Bearer ' . $jeton,
                               'Content-Type: application/json; charset=UTF-8',
                               'X-Upload-Content-Type: ' . $type,
                               'X-Upload-Content-Length: ' . $taille],
            'corps'        => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'avec_entetes' => true,
        ]);

        if ($rep['code'] >= 300 || !preg_match('~^location:\s*(\S+)~mi', $rep['entetes'], $m)) {
            $erreur = 'Ouverture de la session d\'envoi refusée par Google.';
            return null;
        }

        $session = $m[1];
        $bloc    = 8 * 1024 * 1024;               // 8 Mo par morceau
        $f = fopen($chemin, 'rb');
        $pos = 0;
        while ($pos < $taille) {
            $morceau = fread($f, $bloc);
            $fin = $pos + strlen($morceau) - 1;
            $rep = gdrive_http($session, [
                'methode' => 'PUT',
                'entetes' => ['Content-Length: ' . strlen($morceau),
                              'Content-Range: bytes ' . $pos . '-' . $fin . '/' . $taille],
                'corps'   => $morceau,
                'delai'   => 300,
            ]);
            /* 308 = morceau accepté, la suite est attendue. */
            if ($rep['code'] !== 308 && $rep['code'] !== 200 && $rep['code'] !== 201) {
                fclose($f);
                $erreur = 'Transfert interrompu à ' . round($pos / 1048576) . ' Mo.';
                return null;
            }
            $pos = $fin + 1;
        }
        fclose($f);
    }

    $d = json_decode($rep['corps'], true);
    if ($rep['code'] >= 300 || empty($d['id'])) {
        $erreur = $d['error']['message'] ?? ('Envoi refusé (code ' . $rep['code'] . ')');
        return null;
    }
    return ['id' => $d['id'], 'lien' => $d['webViewLink'] ?? ('https://drive.google.com/file/d/' . $d['id'] . '/view')];
}

/* Supprime le fichier correspondant sur Drive. */
function gdrive_supprimer(PDO $pdo, string $idDrive): bool {
    $jeton = gdrive_jeton_acces($pdo);
    if (!$jeton || $idDrive === '') return false;
    $rep = gdrive_http(GDRIVE_FICHIERS . '/' . rawurlencode($idDrive),
                       ['methode' => 'DELETE', 'entetes' => ['Authorization: Bearer ' . $jeton]]);
    return $rep['code'] === 204 || $rep['code'] === 200 || $rep['code'] === 404;
}

/* Espace occupé et disponible sur le compte. */
function gdrive_espace(PDO $pdo): ?array {
    $jeton = gdrive_jeton_acces($pdo);
    if (!$jeton) return null;
    $rep = gdrive_http('https://www.googleapis.com/drive/v3/about?fields=storageQuota',
                       ['entetes' => ['Authorization: Bearer ' . $jeton]]);
    $d = json_decode($rep['corps'], true);
    if (empty($d['storageQuota'])) return null;
    $q = $d['storageQuota'];
    return ['utilise' => (float)($q['usage'] ?? 0),
            'total'   => isset($q['limit']) ? (float)$q['limit'] : null];
}

function gdrive_type_mime(string $chemin, string $nom): string {
    if (function_exists('mime_content_type')) {
        $t = @mime_content_type($chemin);
        if ($t) return $t;
    }
    $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
              'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
              'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel',
              'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
              'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
              'zip' => 'application/zip', 'txt' => 'text/plain', 'csv' => 'text/csv',
              'mp4' => 'video/mp4'];
    return $types[strtolower(pathinfo($nom, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
}
