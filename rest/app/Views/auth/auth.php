<?php
$csrfName = csrf_token();
$csrfHash = csrf_hash();
$hasMobileBool = !empty($hasMobile) && $hasMobile;
$preferCurrentDeviceOtpBool = !empty($preferCurrentDeviceOtp);
$hasProfileEmailBool = !empty($hasProfileEmail);
$allowEmailOtpWithoutPasswordBool = !empty($allowEmailOtpWithoutPassword);
$allowEmailOtpProfileEditBool = !empty($allowEmailOtpProfileEdit);
$missingEmailLabel = $allowEmailOtpProfileEditBool
    ? '(Nessuna email salvata: usa il pulsante qui sotto per inserirla)'
    : '(Nessuna email presente nel profilo)';
$emailLabel = $hasProfileEmailBool ? (!empty($maskedEmail) ? $maskedEmail : $profileEmail) : $missingEmailLabel;
?>
<!DOCTYPE html>
<html>
<head>
<link rel="shortcut icon" href="<?= base_url('public/assets/images/logonew.jpg'); ?>" />
<title><?= esc('AmbulatorioFacile') ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="theme-color" content="#2c8895">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= esc('AmbulatorioFacile') ?>">
<link rel="apple-touch-icon" href="<?= base_url('public/assets/images/logonew.jpg'); ?>">
<link rel="stylesheet" href="<?= base_url('public/assets/css/reset.css'); ?>">
<link rel="stylesheet" href="<?= base_url('public/assets/css/login.css'); ?>">
<link rel="stylesheet" href="<?= base_url('public/assets/fontawesome/css/all.min.css'); ?>">
<script src="<?= base_url('public/assets/js/jquery.min.js'); ?>"></script>
<script>window.BASE_URL = "<?= base_url() ?>";</script>
<script src="<?= base_url('js/pwa.js') ?>" defer></script>

<script>
  function qrFallback(){
    const box = document.querySelector('.qrbox') || document.body;
    const link = "<?= base_url('auth/link?token=' . esc($linkToken ?? '')) ?>";
    const qr = "https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=" + encodeURIComponent(link);
    box.innerHTML =
      '<div style="text-align:center">' +
      '<img src="' + qr + '" alt="QR link" style="max-width:200px;height:auto">' +
      '<div style="margin-top:8px;font-size:12px"><a href="' + link + '" target="_blank" rel="noopener">Apri link di collegamento</a></div>' +
      '</div>';
  }
</script>

<style>
  :root{ --ink:#1f2d3d; --muted:#667085; --brand:#2c8895; --ring:rgba(22,160,133,.25) }
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto; margin:0; padding:24px; color:var(--ink)}
  .banner {
    display:flex; align-items:flex-start; gap:10px;
    background:#f7fbf9; border:1px solid #d8efe7; color:#145a4a;
    border-radius:12px; padding:12px; margin-bottom:15px; font-size:14px;
  }
  .banner.warn{
    background:#fffaf0; border-color:#f2d6a2; color:#8a5a00;
  }
  .banner .ico{font-size:18px; margin-top:1px}
  .subtle { font-size:12px; color:#666; margin-top:6px }
  .qrbox{
    display:flex; align-items:center; justify-content:center;
    background:#fafbff; border:1px dashed #cfd6e4; border-radius:12px;
    padding:10px; margin:10px 0;
  }
  .device-list{ font-size:12px; color:#444; margin-top:6px }
  a.btn-link{ font-weight:700; text-decoration:underline }
  .alt-channels{
    background:#f5f9ff;
    border:1px solid #cddffd;
    border-radius:16px;
    padding:16px;
    margin:14px 0 18px;
  }
  .email-otp-head{
    display:flex;
    align-items:flex-start;
    gap:12px;
    text-align:left;
  }
  .email-otp-icon{
    width:40px;
    height:40px;
    border-radius:12px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#dbeafe;
    color:#1d4ed8;
    font-size:18px;
    flex:0 0 auto;
  }
  .email-otp-title{
    font-size:16px;
    font-weight:800;
    color:#0f172a;
  }
  .email-otp-copy,
  .email-otp-help{
    font-size:13px;
    line-height:1.5;
    color:#475467;
    margin-top:4px;
  }
  .email-otp-btn{
    width:100%;
    margin-top:14px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    border:0;
    border-radius:12px;
    padding:14px 16px;
    background:#2563eb;
    color:#fff;
    font-size:15px;
    font-weight:800;
    cursor:pointer;
    box-shadow:0 10px 20px rgba(37,99,235,.18);
    transition:transform .12s ease, box-shadow .12s ease, opacity .12s ease;
  }
  .email-otp-btn:hover{
    transform:translateY(-1px);
    box-shadow:0 14px 24px rgba(37,99,235,.24);
  }
  .email-otp-btn:disabled{
    opacity:.6;
    cursor:not-allowed;
    transform:none;
    box-shadow:none;
  }
  .email-otp-destination{
    margin-top:12px;
    text-align:center;
    font-size:13px;
    color:#334155;
    line-height:1.5;
  }
  .email-otp-destination strong{
    color:#0f172a;
  }
  .email-otp-help{
    text-align:center;
  }
  .btn{
    appearance:none; background:var(--brand); color:#fff; border:0;
    border-radius:10px; padding:10px 14px; font-weight:700;
  }
  .btn.btn-secondary{
    background:#eef2f6; color:var(--ink);
  }
  .modal-backdrop{
    position:fixed; inset:0; background:rgba(15,23,42,.48);
    display:none; align-items:center; justify-content:center; padding:20px; z-index:9999;
  }
  .modal-card{
    width:min(100%, 460px); background:#fff; border-radius:16px;
    box-shadow:0 22px 45px rgba(15,23,42,.18); padding:20px;
  }
  .modal-title{
    font-size:18px; font-weight:700; margin-bottom:8px;
  }
  .modal-group{
    margin-top:14px;
  }
  .modal-group label{
    display:block; font-size:13px; font-weight:600; margin-bottom:6px;
  }
  .modal-group input{
    width:100%; border:1px solid #cfd6e4; border-radius:10px;
    padding:11px 12px; box-sizing:border-box;
  }
  .modal-error{
    margin-top:12px; color:#b42318; font-size:13px;
  }
  .modal-actions{
    margin-top:16px; display:flex; gap:10px; justify-content:flex-end;
  }
  .otp-actions{
    display:flex;
    flex-direction:column;
    gap:10px;
  }
  .otp-slots{
    position:relative;
    max-width:198px;
    margin:0 auto 15px;
    cursor:text;
  }
  .otp-slot-grid{
    display:flex;
    justify-content:center;
    gap:6px;
  }
  .otp-slot{
    width:45px;
    height:46px;
    text-align:center;
    border-radius:14px;
    border:2px solid #73AD21;
    background:#fff;
    cursor:text;
    transition:border-color .15s ease, box-shadow .15s ease, background-color .15s ease;
  }
  .otp-slot-active{
    border-color:var(--brand);
    box-shadow:0 0 0 3px var(--ring);
  }
  .otp-slot-caret{
    background-image:linear-gradient(var(--brand), var(--brand));
    background-repeat:no-repeat;
    background-size:2px 22px;
    background-position:center center;
    animation:otpCaretBlink 1s step-end infinite;
  }
  @keyframes otpCaretBlink{
    50%{ background-image:none; }
  }
  .otp-logout{
    display:block;
    width:100%;
    text-align:center;
    padding:12px 14px;
    border-radius:10px;
    background:#eef2f6;
    color:var(--ink);
    font-weight:700;
    text-decoration:none;
    box-sizing:border-box;
  }
  .email-status{
    margin-top:12px;
    padding:12px 14px;
    border-radius:12px;
    font-size:13px;
    line-height:1.5;
    text-align:center;
  }
  .email-status.ok{
    background:#ecfdf3;
    border:1px solid #abefc6;
    color:#145a4a;
  }
  .email-status.error{
    background:#fef3f2;
    border:1px solid #fecdca;
    color:#b42318;
  }
</style>
</head>

<body>
<div class="container">
  <div class="wrapper">
    <div class="title" style="background-image: url('<?= base_url('public/assets/images/logo-symbol.svg'); ?>'); background-size: contain; background-repeat: no-repeat; background-position-x: center;"></div>

    <div id="section-has-mobile" style="<?= $hasMobileBool ? '' : 'display:none' ?>">
      <div class="banner" id="connectedBanner">
        <i class="fa-solid fa-bell ico"></i>
        <div>
          <div><strong id="bannerConnectedTitle"><?= $preferCurrentDeviceOtpBool ? 'Preparazione notifica su questo dispositivo' : 'Controlla la notifica sul tuo smartphone' ?></strong></div>
          <div id="bannerConnectedText">
            <?= $preferCurrentDeviceOtpBool
                ? 'Stai accedendo da mobile: attiviamo prima questo contesto e poi inviamo qui la notifica OTP.'
                : 'Abbiamo richiesto l\'invio della notifica OTP al tuo dispositivo registrato. Se stai accedendo da PC, controlla il telefono.' ?>
          </div>
          <div class="device-list" id="connectedDevicesLine"<?= empty($mobiles) ? ' style="display:none"' : '' ?>>
            <i class="fa-solid fa-mobile-screen"></i>
            Dispositivi collegati:
            <?php
            $labels = [];
            if (!empty($mobiles)) {
                foreach ($mobiles as $d) {
                    $labels[] = esc($d['device_name'] ?? 'Smartphone');
                }
            }
            echo !empty($labels) ? implode(', ', $labels) : '<span id="connectedDeviceName">Questo smartphone</span>';
            ?>
          </div>
          <div class="subtle" id="bannerConnectedFooter">
            Se la notifica non arriva entro pochi secondi, usa il pulsante "Invia codice via email" qui sotto.
          </div>
        </div>
      </div>
    </div>

    <div id="section-no-mobile" style="<?= $hasMobileBool ? 'display:none' : '' ?>">
      <div class="banner">
        <i class="fa-solid fa-link ico"></i>
        <div>
          <div><strong>Nessun telefono collegato</strong></div>
          <div id="desktopLinkMode" style="<?= !empty($isDesktop) && $isDesktop ? '' : 'display:none' ?>">
            <div>Collega ora il tuo smartphone per ricevere gli OTP. Scansiona il QR qui sotto.</div>
            <?php if (!empty($linkToken)): ?>
              <div class="qrbox">
                <img
                  id="qrImg"
                  src="<?= base_url('auth/qrcode') ?>?token=<?= esc($linkToken) ?>&t=<?= time() ?>"
                  alt="QR per collegare il telefono"
                  style="max-width:200px;height:auto"
                  onerror="qrFallback()"
                >
              </div>
              <div class="subtle">Token temporaneo attivo per circa 10 minuti.</div>
              <div class="subtle">Quando il telefono viene collegato, lâ€™OTP push parte automaticamente da questa pagina.</div>
            <?php endif; ?>
          </div>
          <div id="mobileLinkMode" style="<?= !empty($isDesktop) && $isDesktop ? 'display:none' : '' ?>">
            <div>Stai accedendo direttamente da questo smartphone e non Ã¨ ancora collegato.</div>
            <div class="subtle" style="margin-top:6px">
              Registrando questo dispositivo, eventuali altri telefoni associati al tuo account verranno disattivati.
            </div>
            <div style="margin-top:10px;">
              <button id="linkMobileHere" class="btn" type="button">
                Registra solo questo dispositivo e invia OTP
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="alt-channels" style="display:none">
      Non hai ricevuto la notifica? Puoi ricevere lâ€™OTP anche via
      <a id="newCodiceSMS2" href="#" class="btn-link"><i class="fa-solid fa-comment-sms"></i> SMS</a>:
      il codice arriverÃ  al numero <strong><?= esc($cellulare ?? '') ?></strong>.
    </div>

    <div class="alt-channels" id="emailOtpPanel">
      <div class="email-otp-head">
        <div class="email-otp-icon">
          <i class="fa-solid fa-envelope"></i>
        </div>
        <div>
          <div class="email-otp-title">Vuoi ricevere il codice via email?</div>
          <div class="email-otp-copy" id="emailOtpIntro">
            Se la notifica non arriva, premi il pulsante qui sotto.
          </div>
        </div>
      </div>

      <button id="newCodiceEmail2" class="email-otp-btn" type="button">
        <i class="fa-solid fa-paper-plane"></i>
        <span id="emailOtpButtonLabel">Invia codice via email</span>
      </button>

      <div class="email-otp-destination">
        Invio a: <strong id="emailDestinationText"><?= esc($emailLabel) ?></strong>
      </div>

      <div class="email-otp-help" id="emailOtpHelp"></div>
    </div>

    <form id="userForm" action="#">
      <div id="otpSlots" class="otp-slots">
        <input
          id="otpHidden"
          name="otp"
          type="text"
          inputmode="numeric"
          pattern="[0-9]*"
          autocomplete="one-time-code"
          maxlength="4"
          aria-label="Inserisci codice OTP"
          style="position:absolute;inset:0;z-index:2;opacity:0;border:0;background:transparent;color:transparent;caret-color:transparent;"
        >
        <div class="otp-slot-grid">
          <input readonly aria-hidden="true" tabindex="-1" class="otp-slot" id="auth1" maxlength="1" type="text">
          <input readonly aria-hidden="true" tabindex="-1" class="otp-slot" id="auth2" maxlength="1" type="text">
          <input readonly aria-hidden="true" tabindex="-1" class="otp-slot" id="auth3" maxlength="1" type="text">
          <input readonly aria-hidden="true" tabindex="-1" class="otp-slot" id="auth4" maxlength="1" type="text">
        </div>
      </div>

      <div id="errorAuth" style="text-align:center;display:none">
        <span style="color:red"><b>AuthCode errato, si prega di riprovare. Se sono passati oltre 2 minuti richiedi un nuovo codice.</b></span>
      </div>

      <div class="row button otp-actions">
        <input type="button" id="submit" value="Conferma">
        <a href="<?= site_url('logout') ?>" class="otp-logout">Logout</a>
      </div>
    </form>

    <div id="emailStatus" class="email-status" style="display:none"></div>

    <?php if ($allowEmailOtpProfileEditBool): ?>
    <div id="emailOtpModal" class="modal-backdrop">
      <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="emailOtpModalTitle">
        <div class="modal-title" id="emailOtpModalTitle">Conferma email per OTP</div>
        <div class="subtle">
          Inserisci l'indirizzo email a cui inviare il codice OTP. Se e gia presente verra mostrato qui sotto e potrai aggiornarlo.
          L'indirizzo verra salvato o aggiornato nel tuo profilo e potra essere modificato successivamente.
          <?php if ($allowEmailOtpWithoutPasswordBool): ?>
          Se stai cambiando la password scaduta, da qui puoi inviare l'OTP via email senza reinserire la password di login.
          <?php else: ?>
          Prima dell'invio devi reinserire la password usata per il login. Dopo l'invio controlla anche la cartella Spam.
          <?php endif; ?>
        </div>

        <div class="modal-group">
          <label for="otpEmailInput">Email</label>
          <input type="email" id="otpEmailInput" autocomplete="email" placeholder="nome@dominio.it">
        </div>

        <?php if (!$allowEmailOtpWithoutPasswordBool): ?>
        <div class="modal-group">
          <label for="otpPasswordInput">Password di login</label>
          <input type="password" id="otpPasswordInput" autocomplete="current-password" placeholder="Reinserisci la password">
        </div>
        <?php endif; ?>

        <div id="emailOtpModalError" class="modal-error" style="display:none"></div>

        <div class="modal-actions">
          <button id="emailOtpCancel" class="btn btn-secondary" type="button">Annulla</button>
          <button id="emailOtpSave" class="btn" type="button">Salva email e invia OTP</button>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  const ids = ['auth1','auth2','auth3','auth4'];
  const OTP_AUTOFILL_CHANNEL = 'otp-autofill';
  const OTP_AUTOFILL_MESSAGE_TYPE = 'otp-autofill';
  const OTP_AUTOFILL_CACHE_NAME = 'otp-autofill-cache-v1';
  const OTP_AUTOFILL_CACHE_URL = new URL('/__otp-autofill__/pending', window.location.origin).href;
  const OTP_AUTOFILL_MAX_AGE_MS = 3 * 60 * 1000;
  const hiddenInput = document.getElementById('otpHidden');
  const slotsContainer = document.getElementById('otpSlots');
  let lastAutofillSignature = '';
  let isSubmittingOtp = false;
  let otpRecoveryTimers = [];

  function fillFromDigits(str, options = {}) {
    const shouldFocusHidden = options.focusHidden === true;
    const digits = str.replace(/\D/g,'').slice(0,4);

    if (hiddenInput) {
      hiddenInput.value = digits;
      hiddenInput.setAttribute('value', digits);
    }

    ids.forEach((id, i) => {
      const input = document.getElementById(ids[i]);
      if (!input) return;
      const nextValue = digits[i] || '';
      input.value = nextValue;
      input.setAttribute('value', nextValue);
    });

    if (shouldFocusHidden && hiddenInput) {
      hiddenInput.focus({ preventScroll: true });
      if (typeof hiddenInput.setSelectionRange === 'function') {
        hiddenInput.setSelectionRange(digits.length, digits.length);
      }
    }

    updateActiveSlot();
  }

  function getOtpDigits() {
    if (hiddenInput) {
      return String(hiddenInput.value || '').replace(/\D/g, '').slice(0, 4);
    }

    return ids.map((id) => {
      const input = document.getElementById(id);
      return input ? String(input.value || '').replace(/\D/g, '').slice(0, 1) : '';
    }).join('');
  }

  function setOtpErrorVisible(visible) {
    const errorBox = document.getElementById('errorAuth');
    if (!errorBox) return;
    errorBox.style.display = visible ? 'block' : 'none';
  }

  function updateActiveSlot() {
    const digits = getOtpDigits();
    const isFocused = !!hiddenInput && document.activeElement === hiddenInput;
    const activeIndex = Math.min(digits.length, ids.length - 1);

    ids.forEach((id, idx) => {
      const slot = document.getElementById(id);
      if (!slot) return;

      slot.classList.remove('otp-slot-active', 'otp-slot-caret');
      if (isFocused && idx === activeIndex) {
        slot.classList.add('otp-slot-active');
        if ((digits[idx] || '') === '') {
          slot.classList.add('otp-slot-caret');
        }
      }
    });
  }

  async function submitOtp() {
    const authCode = getOtpDigits();
    if (authCode.length !== 4 || isSubmittingOtp) {
      return;
    }

    isSubmittingOtp = true;
    setOtpErrorVisible(false);

    try {
      const res = await fetch('<?= base_url('checkOtp') ?>', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ authCode }),
      });

      const json = await res.json();
      if (!res.ok || !json.success) {
        throw new Error(json.error || 'Codice OTP inserito errato o scaduto');
      }

      const redirectTarget = typeof json.redirectUrl === 'string' ? json.redirectUrl.trim() : '';
      const baseUrlWithSlash = String(window.BASE_URL || window.location.origin).replace(/\/?$/, '/');
      const destination = redirectTarget !== ''
        ? new URL(redirectTarget, baseUrlWithSlash).href
        : baseUrlWithSlash;

      window.location.href = destination;
    } catch (e) {
      setOtpErrorVisible(true);
    } finally {
      isSubmittingOtp = false;
    }
  }

  function reinforceAutofill(digits) {
    const attempts = [0, 80, 220, 450];
    attempts.forEach((delay) => {
      window.setTimeout(() => {
        fillFromDigits(digits, { focusHidden: false });
      }, delay);
    });
  }

  async function clearCachedOtpPayload() {
    if (!('caches' in window)) return;

    try {
      const cache = await caches.open(OTP_AUTOFILL_CACHE_NAME);
      await cache.delete(OTP_AUTOFILL_CACHE_URL);
    } catch (e) {
      // Ignore cache cleanup failures: in-memory and URL fallbacks still work.
    }
  }

  function applyOtpAutofill(payload) {
    if (!payload || payload.type !== OTP_AUTOFILL_MESSAGE_TYPE) return;

    const digits = String(payload.otp || '').replace(/\D/g, '').slice(0, 4);
    const signature = String(payload.sentAt || '') + ':' + digits;
    if (!digits || signature === lastAutofillSignature) return;

    lastAutofillSignature = signature;
    clearCachedOtpPayload();
    reinforceAutofill(digits);
    window.setTimeout(() => {
      if (getOtpDigits().length === 4) {
        submitOtp();
      }
    }, 520);
  }

  function buildOtpPayload(otp, sentAt) {
    const digits = String(otp || '').replace(/\D/g, '').slice(0, 4);
    if (!digits) return null;

    return {
      type: OTP_AUTOFILL_MESSAGE_TYPE,
      otp: digits,
      sentAt: sentAt || Date.now(),
    };
  }

  function currentPageMatchesTargetPath(targetPath) {
    if (!targetPath) return true;

    try {
      const currentUrl = new URL(window.location.href);
      const targetUrl = new URL(targetPath, window.location.origin);
      return currentUrl.pathname === targetUrl.pathname;
    } catch (e) {
      return true;
    }
  }

  async function readOtpFromCache() {
    if (!('caches' in window)) return null;

    try {
      const cache = await caches.open(OTP_AUTOFILL_CACHE_NAME);
      const response = await cache.match(OTP_AUTOFILL_CACHE_URL);
      if (!response) return null;

      const payload = await response.json();
      const digits = String(payload?.otp || '').replace(/\D/g, '').slice(0, 4);
      const sentAt = Number(payload?.sentAt || 0) || Date.now();
      const expiresAt = Number(payload?.expiresAt || 0);
      const isExpired = expiresAt > 0
        ? expiresAt < Date.now()
        : (Date.now() - sentAt) > OTP_AUTOFILL_MAX_AGE_MS;

      if (!digits || isExpired) {
        await cache.delete(OTP_AUTOFILL_CACHE_URL);
        return null;
      }

      if (!currentPageMatchesTargetPath(String(payload?.targetPath || ''))) {
        return null;
      }

      return buildOtpPayload(digits, sentAt);
    } catch (e) {
      return null;
    }
  }

  async function recoverOtpFromCache() {
    const payload = await readOtpFromCache();
    if (!payload) {
      return false;
    }

    applyOtpAutofill(payload);
    return true;
  }

  function clearOtpRecoveryTimers() {
    otpRecoveryTimers.forEach((timerId) => {
      window.clearTimeout(timerId);
    });
    otpRecoveryTimers = [];
  }

  function scheduleOtpRecovery(delays) {
    clearOtpRecoveryTimers();

    delays.forEach((delay) => {
      const timerId = window.setTimeout(() => {
        recoverOtpFromCache();
      }, delay);

      otpRecoveryTimers.push(timerId);
    });
  }

  function readOtpFromUrl() {
    try {
      const currentUrl = new URL(window.location.href);
      const queryOtp = currentUrl.searchParams.get('otp');
      const queryCode = currentUrl.searchParams.get('code');
      const queryPayload = buildOtpPayload(
        queryOtp || (queryCode !== '1' ? queryCode : ''),
        currentUrl.searchParams.get('ts')
      );
      if (queryPayload) {
        return queryPayload;
      }

      const rawHash = String(window.location.hash || '').replace(/^#/, '');
      if (!rawHash) return null;

      const params = new URLSearchParams(rawHash);
      return buildOtpPayload(params.get('otp'), params.get('ts'));
    } catch (e) {
      return null;
    }
  }

  function consumeOtpFromUrl() {
    const payload = readOtpFromUrl();
    if (payload) {
      applyOtpAutofill(payload);
    }
    stripNotificationStateFromUrl();
  }

  function stripNotificationStateFromUrl() {
    try {
      const url = new URL(window.location.href);
      const hadFromPush = url.searchParams.has('fromPush');
      const hadOtpQuery = url.searchParams.has('otp');
      const hadTsQuery = url.searchParams.has('ts');
      const hadLegacyCode = url.searchParams.has('code');
      const hadOtpHash = /\botp=/.test(String(url.hash || '').replace(/^#/, ''));

      if (!hadFromPush && !hadOtpQuery && !hadTsQuery && !hadLegacyCode && !hadOtpHash) return;

      url.searchParams.delete('fromPush');
      url.searchParams.delete('otp');
      url.searchParams.delete('ts');
      url.searchParams.delete('code');
      if (hadOtpHash) {
        url.hash = '';
      }

      const query = url.searchParams.toString();
      const cleanUrl = url.pathname + (query ? '?' + query : '');
      window.history.replaceState(null, document.title, cleanUrl);
    } catch (e) {
      // Ignore URL cleanup failures: autofill still works without them.
    }
  }

  function syncFromHiddenInput() {
    if (!hiddenInput) return;

    const digits = String(hiddenInput.value || '').replace(/\D/g, '').slice(0, 4);
    fillFromDigits(digits, { focusHidden: false });
    if (digits.length === 4) {
      window.setTimeout(() => {
        submitOtp();
      }, 0);
    }
  }

  if (hiddenInput) {
    hiddenInput.addEventListener('input', syncFromHiddenInput);
    hiddenInput.addEventListener('focus', function() {
      slotsContainer?.setAttribute('data-focused', '1');
      updateActiveSlot();
    });
    hiddenInput.addEventListener('blur', function() {
      slotsContainer?.removeAttribute('data-focused');
      updateActiveSlot();
    });
    hiddenInput.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') {
        submitOtp();
      }
    });
    hiddenInput.addEventListener('paste', function() {
      window.setTimeout(syncFromHiddenInput, 0);
    });
  }

  if (slotsContainer) {
    slotsContainer.addEventListener('click', function() {
      if (!hiddenInput) return;
      hiddenInput.focus({ preventScroll: true });
      if (typeof hiddenInput.setSelectionRange === 'function') {
        const digits = getOtpDigits();
        hiddenInput.setSelectionRange(digits.length, digits.length);
      }
      updateActiveSlot();
    });
  }

  const submitBtn = document.getElementById('submit');
  if (submitBtn) {
    submitBtn.addEventListener('click', function() {
      submitOtp();
    });
  }

  const form = document.getElementById('userForm');
  if (form) {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      submitOtp();
    });
  }

  consumeOtpFromUrl();
  updateActiveSlot();
  scheduleOtpRecovery([0, 250, 800, 1600, 3200]);

  window.addEventListener('hashchange', function() {
    consumeOtpFromUrl();
  });

  window.addEventListener('pageshow', function() {
    consumeOtpFromUrl();
    scheduleOtpRecovery([0, 250, 800, 1600]);
  });

  window.addEventListener('focus', function() {
    consumeOtpFromUrl();
    scheduleOtpRecovery([0, 250, 800]);
  });

  document.addEventListener('visibilitychange', function() {
    if (document.visibilityState !== 'visible') {
      return;
    }

    consumeOtpFromUrl();
    scheduleOtpRecovery([0, 250, 800]);
  });

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', function(event) {
      applyOtpAutofill(event.data || {});
    });
  }

  if ('BroadcastChannel' in window) {
    const otpChannel = new BroadcastChannel(OTP_AUTOFILL_CHANNEL);
    otpChannel.addEventListener('message', function(event) {
      applyOtpAutofill(event.data || {});
    });

    window.addEventListener('pagehide', function() {
      otpChannel.close();
    }, { once: true });
  }
})();
</script>

<script>
(function () {
  const csrfName = "<?= $csrfName ?>";
  const csrfHash = "<?= $csrfHash ?>";
  const preferCurrentDeviceOtp = <?= $preferCurrentDeviceOtpBool ? 'true' : 'false' ?>;
  const vapidKey = "<?= esc($vapidPublicKey ?? '') ?>";
  const iconUrl = "<?= base_url('notifications/icon.svg') ?>";
  const badgeUrl = "<?= base_url('notifications/badge.svg') ?>";

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/\-/g,'+').replace(/_/g,'/');
    const rawData = atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
    return outputArray;
  }

  function arrayBufferToBase64(buf){
    return btoa(String.fromCharCode.apply(null, new Uint8Array(buf)));
  }

  function isStandaloneAppContext() {
    return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
      || (window.navigator.standalone === true);
  }

  function currentClientMode() {
    return isStandaloneAppContext() ? 'standalone' : 'browser';
  }

  function ensureStandaloneUrlMarker() {
    if (!isStandaloneAppContext()) return;

    try {
      const url = new URL(window.location.href);
      if (url.searchParams.get('app') === '1') return;

      url.searchParams.set('app', '1');
      const query = url.searchParams.toString();
      const nextUrl = url.pathname + (query ? '?' + query : '') + (url.hash || '');
      window.history.replaceState(null, document.title, nextUrl);
    } catch (_) {}
  }

  async function getDeviceName(){
    try{
      const ua = navigator.userAgent || '';
      const ch = navigator.userAgentData || null;
      const platform = ch?.platform || navigator.platform || '';
      const brands = ch?.brands?.map(b=>b.brand).join(' ') || '';
      const model = /SM-[A-Z0-9]+|M200[0-9]+|Mi\s?[0-9A-Za-z]+|Redmi\s?[0-9A-Za-z]+|iPhone|Pixel\s?\d+/i.exec(ua);
      const baseLabel = ((model ? model[0]+' ' : '') + (platform || brands || 'Smartphone')).trim();
      return (isStandaloneAppContext() ? 'App - ' : 'Browser - ') + baseLabel;
    }catch(e){
      return isStandaloneAppContext() ? 'App - Smartphone' : 'Browser - Smartphone';
    }
  }

async function showLocalNotification(title, body) {
  return;
}

  function setConnectedBannerState(title, text, isWarn) {
    const titleEl = document.getElementById('bannerConnectedTitle');
    const textEl = document.getElementById('bannerConnectedText');
    const banner = document.getElementById('connectedBanner');

    if (titleEl) titleEl.textContent = title;
    if (textEl) textEl.textContent = text;
    if (banner) banner.classList.toggle('warn', !!isWarn);
  }

  function updateConnectedDeviceLabel(deviceName) {
    const line = document.getElementById('connectedDevicesLine');
    if (!line || !deviceName) return;

    line.style.display = '';
    line.innerHTML = '<i class="fa-solid fa-mobile-screen"></i> ';
    line.append(document.createTextNode('Dispositivi collegati: ' + deviceName));
  }

  ensureStandaloneUrlMarker();

  let hasProfileEmail = <?= $hasProfileEmailBool ? 'true' : 'false' ?>;
  const allowEmailOtpWithoutPassword = <?= $allowEmailOtpWithoutPasswordBool ? 'true' : 'false' ?>;
  const allowEmailOtpProfileEdit = <?= $allowEmailOtpProfileEditBool ? 'true' : 'false' ?>;
  const missingEmailLabel = <?= json_encode((string)$missingEmailLabel) ?>;
  let currentEmailLabel = <?= json_encode((string)$emailLabel) ?>;
  let currentProfileEmail = <?= json_encode((string)($profileEmail ?? '')) ?>;
  let isEmailOtpRequestPending = false;

  const altChannels = document.getElementById('emailOtpPanel');
  const emailActionBtn = document.getElementById('newCodiceEmail2');
  const emailActionLabelEl = document.getElementById('emailOtpButtonLabel');
  const emailIntroEl = document.getElementById('emailOtpIntro');
  const emailHelpEl = document.getElementById('emailOtpHelp');
  const emailStatusEl = document.getElementById('emailStatus');
  const emailModal = document.getElementById('emailOtpModal');
  const emailInput = document.getElementById('otpEmailInput');
  const passwordInput = document.getElementById('otpPasswordInput');
  const emailSaveBtn = document.getElementById('emailOtpSave');
  const emailCancelBtn = document.getElementById('emailOtpCancel');
  const emailModalError = document.getElementById('emailOtpModalError');
  const emailModalTitle = document.getElementById('emailOtpModalTitle');

  function renderAltChannels() {
    if (!altChannels) return;

    altChannels.style.display = 'block';
    if (emailIntroEl) {
      emailIntroEl.textContent = 'Se la notifica non arriva, premi il pulsante qui sotto.';
    }

    if (emailActionLabelEl) {
      if (isEmailOtpRequestPending) {
        emailActionLabelEl.textContent = 'Invio email in corso...';
      } else if (!hasProfileEmail && allowEmailOtpProfileEdit) {
        emailActionLabelEl.textContent = 'Inserisci email e invia OTP';
      } else if (allowEmailOtpProfileEdit) {
        emailActionLabelEl.textContent = 'Conferma email e invia OTP';
      } else {
        emailActionLabelEl.textContent = 'Invia OTP alla tua email';
      }
    }

    if (emailHelpEl) {
      if (!allowEmailOtpProfileEdit && !hasProfileEmail) {
        emailHelpEl.textContent = 'Nel recupero password puoi usare solo un indirizzo gia presente nel profilo.';
      } else if (allowEmailOtpProfileEdit) {
        emailHelpEl.textContent = allowEmailOtpWithoutPassword
          ? 'Prima dell\'invio potrai confermare o aggiornare l\'indirizzo. Controlla anche la cartella Spam.'
          : 'Prima dell\'invio potrai confermare o aggiornare l\'indirizzo e, se richiesto, reinserire la password di login. Controlla anche la cartella Spam.';
      } else {
        emailHelpEl.textContent = 'Il codice verra inviato all\'indirizzo gia presente nel profilo. Controlla anche la cartella Spam.';
      }
    }

    if (emailActionBtn) {
      const shouldDisable = (!allowEmailOtpProfileEdit && !hasProfileEmail) || isEmailOtpRequestPending;
      emailActionBtn.disabled = shouldDisable;
      emailActionBtn.setAttribute('aria-disabled', shouldDisable ? 'true' : 'false');
    }
  }

  function emailDestinationEl() {
    return document.getElementById('emailDestinationText');
  }

  function refreshEmailLabel(label) {
    currentEmailLabel = label || missingEmailLabel;
    const target = emailDestinationEl();
    if (target) target.textContent = currentEmailLabel;
  }

  function setEmailStatus(message, isError) {
    if (!emailStatusEl) return;
    if (!message) {
      emailStatusEl.style.display = 'none';
      emailStatusEl.textContent = '';
      emailStatusEl.classList.remove('ok', 'error');
      return;
    }

    emailStatusEl.style.display = 'block';
    emailStatusEl.textContent = message;
    emailStatusEl.classList.toggle('error', !!isError);
    emailStatusEl.classList.toggle('ok', !isError);
  }

  function setModalError(message) {
    if (!emailModalError) return;
    if (!message) {
      emailModalError.style.display = 'none';
      emailModalError.textContent = '';
      return;
    }

    emailModalError.style.display = 'block';
    emailModalError.textContent = message;
  }

  function openEmailModal() {
    if (!emailModal) return;
    setEmailStatus('', false);
    setModalError('');
    if (emailInput) {
      emailInput.value = currentProfileEmail || '';
    }
    if (passwordInput) {
      passwordInput.value = '';
    }
    if (emailModalTitle) {
      emailModalTitle.textContent = hasProfileEmail ? 'Conferma o aggiorna email per OTP' : 'Inserisci email per OTP';
    }
    if (emailSaveBtn) {
      emailSaveBtn.textContent = hasProfileEmail ? 'Aggiorna email e invia OTP' : 'Salva email e invia OTP';
    }
    emailModal.style.display = 'flex';
    window.setTimeout(() => {
      if (emailInput) emailInput.focus();
    }, 0);
  }

  function closeEmailModal() {
    if (!emailModal) return;
    emailModal.style.display = 'none';
    setModalError('');
  }

  function startEmailOtpFlow() {
    if (!allowEmailOtpProfileEdit) {
      sendOtpToProfileEmail();
      return;
    }
    openEmailModal();
  }

  async function sendOtpToProfileEmail() {
    if (isEmailOtpRequestPending) {
      return;
    }

    if (!hasProfileEmail) {
      setEmailStatus('Nessun indirizzo email presente nel profilo. Nel recupero password puoi usare solo una mail gia salvata.', true);
      refreshEmailLabel('');
      return;
    }

    isEmailOtpRequestPending = true;
    renderAltChannels();
    setEmailStatus('Invio OTP via email in corso...', false);

    try {
      const body = new URLSearchParams({
        [csrfName]: csrfHash,
      });

      const res = await fetch('<?= base_url('auth/send-otp-email') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
      });

      const json = await res.json();
      if (!res.ok || !json.success) {
        throw new Error(json.message || 'Invio email non riuscito.');
      }

      if (json.maskedEmail) {
        refreshEmailLabel(json.maskedEmail);
      }

      setEmailStatus(json.message + (json.maskedEmail ? ' ' + json.maskedEmail : ''), false);
    } catch (err) {
      setEmailStatus(err.message || String(err), true);
    } finally {
      isEmailOtpRequestPending = false;
      renderAltChannels();
    }
  }

  async function saveEmailAndSendOtp() {
    const body = new URLSearchParams({
      email: emailInput ? emailInput.value.trim() : '',
      [csrfName]: csrfHash,
    });

    if (!allowEmailOtpWithoutPassword) {
      body.append('password', passwordInput ? passwordInput.value : '');
    }

    emailSaveBtn.disabled = true;
    setModalError('');

    try {
      const res = await fetch('<?= base_url('auth/save-email-send-otp') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
      });

      const json = await res.json();
      if (!res.ok || !json.success) {
        throw new Error(json.message || 'Salvataggio email non riuscito');
      }

      hasProfileEmail = true;
      currentProfileEmail = emailInput ? emailInput.value.trim() : currentProfileEmail;
      renderAltChannels();
      refreshEmailLabel(json.maskedEmail || currentProfileEmail);
      closeEmailModal();
      setEmailStatus(json.message + (json.maskedEmail ? ' ' + json.maskedEmail : ''), false);
    } catch (err) {
      setModalError(err.message || String(err));
    } finally {
      emailSaveBtn.disabled = false;
    }
  }

  renderAltChannels();
  refreshEmailLabel(currentEmailLabel);

  document.querySelectorAll('#newCodiceEmail').forEach((el) => {
    el.addEventListener('click', function (e) {
      e.preventDefault();
      startEmailOtpFlow();
    });
  });

  if (emailActionBtn) {
    emailActionBtn.addEventListener('click', function () {
      startEmailOtpFlow();
    });
  }

  if (emailSaveBtn) {
    emailSaveBtn.addEventListener('click', saveEmailAndSendOtp);
  }

  if (emailCancelBtn) {
    emailCancelBtn.addEventListener('click', function () {
      closeEmailModal();
      setEmailStatus('', false);
    });
  }

  if (passwordInput) {
    passwordInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        saveEmailAndSendOtp();
      }
    });
  }

  if (emailInput && !passwordInput) {
    emailInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        saveEmailAndSendOtp();
      }
    });
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

  async function activateCurrentDeviceForOtp() {
    if (!preferCurrentDeviceOtp) {
      return;
    }

    const mode = currentClientMode();
    const isStandalone = mode === 'standalone';

    setConnectedBannerState(
      isStandalone ? 'Preparazione notifica nell app' : 'Preparazione notifica su questo browser',
      isStandalone
        ? 'Stiamo attivando l app installata come destinazione principale per questo accesso.'
        : 'Stiamo attivando il browser corrente come destinazione principale per questo accesso.',
      false
    );

    try {
      if (!('serviceWorker' in navigator)) throw new Error('Service Worker non supportato');
      if (!('PushManager' in window)) throw new Error(unsupportedNotificationsMessage());
      if (typeof Notification === 'undefined') throw new Error(unsupportedNotificationsMessage());

      const reg = await navigator.serviceWorker.register("<?= base_url('sw.js') ?>");
      await navigator.serviceWorker.ready;

      let permission = Notification.permission;
      if (permission !== 'granted') {
        permission = await Notification.requestPermission();
      }

      if (permission !== 'granted') {
        if (permission === 'denied') {
          await syncDeniedPushPermission();
          throw new Error(blockedNotificationsMessage());
        }
        throw new Error('Permesso notifiche non concesso.');
      }

      let sub = await reg.pushManager.getSubscription();
      if (!sub) {
        sub = await reg.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlBase64ToUint8Array(vapidKey),
        });
      }

      const deviceName = await getDeviceName();
      const body = new URLSearchParams({
        endpoint: sub.endpoint,
        p256dh: arrayBufferToBase64(sub.getKey('p256dh')),
        auth: arrayBufferToBase64(sub.getKey('auth')),
        device_name: deviceName,
        client_mode: mode,
        [csrfName]: csrfHash,
      });

      const res = await fetch('<?= base_url('auth/register-device-direct') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
      });

      const json = await res.json();
      if (!res.ok || !json.ok) {
        throw new Error(json.msg || 'Errore backend registrazione device');
      }

      updateConnectedDeviceLabel(deviceName);

      if (json.otpSent === true) {
        setConnectedBannerState(
          isStandalone ? 'Controlla la notifica nell app' : 'Controlla la notifica su questo browser',
          isStandalone
            ? 'L OTP e stato inviato all app installata che stai usando adesso.'
            : 'L OTP e stato inviato al browser mobile da cui stai effettuando l accesso.',
          false
        );
        return;
      }

      setConnectedBannerState(
        'Dispositivo collegato',
        'Il dispositivo corrente e stato registrato, ma la notifica OTP non e partita automaticamente. Usa un canale alternativo.',
        true
      );
    } catch (err) {
      console.error(err);
      setConnectedBannerState(
        'Attivazione notifica non riuscita',
        isStandalone
          ? 'Non sono riuscito ad attivare le notifiche dell app su questo dispositivo. Controlla i permessi o usa l OTP via email.'
          : 'Non sono riuscito ad attivare le notifiche su questo browser. Controlla i permessi o usa l OTP via email.',
        true
      );
    }
  }

  const linkBtn = document.getElementById('linkMobileHere');
  if (linkBtn) {
    linkBtn.addEventListener('click', async function () {
      try {
        linkBtn.disabled = true;
        linkBtn.textContent = 'Registrazione dispositivo...';

        if (!('serviceWorker' in navigator)) throw new Error('Service Worker non supportato');
        if (!('PushManager' in window)) throw new Error(unsupportedNotificationsMessage());

        const reg = await navigator.serviceWorker.register("<?= base_url('sw.js') ?>");
        await navigator.serviceWorker.ready;

        let perm = 'default';

  if (typeof Notification !== 'undefined') {
  perm = Notification.permission;
  if (perm !== 'granted') {
    perm = await Notification.requestPermission();
  }
} else {
  throw new Error(unsupportedNotificationsMessage());
}

if (perm !== 'granted') {
  if (perm === 'denied') {
    await syncDeniedPushPermission();
    throw new Error(blockedNotificationsMessage());
  }
  throw new Error('Permesso notifiche non concesso.');
}

        await ensureDeviceNotificationsAvailable(reg);

        let sub = await reg.pushManager.getSubscription();
        if (!sub) {
          sub = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(vapidKey),
          });
        }

        const deviceName = await getDeviceName();

        const body = new URLSearchParams({
          endpoint: sub.endpoint,
          p256dh: arrayBufferToBase64(sub.getKey('p256dh')),
          auth: arrayBufferToBase64(sub.getKey('auth')),
          device_name: deviceName,
          client_mode: currentClientMode(),
          [csrfName]: csrfHash,
        });

        const res = await fetch('<?= base_url('auth/register-device-direct') ?>', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body,
        });

        const json = await res.json();

        if (!json.ok) {
          throw new Error(json.msg || 'Errore backend registrazione device');
        }

        const noMobile = document.getElementById('section-no-mobile');
        const hasMobile = document.getElementById('section-has-mobile');
        if (noMobile) noMobile.style.display = 'none';
        if (hasMobile) hasMobile.style.display = '';

        const titleEl = document.getElementById('bannerConnectedTitle');
        const textEl = document.getElementById('bannerConnectedText');
        const banner = document.getElementById('connectedBanner');

        if (json.otpSent === true) {
          if (titleEl) titleEl.textContent = 'Dispositivo collegato correttamente';
          if (textEl) textEl.textContent = 'Richiesta OTP push avviata. Se non compare nulla, controlla che le notifiche del browser o del dispositivo siano attive su questo smartphone.';
          if (banner) banner.classList.remove('warn');
          linkBtn.textContent = 'Dispositivo registrato, controlla la notifica OTP';
        } else {
          if (titleEl) titleEl.textContent = 'Dispositivo collegato';
          if (textEl) textEl.textContent = 'Il dispositivo Ã¨ stato registrato, ma la notifica OTP non Ã¨ partita automaticamente. Usa il codice via SMS.';
          if (banner) banner.classList.add('warn');
          linkBtn.textContent = 'Dispositivo registrato';
        }
      } catch (err) {
        console.error(err);
        alert('Registrazione dispositivo non riuscita: ' + (err.message || err));
        linkBtn.disabled = false;
        linkBtn.textContent = 'Registra solo questo dispositivo e invia OTP';
      }
    });
  }

  if (preferCurrentDeviceOtp) {
    activateCurrentDeviceForOtp();
  }
})();
</script>

<script>
(function () {
  function clientShouldUseMobileLinkFlow() {
    try {
      const ua = navigator.userAgent || '';
      const uaData = navigator.userAgentData || null;
      const uaDataMobile = uaData && typeof uaData.mobile === 'boolean'
        ? uaData.mobile
        : null;
      const hasMobileUa = /android|iphone|ipad|ipod|mobi|mobile/i.test(ua);
      const desktopIpad = /Macintosh/i.test(ua) && (navigator.maxTouchPoints || 0) > 1;
      const coarsePointer = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
      const smallViewport = Math.max(window.innerWidth || 0, window.innerHeight || 0) > 0
        && Math.max(window.innerWidth || 0, window.innerHeight || 0) <= 1024;

      if (uaDataMobile === true) return true;
      if (hasMobileUa || desktopIpad) return true;

      return coarsePointer && (navigator.maxTouchPoints || 0) > 0 && smallViewport;
    } catch (_) {
      return false;
    }
  }

  function syncNoMobileLayoutWithClient() {
    const noMobile = document.getElementById('section-no-mobile');
    if (!noMobile || noMobile.style.display === 'none') return;

    const desktopMode = document.getElementById('desktopLinkMode');
    const mobileMode = document.getElementById('mobileLinkMode');
    if (!desktopMode || !mobileMode) return;

    const useMobileFlow = clientShouldUseMobileLinkFlow();
    desktopMode.style.display = useMobileFlow ? 'none' : '';
    mobileMode.style.display = useMobileFlow ? '' : 'none';
  }

  syncNoMobileLayoutWithClient();
  window.addEventListener('resize', syncNoMobileLayoutWithClient);

  const noMobile = document.getElementById('section-no-mobile');
  const hasMobile = document.getElementById('section-has-mobile');
  if (!noMobile || noMobile.style.display === 'none') return;

  let tries = 0;
  let alreadyTriggered = false;
  const maxTries = 60;
  const csrfName = "<?= $csrfName ?>";
  const csrfHash = "<?= $csrfHash ?>";
  const iconUrl = "<?= base_url('notifications/icon.svg') ?>";

async function showLocalNotification(title, body) {
  return;
}

  async function triggerOtpPushAfterLink() {
    if (alreadyTriggered) return;
    alreadyTriggered = true;

    try {
      const body = new URLSearchParams({ [csrfName]: csrfHash });

      const res = await fetch('<?= base_url('auth/send-otp-push') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body
      });

      const json = await res.json();

      const titleEl = document.getElementById('bannerConnectedTitle');
      const textEl = document.getElementById('bannerConnectedText');
      const banner = document.getElementById('connectedBanner');

      if (json.ok && json.otpSent) {
        if (titleEl) titleEl.textContent = 'Dispositivo collegato correttamente';
        if (textEl) textEl.textContent = 'Richiesta OTP inviata al telefono appena collegato.';
        if (banner) banner.classList.remove('warn');
      } else {
        if (titleEl) titleEl.textContent = 'Dispositivo collegato';
        if (textEl) textEl.textContent = 'Il telefono Ã¨ stato associato, ma la notifica OTP non Ã¨ partita automaticamente. Richiedi un SMS.';
        if (banner) banner.classList.add('warn');
      }
    } catch (e) {
      console.error('Invio OTP dopo collegamento fallito', e);
    }
  }

  const timer = setInterval(async () => {
    tries++;
    try {
      const res = await fetch('<?= base_url('auth/device-status') ?>', { cache: 'no-store' });
      const json = await res.json();

      if (json.ok && json.hasMobile) {
        clearInterval(timer);
        noMobile.style.display = 'none';
        hasMobile.style.display = '';
        await triggerOtpPushAfterLink();
      }

      if (tries >= maxTries) clearInterval(timer);
    } catch (e) {
      if (tries >= maxTries) clearInterval(timer);
    }
  }, 2000);
})();
</script>
</body>
</html>

