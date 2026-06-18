<?php
helper('admin_menu');

$sess = session();

/* fallback da sessione */
$badgePosta = (int) ($sess->get('badge_posta_unread') ?? 0);
$badgeChat  = (int) ($sess->get('badge_chat_unread')  ?? 0);

/* se menu_items esiste, prova a prendere i conteggi da lì */
if (!empty($menu_items) && is_array($menu_items)) {
    foreach ($menu_items as $m) {
        if (!is_array($m) || !isset($m['conteggio'])) {
            continue;
        }

        $link   = strtolower((string)($m['link'] ?? ''));
        $titolo = strtolower((string)($m['titolo_menu'] ?? ''));
        $cnt    = (int)$m['conteggio'];

        if ($cnt <= 0) {
            continue;
        }

        // POSTA
        if (str_contains($link, 'posta') || str_contains($titolo, 'posta') || str_contains($titolo, 'messagg')) {
            $badgePosta = $cnt;
        }

        // CHAT
        if (str_contains($link, 'chat') || str_contains($titolo, 'chat')) {
            $badgeChat = $cnt;
        }
    }

    // Evita scritture di sessione inutili durante il render della view.
    if (
        $badgePosta !== (int)($sess->get('badge_posta_unread') ?? 0)
        || $badgeChat !== (int)($sess->get('badge_chat_unread') ?? 0)
    ) {
        $sess->set('badge_posta_unread', $badgePosta);
        $sess->set('badge_chat_unread',  $badgeChat);
    }
}

$pushMobileUserId = (int)($sess->get('userId') ?? 0);
if ($pushMobileUserId <= 0) {
    $pushMobileSessionUser = $sess->get('utente_sess');
    if (is_object($pushMobileSessionUser) && !empty($pushMobileSessionUser->id_user)) {
        $pushMobileUserId = (int)$pushMobileSessionUser->id_user;
    }
}

$currentPath = trim(service('uri')->getPath(), '/');
$hideHeaderMenu = str_starts_with($currentPath, 'admin');
?>

<header class="main-header" style="background:#2c8895">
  <!-- Logo -->
  <a href="./index2.html" class="logo">
    <span class="logo-mini"><b>Ambulatori</b>CLOUD</span>
    <span class="logo-lg"><b>Ambulatori</b>CLOUD</span>
  </a>

  <nav class="navbar navbar-static-top" role="navigation">
    <a href="#" class="sidebar-toggle" data-toggle="offcanvas" role="button" style="display:none">
      <span class="sr-only">Toggle navigation</span>
      <span class="icon-bar"></span>
      <span class="icon-bar"></span>
      <span class="icon-bar"></span>
    </a>

    <?php if (!$hideHeaderMenu): ?>
    <div class="navbar-custom-menu" style="float:left !important">
      <ul class="nav navbar-nav">

<?php
$disableMenuFallback = !empty($disable_menu_fallback);

if ($disableMenuFallback) {
    $fallbackItems = is_array($menu_items ?? null) ? $menu_items : [];
    $navItems = [];
    foreach ($fallbackItems as $it) {
        if (!is_array($it)) {
            continue;
        }
        $link = trim((string)($it['link'] ?? ''));
        if ($link === '') {
            continue;
        }
        $navItems[] = [
            'link'   => $link,
            'titolo' => (string)($it['titolo'] ?? $it['titolo_menu'] ?? $link),
            'fa_icon'=> (string)($it['fa_icon'] ?? $it['class_icon'] ?? 'fa-circle-o'),
            'badge'  => (int)($it['badge'] ?? $it['conteggio'] ?? 0),
        ];
    }
} else {
    $navItems = session()->get('header_nav_items');
    if (empty($navItems) || !is_array($navItems)) {
        $fallbackItems = $menu_items ?? session()->get('header_menu_items') ?? [];
        if (empty($fallbackItems) || !is_array($fallbackItems)) {
            $menuData = session()->get('menuData');
            if (is_array($menuData) && !empty($menuData['result']) && is_array($menuData['result'])) {
                $fallbackItems = $menuData['result'];
            }
        }
        if (empty($fallbackItems) || !is_array($fallbackItems)) {
            $menuDataAdmin = session()->get('menuDataAdmin');
            if (is_array($menuDataAdmin) && !empty($menuDataAdmin['result']) && is_array($menuDataAdmin['result'])) {
                $fallbackItems = $menuDataAdmin['result'];
            }
        }
        $navItems = [];
        if (is_array($fallbackItems)) {
            foreach ($fallbackItems as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $link = trim((string)($it['link'] ?? ''));
                if ($link === '') {
                    continue;
                }
                $navItems[] = [
                    'link'   => $link,
                    'titolo' => (string)($it['titolo'] ?? $it['titolo_menu'] ?? $link),
                    'fa_icon'=> (string)($it['fa_icon'] ?? $it['class_icon'] ?? 'fa-circle-o'),
                    'badge'  => (int)($it['badge'] ?? $it['conteggio'] ?? 0),
                ];
            }
        }
    }
}
?>

<?php foreach ($navItems as $item): ?>
  <?php
    $itemLink = (string)($item['link'] ?? '');
    $itemTitle = admin_menu_pretty_title((string)($item['titolo'] ?? ''), $itemLink);
    $itemIcon = admin_menu_resolve_icon((string)($item['fa_icon'] ?? ''), $itemTitle, $itemLink);
  ?>
  <li class="hidden-xs">
    <a href="<?= site_url($itemLink) ?>" title="<?= esc($itemTitle) ?>">
      <i class="fa <?= esc($itemIcon) ?>"></i>
      <span class="nav-label" style="position:relative; display:inline-block;">
        <?= esc($itemTitle) ?>

        <?php $isChat = str_contains(strtolower($itemLink), 'chat') || str_contains(strtolower($itemTitle), 'chat'); ?>
<?php if (!empty($item['badge']) && (int)$item['badge'] > 0): ?>
  <span class="label label-success"
        <?= $isChat ? 'id="chatBadge"' : '' ?>
        style="position:absolute; top:-10px; right:-18px; font-size:9px; padding:2px 3px; line-height:.9;">
    <?= (int)$item['badge'] ?>
  </span>
<?php endif; ?>
      </span>
    </a>
  </li>
<?php endforeach; ?>

        <!-- Hamburger mobile -->
       <li class="dropdown visible-xs-inline-block">
  <a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button">
    <i class="fa fa-bars"></i>
  </a>

  <ul class="dropdown-menu" style="left:0; right:auto;background:#2c8895">
    <?php foreach ($navItems as $i => $item): ?>
      <?php
        $itemLink = (string)($item['link'] ?? '');
        $itemTitle = admin_menu_pretty_title((string)($item['titolo'] ?? ''), $itemLink);
        $itemIcon = admin_menu_resolve_icon((string)($item['fa_icon'] ?? ''), $itemTitle, $itemLink);
        $isChat = str_contains(strtolower($itemLink), 'chat') || str_contains(strtolower($itemTitle), 'chat');
      ?>
      <li style="position:relative;">
        <a href="<?= site_url($itemLink) ?>">
          <i class="fa <?= esc($itemIcon) ?>"></i> <?= esc($itemTitle) ?>

          <?php if (!empty($item['badge']) && (int)$item['badge'] > 0): ?>
            <span class="label label-success"
                  <?= $isChat ? 'id="chatBadgeMobile"' : '' ?>
                  style="position:absolute; right:12px; top:10px; font-size:9px; padding:2px 3px; line-height:.9;">
              <?= (int)$item['badge'] ?>
            </span>
          <?php endif; ?>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
</li>


      </ul>
    </div>
    <?php endif; ?>

    <div class="navbar-custom-menu">
      <ul class="nav navbar-nav">
        <li class="dropdown user user-menu">
          <a href="#" class="dropdown-toggle" data-toggle="dropdown">
            <?php
              $immagineProfilo = session()->get('immagine_profilo');
              if (empty($immagineProfilo)) {
                  $immagineProfilo = 'user.png';
              }
            ?>
            <img src="<?= base_url('public/dist/img/' . $immagineProfilo) ?>"
                 class="user-image"
                 alt="User Image" />
            <span class="hidden-xs"><?= session()->get('nome_visualizzato') ?></span>
          </a>

          <ul class="dropdown-menu">
            <li class="user-header">
              <img src="<?= base_url('public/dist/img/' . $immagineProfilo) ?>"
                   class="user-image"
                   alt="User Image" />
              <p>
                <small>Sei autenticato come:</small>
                <?= session()->get('nome_visualizzato') ?>
              </p>
            </li>
            <li class="user-footer">
              <div style="padding:0 0 10px 0; margin-bottom:10px; border-bottom:1px solid #eee;">
                <label for="chatBrowserNotifyToggle" style="font-weight:600; cursor:pointer; margin:0;">
                  <input type="checkbox" id="chatBrowserNotifyToggle" style="vertical-align:middle; margin-right:6px;">
                  Notifiche browser chat
                </label>
                <div style="font-size:12px; color:#777; margin-top:4px;">
                  Popup nel browser quando arrivano nuovi messaggi.
                </div>
              </div>
              <div class="pull-left">
              <a href="<?= base_url('profilo') ?>" class="btn btn-default btn-flat">Profilo</a>
              </div>
              <div class="pull-right">
                <a href="<?= base_url('logout') ?>" class="btn btn-default btn-flat">Logout</a>
              </div>
            </li>
          </ul>
        </li>
      </ul>
    </div>

  </nav>
</header>

<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
<script>
  window.CHAT_NOTIFY_CFG = {
    pollUrl: "<?= site_url('chat/poll') ?>",
    chatUrl: "<?= site_url('chat') ?>",
    chatThreadBase: "<?= rtrim(site_url('chat/thread'), '/') ?>",
    chatSoundUrl: "<?= base_url('public/sounds/chat.mp3') ?>"
  };
</script>
<script>
  window.CHAT_SOUND_URL = "<?= base_url('public/sounds/chat.mp3') ?>";
</script>
<script>
  window.PUSH_MOBILE_CFG = {
    vapidKey: "<?= esc(env('VAPID_PUBLIC_KEY', '')) ?>",
    swUrl: "<?= base_url('sw.js') ?>",
    registerUrl: "<?= base_url('profilo/device/register-here') ?>",
    syncPermissionUrl: "<?= base_url('push/sync-permission') ?>",
    userId: <?= $pushMobileUserId ?>,
    csrfName: "<?= csrf_token() ?>",
    csrfHash: "<?= csrf_hash() ?>"
  };
</script>
<script>
(function () {
  'use strict';

  var cfg = window.PUSH_MOBILE_CFG || {};
  if (!cfg.vapidKey || !cfg.registerUrl || !cfg.swUrl) return;
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return;
  if (!isMobileUA()) return;
  if (!(window.isSecureContext || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) return;

  var now = Date.now();
  var promptCooldownMs = 24 * 60 * 60 * 1000;
  var lastPromptAt = parseInt(localStorage.getItem('push_mobile_last_prompt_at') || '0', 10) || 0;

  if (Notification.permission === 'denied') {
    syncDeniedPermissionAndCleanup().catch(function () {});
    return;
  }
  if (Notification.permission === 'default' && (now - lastPromptAt) < promptCooldownMs) return;

  start().catch(function () {});

  async function start() {
    var reg = await navigator.serviceWorker.register(cfg.swUrl);
    var sub = await reg.pushManager.getSubscription();

    if (!sub) {
      if (Notification.permission !== 'granted') {
        localStorage.setItem('push_mobile_last_prompt_at', String(Date.now()));
        var perm = await Notification.requestPermission();
        if (perm !== 'granted') return;
      }

      sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(cfg.vapidKey)
      });
    }

    if (!sub) return;

    var endpoint = sub.endpoint || '';
    var endpointKey = registrationStorageKey();
    if (endpoint && localStorage.getItem(endpointKey) === endpoint) return;

    var body = new URLSearchParams({
      endpoint: endpoint,
      p256dh: arrayBufferToBase64(sub.getKey('p256dh')),
      auth: arrayBufferToBase64(sub.getKey('auth')),
      device_label: await getDeviceName()
    });

    if (cfg.csrfName && cfg.csrfHash) {
      body.append(cfg.csrfName, cfg.csrfHash);
    }

    var res = await fetch(cfg.registerUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      credentials: 'same-origin'
    });

    if (!res.ok) return;

    var json = null;
    try { json = await res.json(); } catch (_) {}
    if (json && json.ok && endpoint) {
      localStorage.setItem(endpointKey, endpoint);
    }
  }

  async function syncDeniedPermissionAndCleanup() {
    var endpoint = '';

    try {
      var reg = await navigator.serviceWorker.register(cfg.swUrl);
      var sub = await reg.pushManager.getSubscription();
      if (sub) {
        endpoint = sub.endpoint || '';
        try { await sub.unsubscribe(); } catch (_) {}
      }
    } catch (_) {}

    localStorage.removeItem(registrationStorageKey());
    localStorage.removeItem('push_mobile_registered_endpoint');

    if (!cfg.syncPermissionUrl) return;

    var body = new URLSearchParams({ permission: 'denied' });
    if (endpoint) body.append('endpoint', endpoint);
    if (cfg.csrfName && cfg.csrfHash) {
      body.append(cfg.csrfName, cfg.csrfHash);
    }

    try {
      await fetch(cfg.syncPermissionUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
        credentials: 'same-origin'
      });
    } catch (_) {}
  }

  function isMobileUA() {
    var ua = (navigator.userAgent || '').toLowerCase();
    return /iphone|ipod|ipad|android|mobi|mobile/.test(ua);
  }

  function registrationStorageKey() {
    var userId = parseInt(cfg.userId || 0, 10) || 0;
    return 'push_mobile_registered_endpoint:' + (userId > 0 ? String(userId) : 'guest');
  }

  function urlBase64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - base64String.length % 4) % 4);
    var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    var rawData = atob(base64);
    var outputArray = new Uint8Array(rawData.length);
    for (var i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
    return outputArray;
  }

  function arrayBufferToBase64(buf) {
    if (!buf) return '';
    return btoa(String.fromCharCode.apply(null, new Uint8Array(buf)));
  }

  async function getDeviceName() {
    try {
      var ua = navigator.userAgent || '';
      var platform = (navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || 'Dispositivo';
      var match = /SM-[A-Z0-9]+|M200[0-9]+|Mi\s?[0-9A-Za-z]+|Redmi\s?[0-9A-Za-z]+|iPhone|iPad|Pixel\s?\d+/i.exec(ua);
      var model = match ? match[0] + ' ' : '';
      return (model + platform).trim();
    } catch (_) {
      return 'Dispositivo';
    }
  }
})();
</script>
<script>
(function () {
  // Fallback robusto per menu avatar: funziona anche se il dropdown Bootstrap
  // viene alterato da script caricati nelle singole pagine.
  document.addEventListener('DOMContentLoaded', function () {
    var trigger = document.querySelector('.user.user-menu > a.dropdown-toggle');
    var menuLi = document.querySelector('.user.user-menu');
    if (!trigger || !menuLi) return;

    function closeUserMenu() {
      menuLi.classList.remove('open');
    }

    trigger.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var isOpen = menuLi.classList.contains('open');
      closeUserMenu();
      if (!isOpen) menuLi.classList.add('open');
    });

    document.addEventListener('click', function (e) {
      if (!e.target.closest('.user.user-menu')) {
        closeUserMenu();
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeUserMenu();
    });

    var hambLi = document.querySelector('li.visible-xs-inline-block.dropdown');
    var hambTrigger = hambLi ? hambLi.querySelector('a.dropdown-toggle') : null;
    if (hambLi && hambTrigger) {
      function closeHambMenu() {
        hambLi.classList.remove('open');
      }

      hambTrigger.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var isOpen = hambLi.classList.contains('open');
        closeHambMenu();
        if (!isOpen) hambLi.classList.add('open');
      });

      document.addEventListener('click', function (e) {
        if (!e.target.closest('li.visible-xs-inline-block.dropdown')) {
          closeHambMenu();
        }
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeHambMenu();
      });
    }
  });

  var KEY = 'chat_browser_notify_enabled';

  function getEnabled() {
    var raw = localStorage.getItem(KEY);
    if (raw === null) return true;
    return raw === '1';
  }

  function setEnabled(v) {
    localStorage.setItem(KEY, v ? '1' : '0');
  }

  window.CHAT_BROWSER_NOTIFY = {
    isEnabled: getEnabled
  };

  var NativeNotification = window.Notification;
  if (typeof NativeNotification === 'function') {
    function NotificationProxy(title, options) {
      if (!getEnabled()) {
        throw new Error('Browser notifications disabled by user');
      }
      return new NativeNotification(title, options);
    }
    NotificationProxy.permission = NativeNotification.permission;
    NotificationProxy.requestPermission = function () {
      return NativeNotification.requestPermission();
    };
    try {
      window.Notification = NotificationProxy;
    } catch (_) {}
  }

  function syncToggleUI() {
    var cb = document.getElementById('chatBrowserNotifyToggle');
    if (!cb) return;
    cb.checked = getEnabled();
  }

  document.addEventListener('DOMContentLoaded', function () {
    var cb = document.getElementById('chatBrowserNotifyToggle');
    if (!cb) return;

    syncToggleUI();

    cb.addEventListener('change', function () {
      var enabled = !!cb.checked;
      setEnabled(enabled);

      if (!enabled) return;
      if (typeof NativeNotification !== 'function') return;
      if (NativeNotification.permission === 'default') {
        NativeNotification.requestPermission().then(function (perm) {
          if (perm !== 'granted') {
            setEnabled(false);
            cb.checked = false;
          }
        }).catch(function () {
          setEnabled(false);
          cb.checked = false;
        });
      }
    });
  });
})();
</script>
<script src="<?= base_url('js/chat-notify.js') ?>"></script>
