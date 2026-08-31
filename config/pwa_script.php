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

  var dejaInstallee = window.matchMedia('(display-mode: standalone)').matches
                   || window.navigator.standalone === true;
  var ua = window.navigator.userAgent.toLowerCase();
  var estIOS = /iphone|ipad|ipod/.test(ua);

  /* L'application a-t-elle déjà été installée depuis cet appareil ?
     On le retient, car une fois installée le navigateur ne propose plus rien,
     et sur iPhone il n'existe aucun moyen de le détecter autrement. */
  function memoireInstallee() {
    try { return localStorage.getItem('app_installee') === '1'; } catch (e) { return false; }
  }
  function marquerInstallee() {
    try { localStorage.setItem('app_installee', '1'); } catch (e) {}
  }

  function masquer() { btn.style.display = 'none'; }

  // Ouverte en tant qu'application : le bouton n'a plus lieu d'être
  if (dejaInstallee) { marquerInstallee(); masquer(); return; }
  if (memoireInstallee()) { masquer(); return; }

  // Chrome/Edge : vérification fiable auprès du système
  if (navigator.getInstalledRelatedApps) {
    navigator.getInstalledRelatedApps().then(function (apps) {
      if (apps && apps.length) { marquerInstallee(); masquer(); }
    }).catch(function () {});
  }

  if (estIOS) { btn.style.display = 'inline-flex'; }

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
      var aide = document.getElementById('pwa-aide');
      if (estIOS && !document.getElementById('pwa-fait')) {
        var f = document.createElement('button');
        f.id = 'pwa-fait';
        f.className = 'pwa-aide-btn';
        f.textContent = "C'est fait, ne plus afficher";
        f.addEventListener('click', function () { marquerInstallee(); masquer(); aide.style.display = 'none'; });
        texte.parentNode.appendChild(f);
      }
      aide.style.display = 'flex';
    }
  });

  window.addEventListener('appinstalled', function () {
    marquerInstallee();
    masquer();
    var aide = document.getElementById('pwa-aide');
    if (aide) aide.style.display = 'none';
  });
})();
</script>
