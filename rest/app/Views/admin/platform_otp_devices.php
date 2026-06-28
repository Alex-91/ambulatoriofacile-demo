<?php
helper('portal');

$tenantRows = is_array($tenantRows ?? null) ? $tenantRows : [];
$selectedTenant = is_array($selectedTenant ?? null) ? $selectedTenant : [];
$accounts = is_array($accounts ?? null) ? $accounts : [];
$summary = is_array($summary ?? null) ? $summary : [];
$errors = is_array($errors ?? null) ? $errors : [];
$runtimeWarning = trim((string) ($runtimeWarning ?? ''));
$success = $success ?? null;
$legacyBootstrapMode = (bool) ($legacyBootstrapMode ?? false);
$platformBootstrapWarnings = is_array($platformBootstrapWarnings ?? null) ? $platformBootstrapWarnings : [];
$selectedTenantName = trim((string) ($selectedTenant['tenant_name'] ?? ''));
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Dispositivi OTP</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/assets/css/platform-console.css') ?>" rel="stylesheet" />
  <style>
    .intro-box {
      border: 1px solid #dbe8eb;
      border-radius: 12px;
      padding: 18px 20px;
      background: linear-gradient(135deg, #f8fcfc 0%, #eff7f8 100%);
      margin-bottom: 16px;
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
<body class="platform-console-body">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items ?? [], 'portal_console_header' => true]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Dispositivi OTP</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Dalla console piattaforma puoi controllare i collegamenti OTP dei singoli studi e disassociarli quando un account cambia telefono o resta bloccato su un device non piu disponibile.
      </p>
    </section>

    <section class="content">
      <div class="row">
        <div class="col-md-3">
          <?= view('partials/sidebar_platform', ['platformMasterEmails' => $platformMasterEmails ?? []]) ?>
        </div>

        <div class="col-md-9">
          <?php if ($success): ?>
            <div class="alert alert-success"><?= esc((string) $success) ?></div>
          <?php endif; ?>
          <?php if (!empty($errors['generic'])): ?>
            <div class="alert alert-danger"><?= esc((string) $errors['generic']) ?></div>
          <?php endif; ?>
          <?php foreach ($platformBootstrapWarnings as $bootstrapWarning): ?>
            <div class="alert <?= $legacyBootstrapMode ? 'alert-info' : 'alert-warning' ?>"><?= esc((string) $bootstrapWarning) ?></div>
          <?php endforeach; ?>
          <?php if ($runtimeWarning !== ''): ?>
            <div class="alert alert-warning"><?= esc($runtimeWarning) ?></div>
          <?php endif; ?>

          <div class="intro-box">
            <h3 style="margin-top:0; margin-bottom:8px;">Controllo centralizzato dei device OTP</h3>
            <p style="margin:0 0 12px 0; color:#52676c;">
              Seleziona uno studio e controlla in un colpo solo quali account hanno un dispositivo attivo collegato per gli OTP push.
            </p>
            <?php if ($selectedTenantName !== ''): ?>
              <span class="summary-badge">Studio selezionato: <?= esc($selectedTenantName) ?></span>
            <?php endif; ?>
            <span class="summary-badge">Account nello spazio: <?= (int) ($summary['total_accounts'] ?? 0) ?></span>
            <span class="summary-badge">Account mappati agenda: <?= (int) ($summary['mapped_accounts'] ?? 0) ?></span>
            <span class="summary-badge">Dispositivi attivi: <?= (int) ($summary['active_devices'] ?? 0) ?></span>
          </div>

          <div class="box box-default">
            <div class="box-header with-border">
              <h3 class="box-title">Seleziona studio</h3>
            </div>
            <div class="box-body">
              <form method="get" action="<?= portal_platform_url('dispositivi-otp') ?>" class="form-inline">
                <div class="form-group" style="min-width:320px; margin-right:10px;">
                  <label class="sr-only" for="id_tenant">Studio</label>
                  <select class="form-control" id="id_tenant" name="id_tenant" style="min-width:320px;">
                    <?php foreach ($tenantRows as $tenantRow): ?>
                      <?php $tenantId = (int) ($tenantRow['id_tenant'] ?? 0); ?>
                      <option value="<?= $tenantId ?>" <?= $tenantId === (int) ($selectedTenantId ?? 0) ? 'selected' : '' ?>>
                        <?= esc((string) ($tenantRow['tenant_name'] ?? $tenantRow['tenant_key'] ?? 'Studio cliente')) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <button type="submit" class="btn btn-primary">
                  <i class="fa fa-search"></i> Apri
                </button>
              </form>
            </div>
          </div>

          <div class="box box-primary">
            <div class="box-header with-border">
              <h3 class="box-title">Account e collegamenti OTP</h3>
            </div>
            <div class="box-body">
              <?= view('partials/otp_device_accounts_table', [
                  'accounts' => $accounts,
                  'disconnectUrl' => portal_platform_url('dispositivi-otp/disconnect'),
                  'tenantId' => (int) ($selectedTenantId ?? 0),
                  'emptyMessage' => 'Nessun account disponibile per lo studio selezionato.',
              ]) ?>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
</div>
</body>
</html>
