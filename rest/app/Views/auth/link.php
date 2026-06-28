<?php
$csrfName = csrf_token();
$csrfHash = csrf_hash();
$token = $_GET['token'] ?? '';
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Collegamento dispositivo â€¢ AmbulatorioFacile</title>
<meta name="theme-color" content="#2c8895">
<script>document.title = <?= json_encode('Collegamento dispositivo - ' . 'AmbulatorioFacile') ?>;</script>
<style>
  :root{
    --ink:#1f2d3d; --muted:#667085; --brand:#2c8895;
    --ok:#0f9d58; --err:#b91c1c; --bg:#f8fafc;
  }
  *{box-sizing:border-box}
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;margin:0;padding:24px;color:var(--ink);background:var(--bg)}
  .card{max-width:560px;margin:32px auto;border:1px solid #e5e7eb;border-radius:14px;padding:20px;background:#fff;box-shadow:0 8px 24px rgba(0,0,0,.05)}
  h1{font-size:20px;margin:0 0 10px}
  .small,.ok,.err{font-size:14px;line-height:1.5}
  .small{color:var(--muted)}
  .ok{color:var(--ok);font-weight:600}
  .err{color:var(--err);font-weight:600}
  .btn{appearance:none;background:var(--brand);color:#fff;border:0;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer;margin-right:8px}
  .btn:disabled{opacity:.6;cursor:not-allowed}
  .btn-secondary{background:#64748b}
  .actions{margin-top:16px}
  .hint{display:none;margin-top:14px;padding:12px 14px;border-radius:10px;background:#f8fafc;border:1px solid #e5e7eb;font-size:14px;line-height:1.5;color:var(--muted)}
</style>
</head>
<body>
<div class="card">
  <h1>Collegamento dispositivo</h1>
  <p class="small">Collega questo telefono al tuo account per ricevere gli OTP via notifica push.</p>

  <p id="status" class="small">Premi il pulsante per iniziare.</p>

  <div class="actions">
    <button id="enableBtn" class="btn" type="button">Attiva notifiche</button>
    <button id="retryBtn" class="btn btn-secondary" type="button" style="display:none">Riprova</button>
  </div>

  <div id="installHint" class="hint">
    Il telefono Ã¨ stato collegato correttamente. Ora torna sul PC: la pagina rileverÃ  il dispositivo e invierÃ  automaticamente la notifica OTP.
  </div>
</div>

<script src="<?= base_url('public/assets/js/push-registration.js') ?>"></script>
<script>
(async () => {
  const statusEl = document.getElementById('status');
  const enableBtn = document.getElementById('enableBtn');
  const retryBtn = document.getElementById('retryBtn');
  const installHint = document.getElementById('installHint');

  const token = "<?= esc($token) ?>";
  const vapidKey = normalizePushVapidKey(<?= json_encode($vapidPublicKey ?? '') ?>);
  const csrfName = "<?= esc($csrfName) ?>";
  const csrfHash = "<?= esc($csrfHash) ?>";
  const iconUrl = "<?= base_url('notifications/icon.svg') ?>";
  const badgeUrl = "<?= base_url('notifications/badge.svg') ?>";

  function setStatus(message, cssClass = 'small') {
    statusEl.className = cssClass;
    statusEl.textContent = message;
  }

  function normalizePushVapidKey(rawValue) {
    return String(rawValue || '')
      .trim()
      .replace(/^["'`]+|["'`]+$/g, '')
      .replace(/\s+/g, '');
  }

  function applicationServerKeyFromVapid(rawValue) {
    const normalized = normalizePushVapidKey(rawValue);
    if (!normalized) {
      throw new Error('Configurazione notifiche push non disponibile. Ricarica la pagina e riprova.');
    }

    try {
      const outputArray = urlBase64ToUint8Array(normalized);
      if (outputArray.length !== 65 || outputArray[0] !== 4) {
        throw new Error('invalid_length');
      }
      return outputArray;
    } catch (_) {
      throw new Error('Configurazione notifiche push non valida. Ricarica la pagina e riprova.');
    }
  }

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
    return outputArray;
  }

  function arrayBufferToBase64(buf) {
    if (!buf) return '';
    return btoa(String.fromCharCode.apply(null, new Uint8Array(buf)));
  }

  async function getDeviceName() {
    try {
      const ua = navigator.userAgent || '';
      const isStandalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
        || (window.navigator.standalone === true);
      const ch = navigator.userAgentData || null;
      const platform = ch?.platform || navigator.platform || '';
      const brands = ch?.brands?.map(b => b.brand).join(' ') || '';
      const model = /SM-[A-Z0-9]+|M200[0-9]+|Mi\s?[0-9A-Za-z]+|Redmi\s?[0-9A-Za-z]+|iPhone|Pixel\s?\d+/i.exec(ua);
      const baseLabel = ((model ? model[0] + ' ' : '') + (platform || brands || 'Smartphone')).trim();
      return (isStandalone ? 'App - ' : 'Browser - ') + baseLabel;
    } catch (e) {
      return ((window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
        || (window.navigator.standalone === true))
        ? 'App - Smartphone'
        : 'Browser - Smartphone';
    }
  }

  function deviceNotificationsBlockedMessage() {
    const info = notificationClientInfo();
    if (info.isIOS) {
      return 'Il sito ha il permesso notifiche attivo, ma le notifiche sembrano disattivate nelle impostazioni del dispositivo o della web app. Riattivale e poi riapri l\'app dalla schermata Home.';
    }
    if (info.isChrome) {
      return 'Chrome ha il permesso notifiche del sito attivo, ma le notifiche sembrano bloccate nelle impostazioni del dispositivo o dell\'app Chrome. Attivale nelle impostazioni notifiche del telefono e poi riprova.';
    }
    if (info.isFirefox) {
      return 'Il sito ha il permesso notifiche attivo, ma le notifiche sembrano bloccate nelle impostazioni del dispositivo o di Firefox. Riattivale e poi riprova.';
    }
    if (info.isEdge) {
      return 'Il sito ha il permesso notifiche attivo, ma le notifiche sembrano bloccate nelle impostazioni del dispositivo o di Edge. Riattivale e poi riprova.';
    }
    return 'Il sito ha il permesso notifiche attivo, ma le notifiche sembrano bloccate nelle impostazioni del dispositivo o del browser. Riattivale e poi riprova.';
  }

  async function ensureDeviceNotificationsAvailable(reg) {
    const info = notificationClientInfo();
    if (!info.isChrome) return;
    if (!reg || typeof reg.showNotification !== 'function' || typeof reg.getNotifications !== 'function') return;

    const tag = 'push-healthcheck-' + Date.now() + '-' + Math.random().toString(36).slice(2);

    try {
      await reg.showNotification(<?= json_encode('AmbulatorioFacile') ?>, {
        body: 'Verifica notifiche attive...',
        tag,
        silent: true,
        renotify: false,
        requireInteraction: false,
        icon: iconUrl,
        badge: badgeUrl,
        data: { internalHealthcheck: true }
      });

      await new Promise((resolve) => window.setTimeout(resolve, 400));

      const notifications = await reg.getNotifications({ tag });
      const isVisible = Array.isArray(notifications) && notifications.length > 0;

      if (isVisible) {
        const userConfirmed = window.confirm('Ti abbiamo appena inviato una notifica di prova. Premi OK se l\'hai vista, oppure Annulla se non e comparsa.');
        notifications.forEach((notification) => {
          try { notification.close(); } catch (_) {}
        });
        if (userConfirmed) {
          return;
        }
      }
    } catch (_) {
      // fall through and show a clearer device/browser settings warning
    }

    throw new Error(deviceNotificationsBlockedMessage());
  }

  async function syncDeniedPushPermission() {
    let endpoint = '';

    try {
      if ('serviceWorker' in navigator && 'PushManager' in window) {
        const reg = await navigator.serviceWorker.register("<?= base_url('sw.js') ?>");
        await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription();
        if (sub) {
          endpoint = sub.endpoint || '';
          try { await sub.unsubscribe(); } catch (_) {}
        }
      }
    } catch (_) {}

    try {
      const body = new URLSearchParams({ permission: 'denied', [csrfName]: csrfHash });
      if (endpoint) body.append('endpoint', endpoint);
      await fetch('<?= base_url('push/sync-permission') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body
      });
    } catch (_) {}
  }

  function notificationClientInfo() {
    const ua = navigator.userAgent || '';
    return {
      isIOS: /iPhone|iPad|iPod/i.test(ua),
      isSafari: /^((?!chrome|android|crios|fxios|edgios).)*safari/i.test(ua),
      isChrome: /Chrome|CriOS/i.test(ua),
      isFirefox: /Firefox|FxiOS/i.test(ua),
      isEdge: /Edg|EdgiOS/i.test(ua),
    };
  }

  function unsupportedNotificationsMessage() {
    const info = notificationClientInfo();
    if (info.isIOS) {
      return 'Su iPhone o iPad le notifiche web funzionano solo se il sito e` aggiunto alla schermata Home e aperto come app.';
    }
    if (info.isSafari) {
      return 'Le notifiche push non sono disponibili in questo contesto di Safari. Verifica di usare HTTPS e un ambiente supportato.';
    }
    return 'Le notifiche push non sono supportate da questo browser o dispositivo.';
  }

  function blockedNotificationsMessage() {
    const info = notificationClientInfo();
    if (info.isIOS) {
      return 'Le notifiche di questo web app risultano bloccate su iPhone o iPad. Non posso attivarle forzatamente: riattivale nelle impostazioni notifiche del dispositivo e poi riapri l\'app dalla schermata Home.';
    }
    if (info.isSafari) {
      return 'Le notifiche di Safari risultano bloccate. Non posso attivarle forzatamente: riattivale dalle impostazioni del sito/browser e poi riprova.';
    }
    if (info.isFirefox) {
      return 'Le notifiche di Firefox risultano bloccate. Non posso attivarle forzatamente: riattivale dalle impostazioni del sito/browser e poi riprova.';
    }
    if (info.isEdge) {
      return 'Le notifiche di Edge risultano bloccate. Non posso attivarle forzatamente: riattivale dalle impostazioni del sito/browser e poi riprova.';
    }
    return 'Le notifiche del browser risultano bloccate. Non posso attivarle forzatamente: riattivale dalle impostazioni del sito/browser e poi riprova.';
  }

  if (!token) {
    setStatus('Token mancante.', 'err');
    enableBtn.style.display = 'none';
    return;
  }

  enableBtn.addEventListener('click', async () => {
    retryBtn.style.display = 'none';
    enableBtn.disabled = true;

    try {
      if (typeof Notification !== 'undefined' && Notification.permission === 'denied') {
        await syncDeniedPushPermission();
        throw new Error(blockedNotificationsMessage());
      }

      if (!('serviceWorker' in navigator)) throw new Error('Service Worker non supportato');
      if (!('PushManager' in window)) throw new Error(unsupportedNotificationsMessage());
      if (typeof Notification === 'undefined') {
  throw new Error(unsupportedNotificationsMessage());
}
if (Notification.permission === 'denied') {
  await syncDeniedPushPermission();
  throw new Error(blockedNotificationsMessage());
}

      setStatus('Registrazione service workerâ€¦');
      const reg = await window.PushRegistration.ensureServiceWorker("<?= base_url('sw.js') ?>");

     let permission = 'default';

if (typeof Notification !== 'undefined') {
  permission = Notification.permission;
  if (permission !== 'granted') {
    setStatus('Richiesta permesso notificheâ€¦');
    permission = await Notification.requestPermission();
  }
} else {
  throw new Error(unsupportedNotificationsMessage());
}

      if (permission !== 'granted') {
        if (permission === 'denied') {
          await syncDeniedPushPermission();
          throw new Error(blockedNotificationsMessage());
        }
        throw new Error('Permesso notifiche non concesso.');
      }

      await ensureDeviceNotificationsAvailable(reg);

      setStatus('Registrazione dispositivoâ€¦');

      const pushState = await window.PushRegistration.ensurePushSubscription("<?= base_url('sw.js') ?>", vapidKey);
      const sub = pushState.subscription;

      const body = new URLSearchParams({
        token,
        endpoint: sub.endpoint,
        p256dh: arrayBufferToBase64(sub.getKey('p256dh')),
        auth: arrayBufferToBase64(sub.getKey('auth')),
        device_name: await getDeviceName(),
        [csrfName]: csrfHash
      });

      setStatus('Invio dati al serverâ€¦');

      const res = await fetch('<?= base_url('auth/link-complete') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body
      });

      const json = await res.json();

      if (!res.ok || !json.ok) {
        throw new Error(json?.msg || 'Errore durante il collegamento del dispositivo.');
      }

      setStatus('Dispositivo collegato correttamente. Torna ora sul PC: la notifica OTP partirÃ  automaticamente dalla schermata di accesso.', 'ok');
      installHint.style.display = 'block';
      enableBtn.style.display = 'none';
    } catch (err) {
      console.error(err);
      setStatus('Collegamento non riuscito: ' + (err.message || err), 'err');
      retryBtn.style.display = 'inline-block';
      enableBtn.disabled = false;
    }
  });

  retryBtn.addEventListener('click', () => location.reload());
})();
</script>
</body>
</html>

