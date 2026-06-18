<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title><?= esc('AmbulatoriCLOUD') ?> | Profilo</title>
 <meta charset="UTF-8">

     <script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>

    <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
    <meta content='width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no' name='viewport'>
    <!-- Bootstrap 3.3.4 -->
    <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
    <!-- Font Awesome Icons -->
    <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" type="text/css" />
    <!-- Ionicons -->
    <link href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css" rel="stylesheet" type="text/css" />
    <!-- fullCalendar 2.2.5-->
    <link href="<?= base_url('public/plugins/fullcalendar/fullcalendar.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/plugins/fullcalendar/fullcalendar.print.css') ?>" rel="stylesheet" type="text/css" media='print' />
    <!-- Theme style -->
    <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
    <!-- AmbulatoriCLOUD skins -->
    <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />
    <!-- iCheck -->
    <link href="<?= base_url('public/plugins/iCheck/flat/blue.css') ?>" rel="stylesheet" type="text/css" />
</head>

  <body class="skin-blue sidebar-mini">
<div class="wrapper">
  <!-- HEADER: quello tuo centralizzato -->
<?= view('partials/header', [
  'menu_items' => $headerMenuItems ?? [],
  'disable_menu_fallback' => !empty($disableHeaderMenuFallback),
]) ?>




  <div class="content-wrapper">
    <section class="content-header">
      <h1>Profilo <small>Modifica dati e medico associato</small></h1>
    </section>

    <section class="content">

      <?php if (session()->getFlashdata('success')): ?>
        <div class="alert alert-success"><?= esc(session()->getFlashdata('success')) ?></div>
      <?php endif; ?>

      <?php if (session()->getFlashdata('error')): ?>
        <div class="alert alert-danger"><?= esc(session()->getFlashdata('error')) ?></div>
      <?php endif; ?>

      <?php $validation = session()->getFlashdata('validation'); ?>
      <?php if (!empty($validation) && is_array($validation)): ?>
        <div class="alert alert-warning">
          <b>Controlla questi campi:</b>
          <ul style="margin-bottom:0">
            <?php foreach ($validation as $k => $msg): ?>
              <li><?= esc($msg) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
<div class="box box-warning">
  <div class="box-header with-border">
    <h3 class="box-title">Cambio Password</h3>
  </div>

  <form method="post" action="<?= base_url('profilo/password') ?>" id="formPwd">
    <?= csrf_field() ?>

    <div class="box-body">
      <div class="row">
        <div class="col-md-6">
          <div class="form-group">
            <label>Nuova Password*</label>
            <input type="password"
                   name="password_new"
                   id="password_new"
                   class="form-control"
                   placeholder="Nuova password"
                   required>
          </div>

          <ul id="password-rules"
              style="list-style-type:none;padding:0;text-align:left;margin-bottom:0;color:#333;font-size:14px;">
            <li id="rule-length" style="margin-bottom:8px;">&#10060; Almeno 8 caratteri</li>
            <li id="rule-uppercase" style="margin-bottom:8px;">&#10060; Almeno una lettera maiuscola</li>
            <li id="rule-lowercase" style="margin-bottom:8px;">&#10060; Almeno una lettera minuscola</li>
            <li id="rule-special" style="margin-bottom:8px;">&#10060; Almeno un carattere speciale</li>
          </ul>

          <div id="pwdError" class="text-danger" style="display:none; margin-top:10px;"></div>
        </div>

        <div class="col-md-6">
          <div class="form-group">
            <label>Conferma Password*</label>
            <input type="password"
                   name="password_new2"
                   id="password_new2"
                   class="form-control"
                   placeholder="Ripeti password"
                   required>
          </div>

          <div id="pwdMatch" class="text-muted" style="margin-top:10px;"></div>

          <div class="form-group" style="margin-top:20px;">
            <label for="otp_code">Codice OTP*</label>
            <input type="text"
                   name="otp_code"
                   id="otp_code"
                   class="form-control"
                   inputmode="numeric"
                   pattern="[0-9]*"
                   maxlength="8"
                   autocomplete="one-time-code"
                   placeholder="Inserisci OTP"
                   required>
          </div>

          <div class="btn-group" role="group" aria-label="Invio OTP cambio password">
            <button type="button"
                    class="btn btn-default btn-sm btnSendPwdOtp"
                    data-channel="push"
                    <?= empty($activeDevice) ? 'disabled' : '' ?>>
              <i class="fa fa-bell"></i> Push
            </button>
            <button type="button" class="btn btn-default btn-sm btnSendPwdOtp" data-channel="sms">
              <i class="fa fa-comment"></i> SMS
            </button>
            <button type="button" class="btn btn-default btn-sm btnSendPwdOtp" data-channel="email">
              <i class="fa fa-envelope"></i> Email
            </button>
          </div>
          <div id="otpStatus" class="text-muted" style="margin-top:10px;"></div>
        </div>
      </div>
    </div>

    <div class="box-footer">
      <button type="submit" class="btn btn-warning" id="btnPwdSave" disabled>
        <i class="fa fa-key"></i> Aggiorna Password
      </button>
    </div>
  </form>
</div>
<div class="box box-info">
  <div class="box-header with-border">
    <h3 class="box-title">Dispositivo collegato</h3>
  </div>

  <div class="box-body">
    <?php if (!empty($activeDevice)): ?>
      <div class="alert alert-success" style="margin-bottom:10px;">
        <b>Attivo:</b> <?= esc($activeDevice['device_label'] ?? 'Dispositivo') ?><br>
        <small class="text-muted">
          <?= esc($activeDevice['device_os'] ?? '') ?> â€” <?= esc($activeDevice['device_type'] ?? '') ?>
        </small>
      </div>

      <form method="post" action="<?= base_url('profilo/device/disconnect') ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-danger"
                onclick="return confirm('Vuoi disassociare il dispositivo?');">
          <i class="fa fa-unlink"></i> Disassocia
        </button>
      </form>
    <?php else: ?>
      <div class="alert alert-warning" style="margin-bottom:10px;">
        Nessun dispositivo collegato.
      </div>

      <button id="btnLinkHere" class="btn btn-info" type="button">
        <i class="fa fa-link"></i> Associa questo dispositivo
      </button>
      <div class="text-muted" style="margin-top:6px;font-size:12px;">
        Associando questo dispositivo, eventuali altri verranno disattivati (1 solo device).
      </div>
    <?php endif; ?>
  </div>
</div>

    <div class="box box-success">
  <div class="box-header with-border">
    <h3 class="box-title">Dati profilo</h3>
  </div>

  <form method="post" action="<?= base_url('profilo/salva') ?>">
    <?= csrf_field() ?>

    <div class="box-body">

      <?php if (!empty($cliente)): ?>
        <!-- =======================
             PROFILO PAZIENTE (UGUALE A PRIMA)
             ======================= -->

        <div class="row">
          <div class="col-md-6">
            <div class="form-group">
              <label>Nome</label>
              <input type="text" name="nome" class="form-control"
                     value="<?= esc(old('nome', $cliente['nome'] ?? '')) ?>" required>
            </div>
          </div>
          <div class="col-md-6">
            <div class="form-group">
              <label>Cognome</label>
              <input type="text" name="cognome" class="form-control"
                     value="<?= esc(old('cognome', $cliente['cognome'] ?? '')) ?>" required>
            </div>
          </div>
        </div>

        <div class="row">
          <div class="col-md-6">
            <div class="form-group">
              <label>Email</label>
              <input type="email" name="email" class="form-control"
                     value="<?= esc(old('email', $cliente['email'] ?? '')) ?>">
            </div>
          </div>
          <div class="col-md-6">
            <div class="form-group">
              <label>Cellulare</label>
              <input type="text" name="cellulare" class="form-control"
                     value="<?= esc(old('cellulare', $cliente['cellulare'] ?? '')) ?>" required>
            </div>
          </div>
        </div>

        <div class="row">
          <div class="col-md-6">
            <div class="form-group">
              <label>Codice fiscale</label>
              <input type="text"
                     name="codice_fiscale"
                     class="form-control"
                     value="<?= esc(old('codice_fiscale', $cliente['codice_fiscale'] ?? '')) ?>"
                     required
                     style="text-transform: uppercase;"
                     oninput="this.value = this.value.toUpperCase();">
            </div>
          </div>

          <div class="col-md-6">
            <div class="form-group">
              <label>Avvisi Email</label>
              <select name="avviso_mail" class="form-control">
                <?php $avv = (int)old('avviso_mail', (int)($cliente['avviso_mail'] ?? 0)); ?>
                <option value="0" <?= $avv === 0 ? 'selected' : '' ?>>No</option>
                <option value="1" <?= $avv === 1 ? 'selected' : '' ?>>SÃ¬</option>
              </select>
            </div>
          </div>
        </div>

        <hr>

        <div class="row">
          <div class="col-md-8">
            <div class="form-group">
              <label>Indirizzo</label>
              <input type="text" name="indirizzo" class="form-control"
                     value="<?= esc(old('indirizzo', $cliente['indirizzo'] ?? '')) ?>">
            </div>
          </div>
          <div class="col-md-4">
            <div class="form-group">
              <label>Provincia</label>
              <input type="text" name="provincia" class="form-control"
                     value="<?= esc(old('provincia', $cliente['provincia'] ?? '')) ?>">
            </div>
          </div>
        </div>

        <div class="row">
          <div class="col-md-6">
            <div class="form-group">
              <label>CittÃ </label>
              <input type="text" name="citta" class="form-control"
                     value="<?= esc(old('citta', $cliente['citta'] ?? '')) ?>">
            </div>
          </div>

          <div class="col-md-6">
            <div class="form-group">
              <label>Medico associato</label>
              <?php $selDot = (int)old('id_dot', (int)($selectedDoctorId ?? 0)); ?>
              <select name="id_dot" class="form-control" required>
                <option value="">-- Seleziona un medico --</option>
                <?php foreach (($doctors ?? []) as $d): ?>
                  <option value="<?= (int)$d['id_personale'] ?>"
                    <?= ((int)$d['id_personale'] === $selDot) ? 'selected' : '' ?>>
                    <?= esc($d['nominativo']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>

      <?php else: ?>
        <!-- =======================
             PROFILO PERSONALE (dap03_personale)
             ======================= -->

        <?php $p = $personale ?? []; ?>

        <div class="row">
          <div class="col-md-6">
            <div class="form-group">
              <label>Nome</label>
              <input type="text" name="nome" class="form-control"
                     value="<?= esc(old('nome', $p['nome'] ?? '')) ?>" required>
            </div>
          </div>
          <div class="col-md-6">
            <div class="form-group">
              <label>Cognome</label>
              <input type="text" name="cognome" class="form-control"
                     value="<?= esc(old('cognome', $p['cognome'] ?? '')) ?>" required>
            </div>
          </div>
        </div>

        <div class="row">
          <div class="col-md-6">
            <div class="form-group">
              <label>Qualifica</label>
              <input type="text" name="qualifica" class="form-control"
                     value="<?= esc(old('qualifica', $p['qualifica'] ?? '')) ?>" required>
            </div>
          </div>
          <div class="col-md-6">
            <div class="form-group">
              <label>Cellulare</label>
              <input type="text" name="cellulare" class="form-control"
                     value="<?= esc(old('cellulare', $p['cellulare'] ?? '')) ?>" required>
            </div>
          </div>
        </div>

        <div class="row">
          <div class="col-md-6">
            <div class="form-group">
              <label>Email</label>
              <input type="email" name="email" class="form-control"
                     value="<?= esc(old('email', $p['email'] ?? '')) ?>">
            </div>
          </div>

          <div class="col-md-6">
            <div class="form-group">
              <label>Gruppo</label>
              <?php $selG = (int)old('id_gruppo', (int)($p['luogo'] ?? 0)); ?>
              <select name="id_gruppo" class="form-control" required>
                <option value="">-- Seleziona un gruppo --</option>
                <?php foreach (($gruppi ?? []) as $g): ?>
                  <option value="<?= (int)$g['id_gruppo'] ?>"
                    <?= ((int)$g['id_gruppo'] === $selG) ? 'selected' : '' ?>>
                    <?= esc($g['nome']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>

      <?php endif; ?>

    </div>

    <div class="box-footer">
      <button type="submit" class="btn btn-success">
        <i class="fa fa-save"></i> Salva
      </button>
    </div>
  </form>
</div>


      
    </section>
  </div>



</div>

<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
<script src="<?= base_url('public/dist/js/app.min.js') ?>"></script>
<script>
(function(){
  const btn = document.getElementById('btnLinkHere');
  if (!btn) return;

  const vapidKey = "<?= esc($vapidPublicKey ?? '') ?>";
  const csrfName = "<?= csrf_token() ?>";
  const csrfHash = "<?= csrf_hash() ?>";
  const iconUrl = "<?= base_url('notifications/icon.svg') ?>";
  const badgeUrl = "<?= base_url('notifications/badge.svg') ?>";

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
      await fetch("<?= base_url('push/sync-permission') ?>", {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
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
      await reg.showNotification(<?= json_encode('AmbulatoriCLOUD') ?>, {
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

  btn.addEventListener('click', async function(){
    try{
      btn.disabled = true;
      btn.innerHTML = 'Associazione in corso...';

      if (!('serviceWorker' in navigator)) throw new Error('Service Worker non supportato');
      if (!('PushManager' in window)) throw new Error(unsupportedNotificationsMessage());
      if (typeof Notification === 'undefined') throw new Error(unsupportedNotificationsMessage());

      const reg = await navigator.serviceWorker.register("<?= base_url('sw.js') ?>");
      await navigator.serviceWorker.ready;

      const perm = await Notification.requestPermission();
      if (perm !== 'granted') {
        if (perm === 'denied') {
          await syncDeniedPushPermission();
          throw new Error(blockedNotificationsMessage());
        }
        throw new Error('Permesso notifiche non concesso.');
      }

      await ensureDeviceNotificationsAvailable(reg);

      const sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapidKey),
      });

      const deviceLabel = await getDeviceName();

      const body = new URLSearchParams({
        endpoint: sub.endpoint,
        p256dh: arrayBufferToBase64(sub.getKey('p256dh')),
        auth: arrayBufferToBase64(sub.getKey('auth')),
        device_label: deviceLabel,
        [csrfName]: csrfHash,
      });

      const res = await fetch("<?= base_url('profilo/device/register-here') ?>", {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body
      });

      const json = await res.json();
      if (!json.ok) throw new Error(json.msg || 'Errore durante associazione');

      // ricarico per vedere il device attivo
      window.location.reload();

    } catch(err){
      alert('Associazione non riuscita: ' + (err.message || err));
      btn.disabled = false;
      btn.innerHTML = '<i class="fa fa-link"></i> Associa questo dispositivo';
    }
  });

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
  async function getDeviceName(){
    try{
      const ua = navigator.userAgent || '';
      const isStandalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
        || (window.navigator.standalone === true);
      const platform = (navigator.userAgentData?.platform) || navigator.platform || '';
      const model = /SM-[A-Z0-9]+|M200[0-9]+|Mi\s?[0-9A-Za-z]+|Redmi\s?[0-9A-Za-z]+|iPhone|Pixel\s?\d+/i.exec(ua);
      const baseLabel = ((model ? model[0]+' ' : '') + (platform || 'Dispositivo')).trim();
      return (isStandalone ? 'App - ' : 'Browser - ') + baseLabel;
    }catch(e){
      return ((window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
        || (window.navigator.standalone === true))
        ? 'App - Dispositivo'
        : 'Browser - Dispositivo';
    }
  }
})();
</script>

</body>
</html>
<script>
(function() {
  function setRule(el, ok) {
    el.innerHTML = (ok ? '&#9989; ' : '&#10060; ') + el.getAttribute('data-text');
  }

  const pwd  = document.getElementById('password_new');
  const pwd2 = document.getElementById('password_new2');
  const otp  = document.getElementById('otp_code');
  const btn  = document.getElementById('btnPwdSave');
  const otpStatus = document.getElementById('otpStatus');
  const sendOtpBtns = document.querySelectorAll('.btnSendPwdOtp');
  const otpUrl = "<?= base_url('profilo/password/otp') ?>";
  const csrfName = "<?= csrf_token() ?>";
  const csrfHash = "<?= csrf_hash() ?>";

  const rLen = document.getElementById('rule-length');
  const rUp  = document.getElementById('rule-uppercase');
  const rLow = document.getElementById('rule-lowercase');
  const rSp  = document.getElementById('rule-special');

  // testo fisso (cosÃ¬ non perdi le icone)
  rLen.setAttribute('data-text', 'Almeno 8 caratteri');
  rUp.setAttribute('data-text',  'Almeno una lettera maiuscola');
  rLow.setAttribute('data-text', 'Almeno una lettera minuscola');
  rSp.setAttribute('data-text',  'Almeno un carattere speciale');

  const matchInfo = document.getElementById('pwdMatch');

  function validate() {
    const v = pwd.value || '';
    const v2 = pwd2.value || '';

    const okLen  = v.length >= 8;
    const okUp   = /[A-Z]/.test(v);
    const okLow  = /[a-z]/.test(v);
    const okSpec = /[^A-Za-z0-9]/.test(v);

    setRule(rLen, okLen);
    setRule(rUp,  okUp);
    setRule(rLow, okLow);
    setRule(rSp,  okSpec);

    const okAll = okLen && okUp && okLow && okSpec;
    const otpOk = otp && /^[0-9]{4,8}$/.test(otp.value || '');

    if (v2.length > 0) {
      if (v === v2) {
        matchInfo.className = 'text-success';
        matchInfo.textContent = 'Le password coincidono.';
      } else {
        matchInfo.className = 'text-danger';
        matchInfo.textContent = 'Le password non coincidono.';
      }
    } else {
      matchInfo.className = 'text-muted';
      matchInfo.textContent = '';
    }

    btn.disabled = !(okAll && v.length > 0 && v === v2 && otpOk);
  }

  pwd.addEventListener('input', validate);
  pwd2.addEventListener('input', validate);
  if (otp) {
    otp.addEventListener('input', function() {
      otp.value = (otp.value || '').replace(/\D/g, '').slice(0, 8);
      validate();
    });
  }

  sendOtpBtns.forEach(function(button) {
    if (button.disabled) {
      button.setAttribute('data-initial-disabled', '1');
    }

    button.addEventListener('click', async function() {
      const channel = button.getAttribute('data-channel') || 'push';
      const oldHtml = button.innerHTML;
      setOtpButtonsDisabled(true);
      button.innerHTML = 'Invio...';

      if (otpStatus) {
        otpStatus.className = 'text-muted';
        otpStatus.textContent = 'Invio OTP in corso...';
      }

      try {
        const body = new URLSearchParams({
          channel: channel,
          [csrfName]: csrfHash
        });

        const res = await fetch(otpUrl, {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: body
        });

        let json = {};
        try {
          json = await res.json();
        } catch (e) {
          json = {};
        }

        if (!res.ok || !json.ok) {
          throw new Error(json.msg || 'Invio OTP non riuscito.');
        }

        if (otpStatus) {
          otpStatus.className = 'text-success';
          otpStatus.textContent = json.msg || 'OTP inviato.';
        }

        if (otp) {
          otp.focus();
        }
      } catch (err) {
        if (otpStatus) {
          otpStatus.className = 'text-danger';
          otpStatus.textContent = err.message || 'Invio OTP non riuscito.';
        }
      } finally {
        button.innerHTML = oldHtml;
        setOtpButtonsDisabled(false);
      }
    });
  });

  function setOtpButtonsDisabled(disabled) {
    sendOtpBtns.forEach(function(button) {
      button.disabled = disabled || button.getAttribute('data-initial-disabled') === '1';
    });
  }

  validate();
})();
</script>

