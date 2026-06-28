<?php
helper('portal');

$tenant = is_array($tenant ?? null) ? $tenant : [];
$accounts = is_array($accounts ?? null) ? $accounts : [];
$summary = is_array($summary ?? null) ? $summary : [];
$errors = is_array($errors ?? null) ? $errors : [];
$sidebarMenuItems = is_array($sidebarMenuItems ?? null) ? $sidebarMenuItems : [];
$runtimeWarning = trim((string) ($runtimeWarning ?? ''));
$success = $success ?? null;
$tenantName = trim((string) ($tenantContext->tenantName ?? ($tenant['tenant_name'] ?? 'Studio cliente')));
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Dispositivi OTP</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <style>
    .nav-pills.nav-stacked > li.active > a {
      background-color: #2c8895;
      color: #fff;
    }
    .workspace-hero {
      border: 1px solid #dbe8eb;
      border-radius: 16px;
      padding: 20px 22px;
      background: linear-gradient(135deg, #f8fcfc 0%, #eff7f8 100%);
      margin-bottom: 18px;
    }
    .summary-badge {
      display: inline-block;
      margin: 0 8px 8px 0;
      padding: 7px 11px;
      border-radius: 999px;
      background: #dff1f2;
      color: #176872;
      font-size: 12px;
      font-weight: 600;
    }
  </style>
</head>
<body class="skin-blue sidebar-mini">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items ?? [], 'portal_console_header' => false]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Dispositivi OTP</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Qui puoi vedere quali account dello studio hanno un dispositivo collegato per ricevere gli OTP push e disassociare rapidamente i collegamenti non piu validi.
      </p>
    </section>

    <section class="content">
      <div class="row">
        <div class="col-md-3">
          <?= view('partials/sidebar_admin', ['menu_items' => $sidebarMenuItems]) ?>
        </div>

        <div class="col-md-9">
          <?php if ($success): ?>
            <div class="alert alert-success"><?= esc((string) $success) ?></div>
          <?php endif; ?>
          <?php if (!empty($errors['generic'])): ?>
            <div class="alert alert-danger"><?= esc((string) $errors['generic']) ?></div>
          <?php endif; ?>
          <?php if ($runtimeWarning !== ''): ?>
            <div class="alert alert-warning"><?= esc($runtimeWarning) ?></div>
          <?php endif; ?>

          <div class="workspace-hero">
            <h3 style="margin-top:0; margin-bottom:8px;"><?= esc($tenantName) ?></h3>
            <p style="margin:0 0 12px 0; color:#52676c;">
              Se un collaboratore cambia telefono o perde il dispositivo, puoi disattivare il collegamento qui senza intervenire a mano sul database.
            </p>
            <span class="summary-badge">Account nello spazio: <?= (int) ($summary['total_accounts'] ?? 0) ?></span>
            <span class="summary-badge">Account mappati agenda: <?= (int) ($summary['mapped_accounts'] ?? 0) ?></span>
            <span class="summary-badge">Dispositivi attivi: <?= (int) ($summary['active_devices'] ?? 0) ?></span>
          </div>

          <div class="box box-primary">
            <div class="box-header with-border">
              <h3 class="box-title">Account e collegamenti OTP</h3>
            </div>
            <div class="box-body">
              <?= view('partials/otp_device_accounts_table', [
                  'accounts' => $accounts,
                  'disconnectUrl' => portal_tenant_space_url('dispositivi-otp/disconnect'),
                  'emptyMessage' => 'Nessun account dello spazio disponibile.',
              ]) ?>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>

  <footer class="main-footer">
    <div class="pull-right hidden-xs"><b>Version</b> 2.0</div>
    <strong>&copy; AmbulatorioFacile</strong>
  </footer>
</div>
</body>
</html>
