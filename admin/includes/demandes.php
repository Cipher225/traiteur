<?php
/* Types de documents rédigeables par l'employé (rapport + demandes RH).
   Chaque type : [label, icône, préfixe, champs structurés, intro éditeur, couleur badge] */
function demande_types(): array {
    /* Les préfixes sont réglables dans Paramètres → Facturation. On les lit une
       seule fois, puis on retombe sur la valeur d'origine si rien n'est défini. */
    static $p = null;
    if ($p === null) {
        $p = ['rapport' => 'RAP', 'permission' => 'PERM', 'conge' => 'CONGE',
              'explication' => 'EXPL', 'conge_maladie' => 'MAL'];
        try {
            global $pdo;
            if ($pdo instanceof PDO) {
                $st = $pdo->query("SELECT cle, valeur FROM settings WHERE cle LIKE 'prefixe_%'");
                foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $cle => $val) {
                    $k = substr($cle, 8);                       // « prefixe_rapport » → « rapport »
                    if (isset($p[$k]) && trim((string)$val) !== '') $p[$k] = strtoupper(trim($val));
                }
            }
        } catch (Throwable $e) { /* on garde les valeurs d'origine */ }
    }

    return [
        'rapport'       => ['Rapport journalier',                 '📝', $p['rapport'],   [],                         'Résumé de la journée…',                'badge-violet'],
        'permission'    => ['Demande de permission',              '🙋', $p['permission'],  ['periode','motif'],        'Précisez le motif de votre absence…',   'badge-gold'],
        'conge'         => ['Demande de congé',                   '🏖️', $p['conge'], ['periode','motif'],        'Motivez votre demande de congé…',       'badge-teal'],
        'explication'   => ["Réponse à une demande d'explication",'✍️', $p['explication'],  ['objet'],                  'Rédigez votre réponse / explication…',  'badge'],
        'conge_maladie' => ['Demande de congé maladie',           '🏥', $p['conge_maladie'],   ['periode','hopital','motif'],'Informations complémentaires…',        'badge-danger'],
    ];
}
function demande_type_info(string $t): array {
    $all = demande_types();
    return $all[$t] ?? $all['rapport'];
}
/* Types soumis à une décision de l'admin (accepter / refuser) */
function demande_decidable(string $t): bool {
    return in_array($t, ['permission', 'conge', 'conge_maladie'], true);
}
