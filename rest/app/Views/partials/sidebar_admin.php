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
$isBillingMenu = static function(string $link): bool {
    $path=trim((string)parse_url($link,PHP_URL_PATH),'/');
    return (bool)preg_match('~^(?:admin/)?fatturazione(?!-ts(?:/|$))(?:[-/]|$)~',$path);
};
$isTsMenu=static fn(string $link): bool => (bool)preg_match('~^(?:admin/)?(?:sistema-ts|fatturazione-ts)(?:/|$)~',trim((string)parse_url($link,PHP_URL_PATH),'/'));
$tsMenuRows=array_filter($menu_items,static fn($row)=>$isTsMenu((string)($row['link']??'')));
$tsLinks=['Riepilogo'=>site_url('admin/sistema-ts'),'Documenti e invii'=>site_url('admin/sistema-ts/documenti'),'Nuovo documento'=>site_url('admin/sistema-ts/documenti/nuovo'),'Diagnostica'=>site_url('admin/sistema-ts/diagnostica')];
$tsGroupRendered=false;
$tsGroupActive=(bool)preg_match('~(?:^|/)(?:admin/(?:sistema-ts|fatturazione-ts)|spazio/sistema-ts)(?:/|$)~',$currentPath);
if ($tsMenuRows) {
    $contextActions=array_values(array_filter($contextActions,static function($action) use (&$tsLinks): bool {
        $href=(string)($action['href']??'');
        if (preg_match('~/spazio/sistema-ts(?:/|$)~',$href) && empty($action['disabled'])) {
            $tsLinks['Configurazione']=$href;return false;
        }
        return true;
    }));
}
$tsActiveLabel='';
foreach ($tsLinks as $label=>$href) {
    if ($isLinkActive($href)) $tsActiveLabel=$label;
}
$billingMenuRows=array_filter($menu_items,static fn($row)=>$isBillingMenu((string)($row['link']??'')));
$billingHasCore=(bool)array_filter($billingMenuRows,static fn($row)=>str_contains((string)($row['link']??''),'fatturazione') && !str_contains((string)$row['link'],'fatturazione-ts'));
$billingExtraLinks=[];
if ($billingMenuRows) {
    $contextActions=array_values(array_filter($contextActions,static function($action) use (&$billingExtraLinks): bool {
        $href=(string)($action['href']??'');
        if (preg_match('~/spazio/fatturazione(?:/|$)~',$href) && empty($action['disabled'])) {
            $billingExtraLinks[(string)$action['label']]=$href;
            return false;
        }
        return true;
    }));
}
$acceptanceEnabled=false;
if ($billingMenuRows) {
    try {
        $tenantId=(int)($resolvedSidebar['tenant_id']??0);
        $acceptanceEnabled=$tenantId>0?(bool)array_filter(\App\Services\BillingCapabilities::resolve($tenantId)):(bool)array_filter($GLOBALS['capabilities']??[]);
    } catch (\Throwable $e) {log_message('error','Acceptance menu: '.$e->getMessage());}
}
$billingGroupRendered=false;
$specializationsHref=site_url('admin/fatturazione/gestione?tab=catalogo&kind=branch&context=personale');
$specializationsActive=$isLinkActive(site_url('admin/fatturazione/gestione')) && service('request')->getGet('tab')==='catalogo' && (service('request')->getGet('kind')??'branch')==='branch';
$billingGroupActive=(bool)preg_match('~(?:^|/)(?:admin/fatturazione(?!-ts(?:/|$))(?:[-/]|$)|spazio/fatturazione(?:/|$))~',$currentPath);
$billingGroupActive=$billingGroupActive && !$specializationsActive;
?>
<link rel="stylesheet" href="<?= base_url('public/assets/fontawesome/css/all.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('public/assets/fontawesome/css/v4-shims.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('public/assets/css/billing-menu.css?v=20260927-group') ?>">
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

      <?php if ($acceptanceEnabled): ?>
        <li class="<?= $isLinkActive(site_url('admin/accettazione'))?'active':'' ?>"><a href="<?= site_url('admin/accettazione') ?>"><i class="fa fa-user-check" aria-hidden="true"></i> Accettazione</a></li>
        <li class="<?= $specializationsActive?'active':'' ?>"><a href="<?= esc($specializationsHref) ?>" <?= $specializationsActive?'aria-current="page"':'' ?>><i class="fa fa-stethoscope" aria-hidden="true"></i> Gestione specializzazioni</a></li>
      <?php endif ?>
      <?php foreach ($menu_items as $menu): ?>
        <?php
          $menuLink = trim((string) ($menu['link'] ?? ''));
          $normalizedMenuLink = strtolower($normalizePath($menuLink));
          if ($normalizedMenuLink === '' || $normalizedMenuLink === 'logout' || $normalizedMenuLink === 'admin/personale/logout') {
              continue;
          }

          if ($isTsMenu($menuLink)) {
              if (!$tsGroupRendered) {
                  $tsGroupRendered=true;
                  ?>
                  <li class="ts-menu-parent">
                    <details class="billing-menu-group ts-menu-group" <?= $tsGroupActive?'open':'' ?>>
                      <summary><i class="fa fa-heartbeat" aria-hidden="true"></i><span>Sistema TS</span><span class="billing-menu-chevron" aria-hidden="true">›</span></summary>
                      <nav aria-label="Sezioni Sistema TS"><ul class="nav nav-pills nav-stacked">
                        <?php foreach ($tsLinks as $label=>$href): $selected=$tsActiveLabel===$label; ?>
                        <li class="<?= $selected?'active':'' ?>"><a href="<?= esc($href) ?>" <?= $selected?'aria-current="page"':'' ?>><?= esc($label) ?></a></li>
                        <?php endforeach ?>
                      </ul></nav>
                    </details>
                  </li>
                  <?php
              }
              continue;
          }

          if ($isBillingMenu($menuLink)) {
              if (!$billingGroupRendered) {
                  $billingGroupRendered=true;
                  ?>
                  <li class="billing-menu-parent">
                    <details class="billing-menu-group" <?= $billingGroupActive?'open':'' ?>>
                      <summary><i class="fa fa-calculator" aria-hidden="true"></i><span>Fatturazione</span><span class="billing-menu-chevron" aria-hidden="true">›</span></summary>
                      <?= view('admin/billing/navigation', [
                          'tenantScope'=>['tenant_id'=>(int)($resolvedSidebar['tenant_id']??0)],
                          'billingNavigationLayout'=>'sidebar', 'billingHasCore'=>$billingHasCore,
                          'billingExtraLinks'=>$billingExtraLinks, 'activeBillingTab'=>null,
                      ], ['saveData'=>false]) ?>
                    </details>
                  </li>
                  <?php
              }
              continue;
          }

          $menuLabel = admin_menu_pretty_title((string) ($menu['titolo_menu'] ?? ''), $menuLink);
          if (in_array($normalizedMenuLink,['personale/modifica_personale','admin/personale/modifica_personale'],true)) $menuLabel='Personale';
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
