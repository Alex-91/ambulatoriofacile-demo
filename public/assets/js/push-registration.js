(function (window) {
  'use strict';

  if (window.PushRegistration) {
    return;
  }

  function normalizePushVapidKey(rawValue) {
    return String(rawValue || '')
      .trim()
      .replace(/^["'`]+|["'`]+$/g, '')
      .replace(/\s+/g, '');
  }

  function urlBase64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - base64String.length % 4) % 4);
    var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    var rawData = window.atob(base64);
    var outputArray = new Uint8Array(rawData.length);

    for (var i = 0; i < rawData.length; i += 1) {
      outputArray[i] = rawData.charCodeAt(i);
    }

    return outputArray;
  }

  function applicationServerKeyFromVapid(rawValue) {
    var normalized = normalizePushVapidKey(rawValue);
    if (!normalized) {
      throw new Error('Configurazione notifiche push non disponibile. Ricarica la pagina e riprova.');
    }

    try {
      var outputArray = urlBase64ToUint8Array(normalized);
      if (outputArray.length !== 65 || outputArray[0] !== 4) {
        throw new Error('invalid_length');
      }

      return outputArray;
    } catch (_) {
      throw new Error('Configurazione notifiche push non valida. Ricarica la pagina e riprova.');
    }
  }

  function toUint8Array(value) {
    if (!value) {
      return null;
    }

    if (value instanceof Uint8Array) {
      return value;
    }

    if (value instanceof ArrayBuffer) {
      return new Uint8Array(value);
    }

    if (ArrayBuffer.isView(value)) {
      return new Uint8Array(value.buffer.slice(value.byteOffset, value.byteOffset + value.byteLength));
    }

    return null;
  }

  function uint8ArraysEqual(left, right) {
    if (!left || !right || left.length !== right.length) {
      return false;
    }

    for (var i = 0; i < left.length; i += 1) {
      if (left[i] !== right[i]) {
        return false;
      }
    }

    return true;
  }

  function normalizePathname(pathname) {
    var normalized = String(pathname || '/').replace(/\/+$/, '');
    return normalized === '' ? '/' : normalized + '/';
  }

  function serviceWorkerUrl(swUrl) {
    return new URL(swUrl, window.location.href);
  }

  function serviceWorkerScopeUrl(swUrl) {
    return new URL('./', serviceWorkerUrl(swUrl));
  }

  function serviceWorkerScopePath(swUrl) {
    return serviceWorkerScopeUrl(swUrl).pathname;
  }

  function activeWorkerScriptUrl(registration) {
    var worker = registration.active || registration.waiting || registration.installing;
    if (!worker || !worker.scriptURL) {
      return '';
    }

    return worker.scriptURL;
  }

  function looksLikePushWorker(registration) {
    var scriptUrl = activeWorkerScriptUrl(registration);
    if (!scriptUrl) {
      return false;
    }

    try {
      return /\/sw\.js$/i.test(new URL(scriptUrl, window.location.href).pathname);
    } catch (_) {
      return false;
    }
  }

  function isConflictingRegistration(registration, expectedScopeHref) {
    if (!registration || !registration.scope || !looksLikePushWorker(registration)) {
      return false;
    }

    try {
      var expectedScope = new URL(expectedScopeHref, window.location.href);
      var candidateScope = new URL(registration.scope, window.location.href);

      if (candidateScope.origin !== expectedScope.origin) {
        return false;
      }

      if (candidateScope.href === expectedScope.href) {
        return false;
      }

      var expectedPath = normalizePathname(expectedScope.pathname);
      var candidatePath = normalizePathname(candidateScope.pathname);
      var currentPath = normalizePathname(new URL(window.location.href).pathname);

      if (expectedPath === candidatePath) {
        return false;
      }

      var overlapsExpected = expectedPath.indexOf(candidatePath) === 0 || candidatePath.indexOf(expectedPath) === 0;
      var controlsCurrentPage = currentPath.indexOf(candidatePath) === 0;

      return overlapsExpected || controlsCurrentPage;
    } catch (_) {
      return false;
    }
  }

  async function waitForServiceWorkerActivation(registration) {
    if (!registration) {
      throw new Error('Registrazione service worker non disponibile');
    }

    if (registration.active) {
      return registration;
    }

    var worker = registration.installing || registration.waiting;
    if (!worker) {
      return registration;
    }

    await new Promise(function (resolve, reject) {
      var timeoutId = window.setTimeout(function () {
        reject(new Error('Attivazione service worker scaduta'));
      }, 10000);

      function cleanup() {
        window.clearTimeout(timeoutId);
        worker.removeEventListener('statechange', onStateChange);
      }

      function onStateChange() {
        if (worker.state === 'activated') {
          cleanup();
          resolve();
          return;
        }

        if (worker.state === 'redundant') {
          cleanup();
          reject(new Error('Service worker diventato ridondante'));
        }
      }

      worker.addEventListener('statechange', onStateChange);
      onStateChange();
    });

    return registration;
  }

  async function registrationForScope(swUrl) {
    return navigator.serviceWorker.getRegistration(serviceWorkerScopeUrl(swUrl).href);
  }

  async function cleanupConflictingRegistrations(swUrl) {
    if (!navigator.serviceWorker.getRegistrations) {
      return;
    }

    var expectedScopeHref = serviceWorkerScopeUrl(swUrl).href;
    var registrations = await navigator.serviceWorker.getRegistrations();

    for (var i = 0; i < registrations.length; i += 1) {
      var registration = registrations[i];
      if (!isConflictingRegistration(registration, expectedScopeHref)) {
        continue;
      }

      try {
        var sub = await registration.pushManager.getSubscription();
        if (sub) {
          await sub.unsubscribe();
        }
      } catch (_) {}

      try {
        await registration.unregister();
      } catch (_) {}
    }
  }

  async function ensureExpectedSubscription(registration, desiredKey) {
    var existing = await registration.pushManager.getSubscription();
    if (!existing) {
      return null;
    }

    try {
      var currentKey = toUint8Array(existing.options && existing.options.applicationServerKey);
      if (!currentKey || uint8ArraysEqual(currentKey, desiredKey)) {
        return existing;
      }
    } catch (_) {
      return existing;
    }

    try {
      await existing.unsubscribe();
    } catch (_) {}

    return null;
  }

  function shouldRepairAndRetry(error) {
    var message = String((error && (error.message || error.name)) || error || '').toLowerCase();
    return (
      message.indexOf('applicationserverkey') !== -1
      || message.indexOf('invalidstate') !== -1
      || message.indexOf('subscription') !== -1
      || message.indexOf('service worker') !== -1
      || message.indexOf('registration') !== -1
    );
  }

  async function registerScopedWorker(swUrl) {
    var workerUrl = serviceWorkerUrl(swUrl);
    var scopePath = serviceWorkerScopePath(swUrl);
    var registration = await navigator.serviceWorker.register(workerUrl.href, { scope: scopePath });
    await waitForServiceWorkerActivation(registration);
    return (await registrationForScope(swUrl)) || registration;
  }

  async function ensureServiceWorker(swUrl) {
    if (!('serviceWorker' in navigator)) {
      throw new Error('Service Worker non supportato');
    }

    await cleanupConflictingRegistrations(swUrl);
    return registerScopedWorker(swUrl);
  }

  async function repairPushRegistration(swUrl, desiredKey) {
    await cleanupConflictingRegistrations(swUrl);

    var currentRegistration = await registrationForScope(swUrl);
    if (currentRegistration) {
      try {
        var sub = await currentRegistration.pushManager.getSubscription();
        if (sub) {
          await sub.unsubscribe();
        }
      } catch (_) {}

      try {
        await currentRegistration.unregister();
      } catch (_) {}
    }

    await new Promise(function (resolve) {
      window.setTimeout(resolve, 200);
    });

    var bustedUrl = serviceWorkerUrl(swUrl);
    bustedUrl.searchParams.set('pv', String(Date.now()));

    var registration = await navigator.serviceWorker.register(bustedUrl.href, {
      scope: serviceWorkerScopePath(swUrl)
    });
    await waitForServiceWorkerActivation(registration);

    registration = (await registrationForScope(swUrl)) || registration;

    return {
      registration: registration,
      subscription: await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: desiredKey
      }),
      repaired: true
    };
  }

  async function ensurePushSubscription(swUrl, vapidKey) {
    if (!('serviceWorker' in navigator)) {
      throw new Error('Service Worker non supportato');
    }

    if (!('PushManager' in window)) {
      throw new Error('Le notifiche push non sono supportate su questo dispositivo.');
    }

    var desiredKey = applicationServerKeyFromVapid(vapidKey);
    await cleanupConflictingRegistrations(swUrl);

    var registration = await registerScopedWorker(swUrl);
    var subscription = await ensureExpectedSubscription(registration, desiredKey);

    if (!subscription) {
      subscription = await registration.pushManager.getSubscription();
    }

    if (!subscription) {
      try {
        subscription = await registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: desiredKey
        });
      } catch (error) {
        if (!shouldRepairAndRetry(error)) {
          throw error;
        }

        var repaired = await repairPushRegistration(swUrl, desiredKey);
        registration = repaired.registration;
        subscription = repaired.subscription;
      }
    }

    return {
      registration: registration,
      subscription: subscription,
      applicationServerKey: desiredKey
    };
  }

  window.PushRegistration = {
    normalizePushVapidKey: normalizePushVapidKey,
    urlBase64ToUint8Array: urlBase64ToUint8Array,
    applicationServerKeyFromVapid: applicationServerKeyFromVapid,
    ensureServiceWorker: ensureServiceWorker,
    ensurePushSubscription: ensurePushSubscription
  };
})(window);
