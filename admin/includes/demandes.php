<?php
/* Types de documents rédigeables par l'employé (rapport + demandes RH).
   Chaque type : [label, icône, préfixe, champs structurés, intro éditeur, couleur badge] */
function demande_types(): array {
    return [
        'rapport'       => ['Rapport journalier',                 '📝', 'RAP',   [],                         'Résumé de la journée…',                'badge-violet'],
        'permission'    => ['Demande de permission',              '🙋', 'PERM',  ['periode','motif'],        'Précisez le motif de votre absence…',   'badge-gold'],
        'conge'         => ['Demande de congé',                   '🏖️', 'CONGE', ['periode','motif'],        'Motivez votre demande de congé…',       'badge-teal'],
        'explication'   => ["Réponse à une demande d'explication",'✍️', 'EXPL',  ['objet'],                  'Rédigez votre réponse / explication…',  'badge'],
        'conge_maladie' => ['Demande de congé maladie',           '🏥', 'MAL',   ['periode','hopital','motif'],'Informations complémentaires…',        'badge-danger'],
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
