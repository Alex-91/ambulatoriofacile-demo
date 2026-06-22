<?php
helper('portal');

$currentPath = trim(service('uri')->getPath(), '/');
$tenantSpacesActive = ($currentPath === 'login/piattaforma' || str_starts_with($currentPath, 'login/piattaforma/spazi-clienti')) ? 'active' : '';
$platformFeaturesActive = str_starts_with($currentPath, 'login/piattaforma/funzioni') ? 'active' : '';
$appointmentNotificationsActive = str_starts_with($currentPath, 'login/piattaforma/notifiche-appuntamenti') ? 'active' : '';
$platformMasterEmails = is_array($platformMasterEmails ?? null) ? $platformMasterEmails : [];
?>
<div class="box box-solid" style="margin-bottom: 0 !important">
  <div class="box-header with-border">
    <h3 class="box-title">Console piattaforma</h3>
    <div class="box-tools">
      <button class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button>
    </div>
  </div>
  <div class="box-body no-padding">
    <ul class="nav nav-pills nav-stacked">
      <li class="<?= esc($tenantSpacesActive) ?>">
        <a href="<?= portal_platform_url('spazi-clienti') ?>">
          <i class="fa fa-sitemap"></i>
          Spazi cliente
        </a>
      </li>
      <li class="<?= esc($platformFeaturesActive) ?>">
        <a href="<?= portal_platform_url('funzioni') ?>">
          <i class="fa fa-toggle-on"></i>
          Catalogo funzioni
        </a>
      </li>
      <li class="<?= esc($appointmentNotificationsActive) ?>">
        <a href="<?= portal_platform_url('notifiche-appuntamenti') ?>">
          <i class="fa fa-commenting"></i>
          Notifiche appuntamenti
        </a>
      </li>
      <li>
        <a href="<?= site_url('logout') ?>">
          <i class="fa fa-sign-out"></i>
          Logout
        </a>
      </li>
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
