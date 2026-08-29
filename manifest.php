<?php
/* ============================================================================
   MANIFEST DE L'APPLICATION (PWA)
   Décrit l'application installable : nom, icônes, couleurs, écran de démarrage.
   Généré dynamiquement à partir des paramètres de l'entreprise.
   ============================================================================ */
require __DIR__ . '/config/db.php';
$s = get_settings($pdo);

header('Content-Type: application/manifest+json; charset=utf-8');

$nom = $s['nom_entreprise'] ?? 'Groupe Helisce';
$slogan = $s['slogan'] ?? '';

echo json_encode([
    'name'             => $nom,
    'short_name'       => mb_substr($nom, 0, 18),
    'description'      => $slogan,
    'start_url'        => './index.php',
    'scope'            => './',
    'display'          => 'standalone',        // plein écran, sans barre de navigateur
    'orientation'      => 'portrait-primary',
    'background_color' => '#ffffff',            // fond blanc de l'écran de démarrage
    'theme_color'      => '#0a1f44',            // couleur de la barre système
    'lang'             => 'fr',
    'dir'              => 'ltr',
    'icons'            => [
        [
            'src'     => './icone-pwa.php?t=192',
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => './icone-pwa.php?t=512',
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => './icone-pwa.php?t=192&m=1',
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'maskable',   // icône adaptative (Android)
        ],
        [
            'src'     => './icone-pwa.php?t=512&m=1',
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'maskable',
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
