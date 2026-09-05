<?php
/* ============================================================================
   ENVOI D'EMAILS — helper simple et autonome (sans dépendance externe)
   ----------------------------------------------------------------------------
   Deux modes, choisis automatiquement selon la configuration :

   • SMTP (recommandé en ligne) : si les réglages SMTP sont renseignés dans
     Paramètres (serveur, port, identifiant, mot de passe), l'email part via
     une connexion SMTP authentifiée — fiable chez tous les hébergeurs.

   • mail() natif : repli si aucun SMTP n'est configuré (fonctionne surtout
     en local ou si l'hébergeur autorise la fonction mail()).

   Utilisation :
     envoyer_email($pdo, $destinataire, $sujet, $corps_html);
   Retourne true si l'email est parti, false sinon (jamais d'erreur fatale).
   ============================================================================ */

if (!function_exists('envoyer_email')) {

function email_config(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cles = ['smtp_hote','smtp_port','smtp_user','smtp_pass','smtp_secure','email','nom_entreprise','emails_actifs'];
    $in = implode(',', array_fill(0, count($cles), '?'));
    $st = $pdo->prepare("SELECT cle, valeur FROM settings WHERE cle IN ($in)");
    $st->execute($cles);
    $cfg = [];
    foreach ($st->fetchAll() as $r) $cfg[$r['cle']] = $r['valeur'];
    $cache = $cfg;
    return $cfg;
}

/* $pieces : liste de fichiers à joindre, chacun sous la forme
   ['chemin' => '/chemin/vers/fichier.pdf', 'nom' => 'Facture.pdf'] */
function envoyer_email(PDO $pdo, string $dest, string $sujet, string $corpsHtml,
                       string $repondreA = '', array $pieces = []): bool {
    $dest = trim($dest);
    if ($dest === '' || !filter_var($dest, FILTER_VALIDATE_EMAIL)) return false;

    $cfg = email_config($pdo);

    // Les emails peuvent être désactivés globalement (réglage "emails_actifs")
    if (isset($cfg['emails_actifs']) && $cfg['emails_actifs'] === '0') return false;

    $expediteurNom  = $cfg['nom_entreprise'] ?? 'Groupe Helisce';
    $expediteurMail = $cfg['email'] ?? ($cfg['smtp_user'] ?? 'no-reply@localhost');

    $corps = email_gabarit($sujet, $corpsHtml, $expediteurNom, (string)($cfg['slogan'] ?? ''));

    // --- Mode SMTP si configuré ---
    if (!empty($cfg['smtp_hote']) && !empty($cfg['smtp_user'])) {
        return smtp_envoyer(
            $cfg['smtp_hote'],
            (int)($cfg['smtp_port'] ?? 587),
            $cfg['smtp_secure'] ?? 'tls',
            $cfg['smtp_user'],
            $cfg['smtp_pass'] ?? '',
            $expediteurMail, $expediteurNom,
            $dest, $sujet, $corps, $repondreA, $pieces
        );
    }

    // --- Repli : mail() natif ---
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= 'From: ' . email_encode($expediteurNom) . ' <' . $expediteurMail . ">\r\n";
    if ($repondreA) $headers .= 'Reply-To: ' . $repondreA . "\r\n";

    if ($pieces) {
        $limite = '=_' . bin2hex(random_bytes(12));
        $headers .= 'Content-Type: multipart/mixed; boundary="' . $limite . '"' . "\r\n";
        $contenu  = "--$limite\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n" . $corps . "\r\n";
        foreach ($pieces as $p) {
            if (empty($p['chemin']) || !is_file($p['chemin'])) continue;
            $nom = $p['nom'] ?? basename($p['chemin']);
            $contenu .= "--$limite\r\n";
            $contenu .= 'Content-Type: application/octet-stream; name="' . $nom . '"' . "\r\n";
            $contenu .= "Content-Transfer-Encoding: base64\r\n";
            $contenu .= 'Content-Disposition: attachment; filename="' . $nom . '"' . "\r\n\r\n";
            $contenu .= chunk_split(base64_encode(file_get_contents($p['chemin']))) . "\r\n";
        }
        $contenu .= "--$limite--";
    } else {
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $contenu = $corps;
    }
    return @mail($dest, email_encode($sujet), $contenu, $headers);
}

/* Encodage MIME d'un texte (accents dans le sujet / le nom) */
function email_encode(string $t): string {
    return '=?UTF-8?B?' . base64_encode($t) . '?=';
}

/* Gabarit HTML navy & or de l'entreprise */
function email_gabarit(string $sujet, string $contenu, string $entreprise, string $slogan = ''): string {
    $an = date('Y');
    return '<!DOCTYPE html><html><body style="margin:0;background:#f4f6fb;font-family:Arial,sans-serif">
      <div style="max-width:600px;margin:0 auto;background:#fff">
        <div style="background:linear-gradient(135deg,#0a1f44,#020714);padding:26px 30px;text-align:center">
          <div style="color:#fff;font-size:20px;font-weight:bold;letter-spacing:1px">' . htmlspecialchars($entreprise) . '</div>
          ' . ($slogan !== '' ? '<div style="color:#d4a526;font-size:12px;margin-top:4px;letter-spacing:2px;text-transform:uppercase">' . htmlspecialchars($slogan) . '</div>' : '') . '
        </div>
        <div style="height:3px;background:linear-gradient(90deg,#d4a526,#b8870f)"></div>
        <div style="padding:30px;color:#1a2744;font-size:15px;line-height:1.6">' . $contenu . '</div>
        <div style="padding:20px 30px;background:#0a1f44;color:#a9b7d0;font-size:12px;text-align:center">
          © ' . $an . ' ' . htmlspecialchars($entreprise) . ' — Cet email vous a été envoyé automatiquement.
        </div>
      </div></body></html>';
}

/* ----------------------------------------------------------------------------
   Envoi SMTP minimal (AUTH LOGIN), sans bibliothèque externe.
   Gère STARTTLS (port 587) et SSL direct (port 465).
   ---------------------------------------------------------------------------- */
function smtp_envoyer(string $hote, int $port, string $secure, string $user, string $pass,
                      string $deMail, string $deNom, string $dest, string $sujet, string $corps,
                      string $repondreA = '', array $pieces = []): bool {
    $timeout = 15;
    $transport = ($secure === 'ssl' || $port === 465) ? 'ssl://' : '';
    $fp = @fsockopen($transport . $hote, $port, $errno, $errstr, $timeout);
    if (!$fp) return false;
    stream_set_timeout($fp, $timeout);

    $lire = function() use ($fp) {
        $data = '';
        while ($ligne = fgets($fp, 515)) { $data .= $ligne; if (isset($ligne[3]) && $ligne[3] === ' ') break; }
        return $data;
    };
    $ecrire = function($cmd) use ($fp) { fputs($fp, $cmd . "\r\n"); };
    $attendre = function($codes) use ($lire) {
        $r = $lire(); $code = (int)substr($r, 0, 3);
        return in_array($code, (array)$codes, true);
    };

    if (!$attendre(220)) { fclose($fp); return false; }
    $ecrire('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost')); $lire();

    // STARTTLS si demandé (port 587)
    if ($transport === '' && $secure !== 'none') {
        $ecrire('STARTTLS');
        if (!$attendre(220)) { fclose($fp); return false; }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return false; }
        $ecrire('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost')); $lire();
    }

    // Authentification
    $ecrire('AUTH LOGIN'); if (!$attendre(334)) { fclose($fp); return false; }
    $ecrire(base64_encode($user)); if (!$attendre(334)) { fclose($fp); return false; }
    $ecrire(base64_encode($pass)); if (!$attendre(235)) { fclose($fp); return false; }

    // Enveloppe
    $ecrire('MAIL FROM:<' . $deMail . '>'); if (!$attendre(250)) { fclose($fp); return false; }
    $ecrire('RCPT TO:<' . $dest . '>'); if (!$attendre([250,251])) { fclose($fp); return false; }
    $ecrire('DATA'); if (!$attendre(354)) { fclose($fp); return false; }

    // En-têtes + corps
    $entete  = 'From: ' . email_encode($deNom) . ' <' . $deMail . ">\r\n";
    $entete .= 'To: <' . $dest . ">\r\n";
    if ($repondreA) $entete .= 'Reply-To: ' . $repondreA . "\r\n";
    $entete .= 'Subject: ' . email_encode($sujet) . "\r\n";
    $entete .= "MIME-Version: 1.0\r\n";
    $entete .= 'Date: ' . date('r') . "\r\n";

    /* Avec des fichiers joints, le message devient « multipart » : une partie
       pour le texte, une partie par fichier. Sans fichier, on garde un message
       HTML simple, plus léger. */
    if ($pieces) {
        $limite = '=_' . bin2hex(random_bytes(12));
        $entete .= 'Content-Type: multipart/mixed; boundary="' . $limite . '"' . "\r\n";
        $contenu  = "--$limite\r\n";
        $contenu .= "Content-Type: text/html; charset=UTF-8\r\n";
        $contenu .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $contenu .= $corps . "\r\n";
        foreach ($pieces as $p) {
            if (empty($p['chemin']) || !is_file($p['chemin'])) continue;
            $nom  = $p['nom'] ?? basename($p['chemin']);
            $type = $p['type'] ?? (function_exists('mime_content_type')
                        ? (mime_content_type($p['chemin']) ?: 'application/octet-stream')
                        : 'application/octet-stream');
            $contenu .= "--$limite\r\n";
            $contenu .= 'Content-Type: ' . $type . '; name="' . $nom . '"' . "\r\n";
            $contenu .= "Content-Transfer-Encoding: base64\r\n";
            $contenu .= 'Content-Disposition: attachment; filename="' . $nom . '"' . "\r\n\r\n";
            $contenu .= chunk_split(base64_encode(file_get_contents($p['chemin']))) . "\r\n";
        }
        $contenu .= "--$limite--";
    } else {
        $entete .= "Content-Type: text/html; charset=UTF-8\r\n";
        $contenu = $corps;
    }

    // Échapper les points en début de ligne (règle SMTP)
    $corpsSmtp = preg_replace('/^\./m', '..', $contenu);
    $ecrire($entete . "\r\n" . $corpsSmtp . "\r\n.");
    if (!$attendre(250)) { fclose($fp); return false; }

    $ecrire('QUIT'); fclose($fp);
    return true;
}

} // fin if function_exists
