/* ============================================================================
   CALCULATRICE SCIENTIFIQUE — Groupe Helisce
   ============================================================================
   Une calculatrice de bureau complète, dans l'esprit des fx-991 : deux lignes
   d'écran (l'expression en haut, le résultat en bas), second jeu de touches
   par « Maj », degrés ou radians, mémoire et historique.

   Le calcul ne passe JAMAIS par « eval ». Une expression saisie par un
   utilisateur est une donnée, pas du code : la confier à l'interpréteur du
   navigateur reviendrait à lui laisser exécuter ce qu'il veut dans la page,
   avec la session ouverte. L'expression est donc découpée en éléments
   (tokenisation) puis lue par un analyseur descendant écrit ici même, qui ne
   sait faire qu'une chose : des mathématiques.
   ========================================================================= */
(function () {
  'use strict';

  /* ==========================================================================
     1. LE MOTEUR
     ====================================================================== */

  /* Découpage en éléments. Une erreur ici remonte telle quelle à l'écran. */
  function decouper(src) {
    var t = [], i = 0, n = src.length;
    var fonctions = ['asinh','acosh','atanh','sinh','cosh','tanh','asin','acos','atan',
                     'sin','cos','tan','log','ln','abs','exp','sqrt','cbrt'];

    while (i < n) {
      var c = src[i];

      if (c === ' ') { i++; continue; }

      /* Nombre : chiffres, séparateur décimal, notation scientifique (1.2e-3) */
      if (/[0-9.]/.test(c)) {
        var j = i, vu = false;
        while (j < n && /[0-9.]/.test(src[j])) { if (src[j] === '.') { if (vu) break; vu = true; } j++; }
        if (j < n && (src[j] === 'E')) {           /* exposant : 1.2E5, 1.2E-5 */
          var k = j + 1;
          if (k < n && (src[k] === '-' || src[k] === '+')) k++;
          if (k < n && /[0-9]/.test(src[k])) { while (k < n && /[0-9]/.test(src[k])) k++; j = k; }
        }
        var brut = src.slice(i, j);
        if (!/^\d*\.?\d*(E[-+]?\d+)?$/.test(brut) || brut === '.' || brut === '')
          throw new Error('Nombre mal écrit');
        t.push({ t: 'nb', v: parseFloat(brut) });
        i = j; continue;
      }

      if (c === 'π') { t.push({ t: 'nb', v: Math.PI }); i++; continue; }
      if (c === 'e' && !/[a-z]/.test(src[i + 1] || '')) { t.push({ t: 'nb', v: Math.E }); i++; continue; }

      /* Une fonction se reconnaît à son nom, le plus long d'abord : « asin »
         avant « sin », sinon « asin(… » se lirait « a » puis « sin ». */
      var trouve = null;
      for (var f = 0; f < fonctions.length; f++) {
        if (src.startsWith(fonctions[f], i)) { trouve = fonctions[f]; break; }
      }
      if (trouve) { t.push({ t: 'fn', v: trouve }); i += trouve.length; continue; }

      if (c === '√') { t.push({ t: 'fn', v: 'sqrt' }); i++; continue; }
      if (c === '∛') { t.push({ t: 'fn', v: 'cbrt' }); i++; continue; }

      if ('+-×÷*/^('.indexOf(c) >= 0 || c === ')' || c === '!' || c === '%') {
        var op = c === '×' ? '*' : (c === '÷' ? '/' : c);
        t.push({ t: op === '(' || op === ')' ? 'par' : 'op', v: op });
        i++; continue;
      }

      throw new Error('Caractère inattendu : ' + c);
    }
    return t;
  }

  /* Analyseur descendant. Priorités, de la plus faible à la plus forte :
     + −  |  × ÷  |  moins unaire  |  puissance  |  ! et %  |  atome        */
  function evaluer(src, degres) {
    var t = decouper(src), p = 0;

    function fin()      { return p >= t.length; }
    function tete()     { return t[p]; }
    function estOp(v)   { return !fin() && tete().t === 'op'  && tete().v === v; }
    function estPar(v)  { return !fin() && tete().t === 'par' && tete().v === v; }

    /* Le contenu d'un sinus est en degrés ou en radians selon le mode ; les
       fonctions inverses répondent dans la même unité. */
    var versRad = function (x) { return degres ? x * Math.PI / 180 : x; };
    var depuisRad = function (x) { return degres ? x * 180 / Math.PI : x; };

    function factorielle(x) {
      if (x < 0 || Math.abs(x - Math.round(x)) > 1e-9) throw new Error('Factorielle : entier positif attendu');
      x = Math.round(x);
      if (x > 170) throw new Error('Trop grand');
      var r = 1; for (var k = 2; k <= x; k++) r *= k; return r;
    }

    var FN = {
      sin:  function (x) { return Math.sin(versRad(x)); },
      cos:  function (x) { return Math.cos(versRad(x)); },
      tan:  function (x) { return Math.tan(versRad(x)); },
      asin: function (x) { if (x < -1 || x > 1) throw new Error('Hors domaine'); return depuisRad(Math.asin(x)); },
      acos: function (x) { if (x < -1 || x > 1) throw new Error('Hors domaine'); return depuisRad(Math.acos(x)); },
      atan: function (x) { return depuisRad(Math.atan(x)); },
      sinh: Math.sinh, cosh: Math.cosh, tanh: Math.tanh,
      asinh: Math.asinh, acosh: Math.acosh, atanh: Math.atanh,
      ln:   function (x) { if (x <= 0) throw new Error('Logarithme : nombre positif attendu'); return Math.log(x); },
      log:  function (x) { if (x <= 0) throw new Error('Logarithme : nombre positif attendu'); return Math.log10(x); },
      exp:  Math.exp,
      abs:  Math.abs,
      sqrt: function (x) { if (x < 0) throw new Error('Racine d’un nombre négatif'); return Math.sqrt(x); },
      cbrt: Math.cbrt
    };

    function atome() {
      if (fin()) throw new Error('Expression incomplète');
      var j = tete();

      if (j.t === 'nb')  { p++; return j.v; }
      if (j.t === 'fn')  {
        p++;
        /* « sin 30 » est accepté comme « sin(30) » : la parenthèse est une
           commodité d'écriture, pas une obligation. L'argument s'arrête avant
           la puissance, pour que « sin(30)² » se lise « (sin 30)² » — ce que
           l'on veut dire en l'écrivant, et ce que fait une fx-991. */
        var arg = argFonction();
        var f = FN[j.v]; if (!f) throw new Error('Fonction inconnue');
        return f(arg);
      }
      if (j.t === 'par' && j.v === '(') {
        p++;
        var v = somme();
        if (!estPar(')')) throw new Error('Parenthèse non fermée');
        p++;
        return v;
      }
      throw new Error('Expression incomplète');
    }

    /* L'argument d'une fonction accepte un signe — « √-4 » doit répondre
       « racine d'un nombre négatif » et non « expression incomplète » — mais
       s'arrête avant la puissance, voir plus haut. */
    function argFonction() {
      if (estOp('-')) { p++; return -argFonction(); }
      if (estOp('+')) { p++; return  argFonction(); }
      return suffixe();
    }

    /* Suffixes : 5! , 20% — ils se cumulent : 5!% vaut 1,2. */
    function suffixe() {
      var v = atome();
      while (!fin() && tete().t === 'op' && (tete().v === '!' || tete().v === '%')) {
        v = tete().v === '!' ? factorielle(v) : v / 100;
        p++;
      }
      return v;
    }

    /* La puissance s'associe à droite : 2^3^2 = 2^(3^2) = 512. */
    function puissance() {
      var base = suffixe();
      if (estOp('^')) { p++; return Math.pow(base, unaire()); }
      return base;
    }

    function unaire() {
      if (estOp('-')) { p++; return -unaire(); }
      if (estOp('+')) { p++; return unaire(); }
      return puissance();
    }

    function produit() {
      var v = unaire();
      for (;;) {
        if (estOp('*')) { p++; v *= unaire(); continue; }
        if (estOp('/')) {
          p++;
          var d = unaire();
          if (d === 0) throw new Error('Division par zéro');
          v /= d; continue;
        }
        /* Multiplication implicite : 2π, 3(4+5), 2sin30 — exactement ce que
           l'on écrit à la main, et ce que fait une fx-991. */
        if (!fin() && (tete().t === 'nb' || tete().t === 'fn' || estPar('('))) { v *= unaire(); continue; }
        return v;
      }
    }

    function somme() {
      var v = produit();
      for (;;) {
        if (estOp('+')) { p++; v += produit(); continue; }
        if (estOp('-')) { p++; v -= produit(); continue; }
        return v;
      }
    }

    var r = somme();
    if (!fin()) throw new Error('Expression mal formée');
    if (!isFinite(r)) throw new Error(isNaN(r) ? 'Résultat indéfini' : 'Résultat hors limites');
    return r;
  }

  /* Affichage d'un résultat : assez de décimales pour être juste, pas assez
     pour être illisible. Les très grands et très petits nombres passent en
     notation scientifique, comme sur une vraie calculatrice. */
  function formater(x) {
    if (x === 0) return '0';
    var abs = Math.abs(x);
    if (abs >= 1e12 || abs < 1e-9) return x.toExponential(8).replace(/\.?0+e/, 'e');
    var s = parseFloat(x.toPrecision(12)).toString();
    /* Séparateur de milliers, en espace fine insécable — la convention
       française, et celle des documents de la maison. */
    var parties = s.split('.');
    parties[0] = parties[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return parties.join(',');
  }

  /* ==========================================================================
     2. L'INTERFACE
     ====================================================================== */
  function demarrer() {
    var fen = document.getElementById('calcFenetre');
    if (!fen) return;

    var ecranExpr = fen.querySelector('.cal-expr');
    var ecranRes  = fen.querySelector('.cal-res');
    var ecranInd  = fen.querySelector('.cal-ind');
    var histBoite = fen.querySelector('.cal-hist');

    var expr = '', dernier = 0, memoire = 0, degres = true, maj = false, historique = [];

    /* Après un « = », la ligne du haut n'affiche plus l'expression (elle est
       vide, on repart de zéro) mais le résultat suivi de « = », comme sur une
       machine. Ce libellé provisoire tient jusqu'à la frappe suivante. */
    var libelle = null;

    function majEcran(res, erreur) {
      ecranExpr.textContent = libelle !== null ? libelle : (expr || '0');
      if (res !== undefined) {
        ecranRes.textContent = res;
        ecranRes.classList.toggle('est-erreur', !!erreur);
      }
      ecranInd.innerHTML =
        '<span class="' + (degres ? 'on' : '') + '">DEG</span>' +
        '<span class="' + (degres ? '' : 'on') + '">RAD</span>' +
        (memoire !== 0 ? '<span class="on">M</span>' : '<span>M</span>') +
        (maj ? '<span class="on maj">MAJ</span>' : '');
      fen.classList.toggle('est-maj', maj);
      /* L'écran suit la saisie : on lit toujours la fin de l'expression. */
      ecranExpr.scrollLeft = ecranExpr.scrollWidth;
    }

    /* Aperçu en direct : le résultat s'affiche pendant la frappe, en gris,
       et ne devient définitif qu'au « = ». Une expression incomplète ne
       provoque aucun message : on est en train de l'écrire. */
    function apercu() {
      libelle = null;
      if (!expr.trim()) { ecranRes.textContent = '0'; ecranRes.classList.remove('est-erreur'); majEcran(); return; }
      try {
        var v = evaluer(expr, degres);
        ecranRes.textContent = formater(v);
        ecranRes.classList.remove('est-erreur', 'est-partiel');
      } catch (err) {
        /* En cours d'écriture, on ne crie pas à l'erreur : on se contente de
           pâlir le résultat, qui ne correspond plus à ce qui est écrit. */
        ecranRes.classList.add('est-partiel');
      }
      majEcran();
    }

    function ajouter(txt) { expr += txt; apercu(); }

    function egale() {
      if (!expr.trim()) return;
      try {
        var v = evaluer(expr, degres);
        dernier = v;
        var ligne = formater(v);
        ecranRes.textContent = ligne;
        ecranRes.classList.remove('est-erreur', 'est-partiel');
        historique.unshift({ q: expr, r: ligne });
        if (historique.length > 40) historique.pop();
        dessinerHistorique();
        expr = '';
        libelle = ligne + ' =';
        fen.classList.add('a-repondu');
        setTimeout(function () { fen.classList.remove('a-repondu'); }, 220);
      } catch (err) {
        ecranRes.textContent = err.message;
        ecranRes.classList.add('est-erreur');
      }
      majEcran(undefined);
    }

    function dessinerHistorique() {
      if (!historique.length) {
        histBoite.innerHTML = '<p class="cal-hist-vide">Vos calculs apparaîtront ici. Touchez-en un pour le réutiliser.</p>';
        return;
      }
      histBoite.innerHTML = historique.map(function (h, i) {
        return '<button type="button" class="cal-h" data-i="' + i + '">' +
               '<span class="ch-q">' + h.q.replace(/[<>&]/g, '') + '</span>' +
               '<span class="ch-r">= ' + h.r + '</span></button>';
      }).join('');
    }
    dessinerHistorique();

    histBoite.addEventListener('click', function (ev) {
      var b = ev.target.closest('.cal-h'); if (!b) return;
      expr += historique[+b.dataset.i].r.replace(/ /g, '').replace(',', '.');
      apercu();
    });

    /* Chaque touche porte son action : « ins » insère, « act » commande. */
    fen.addEventListener('click', function (ev) {
      var b = ev.target.closest('.cal-t'); if (!b) return;

      /* En mode Maj, la touche joue sa seconde fonction puis Maj retombe —
         comme sur une vraie machine, où il faut le réappuyer à chaque fois. */
      var ins = maj && b.dataset.ins2 !== undefined ? b.dataset.ins2 : b.dataset.ins;
      var act = maj && b.dataset.act2 !== undefined ? b.dataset.act2 : b.dataset.act;
      if (maj && b.dataset.act !== 'maj') { maj = false; }

      if (ins !== undefined) { ajouter(ins); return; }
      if (act === undefined) { majEcran(); return; }

      switch (act) {
        case 'maj':   maj = !maj; majEcran(); break;
        case 'ac':    expr = ''; libelle = null; ecranRes.textContent = '0';
                      ecranRes.classList.remove('est-erreur', 'est-partiel'); majEcran(); break;
        case 'del':   expr = expr.slice(0, -1); apercu(); break;
        case 'egale': egale(); break;
        case 'signe': expr = expr.trim().startsWith('-') ? expr.replace(/^\s*-/, '') : '-' + expr; apercu(); break;
        case 'deg':   degres = !degres; apercu(); break;
        case 'ans':   ajouter(String(dernier)); break;
        case 'mplus': try { memoire += evaluer(expr || '0', degres); } catch (e) {} majEcran(); break;
        case 'mmoins':try { memoire -= evaluer(expr || '0', degres); } catch (e) {} majEcran(); break;
        case 'mr':    ajouter(String(memoire)); break;
        case 'mc':    memoire = 0; majEcran(); break;
        case 'copier':
          var v = ecranRes.textContent;
          if (navigator.clipboard) navigator.clipboard.writeText(v.replace(/ /g, '').replace(',', '.'));
          b.classList.add('copie'); setTimeout(function () { b.classList.remove('copie'); }, 900);
          break;
        case 'vider': historique = []; dessinerHistorique(); break;
      }
    });

    /* ---- Ouvrir, fermer ---- */
    function ouvrir() {
      fen.hidden = false;
      requestAnimationFrame(function () { fen.classList.add('ouverte'); });
      majEcran();
    }
    function fermer() {
      fen.classList.remove('ouverte');
      setTimeout(function () { fen.hidden = true; }, 180);
    }
    document.querySelectorAll('[data-calc-ouvrir]').forEach(function (b) {
      b.addEventListener('click', function (e) { e.preventDefault(); fen.hidden ? ouvrir() : fermer(); });
    });
    fen.querySelectorAll('[data-calc-fermer]').forEach(function (b) {
      b.addEventListener('click', fermer);
    });

    /* Le clavier de l'ordinateur pilote la machine : on ne va pas obliger
       quelqu'un qui a dix chiffres à saisir à viser des touches à la souris. */
    document.addEventListener('keydown', function (ev) {
      if (fen.hidden) return;
      /* Le clavier ne pilote la machine que si la page n'attend rien d'autre :
         un champ, un bouton ou un lien qui a le focus garde la main, sinon
         « Entrée » enverrait un calcul au lieu de valider le formulaire. */
      var cible = ev.target;
      if (cible && !fen.contains(cible) &&
          (/^(INPUT|TEXTAREA|SELECT|BUTTON|A|SUMMARY)$/.test(cible.tagName) || cible.isContentEditable)) return;

      var k = ev.key;
      if (/^[0-9]$/.test(k))            { ajouter(k); ev.preventDefault(); return; }
      if (k === '.' || k === ',')       { ajouter('.'); ev.preventDefault(); return; }
      if ('+-*/^()%!'.indexOf(k) >= 0)  { ajouter(k === '*' ? '×' : k === '/' ? '÷' : k); ev.preventDefault(); return; }
      if (k === 'Enter' || k === '=')   { egale(); ev.preventDefault(); return; }
      if (k === 'Backspace')            { expr = expr.slice(0, -1); apercu(); ev.preventDefault(); return; }
      if (k === 'Escape')               { fermer(); ev.preventDefault(); return; }
      if (k === 'Delete')               { expr = ''; libelle = null; ecranRes.textContent = '0'; majEcran(); ev.preventDefault(); }
    });

    /* Déplacement à la souris par la barre de titre : la calculatrice ne doit
       pas masquer le tableau qu'on est en train de lire. */
    (function deplacer() {
      var poignee = fen.querySelector('.cal-tete');
      if (!poignee) return;
      var actif = false, dx = 0, dy = 0;
      poignee.addEventListener('pointerdown', function (ev) {
        if (ev.target.closest('button')) return;
        if (window.innerWidth <= 720) return;      /* sur téléphone, elle est centrée */
        actif = true;
        var r = fen.getBoundingClientRect();
        dx = ev.clientX - r.left; dy = ev.clientY - r.top;
        fen.style.transition = 'none';
        poignee.setPointerCapture(ev.pointerId);
      });
      poignee.addEventListener('pointermove', function (ev) {
        if (!actif) return;
        var x = Math.min(Math.max(0, ev.clientX - dx), window.innerWidth  - fen.offsetWidth);
        var y = Math.min(Math.max(0, ev.clientY - dy), window.innerHeight - 60);
        fen.style.left = x + 'px'; fen.style.top = y + 'px';
        fen.style.right = 'auto'; fen.style.bottom = 'auto';
      });
      poignee.addEventListener('pointerup', function () { actif = false; fen.style.transition = ''; });
    })();

    majEcran();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', demarrer);
  else demarrer();
})();
