/* ============================================================================
   DÉCONNEXION AUTOMATIQUE SUR INACTIVITÉ
   ----------------------------------------------------------------------------
   Le serveur coupe déjà la session au bout du délai, mais il ne le fait qu'au
   chargement de page suivant : un écran resté ouvert afficherait encore des
   données confidentielles. Ce compte à rebours ferme la session dans le
   navigateur, à l'heure dite.

   Un avertissement s'affiche 30 secondes avant, pour éviter de perdre une
   saisie en cours. Le moindre mouvement relance le compteur.
   ============================================================================ */
(function () {
  'use strict';

  var DELAI  = (window.INACTIVITE_SECONDES || 180) * 1000;  // 3 minutes par défaut
  var ALERTE = 30 * 1000;                                   // avertissement 30 s avant
  var SORTIE = window.INACTIVITE_URL || '../login.php?inactif=1';

  var t0 = Date.now();
  var boite = null, minuteur = null;

  function fermerBoite() {
    if (boite) { boite.remove(); boite = null; }
  }

  function relancer() {
    t0 = Date.now();
    fermerBoite();
  }

  function avertir(restant) {
    if (boite) {
      var s = boite.querySelector('.iv-sec');
      if (s) s.textContent = restant;
      return;
    }
    boite = document.createElement('div');
    boite.className = 'inactif-boite';
    boite.innerHTML =
      '<div class="iv-carte">' +
        '<div class="iv-ico">🔒</div>' +
        '<div class="iv-txt">' +
          '<strong>Déconnexion imminente</strong>' +
          '<div>Vous allez être déconnecté dans <span class="iv-sec">' + restant + '</span> secondes, ' +
          'par sécurité. Bougez la souris ou touchez l\'écran pour rester connecté.</div>' +
        '</div>' +
        '<button type="button" class="iv-btn">Rester connecté</button>' +
      '</div>';
    document.body.appendChild(boite);
    boite.querySelector('.iv-btn').addEventListener('click', function () {
      relancer();
      /* On prévient aussi le serveur, sinon il compterait l'attente comme
         de l'inactivité et couperait la session au prochain chargement. */
      fetch(window.location.href, { method: 'HEAD', credentials: 'same-origin' }).catch(function () {});
    });
  }

  function verifier() {
    var ecoule = Date.now() - t0;
    if (ecoule >= DELAI) {
      window.location.href = SORTIE;
      return;
    }
    if (ecoule >= DELAI - ALERTE) {
      avertir(Math.max(1, Math.ceil((DELAI - ecoule) / 1000)));
    }
  }

  ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'wheel', 'focus']
    .forEach(function (ev) {
      window.addEventListener(ev, function () {
        /* Pendant l'avertissement, seul un clic sur le bouton doit compter :
           un simple mouvement de souris ne doit pas masquer l'alerte sans que
           l'utilisateur s'en rende compte. */
        if (!boite) relancer();
      }, { passive: true });
    });

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) verifier();
  });

  minuteur = setInterval(verifier, 1000);
})();
