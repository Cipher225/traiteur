/* ============================================================================
   Mélodie de notification — inspirée du "Tri-tone" iPhone.
   Générée en Web Audio API (aucun fichier audio à télécharger).
   Se déclenche quand le nombre de messages non lus augmente.
   ============================================================================ */
(function () {
  'use strict';

  var AudioCtx = window.AudioContext || window.webkitAudioContext;
  if (!AudioCtx) return;

  var ctx = null;
  function ensureCtx() {
    if (!ctx) { try { ctx = new AudioCtx(); } catch (e) { return null; } }
    if (ctx.state === 'suspended') ctx.resume();
    return ctx;
  }

  /* Joue une note à une fréquence donnée, à un instant, pour une durée. */
  function note(freq, start, dur) {
    var c = ctx;
    var osc = c.createOscillator();
    var gain = c.createGain();
    osc.type = 'sine';
    osc.frequency.value = freq;
    // Enveloppe douce (attaque rapide, extinction en cloche) — sonorité "verre / cloche"
    gain.gain.setValueAtTime(0, start);
    gain.gain.linearRampToValueAtTime(0.28, start + 0.012);
    gain.gain.exponentialRampToValueAtTime(0.0008, start + dur);
    osc.connect(gain); gain.connect(c.destination);
    osc.start(start);
    osc.stop(start + dur + 0.02);
  }

  /* La mélodie : deux notes claires façon "Tri-tone" (mi–do aigus). */
  function jouer() {
    var c = ensureCtx();
    if (!c) return;
    var t = c.currentTime + 0.02;
    // Fréquences proches du tri-ton iPhone : une note haute puis une plus haute
    note(1318.5, t,        0.16);  // Mi6
    note(1567.98, t + 0.14, 0.32); // Sol6 (résonance plus longue)
  }

  /* Débloquer l'audio à la première interaction (politique navigateur). */
  var debloque = false;
  function debloquer() {
    if (debloque) return;
    ensureCtx();
    debloque = true;
    window.removeEventListener('click', debloquer);
    window.removeEventListener('keydown', debloquer);
    window.removeEventListener('touchstart', debloquer);
  }
  window.addEventListener('click', debloquer);
  window.addEventListener('keydown', debloquer);
  window.addEventListener('touchstart', debloquer);

  /* Surveiller le compteur de messages et sonner quand il augmente. */
  var wrap = document.querySelector('.notif-wrap[data-msg-count]');
  if (!wrap) return;

  var STORAGE = 'gh_msg_count';
  var actuel = parseInt(wrap.getAttribute('data-msg-count') || '0', 10) || 0;

  // Comparaison avec la dernière valeur connue (via sessionStorage, propre à l'onglet)
  var precedent = null;
  try { precedent = sessionStorage.getItem(STORAGE); } catch (e) {}
  precedent = precedent === null ? actuel : (parseInt(precedent, 10) || 0);

  // Au chargement : si plus de messages qu'avant → sonner une fois
  if (actuel > precedent) { setTimeout(jouer, 400); }
  try { sessionStorage.setItem(STORAGE, String(actuel)); } catch (e) {}

  /* Vérification périodique en arrière-plan (toutes les 30 s) via l'API notifications. */
  function verifier() {
    fetch('notifications-check.php', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j || typeof j.messages !== 'number') return;
        var dernier = parseInt(sessionStorage.getItem(STORAGE) || '0', 10) || 0;
        if (j.messages > dernier) {
          jouer();
          // Mettre à jour la pastille visuelle si présente
          var dot = wrap.querySelector('.notif-dot');
          if (dot) dot.textContent = j.total > 99 ? '99+' : j.total;
        }
        try { sessionStorage.setItem(STORAGE, String(j.messages)); } catch (e) {}
      })
      .catch(function () {});
  }
  setInterval(verifier, 30000);
})();
