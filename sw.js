self.addEventListener('install', event => {
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('push', event => {
  let payload = {};
  const scopeBase = new URL('./', self.registration.scope);

  try {
    payload = event.data ? event.data.json() : {};
  } catch (e) {
    payload = {};
  }

  const title = payload.title || 'AmbulatorioFacile';

  const options = {
    body: payload.body || 'Hai una nuova notifica',
    icon: payload.icon || new URL('public/assets/images/icon-192x192.png', scopeBase).href,
    badge: payload.badge || new URL('public/assets/images/icon-192x192.png', scopeBase).href,
    tag: payload.tag || ('ambulatoriofacile-' + Math.random().toString(36)),
    requireInteraction: payload.requireInteraction === true,
    renotify: payload.renotify === true,
    silent: payload.silent === true,
    data: payload.data || {},
    actions: [
      {
        action: 'open',
        title: 'Apri'
      },
      {
        action: 'close',
        title: 'Chiudi'
      }
    ]
  };

  event.waitUntil(
    self.registration.showNotification(title, options)
  );
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const scopeBase = new URL('./', self.registration.scope);

  if (event.action === 'close') {
    return;
  }

  const url = event.notification?.data?.url || new URL('auth', scopeBase).href;

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientList => {
      for (const client of clientList) {
        try {
          const clientUrl = new URL(client.url);
          const targetUrl = new URL(url, self.location.origin);

          if (clientUrl.origin === targetUrl.origin) {
            if ('focus' in client) {
              client.navigate(targetUrl.href);
              return client.focus();
            }
          }
        } catch (e) {}
      }

      if (clients.openWindow) {
        return clients.openWindow(url);
      }
    })
  );
});
