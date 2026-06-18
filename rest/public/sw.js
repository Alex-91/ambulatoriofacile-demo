self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

const OTP_AUTOFILL_CHANNEL = 'otp-autofill';
const OTP_AUTOFILL_MESSAGE_TYPE = 'otp-autofill';
const OTP_AUTOFILL_CACHE_NAME = 'otp-autofill-cache-v1';
const OTP_AUTOFILL_CACHE_URL = new URL('/__otp-autofill__/pending', self.location.origin).href;
const OTP_AUTOFILL_TTL_MS = 3 * 60 * 1000;

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function normalizeNotificationValue(value) {
  if (typeof value === 'string' || typeof value === 'number') {
    return String(value).trim();
  }

  return '';
}

function buildOtpAutofillMessage(otp, sentAt) {
  const digits = normalizeNotificationValue(otp).replace(/\D/g, '').slice(0, 4);
  if (digits === '') {
    return null;
  }

  return {
    type: OTP_AUTOFILL_MESSAGE_TYPE,
    otp: digits,
    sentAt: sentAt || Date.now(),
  };
}

function extractTargetPath(targetUrl) {
  try {
    return new URL(targetUrl, self.location.origin).pathname;
  } catch (e) {
    return '/';
  }
}

async function cacheOtpAutofillMessage(message, targetUrl) {
  if (!message || typeof caches === 'undefined') {
    return;
  }

  try {
    const cache = await caches.open(OTP_AUTOFILL_CACHE_NAME);
    const payload = {
      ...message,
      targetPath: extractTargetPath(targetUrl),
      expiresAt: Date.now() + OTP_AUTOFILL_TTL_MS,
    };

    await cache.put(OTP_AUTOFILL_CACHE_URL, new Response(JSON.stringify(payload), {
      headers: {
        'Content-Type': 'application/json',
      },
    }));
  } catch (e) {
    // Ignore cache persistence failures and keep URL/message fallbacks active.
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

function broadcastOtpMessage(message) {
  if (typeof BroadcastChannel === 'undefined') {
    return;
  }

  try {
    const channel = new BroadcastChannel(OTP_AUTOFILL_CHANNEL);
    channel.postMessage(message);
    channel.close();
  } catch (e) {
    // Ignore browser-specific BroadcastChannel issues and keep postMessage fallback.
  }
}

async function postOtpMessageToAuthClients(targetUrl, message) {
  const allClients = await clients.matchAll({ type: 'window', includeUncontrolled: true });
  for (const client of allClients) {
    if (!sameOriginPath(client.url, targetUrl) || typeof client.postMessage !== 'function') {
      continue;
    }

    try {
      client.postMessage(message);
    } catch (e) {
      // Ignore transient delivery failures and continue with other candidates.
    }
  }
}

async function deliverOtpAutofillMessage(targetUrl, message) {
  for (const delay of [0, 350, 1000]) {
    if (delay > 0) {
      await sleep(delay);
    }

    broadcastOtpMessage(message);
    await postOtpMessageToAuthClients(targetUrl, message);
  }
}

async function hasVisibleDuplicateNotification(title, body, options) {
  if (
    !self.registration
    || typeof self.registration.getNotifications !== 'function'
    || !options
    || !options.tag
  ) {
    return false;
  }

  try {
    const notifications = await self.registration.getNotifications({ tag: options.tag });
    const incomingOtp = normalizeNotificationValue(options.data && options.data.otp);

    for (const notification of notifications) {
      const currentOtp = normalizeNotificationValue(notification.data && notification.data.otp);
      if (incomingOtp !== '' && currentOtp !== '' && incomingOtp === currentOtp) {
        return true;
      }

      if (
        String(notification.title || '') === String(title || '')
        && String(notification.body || '') === String(body || '')
      ) {
        return true;
      }
    }
  } catch (e) {
    // Ignore getNotifications failures and fall back to showing the notification once.
  }

  return false;
}

self.addEventListener('push', (event) => {
  let payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch (e) {
    payload = {};
  }

  const title = payload.title || 'AgendaFlow';
  const body = payload.body || 'Nuova notifica';
  const options = {
    body,
    icon: payload.icon || '/notifications/icon.svg',
    badge: payload.badge || '/notifications/badge.svg',
    tag: payload.tag || undefined,
    renotify: payload.renotify === true,
    silent: payload.silent === true,
    requireInteraction: payload.requireInteraction === true,
    data: payload.data || {},
  };
  const otpAutofillMessage = buildOtpAutofillMessage(options.data && options.data.otp, Date.now());
  const rawTargetUrl = options.data && options.data.url ? options.data.url : '/';

  event.waitUntil((async () => {
    if (otpAutofillMessage) {
      await cacheOtpAutofillMessage(otpAutofillMessage, rawTargetUrl);
    }

    if (await hasVisibleDuplicateNotification(title, body, options)) {
      return;
    }

    await self.registration.showNotification(title, options);
  })());
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  const data = event.notification.data || {};
  const rawTargetUrl = data.url || '/';
  const preferredClientMode = normalizeNotificationValue(data.clientMode).toLowerCase();
  const otp = typeof data.otp === 'string' || typeof data.otp === 'number'
    ? String(data.otp).trim()
    : '';
  let targetUrl = self.location.origin + '/';

  try {
    targetUrl = new URL(rawTargetUrl, self.location.origin).href;
  } catch (e) {
    targetUrl = self.location.origin + '/';
  }

  event.waitUntil((async () => {
    const targetUrlObject = new URL(targetUrl);
    const otpAutofillMessage = buildOtpAutofillMessage(otp, Date.now());
    if (otp !== '') {
      targetUrlObject.searchParams.set('otp', otp);
      targetUrlObject.searchParams.set('ts', String(Date.now()));
    }

    const finalTargetUrl = targetUrlObject.href;
    if (otpAutofillMessage) {
      await cacheOtpAutofillMessage(otpAutofillMessage, finalTargetUrl);
    }
    const allClients = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    let selectedClient = null;
    let existingAuthClient = null;

    for (const client of allClients) {
      if (
        sameOriginPath(client.url, targetUrlObject)
        && clientMatchesPreferredMode(client.url, preferredClientMode)
      ) {
        existingAuthClient = client;
        selectedClient = client;
        break;
      }
    }

    if (existingAuthClient) {
      if (typeof existingAuthClient.navigate === 'function') {
        const navigatedClient = await existingAuthClient.navigate(finalTargetUrl);
        if (navigatedClient) {
          existingAuthClient = navigatedClient;
        }
      }

      if ('focus' in existingAuthClient) {
        await existingAuthClient.focus();
      }

      if (otpAutofillMessage) {
        await deliverOtpAutofillMessage(targetUrlObject, otpAutofillMessage);
      }

      return existingAuthClient;
    }

    for (const client of allClients) {
      if (
        preferredClientMode === 'standalone'
        && !clientMatchesPreferredMode(client.url, preferredClientMode)
      ) {
        continue;
      }

      if (
        !selectedClient
        && 'focus' in client
        && client.url.includes(self.location.origin)
        && clientMatchesPreferredMode(client.url, preferredClientMode)
      ) {
        selectedClient = client;
      }
    }

    if (selectedClient && typeof selectedClient.navigate === 'function') {
      const navigatedClient = await selectedClient.navigate(finalTargetUrl);
      if (navigatedClient) {
        selectedClient = navigatedClient;
      }
      if ('focus' in selectedClient) {
        await selectedClient.focus();
      }
    } else if (clients.openWindow) {
      selectedClient = await clients.openWindow(finalTargetUrl);
    }

    if (otpAutofillMessage) {
      await deliverOtpAutofillMessage(targetUrlObject, otpAutofillMessage);
    }

    return selectedClient || null;
  })());
});
