<?php
helper('admin_menu');
helper('portal');

$sess = session();

$tenantContext = $sess->get('tenant_context');
$tenantName = is_array($tenantContext) ? trim((string) ($tenantContext['tenant_name'] ?? '')) : '';
$tenantId = is_array($tenantContext) ? (int) ($tenantContext['tenant_id'] ?? 0) : 0;
$tenantRole = is_array($tenantContext) ? trim((string) ($tenantContext['tenant_role'] ?? '')) : '';
$tenantFeatureFlags = is_array($tenantContext) ? (array) ($tenantContext['feature_flags'] ?? []) : [];
$platformTenants = $sess->get('platform_selectable_tenants');
$platformTenants = is_array($platformTenants) ? $platformTenants : [];
$platformTenantCount = count($platformTenants);
$showTenantSwitchSection = $platformTenantCount > 0;
$tenantSwitchSectionTitle = $platformTenantCount > 1 ? 'Cambia spazio' : 'Apri spazio';
$canAccessPlatformConsole = (bool) ($sess->get('platform_is_admin') ?? false) === true;
$canManageTenantFeatures = $tenantId > 0
    && $tenantRole === 'tenant_master'
    && (int) ($sess->get('platform_user_id') ?? 0) > 0;
$canManageTenantUsers = $tenantId > 0
    && in_array($tenantRole, ['tenant_master', 'tenant_admin'], true)
    && !empty($tenantFeatureFlags['staff_management']);
$showTenantOnboardingLink = $tenantId > 0
    && $tenantRole === 'tenant_master'
    && in_array(strtolower(trim((string) ($tenantContext['onboarding_status'] ?? 'draft'))), ['draft', 'setup'], true);

$headerLogoUrl = portal_public_access_url('login');
$profileImage = trim((string) ($sess->get('immagine_profilo') ?? ''));
if ($profileImage === '') {
    $profileImage = 'user.png';
}

$profileImageUrl = base_url('public/dist/img/' . $profileImage);
$profileImageFallbackUrl = base_url('public/dist/img/user.png');
$displayName = trim((string) ($sess->get('nome_visualizzato') ?? ''));
if ($displayName === '') {
    $displayName = 'Account';
}

$navItems = $sess->get('header_nav_items');
if (empty($navItems) || !is_array($navItems)) {
    $fallbackItems = $menu_items ?? $sess->get('header_menu_items') ?? [];
    if (empty($fallbackItems) || !is_array($fallbackItems)) {
        $menuData = $sess->get('menuData');
        if (is_array($menuData) && !empty($menuData['result']) && is_array($menuData['result'])) {
            $fallbackItems = $menuData['result'];
        }
    }
    if (empty($fallbackItems) || !is_array($fallbackItems)) {
        $menuDataAdmin = $sess->get('menuDataAdmin');
        if (is_array($menuDataAdmin) && !empty($menuDataAdmin['result']) && is_array($menuDataAdmin['result'])) {
            $fallbackItems = $menuDataAdmin['result'];
        }
    }

    $navItems = [];
    if (is_array($fallbackItems)) {
        foreach ($fallbackItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemLink = trim((string) ($item['link'] ?? ''));
            if ($itemLink === '') {
                continue;
            }

            $navItems[] = [
                'link' => $itemLink,
                'titolo' => (string) ($item['titolo'] ?? $item['titolo_menu'] ?? $itemLink),
                'fa_icon' => (string) ($item['fa_icon'] ?? $item['class_icon'] ?? 'fa-circle-o'),
                'badge' => (int) ($item['badge'] ?? $item['conteggio'] ?? 0),
            ];
        }
    }
}
?>

<header class="main-header platform-console-shell">
  <a href="<?= esc($headerLogoUrl) ?>" class="logo">
    <span class="logo-mini"><b>Amb</b>F</span>
    <span class="logo-lg"><b>Ambulatorio</b>Facile</span>
  </a>

  <nav class="navbar navbar-static-top platform-console-navbar" role="navigation">
    <div class="platform-navbar-shell">
      <?php if ($tenantName !== ''): ?>
      <div class="platform-navbar-tenant hidden-xs">
        <span class="platform-navbar-tenant-label">Spazio</span>
        <strong class="platform-navbar-tenant-name"><?= esc($tenantName) ?></strong>
      </div>
      <?php endif; ?>

      <div class="navbar-custom-menu platform-navbar-secondary">
        <ul class="nav navbar-nav">
          <li class="dropdown user user-menu">
            <a href="#" class="dropdown-toggle platform-console-user-trigger" data-toggle="dropdown">
              <img
                src="<?= esc($profileImageUrl) ?>"
                onerror="this.onerror=null;this.src='<?= esc($profileImageFallbackUrl, 'attr') ?>';"
                class="user-image"
                alt="<?= esc($displayName) ?>"
              />
              <span class="platform-console-user-name hidden-xs"><?= esc($displayName) ?></span>
            </a>

            <ul class="dropdown-menu">
              <li class="user-header">
                <img
                  src="<?= esc($profileImageUrl) ?>"
                  onerror="this.onerror=null;this.src='<?= esc($profileImageFallbackUrl, 'attr') ?>';"
                  class="user-image"
                  alt="<?= esc($displayName) ?>"
                />
                <p>
                  <?= esc($displayName) ?>
                  <?php if ($tenantName !== ''): ?>
                  <br><small>Spazio attivo: <?= esc($tenantName) ?></small>
                  <?php endif; ?>
                </p>
              </li>
              <li class="user-footer">
                <?php if ($showTenantSwitchSection): ?>
                <div class="platform-console-dropdown-block">
                  <div class="platform-console-dropdown-title"><?= esc($tenantSwitchSectionTitle) ?></div>
                  <?php foreach ($platformTenants as $availableTenant): ?>
                    <?php
                    $availableTenantId = (int) ($availableTenant['id_tenant'] ?? 0);
                    $tenantLabel = trim((string) ($availableTenant['tenant_name'] ?? $availableTenant['tenant_key'] ?? 'Spazio cliente'));
                    $isCurrentTenant = $availableTenantId === $tenantId;
                    ?>
                    <div class="platform-console-dropdown-action">
                      <?php if ($isCurrentTenant): ?>
                      <span class="btn btn-default btn-flat"><?= esc($tenantLabel) ?> (attivo)</span>
                      <?php else: ?>
                      <a href="<?= esc(portal_tenant_switch_url($availableTenantId)) ?>" class="btn btn-default btn-flat"><?= esc($tenantLabel) ?></a>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($canAccessPlatformConsole): ?>
                <div class="platform-console-dropdown-block">
                  <a href="<?= esc(portal_platform_url('spazi-clienti')) ?>" class="btn btn-default btn-flat">
                    <i class="fa fa-sitemap"></i> Console piattaforma
                  </a>
                </div>
                <?php endif; ?>

                <?php if ($canManageTenantUsers): ?>
                <div class="platform-console-dropdown-block">
                  <a href="<?= esc(portal_tenant_space_url('utenti')) ?>" class="btn btn-default btn-flat">
                    <i class="fa fa-users"></i> Gestisci utenti dello spazio
                  </a>
                </div>
                <?php endif; ?>

                <?php if ($canManageTenantFeatures): ?>
                <div class="platform-console-dropdown-block">
                  <a href="<?= esc(portal_tenant_space_url('funzioni')) ?>" class="btn btn-default btn-flat">
                    <i class="fa fa-toggle-on"></i> Gestisci funzioni
                  </a>
                </div>
                <?php endif; ?>

                <?php if ($showTenantOnboardingLink): ?>
                <div class="platform-console-dropdown-block">
                  <a href="<?= esc(portal_tenant_space_url('onboarding')) ?>" class="btn btn-default btn-flat">
                    <i class="fa fa-check-square-o"></i> Completa onboarding
                  </a>
                </div>
                <?php endif; ?>

                <div class="platform-console-user-actions">
                  <a href="<?= esc(base_url('profilo')) ?>" class="btn btn-default btn-flat">Profilo</a>
                  <a href="<?= esc(base_url('logout')) ?>" class="btn btn-default btn-flat">Logout</a>
                </div>
              </li>
            </ul>
          </li>
        </ul>
      </div>

      <div class="navbar-custom-menu platform-navbar-primary">
        <ul class="nav navbar-nav platform-navbar-links">
          <?php foreach ($navItems as $item): ?>
            <?php
            $itemLink = (string) ($item['link'] ?? '');
            $itemTitle = admin_menu_pretty_title((string) ($item['titolo'] ?? ''), $itemLink);
            $itemIcon = admin_menu_resolve_icon((string) ($item['fa_icon'] ?? ''), $itemTitle, $itemLink);
            $itemBadge = (int) ($item['badge'] ?? 0);
            ?>
          <li class="hidden-xs">
            <a href="<?= esc(site_url($itemLink)) ?>" title="<?= esc($itemTitle) ?>" class="platform-nav-link">
              <i class="fa <?= esc($itemIcon) ?>"></i>
              <span class="nav-label">
                <?= esc($itemTitle) ?>
                <?php if ($itemBadge > 0): ?>
                <span class="label label-success platform-console-nav-badge"><?= $itemBadge ?></span>
                <?php endif; ?>
              </span>
            </a>
          </li>
          <?php endforeach; ?>

          <li class="dropdown visible-xs-inline-block">
            <a href="#" class="dropdown-toggle platform-nav-link" data-toggle="dropdown" role="button">
              <i class="fa fa-bars"></i>
              <span class="nav-label">Menu</span>
            </a>
            <ul class="dropdown-menu platform-console-mobile-menu">
              <?php foreach ($navItems as $item): ?>
                <?php
                $itemLink = (string) ($item['link'] ?? '');
                $itemTitle = admin_menu_pretty_title((string) ($item['titolo'] ?? ''), $itemLink);
                $itemIcon = admin_menu_resolve_icon((string) ($item['fa_icon'] ?? ''), $itemTitle, $itemLink);
                $itemBadge = (int) ($item['badge'] ?? 0);
                ?>
              <li>
                <a href="<?= esc(site_url($itemLink)) ?>">
                  <i class="fa <?= esc($itemIcon) ?>"></i> <?= esc($itemTitle) ?>
                  <?php if ($itemBadge > 0): ?>
                  <span class="label label-success pull-right"><?= $itemBadge ?></span>
                  <?php endif; ?>
                </a>
              </li>
              <?php endforeach; ?>
            </ul>
          </li>
        </ul>
      </div>
    </div>
  </nav>
</header>
