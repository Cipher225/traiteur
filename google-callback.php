<?php
/* Retour de Google : échange le code contre un jeton, récupère le profil,
   crée le compte s'il n'existe pas, puis connecte l'utilisateur. */
require __DIR__ . '/config/db.php';

function base_url(): string {
    global $pdo; $s = get_settings($pdo); if (!empty($s['site_url'])) return rtrim($s['site_url'], '/');
    if (defined('SITE_URL') && SITE_URL !== '') return rtrim(SITE_URL, '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    return $scheme . '://' . $host . $dir;
}
function stop(string $msg): void {
    die('<div style="font-family:sans-serif;max-width:520px;margin:80px auto;text-align:center">'
      . '<h2 style="color:#b8302a">Connexion Google impossible</h2><p>' . e($msg) . '</p>'
      . '<a href="login.php" style="color:#0a1f44">← Retour à la connexion</a></div>');
}

/* Requête HTTP robuste : utilise curl si disponible, sinon file_get_contents */
function http_post(string $url, array $fields): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>http_build_query($fields), CURLOPT_TIMEOUT=>15]);
        $r = curl_exec($ch); curl_close($ch);
        return $r === false ? null : $r;
    }
    $ctx = stream_context_create(['http'=>['method'=>'POST',
        'header'=>'Content-Type: application/x-www-form-urlencoded',
        'content'=>http_build_query($fields), 'timeout'=>15]]);
    $r = @file_get_contents($url, false, $ctx);
    return $r === false ? null : $r;
}
function http_get_auth(string $url, string $token): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token], CURLOPT_TIMEOUT=>15]);
        $r = curl_exec($ch); curl_close($ch);
        return $r === false ? null : $r;
    }
    $ctx = stream_context_create(['http'=>['header'=>'Authorization: Bearer '.$token, 'timeout'=>15]]);
    $r = @file_get_contents($url, false, $ctx);
    return $r === false ? null : $r;
}

$G_ID = google_client_id($pdo); $G_SECRET = google_client_secret($pdo);
if ($G_ID === '') stop("La connexion Google n'est pas configuree.");
if (isset($_GET['error'])) stop('Autorisation refusée.');
if (empty($_GET['code']) || empty($_GET['state'])) stop('Réponse Google invalide.');
if (($_SESSION['google_state'] ?? '') !== $_GET['state']) stop('Jeton de sécurité invalide, veuillez réessayer.');

$redirect = base_url() . '/google-callback.php';

/* 1. Échange du code contre un jeton d'accès */
$resp = http_post('https://oauth2.googleapis.com/token', [
    'code'          => $_GET['code'],
    'client_id'     => $G_ID,
    'client_secret' => $G_SECRET,
    'redirect_uri'  => $redirect,
    'grant_type'    => 'authorization_code',
]);
if ($resp === null) stop('Impossible de contacter Google.');
$data = json_decode($resp, true);
if (empty($data['access_token'])) stop('Google n\'a pas renvoyé de jeton d\'accès.');

/* 2. Récupération du profil */
$ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $data['access_token']],
    CURLOPT_TIMEOUT => 15,
]);
$prof = json_decode(curl_exec($ch), true);
curl_close($ch);
if (empty($prof['id']) || empty($prof['email'])) stop('Profil Google incomplet.');

$googleId = $prof['id'];
$email    = $prof['email'];
$nom      = $prof['name'] ?? $email;
$role     = ($_SESSION['google_role'] ?? 'client') === 'employe' ? 'employe' : 'client';

/* 3. Compte existant (par google_id ou email) ? sinon on le crée */
$st = $pdo->prepare("SELECT * FROM users WHERE google_id=? OR email=? LIMIT 1");
$st->execute([$googleId, $email]);
$user = $st->fetch();

if ($user) {
    /* Lier le google_id si le compte a été créé autrement */
    if (empty($user['google_id'])) {
        $pdo->prepare("UPDATE users SET google_id=? WHERE id=?")->execute([$googleId, $user['id']]);
    }
} else {
    /* Nouveau compte : username unique dérivé de l'email */
    $baseUser = preg_replace('/[^a-z0-9]/', '', strtolower(explode('@', $email)[0])) ?: 'user';
    $username = $baseUser; $i = 1;
    while ($pdo->query("SELECT 1 FROM users WHERE username=" . $pdo->quote($username))->fetchColumn()) {
        $username = $baseUser . (++$i);
    }
    /* Un client Google a besoin d'une fiche client liée */
    $clientId = null;
    if ($role === 'client') {
        $pdo->prepare("INSERT INTO clients (nom, email) VALUES (?,?)")->execute([$nom, $email]);
        $clientId = (int)$pdo->lastInsertId();
    }
    $pdo->prepare("INSERT INTO users (username, password, google_id, nom, email, role, client_id, actif, profil_complet)
                   VALUES (?,?,?,?,?,?,?,1,0)")
        ->execute([$username, '', $googleId, $nom, $email, $role, $clientId]);
    $user = $pdo->query("SELECT * FROM users WHERE id=" . (int)$pdo->lastInsertId())->fetch();
}

if ((int)$user['actif'] !== 1) stop('Votre compte est désactivé. Contactez l\'administrateur.');

/* 4. Ouverture de session */
$_SESSION['admin_id']    = (int)$user['id'];
$_SESSION['admin_nom']   = $user['nom'];
$_SESSION['admin_role']  = $user['role'];
$_SESSION['admin_perms'] = $user['permissions'] ? json_decode($user['permissions'], true) : [];
$sid = session_id();
$pdo->prepare("UPDATE users SET session_id=?, last_activity=NOW() WHERE id=?")->execute([$sid, (int)$user['id']]);
$_SESSION['sid_check'] = $sid;
$_SESSION['derniere_activite'] = time();
unset($_SESSION['google_state'], $_SESSION['google_role']);

/* 5. Redirection : profil à compléter en priorité */
$profilIncomplet = (int)($user['profil_complet'] ?? 1) === 0;
if ($user['role'] === 'client') {
    header('Location: espace-client/' . ($profilIncomplet ? 'profil.php?bienvenue=1' : 'index.php'));
} else {
    header('Location: admin/' . ($profilIncomplet ? 'profil.php?bienvenue=1' : 'index.php'));
}
exit;
