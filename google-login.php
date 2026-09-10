<?php
/* Démarre la connexion Google : redirige l'utilisateur vers l'écran de consentement Google. */
require __DIR__ . '/config/db.php';

$G_ID = google_client_id($pdo);
if ($G_ID === '') {
    die("La connexion Google n'est pas configurée. Renseignez les identifiants Google dans Paramètres.");
}

/* Détermine l'URL de redirection (doit correspondre à celle déclarée dans la console Google) */
/* Adresse de retour de Google.

   On part du réglage du site, mais on conserve le domaine par lequel
   l'utilisateur navigue réellement : « site.com » et « www.site.com » sont
   deux domaines distincts pour un navigateur, et basculer de l'un à l'autre
   au milieu de la connexion faisait perdre la session.

   Google exige que cette adresse figure parmi les adresses autorisées :
   inscrivez-y les deux formes, avec et sans « www ». */
function base_url(): string {
    global $pdo;
    $reference = '';
    try { $s = get_settings($pdo); $reference = (string)($s['site_url'] ?? ''); } catch (Throwable $e) {}
    if ($reference === '' && defined('SITE_URL')) $reference = (string)SITE_URL;
    $reference = rtrim(trim($reference), '/');

    $hoteReel = $_SERVER['HTTP_HOST'] ?? '';
    if ($reference !== '' && $hoteReel !== '') {
        $p = parse_url($reference);
        /* Même site, écriture différente du domaine : on garde celle du
           navigateur pour ne pas casser la session. */
        if (!empty($p['host'])
            && ltrim($p['host'], 'w.') === ltrim($hoteReel, 'w.')
            && $p['host'] !== $hoteReel) {
            $reference = ($p['scheme'] ?? 'https') . '://' . $hoteReel
                       . rtrim($p['path'] ?? '', '/');
        }
        return $reference;
    }
    if ($reference !== '') return $reference;

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    return $scheme . '://' . ($hoteReel ?: 'localhost') . $dir;
}

$redirect = base_url() . '/google-callback.php';

/* Le jeton porte le rôle demandé et se vérifie par signature : il survit à un
   changement de domaine entre l'aller et le retour. On le garde aussi en
   session, ce qui n'coûte rien et sert de seconde vérification quand elle
   est disponible. */
$role  = ($_GET['role'] ?? 'client') === 'employe' ? 'employe' : 'client';
$state = google_state_creer($pdo, $role);
$_SESSION['google_state'] = $state;
$_SESSION['google_role']  = $role;

$params = [
    'client_id'     => $G_ID,
    'redirect_uri'  => $redirect,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'access_type'   => 'online',
    'state'         => $state,
    'prompt'        => 'select_account',
];
header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
exit;
