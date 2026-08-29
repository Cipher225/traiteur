/* Applique le thème immédiatement (évite le flash).
   Défaut : site public = marine premium (dark) · espace connecté = clair (light) */
(function () {
  try {
    var html = document.documentElement;
    var parDefaut = html.getAttribute('data-space') === 'app' ? 'light' : 'dark';
    var t = localStorage.getItem('helisce-theme-' + (html.getAttribute('data-space') || 'public')) || parDefaut;
    html.setAttribute('data-theme', t);
  } catch (e) {}
})();

/* Bascule clair/sombre (mémorisée séparément pour chaque espace) */
function toggleTheme() {
  var html = document.documentElement;
  var espace = html.getAttribute('data-space') || 'public';
  var next = html.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
  html.setAttribute('data-theme', next);
  try { localStorage.setItem('helisce-theme-' + espace, next); } catch (e) {}
  document.querySelectorAll('[data-theme-icon]').forEach(function (el) {
    el.textContent = next === 'light' ? '🌙' : '☀️';
  });
}
document.addEventListener('DOMContentLoaded', function () {
  var cur = document.documentElement.getAttribute('data-theme');
  document.querySelectorAll('[data-theme-icon]').forEach(function (el) {
    el.textContent = cur === 'light' ? '🌙' : '☀️';
  });
});
