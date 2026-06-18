(function () {
  if (!('serviceWorker' in navigator)) {
    return;
  }

  if (!(window.isSecureContext || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
    return;
  }

  const OTP_AUTOFILL_CACHE_NAME = 'otp-autofill-cache-v1';
  const OTP_AUTOFILL_CACHE_URL = new URL('/__otp-autofill__/pending', window.location.origin).href;
  const OTP_AUTOFILL_MAX_AGE_MS = 3 * 60 * 1000;
  const OTP_AUTOFILL_REDIRECT_MARKER_KEY = 'otp-autofill-redirect-attempt';
  let pendingOtpRedirectInFlight = false;

  function isStandaloneAppContext() {
    return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
      || (window.navigator.standalone === true);
  }

  function canRedirectPendingOtpFromCurrentPage() {
    try {
      if (document.getElementById('username') && document.getElementById('password')) {
        return true;
      }

      const currentPath = new URL(window.location.href).pathname.replace(/\/+$/, '');
      if (currentPath === '' || currentPath === '/') {
        return true;
      }

      return /\/login$/i.test(currentPath);
    } catch (e) {
      return false;
    }
  }

  async function readPendingOtpFromCache() {
    if (!('caches' in window)) {
      return null;
    }

    try {
      const cache = await caches.open(OTP_AUTOFILL_CACHE_NAME);
      const response = await cache.match(OTP_AUTOFILL_CACHE_URL);
      if (!response) {
        return null;
      }

      const payload = await response.json();
      const otp = String(payload && payload.otp ? payload.otp : '').replace(/\D/g, '').slice(0, 4);
      const sentAt = Number(payload && payload.sentAt ? payload.sentAt : 0) || Date.now();
      const expiresAt = Number(payload && payload.expiresAt ? payload.expiresAt : 0);
      const isExpired = expiresAt > 0
        ? expiresAt < Date.now()
        : (Date.now() - sentAt) > OTP_AUTOFILL_MAX_AGE_MS;

      if (!otp || isExpired) {
        try {
          await cache.delete(OTP_AUTOFILL_CACHE_URL);
        } catch (_) {}
        return null;
      }

      return {
        otp,
        sentAt,
        targetPath: String(payload && payload.targetPath ? payload.targetPath : '/auth') || '/auth',
      };
    } catch (e) {
      return null;
    }
  }

  async function redirectToPendingOtpTarget() {
    if (pendingOtpRedirectInFlight || !canRedirectPendingOtpFromCurrentPage()) {
      return;
    }

    pendingOtpRedirectInFlight = true;

    try {
      const payload = await readPendingOtpFromCache();
      if (!payload) {
        return;
      }

      const payloadSignature = String(payload.sentAt || '') + ':' + payload.otp;
      try {
        if (window.sessionStorage.getItem(OTP_AUTOFILL_REDIRECT_MARKER_KEY) === payloadSignature) {
          return;
        }
      } catch (_) {}

      const targetUrl = new URL(payload.targetPath, window.location.origin);
      const currentUrl = new URL(window.location.href);
      if (currentUrl.pathname === targetUrl.pathname) {
        return;
      }

      targetUrl.searchParams.set('fromPush', '1');
      targetUrl.searchParams.set('otp', payload.otp);
      targetUrl.searchParams.set('ts', String(payload.sentAt || Date.now()));

      if (isStandaloneAppContext()) {
        targetUrl.searchParams.set('app', '1');
      }

      try {
        window.sessionStorage.setItem(OTP_AUTOFILL_REDIRECT_MARKER_KEY, payloadSignature);
      } catch (_) {}

      window.location.replace(targetUrl.href);
    } finally {
      pendingOtpRedirectInFlight = false;
    }
  }

  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/sw.js').catch(function () {
      // no-op
    });

    redirectToPendingOtpTarget();
  });

  window.addEventListener('pageshow', function () {
    redirectToPendingOtpTarget();
  });

  window.addEventListener('focus', function () {
    redirectToPendingOtpTarget();
  });

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
      redirectToPendingOtpTarget();
    }
  });
})();
