<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8" />
  <title>AmbulatorioFacile â€” Menu</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
  <meta name="theme-color" content="#2c8895">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="<?= esc('AmbulatorioFacile') ?>">

  <link rel="shortcut icon" href="<?= base_url('public/assets/images/logonew.jpg'); ?>" />
  <link rel="apple-touch-icon" href="<?= base_url('public/assets/images/pwa-icon-192.png'); ?>">
  <link rel="manifest" href="<?= base_url('manifest.json') ?>">
  <link rel="stylesheet" href="<?= base_url('public/assets/css/login_schede.css'); ?>">
  <link rel="stylesheet" href="<?= base_url('public/assets/fontawesome/css/all.min.css'); ?>">
  <script src="<?= base_url('public/assets/js/jquery.min.js'); ?>"></script>
  <script src="<?= base_url('js/pwa.js') ?>" defer></script>
  <script>document.title = <?= json_encode('AmbulatorioFacile' . ' - Menu') ?>;</script>

  <?php
    // badge da sessione (Home::refreshHeaderSession li salva sempre)
    $badgePosta = (int) (session()->get('badge_posta_unread') ?? 0);
    $badgeChat  = (int) (session()->get('badge_chat_unread')  ?? 0);
    $demoCurrentAccount = session()->get(\App\Services\DemoAccessService::SESSION_KEY_CURRENT);
    $currentSessionUsername = trim((string) (session()->get('username') ?? ''));
    $demoSessionUsername = is_array($demoCurrentAccount)
      ? trim((string) ($demoCurrentAccount['session_username'] ?? $demoCurrentAccount['username'] ?? ''))
      : '';
    $showDemoRoleButton = is_array($demoCurrentAccount)
      && $currentSessionUsername !== ''
      && $demoSessionUsername !== ''
      && strcasecmp($currentSessionUsername, $demoSessionUsername) === 0;
    $demoAccessUrl = $showDemoRoleButton
      ? trim((string) ($demoCurrentAccount['access_url'] ?? site_url('access')))
      : '';
  ?>

  <style>
    /* Estensioni minime allo stile esistente (login.css) */
    .container { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 16px; }
    .wrapper { width: 100%; max-width: 720px; background: #fff; border-radius: 14px; padding: 20px 20px 28px; box-shadow: 0 10px 30px rgba(0,0,0,.08); }
    .title {
      height: 72px;
      background-image: url('<?= base_url('public/assets/images/logo-header.svg'); ?>');
      background-size: auto 40px;
      background-repeat: no-repeat;
      background-position: left center;
      margin-bottom: 10px;
    }

    .quick-nav {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 16px;
      margin-top: 12px;
    }
    .nav-card {
      position: relative; /* <-- serve per badge */
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      text-decoration: none; background: #f9fafb; border: 1px solid #eef0f2; border-radius: 14px;
      padding: 20px 16px; transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
      user-select: none; -webkit-tap-highlight-color: transparent;
    }
    .nav-card:focus { outline: 2px solid #16a08555; outline-offset: 2px; }
    .nav-card:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,.08); background: #ffffff; }
    .icon { width: 72px; height: 72px; margin-bottom: 12px; }
    .label { font-weight: 600; font-size: 15px; color: #2c3e50; letter-spacing: .2px; }

    /* SVG â€œbrandâ€ colori neutri con accento #2c8895 */
    .stroke { stroke: #2c3e50; }
    .fill   { fill: #2c8895; }
    .muted  { stroke: #9aa5b1; }

    @media (max-width: 360px) {
      .icon { width: 64px; height: 64px; }
      .label { font-size: 14px; }
    }

    /* BADGE */
    .badge-count{
      position:absolute;
      top:10px;
      right:10px;
      min-width: 22px;
      height: 22px;
      padding: 0 6px;
      border-radius: 999px;
      background: #2c8895;
      color: #fff;
      font-size: 12px;
      font-weight: 700;
      line-height: 22px;
      text-align: center;
      box-shadow: 0 6px 14px rgba(0,0,0,.12);
    }
    .topbar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin-bottom:10px;
  flex-wrap:wrap;            /* su schermi piccoli va a capo */
}

.topbar .title{
  flex: 1 1 320px;           /* il titolo cresce e puÃ² andare a capo */
  min-width: 220px;
  height: 72px;
  background-size: auto 40px;
  background-repeat: no-repeat;
  background-position: left center;
  margin-bottom: 0;          /* ora lo gestisce topbar */
}

.btn-logout{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:8px 12px;
  border-radius:10px;
  font-size:13px;
  font-weight:700;
  text-decoration:none;
  color:#fff;
  background:#c0392b;
  white-space:nowrap;
  user-select:none;
  -webkit-tap-highlight-color: transparent;
}

.btn-logout:focus{
  outline: 2px solid #c0392b55;
  outline-offset: 2px;
}

@media (max-width: 420px){
  .btn-logout{
    width:100%;              /* su mobile bottone full width */
    justify-content:center;
  }
}

.btn-profilo{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:8px 12px;
  border-radius:10px;
  font-size:13px;
  font-weight:700;
  text-decoration:none;
  color:#fff;
  background:#2c8895;
  white-space:nowrap;
  user-select:none;
  -webkit-tap-highlight-color: transparent;
}

.btn-profilo:focus{
  outline: 2px solid #c0392b55;
  outline-offset: 2px;
}

  .btn-demo-switch{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:8px 12px;
  border-radius:10px;
  font-size:13px;
  font-weight:700;
  text-decoration:none;
  color:#fff;
  background:#2c8895;
  white-space:nowrap;
  user-select:none;
  -webkit-tap-highlight-color: transparent;
}

.btn-demo-switch:focus{
  outline: 2px solid #2c889555;
  outline-offset: 2px;
}

@media (max-width: 420px){
  .btn-profilo{
    width:100%;              /* su mobile bottone full width */
    justify-content:center;
  }

  .btn-demo-switch{
    width:100%;
    justify-content:center;
  }
}


  </style>
</head>

<body>
  <div class="container" style="margin-top:0px">
    <div class="wrapper">
<div class="topbar">
  <div class="title" aria-label="<?= esc('AmbulatorioFacile') ?>"><?= esc('AmbulatorioFacile') ?></div>
<?php if ($showDemoRoleButton && $demoAccessUrl !== ''): ?>
  <a class="btn-demo-switch" href="<?= esc($demoAccessUrl) ?>" aria-label="Cambia ruolo demo">
    <i class="fa fa-random"></i>
    Cambia ruolo demo
  </a>
<?php endif; ?>
<a class="btn-profilo" href="<?= site_url('profilo') ?>" aria-label="Vai al profilo">
    <i class="fa fa-sign-out-alt"></i>
    Profilo
  </a>
  <a class="btn-logout" href="<?= site_url('logout') ?>" aria-label="Esci dall'applicazione">
    <i class="fa fa-sign-out-alt"></i>
    Logout
  </a>
</div>

      <div class="quick-nav">
          <?php foreach (($schede ?? []) as $s): ?>
            <?php
              $href = site_url($s['url'] ?? '');
              $disabled = ((int)($s['can_access'] ?? 0) !== 1);
              $showCardBadge = (string)($s['codice'] ?? '') !== 'chat';
            ?>

            <a class="nav-card"
              href="<?= $disabled ? 'javascript:void(0)' : $href ?>"
              role="button"
              aria-label="<?= esc($s['aria_label'] ?? ('Vai a ' . ($s['titolo'] ?? ''))) ?>"
              <?= $disabled ? 'aria-disabled="true" tabindex="-1" style="opacity:.55; pointer-events:none"' : '' ?>
            >
              <?php if ($showCardBadge && !empty($s['badge']) && (int)$s['badge'] > 0): ?>
                <span class="badge-count" aria-label="<?= (int)$s['badge'] ?> non letti"><?= (int)$s['badge'] ?></span>
              <?php endif; ?>

              <?php
                // SVG dalla tabella (fidati solo se gestita da admin; altrimenti va sanificata)
                echo $s['icon_svg'] ?? '';
              ?>

              <div class="label"><?= esc($s['titolo'] ?? '') ?></div>
            </a>
          <?php endforeach; ?>
          <?php if (empty($schede)): ?>
  <div style="
    margin-top: 24px;
    padding: 24px;
    border-radius: 14px;
    background: #f9fafb;
    border: 1px solid #eef0f2;
    text-align: center;
    color: #2c3e50;
  ">
    <div style="font-size:18px;font-weight:700;margin-bottom:8px;">
      Accesso non configurato
    </div>

    <div id="access-not-configured-copy" style="font-size:14.5px;line-height:1.5;">
      Il tuo account Ã¨ attualmente attivo, ma non risulta abilitato ad alcuna funzionalitÃ  operative della piattaforma.<br><br>
      Per poter accedere ai servizi di <strong>AmbulatorioFacile</strong>, Ã¨ necessario che un amministratore assegni
      le autorizzazioni appropriate al tuo profilo.
    </div>

    <div id="access-not-configured-support" style="margin-top:14px;font-size:13px;color:#6b7280;">
      Per informazioni o supporto, contatta lâ€™amministrazione della struttura.
    </div>
  </div>
<?php endif; ?>

        </div>

    </div>
  </div>

  <script>
    document.querySelectorAll('.nav-card').forEach(function(card){
      card.setAttribute('tabindex','0');
      card.addEventListener('keydown', function(e){
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); card.click(); }
      });
    });

    var accessCopy = document.getElementById('access-not-configured-copy');
    if (accessCopy) {
      accessCopy.innerHTML =
        'Il tuo account e attualmente attivo, ma non risulta abilitato ad alcuna funzionalita operative della piattaforma.<br><br>' +
        'Per poter accedere ai servizi di <strong><?= esc('AmbulatorioFacile') ?></strong>, e necessario che un amministratore assegni le autorizzazioni appropriate al tuo profilo.';
    }

    var accessSupport = document.getElementById('access-not-configured-support');
    if (accessSupport) {
      accessSupport.textContent = "Per informazioni o supporto, contatta l'amministrazione della struttura.";
    }
  </script>
</body>
</html>

