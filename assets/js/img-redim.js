/* Aperçu + redimensionnement + détourage optionnel côté navigateur (sans API, sans GD).
   <input type="file" data-redim="LxH" [data-redim-mode] [data-redim-cut]>
   data-redim-cut : ajoute une case « Détourer le fond » (fond uni clair -> transparent). */
(function () {
  'use strict';

  function parseDims(input) {
    var d = (input.getAttribute('data-redim') || '').split('x');
    var w = parseInt(d[0], 10), h = parseInt(d[1], 10);
    if (!w || !h) return null;
    return { w: w, h: h, mode: input.getAttribute('data-redim-mode') || 'cover',
             cut: input.hasAttribute('data-redim-cut') };
  }

  /* Détourage : rend transparents les pixels proches de la couleur du fond (coins). */
  function detourer(ctx, w, h, tolerance) {
    var im = ctx.getImageData(0, 0, w, h), d = im.data;
    // Couleur de fond = moyenne des 4 coins
    function px(x, y) { var i = (y * w + x) * 4; return [d[i], d[i+1], d[i+2]]; }
    var coins = [px(0,0), px(w-1,0), px(0,h-1), px(w-1,h-1)];
    var bg = [0,0,0];
    coins.forEach(function(c){ bg[0]+=c[0]; bg[1]+=c[1]; bg[2]+=c[2]; });
    bg = [bg[0]/4, bg[1]/4, bg[2]/4];
    // Ne détoure que si le fond est clair et uni (sinon on risque d'abîmer la photo)
    var clair = (bg[0]+bg[1]+bg[2])/3 > 200;
    if (!clair) return false;
    var tol = tolerance || 42;
    for (var i = 0; i < d.length; i += 4) {
      var dr = d[i]-bg[0], dg = d[i+1]-bg[1], db = d[i+2]-bg[2];
      var dist = Math.sqrt(dr*dr + dg*dg + db*db);
      if (dist < tol) {
        d[i+3] = 0; // transparent
      } else if (dist < tol * 1.7) {
        d[i+3] = Math.round(255 * (dist - tol) / (tol * 0.7)); // bord adouci
      }
    }
    ctx.putImageData(im, 0, 0);
    return true;
  }

  function setup(input) {
    var dims = parseDims(input);
    if (!dims) return;

    var box = document.createElement('div');
    box.className = 'redim-preview';
    var cutHtml = dims.cut
      ? '<label class="redim-cut"><input type="checkbox" class="redim-cut-cb"> Détourer le fond (transparent)</label>'
      : '';
    box.innerHTML =
      '<div class="redim-frame"><span class="redim-hint">Aperçu · ' + dims.w + '×' + dims.h + ' px</span></div>' +
      '<div class="redim-side"><div class="redim-note"></div>' + cutHtml + '</div>';
    input.parentNode.insertBefore(box, input.nextSibling);
    var frame = box.querySelector('.redim-frame');
    var note = box.querySelector('.redim-note');
    var cutCb = box.querySelector('.redim-cut-cb');
    frame.style.aspectRatio = dims.w + ' / ' + dims.h;
    frame.classList.add('chk'); // fond damier pour voir la transparence

    var form = input.closest('form');
    var submitBtn = form ? form.querySelector('button[type="submit"], button:not([type]), input[type="submit"]') : null;
    input.__redimReady = true;
    input.__lastImg = null;

    function rendre() {
      var img = input.__lastImg;
      if (!img) return;
      input.__redimReady = false;
      note.textContent = 'Préparation de l\'image…';

      var canvas = document.createElement('canvas');
      canvas.width = dims.w; canvas.height = dims.h;
      var ctx = canvas.getContext('2d');
      ctx.clearRect(0, 0, dims.w, dims.h);
      var sw = img.width, sh = img.height, ratio, nw, nh, dx, dy, sx, sy, cw, ch;
      if (dims.mode === 'contain') {
        ratio = Math.min(dims.w / sw, dims.h / sh);
        nw = Math.round(sw * ratio); nh = Math.round(sh * ratio);
        dx = Math.round((dims.w - nw) / 2); dy = Math.round((dims.h - nh) / 2);
        ctx.drawImage(img, 0, 0, sw, sh, dx, dy, nw, nh);
      } else {
        ratio = Math.max(dims.w / sw, dims.h / sh);
        cw = Math.round(dims.w / ratio); ch = Math.round(dims.h / ratio);
        sx = Math.round((sw - cw) / 2); sy = Math.round((sh - ch) / 2);
        ctx.drawImage(img, sx, sy, cw, ch, 0, 0, dims.w, dims.h);
      }

      var detoure = false;
      if (cutCb && cutCb.checked) { detoure = detourer(ctx, dims.w, dims.h); }

      frame.innerHTML = '';
      var preview = new Image();
      preview.src = canvas.toDataURL('image/png');
      preview.className = 'redim-img';
      frame.appendChild(preview);

      function finalise(blob) {
        var remplace = false;
        if (blob) {
          try {
            var dt = new DataTransfer();
            var base = (input.__fileName || 'image').replace(/\.[^.]+$/, '') || 'image';
            dt.items.add(new File([blob], base + '.png', { type: 'image/png' }));
            input.files = dt.files; remplace = true;
          } catch (err) { remplace = false; }
        }
        input.__redimReady = true;
        var etat = '<strong>' + dims.w + '×' + dims.h + ' px (PNG' + (detoure ? ' transparent' : '') + ')</strong>';
        if (cutCb && cutCb.checked && !detoure) {
          note.innerHTML = '⚠ Fond non détouré (il n\'est pas assez uni/clair). Image en ' + etat + '.';
        } else {
          note.innerHTML = (remplace ? '✓ Image prête : ' : '✓ L\'image sera enregistrée en ') + etat + '.';
        }
        if (submitBtn) {
          if (submitBtn.tagName === 'INPUT') submitBtn.value = 'Redimensionner et enregistrer';
          else submitBtn.textContent = 'Redimensionner et enregistrer';
        }
      }
      if (canvas.toBlob) { canvas.toBlob(finalise, 'image/png'); }
      else {
        try {
          var bin = atob(canvas.toDataURL('image/png').split(',')[1]);
          var arr = new Uint8Array(bin.length);
          for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
          finalise(new Blob([arr], { type: 'image/png' }));
        } catch (e2) { finalise(null); }
      }
    }

    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      if (!file) { frame.innerHTML = '<span class="redim-hint">Aperçu · ' + dims.w + '×' + dims.h + ' px</span>'; note.textContent = ''; input.__lastImg = null; input.__redimReady = true; return; }
      if (!/^image\//.test(file.type)) { note.innerHTML = '<span style="color:#e57373">Ce fichier n\'est pas une image.</span>'; return; }
      input.__fileName = file.name;
      var reader = new FileReader();
      reader.onload = function (e) {
        var img = new Image();
        img.onload = function () { input.__lastImg = img; rendre(); };
        img.onerror = function () { input.__redimReady = true; note.innerHTML = '<span style="color:#e57373">Image illisible.</span>'; };
        img.src = e.target.result;
      };
      reader.readAsDataURL(file);
    });

    if (cutCb) cutCb.addEventListener('change', rendre);

    if (form) {
      form.addEventListener('submit', function (ev) {
        if (input.files && input.files.length && input.__redimReady === false) {
          ev.preventDefault();
          note.textContent = 'Un instant, l\'image finit d\'être préparée…';
          var t = setInterval(function () { if (input.__redimReady) { clearInterval(t); form.submit(); } }, 120);
        }
      });
    }
  }

  function init() { document.querySelectorAll('input[type="file"][data-redim]').forEach(setup); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
