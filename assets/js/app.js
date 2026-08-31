/* ===== Onglets du menu ===== */
const tabs = document.querySelectorAll('.menu-tab');
const panels = document.querySelectorAll('.menu-panel');
tabs.forEach(tab => tab.addEventListener('click', () => {
  tabs.forEach(t => t.classList.remove('active'));
  tab.classList.add('active');
  panels.forEach(p => p.hidden = p.dataset.panel !== tab.dataset.cat);
}));

/* ===== Apparition au scroll ===== */
const io = new IntersectionObserver(entries => {
  entries.forEach(en => { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } });
}, { threshold: 0.12 });
document.querySelectorAll('.reveal').forEach(el => io.observe(el));

/* ===== Lien actif (topnav + tab bar) selon la section visible ===== */
const links = [...document.querySelectorAll('.topnav a, .tabbar a')];
const sections = [...document.querySelectorAll('section[id]')];
const spy = new IntersectionObserver(entries => {
  entries.forEach(en => {
    if (en.isIntersecting) {
      links.forEach(l => l.classList.toggle('active', l.getAttribute('href') === '#' + en.target.id));
    }
  });
}, { rootMargin: '-45% 0px -50% 0px' });
sections.forEach(s => spy.observe(s));

/* ===== Après envoi d'un formulaire, revenir sur la section devis ===== */
if (location.hash === '#devis') {
  document.getElementById('devis')?.scrollIntoView();
}

/* ===== Soumission d'un avis (AJAX) ===== */
const avisForm = document.getElementById('avisForm');
if (avisForm) {
  avisForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const msg = document.getElementById('avisMsg');
    const btn = avisForm.querySelector('button[type=submit]');
    const data = new FormData(avisForm);
    data.append('csrf', window.CSRF_TOKEN || '');
    btn.disabled = true; msg.textContent = 'Envoi…'; msg.style.color = 'var(--ink-dim)';
    try {
      const r = await fetch('api/temoignage.php', { method: 'POST', body: data });
      const j = await r.json();
      msg.textContent = j.msg;
      msg.style.color = j.ok ? 'var(--teal, #3edbc1)' : 'var(--rose, #e57373)';
      if (j.ok) avisForm.reset();
    } catch (err) {
      msg.textContent = 'Erreur réseau, réessayez.'; msg.style.color = 'var(--rose,#e57373)';
    } finally { btn.disabled = false; }
  });
}

/* Ferme le panneau de notifications quand on clique ailleurs */
document.addEventListener('click', function (e) {
  document.querySelectorAll('.notif-wrap.open').forEach(function (w) {
    if (!w.contains(e.target)) w.classList.remove('open');
  });
});

/* ============================================================
   SÉLECTEUR D'ICÔNES
   Un clic sur l'aperçu ou sur « Choisir… » ouvre la palette.
   ============================================================ */
(function () {
  document.addEventListener('click', function (e) {
    var ouvrir = e.target.closest('.ci-ouvrir, .ci-apercu');
    if (ouvrir) {
      var bloc = ouvrir.closest('.champ-icone');
      var pal = bloc.querySelector('.ci-palette');
      pal.hidden = !pal.hidden;
      return;
    }

    var item = e.target.closest('.ci-item');
    if (item) {
      var b = item.closest('.champ-icone');
      var v = b.querySelector('.ci-valeur');
      var a = b.querySelector('.ci-apercu');
      v.value = item.textContent.trim();
      a.textContent = item.textContent.trim();
      b.querySelectorAll('.ci-item').forEach(function (x) { x.classList.remove('ci-choisie'); });
      item.classList.add('ci-choisie');
      b.querySelector('.ci-palette').hidden = true;
      return;
    }

    // Clic à l'extérieur : on referme les palettes ouvertes
    if (!e.target.closest('.champ-icone')) {
      document.querySelectorAll('.ci-palette').forEach(function (p) { p.hidden = true; });
    }
  });

  // Saisie manuelle : l'aperçu suit
  document.addEventListener('input', function (e) {
    if (e.target.classList && e.target.classList.contains('ci-valeur')) {
      var a = e.target.closest('.champ-icone').querySelector('.ci-apercu');
      if (a) a.textContent = e.target.value || '·';
    }
  });
})();
