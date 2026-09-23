<?php
/* ============================================================================
   CALCULATRICE SCIENTIFIQUE — le clavier et son panneau
   ============================================================================
   Un seul fichier pour les deux espaces (administration/employés et client) :
   une calculatrice qui diverge d'un espace à l'autre est une calculatrice qui
   finit par donner deux résultats.

   Deux paramètres seulement :
     $calcBase : le chemin relatif vers la racine du site ('.' ou '..'),
                 pour retrouver le fichier JavaScript.
   Le moteur de calcul vit dans assets/js/calculatrice.js.

   Convention des touches :
     data-ins  : le texte inséré dans l'expression
     data-act  : une commande (=, AC, mémoire…)
     data-ins2 / data-act2 : la seconde fonction, atteinte par la touche Maj
============================================================================ */

function calculatrice_bouton(string $classe = ''): void { ?>
<button type="button" class="calc-declencheur <?= htmlspecialchars($classe, ENT_QUOTES) ?>"
        data-calc-ouvrir title="Calculatrice scientifique (Échap pour fermer)">
  <span class="cd-ico" aria-hidden="true">
    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8"
         stroke-linecap="round" stroke-linejoin="round">
      <rect x="4" y="2" width="16" height="20" rx="3"/><path d="M8 6h8"/>
      <path d="M8.5 11h0M12 11h0M15.5 11h0M8.5 14.5h0M12 14.5h0M15.5 14.5h0M8.5 18h0M12 18h0M15.5 18h0"/>
    </svg>
  </span>
  Calculatrice
</button>
<?php }

function calculatrice_panneau(string $calcBase = '.'): void {
    /* Chaque rangée : [libellé, seconde fonction affichée, attributs, classe] */
    ?>
<div class="cal-fen" id="calcFenetre" hidden role="dialog" aria-label="Calculatrice scientifique">

  <div class="cal-tete">
    <span class="cal-titre">Calculatrice scientifique</span>
    <span class="cal-modele">GH-991</span>
    <button type="button" class="cal-fermer" data-calc-fermer aria-label="Fermer">✕</button>
  </div>

  <!-- Écran : l'expression en haut, le résultat en bas, les témoins au-dessus -->
  <div class="cal-ecran">
    <div class="cal-ind" aria-hidden="true"></div>
    <div class="cal-expr" aria-live="off">0</div>
    <div class="cal-res" aria-live="polite">0</div>
  </div>

  <div class="cal-clavier">
    <!-- Rangée 1 : modes et mémoire -->
    <button type="button" class="cal-t t-maj"  data-act="maj"><b>Maj</b></button>
    <button type="button" class="cal-t t-mode" data-act="deg">DEG<br><small>RAD</small></button>
    <button type="button" class="cal-t t-2 t-mem"  data-act="mplus" data-act2="mmoins"><b>M+</b><small>M−</small></button>
    <button type="button" class="cal-t t-2 t-mem"  data-act="mr"    data-act2="mc"><b>MR</b><small>MC</small></button>
    <button type="button" class="cal-t t-mem"  data-act="copier" title="Copier le résultat"><b>Copier</b></button>

    <!-- Rangée 2 : trigonométrie (Maj → fonctions inverses) -->
    <button type="button" class="cal-t t-2 t-fn" data-ins="sin(" data-ins2="asin("><b>sin</b><small>sin⁻¹</small></button>
    <button type="button" class="cal-t t-2 t-fn" data-ins="cos(" data-ins2="acos("><b>cos</b><small>cos⁻¹</small></button>
    <button type="button" class="cal-t t-2 t-fn" data-ins="tan(" data-ins2="atan("><b>tan</b><small>tan⁻¹</small></button>
    <button type="button" class="cal-t t-2 t-fn" data-ins="ln("  data-ins2="exp("><b>ln</b><small>e^x</small></button>
    <button type="button" class="cal-t t-2 t-fn" data-ins="log(" data-ins2="10^"><b>log</b><small>10^x</small></button>

    <!-- Rangée 3 : puissances, racines, constantes -->
    <button type="button" class="cal-t t-2 t-fn" data-ins="√("  data-ins2="∛("><b>√</b><small>∛</small></button>
    <button type="button" class="cal-t t-2 t-fn" data-ins="^2"  data-ins2="^"><b>x²</b><small>x^y</small></button>
    <button type="button" class="cal-t t-2 t-fn" data-ins="^-1" data-ins2="!"><b>x⁻¹</b><small>x!</small></button>
    <button type="button" class="cal-t t-2 t-fn" data-ins="π"   data-ins2="e"><b>π</b><small>e</small></button>
    <button type="button" class="cal-t t-2 t-fn" data-ins="%"   data-ins2="abs("><b>%</b><small>|x|</small></button>

    <!-- Rangée 4 : parenthèses et effacement -->
    <button type="button" class="cal-t t-par" data-ins="("><b>(</b></button>
    <button type="button" class="cal-t t-par" data-ins=")"><b>)</b></button>
    <button type="button" class="cal-t t-fn"  data-act="ans"><b>Ans</b></button>
    <button type="button" class="cal-t t-eff" data-act="del"><b>DEL</b></button>
    <button type="button" class="cal-t t-ac"  data-act="ac"><b>AC</b></button>

    <!-- Pavé numérique -->
    <button type="button" class="cal-t t-nb" data-ins="7"><b>7</b></button>
    <button type="button" class="cal-t t-nb" data-ins="8"><b>8</b></button>
    <button type="button" class="cal-t t-nb" data-ins="9"><b>9</b></button>
    <button type="button" class="cal-t t-op" data-ins="÷"><b>÷</b></button>
    <button type="button" class="cal-t t-op" data-ins="×"><b>×</b></button>

    <button type="button" class="cal-t t-nb" data-ins="4"><b>4</b></button>
    <button type="button" class="cal-t t-nb" data-ins="5"><b>5</b></button>
    <button type="button" class="cal-t t-nb" data-ins="6"><b>6</b></button>
    <button type="button" class="cal-t t-op" data-ins="-"><b>−</b></button>
    <button type="button" class="cal-t t-op" data-ins="+"><b>+</b></button>

    <button type="button" class="cal-t t-nb" data-ins="1"><b>1</b></button>
    <button type="button" class="cal-t t-nb" data-ins="2"><b>2</b></button>
    <button type="button" class="cal-t t-nb" data-ins="3"><b>3</b></button>
    <button type="button" class="cal-t t-fn" data-act="signe"><b>±</b></button>
    <?php /* Le « = » occupe deux rangées dans la dernière colonne : c'est sa
             place sur toutes les machines, et la main la trouve sans regarder. */ ?>
    <button type="button" class="cal-t t-eg" data-act="egale" style="grid-row:span 2"><b>=</b></button>

    <button type="button" class="cal-t t-nb" data-ins="0" style="grid-column:span 2"><b>0</b></button>
    <button type="button" class="cal-t t-nb" data-ins="."><b>,</b></button>
    <button type="button" class="cal-t t-fn" data-ins="E" title="Puissance de dix"><b>×10ˣ</b></button>
  </div>

  <details class="cal-tiroir">
    <summary>Historique &amp; aide</summary>
    <div class="cal-hist"></div>
    <div class="cal-aide">
      <button type="button" class="cal-t t-eff cal-vider" data-act="vider"><b>Vider l'historique</b></button>
      <p>Le clavier de l'ordinateur fonctionne : chiffres, <b>+ − * /</b>, <b>Entrée</b> pour calculer,
         <b>Retour arrière</b> pour effacer, <b>Échap</b> pour fermer.
         La multiplication peut être sous-entendue — <b>2π</b>, <b>3(4+5)</b>.</p>
    </div>
  </details>
</div>
<script src="<?= asset($calcBase . '/assets/js/calculatrice.js') ?>"></script>
<?php }
