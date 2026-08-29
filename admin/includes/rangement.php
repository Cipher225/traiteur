<?php
/* ============================================================================
   RANGEMENT DES DOCUMENTS — helpers de regroupement et de filtrage
   ============================================================================ */

/* Noms des mois en français */
function rangement_mois_fr(int $m): string {
    $noms = [1=>'Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
    return $noms[$m] ?? (string)$m;
}

/* Regroupe une liste de documents par Année → Mois → Client.
   Chaque document doit avoir une clé de date ($dateKey) et un nom de client ($clientKey). */
/**
 * Renvoie le nom de classement d'un document : le nom de l'ENTREPRISE si le client
 * en est une, sinon le nom du client (particulier). « Client de passage » si vide.
 * Attend éventuellement les clés : 'type_client', 'entreprise', 'client'.
 */
function rangement_nom(array $d): string {
    $type = (string)($d['type_client'] ?? '');
    $ent  = trim((string)($d['entreprise'] ?? ''));
    if ($type === 'entreprise' && $ent !== '') return $ent;
    // Sécurité : même sans type_client explicite, si une raison sociale existe, on la privilégie
    if ($type === '' && $ent !== '') return $ent;
    $nom = trim((string)($d['client'] ?? $d['client_nom'] ?? ''));
    return $nom !== '' ? $nom : 'Client de passage';
}

function rangement_arbre(array $docs, string $dateKey = 'date_emission', string $clientKey = 'client'): array {
    $arbre = [];
    foreach ($docs as $d) {
        $ts = strtotime((string)($d[$dateKey] ?? '')) ?: time();
        $annee = (int)date('Y', $ts);
        $mois  = (int)date('n', $ts);
        // Si la clé demandée est le classement intelligent, on l'utilise ; sinon la clé fournie
        if ($clientKey === '_rangement') {
            $client = rangement_nom($d);
        } else {
            $client = trim((string)($d[$clientKey] ?? '')) ?: 'Client de passage';
        }
        $arbre[$annee][$mois][$client][] = $d;
    }
    /* Tri : années décroissantes, mois décroissants, clients alphabétiques */
    krsort($arbre);
    foreach ($arbre as &$mois_) {
        krsort($mois_);
        foreach ($mois_ as &$clients_) ksort($clients_);
    }
    return $arbre;
}

/* Regroupe simplement par Année → Mois (pour l'espace employé). */
function rangement_par_mois(array $docs, string $dateKey = 'created_at'): array {
    $arbre = [];
    foreach ($docs as $d) {
        $ts = strtotime((string)($d[$dateKey] ?? '')) ?: time();
        $arbre[(int)date('Y', $ts)][(int)date('n', $ts)][] = $d;
    }
    krsort($arbre);
    foreach ($arbre as &$m) krsort($m);
    return $arbre;
}

/* Applique des filtres (client, mois, année) à une liste de documents. */
function rangement_filtrer(array $docs, array $f, string $dateKey = 'date_emission', string $clientIdKey = 'client_id'): array {
    return array_values(array_filter($docs, function($d) use ($f, $dateKey, $clientIdKey) {
        $ts = strtotime((string)($d[$dateKey] ?? '')) ?: 0;
        if (!empty($f['annee']) && (int)date('Y', $ts) !== (int)$f['annee']) return false;
        if (!empty($f['mois'])  && (int)date('n', $ts) !== (int)$f['mois'])  return false;
        if (!empty($f['client']) && (int)($d[$clientIdKey] ?? 0) !== (int)$f['client']) return false;
        return true;
    }));
}

/* Liste des années présentes dans un jeu de documents (pour peupler le filtre). */
function rangement_annees(array $docs, string $dateKey = 'date_emission'): array {
    $a = [];
    foreach ($docs as $d) { $ts = strtotime((string)($d[$dateKey] ?? '')); if ($ts) $a[(int)date('Y', $ts)] = true; }
    krsort($a);
    return array_keys($a);
}
