// ================================================
// LoKy ACC — Service Worker
// Gère la réception des notifications Push
// Ce fichier DOIT être à la racine du site web
// ================================================

const CACHE_NAME = 'loky-v1';

// Installation du service worker
self.addEventListener('install', event => {
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(clients.claim());
});

// ---- Réception d'une notification Push ----
self.addEventListener('push', event => {
  let data = {
    title:   '⚡ LoKy ACC',
    body:    'Surplus disponible !',
    icon:    '/loky_icon.png',
    badge:   '/loky_badge.png',
    tag:     'loky-surplus',
    renotify: true,
    data:    { url: '/loky_api.php' }
  };

  // Lire les données envoyées par le serveur
  if (event.data) {
    try {
      const payload = event.data.json();
      data = { ...data, ...payload };
    } catch(e) {
      data.body = event.data.text();
    }
  }

  event.waitUntil(
    self.registration.showNotification(data.title, {
      body:     data.body,
      icon:     data.icon,
      badge:    data.badge,
      tag:      data.tag,
      renotify: data.renotify,
      vibrate:  [200, 100, 200],
      data:     data.data,
      actions: [
        { action: 'view',    title: '📊 Voir le dashboard' },
        { action: 'dismiss', title: '✕ Ignorer' }
      ]
    })
  );
});

// ---- Clic sur la notification ----
self.addEventListener('notificationclick', event => {
  event.notification.close();

  if (event.action === 'dismiss') return;

  const url = event.notification.data?.url || '/loky_api.php';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then(clientList => {
        // Si le dashboard est déjà ouvert, focus dessus
        for (const client of clientList) {
          if (client.url.includes('loky') && 'focus' in client) {
            return client.focus();
          }
        }
        // Sinon ouvrir un nouvel onglet
        if (clients.openWindow) {
          return clients.openWindow(url);
        }
      })
  );
});
