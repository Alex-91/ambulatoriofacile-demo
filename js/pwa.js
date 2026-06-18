window.startPush = async function startPush(){
	if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

  // BASE_URL viene preso dalla pagina oppure ricostruito dal path corrente
  const fallbackBasePath = (window.location.pathname || '/')
    .replace(/\/(login|auth)\/?$/i, '/')
    .replace(/\/+$/, '/');

  const BASE_URL = new URL(window.BASE_URL || fallbackBasePath, window.location.origin)
    .toString()
    .replace(/\/$/, '');

  // ✅ SW e scope coerenti col manifest (/test/)
  const reg = await navigator.serviceWorker.register(`${BASE_URL}/sw.js`, { scope: `${BASE_URL}/` });

  const permission = await Notification.requestPermission();
  if (permission !== 'granted') return;

  const { key:vapidPublicKey } = await fetch(`${BASE_URL}/push/publicKey`).then(r=>r.json());
  const applicationServerKey = urlBase64ToUint8Array(vapidPublicKey);

  const subscription = await reg.pushManager.subscribe({
    userVisibleOnly:true,
    applicationServerKey
  });

  const info = await collectDeviceInfo();
  const autoLabel = buildAutoLabel(info);
const isPwa =
  window.matchMedia('(display-mode: standalone)').matches ||
  window.matchMedia('(display-mode: fullscreen)').matches ||
  window.navigator.standalone === true; // iOS Safari
  await fetch(`${BASE_URL}/push/subscribe`,{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({ subscription, deviceInfo: info, deviceLabel: autoLabel,
context: {
      isPwa: isPwa ? 1 : 0
    }	})
  });
  const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);

if (isIOS && !isPwa) {
  alert("Su iPhone le notifiche funzionano solo se installi l'app (Condividi → Aggiungi a Home) e poi abiliti le notifiche.");
  return;
}

  async function collectDeviceInfo(){
  const ua = navigator.userAgent;
  const out = { brand:null, model:null, os:null, type:'unknown', ua };

  // UA-CH (Chrome/Android spesso dà il model)
  if (navigator.userAgentData?.getHighEntropyValues) {
    try {
      const hi = await navigator.userAgentData.getHighEntropyValues(['platform','model','mobile']);
      out.os = hi.platform || null;          // "Android", "Windows", "iOS"
      out.model = hi.model || null;          // es. "Pixel 7", "SM-G990B"
      out.type = hi.mobile ? 'phone' : 'desktop';
    } catch {}
  }

  // fallback OS
  const s = ua.toLowerCase();
  if (!out.os) {
    if (s.includes('android')) out.os = 'Android';
    else if (s.includes('iphone') || s.includes('ipad') || s.includes('ipod')) out.os = 'iOS';
    else if (s.includes('windows')) out.os = 'Windows';
    else if (s.includes('mac os x') || s.includes('macintosh')) out.os = 'macOS';
    else if (s.includes('linux')) out.os = 'Linux';
  }

  // fallback type
  if (out.type==='unknown') {
    const isTab = /ipad|tablet|sm\-t|tab|kindle|silk|playbook/.test(s) || (s.includes('android') && !s.includes('mobile'));
    const isPh  = !isTab && /iphone|ipod|android.*mobile|mobile safari|mobile;|mobi/.test(s);
    out.type = isPh ? 'phone' : (isTab ? 'tablet' : 'desktop');
  }

  // fallback model (Android: cerca prima di "Build/")
  if (!out.model && out.os==='Android') {
    const m = ua.match(/; ?([^;]*?) build\//i);
    if (m && m[1]) out.model = m[1].trim();
  }
  if (!out.model && out.os==='iOS') {
    if (s.includes('iphone')) out.model = 'iPhone';
    else if (s.includes('ipad')) out.model = 'iPad';
    else if (s.includes('ipod')) out.model = 'iPod';
  }

  // brand euristico
  if (!out.brand && out.model) {
    const M = out.model.toUpperCase();
    if (M.startsWith('SM-') || M.startsWith('GT-')) out.brand = 'Samsung';
    else if (M.includes('PIXEL')) out.brand = 'Google';
    else if (M.startsWith('MOTO') || M.startsWith('XT')) out.brand = 'Motorola';
    else if (out.os==='iOS') out.brand = 'Apple';
  }
  return out;
}

function buildAutoLabel(info){
  if (info.brand && info.model) return `${info.brand} ${info.model}`.trim().slice(0,64);
  if (info.model) return info.model.slice(0,64);
  if (info.brand) return info.brand.slice(0,64);
  if (info.os && info.type) return `${info.os} ${info.type}`.slice(0,64);
  return 'Dispositivo';
}

  function urlBase64ToUint8Array(base64String){
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g,'+').replace(/_/g,'/');
    const rawData = atob(base64);
    return Uint8Array.from([...rawData].map(c=>c.charCodeAt(0)));
  }
};
