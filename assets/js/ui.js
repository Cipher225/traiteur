/* ============================================================================
   INTERFACE COMMUNE
   ----------------------------------------------------------------------------
   Deux comportements présents dans toute l'application :

   1. Les demandes de confirmation. La fenêtre du navigateur est laide, non
      traduisible et impossible à styler. On la remplace par une fenêtre
      maison, qui laisse aussi la place d'expliquer les conséquences.

   2. La progression d'un envoi de fichier. Sans retour visuel, l'utilisateur
      ne sait pas si la page a gelé — et il clique une seconde fois.
   ============================================================================ */
(function () {
  'use strict';

  var doux = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ==========================================================================
     1. FENÊTRE DE CONFIRMATION
     ========================================================================== */
  var boite = null;

  function fermerBoite() {
    if (!boite) return;
    var b = boite; boite = null;
    b.classList.remove('ouvert');
    setTimeout(function () { if (b.parentNode) b.remove(); }, doux ? 200 : 0);
    document.body.style.overflow = '';
  }

  /* $options : { titre, texte, action, danger, icone } */
  function demanderConfirmation(options, alorsOui) {
    fermerBoite();
    var o = options || {};
    var danger = !!o.danger;

    boite = document.createElement('div');
    boite.className = 'cfm';
    boite.innerHTML =
      '<div class="cfm-carte' + (danger ? ' danger' : '') + '" role="dialog" aria-modal="true">' +
        '<div class="cfm-ico">' + (o.icone || (danger ? '🗑️' : '❓')) + '</div>' +
        '<h3>' + (o.titre || 'Confirmer l\'action') + '</h3>' +
        '<p>' + (o.texte || '') + '</p>' +
        '<div class="cfm-btns">' +
          '<button type="button" class="cfm-non">Annuler</button>' +
          '<button type="button" class="cfm-oui' + (danger ? ' danger' : '') + '">' +
            (o.action || 'Confirmer') + '</button>' +
        '</div>' +
      '</div>';

    document.body.appendChild(boite);
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(function () { boite.classList.add('ouvert'); });

    var oui = boite.querySelector('.cfm-oui');
    oui.focus();
    oui.addEventListener('click', function () { fermerBoite(); alorsOui(); });
    boite.querySelector('.cfm-non').addEventListener('click', fermerBoite);
    boite.addEventListener('click', function (e) { if (e.target === boite) fermerBoite(); });

    document.addEventListener('keydown', function esc(e) {
      if (!boite) { document.removeEventListener('keydown', esc); return; }
      if (e.key === 'Escape') { fermerBoite(); document.removeEventListener('keydown', esc); }
      if (e.key === 'Enter')  { fermerBoite(); alorsOui(); document.removeEventListener('keydown', esc); }
    });
  }

  /* Une phrase de confirmation devient un titre court et une explication :
     « Supprimer cette facture ? Cette action est définitive. » */
  function decouper(message) {
    var m = String(message || '').trim();
    var i = m.search(/[?!.]\s/);
    if (i > 0 && i < m.length - 2) {
      return { titre: m.slice(0, i + 1).trim(), texte: m.slice(i + 1).trim() };
    }
    return { titre: m || 'Confirmer l\'action', texte: 'Cette action ne pourra pas être annulée.' };
  }

  function estDangereux(el, message) {
    var t = (String(message || '') + ' ' + (el.className || '')).toLowerCase();
    return /supprim|effac|retir|vider|annul|danger/.test(t);
  }

  /* On intercepte AVANT que le navigateur n'affiche sa propre fenêtre. */
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.dataset.cfmOk === '1') { f.dataset.cfmOk = ''; return; }

    var msg = f.getAttribute('data-confirm');
    if (!msg) {
      var brut = f.getAttribute('onsubmit') || '';
      var m = brut.match(/confirm\(\s*(['"])([\s\S]*?)\1\s*\)/);
      if (m) { msg = m[2].replace(/\\'/g, "'").replace(/\\"/g, '"'); f.removeAttribute('onsubmit'); }
    }
    if (!msg) return;

    e.preventDefault();
    var d = decouper(msg);
    var envoyeur = f.__cfmBouton || null;
    demanderConfirmation({
      titre: d.titre, texte: d.texte,
      danger: estDangereux(f, msg),
      action: estDangereux(f, msg) ? 'Supprimer' : 'Confirmer'
    }, function () {
      f.dataset.cfmOk = '1';
      /* On rejoue le clic du bouton d'origine : sa valeur doit partir avec
         le formulaire, sinon l'action serait perdue. */
      if (envoyeur && envoyeur.name) {
        var h = document.createElement('input');
        h.type = 'hidden'; h.name = envoyeur.name; h.value = envoyeur.value;
        f.appendChild(h);
      }
      if (typeof f.requestSubmit === 'function') f.requestSubmit();
      else f.submit();
    });
  }, true);

  /* On retient quel bouton a déclenché l'envoi. */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('button[type="submit"], button:not([type]), input[type="submit"]');
    if (b && b.form) b.form.__cfmBouton = b;

    /* Boutons portant leur propre confirmation */
    var el = e.target.closest('[onclick*="confirm("]');
    if (!el) return;
    var m = (el.getAttribute('onclick') || '').match(/confirm\(\s*(['"])([\s\S]*?)\1\s*\)/);
    if (!m) return;
    el.removeAttribute('onclick');
    e.preventDefault(); e.stopPropagation();
    var d = decouper(m[2]);
    demanderConfirmation({
      titre: d.titre, texte: d.texte,
      danger: estDangereux(el, m[2]),
      action: estDangereux(el, m[2]) ? 'Supprimer' : 'Confirmer'
    }, function () {
      if (el.form) {
        el.form.dataset.cfmOk = '1';
        if (el.name) {
          var h = document.createElement('input');
          h.type = 'hidden'; h.name = el.name; h.value = el.value;
          el.form.appendChild(h);
        }
        if (typeof el.form.requestSubmit === 'function') el.form.requestSubmit();
        else el.form.submit();
      } else if (el.href) {
        window.location.href = el.href;
      }
    });
  }, true);

  /* Les liens de suppression passent par la même fenêtre. */
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-confirm]');
    if (!a) return;
    e.preventDefault();
    var d = decouper(a.getAttribute('data-confirm'));
    demanderConfirmation({
      titre: d.titre, texte: d.texte,
      danger: estDangereux(a, a.getAttribute('data-confirm')),
      action: 'Confirmer'
    }, function () { window.location.href = a.href; });
  }, true);

  /* ==========================================================================
     2. PROGRESSION D'UN ENVOI DE FICHIER
     ========================================================================== */
  var barre = null;

  function ouvrirProgression(poids) {
    barre = document.createElement('div');
    barre.className = 'envoi';
    barre.innerHTML =
      '<div class="env-carte">' +
        '<div class="env-ico">' +
          '<svg viewBox="0 0 100 100" class="env-anneau">' +
            '<circle cx="50" cy="50" r="42" class="ea-fond"/>' +
            '<circle cx="50" cy="50" r="42" class="ea-arc" id="ea-arc"' +
              ' stroke-dasharray="263.9" stroke-dashoffset="263.9"/>' +
          '</svg>' +
          '<span class="env-pc" id="env-pc">0<i>%</i></span>' +
        '</div>' +
        '<strong id="env-titre">Envoi en cours…</strong>' +
        '<span id="env-detail">' + (poids ? poidsLisible(0) + ' sur ' + poidsLisible(poids) : 'Préparation') + '</span>' +
        '<div class="env-jauge"><span id="env-j"></span></div>' +
      '</div>';
    document.body.appendChild(barre);
    requestAnimationFrame(function () { barre.classList.add('ouvert'); });
  }

  function majProgression(charge, total) {
    if (!barre) return;
    var pc = total > 0 ? Math.min(100, Math.round(charge / total * 100)) : 0;
    var arc = barre.querySelector('#ea-arc');
    if (arc) arc.style.strokeDashoffset = 263.9 - (263.9 * pc / 100);
    var t = barre.querySelector('#env-pc');
    if (t) t.innerHTML = pc + '<i>%</i>';
    var j = barre.querySelector('#env-j');
    if (j) j.style.width = pc + '%';
    var d = barre.querySelector('#env-detail');
    if (d && total > 0) d.textContent = poidsLisible(charge) + ' sur ' + poidsLisible(total);
    if (pc >= 100) {
      var ti = barre.querySelector('#env-titre');
      if (ti) ti.textContent = 'Traitement par le serveur…';
      if (d) d.textContent = 'Encore un instant';
    }
  }

  function fermerProgression() {
    if (!barre) return;
    var b = barre; barre = null;
    b.classList.remove('ouvert');
    setTimeout(function () { if (b.parentNode) b.remove(); }, 250);
  }

  function poidsLisible(o) {
    if (o >= 1048576) return (o / 1048576).toFixed(1).replace('.0', '') + ' Mo';
    if (o >= 1024) return Math.round(o / 1024) + ' Ko';
    return o + ' o';
  }

  function poidsFormulaire(f) {
    var total = 0;
    f.querySelectorAll('input[type="file"]').forEach(function (c) {
      Array.prototype.forEach.call(c.files || [], function (fi) { total += fi.size; });
    });
    return total;
  }

  /* Un envoi de fichiers passe par une requête suivie pas à pas, pour montrer
     la progression. Le résultat du serveur remplace ensuite la page, comme
     l'aurait fait un envoi classique. */
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.enctype !== 'multipart/form-data') return;
    if (f.dataset.sansProgression === '1') return;
    if (f.getAttribute('data-confirm') && f.dataset.cfmOk !== '1') return;

    var poids = poidsFormulaire(f);
    if (poids < 300 * 1024) return;          // trop petit : l'envoi est instantané
    if (!window.XMLHttpRequest || !window.FormData) return;

    e.preventDefault();
    var donnees = new FormData(f);
    if (f.__cfmBouton && f.__cfmBouton.name) {
      donnees.append(f.__cfmBouton.name, f.__cfmBouton.value);
    }

    ouvrirProgression(poids);
    var x = new XMLHttpRequest();
    x.open(f.method || 'POST', f.action || window.location.href, true);
    x.upload.addEventListener('progress', function (ev) {
      if (ev.lengthComputable) majProgression(ev.loaded, ev.total);
    });
    x.addEventListener('load', function () {
      fermerProgression();
      /* Le serveur redirige normalement après enregistrement : on suit. */
      if (x.responseURL && x.responseURL !== window.location.href) {
        window.location.href = x.responseURL;
      } else {
        window.location.reload();
      }
    });
    x.addEventListener('error', function () {
      fermerProgression();
      demanderConfirmation({
        titre: "L'envoi a échoué.",
        texte: 'Vérifiez votre connexion, puis réessayez.',
        action: 'Réessayer', icone: '⚠️'
      }, function () { f.submit(); });
    });
    x.send(donnees);
  });

  /* Accessible depuis les pages qui en ont besoin. */
  window.confirmerAction = demanderConfirmation;
})();
