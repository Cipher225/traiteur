<?php $pwaBase = $pwaBase ?? '.'; ?>
<!-- Bouton d'installation de l'application -->
<button id="pwa-install" class="pwa-install-btn" style="display:none" aria-label="Installer l'application">
  <span class="pwa-install-ico">⬇️</span> Installer l'application
</button>

<!-- Fenêtre d'aide à l'installation (si l'installation automatique n'est pas disponible) -->
<div id="pwa-aide" class="pwa-aide" style="display:none">
  <div class="pwa-aide-carte">
    <button class="pwa-aide-fermer" aria-label="Fermer" onclick="document.getElementById('pwa-aide').style.display='none'">✕</button>
    <div class="pwa-aide-titre">📲 Installer l'application</div>
    <div id="pwa-aide-texte"></div>
  </div>
</div>

<script>
(function () {
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('<?= $pwaBase ?>/service-worker.js').catch(function () {});
    });
  }

  var promptEvt = null;
  var btn = document.getElementById('pwa-install');
  if (!btn) return;

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    promptEvt = e;
    btn.style.display = 'inline-flex';
  });

  var dejaInstallee = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  var ua = window.navigator.userAgent.toLowerCase();
  var estIOS = /iphone|ipad|ipod/.test(ua);

  if (estIOS && !dejaInstallee) { btn.style.display = 'inline-flex'; }

  btn.addEventListener('click', function () {
    if (promptEvt) {
      promptEvt.prompt();
      promptEvt.userChoice.then(function () { promptEvt = null; btn.style.display = 'none'; });
    } else {
      var texte = document.getElementById('pwa-aide-texte');
      if (estIOS) {
        texte.innerHTML = 'Sur iPhone/iPad :<br><br>1. Appuyez sur le bouton <strong>Partager</strong> (en bas de Safari).<br>2. Choisissez <strong>&laquo; Sur l\'ecran d\'accueil &raquo;</strong>.<br>3. Appuyez sur <strong>Ajouter</strong>.<br><br>L\'application apparaitra avec notre logo sur votre ecran.';
      } else if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
        texte.innerHTML = 'L\'installation necessite une connexion securisee (https). Une fois le site en ligne avec son adresse definitive, le bouton d\'installation apparaitra automatiquement.';
      } else {
        texte.innerHTML = 'Pour installer : ouvrez le menu de votre navigateur et choisissez <strong>&laquo; Installer l\'application &raquo;</strong> ou <strong>&laquo; Ajouter a l\'ecran d\'accueil &raquo;</strong>.';
      }
      document.getElementById('pwa-aide').style.display = 'flex';
    }
  });

  window.addEventListener('appinstalled', function () { btn.style.display = 'none'; });
})();
</script>
