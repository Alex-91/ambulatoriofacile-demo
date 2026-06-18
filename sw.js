self.addEventListener('install', event => {
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('push', event => {
  let data = {};
  const scopeBase = new URL('./', self.registration.scope);

  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = {};
  }

  const title = data.title || 'Ambulatorio Facile';

  const options = {
    body: 'Hai una nuova notifica',
    icon: data.icon || new URL('public/assets/images/icon-192x192.png', scopeBase).href,
    badge: data.badge || new URL('public/assets/images/icon-192x192.png', scopeBase).href,
    tag: data.tag || ('ambulatoricloud-' + Math.random().toString(36)),
    requireInteraction: true,
    renotify: true,
    data: data.data || {},
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
