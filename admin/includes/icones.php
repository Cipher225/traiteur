<?php
/* ============================================================================
   SÉLECTEUR D'ICÔNES
   ----------------------------------------------------------------------------
   Affiche un champ accompagné d'une palette d'icônes classées par thème.
   L'utilisateur clique sur une icône plutôt que de la chercher au clavier,
   tout en gardant la possibilité d'en coller une autre.

   Usage :  <?= champ_icone('icone', $valeur, 'Icône du dossier') ?>
   ============================================================================ */

function icones_disponibles(): array {
    return [
        'Restauration' => ['🍽️','🍴','👨‍🍳','🧑‍🍳','🍳','🥘','🍲','🥗','🥪','🍕','🍔','🌮','🍛','🍜',
                           '🍱','🥟','🍤','🍗','🥩','🥓','🧆','🫕','🍚','🍝'],
        'Boissons'     => ['🥂','🍾','🍷','🍸','🍹','🍺','🧃','🧉','☕','🫖','🥤','🧊','🍶','🥛'],
        'Desserts'     => ['🍰','🎂','🧁','🍮','🍨','🍦','🍩','🍪','🍫','🍬','🍯','🥐','🥧','🍓'],
        'Événements'   => ['🎉','🎊','🎈','🎁','💍','💐','🌹','🕯️','🎀','🎪','🎭','🎶','🥳','💒'],
        'Documents'    => ['📁','📂','🗂️','📄','📃','📑','📊','📈','📉','🧾','📋','📌','📎','🗃️',
                           '📚','📕','📗','📘','📙','🔖','🗄️','📇'],
        'Gestion'      => ['💼','🏢','🏪','🏬','🗓️','📅','⏰','⏳','🔑','🔒','🔓','⚙️','🛠️','🔧',
                           '📦','🚚','🛒','🧹','🧺','♻️'],
        'Finance'      => ['💰','💵','💳','🧮','🏦','💹','📥','📤','🪙','💸','🧿','🎯'],
        'Personnes'    => ['👤','👥','🧑','👩','👨','🧑‍💼','👮','🕴️','🤝','👋','🙋','💬','📞','✉️'],
        'Repères'      => ['⭐','✨','🌟','💫','🔥','❤️','💙','💚','💛','🧡','💜','✅','❌','⚠️',
                           '❗','❓','🔔','📍','🏆','🥇','🎖️','💎','🌍','🚀'],
    ];
}

function champ_icone(string $nom, string $valeur = '', string $libelle = 'Icône', string $defaut = '📁'): string {
    $val = $valeur !== '' ? $valeur : $defaut;
    $id  = 'ic_' . preg_replace('/[^a-z0-9]/i', '', $nom) . '_' . substr(md5($nom . $val . random_int(0, 9999)), 0, 5);

    $o  = '<div class="champ-icone" data-champ="' . $id . '">';
    $o .= '<label>' . htmlspecialchars($libelle) . '</label>';
    $o .= '<div class="ci-haut">';
    $o .= '<button type="button" class="ci-apercu" aria-label="Choisir une icône">' . htmlspecialchars($val) . '</button>';
    $o .= '<input class="input ci-valeur" name="' . htmlspecialchars($nom) . '" value="' . htmlspecialchars($val) . '" maxlength="8" aria-label="Icône choisie">';
    $o .= '<button type="button" class="btn btn-glass btn-sm ci-ouvrir">Choisir…</button>';
    $o .= '</div>';

    $o .= '<div class="ci-palette" hidden>';
    foreach (icones_disponibles() as $groupe => $liste) {
        $o .= '<div class="ci-groupe"><div class="ci-titre">' . htmlspecialchars($groupe) . '</div><div class="ci-grille">';
        foreach ($liste as $ic) {
            $sel = ($ic === $val) ? ' ci-choisie' : '';
            $o .= '<button type="button" class="ci-item' . $sel . '">' . $ic . '</button>';
        }
        $o .= '</div></div>';
    }
    $o .= '</div></div>';
    return $o;
}
