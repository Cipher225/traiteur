<?php
/* ============================================================================
   BADGES & CARTES PROFESSIONNELLES — logique métier
   ============================================================================ */

/* Génère un matricule selon le format paramétré par l'admin.
   Jetons disponibles : {PREFIXE} {ANNEE} {MOIS} {SEQ3} {SEQ4} {SEQ5} */
/* Convertit un numéro (1, 2, 3…) en suffixe de 2 lettres synchronisé :
   1 → AA, 2 → AB, 3 → AC … 26 → AZ, 27 → BA, 28 → BB …
   La 1re lettre avance tous les 26, la 2e boucle de A à Z. */
function badge_lettres_suffixe(int $n): string {
    if ($n < 1) $n = 1;
    $i = $n - 1;               // base 0
    $deuxieme = $i % 26;       // 0..25 → A..Z
    $premiere = intdiv($i, 26); // avance tous les 26
    // Si on dépasse ZZ (676), on repart proprement en bouclant la 1re lettre
    $premiere = $premiere % 26;
    return chr(65 + $premiere) . chr(65 + $deuxieme);
}

/* Génère le matricule / identifiant selon le format Groupe Helisce.
   Employé : GH01-2608-AA  (01 = n° employé, 2608 = année+mois, AA = lettres liées au n°)
   Externe : GH-EX01-2608  (EX01 = n° externe, pas de suffixe lettres)
   La numérotation ne se réinitialise pas (elle est continue). */
function badge_generer_matricule(PDO $pdo, array $settings, string $typePorteur = 'employe'): string {
    $prefixe = trim((string)($settings['badge_prefixe'] ?? 'GH')) ?: 'GH';
    $aa = date('y'); // année sur 2 chiffres (26)
    $mm = date('m'); // mois sur 2 chiffres (08)
    $ym = $aa . $mm; // 2608

    if ($typePorteur === 'externe') {
        // Compter les externes déjà émis pour continuer la séquence
        $st = $pdo->prepare("SELECT COUNT(*) FROM badges WHERE type_porteur='externe' AND matricule LIKE ?");
        $st->execute([$prefixe . '-EX%']);
        $seq = (int)$st->fetchColumn() + 1;
        for ($essai = 0; $essai < 10000; $essai++) {
            $num = str_pad((string)$seq, 2, '0', STR_PAD_LEFT);
            $mat = $prefixe . '-EX' . $num . '-' . $ym;
            $chk = $pdo->prepare("SELECT 1 FROM badges WHERE matricule=?
                              UNION SELECT 1 FROM employes WHERE matricule=? LIMIT 1");
            $chk->execute([$mat, $mat]);
            if (!$chk->fetchColumn()) return $mat;
            $seq++;
        }
        return $prefixe . '-EX' . substr(uniqid(), -4) . '-' . $ym;
    }

    /* Employé : la séquence doit tenir compte des matricules DÉJÀ ATTRIBUÉS aux
       fiches du personnel, et pas seulement des badges édités. Sans cela, deux
       fiches créées avant tout badge recevraient le même numéro.
       Le numéro 01 est réservé à la direction. */
    $seqEmp = 0;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM employes WHERE matricule LIKE ?");
        $st->execute([$prefixe . '%']);
        $seqEmp = (int)$st->fetchColumn();
    } catch (Throwable $e) {}

    $st = $pdo->prepare("SELECT COUNT(*) FROM badges WHERE type_porteur='employe' AND matricule LIKE ?");
    $st->execute([$prefixe . '%']);
    $seqBadge = (int)$st->fetchColumn();

    $seq = max($seqEmp, $seqBadge) + 1;
    if ($typePorteur === 'direction') {
        $seq = 1;                       // la direction porte le premier matricule
    } elseif ($seq < 2) {
        $seq = 2;                       // 01 reste réservé à la direction
    }
    for ($essai = 0; $essai < 10000; $essai++) {
        $num = str_pad((string)$seq, 2, '0', STR_PAD_LEFT);
        $lettres = badge_lettres_suffixe($seq);
        $mat = $prefixe . $num . '-' . $ym . '-' . $lettres;
        $chk = $pdo->prepare("SELECT 1 FROM badges WHERE matricule=?
                              UNION SELECT 1 FROM employes WHERE matricule=? LIMIT 1");
        $chk->execute([$mat, $mat]);
        if (!$chk->fetchColumn()) return $mat;
        $seq++;
    }
    return $prefixe . substr(uniqid(), -4) . '-' . $ym;
}

/* Aperçu du format (pour l'écran de paramétrage), sans toucher la base */
function badge_apercu_format(string $format, string $prefixe): string {
    if ($format === '') $format = '{PREFIXE}-{ANNEE}-{SEQ4}';
    return strtr($format, [
        '{PREFIXE}' => $prefixe ?: 'HEL',
        '{ANNEE}'   => date('Y'),
        '{MOIS}'    => date('m'),
        '{SEQ3}'    => '001',
        '{SEQ4}'    => '0001',
        '{SEQ5}'    => '00001',
    ]);
}

/* URL de vérification en ligne encodée dans le QR.
   Le QR ne contient PAS les données personnelles : il pointe vers une page
   qui confirme l'authenticité et protège les coordonnées (badge perdu). */
function badge_url_verif(array $b, array $settings): string {
    $base = defined('SITE_URL') && SITE_URL !== '' ? rtrim(SITE_URL, '/') : ($settings['site_url'] ?? '');
    $base = rtrim((string)$base, '/');
    if ($base === '') $base = 'https://VOTRE-SITE';   // repli si non configuré
    return $base . '/verifier-badge.php?t=' . rawurlencode($b['token'] ?? '');
}

/* Contenu du QR : vCard (enregistrable dans le téléphone) contenant les infos.
   Compatible avec l'app Contacts de tous les smartphones. */
function badge_vcard(array $b, array $settings): string {
    $org = $b['organisation'] ?: ($settings['nom_entreprise'] ?? '');
    $lignes = [
        'BEGIN:VCARD',
        'VERSION:3.0',
        'N:' . $b['nom'],
        'FN:' . $b['nom'],
    ];
    if (!empty($b['poste']))     $lignes[] = 'TITLE:' . $b['poste'];
    if (!empty($org))            $lignes[] = 'ORG:' . $org;
    if (!empty($b['telephone'])) $lignes[] = 'TEL;TYPE=CELL:' . $b['telephone'];
    if (!empty($b['email']))     $lignes[] = 'EMAIL:' . $b['email'];
    /* Le matricule voyage en note pour rester lisible en texte brut */
    $lignes[] = 'NOTE:Matricule ' . $b['matricule']
              . (!empty($b['departement']) ? ' - ' . $b['departement'] : '');
    $lignes[] = 'END:VCARD';
    return implode("\n", $lignes);
}

/* Statut effectif du badge (tient compte de l'expiration) */
function badge_statut(array $b): string {
    if ($b['statut'] === 'suspendu') return 'suspendu';
    if (!empty($b['date_expiration']) && strtotime($b['date_expiration']) < strtotime('today')) return 'expire';
    return $b['statut'];
}

function badge_statut_label(string $s): array {
    return [
        'actif'    => ['Actif',    'badge-teal'],
        'suspendu' => ['Suspendu', 'badge-danger'],
        'expire'   => ['Expiré',   'badge'],
    ][$s] ?? ['—', 'badge'];
}

/* Initiales pour l'avatar de secours (quand pas de photo) */
function badge_initiales(string $nom): string {
    $mots = preg_split('/\s+/', trim($nom));
    $i = '';
    foreach ($mots as $m) { if ($m !== '') $i .= mb_strtoupper(mb_substr($m, 0, 1)); if (mb_strlen($i) >= 2) break; }
    return $i ?: '?';
}
