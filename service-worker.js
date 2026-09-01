/* ============================================================================
   SERVICE WORKER — permet l'installation de l'application (PWA)
   et un fonctionnement de base même avec une connexion instable.
   ============================================================================ */
const CACHE = 'helisce-v2';
const PAGE_HORS_LIGNE = 'hors-ligne.php';

// À l'installation : on active immédiatement.
self.addEventListener('install', (e) => {
  self.skipWaiting();
});

// À l'activation : on nettoie les anciens caches.
self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((cles) =>
      Promise.all(cles.filter((c) => c !== CACHE).map((c) => caches.delete(c)))
    ).then(() => self.clients.claim())
  );
});

/* Stratégie « réseau d'abord » : on privilégie toujours les données à jour
   (l'application est dynamique), avec repli sur le cache si le réseau échoue.
   On ne met en cache que les ressources statiques (CSS, JS, images). */
self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  const estStatique = /\.(css|js|png|jpg|jpeg|webp|svg|woff2?|ico)$/i.test(url.pathname);

  if (estStatique) {
    // Ressources statiques : cache d'abord, mise à jour en arrière-plan.
    e.respondWith(
      caches.match(req).then((cache) => {
        const reseau = fetch(req).then((rep) => {
          if (rep && rep.status === 200) {
            const copie = rep.clone();
            caches.open(CACHE).then((c) => c.put(req, copie));
          }
          return rep;
        }).catch(() => cache);
        return cache || reseau;
      })
    );
  } else {
    /* Pages : réseau d'abord (les données doivent être à jour), mais chaque page
       consultée est conservée. Hors connexion, on réaffiche la dernière version
       vue plutôt qu'une erreur du navigateur. */
    e.respondWith(
      fetch(req).then((rep) => {
        if (rep && rep.status === 200 && rep.type === 'basic') {
          const copie = rep.clone();
          caches.open(CACHE).then((c) => c.put(req, copie));
        }
        return rep;
      }).catch(() =>
        caches.match(req).then((cache) =>
          cache || caches.match(PAGE_HORS_LIGNE).then((p) =>
            p || new Response(
              '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">' +
              '<meta name="viewport" content="width=device-width,initial-scale=1">' +
              '<title>Hors connexion</title><style>body{font-family:system-ui,sans-serif;' +
              'background:#0a1020;color:#eaf0fb;display:flex;align-items:center;' +
              'justify-content:center;height:100vh;margin:0;text-align:center;padding:24px}' +
              'h1{color:#d4a526;font-size:22px}p{color:#a9b7d0;line-height:1.6}</style></head>' +
              '<body><div><h1>Vous êtes hors connexion</h1>' +
              '<p>Cette page n\'a pas encore été consultée.<br>' +
              'Reconnectez-vous pour y accéder.</p></div></body></html>',
              { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
            )
          )
        )
      )
    );
  }
});

/* Nettoyage : on limite le nombre de pages conservées pour ne pas saturer
   l'espace de stockage du téléphone. */
self.addEventListener('message', (e) => {
  if (e.data === 'nettoyer') {
    caches.open(CACHE).then((c) =>
      c.keys().then((cles) => {
        if (cles.length > 60) cles.slice(0, cles.length - 60).forEach((k) => c.delete(k));
      })
    );
  }
});
