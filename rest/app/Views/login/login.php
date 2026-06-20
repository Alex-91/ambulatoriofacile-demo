<?php
$demoMode = (bool) ($demoMode ?? false);
$prefillUsername = (string) ($prefillUsername ?? '');
$demoProfileSlug = (string) ($demoProfileSlug ?? '');
$demoProfileLabel = (string) ($demoProfileLabel ?? '');
$demoOtpHint = (bool) ($demoOtpHint ?? false);
$loginSuccess = trim((string) ($loginSuccess ?? ''));
$loginError = trim((string) ($loginError ?? ''));
?>
<html><head>
       

<link rel="shortcut icon"  href="<?= base_url('public/assets/images/logonew.jpg'); ?>" />
<title><?= esc('AmbulatorioFacile') ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="theme-color" content="#2c8895">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= esc('AmbulatorioFacile') ?>">
<link rel="apple-touch-icon" href="<?= base_url('public/assets/images/logonew.jpg'); ?>">     
 <link rel="stylesheet" href="<?= base_url('public/assets/css/login.css'); ?>">
 <script src="<?= base_url('public/assets/js/jquery.min.js'); ?>"></script>
 <link rel="stylesheet" href="<?= base_url('public/assets/fontawesome/css/all.min.css'); ?>">
<link rel="manifest" href="<?= base_url('manifest.json') ?>">
<meta name="theme-color" content="#6c5ce7">
<link rel="apple-touch-icon" href="<?= base_url('icons/maskable-512.png') ?>">
<script>window.BASE_URL = "<?= base_url() ?>";</script>
<style>
  .demo-login-banner {
    margin: 0 0 18px;
    padding: 14px 16px;
    border-radius: 18px;
    background: rgba(44, 136, 149, 0.1);
    border: 1px solid rgba(44, 136, 149, 0.18);
    color: #1d5058;
    line-height: 1.45;
  }

  .demo-login-banner strong,
  .demo-login-banner span {
    display: block;
  }

  .demo-login-banner span + span {
    margin-top: 4px;
  }

  .demo-login-links {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 10px;
  }

  .demo-login-links a {
    color: #1c6670;
    font-weight: 700;
    text-decoration: none;
  }

  .demo-login-note {
    margin: 10px 0 2px;
    text-align: center;
    font-size: 13px;
    color: #4f5f65;
    line-height: 1.45;
  }

  .tenant-picker {
    display: none;
    margin: 14px 0 6px;
    padding: 14px;
    border-radius: 18px;
    background: rgba(44, 136, 149, 0.08);
    border: 1px solid rgba(44, 136, 149, 0.16);
    text-align: left;
  }

  .tenant-picker h4 {
    margin: 0 0 8px;
    color: #1d5058;
    font-size: 16px;
  }

  .tenant-picker p {
    margin: 0 0 10px;
    color: #4f5f65;
    font-size: 13px;
    line-height: 1.45;
  }

  .tenant-picker button {
    width: 100%;
    margin-bottom: 8px;
    border: 0;
    border-radius: 12px;
    padding: 10px 12px;
    background: #2c8895;
    color: #fff;
    font-weight: 700;
    text-align: left;
    cursor: pointer;
  }

  .tenant-picker button span {
    display: block;
    font-weight: 400;
    font-size: 12px;
    margin-top: 3px;
    opacity: 0.92;
  }
</style>
    </head>

<body>

        <!-- Top content -->
        		<div class="container">
      <div class="wrapper">
<div class="title" style="background-image: url('<?= base_url('public/assets/images/logonew.jpg'); ?>'); background-size: contain; background-repeat: no-repeat; background-position-x: center;"></div>
     <form action="<?= site_url('login') ?>" method="post">
        <?php if ($demoMode): ?>
          <div class="demo-login-banner">
            <strong>Accesso demo guidato</strong>
            <span><?= esc($demoProfileLabel !== '' ? $demoProfileLabel : 'Percorso commerciale separato') ?></span>
            <?php if ($prefillUsername !== ''): ?>
              <span>Utente precompilato: <?= esc($prefillUsername) ?></span>
            <?php endif; ?>
            <div class="demo-login-links">
              <?php if ($demoProfileSlug !== ''): ?>
                <a href="<?= site_url('demo/access/' . $demoProfileSlug) ?>">Torna agli account demo</a>
              <?php endif; ?>
              <a href="<?= site_url('demo') ?>">Overview demo</a>
            </div>
          </div>
        <?php endif; ?>
		 <div id="okLogin" style="text-align: center;display:none">
           
				<span style="color:green"><b>Registrazione avvenuta correttamente</b></span>
            
          </div>
          <?php if ($loginSuccess !== ''): ?>
            <div class="demo-login-banner" style="background:rgba(40,167,69,.08); border-color:rgba(40,167,69,.18); color:#1f6b35;">
              <strong><?= esc($loginSuccess) ?></strong>
            </div>
          <?php endif; ?>
          <?php if ($loginError !== ''): ?>
            <div class="demo-login-banner" style="background:rgba(220,53,69,.08); border-color:rgba(220,53,69,.16); color:#8f2130;">
              <strong><?= esc($loginError) ?></strong>
            </div>
          <?php endif; ?>
          <div class="row">
            <i class="fa fa-user"></i>
            <input type="text" id="username" placeholder="Email o Username" value="<?= esc($prefillUsername) ?>" autocomplete="username" required>
          </div>
         <div class="row">
				<i class="fa fa-lock"></i>
				<input type="password" id="password" placeholder="Password" autocomplete="current-password" required>
				<i class="fa fa-eye-slash toggle-password" id="togglePassword"></i>
			</div>
          <?php if ($demoMode): ?>
            <div class="demo-login-note">
              <?= $demoOtpHint ? 'Per questo account demo, se richiesto, puoi completare il passaggio OTP con il codice fisso 2510.' : 'Se l account selezionato richiede MFA, il passaggio OTP arriva nello step successivo senza cambiare il login standard.' ?>
            </div>
          <?php endif; ?>

		  <div id="errorLogin" style="text-align: center;display:none">
           
				<span style="color:red"><b>Username o password errati oppure utente non autorizzato</b></span>
            
          </div>
          <div id="tenantPicker" class="tenant-picker">
            <h4>Seleziona il tuo spazio</h4>
            <p>Scegli lo spazio cliente in cui vuoi entrare.</p>
            <div id="tenantPickerOptions"></div>
          </div>
<div class="row button">
            <input type="button" id="submit" value="Login">
            <input type="button" id="register" value="Registrati" >
			      <input type="button" id="reset" value="Password Dimenticata?">
<script src="<?= base_url('js/pwa.js') ?>" defer></script>

            <input type="button" style="display:none;" id="installPWA" value="Installa l'app">
         <div id="installNote" style="display:none; margin-top:8px;">
  <div style="
    display:flex;
    align-items:flex-start;
    gap:8px;
    max-width:360px;
    margin:0 auto;
    font-size:13px;
    color:#555;
    line-height:1.4;
  ">
    <span style="display:block;">
      Dopo lâ€™installazione Ã¨ necessario effettuare un primo accesso dallâ€™app
      per abilitare le notifiche e ricevere lâ€™OTP.
    </span>
  </div>
</div>


          </div>
           <div class="row button" style="visibility: hidden;">
          </div>
		    <div class="row button" style="visibility: hidden;">
          </div>
        </form>
      </div>
    </div>
</body>
<script>
  // Helper richiamabile da login.js quando il login fallisce
  window.showLoginError = function (msg) {
    var box = document.getElementById('errorLogin');
    if (!box) return;
    if (msg) {
      var span = box.querySelector('span');
      if (span) span.textContent = msg;
    }
    box.style.display = 'block';
  };
</script>

<script>
(function () {
  var loginUrl = <?= json_encode(site_url('login')) ?>;
  var selectTenantUrl = <?= json_encode(site_url('login/tenant-select')) ?>;
  var registerUrl = <?= json_encode(site_url('register')) ?>;
  var resetUrl = <?= json_encode(site_url('login/recupero')) ?>;
  var authUrl = <?= json_encode(site_url('auth')) ?>;
  var hasPrefilledUsername = <?= json_encode($prefillUsername !== '') ?>;
  var tenantPicker = document.getElementById('tenantPicker');
  var tenantPickerOptions = document.getElementById('tenantPickerOptions');

  function showError(message) {
    window.showLoginError(message || 'Username o password errati oppure utente non autorizzato');
  }

  function hideTenantPicker() {
    if (!tenantPicker) return;
    tenantPicker.style.display = 'none';
    if (tenantPickerOptions) tenantPickerOptions.innerHTML = '';
  }

  async function submitTenantSelection(tenantId) {
    $('#errorLogin').hide();

    try {
      var response = await fetch(selectTenantUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
          tenant_id: tenantId
        })
      });

      var payload = await response.json();
      if (payload && payload.resp === 'OK') {
        window.location.href = resolveRedirect(payload);
        return;
      }

      hideTenantPicker();
      showError((payload && (payload.message || payload.error)) || 'Selezione spazio non riuscita.');
    } catch (error) {
      hideTenantPicker();
      showError('Errore di comunicazione con il server.');
    }
  }

  function renderTenantPicker(tenants) {
    if (!tenantPicker || !tenantPickerOptions) {
      return;
    }

    tenantPickerOptions.innerHTML = '';
    (tenants || []).forEach(function (tenant) {
      var button = document.createElement('button');
      button.type = 'button';
      button.innerHTML =
        String(tenant.tenant_name || tenant.tenant_key || 'Spazio cliente') +
        '<span>' +
        String(tenant.package_name || tenant.package_code || '') +
        (tenant.login_hint ? ' · ' + String(tenant.login_hint) : '') +
        '</span>';
      button.addEventListener('click', function () {
        submitTenantSelection(Number(tenant.id_tenant || 0));
      });
      tenantPickerOptions.appendChild(button);
    });

    tenantPicker.style.display = 'block';
  }

  function resolveRedirect(payload) {
    var redirectTarget = payload && typeof payload.redirectUrl === 'string' ? payload.redirectUrl.trim() : '';
    if (redirectTarget) {
      return new URL(redirectTarget, <?= json_encode(rtrim(site_url('/'), '/') . '/') ?>).href;
    }

    return authUrl;
  }

  async function submitLogin() {
    var username = String($('#username').val() || '').trim();
    var password = String($('#password').val() || '');

    if (!username || !password) {
      showError('Inserisci username e password.');
      return;
    }

    $('#errorLogin').hide();
    hideTenantPicker();

    try {
      var response = await fetch(loginUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
          username: username,
          password: password
        })
      });

      var payload = await response.json();

      if (payload && (payload.resp === 'OK' || payload.resp === 'SCADENZA')) {
        window.location.href = resolveRedirect(payload);
        return;
      }

      if (payload && payload.resp === 'TENANT_SELECT') {
        renderTenantPicker(payload.tenants || []);
        return;
      }

      if (payload && payload.resp === 'PASSWORD_SETUP_REQUIRED') {
        window.location.href = resolveRedirect(payload);
        return;
      }

      if (payload && payload.resp === 'RESET_REQUIRED') {
        showError(payload.message || 'La password deve essere reimpostata. Usa Password Dimenticata?.');
        return;
      }

      showError((payload && (payload.message || payload.error)) || '');
    } catch (error) {
      showError('Errore di comunicazione con il server.');
    }
  }

  $(document).ready(function () {
    if (hasPrefilledUsername) {
      $('#password').trigger('focus');
    } else {
      $('#username').trigger('focus');
    }

    $('#submit').on('click', function () {
      submitLogin();
    });

    $('#username, #password').on('keydown', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        submitLogin();
      }
    });

    $('#register').on('click', function () {
      window.location.href = registerUrl;
    });

    $('#reset').on('click', function () {
      window.location.href = resetUrl;
    });

    $('#togglePassword').on('mousedown touchstart', function () {
      $('#password').attr('type', 'text');
      $(this).removeClass('fa-eye-slash').addClass('fa-eye');
    });

    $('#togglePassword').on('mouseup mouseleave touchend touchcancel', function () {
      $('#password').attr('type', 'password');
      $(this).removeClass('fa-eye').addClass('fa-eye-slash');
    });
  });
})();
</script>

<script>
let deferredPrompt = null;

// Ãˆ un dispositivo mobile?
function isMobile() {
    return /android|iphone|ipad|ipod/i.test(navigator.userAgent);
}

// La PWA Ã¨ giÃ  in modalitÃ  standalone (quindi installata)?
function isInStandaloneMode() {
    return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
           || (window.navigator.standalone === true);
}

document.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('installPWA');
    if (!btn) return;

    // Mostra il bottone SOLO se:
    // - Ã¨ un dispositivo mobile
    // - NON Ã¨ giÃ  installata
    const note = document.getElementById('installNote');
    if (isMobile() && !isInStandaloneMode()) {
        btn.style.display = 'inline-block'; // o 'block' se preferisci
            if (note) note.style.display = 'block';
    } else {
        btn.style.display = 'none';
            if (note) note.style.display = 'none';
    }

    // Click sul bottone
    btn.addEventListener('click', async () => {

        // Caso 1: Android / Chrome con beforeinstallprompt
        if (deferredPrompt) {
            deferredPrompt.prompt();
            await deferredPrompt.userChoice;
            deferredPrompt = null;
            return;
        }

        // Caso 2: iOS non installata â†’ istruzioni
        if (/iphone|ipad|ipod/i.test(navigator.userAgent) && !isInStandaloneMode()) {
            alert("Per installare lâ€™app su iPhone: premi il pulsante Condividi e scegli 'Aggiungi alla schermata Home'.");
            return;
        }

        // Caso 3: altri browser
        alert("Per installare lâ€™app usa 'Aggiungi alla schermata Home' del browser oppure se giÃ  installata controlla fra le tue app.");
    });
});

// Catturo l'evento beforeinstallprompt (Android/Chrome)
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    console.log('beforeinstallprompt catturato');
});

// Quando l'app viene installata â†’ nascondo il bottone
window.addEventListener('appinstalled', () => {
    const btn = document.getElementById('installPWA');
        const note = document.getElementById('installNote');
    if (btn) {
        btn.style.display = 'none';
        
    }
        if (note) note.style.display = 'none';
    console.log('PWA installata');
});
</script>

</html>

