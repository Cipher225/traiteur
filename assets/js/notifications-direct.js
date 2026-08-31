/* ============================================================================
   NOTIFICATIONS EN DIRECT
   ----------------------------------------------------------------------------
   Interroge le serveur toutes les 10 secondes et met à jour les pastilles du
   menu sans recharger la page. Sur la messagerie et le forum, la conversation
   se rafraîchit d'elle-même à l'arrivée d'un nouveau message.

   Économe par principe :
   • rien n'est demandé quand l'onglet est en arrière-plan ;
   • la page ne se recharge jamais pendant que vous écrivez ;
   • en cas de coupure réseau, l'intervalle s'espace puis revient à la normale.
   ============================================================================ */
(function () {
  'use strict';

  var url = (window.NOTIF_URL || 'notifications-check.php');
  var BASE = 10000;          // 10 secondes
  var MAX  = 60000;          // au-delà d'une minute en cas d'échec répété
  var attente = BASE;
  var minuteur = null;
  var connus = null;         // dernier état connu, pour repérer les nouveautés

  /* ---------- Pastilles du menu ---------- */
  function majPastille(cle, nb) {
    var lien = document.querySelector('.side-nav a[data-nav="' + cle + '"]')
            || document.querySelector('.side-nav a[href*="' + cle + '.php"]');
    if (!lien) return;
    var p = lien.querySelector('.nav-badge');
    if (nb > 0) {
      if (!p) {
        p = document.createElement('span');
        p.className = 'nav-badge';
        lien.appendChild(p);
      }
      if (p.textContent !== String(nb)) {
        p.textContent = nb;
        p.classList.remove('pulse');
        void p.offsetWidth;            // relance l'animation
        p.classList.add('pulse');
      }
    } else if (p) {
      p.remove();
    }
  }

  /* ---------- Petit signal discret ---------- */
  function signaler(texte, lien) {
    var t = document.createElement('div');
    t.className = 'notif-flash';
    t.innerHTML = '<span>💬</span> ' + texte;
    if (lien) {
      t.style.cursor = 'pointer';
      t.addEventListener('click', function () { location.href = lien; });
    }
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add('sortie'); }, 4500);
    setTimeout(function () { if (t.parentNode) t.remove(); }, 5100);
  }

  /* ---------- L'utilisateur est-il en train d'écrire ? ---------- */
  function enTrainDEcrire() {
    var a = document.activeElement;
    if (!a) return false;
    var tag = (a.tagName || '').toLowerCase();
    if (tag === 'textarea' || (tag === 'input' && a.type !== 'submit' && a.type !== 'button')) return true;
    if (a.isContentEditable) return true;
    // un champ déjà rempli, même sans le curseur dedans, ne doit pas être perdu
    var champs = document.querySelectorAll('form textarea, form input[type="text"]');
    for (var i = 0; i < champs.length; i++) {
      if (champs[i].value && champs[i].value.trim() !== '') return true;
    }
    return false;
  }

  /* ---------- Page en cours ---------- */
  var page = (location.pathname.split('/').pop() || '').toLowerCase();
  var surMessagerie = page.indexOf('messagerie') === 0;
  var surForum      = page.indexOf('forum') === 0;

  /* ---------- Interrogation ---------- */
  function verifier() {
    if (document.hidden) { programmer(); return; }

    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        attente = BASE;
        if (!d || !d.ok) { programmer(); return; }

        var badges = d.badges || {};
        Object.keys(badges).forEach(function (k) { majPastille(k, badges[k] | 0); });

        if (connus !== null) {
          var nouveauxMsg   = (d.messages | 0) - (connus.messages | 0);
          var nouveauxForum = (d.forum | 0)    - (connus.forum | 0);

          // Sur la page concernée : on rafraîchit pour afficher le message
          if ((surMessagerie && nouveauxMsg > 0) || (surForum && nouveauxForum > 0)) {
            if (!enTrainDEcrire()) { location.reload(); return; }
            signaler('Nouveau message reçu — actualisez pour le voir');
          } else if (nouveauxMsg > 0) {
            signaler(nouveauxMsg > 1 ? nouveauxMsg + ' nouveaux messages' : 'Nouveau message',
                     'messagerie.php');
          } else if (nouveauxForum > 0) {
            signaler(nouveauxForum > 1 ? nouveauxForum + ' nouveaux messages sur le forum'
                                       : 'Nouveau message sur le forum', 'forum.php');
          }
        }
        connus = d;
        programmer();
      })
      .catch(function () {
        attente = Math.min(attente * 2, MAX);   // réseau coupé : on espace les tentatives
        programmer();
      });
  }

  function programmer() {
    clearTimeout(minuteur);
    minuteur = setTimeout(verifier, attente);
  }

  // Au retour sur l'onglet, on vérifie tout de suite
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { clearTimeout(minuteur); attente = BASE; verifier(); }
  });

  setTimeout(verifier, 3000);   // première vérification après le chargement
})();
