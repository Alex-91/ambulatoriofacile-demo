<?php
helper('admin_menu');

$resolvedSidebar = (new \App\Services\MenuResolverService())->resolveAdminSidebar(
    is_array($menu_items ?? null) ? $menu_items : []
);

$tenantName = trim((string) ($resolvedSidebar['tenant_name'] ?? ''));
$menu_items = is_array($resolvedSidebar['menu_items'] ?? null) ? $resolvedSidebar['menu_items'] : [];
$primaryAction = is_array($resolvedSidebar['primary_action'] ?? null) ? $resolvedSidebar['primary_action'] : null;
$secondaryPrimaryAction = is_array($resolvedSidebar['secondary_primary_action'] ?? null) ? $resolvedSidebar['secondary_primary_action'] : null;
$contextActions = is_array($resolvedSidebar['context_actions'] ?? null) ? $resolvedSidebar['context_actions'] : [];
$accountActions = is_array($resolvedSidebar['account_actions'] ?? null) ? $resolvedSidebar['account_actions'] : [];

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
$agendaFontPreferencesHref = site_url('admin/preferenze-agenda');
$agendaFontPreferencesPath = strtolower($normalizePath($agendaFontPreferencesHref));
$isLinkActive = static function (string $href) use ($normalizePath, $currentPath): bool {
    $itemPath = strtolower($normalizePath($href));
    if ($itemPath === '') {
        return false;
    }

    return $currentPath === $itemPath || str_starts_with($currentPath, $itemPath . '/');
};
?>
<div class="box box-solid admin-sidebar-menu" style="margin-bottom:0 !important">
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

      <?php if ($tenantName !== ''): ?>
        <li class="<?= $currentPath === $agendaFontPreferencesPath ? 'active' : '' ?>">
          <a href="<?= esc($agendaFontPreferencesHref) ?>">
            <i class="fa fa-font"></i>
            Dimensioni testi agenda
          </a>
        </li>
      <?php endif; ?>
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
