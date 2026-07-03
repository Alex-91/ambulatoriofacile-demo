self.addEventListener('install', event => {
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(self.clients.claim());
});

function normalizeNotificationValue(value) {
  if (typeof value === 'string' || typeof value === 'number') {
    return String(value).trim();
  }

  return '';
}

function clientHasAppMarker(clientUrl) {
  try {
    return new URL(clientUrl).searchParams.get('app') === '1';
  } catch (e) {
    return false;
  }
}

function clientMatchesPreferredMode(clientUrl, preferredMode) {
  if (preferredMode === 'standalone') {
    return clientHasAppMarker(clientUrl);
  }

  if (preferredMode === 'browser') {
    return !clientHasAppMarker(clientUrl);
  }

  return true;
}

function buildNotificationTargetUrl(rawUrl, preferredMode, scopeBase) {
  const targetUrl = new URL(rawUrl || 'auth', scopeBase);

  if (preferredMode === 'standalone' && targetUrl.searchParams.get('app') !== '1') {
    targetUrl.searchParams.set('app', '1');
  }

  return targetUrl;
}

function isSameOriginClient(clientUrl, targetUrl) {
  try {
    return new URL(clientUrl).origin === targetUrl.origin;
  } catch (e) {
    return false;
  }
}

function sameOriginPath(clientUrl, targetUrl) {
  try {
    const client = new URL(clientUrl);
    return client.origin === targetUrl.origin && client.pathname === targetUrl.pathname;
  } catch (e) {
    return false;
  }
}

function getClientPath(clientUrl) {
  try {
    return new URL(clientUrl).pathname;
  } catch (e) {
    return '';
  }
}

function normalizeClientPath(path) {
  const normalized = String(path || '').replace(/\/+$/, '');

  return normalized !== '' ? normalized : '/';
}

function isAgendaLikePath(path) {
  const normalizedPath = normalizeClientPath(path);

  return normalizedPath.endsWith('/agenda')
    || normalizedPath.endsWith('/spazio/agenda')
    || normalizedPath.endsWith('/login/spazio/agenda');
}

function isLoginLikeClient(clientUrl) {
  const path = normalizeClientPath(getClientPath(clientUrl));

  return path.endsWith('/login')
    || path.endsWith('/auth')
    || path.includes('/login/')
    || path.includes('/auth/');
}

function selectBestNotificationClient(clientList, targetUrl, preferredMode) {
  let selectedClient = null;
  let selectedScore = Number.NEGATIVE_INFINITY;
  const targetPath = targetUrl.pathname;
  const targetIsAgendaLike = isAgendaLikePath(targetPath);

  for (const client of clientList) {
    if (!isSameOriginClient(client.url, targetUrl)) {
      continue;
    }

    let score = 0;
    if (sameOriginPath(client.url, targetUrl)) {
      score += 500;
    }

    if (clientMatchesPreferredMode(client.url, preferredMode)) {
      score += 200;
    }

    if (targetIsAgendaLike && isAgendaLikePath(getClientPath(client.url))) {
      score += 120;
    }

    if (clientHasAppMarker(client.url)) {
      score += 25;
    }

    if (isLoginLikeClient(client.url)) {
      score -= 150;
    }

    if (score > selectedScore) {
      selectedScore = score;
      selectedClient = client;
    }
  }

  return selectedClient;
}

async function navigateClientToTarget(client, targetUrl) {
  let activeClient = client;

  if (typeof activeClient.navigate === 'function') {
    try {
      const navigatedClient = await activeClient.navigate(targetUrl.href);
      if (navigatedClient) {
        activeClient = navigatedClient;
      }
    } catch (e) {}
  }

  if ('focus' in activeClient) {
    try {
      await activeClient.focus();
    } catch (e) {}
  }

  return activeClient;
}

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

  const data = event.notification?.data || {};
  const preferredClientMode = normalizeNotificationValue(data.clientMode).toLowerCase();
  const targetUrl = buildNotificationTargetUrl(
    data.url || new URL('auth', scopeBase).href,
    preferredClientMode,
    scopeBase
  );

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async clientList => {
      const selectedClient = selectBestNotificationClient(
        clientList,
        targetUrl,
        preferredClientMode
      );

      if (selectedClient) {
        return navigateClientToTarget(selectedClient, targetUrl);
      }

      if (clients.openWindow) {
        return clients.openWindow(targetUrl.href);
      }
    })
  );
});
