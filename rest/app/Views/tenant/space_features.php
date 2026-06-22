<?php
helper('portal');

if (empty($menu_items) || !is_array($menu_items)) {
    $menu_items = session()->get('header_menu_items') ?? [];
}

$errors = is_array($errors ?? null) ? $errors : [];
$success = $success ?? null;
$featureStates = is_array($featureStates ?? null) ? $featureStates : [];
$tenantContext = $tenantContext ?? null;
$appointmentNotificationsAvailable = false;

$manageableRows = [];
$lockedRows = [];
$unavailableRows = [];

foreach ($featureStates as $row) {
    $entitled = (bool) ($row['entitlement_enabled'] ?? false);
    $tenantManaged = (bool) ($row['is_tenant_managed'] ?? false);
    $featureKey = trim((string) ($row['feature_key'] ?? ''));

    if ($featureKey === 'appointment_notifications' && (bool) ($row['effective_enabled'] ?? false)) {
        $appointmentNotificationsAvailable = true;
    }

    if (!$entitled) {
        $unavailableRows[] = $row;
        continue;
    }

    if ($tenantManaged) {
        $manageableRows[] = $row;
        continue;
    }

    $lockedRows[] = $row;
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Funzioni Spazio</title>
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
    .feature-card {
      border: 1px solid #e5ecee;
      border-radius: 10px;
      padding: 14px 16px;
      min-height: 170px;
      margin-bottom: 14px;
      background: #fff;
    }
    .feature-card h4 {
      margin-top: 0;
      margin-bottom: 8px;
      font-size: 17px;
    }
    .feature-card p {
      color: #667b80;
      min-height: 44px;
      margin-bottom: 10px;
    }
    .status-chip {
      display: inline-block;
      margin: 0 8px 8px 0;
      padding: 6px 10px;
      border-radius: 999px;
      background: #eef5f6;
      color: #1b6770;
      font-size: 12px;
      font-weight: 600;
    }
  </style>
</head>

<body class="platform-console-body">
<div class="wrapper">
  <?= view('partials/header_portal_console', ['menu_items' => $menu_items]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Funzioni dello Spazio</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Qui il tenant master decide quali funzioni attivare per il proprio spazio, entro i limiti concessi dalla piattaforma.
      </p>
    </section>

    <section class="content">
      <?php if ($success): ?>
        <div class="alert alert-success"><?= esc((string) $success) ?></div>
      <?php endif; ?>
      <?php if (!empty($errors['generic'])): ?>
        <div class="alert alert-danger"><?= esc((string) $errors['generic']) ?></div>
      <?php endif; ?>

      <div class="intro-box">
        <h3 style="margin-top:0; margin-bottom:8px;">
          Spazio attivo: <?= esc((string) ($tenantContext->tenantName ?? '')) ?>
        </h3>
        <p style="margin:0 0 12px 0; color:#52676c;">
          Le funzioni che vedi qui sono gia state concesse dal pacchetto o dalla configurazione centrale. Tu puoi solo governare quelle marcate come self service.
        </p>
        <span class="status-chip">Gestibili da te: <?= count($manageableRows) ?></span>
        <span class="status-chip">Centrali: <?= count($lockedRows) ?></span>
        <span class="status-chip">Non incluse: <?= count($unavailableRows) ?></span>
        <?php if ($appointmentNotificationsAvailable): ?>
          <div style="margin-top:12px;">
            <a class="btn btn-default" href="<?= portal_tenant_space_url('notifiche-appuntamenti') ?>">
              <i class="fa fa-commenting"></i> Apri centro notifiche appuntamenti
            </a>
          </div>
        <?php endif; ?>
      </div>

      <div class="box box-success">
        <div class="box-header with-border">
          <h3 class="box-title">Funzioni governabili dal tenant master</h3>
        </div>
        <form method="post" action="<?= portal_tenant_space_url('funzioni/save') ?>">
          <?= csrf_field() ?>
          <div class="box-body">
            <?php if ($manageableRows === []): ?>
              <div class="alert alert-info" style="margin-bottom:0;">
                In questo momento non ci sono funzioni self service da governare per il tuo spazio.
              </div>
            <?php else: ?>
              <div class="row">
                <?php foreach ($manageableRows as $row): ?>
                  <?php
                    $featureKey = (string) ($row['feature_key'] ?? '');
                    $enabled = (bool) ($row['effective_enabled'] ?? false);
                    $sourceLabel = ($row['tenant_preference_enabled'] ?? null) === null ? 'default spazio' : 'personalizzata';
                  ?>
                  <div class="col-md-4">
                    <div class="feature-card">
                      <h4><i class="fa <?= esc((string) ($row['icon_class'] ?? 'fa-toggle-on')) ?>"></i> <?= esc((string) ($row['feature_name'] ?? $featureKey)) ?></h4>
                      <p><?= esc((string) ($row['description'] ?? '')) ?></p>
                      <div class="checkbox" style="margin:0 0 10px 0;">
                        <label>
                          <input type="checkbox" name="enabled_features[]" value="<?= esc($featureKey) ?>" <?= $enabled ? 'checked' : '' ?>>
                          Funzione attiva per questo spazio
                        </label>
                      </div>
                      <span class="label label-<?= $enabled ? 'success' : 'default' ?>">
                        <?= $enabled ? 'attiva' : 'spenta' ?>
                      </span>
                      <span class="label label-info"><?= esc($sourceLabel) ?></span>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <?php if ($manageableRows !== []): ?>
          <div class="box-footer">
            <button class="btn btn-success" type="submit">
              <i class="fa fa-save"></i> Salva funzioni dello spazio
            </button>
          </div>
          <?php endif; ?>
        </form>
      </div>

      <?php if ($lockedRows !== []): ?>
      <div class="box box-default">
        <div class="box-header with-border">
          <h3 class="box-title">Funzioni gestite centralmente</h3>
        </div>
        <div class="box-body">
          <div class="row">
            <?php foreach ($lockedRows as $row): ?>
              <div class="col-md-4">
                <div class="feature-card">
                  <h4><i class="fa <?= esc((string) ($row['icon_class'] ?? 'fa-lock')) ?>"></i> <?= esc((string) ($row['feature_name'] ?? '')) ?></h4>
                  <p><?= esc((string) ($row['description'] ?? '')) ?></p>
                  <span class="label label-default">gestita dalla piattaforma</span>
                  <span class="label label-<?= ((bool) ($row['effective_enabled'] ?? false)) ? 'success' : 'default' ?>">
                    <?= ((bool) ($row['effective_enabled'] ?? false)) ? 'attiva' : 'spenta' ?>
                  </span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($unavailableRows !== []): ?>
      <div class="box box-warning">
        <div class="box-header with-border">
          <h3 class="box-title">Funzioni non incluse nel tuo spazio</h3>
        </div>
        <div class="box-body">
          <div class="row">
            <?php foreach ($unavailableRows as $row): ?>
              <div class="col-md-4">
                <div class="feature-card">
                  <h4><i class="fa <?= esc((string) ($row['icon_class'] ?? 'fa-ban')) ?>"></i> <?= esc((string) ($row['feature_name'] ?? '')) ?></h4>
                  <p><?= esc((string) ($row['description'] ?? '')) ?></p>
                  <span class="label label-warning">non disponibile</span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </section>
  </div>
</div>

<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
</body>
</html>
