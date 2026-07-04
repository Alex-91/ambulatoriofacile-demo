<?php
$resolvedSidebar = (new \App\Services\MenuResolverService())->resolvePlatformSidebar(
    is_array($platformMasterEmails ?? null) ? $platformMasterEmails : []
);

$platformMasterEmails = is_array($resolvedSidebar['platform_master_emails'] ?? null)
    ? $resolvedSidebar['platform_master_emails']
    : [];
$platformSidebarItems = is_array($resolvedSidebar['items'] ?? null) ? $resolvedSidebar['items'] : [];
$platformSidebarTitle = trim((string) ($resolvedSidebar['title'] ?? 'Console piattaforma'));
?>
<div class="box box-solid" style="margin-bottom: 0 !important">
  <div class="box-header with-border">
    <h3 class="box-title"><?= esc($platformSidebarTitle) ?></h3>
  </div>
  <div class="box-body no-padding">
    <ul class="nav nav-pills nav-stacked">
      <?php foreach ($platformSidebarItems as $item): ?>
        <li class="<?= !empty($item['active']) ? 'active' : '' ?>">
          <a href="<?= esc((string) ($item['href'] ?? '#')) ?>">
            <i class="fa <?= esc((string) ($item['icon'] ?? 'fa-circle-o')) ?>"></i>
            <?= esc((string) ($item['label'] ?? 'Voce')) ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div class="box-footer" style="font-size:12px; color:#5f6f73;">
    Accesso riservato agli account master configurati nel login unico.
    <?php if ($platformMasterEmails !== []): ?>
      <div style="margin-top:8px;">
        <strong>Master configurati:</strong><br>
        <?= esc(implode(', ', $platformMasterEmails)) ?>
      </div>
    <?php endif; ?>
  </div>
</div>
