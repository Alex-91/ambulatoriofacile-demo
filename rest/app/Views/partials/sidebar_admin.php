<?php
helper(['admin_menu', 'portal']);

if (empty($menu_items) || !is_array($menu_items)) {
    $menuDataAdmin = session()->get('menuDataAdmin');
    $menu_items = is_array($menuDataAdmin['result'] ?? null) ? $menuDataAdmin['result'] : [];
}

$sess = session();
$currentMenuUserId = (int) ($sess->get('id_user') ?? 0);
if ($currentMenuUserId <= 0) {
    $sessionUser = $sess->get('utente_sess');
    if (is_object($sessionUser) && !empty($sessionUser->id_user)) {
        $currentMenuUserId = (int) $sessionUser->id_user;
    }
}

$adminMenuVisibility = new \App\Services\AdminMenuVisibilityService();
if ($currentMenuUserId > 0) {
    $menu_items = $adminMenuVisibility->filterMenuRowsForUser($menu_items, $currentMenuUserId);
}

$normalizePath = static function (?string $path): string {
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }

    $parsedPath = parse_url($path, PHP_URL_PATH);
    if (is_string($parsedPath) && $parsedPath !== '') {
        $path = $parsedPath;
    }

    return trim(str_replace('\\', '/', $path), '/');
};

$currentPath = strtolower($normalizePath(service('uri')->getPath()));
$tenantContext = $sess->get('tenant_context');
$tenantName = is_array($tenantContext) ? trim((string) ($tenantContext['tenant_name'] ?? '')) : '';
$tenantId = is_array($tenantContext) ? (int) ($tenantContext['tenant_id'] ?? 0) : 0;
$tenantRole = is_array($tenantContext) ? trim((string) ($tenantContext['tenant_role'] ?? '')) : '';
$tenantFeatureFlags = is_array($tenantContext) ? (array) ($tenantContext['feature_flags'] ?? []) : [];
$isTenantOperationalConsoleSession = $tenantId > 0 && in_array($tenantRole, ['tenant_master', 'tenant_admin'], true);
$isTenantMasterOperational = $tenantId > 0 && $tenantRole === 'tenant_master';
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
$canOpenAgenda = $tenantId > 0 || session()->get('is_admin') === true || (int) (session()->get('admin') ?? 0) === 1;
$tenantOperationalHomeUrl = $tenantId > 0 ? portal_operational_home_url() : null;
$platformTenants = $sess->get('platform_selectable_tenants');
$platformTenants = is_array($platformTenants) ? $platformTenants : [];
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

$isLinkActive = static function (string $href) use ($normalizePath, $currentPath): bool {
    $itemPath = strtolower($normalizePath($href));
    if ($itemPath === '') {
        return false;
    }

    return $currentPath === $itemPath || str_starts_with($currentPath, $itemPath . '/');
};

$primaryAction = null;
$secondaryPrimaryAction = null;
$contextActions = [];
$accountActions = [];

if ($isTenantOperationalConsoleSession && $tenantOperationalHomeUrl !== null) {
    $primaryAction = [
        'href' => $tenantOperationalHomeUrl,
        'label' => 'Vai al portale operativo',
        'icon' => 'fa-home',
        'active' => $isLinkActive($tenantOperationalHomeUrl),
    ];
}

if ($canOpenAgenda) {
    $secondaryPrimaryAction = [
        'href' => site_url('agenda'),
        'label' => 'Vai in agenda',
        'icon' => 'fa-calendar',
        'active' => $isLinkActive(site_url('agenda')),
    ];
}

if ($isTenantOperationalConsoleSession) {
    if ($platformTenants !== []) {
        foreach ($platformTenants as $availableTenant) {
            $availableTenantId = (int) ($availableTenant['id_tenant'] ?? 0);
            if ($availableTenantId <= 0) {
                continue;
            }

            $tenantLabel = trim((string) ($availableTenant['tenant_name'] ?? $availableTenant['tenant_key'] ?? 'Spazio cliente'));
            $isCurrentTenant = $availableTenantId === $tenantId;
            $contextActions[] = [
                'href' => $isCurrentTenant && $tenantOperationalHomeUrl !== null
                    ? $tenantOperationalHomeUrl
                    : portal_tenant_switch_url($availableTenantId),
                'label' => $isCurrentTenant ? $tenantLabel . ' (attivo)' : 'Apri spazio: ' . $tenantLabel,
                'icon' => 'fa-exchange',
                'active' => $isCurrentTenant,
            ];
        }
    }

    if ($showDemoRoleSwitch) {
        if ($demoAccessUrl !== '') {
            $contextActions[] = [
                'href' => $demoAccessUrl,
                'label' => 'Apri selettore ruoli demo',
                'icon' => 'fa-random',
                'active' => false,
            ];
        }

        foreach ($demoSwitchAccounts as $demoSwitchAccount) {
            if (!is_array($demoSwitchAccount)) {
                continue;
            }

            $demoSwitchLabel = trim((string) ($demoSwitchAccount['role'] ?? $demoSwitchAccount['label'] ?? 'Ruolo demo'));
            $demoSwitchDetail = trim((string) ($demoSwitchAccount['label'] ?? ''));
            $demoSwitchUrl = trim((string) ($demoSwitchAccount['entry_url'] ?? ''));
            $demoSwitchCurrent = (bool) ($demoSwitchAccount['is_current'] ?? false);

            $contextActions[] = [
                'href' => $demoSwitchCurrent || $demoSwitchUrl === '' ? '#' : $demoSwitchUrl,
                'label' => $demoSwitchLabel . ($demoSwitchDetail !== '' ? ' - ' . $demoSwitchDetail : '') . ($demoSwitchCurrent ? ' (attivo)' : ''),
                'icon' => 'fa-user-secret',
                'active' => $demoSwitchCurrent,
                'disabled' => $demoSwitchCurrent || $demoSwitchUrl === '',
            ];
        }
    }

    if ($canAccessPlatformConsole) {
        $contextActions[] = [
            'href' => portal_platform_url('spazi-clienti'),
            'label' => 'Console piattaforma',
            'icon' => 'fa-sitemap',
            'active' => $isLinkActive(portal_platform_url('spazi-clienti')),
        ];
    }

    if ($canManageTenantUsers) {
        $contextActions[] = [
            'href' => portal_tenant_space_url('utenti'),
            'label' => 'Gestisci utenti dello spazio',
            'icon' => 'fa-users',
            'active' => $isLinkActive(portal_tenant_space_url('utenti')),
        ];
    }

    if ($canManageTenantFeatures) {
        $contextActions[] = [
            'href' => portal_tenant_space_url('funzioni'),
            'label' => 'Gestisci funzioni dello spazio',
            'icon' => 'fa-toggle-on',
            'active' => $isLinkActive(portal_tenant_space_url('funzioni')),
        ];
    }

    if ($canManageAppointmentNotifications) {
        $contextActions[] = [
            'href' => portal_tenant_space_url('notifiche-appuntamenti'),
            'label' => 'Gestisci notifiche appuntamenti',
            'icon' => 'fa-commenting',
            'active' => $isLinkActive(portal_tenant_space_url('notifiche-appuntamenti')),
        ];
    }

    if (!$isPlatformConsoleSession && !$isTenantMasterOperational) {
        $accountActions[] = [
            'href' => base_url('profilo'),
            'label' => 'Profilo',
            'icon' => 'fa-user',
            'active' => $isLinkActive(base_url('profilo')),
        ];
    }

    $accountActions[] = [
        'href' => base_url('logout'),
        'label' => 'Logout',
        'icon' => 'fa-sign-out',
        'active' => false,
    ];
}

if ($currentMenuUserId > 0) {
    $contextActions = $adminMenuVisibility->filterContextActionsForUser($contextActions, $currentMenuUserId);
}

$adminVisitTypesFeatureEnabled = false;
if ($tenantId > 0) {
    try {
        $adminFeatureMap = (new \App\Services\TenantFeatureService())->resolveEffectiveFeatureMapForTenant($tenantId);
        $adminVisitTypesFeatureEnabled = !empty($adminFeatureMap['agenda_visit_types']);
    } catch (\Throwable $e) {
        $adminVisitTypesFeatureEnabled = false;
    }
}

if ($adminVisitTypesFeatureEnabled) {
    $hasVisitTypesMenu = false;
    foreach ($menu_items as $menuRow) {
        $menuLink = trim((string) ($menuRow['link'] ?? ''));
        if (strtolower($normalizePath($menuLink)) === 'agenda/gestione-tipi-visita') {
            $hasVisitTypesMenu = true;
            break;
        }
    }

    if (!$hasVisitTypesMenu) {
        $menu_items[] = [
            'titolo_menu' => 'Tipi visita',
            'link' => 'agenda/gestione-tipi-visita',
            'class_icon' => 'fa-list-alt',
        ];
    }
}
?>
<div class="box box-solid" style="margin-bottom:0 !important">
  <div class="box-header with-border">
    <h3 class="box-title">Menu</h3>
    <?php if ($tenantName !== ''): ?>
      <div class="text-muted" style="margin-top:6px; font-size:12px;">
        Spazio attivo: <?= esc($tenantName) ?>
      </div>
    <?php endif; ?>
    <div class="box-tools">
      <button class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button>
    </div>
  </div>
  <div class="box-body no-padding">
    <?php if ($primaryAction !== null): ?>
      <div style="padding:15px 15px 0;">
        <a href="<?= esc((string) $primaryAction['href']) ?>"
           class="btn btn-primary btn-block"
           style="background:#2c8895; border-color:#24747f; font-weight:700;">
          <i class="fa <?= esc((string) $primaryAction['icon']) ?>"></i>
          <?= esc((string) $primaryAction['label']) ?>
        </a>
      </div>
    <?php endif; ?>

    <?php if ($secondaryPrimaryAction !== null): ?>
      <div style="padding:12px 15px 0;">
        <a href="<?= esc((string) $secondaryPrimaryAction['href']) ?>"
           class="btn btn-info btn-block"
           style="font-weight:700;">
          <i class="fa <?= esc((string) $secondaryPrimaryAction['icon']) ?>"></i>
          <?= esc((string) $secondaryPrimaryAction['label']) ?>
        </a>
      </div>
    <?php endif; ?>

    <ul class="nav nav-pills nav-stacked" style="margin-top:<?= ($primaryAction !== null || $secondaryPrimaryAction !== null) ? '12px' : '0' ?>;">
      <?php if ($menu_items === []): ?>
        <li class="disabled">
          <a href="#">
            <i class="fa fa-circle-o"></i>
            Nessuna voce menu configurata
          </a>
        </li>
      <?php endif; ?>

      <?php foreach ($menu_items as $menu): ?>
        <?php
          $menuLink = trim((string) ($menu['link'] ?? ''));
          $normalizedMenuLink = strtolower($normalizePath($menuLink));
          if ($normalizedMenuLink === '' || $normalizedMenuLink === 'logout' || $normalizedMenuLink === 'admin/personale/logout') {
              continue;
          }

          $menuLabel = admin_menu_pretty_title((string) ($menu['titolo_menu'] ?? ''), $menuLink);
          $icon = admin_menu_resolve_icon(
              (string) ($menu['icon'] ?? $menu['class_icon'] ?? ''),
              $menuLabel,
              $menuLink
          );
          $itemHref = admin_menu_resolve_href($menuLink);
          $isActive = $isLinkActive($itemHref);
        ?>
        <li class="<?= $isActive ? 'active' : '' ?>">
          <a href="<?= esc($itemHref) ?>">
            <i class="fa <?= esc($icon) ?>"></i>
            <?= esc($menuLabel) ?>
            <?php if (!empty($menu['conteggio'])): ?>
              <span class="label label-primary pull-right"><?= esc($menu['conteggio']) ?></span>
            <?php endif; ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php if ($contextActions !== []): ?>
      <div style="padding:14px 15px 6px; color:#7d8b8f; font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase;">
        Spazio e accessi
      </div>
      <ul class="nav nav-pills nav-stacked">
        <?php foreach ($contextActions as $action): ?>
          <?php
            $actionHref = (string) ($action['href'] ?? '#');
            $actionActive = !empty($action['active']) || $isLinkActive($actionHref);
            $actionDisabled = !empty($action['disabled']);
          ?>
          <li class="<?= $actionActive ? 'active' : ($actionDisabled ? 'disabled' : '') ?>">
            <?php if ($actionDisabled): ?>
              <a href="#">
                <i class="fa <?= esc((string) ($action['icon'] ?? 'fa-circle-o')) ?>"></i>
                <?= esc((string) ($action['label'] ?? 'Voce')) ?>
              </a>
            <?php else: ?>
              <a href="<?= esc($actionHref) ?>">
                <i class="fa <?= esc((string) ($action['icon'] ?? 'fa-circle-o')) ?>"></i>
                <?= esc((string) ($action['label'] ?? 'Voce')) ?>
              </a>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($accountActions !== []): ?>
      <div style="padding:14px 15px 6px; color:#7d8b8f; font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase;">
        Account
      </div>
      <ul class="nav nav-pills nav-stacked">
        <?php foreach ($accountActions as $action): ?>
          <li class="<?= !empty($action['active']) ? 'active' : '' ?>">
            <a href="<?= esc((string) ($action['href'] ?? '#')) ?>">
              <i class="fa <?= esc((string) ($action['icon'] ?? 'fa-circle-o')) ?>"></i>
              <?= esc((string) ($action['label'] ?? 'Voce')) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
