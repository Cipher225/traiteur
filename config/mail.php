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
    $cles = ['smtp_hote','smtp_port','smtp_user','smtp_pass','smtp_secure','email','nom_entreprise','emails_actifs','annee_fondation'];
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
/* $images : images intégrées au message, sous la forme
   ['cid' => 'signature', 'chemin' => '/chemin/image.png'].
   Une image intégrée s'affiche toujours, alors qu'une image chargée depuis
   Internet est bloquée par défaut dans la plupart des messageries. */
function envoyer_email(PDO $pdo, string $dest, string $sujet, string $corpsHtml,
                       string $repondreA = '', array $pieces = [], ?string &$erreur = null,
                       array $images = []): bool {
    $dest = trim($dest);
    if ($dest === '' || !filter_var($dest, FILTER_VALIDATE_EMAIL)) {
        $erreur = "Adresse du destinataire invalide.";
        return false;
    }

    $cfg = email_config($pdo);

    // Les emails peuvent être désactivés globalement (réglage "emails_actifs")
    if (isset($cfg['emails_actifs']) && $cfg['emails_actifs'] === '0') {
        $erreur = "L'envoi d'emails est désactivé dans Paramètres → Emails.";
        return false;
    }

    $expediteurNom  = $cfg['nom_entreprise'] ?? 'Groupe Helisce';
    $expediteurMail = $cfg['email'] ?? ($cfg['smtp_user'] ?? 'no-reply@localhost');

    $avecLogo = false;
    foreach ($images as $img) { if (($img['cid'] ?? '') === 'logo-entreprise') $avecLogo = true; }
    $corps = email_gabarit($sujet, $corpsHtml, $expediteurNom, (string)($cfg['slogan'] ?? ''),
                           $avecLogo, (string)($cfg['annee_fondation'] ?? ''));

    // --- Mode SMTP si configuré ---
    if (!empty($cfg['smtp_hote']) && !empty($cfg['smtp_user'])) {
        return smtp_envoyer(
            $cfg['smtp_hote'],
            (int)($cfg['smtp_port'] ?? 587),
            $cfg['smtp_secure'] ?? 'tls',
            $cfg['smtp_user'],
            $cfg['smtp_pass'] ?? '',
            $expediteurMail, $expediteurNom,
            $dest, $sujet, $corps, $repondreA, $pieces, $erreur, $images
        );
    }

    /* --- Repli : mail() natif ---
       Sans serveur d'envoi configuré, on tente la fonction du système. Elle
       fonctionne rarement sur un hébergement mutualisé : mieux vaut le dire. */
    if (empty($cfg['smtp_hote'])) {
        $erreur = "Aucun serveur d'envoi configuré. Renseignez vos paramètres dans Paramètres → Emails.";
    } elseif (empty($cfg['smtp_user'])) {
        $erreur = "Identifiant de connexion manquant dans Paramètres → Emails.";
    }

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
function email_gabarit(string $sujet, string $contenu, string $entreprise, string $slogan = '',
                       bool $avecLogo = false, string $annee = ''): string {
    /* Année affichée au bas du message : l'année de création de l'entreprise,
       réglable dans les paramètres. Par défaut, l'année en cours. */
    $an = trim($annee) !== '' ? $annee : date('Y');
    /* Le logo est une image intégrée au message (cid) : il s'affiche même
       lorsque la messagerie bloque les images provenant d'Internet. */
    /* Le logo est posé sur une pastille blanche : beaucoup de logos contiennent
       du bleu foncé et disparaîtraient sur le bandeau marine. */
    $logo = $avecLogo
        ? '<table align="center" style="margin:0 auto 14px"><tr><td style="background:#ffffff;'
          . 'border-radius:12px;padding:10px 18px;text-align:center">'
          . '<img src="cid:logo-entreprise" alt="' . htmlspecialchars($entreprise) . '" '
          . 'style="max-height:58px;max-width:190px;display:block"></td></tr></table>'
        : '';
    return '<!DOCTYPE html><html><body style="margin:0;background:#f4f6fb;font-family:Arial,sans-serif">
      <div style="max-width:600px;margin:0 auto;background:#fff">
        <div style="background:linear-gradient(135deg,#0a1f44,#020714);padding:26px 30px;text-align:center">
          ' . $logo . '
          <div style="color:#fff;font-size:20px;font-weight:bold;letter-spacing:1px">' . htmlspecialchars($entreprise) . '</div>
          ' . ($slogan !== '' ? '<div style="color:#d4a526;font-size:12px;margin-top:4px;letter-spacing:2px;text-transform:uppercase">' . htmlspecialchars($slogan) . '</div>' : '') . '
        </div>
        <div style="height:3px;background:linear-gradient(90deg,#d4a526,#b8870f)"></div>
        <div style="padding:30px;color:#1a2744;font-size:15px;line-height:1.6">' . $contenu . '</div>
        <div style="padding:20px 30px;background:#0a1f44;color:#a9b7d0;font-size:12px;text-align:center">
          © ' . $an . ' ' . htmlspecialchars($entreprise) . '
        </div>
      </div></body></html>';
}

/* ----------------------------------------------------------------------------
   Envoi SMTP minimal (AUTH LOGIN), sans bibliothèque externe.
   Gère STARTTLS (port 587) et SSL direct (port 465).
   ---------------------------------------------------------------------------- */
function smtp_envoyer(string $hote, int $port, string $secure, string $user, string $pass,
                      string $deMail, string $deNom, string $dest, string $sujet, string $corps,
                      string $repondreA = '', array $pieces = [], ?string &$erreur = null,
                      array $images = []): bool {
    $timeout = 15;
    $transport = ($secure === 'ssl' || $port === 465) ? 'ssl://' : '';
    $fp = @fsockopen($transport . $hote, $port, $errno, $errstr, $timeout);
    if (!$fp) {
        $erreur = "Impossible de joindre $hote sur le port $port. "
                . ($errstr ? "Réponse : $errstr. " : '')
                . "Vérifiez le nom du serveur et le port, ou demandez à votre hébergeur "
                . "si les connexions sortantes vers ce port sont autorisées.";
        return false;
    }
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

    if (!$attendre(220)) { $erreur = "Le serveur $hote n'a pas répondu correctement à la connexion."; fclose($fp); return false; }
    $ecrire('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost')); $lire();

    // STARTTLS si demandé (port 587)
    if ($transport === '' && $secure !== 'none') {
        $ecrire('STARTTLS');
        if (!$attendre(220)) { $erreur = "Le serveur a refusé de passer en connexion sécurisée (STARTTLS). Essayez le port 465 en SSL."; fclose($fp); return false; }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { $erreur = "La connexion sécurisée n'a pas pu être établie."; fclose($fp); return false; }
        $ecrire('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost')); $lire();
    }

    // Authentification
    $ecrire('AUTH LOGIN');
    if (!$attendre(334)) { $erreur = "Le serveur a refusé la méthode d'authentification."; fclose($fp); return false; }
    $ecrire(base64_encode($user));
    if (!$attendre(334)) { $erreur = "Identifiant refusé : « $user »."; fclose($fp); return false; }
    $ecrire(base64_encode($pass));
    if (!$attendre(235)) {
        $erreur = "Identifiant ou mot de passe refusé par $hote. "
                . (stripos($hote, 'gmail') !== false
                    ? "Avec Gmail, votre mot de passe habituel ne fonctionne PAS : il faut créer un « mot de passe d'application » de 16 caractères (Compte Google → Sécurité → Validation en deux étapes, puis Mots de passe des applications)."
                    : "Vérifiez l'identifiant et le mot de passe.");
        fclose($fp); return false;
    }

    // Enveloppe
    $ecrire('MAIL FROM:<' . $deMail . '>');
    if (!$attendre(250)) {
        $erreur = "L'adresse d'expédition « $deMail » a été refusée. "
                . "Chez la plupart des fournisseurs, elle doit être identique à l'identifiant de connexion.";
        fclose($fp); return false;
    }
    $ecrire('RCPT TO:<' . $dest . '>');
    if (!$attendre([250,251])) { $erreur = "L'adresse du destinataire « $dest » a été refusée."; fclose($fp); return false; }
    $ecrire('DATA'); if (!$attendre(354)) { $erreur = "Le serveur a refusé de recevoir le message."; fclose($fp); return false; }

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
    /* Le corps du message : du HTML, éventuellement accompagné d'images
       intégrées (signature, logo). L'ensemble forme un bloc « related ». */
    $bloc = function () use ($corps, $images) {
        if (!$images) {
            return "Content-Type: text/html; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: 8bit\r\n\r\n" . $corps . "\r\n";
        }
        $lim = '=_rel_' . bin2hex(random_bytes(8));
        $o  = 'Content-Type: multipart/related; boundary="' . $lim . '"' . "\r\n\r\n";
        $o .= "--$lim\r\nContent-Type: text/html; charset=UTF-8\r\n";
        $o .= "Content-Transfer-Encoding: 8bit\r\n\r\n" . $corps . "\r\n";
        foreach ($images as $img) {
            if (empty($img['chemin']) || !is_file($img['chemin'])) continue;
            $type = function_exists('mime_content_type')
                  ? (mime_content_type($img['chemin']) ?: 'image/png') : 'image/png';
            $o .= "--$lim\r\n";
            $o .= 'Content-Type: ' . $type . "\r\n";
            $o .= "Content-Transfer-Encoding: base64\r\n";
            $o .= 'Content-ID: <' . $img['cid'] . '>' . "\r\n";
            $o .= 'Content-Disposition: inline; filename="' . $img['cid'] . '.png"' . "\r\n\r\n";
            $o .= chunk_split(base64_encode(file_get_contents($img['chemin']))) . "\r\n";
        }
        return $o . "--$lim--\r\n";
    };

    if ($pieces) {
        $limite = '=_' . bin2hex(random_bytes(12));
        $entete .= 'Content-Type: multipart/mixed; boundary="' . $limite . '"' . "\r\n";
        $contenu  = "--$limite\r\n";
        $contenu .= $bloc();
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
    } elseif ($images) {
        $limite = '=_rel_' . bin2hex(random_bytes(8));
        $entete .= 'Content-Type: multipart/related; boundary="' . $limite . '"' . "\r\n";
        $contenu  = "--$limite\r\nContent-Type: text/html; charset=UTF-8\r\n";
        $contenu .= "Content-Transfer-Encoding: 8bit\r\n\r\n" . $corps . "\r\n";
        foreach ($images as $img) {
            if (empty($img['chemin']) || !is_file($img['chemin'])) continue;
            $contenu .= "--$limite\r\n";
            $contenu .= "Content-Type: image/png\r\n";
            $contenu .= "Content-Transfer-Encoding: base64\r\n";
            $contenu .= 'Content-ID: <' . $img['cid'] . '>' . "\r\n";
            $contenu .= 'Content-Disposition: inline; filename="' . $img['cid'] . '.png"' . "\r\n\r\n";
            $contenu .= chunk_split(base64_encode(file_get_contents($img['chemin']))) . "\r\n";
        }
        $contenu .= "--$limite--";
    } else {
        $entete .= "Content-Type: text/html; charset=UTF-8\r\n";
        $contenu = $corps;
    }

    // Échapper les points en début de ligne (règle SMTP)
    $corpsSmtp = preg_replace('/^\./m', '..', $contenu);
    $ecrire($entete . "\r\n" . $corpsSmtp . "\r\n.");
    if (!$attendre(250)) { $erreur = "Le message a été refusé au moment de la remise."; fclose($fp); return false; }

    $ecrire('QUIT'); fclose($fp);
    return true;
}

} // fin if function_exists
