/* ============================================================================
   SERVICE WORKER — permet l'installation de l'application (PWA)
   et un fonctionnement de base même avec une connexion instable.
   ============================================================================ */
const CACHE = 'helisce-v1';

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
    // Pages dynamiques : réseau d'abord, repli sur cache si hors-ligne.
    e.respondWith(
      fetch(req).catch(() => caches.match(req))
    );
  }
});
