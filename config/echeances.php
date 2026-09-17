<?php
/* ============================================================================
   ÉCHÉANCES RÉCURRENTES

   Certaines obligations reviennent à date fixe : déclarations, cotisations,
   renouvellements. Les oublier coûte des pénalités, et on s'en aperçoit
   toujours trop tard.

   Ce module calcule, pour chaque obligation, la prochaine date attendue et
   l'état dans lequel elle se trouve. Il ne fait AUCUNE hypothèse sur les
   dates légales : c'est vous qui les renseignez, parce qu'elles changent
   d'un régime fiscal à l'autre et d'une année à l'autre.
   ============================================================================ */

/* Les cinq états possibles, du plus urgent au plus serein. */
const ECH_RETARD   = 'retard';      // la date est passée, rien n'a été fait
const ECH_AUJOURD  = 'aujourdhui';  // c'est aujourd'hui
const ECH_PROCHE   = 'proche';      // dans le délai de préavis
const ECH_A_VENIR  = 'a_venir';     // plus loin
const ECH_FAIT     = 'fait';        // accompli pour cette période

function ech_categories(): array {
    return [
        'fiscal'    => ['🏛️', 'Fiscal'],
        'social'    => ['👥', 'Social'],
        'juridique' => ['⚖️', 'Juridique'],
        'assurance' => ['🛡️', 'Assurance'],
        'banque'    => ['🏦', 'Banque'],
        'autre'     => ['📌', 'Autre'],
    ];
}

function ech_recurrences(): array {
    return [
        'mensuelle'     => 'Chaque mois',
        'trimestrielle' => 'Chaque trimestre',
        'semestrielle'  => 'Deux fois par an',
        'annuelle'      => 'Une fois par an',
        'unique'        => 'Une seule fois',
    ];
}

/* ----------------------------------------------------------------------------
   Date attendue d'une occurrence.

   Le jour demandé peut ne pas exister — le 31 février, le 31 avril. On prend
   alors le dernier jour du mois : c'est ce que fait l'administration, et
   c'est ce qu'attend l'utilisateur qui a saisi « 31 ».
   ---------------------------------------------------------------------------- */
function ech_date(int $annee, int $mois, int $jour): string {
    $dernier = (int)date('t', mktime(0, 0, 0, $mois, 1, $annee));
    return sprintf('%04d-%02d-%02d', $annee, $mois, min(max(1, $jour), $dernier));
}

/* Libellé de la période concernée : sert de clé d'accomplissement. */
function ech_periode(array $e, string $date): string {
    return $e['recurrence'] === 'annuelle' || $e['recurrence'] === 'unique'
         ? substr($date, 0, 4)
         : substr($date, 0, 7);
}

/* ----------------------------------------------------------------------------
   Toutes les occurrences d'une échéance sur une année donnée.

   On énumère plutôt que de calculer « la prochaine » : c'est ce qui permet
   d'afficher l'année entière sur le cadran, et de repérer une occurrence
   passée restée en souffrance.
   ---------------------------------------------------------------------------- */
function ech_occurrences(array $e, int $annee): array {
    $jour = (int)($e['jour_du_mois'] ?: 15);
    $ref  = (int)($e['mois'] ?: 1);
    $dates = [];

    switch ($e['recurrence']) {
        case 'mensuelle':
            for ($m = 1; $m <= 12; $m++) $dates[] = ech_date($annee, $m, $jour);
            break;

        case 'trimestrielle':
            /* On part du mois de référence, puis tous les trois mois. */
            for ($k = 0; $k < 4; $k++) {
                $m = (($ref - 1 + $k * 3) % 12) + 1;
                $dates[] = ech_date($annee, $m, $jour);
            }
            break;

        case 'semestrielle':
            for ($k = 0; $k < 2; $k++) {
                $m = (($ref - 1 + $k * 6) % 12) + 1;
                $dates[] = ech_date($annee, $m, $jour);
            }
            break;

        case 'annuelle':
            $dates[] = ech_date($annee, $ref, $jour);
            break;

        case 'unique':
            if (!empty($e['date_unique']) && substr($e['date_unique'], 0, 4) === (string)$annee) {
                $dates[] = (string)$e['date_unique'];
            }
            break;
    }
    sort($dates);
    return $dates;
}

/* ----------------------------------------------------------------------------
   État d'une occurrence, à une date de référence.
   ---------------------------------------------------------------------------- */
function ech_etat(array $e, string $dateEcheance, bool $fait, string $aujourdhui = ''): array {
    $aujourdhui = $aujourdhui ?: date('Y-m-d');
    $jours = (int)floor((strtotime($dateEcheance) - strtotime($aujourdhui)) / 86400);
    $preavis = max(0, (int)($e['preavis_jours'] ?? 10));

    if ($fait)            $etat = ECH_FAIT;
    elseif ($jours < 0)   $etat = ECH_RETARD;
    elseif ($jours === 0) $etat = ECH_AUJOURD;
    elseif ($jours <= $preavis) $etat = ECH_PROCHE;
    else                  $etat = ECH_A_VENIR;

    return ['etat' => $etat, 'jours' => $jours, 'date' => $dateEcheance];
}

/* Formulation du délai, telle qu'on la dirait à l'oral. */
function ech_delai(int $jours): string {
    if ($jours === 0)  return "aujourd'hui";
    if ($jours === 1)  return 'demain';
    if ($jours === -1) return 'hier';
    if ($jours > 0)    return 'dans ' . $jours . ' jour' . ($jours > 1 ? 's' : '');
    $r = abs($jours);
    return 'en retard de ' . $r . ' jour' . ($r > 1 ? 's' : '');
}

/* ----------------------------------------------------------------------------
   Tableau complet d'une année : chaque échéance avec toutes ses occurrences.
   ---------------------------------------------------------------------------- */
function ech_annee(PDO $pdo, int $annee, bool $actifSeulement = true): array {
    try {
        $sql = "SELECT e.*, u.nom AS responsable FROM echeances e
                LEFT JOIN users u ON u.id = e.responsable_id";
        if ($actifSeulement) $sql .= " WHERE e.actif = 1";
        $sql .= " ORDER BY e.ordre, e.libelle";
        $liste = $pdo->query($sql)->fetchAll();
    } catch (Throwable $ex) { return []; }

    /* Accomplissements de l'année, chargés en une fois : une requête par
       occurrence ferait des centaines d'allers-retours. */
    $faits = [];
    try {
        $st = $pdo->prepare("SELECT echeance_id, periode, fait_le, montant_reel, note
                             FROM echeances_faites WHERE periode LIKE ?");
        $st->execute([$annee . '%']);
        foreach ($st->fetchAll() as $f) {
            $faits[$f['echeance_id'] . '|' . $f['periode']] = $f;
        }
    } catch (Throwable $ex) {}

    $res = [];
    foreach ($liste as $e) {
        $occ = [];
        foreach (ech_occurrences($e, $annee) as $d) {
            $per = ech_periode($e, $d);
            $cle = $e['id'] . '|' . $per;
            $fait = isset($faits[$cle]);
            $occ[] = ech_etat($e, $d, $fait) + [
                'periode' => $per,
                'fait_le' => $fait ? $faits[$cle]['fait_le'] : null,
                'montant_reel' => $fait ? (float)$faits[$cle]['montant_reel'] : 0,
                'note' => $fait ? $faits[$cle]['note'] : '',
            ];
        }
        $res[] = $e + ['occurrences' => $occ];
    }
    return $res;
}

/* ----------------------------------------------------------------------------
   Ce qui réclame votre attention : en retard, aujourd'hui, ou dans le préavis.

   Trié du plus urgent au moins urgent. C'est cette liste qui alimente les
   alertes du tableau de bord.
   ---------------------------------------------------------------------------- */
function ech_a_traiter(PDO $pdo, ?int $annee = null): array {
    $annee = $annee ?: (int)date('Y');
    $urgentes = [];

    /* On regarde aussi l'année précédente : une échéance de décembre non
       faite reste en retard au mois de janvier. */
    foreach ([$annee - 1, $annee, $annee + 1] as $a) {
        foreach (ech_annee($pdo, $a) as $e) {
            foreach ($e['occurrences'] as $o) {
                if ($o['etat'] === ECH_FAIT || $o['etat'] === ECH_A_VENIR) continue;
                /* On ne remonte pas indéfiniment : au-delà de 120 jours de
                   retard, l'information n'est plus actionnable. */
                if ($o['jours'] < -120) continue;
                $urgentes[] = $e + $o;
            }
        }
    }

    usort($urgentes, fn($a, $b) => $a['jours'] <=> $b['jours']);
    return $urgentes;
}

/* Décompte rapide, pour la pastille du menu. */
function ech_compteur(PDO $pdo): array {
    $c = ['retard' => 0, 'aujourdhui' => 0, 'proche' => 0];
    foreach (ech_a_traiter($pdo) as $o) {
        if (isset($c[$o['etat']])) $c[$o['etat']]++;
    }
    $c['total'] = array_sum($c);
    return $c;
}

/* ----------------------------------------------------------------------------
   Marquer une occurrence comme accomplie — ou revenir sur l'action.
   ---------------------------------------------------------------------------- */
function ech_marquer(PDO $pdo, int $id, string $periode, string $dateEcheance,
                     float $montant = 0, string $note = ''): bool {
    try {
        $pdo->prepare("INSERT INTO echeances_faites
                       (echeance_id, periode, echue_le, fait_par, montant_reel, note)
                       VALUES (?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE fait_le = NOW(),
                        fait_par = VALUES(fait_par), montant_reel = VALUES(montant_reel),
                        note = VALUES(note)")
            ->execute([$id, $periode, $dateEcheance,
                       (int)($_SESSION['admin_id'] ?? 0) ?: null,
                       $montant, mb_substr($note, 0, 255)]);
        return true;
    } catch (Throwable $e) { return false; }
}

function ech_annuler(PDO $pdo, int $id, string $periode): bool {
    try {
        $pdo->prepare("DELETE FROM echeances_faites WHERE echeance_id = ? AND periode = ?")
            ->execute([$id, $periode]);
        return true;
    } catch (Throwable $e) { return false; }
}

/* ----------------------------------------------------------------------------
   Modèles proposés au premier lancement.

   ATTENTION : ce sont des INTITULÉS COURANTS, pas des dates officielles. Les
   échéances légales dépendent de votre régime fiscal et changent d'une année
   à l'autre. Chaque modèle est créé INACTIF, avec une date à confirmer —
   inscrire une date non vérifiée serait pire que de ne rien proposer.
   ---------------------------------------------------------------------------- */
function ech_modeles(): array {
    return [
        ['TVA — déclaration mensuelle',        'fiscal', 'DGI',   'mensuelle',     15, null, 8],
        ['Cotisations sociales (CNPS)',        'social', 'CNPS',  'mensuelle',     15, null, 8],
        ['Impôt sur les traitements et salaires', 'fiscal', 'DGI', 'mensuelle',    15, null, 8],
        ['Acompte d\'impôt sur le résultat',    'fiscal', 'DGI',   'trimestrielle', 20, 1,   15],
        ['Patente — règlement annuel',          'fiscal', 'Mairie','annuelle',      31, 3,   30],
        ['États financiers annuels',            'fiscal', 'DGI',   'annuelle',      30, 6,   45],
        ['Renouvellement assurance véhicule',   'assurance', '',   'annuelle',      1,  1,   30],
        ['Visite médicale du personnel',        'social', '',      'annuelle',      1,  9,   30],
    ];
}
