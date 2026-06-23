<?php
helper('admin_menu');
helper('portal');

$sess = session();
$headerMenuUserId = (int) ($sess->get('id_user') ?? 0);
if ($headerMenuUserId <= 0) {
    $headerSessionUser = $sess->get('utente_sess');
    if (is_object($headerSessionUser) && !empty($headerSessionUser->id_user)) {
        $headerMenuUserId = (int) $headerSessionUser->id_user;
    }
}
$headerMenuVisibility = new \App\Services\AdminMenuVisibilityService();

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
$tenantContext = $sess->get('tenant_context');
$tenantName = is_array($tenantContext) ? trim((string)($tenantContext['tenant_name'] ?? '')) : '';
$tenantId = is_array($tenantContext) ? (int)($tenantContext['tenant_id'] ?? 0) : 0;
$tenantRole = is_array($tenantContext) ? trim((string)($tenantContext['tenant_role'] ?? '')) : '';
$isTenantOperationalConsoleSession = $tenantId > 0 && in_array($tenantRole, ['tenant_master', 'tenant_admin'], true);
$hideHeaderMenu = str_starts_with($currentPath, 'admin') || $isTenantOperationalConsoleSession;
$tenantFeatureFlags = is_array($tenantContext) ? (array)($tenantContext['feature_flags'] ?? []) : [];
$chatFeatureEnabled = $tenantId <= 0 || !empty($tenantFeatureFlags['chat']);
$isChatRoute = $currentPath === 'chat' || str_starts_with($currentPath, 'chat/');
$chatUiActive = $chatFeatureEnabled && $isChatRoute;
$platformTenants = $sess->get('platform_selectable_tenants');
$platformTenants = is_array($platformTenants) ? $platformTenants : [];
$platformTenantCount = count($platformTenants);
$showTenantSwitchSection = $platformTenantCount > 0;
$tenantSwitchSectionTitle = $platformTenantCount > 1 ? 'Cambia spazio' : 'Apri spazio';
$canAccessPlatformConsole = (bool) ($sess->get('platform_is_admin') ?? false) === true;
$isPlatformConsoleSession = $canAccessPlatformConsole
    && (string) ($sess->get('loginSource') ?? '') === 'platform_console';
$canManageTenantFeatures = $tenantId > 0
    && $tenantRole === 'tenant_master'
    && (int) ($sess->get('platform_user_id') ?? 0) > 0;
$canManageAppointmentNotifications = $tenantId > 0
    && $tenantRole === 'tenant_master'
    && (int) ($sess->get('platform_user_id') ?? 0) > 0
    && !empty($tenantFeatureFlags['appointment_notifications']);
$canManageTenantUsers = $tenantId > 0
    && in_array($tenantRole, ['tenant_master', 'tenant_admin'], true)
    && !empty($tenantFeatureFlags['staff_management']);
$showTenantOnboardingLink = $tenantId > 0
    && $tenantRole === 'tenant_master'
    && in_array(strtolower(trim((string)($tenantContext['onboarding_status'] ?? 'draft'))), ['draft', 'setup'], true);
$isTenantOnboardingRoute = in_array($currentPath, ['login/spazio/onboarding', 'spazio/onboarding'], true);
$useMinimalTenantOnboardingHeader = $isTenantOnboardingRoute
    && $tenantRole === 'tenant_master'
    && $showTenantOnboardingLink;
$hideHeaderMenu = $hideHeaderMenu || $useMinimalTenantOnboardingHeader;
$tenantOperationalHomeUrl = $tenantId > 0 ? portal_operational_home_url() : null;
$moveHeaderActionsToSidebar = $isTenantOperationalConsoleSession && !$useMinimalTenantOnboardingHeader;
$showHeaderProfileAction = !$isPlatformConsoleSession && !$moveHeaderActionsToSidebar;
$portalConsoleHeaderOverride = isset($portal_console_header) ? (bool) $portal_console_header : null;
$portalConsolePrefixes = ['login', 'spazio', 'piattaforma'];
$isPortalConsoleRoute = false;
foreach ($portalConsolePrefixes as $portalConsolePrefix) {
    if ($currentPath === $portalConsolePrefix || str_starts_with($currentPath, $portalConsolePrefix . '/')) {
        $isPortalConsoleRoute = true;
        break;
    }
}
$isPortalConsoleHeader = $portalConsoleHeaderOverride ?? $isPortalConsoleRoute;
$profileImageFallbackUrl = base_url('public/dist/img/user.png');
$demoSessionActive = (bool) ($sess->get(\App\Services\DemoAccessService::SESSION_KEY_ACTIVE) ?? false);
$demoCurrentAccount = $sess->get(\App\Services\DemoAccessService::SESSION_KEY_CURRENT);
$demoSwitchAccounts = $sess->get(\App\Services\DemoAccessService::SESSION_KEY_SWITCH_ACCOUNTS);
$currentSessionUsername = trim((string) ($sess->get('username') ?? ''));
$demoCurrentSessionUsername = is_array($demoCurrentAccount)
    ? trim((string) ($demoCurrentAccount['session_username'] ?? $demoCurrentAccount['username'] ?? ''))
    : '';
$showDemoRoleSwitch = $demoSessionActive
    && is_array($demoCurrentAccount)
    && is_array($demoSwitchAccounts)
    && $currentSessionUsername !== ''
    && $demoCurrentSessionUsername !== ''
    && strcasecmp($currentSessionUsername, $demoCurrentSessionUsername) === 0;
$demoAccessUrl = $showDemoRoleSwitch
    ? trim((string) ($demoCurrentAccount['access_url'] ?? site_url('access')))
    : '';
$headerLogoUrl = $isPortalConsoleHeader
    ? (($isTenantOperationalConsoleSession && $tenantOperationalHomeUrl !== null)
        ? $tenantOperationalHomeUrl
        : ($demoSessionActive ? site_url('access') : portal_public_access_url('login')))
    : (($hideHeaderMenu || (bool) ($sess->get('is_admin') ?? false) === true)
        ? portal_operational_home_url()
        : site_url('/'));

if (!$chatFeatureEnabled) {
    $badgeChat = 0;
    if ((int)($sess->get('badge_chat_unread') ?? 0) !== 0) {
        $sess->set('badge_chat_unread', 0);
    }
}
?>

<header class="main-header" style="background:#2c8895">
  <!-- Logo -->
  <a href="<?= esc($headerLogoUrl) ?>" class="logo">
    <span class="logo-mini"><b>Ambulatorio</b>Facile</span>
    <span class="logo-lg"><b>Ambulatorio</b>Facile</span>
  </a>

  <nav class="navbar navbar-static-top<?= $isPortalConsoleHeader ? ' platform-console-navbar' : '' ?>" role="navigation">
    <a href="#" class="sidebar-toggle" data-toggle="offcanvas" role="button" style="display:none">
      <span class="sr-only">Toggle navigation</span>
      <span class="icon-bar"></span>
      <span class="icon-bar"></span>
      <span class="icon-bar"></span>
    </a>

    <?php if ($isPortalConsoleHeader): ?>
    <div class="platform-navbar-shell<?= $useMinimalTenantOnboardingHeader ? ' platform-navbar-shell-minimal' : '' ?>">
    <?php endif; ?>

    <?php if ($tenantName !== '' && !$useMinimalTenantOnboardingHeader): ?>
      <?php if ($isPortalConsoleHeader): ?>
      <div class="platform-navbar-tenant hidden-xs">
        <span class="platform-navbar-tenant-label">Spazio</span>
        <strong class="platform-navbar-tenant-name"><?= esc($tenantName) ?></strong>
      </div>
      <?php else: ?>
      <div class="navbar-text hidden-xs" style="float:left; color:#e8f6f8; margin-left:16px; font-weight:600;">
        Spazio: <?= esc($tenantName) ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (!$hideHeaderMenu): ?>
    <div class="navbar-custom-menu<?= $isPortalConsoleHeader ? ' platform-navbar-primary' : '' ?>"<?= $isPortalConsoleHeader ? '' : ' style="float:left !important"' ?>>
      <ul class="nav navbar-nav<?= $isPortalConsoleHeader ? ' platform-navbar-links' : '' ?>">

<?php
$disableMenuFallback = !empty($disable_menu_fallback);
$navItemsUseAdminResolver = false;

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
                $navItemsUseAdminResolver = true;
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

if (!$chatFeatureEnabled && !empty($navItems) && is_array($navItems)) {
    $navItems = array_values(array_filter($navItems, static function ($item): bool {
        if (!is_array($item)) {
            return false;
        }

        $link = strtolower(trim((string)($item['link'] ?? '')));
        $title = strtolower(trim((string)($item['titolo'] ?? '')));

        return !str_contains($link, 'chat') && !str_contains($title, 'chat');
    }));
}

if ($headerMenuUserId > 0) {
    $navItems = $headerMenuVisibility->filterMenuRowsForUser($navItems, $headerMenuUserId);
}
?>

<?php foreach ($navItems as $item): ?>
  <?php
    $itemLink = (string)($item['link'] ?? '');
    $itemTitle = admin_menu_pretty_title((string)($item['titolo'] ?? ''), $itemLink);
    $itemIcon = admin_menu_resolve_icon((string)($item['fa_icon'] ?? ''), $itemTitle, $itemLink);
    $itemHref = $navItemsUseAdminResolver ? admin_menu_resolve_href($itemLink) : site_url($itemLink);
  ?>
  <li class="hidden-xs">
    <a href="<?= esc($itemHref) ?>" title="<?= esc($itemTitle) ?>"<?= $isPortalConsoleHeader ? ' class="platform-nav-link"' : '' ?>>
      <i class="fa <?= esc($itemIcon) ?>"></i>
      <span class="nav-label" style="position:relative; display:inline-block;">
        <?= esc($itemTitle) ?>

        <?php $isChat = str_contains(strtolower($itemLink), 'chat') || str_contains(strtolower($itemTitle), 'chat'); ?>
        <?php $showItemBadge = !$isChat || $chatUiActive; ?>
<?php if ($showItemBadge && !empty($item['badge']) && (int)$item['badge'] > 0): ?>
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
        $itemHref = $navItemsUseAdminResolver ? admin_menu_resolve_href($itemLink) : site_url($itemLink);
        $isChat = str_contains(strtolower($itemLink), 'chat') || str_contains(strtolower($itemTitle), 'chat');
        $showItemBadge = !$isChat || $chatUiActive;
      ?>
      <li style="position:relative;">
        <a href="<?= esc($itemHref) ?>">
          <i class="fa <?= esc($itemIcon) ?>"></i> <?= esc($itemTitle) ?>

          <?php if ($showItemBadge && !empty($item['badge']) && (int)$item['badge'] > 0): ?>
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

    <div class="navbar-custom-menu<?= $isPortalConsoleHeader ? ' platform-navbar-secondary' : '' ?>">
      <ul class="nav navbar-nav">
        <li class="dropdown user user-menu platform-user-menu<?= $useMinimalTenantOnboardingHeader ? ' platform-user-menu-minimal' : '' ?>">
          <a href="#" class="dropdown-toggle platform-user-toggle" data-toggle="dropdown">
            <?php
              $immagineProfilo = session()->get('immagine_profilo');
              if (empty($immagineProfilo)) {
                  $immagineProfilo = 'user.png';
              }
            ?>
            <img src="<?= base_url('public/dist/img/' . $immagineProfilo) ?>"
                 onerror="this.onerror=null;this.src='<?= esc($profileImageFallbackUrl, 'attr') ?>';"
                 class="user-image"
                 alt="User Image" />
            <span class="hidden-xs platform-user-name"><?= session()->get('nome_visualizzato') ?></span>
          </a>

          <ul class="dropdown-menu platform-user-dropdown<?= $useMinimalTenantOnboardingHeader ? ' platform-user-dropdown-minimal' : '' ?>">
            <li class="user-header platform-user-summary">
              <img src="<?= base_url('public/dist/img/' . $immagineProfilo) ?>"
                   onerror="this.onerror=null;this.src='<?= esc($profileImageFallbackUrl, 'attr') ?>';"
                   class="user-image"
                   alt="User Image" />
              <p class="platform-user-summary-text">
                <small>Sei autenticato come:</small>
                <?= session()->get('nome_visualizzato') ?>
                <?php if ($tenantName !== ''): ?>
                  <br><small>Spazio attivo: <?= esc($tenantName) ?></small>
                <?php endif; ?>
              </p>
            </li>
            <li class="user-footer platform-user-footer">
              <?php if (!$moveHeaderActionsToSidebar): ?>
              <div class="platform-user-sections">
              <?php if (!$useMinimalTenantOnboardingHeader && $tenantOperationalHomeUrl !== null): ?>
              <div class="platform-user-section">
                <a href="<?= esc($tenantOperationalHomeUrl) ?>" class="btn btn-default btn-flat platform-user-action">
                  <i class="fa fa-home"></i> Vai al portale operativo
                </a>
              </div>
              <?php endif; ?>
              <?php if (!$useMinimalTenantOnboardingHeader && $showTenantSwitchSection): ?>
              <div class="platform-user-section">
                <div class="platform-user-section-title"><?= esc($tenantSwitchSectionTitle) ?></div>
                <?php foreach ($platformTenants as $availableTenant): ?>
                  <?php
                    $availableTenantId = (int)($availableTenant['id_tenant'] ?? 0);
                    $isCurrentTenant = $availableTenantId === $tenantId;
                    $tenantLabel = trim((string)($availableTenant['tenant_name'] ?? $availableTenant['tenant_key'] ?? 'Spazio cliente'));
                    $tenantSwitchUrl = portal_tenant_switch_url($availableTenantId);
                  ?>
                  <div class="platform-user-option">
                    <?php if ($isCurrentTenant): ?>
                      <span class="btn btn-default btn-flat platform-user-action platform-user-action-current">
                        <?= esc($tenantLabel) ?> (attivo)
                      </span>
                    <?php else: ?>
                      <a href="<?= esc($tenantSwitchUrl) ?>" class="btn btn-default btn-flat platform-user-action">
                        <?= esc($tenantLabel) ?>
                      </a>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
              <?php if (!$useMinimalTenantOnboardingHeader && $showDemoRoleSwitch): ?>
              <div class="platform-user-section">
                <div class="platform-user-section-title">Cambia ruolo demo</div>
                <?php if ($demoAccessUrl !== ''): ?>
                  <div class="platform-user-option">
                    <a href="<?= esc($demoAccessUrl) ?>" class="btn btn-default btn-flat platform-user-action">
                      <i class="fa fa-random"></i> Apri selettore ruoli demo
                    </a>
                  </div>
                <?php endif; ?>
                <?php foreach ($demoSwitchAccounts as $demoSwitchAccount): ?>
                  <?php
                    if (!is_array($demoSwitchAccount)) {
                        continue;
                    }
                    $demoSwitchLabel = trim((string) ($demoSwitchAccount['role'] ?? $demoSwitchAccount['label'] ?? 'Ruolo demo'));
                    $demoSwitchDetail = trim((string) ($demoSwitchAccount['label'] ?? ''));
                    $demoSwitchUrl = trim((string) ($demoSwitchAccount['entry_url'] ?? ''));
                    $demoSwitchCurrent = (bool) ($demoSwitchAccount['is_current'] ?? false);
                  ?>
                  <div class="platform-user-option">
                    <?php if ($demoSwitchCurrent || $demoSwitchUrl === ''): ?>
                      <span class="btn btn-default btn-flat platform-user-action platform-user-action-current">
                        <?= esc($demoSwitchLabel) ?><?= $demoSwitchDetail !== '' ? ' · ' . esc($demoSwitchDetail) : '' ?><?= $demoSwitchCurrent ? ' (attivo)' : '' ?>
                      </span>
                    <?php else: ?>
                      <a href="<?= esc($demoSwitchUrl) ?>" class="btn btn-default btn-flat platform-user-action">
                        <?= esc($demoSwitchLabel) ?><?= $demoSwitchDetail !== '' ? ' · ' . esc($demoSwitchDetail) : '' ?>
                      </a>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
              <?php if (!$useMinimalTenantOnboardingHeader && $canAccessPlatformConsole): ?>
              <div class="platform-user-section">
                <a href="<?= portal_platform_url('spazi-clienti') ?>" class="btn btn-default btn-flat platform-user-action">
                  <i class="fa fa-sitemap"></i> Console piattaforma
                </a>
              </div>
              <?php endif; ?>
              <?php if (
                  !$useMinimalTenantOnboardingHeader
                  && $canManageTenantUsers
                  && ($headerMenuUserId <= 0 || $headerMenuVisibility->canUserSeeMenuLink($headerMenuUserId, 'spazio/utenti'))
              ): ?>
              <div class="platform-user-section">
                <a href="<?= portal_tenant_space_url('utenti') ?>" class="btn btn-default btn-flat platform-user-action">
                  <i class="fa fa-users"></i> Gestisci utenti dello spazio
                </a>
              </div>
              <?php endif; ?>
              <?php if (
                  !$useMinimalTenantOnboardingHeader
                  && $canManageTenantFeatures
                  && ($headerMenuUserId <= 0 || $headerMenuVisibility->canUserSeeMenuLink($headerMenuUserId, 'spazio/funzioni'))
              ): ?>
              <div class="platform-user-section">
                <a href="<?= portal_tenant_space_url('funzioni') ?>" class="btn btn-default btn-flat platform-user-action">
                  <i class="fa fa-toggle-on"></i> Gestisci funzioni dello spazio
                </a>
              </div>
              <?php endif; ?>
              <?php if (
                  !$useMinimalTenantOnboardingHeader
                  && $canManageAppointmentNotifications
                  && ($headerMenuUserId <= 0 || $headerMenuVisibility->canUserSeeMenuLink($headerMenuUserId, 'spazio/notifiche-appuntamenti'))
              ): ?>
              <div class="platform-user-section">
                <a href="<?= portal_tenant_space_url('notifiche-appuntamenti') ?>" class="btn btn-default btn-flat platform-user-action">
                  <i class="fa fa-commenting"></i> Gestisci notifiche appuntamenti
                </a>
              </div>
              <?php endif; ?>
              <?php if (!$useMinimalTenantOnboardingHeader && !$isPlatformConsoleSession && $chatUiActive): ?>
              <div class="platform-user-section">
                <label for="chatBrowserNotifyToggle" class="platform-user-toggle-label">
                  <input type="checkbox" id="chatBrowserNotifyToggle" style="vertical-align:middle; margin-right:6px;">
                  Notifiche browser chat
                </label>
                <div class="platform-user-help">
                  Popup nel browser quando arrivano nuovi messaggi.
                </div>
              </div>
              <?php endif; ?>
              </div>
              <?php endif; ?>
              <div class="platform-user-footer-actions<?= $showHeaderProfileAction ? '' : ' platform-user-footer-actions-logout-only' ?>">
                <?php if ($showHeaderProfileAction): ?>
                <a href="<?= base_url('profilo') ?>" class="btn btn-default btn-flat">Profilo</a>
                <?php endif; ?>
                <a href="<?= base_url('logout') ?>" class="btn btn-default btn-flat">Logout</a>
              </div>
            </li>
          </ul>
        </li>
      </ul>
    </div>

    <?php if ($isPortalConsoleHeader): ?>
    </div>
    <?php endif; ?>

  </nav>
</header>

<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
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

  <?php if ($chatUiActive): ?>
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
  <?php endif; ?>
})();
</script>
