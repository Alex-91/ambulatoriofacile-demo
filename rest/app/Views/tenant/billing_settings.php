<?php
helper('portal');

$menuDataAdmin = session()->get('menuDataAdmin');
$sidebarMenuItems = is_array($menuDataAdmin['result'] ?? null) ? $menuDataAdmin['result'] : [];

if (empty($menu_items) || !is_array($menu_items)) {
    $menu_items = $sidebarMenuItems !== [] ? $sidebarMenuItems : (session()->get('header_menu_items') ?? []);
}

$moduleStatus = is_array($moduleStatus ?? null) ? $moduleStatus : [];
$errors = is_array($errors ?? null) ? $errors : [];
$billingEnabled = !empty($moduleStatus['billing_enabled']);
$tsEnabled = !empty($moduleStatus['ts_enabled']);
$integratedEnabled = !empty($moduleStatus['integrated_enabled']);
$modeTitle = trim((string) ($moduleStatus['mode_title'] ?? 'Fatturazione'));
$modeMessage = trim((string) ($moduleStatus['mode_message'] ?? ''));
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Configura Fatturazione</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <style>
    .nav-pills.nav-stacked > li.active > a { background-color:#2c8895; color:#fff; }
    .intro-box { border:1px solid #dfd6bf; border-radius:12px; padding:18px 20px; background:linear-gradient(135deg, #fffdf8 0%, #f6f0e0 100%); margin-bottom:16px; }
    .quick-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px; margin-top:14px; }
    .quick-card { border:1px solid #e3ecee; border-radius:12px; background:#fff; padding:14px; }
    .quick-card h4 { margin:0 0 6px 0; font-size:15px; }
    .quick-card p { color:#667b80; margin:0; font-size:12px; line-height:1.5; }
    .status-chip { display:inline-block; margin:0 8px 8px 0; padding:6px 10px; border-radius:999px; background:#f4efe2; color:#7a5818; font-size:12px; font-weight:600; }
  </style>
</head>
<body class="skin-blue sidebar-mini">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items, 'portal_console_header' => false]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Configura Fatturazione</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Hub operativo del modulo cliente, separato dal Sistema TS ma pronto a conviverci quando serve.
      </p>
    </section>

    <section class="content">
      <div class="row">
        <div class="col-md-3">
          <?= view('partials/sidebar_admin', ['menu_items' => $sidebarMenuItems]) ?>
        </div>

        <div class="col-md-9">
          <?php if (!empty($errors['generic'])): ?>
            <div class="alert alert-danger"><?= esc((string) $errors['generic']) ?></div>
          <?php endif; ?>
          <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?= esc((string) $success) ?></div>
          <?php endif; ?>

          <div class="intro-box">
            <h3 style="margin-top:0; margin-bottom:8px;">
              Studio attivo: <?= esc((string) ($tenantContext->tenantName ?? '')) ?>
            </h3>
            <p style="margin:0 0 12px 0; color:#52676c;">
              <?= esc($modeMessage) ?>
            </p>
            <span class="status-chip">Fatturazione: <?= $billingEnabled ? 'attiva' : 'spenta' ?></span>
            <span class="status-chip">Sistema TS: <?= $tsEnabled ? 'attivo' : 'spento' ?></span>
            <span class="status-chip">Modalita: <?= esc($modeTitle) ?></span>
            <div style="margin-top:12px;">
              <a class="btn btn-default" href="<?= portal_tenant_space_url('funzioni') ?>">
                <i class="fa fa-arrow-left"></i> Torna alle funzioni dello spazio
              </a>
              <a class="btn btn-primary" href="<?= site_url('admin/fatturazione') ?>" style="margin-left:8px;">
                <i class="fa fa-calculator"></i> Apri dashboard fatturazione
              </a>
              <a class="btn btn-success" href="<?= site_url('admin/fatturazione-documenti') ?>" style="margin-left:8px;">
                <i class="fa fa-folder-open-o"></i> Apri archivio documenti
              </a>
              <a class="btn btn-warning" href="<?= site_url('admin/fatturazione-documento') ?>" style="margin-left:8px;">
                <i class="fa fa-file-text-o"></i> Documento fatturazione
              </a>
              <?php if ($tsEnabled): ?>
                <a class="btn btn-default" href="<?= portal_tenant_space_url('sistema-ts') ?>" style="margin-left:8px;">
                  <i class="fa fa-exchange"></i> Vai al modulo Sistema TS
                </a>
              <?php endif; ?>
            </div>
          </div>

          <div class="alert alert-info">
            La configurazione tecnica del Sistema TS resta nella sua area dedicata. Il documento cliente invece adesso ha una schermata propria, cosi puoi definire campi, layout e branding del modulo Fatturazione senza dipendere dal TS.
          </div>

          <div class="quick-grid">
            <div class="quick-card">
              <h4><i class="fa fa-file-text-o"></i> Documento fatturazione</h4>
              <p>Apri la nuova voce di menu per scegliere titolo documento, campi visibili, logo e personalizzazioni grafiche del file consegnato al cliente.</p>
            </div>
            <div class="quick-card">
              <h4><i class="fa fa-file-text-o"></i> Documento cliente</h4>
              <p>Qui fara capo la creazione del documento da consegnare al paziente o cliente, indipendente dall invio TS.</p>
            </div>
            <div class="quick-card">
              <h4><i class="fa fa-link"></i> Collegamento opzionale</h4>
              <p>Se anche il Sistema TS e attivo, il documento cliente potra generare o collegare un documento TS senza confondere i due moduli.</p>
            </div>
            <div class="quick-card">
              <h4><i class="fa fa-shield"></i> Coesistenza pulita</h4>
              <p>Accendere Fatturazione non obbliga ad accendere TS, e viceversa. Quando entrambi sono presenti, il flusso integrato resta comunque controllabile.</p>
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
<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
</body>
</html>
