<?php
/* Démarre la connexion Google : redirige l'utilisateur vers l'écran de consentement Google. */
require __DIR__ . '/config/db.php';

$G_ID = google_client_id($pdo);
if ($G_ID === '') {
    die("La connexion Google n'est pas configurée. Renseignez les identifiants Google dans Paramètres.");
}

/* Détermine l'URL de redirection (doit correspondre à celle déclarée dans la console Google) */
function base_url(): string {
    global $pdo; $s = get_settings($pdo); if (!empty($s['site_url'])) return rtrim($s['site_url'], '/');
    if (defined('SITE_URL') && SITE_URL !== '') return rtrim(SITE_URL, '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    return $scheme . '://' . $host . $dir;
}

$redirect = base_url() . '/google-callback.php';
$_SESSION['google_state'] = bin2hex(random_bytes(16));
$_SESSION['google_role']  = ($_GET['role'] ?? 'client') === 'employe' ? 'employe' : 'client';

$params = [
    'client_id'     => $G_ID,
    'redirect_uri'  => $redirect,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'access_type'   => 'online',
    'state'         => $_SESSION['google_state'],
    'prompt'        => 'select_account',
];
header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
exit;
