<?php
$menu_items = $menu_items ?? ((session()->get('menuDataAdmin')['result'] ?? []));
$tenantScope = is_array($tenantScope ?? null) ? $tenantScope : [];
$moduleStatus = is_array($moduleStatus ?? null) ? $moduleStatus : [];
$documentSettings = is_array($documentSettings ?? null) ? $documentSettings : [];
$documentsDashboard = is_array($documentsDashboard ?? null) ? $documentsDashboard : [];
$billingEnabled = !empty($moduleStatus['billing_enabled']);
$tsEnabled = !empty($moduleStatus['ts_enabled']);
$integratedEnabled = !empty($moduleStatus['integrated_enabled']);
$modeTitle = trim((string) ($moduleStatus['mode_title'] ?? 'Fatturazione'));
$modeMessage = trim((string) ($moduleStatus['mode_message'] ?? ''));
$documentConfig = is_array($documentSettings['config'] ?? null) ? $documentSettings['config'] : [];
$documentBranding = is_array($documentConfig['branding'] ?? null) ? $documentConfig['branding'] : [];
$documentLayout = is_array($documentConfig['layout'] ?? null) ? $documentConfig['layout'] : [];
$documentFields = is_array($documentConfig['fields'] ?? null) ? $documentConfig['fields'] : [];
$documentTitle = trim((string) ($documentConfig['document_title'] ?? 'Documento fatturazione'));
$enabledFieldCount = count(array_filter($documentFields, static function ($enabled): bool {
    return (bool) $enabled;
}));
$enabledBlockCount = count(array_filter([
    !empty($documentLayout['show_header']),
    !empty($documentLayout['show_footer']),
    !empty($documentLayout['show_payment_box']),
    !empty($documentLayout['show_signature_box']),
    !empty($documentLayout['show_terms_box']),
], static function ($enabled): bool {
    return (bool) $enabled;
}));
$logoConfigured = trim((string) ($documentBranding['logo_url'] ?? '')) !== '';
$documentsSummary = is_array($documentsDashboard['summary'] ?? null) ? $documentsDashboard['summary'] : [];
$recentDocuments = is_array($documentsDashboard['recent_documents'] ?? null) ? $documentsDashboard['recent_documents'] : [];
$documentsTableAvailable = !empty($documentsDashboard['table_available']);
$documentsSchemaMessage = trim((string) ($documentsDashboard['schema_message'] ?? ''));
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Fatturazione</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <style>
    .nav-pills.nav-stacked > li.active > a { background-color:#2c8895; color:#fff; }
    .hero-box { border:1px solid #dbe8eb; border-radius:16px; padding:22px; background:linear-gradient(135deg, #fffdf7 0%, #f8f4e8 55%, #eef7f8 100%); margin-bottom:16px; }
    .metric-card { border:1px solid #e5ecee; border-radius:14px; background:#fff; padding:16px; min-height:150px; margin-bottom:16px; }
    .metric-card .value { font-size:24px; font-weight:700; color:#8a5b10; line-height:1.15; margin-bottom:8px; }
    .metric-card .label-top { color:#7d6c4b; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
    .metric-card .hint { color:#60757a; line-height:1.55; }
    .state-chip { display:inline-block; padding:5px 10px; border-radius:999px; background:#f4efe2; color:#7a5818; font-size:12px; font-weight:700; margin:0 8px 8px 0; }
  </style>
  <link href="<?= base_url('public/assets/css/billing-ts-ui.css') ?>" rel="stylesheet" />
</head>
<body class="skin-blue sidebar-mini billing-ts-ui module-fatturazione">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Fatturazione</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Modulo separato per i documenti cliente dello spazio <?= esc((string) ($tenantScope['tenant_name'] ?? 'attivo')) ?>.
      </p>
    </section>

    <section class="content">
      <div class="row">
        <div class="col-md-3">
          <?= view('partials/sidebar_admin', ['menu_items' => $menu_items]) ?>
        </div>

        <div class="col-md-9">
          <div class="hero-box">
            <h3 style="margin-top:0; margin-bottom:8px;"><?= esc($modeTitle) ?></h3>
            <p style="margin:0 0 12px 0; color:#556b70;">
              <?= esc($modeMessage) ?>
            </p>
            <span class="state-chip">Fatturazione: <?= $billingEnabled ? 'attiva' : 'spenta' ?></span>
            <span class="state-chip">Sistema TS: <?= $tsEnabled ? 'attivo' : 'spento' ?></span>
            <span class="state-chip">Convivenza: <?= $integratedEnabled ? 'integrata' : 'standalone' ?></span>
            <div style="margin-top:12px;">
              <a class="btn btn-default" href="<?= portal_tenant_space_url('fatturazione') ?>">
                <i class="fa fa-cog"></i> Apri spazio fatturazione
              </a>
              <a class="btn btn-warning" href="<?= site_url('admin/fatturazione-documento') ?>" style="margin-left:8px;">
                <i class="fa fa-file-text-o"></i> Configura documento fatturazione
              </a>
              <a class="btn btn-success" href="<?= site_url('admin/fatturazione-documenti') ?>" style="margin-left:8px;">
                <i class="fa fa-folder-open-o"></i> Apri archivio documenti
              </a>
              <?php if ($tsEnabled): ?>
                <a class="btn btn-primary" href="<?= site_url('admin/sistema-ts') ?>" style="margin-left:8px;">
                  <i class="fa fa-exchange"></i> Apri modulo Sistema TS
                </a>
              <?php endif; ?>
            </div>
          </div>

          <div class="alert alert-info">
            La Fatturazione ora ha anche una schermata dedicata per il <strong>documento cliente</strong>: puoi scegliere campi, branding e regole di convivenza con il Sistema TS senza mischiare i due moduli.
          </div>

          <div class="row">
            <div class="col-md-4">
              <div class="metric-card">
                <span class="label-top">Modulo cliente</span>
                <div class="value">Separato dal TS</div>
                <div class="hint">La Fatturazione viene trattata come area autonoma, pronta a gestire documento cliente, PDF e numerazione senza forzare il flusso TS.</div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="metric-card">
                <span class="label-top">Documenti creati</span>
                <div class="value"><?= (int) ($documentsSummary['total_documents'] ?? 0) ?></div>
                <div class="hint">L archivio Fatturazione tiene bozza, definitivo e stampa PDF indipendenti dal flusso Sistema TS.</div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="metric-card">
                <span class="label-top">Prossimo step</span>
                <div class="value">Template documento</div>
                <div class="hint">La nuova schermata Documento fatturazione definisce titolo, campi, logo e comportamento del documento da consegnare al cliente.</div>
              </div>
            </div>
          </div>

          <div class="box box-default">
            <div class="box-header with-border">
              <h3 class="box-title">Primi documenti fatturazione</h3>
              <div class="box-tools">
                <a class="btn btn-xs btn-success" href="<?= site_url('admin/fatturazione-documenti/nuovo') ?>">
                  <i class="fa fa-plus"></i> Nuovo documento
                </a>
              </div>
            </div>
            <div class="box-body">
              <?php if (!$documentsTableAvailable): ?>
                <div class="alert alert-warning" style="margin-bottom:0;">
                  <?= esc($documentsSchemaMessage !== '' ? $documentsSchemaMessage : 'L archivio documenti non e ancora disponibile nel database runtime corrente. La migration del modulo Fatturazione dovra essere eseguita prima di usare il flusso completo.') ?>
                </div>
              <?php elseif ($recentDocuments === []): ?>
                <p class="text-muted" style="margin:0;">
                  Nessun documento creato finora. Il prossimo step e aprire l archivio e salvare la prima fattura o ricevuta dello studio.
                </p>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-striped table-bordered" style="margin-bottom:0;">
                    <thead>
                      <tr>
                        <th>#</th>
                        <th>Documento</th>
                        <th>Cliente</th>
                        <th>Data</th>
                        <th>Totale</th>
                        <th>Stato</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($recentDocuments as $row): ?>
                        <tr>
                          <td>#<?= (int) ($row['id_billing_document'] ?? 0) ?></td>
                          <td>
                            <a href="<?= site_url('admin/fatturazione-documenti/modifica/' . (int) ($row['id_billing_document'] ?? 0)) ?>">
                              <?= esc((string) ($row['document_number'] ?? '-')) ?>
                            </a>
                          </td>
                          <td><?= esc((string) ($row['patient_name'] ?? '-')) ?></td>
                          <td><?= esc((string) ($row['issue_date'] ?? '-')) ?></td>
                          <td>&euro; <?= number_format((float) ($row['amount_total'] ?? 0), 2, ',', '.') ?></td>
                          <td><?= esc((string) ($row['local_state_label'] ?? $row['local_state'] ?? '-')) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="box box-default">
            <div class="box-header with-border">
              <h3 class="box-title">Profilo documento attuale</h3>
            </div>
            <div class="box-body">
              <div class="row">
                <div class="col-md-4">
                  <strong><?= esc($documentTitle) ?></strong>
                  <div class="text-muted" style="margin-top:6px;">
                    <?= $logoConfigured ? 'Logo configurato' : 'Logo non ancora impostato' ?>
                  </div>
                </div>
                <div class="col-md-3">
                  <strong><?= $enabledFieldCount ?></strong>
                  <div class="text-muted">campi visibili nel documento</div>
                </div>
                <div class="col-md-3">
                  <strong><?= $enabledBlockCount ?></strong>
                  <div class="text-muted">blocchi layout attivi</div>
                </div>
                <div class="col-md-2" style="text-align:right;">
                  <a class="btn btn-default" href="<?= site_url('admin/fatturazione-documento') ?>">
                    <i class="fa fa-pencil"></i> Personalizza
                  </a>
                </div>
              </div>
            </div>
          </div>

          <div class="box box-default">
            <div class="box-header with-border">
              <h3 class="box-title">Regole del nuovo assetto</h3>
            </div>
            <div class="box-body">
              <ul style="margin:0 0 0 18px; line-height:1.7;">
                <li>Il modulo <strong>Fatturazione</strong> deve poter funzionare da solo per creare e consegnare documenti al cliente.</li>
                <li>Il modulo <strong>Sistema TS</strong> deve poter funzionare da solo per preparare e inviare documenti TS quando serve.</li>
                <li>Quando entrambi sono attivi, il collegamento tra i due deve essere opzionale e trasparente, senza fondere i modelli in un unico blocco rigido.</li>
              </ul>
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
