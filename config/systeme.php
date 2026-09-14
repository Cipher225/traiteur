<?php
/* ============================================================================
   MESURES DU SERVEUR

   Un hébergement mutualisé ne donne pas toujours accès aux compteurs du
   système : selon la configuration, /proc peut être masqué et shell_exec
   désactivé. Chaque mesure indique donc si elle est RÉELLE ou INDISPONIBLE —
   afficher une jauge inventée serait pire que ne rien afficher.
   ============================================================================ */

/* ----------------------------------------------------------------------------
   Mémoire vive.
   ---------------------------------------------------------------------------- */
function sys_memoire(): array {
    $vide = ['dispo' => false, 'total' => 0, 'utilise' => 0, 'pct' => 0, 'source' => ''];

    if (is_readable('/proc/meminfo')) {
        $texte = @file_get_contents('/proc/meminfo');
        if ($texte !== false) {
            $lire = function (string $cle) use ($texte): float {
                return preg_match('/^' . $cle . ':\s+(\d+)/m', $texte, $m) ? (float)$m[1] * 1024 : 0;
            };
            $total = $lire('MemTotal');
            /* MemAvailable est l'estimation du noyau : plus juste que MemFree,
               qui ignore le cache récupérable et fait croire à une saturation. */
            $libre = $lire('MemAvailable') ?: ($lire('MemFree') + $lire('Cached') + $lire('Buffers'));
            if ($total > 0) {
                return ['dispo' => true, 'total' => $total, 'utilise' => $total - $libre,
                        'pct' => round(($total - $libre) / $total * 100, 1), 'source' => 'systeme'];
            }
        }
    }
    return $vide;
}

/* ----------------------------------------------------------------------------
   Mémoire consommée par PHP lui-même. Toujours mesurable, elle sert de repli
   quand le système est muet — et reste utile par elle-même : c'est ce que
   votre application consomme.
   ---------------------------------------------------------------------------- */
function sys_memoire_php(): array {
    $limite = ini_get('memory_limit');
    $octets = -1;
    if ($limite !== false && $limite !== '-1') {
        $n = (float)$limite;
        $u = strtoupper(substr(trim($limite), -1));
        $octets = (int)($n * ($u === 'G' ? 1073741824 : ($u === 'M' ? 1048576 : ($u === 'K' ? 1024 : 1))));
    }
    $pic = memory_get_peak_usage(true);
    return ['utilise' => memory_get_usage(true), 'pic' => $pic, 'limite' => $octets,
            'pct' => $octets > 0 ? round($pic / $octets * 100, 1) : 0];
}

/* ----------------------------------------------------------------------------
   Charge du processeur.

   On mesure le temps passé par le processeur entre deux relevés espacés d'un
   dixième de seconde : c'est la seule façon d'obtenir un pourcentage réel.
   La charge moyenne, elle, indique le nombre de tâches en attente.
   ---------------------------------------------------------------------------- */
function sys_processeur(): array {
    $r = ['dispo' => false, 'pct' => 0, 'coeurs' => sys_nb_coeurs(),
          'charge' => [0, 0, 0], 'charge_dispo' => false];

    if (function_exists('sys_getloadavg')) {
        $c = @sys_getloadavg();
        if (is_array($c) && count($c) === 3) {
            $r['charge'] = array_map(fn($v) => round((float)$v, 2), $c);
            $r['charge_dispo'] = true;
        }
    }

    if (is_readable('/proc/stat')) {
        $a = sys_lire_cpu();
        usleep(100000);                       // 0,1 s : assez court pour ne pas faire attendre
        $b = sys_lire_cpu();
        if ($a && $b) {
            $dTotal = $b['total'] - $a['total'];
            $dRepos = $b['repos'] - $a['repos'];
            if ($dTotal > 0) {
                $r['dispo'] = true;
                $r['pct'] = round(max(0, min(100, (1 - $dRepos / $dTotal) * 100)), 1);
            }
        }
    }

    /* Repli : la charge moyenne rapportée au nombre de cœurs donne une
       estimation acceptable quand /proc/stat est inaccessible. */
    if (!$r['dispo'] && $r['charge_dispo'] && $r['coeurs'] > 0) {
        $r['dispo'] = true;
        $r['pct'] = round(min(100, $r['charge'][0] / $r['coeurs'] * 100), 1);
        $r['estime'] = true;
    }
    return $r;
}

function sys_lire_cpu(): ?array {
    $l = @file('/proc/stat');
    if (!$l) return null;
    foreach ($l as $ligne) {
        if (strncmp($ligne, 'cpu ', 4) !== 0) continue;
        $p = preg_split('/\s+/', trim($ligne));
        array_shift($p);
        $p = array_map('floatval', $p);
        /* Colonnes 4 et 5 : temps au repos et en attente de disque. */
        return ['total' => array_sum($p), 'repos' => ($p[3] ?? 0) + ($p[4] ?? 0)];
    }
    return null;
}

function sys_nb_coeurs(): int {
    if (is_readable('/proc/cpuinfo')) {
        $n = @substr_count((string)file_get_contents('/proc/cpuinfo'), 'processor');
        if ($n > 0) return $n;
    }
    return 1;
}

/* ----------------------------------------------------------------------------
   Espace disque du dossier de l'application.
   ---------------------------------------------------------------------------- */
function sys_disque(): array {
    $chemin = realpath(__DIR__ . '/..') ?: '.';
    $total = @disk_total_space($chemin);
    $libre = @disk_free_space($chemin);
    if (!$total || $total <= 0) return ['dispo' => false, 'total' => 0, 'utilise' => 0, 'pct' => 0];
    return ['dispo' => true, 'total' => (float)$total, 'libre' => (float)$libre,
            'utilise' => (float)$total - (float)$libre,
            'pct' => round(((float)$total - (float)$libre) / (float)$total * 100, 1)];
}

/* ----------------------------------------------------------------------------
   Poids de l'application : ce que VOUS occupez réellement.
   Sur un mutualisé, le disque total appartient à l'hébergeur et ne dit rien ;
   ce chiffre-ci est le vôtre.
   ---------------------------------------------------------------------------- */
function sys_poids_application(): array {
    $racine = realpath(__DIR__ . '/..');
    $poids = ['uploads' => 0, 'application' => 0, 'fichiers' => 0];
    if (!$racine) return $poids;

    $mesurer = function (string $dossier) use (&$poids): float {
        if (!is_dir($dossier)) return 0;
        $total = 0;
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY);
            foreach ($it as $f) { $total += $f->getSize(); $poids['fichiers']++; }
        } catch (Throwable $e) {}
        return $total;
    };

    $poids['uploads'] = $mesurer($racine . '/uploads');
    return $poids;
}

/* ----------------------------------------------------------------------------
   Base de données : poids et nombre de connexions.
   ---------------------------------------------------------------------------- */
function sys_base(PDO $pdo): array {
    $r = ['dispo' => false, 'poids' => 0, 'tables' => 0, 'lignes' => 0,
          'version' => '', 'connexions' => 0, 'duree' => 0];
    try {
        $t0 = microtime(true);
        $st = $pdo->query("SELECT COUNT(*) tables,
                                  COALESCE(SUM(data_length + index_length),0) poids,
                                  COALESCE(SUM(table_rows),0) lignes
                           FROM information_schema.TABLES
                           WHERE table_schema = DATABASE()");
        $d = $st->fetch();
        $r['duree'] = round((microtime(true) - $t0) * 1000, 1);
        $r['dispo'] = true;
        $r['tables'] = (int)$d['tables'];
        $r['poids']  = (float)$d['poids'];
        $r['lignes'] = (int)$d['lignes'];
        $r['version'] = (string)$pdo->query('SELECT VERSION()')->fetchColumn();

        $c = $pdo->query("SHOW STATUS LIKE 'Threads_connected'")->fetch();
        $r['connexions'] = (int)($c['Value'] ?? 0);
    } catch (Throwable $e) {}
    return $r;
}

/* ----------------------------------------------------------------------------
   Temps de fonctionnement du serveur.
   ---------------------------------------------------------------------------- */
function sys_uptime(): array {
    if (is_readable('/proc/uptime')) {
        $t = @file_get_contents('/proc/uptime');
        if ($t !== false) {
            $s = (int)(float)strtok($t, ' ');
            return ['dispo' => true, 'secondes' => $s, 'texte' => sys_duree_lisible($s)];
        }
    }
    return ['dispo' => false, 'secondes' => 0, 'texte' => ''];
}

function sys_duree_lisible(int $s): string {
    $j = intdiv($s, 86400); $s %= 86400;
    $h = intdiv($s, 3600);  $s %= 3600;
    $m = intdiv($s, 60);
    $bouts = [];
    if ($j) $bouts[] = $j . ' jour' . ($j > 1 ? 's' : '');
    if ($h) $bouts[] = $h . ' h';
    if (!$j && $m) $bouts[] = $m . ' min';
    return $bouts ? implode(' ', $bouts) : 'moins d\'une minute';
}

/* ----------------------------------------------------------------------------
   Poids lisible par un humain.
   ---------------------------------------------------------------------------- */
function sys_octets(float $o, int $dec = 1): string {
    $u = ['o', 'Ko', 'Mo', 'Go', 'To'];
    $i = 0;
    while ($o >= 1024 && $i < 4) { $o /= 1024; $i++; }
    return number_format($o, $i === 0 ? 0 : $dec, ',', ' ') . ' ' . $u[$i];
}

/* ----------------------------------------------------------------------------
   Relevé complet, au format attendu par l'écran de surveillance.
   ---------------------------------------------------------------------------- */
function sys_releve(PDO $pdo): array {
    return [
        'heure'      => date('H:i:s'),
        'processeur' => sys_processeur(),
        'memoire'    => sys_memoire(),
        'memoire_php'=> sys_memoire_php(),
        'disque'     => sys_disque(),
        'base'       => sys_base($pdo),
        'uptime'     => sys_uptime(),
        'php'        => PHP_VERSION,
        'serveur'    => $_SERVER['SERVER_SOFTWARE'] ?? 'inconnu',
    ];
}
