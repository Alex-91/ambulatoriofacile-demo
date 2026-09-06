<?php
/** @var array $menu_items */
/** @var \App\Libraries\TenantContext $tenantContext */
/** @var array $agendaFontSettings */

$menu_items = is_array($menu_items ?? null) ? $menu_items : [];
$agendaFontSettings = is_array($agendaFontSettings ?? null) ? $agendaFontSettings : [];
$tenantName = trim((string) ($tenantContext->tenantName ?? 'Spazio cliente'));
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Dimensioni testi agenda</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet">
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet">
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('public/css/agenda-font-preferences.css') ?>" rel="stylesheet">
</head>
<body class="skin-blue sidebar-mini">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Dimensioni testi agenda <small><?= esc($tenantName) ?></small></h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Configura in un solo punto la leggibilità dell’agenda per tutti gli utenti dello spazio.
      </p>
    </section>

    <section class="content">
      <div class="row">
        <div class="col-md-3">
          <?= view('partials/sidebar_admin', ['menu_items' => $menu_items]) ?>
        </div>

        <div class="col-md-9">
          <?php if (session()->getFlashdata('success')): ?>
            <div class="alert alert-success"><?= esc(session()->getFlashdata('success')) ?></div>
          <?php endif; ?>

          <?php if (session()->getFlashdata('error')): ?>
            <div class="alert alert-danger"><?= esc(session()->getFlashdata('error')) ?></div>
          <?php endif; ?>

          <?= view('profilo/_agenda_font_preferences', [
              'agendaFontSettings' => $agendaFontSettings,
              'agendaFontFormAction' => site_url('admin/preferenze-agenda'),
          ]) ?>
        </div>
      </div>
    </section>
  </div>

  <footer class="main-footer">
    <div class="pull-right hidden-xs"><b>Version</b> 2.0</div>
    <strong>&copy; AmbulatorioFacile</strong>
  </footer>
</div>

<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
<script src="<?= base_url('public/plugins/slimScroll/jquery.slimscroll.min.js') ?>"></script>
<script src="<?= base_url('public/plugins/fastclick/fastclick.min.js') ?>"></script>
<script src="<?= base_url('public/dist/js/app.min.js') ?>"></script>
<script src="<?= base_url('public/js/agenda-font-preferences.js') ?>"></script>
</body>
</html>
