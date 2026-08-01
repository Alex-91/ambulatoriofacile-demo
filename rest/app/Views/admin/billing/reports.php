<?php
$menu_items = $menu_items ?? ((session()->get('menuDataAdmin')['result'] ?? []));
$tenantScope = is_array($tenantScope ?? null) ? $tenantScope : [];
$report = is_array($report ?? null) ? $report : [];
$filters = is_array($report['filters'] ?? null) ? $report['filters'] : [];
$filterOptions = is_array($report['filter_options'] ?? null) ? $report['filter_options'] : [];
$summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
$documents = is_array($report['documents'] ?? null) ? $report['documents'] : [];
$monthlyBreakdown = is_array($report['monthly_breakdown'] ?? null) ? $report['monthly_breakdown'] : [];
$paymentBreakdown = is_array($report['payment_breakdown'] ?? null) ? $report['payment_breakdown'] : [];
$documentTypeBreakdown = is_array($report['document_type_breakdown'] ?? null) ? $report['document_type_breakdown'] : [];
$tableAvailable = !empty($report['table_available']);
$schemaMessage = trim((string) ($report['schema_message'] ?? ''));
$errors = is_array($errors ?? null) ? $errors : [];
$exportUrl = site_url('admin/fatturazione-statistiche/export') . '?' . http_build_query($filters);
$exportPdfUrl = site_url('admin/fatturazione-statistiche/export-pdf') . '?' . http_build_query($filters);
$maxMonthlyAmount = 0.0;
foreach ($monthlyBreakdown as $month) {
    $maxMonthlyAmount = max($maxMonthlyAmount, (float) ($month['amount'] ?? 0));
}
$totalAmount = (float) ($summary['total_amount'] ?? 0);
$formatDate = static function (string $value): string {
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

    return $date !== false ? $date->format('d/m/Y') : '-';
};
$periodText = 'Tutto lo storico';
if (trim((string) ($filters['date_from'] ?? '')) !== '' || trim((string) ($filters['date_to'] ?? '')) !== '') {
    $periodText = 'Dal ' . $formatDate((string) ($filters['date_from'] ?? '')) . ' al ' . $formatDate((string) ($filters['date_to'] ?? ''));
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Statistiche e report</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/assets/css/billing-ts-ui.css') ?>" rel="stylesheet" />
  <style>
    .billing-report-page { color:#20383d; }
    .billing-report-filter { background:#fff; border:1px solid #dfe9eb; border-radius:16px; padding:20px; margin-bottom:18px; box-shadow:0 8px 24px rgba(31,66,73,.06); }
    .billing-report-filter h2 { margin:0 0 4px; font-size:20px; font-weight:700; }
    .billing-report-filter > p { margin:0 0 18px; color:#70858a; }
    .billing-report-filter label { color:#3c555a; font-size:12px; font-weight:700; letter-spacing:.02em; }
    .billing-report-filter .form-control { height:42px; border-color:#d9e5e7; border-radius:9px; box-shadow:none; }
    .billing-report-filter .form-control:focus { border-color:#2c8895; box-shadow:0 0 0 2px rgba(44,136,149,.12); }
    .billing-report-filter-actions { display:flex; align-items:center; justify-content:flex-end; gap:10px; flex-wrap:wrap; padding-top:6px; }
    .billing-report-export-select { min-width:175px; height:40px; padding:0 36px 0 12px; border:1px solid #2c8895; border-radius:9px; color:#176875; background:#fff; font-weight:700; cursor:pointer; }
    .billing-report-kpis { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:14px; margin-bottom:18px; }
    .billing-report-kpi { background:#fff; border:1px solid #dfe9eb; border-radius:15px; padding:18px; box-shadow:0 8px 22px rgba(31,66,73,.05); }
    .billing-report-kpi.is-primary { color:#fff; border-color:#237985; background:linear-gradient(135deg, #2c8895 0%, #176875 100%); }
    .billing-report-kpi span { display:block; color:#71858a; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
    .billing-report-kpi.is-primary span, .billing-report-kpi.is-primary small { color:rgba(255,255,255,.78); }
    .billing-report-kpi strong { display:block; margin:8px 0 4px; font-size:28px; line-height:1.1; }
    .billing-report-kpi small { color:#7b8e92; }
    .billing-report-grid { display:grid; grid-template-columns:minmax(0, 1.65fr) minmax(260px, .75fr); gap:18px; margin-bottom:18px; }
    .billing-report-panel { background:#fff; border:1px solid #dfe9eb; border-radius:16px; box-shadow:0 8px 24px rgba(31,66,73,.05); overflow:hidden; }
    .billing-report-panel-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; padding:18px 20px; border-bottom:1px solid #edf2f3; }
    .billing-report-panel-heading h2 { margin:0; font-size:18px; font-weight:700; }
    .billing-report-panel-heading p { margin:4px 0 0; color:#7b8d91; font-size:12px; }
    .billing-report-panel-body { padding:18px 20px; }
    .billing-month-row { display:grid; grid-template-columns:72px minmax(90px, 1fr) 100px; gap:12px; align-items:center; margin-bottom:14px; }
    .billing-month-row:last-child { margin-bottom:0; }
    .billing-month-label { color:#4f666b; font-weight:700; }
    .billing-month-track { height:12px; overflow:hidden; border-radius:999px; background:#edf3f4; }
    .billing-month-fill { display:block; height:100%; min-width:3px; border-radius:999px; background:linear-gradient(90deg, #2c8895, #51b4aa); }
    .billing-month-value { text-align:right; font-weight:700; color:#1e5e68; }
    .billing-breakdown-row { padding:12px 0; border-bottom:1px solid #edf2f3; }
    .billing-breakdown-row:last-child { border-bottom:0; }
    .billing-breakdown-main { display:flex; justify-content:space-between; gap:10px; }
    .billing-breakdown-main strong:last-child { color:#1d6670; white-space:nowrap; }
    .billing-breakdown-meta { margin-top:4px; color:#829397; font-size:12px; }
    .billing-breakdown-track { height:5px; margin-top:8px; overflow:hidden; border-radius:999px; background:#edf3f4; }
    .billing-breakdown-fill { display:block; height:100%; background:#57aaa4; }
    .billing-report-table-wrap { max-height:620px; overflow:auto; }
    .billing-report-table { margin:0; min-width:990px; }
    .billing-report-table thead th { position:sticky; top:0; z-index:2; padding:13px 12px; color:#60777c; background:#f7fafb; border-bottom:1px solid #dfe9eb; font-size:11px; text-transform:uppercase; letter-spacing:.04em; }
    .billing-report-table tbody td { padding:13px 12px; vertical-align:middle; border-color:#edf2f3; }
    .billing-report-client strong { display:block; }
    .billing-report-client small { color:#819398; }
    .billing-report-services { display:block; max-width:250px; color:#6c8185; font-size:12px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .billing-report-total { color:#1c6872; font-weight:800; white-space:nowrap; }
    .billing-report-state { display:inline-block; padding:5px 9px; border-radius:999px; background:#eef4f5; color:#536b70; font-size:11px; font-weight:700; }
    .billing-report-state.is-issued { background:#e5f5ef; color:#18744f; }
    .billing-report-empty { padding:46px 20px; color:#71878b; text-align:center; }
    .billing-report-empty i { display:block; margin-bottom:12px; color:#a8babc; font-size:36px; }
    .billing-report-accountant-note { display:flex; align-items:flex-start; gap:13px; margin-top:14px; padding:14px 16px; border-radius:12px; background:#edf7f5; color:#386267; }
    .billing-report-accountant-note i { color:#2c8895; font-size:20px; margin-top:2px; }
    @media (max-width:1100px) { .billing-report-kpis { grid-template-columns:repeat(2, minmax(0, 1fr)); } .billing-report-grid { grid-template-columns:1fr; } }
    @media (max-width:767px) { .billing-report-kpis { grid-template-columns:1fr; } .billing-month-row { grid-template-columns:62px minmax(70px,1fr) 88px; } .billing-report-filter-actions { justify-content:flex-start; } }
  </style>
</head>
<body class="skin-blue sidebar-mini billing-ts-ui module-fatturazione">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items]) ?>

  <div class="content-wrapper">
    <section class="content billing-dashboard-content">
      <div class="row billing-dashboard-layout">
        <aside class="col-md-3 billing-dashboard-nav">
          <?= view('partials/sidebar_admin', ['menu_items' => $menu_items]) ?>
        </aside>

        <main class="col-md-9">
          <div class="billing-dashboard billing-report-page">
            <header class="billing-modulebar">
              <div class="billing-modulebar-copy">
                <div class="billing-eyebrow">Fatturazione</div>
                <div class="billing-title-row">
                  <span class="billing-module-icon"><i class="fa fa-bar-chart"></i></span>
                  <div>
                    <h1>Statistiche e report</h1>
                    <p>Analizza il fatturato e prepara i dati da inviare al commercialista.</p>
                  </div>
                </div>
              </div>
            </header>

            <?php if (!empty($errors['generic'])): ?>
              <div class="alert alert-danger"><?= esc((string) $errors['generic']) ?></div>
            <?php endif; ?>

            <form class="billing-report-filter" method="get" action="<?= site_url('admin/fatturazione-statistiche') ?>">
              <h2>Filtri del report</h2>
              <p>I totali, i grafici e l'export vengono aggiornati usando gli stessi filtri.</p>
              <div class="row">
                <div class="col-md-4">
                  <div class="form-group">
                    <label for="billing-report-period">Periodo</label>
                    <select class="form-control" id="billing-report-period" name="period">
                      <?php foreach ((array) ($filterOptions['periods'] ?? []) as $value => $label): ?>
                        <option value="<?= esc((string) $value) ?>" <?= (string) ($filters['period'] ?? '') === (string) $value ? 'selected' : '' ?>><?= esc((string) $label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="col-sm-6 col-md-4">
                  <div class="form-group">
                    <label for="billing-report-from">Dal</label>
                    <input class="form-control js-report-custom-date" id="billing-report-from" type="date" name="date_from" value="<?= esc((string) ($filters['date_from'] ?? '')) ?>">
                  </div>
                </div>
                <div class="col-sm-6 col-md-4">
                  <div class="form-group">
                    <label for="billing-report-to">Al</label>
                    <input class="form-control js-report-custom-date" id="billing-report-to" type="date" name="date_to" value="<?= esc((string) ($filters['date_to'] ?? '')) ?>">
                  </div>
                </div>
              </div>
              <div class="row">
                <div class="col-sm-6 col-md-6">
                  <div class="form-group">
                    <label>Tipo documento</label>
                    <select class="form-control" name="document_type">
                      <?php foreach ((array) ($filterOptions['document_types'] ?? []) as $value => $label): ?>
                        <option value="<?= esc((string) $value) ?>" <?= (string) ($filters['document_type'] ?? '') === (string) $value ? 'selected' : '' ?>><?= esc((string) $label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="col-sm-6 col-md-6">
                  <div class="form-group">
                    <label>Pagamento</label>
                    <select class="form-control" name="payment_method">
                      <?php foreach ((array) ($filterOptions['payment_methods'] ?? []) as $value => $label): ?>
                        <option value="<?= esc((string) $value) ?>" <?= (string) ($filters['payment_method'] ?? '') === (string) $value ? 'selected' : '' ?>><?= esc((string) $label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
              </div>
              <div class="row">
                <div class="col-md-7">
                  <div class="form-group" style="margin-bottom:0;">
                    <label for="billing-report-patient">Cliente, codice fiscale o numero fattura</label>
                    <input class="form-control" id="billing-report-patient" type="search" name="patient" maxlength="120" placeholder="Cerca nel report" value="<?= esc((string) ($filters['patient'] ?? '')) ?>">
                  </div>
                </div>
                <div class="col-md-5">
                  <div class="billing-report-filter-actions">
                    <a class="billing-action billing-action-secondary" href="<?= site_url('admin/fatturazione-statistiche') ?>"><i class="fa fa-undo"></i> Azzera</a>
                    <button class="billing-action billing-action-primary" type="submit"><i class="fa fa-filter"></i> Applica filtri</button>
                  </div>
                </div>
              </div>
            </form>

            <?php if (!$tableAvailable): ?>
              <div class="alert alert-warning"><?= esc($schemaMessage !== '' ? $schemaMessage : 'Archivio fatture non disponibile.') ?></div>
            <?php else: ?>
              <div class="billing-report-kpis">
                <article class="billing-report-kpi is-primary">
                  <span>Fatturato filtrato</span>
                  <strong>&euro; <?= number_format($totalAmount, 2, ',', '.') ?></strong>
                  <small><?= esc($periodText) ?></small>
                </article>
                <article class="billing-report-kpi">
                  <span>Documenti</span>
                  <strong><?= (int) ($summary['document_count'] ?? 0) ?></strong>
                  <small><?= (int) ($summary['client_count'] ?? 0) ?> clienti distinti</small>
                </article>
                <article class="billing-report-kpi">
                  <span>Valore medio</span>
                  <strong>&euro; <?= number_format((float) ($summary['average_amount'] ?? 0), 2, ',', '.') ?></strong>
                  <small>per documento</small>
                </article>
                <article class="billing-report-kpi">
                  <span>Imponibile e bollo</span>
                  <strong>&euro; <?= number_format((float) ($summary['subtotal_amount'] ?? 0), 2, ',', '.') ?></strong>
                  <small>Bolli: &euro; <?= number_format((float) ($summary['stamp_duty_amount'] ?? 0), 2, ',', '.') ?></small>
                </article>
              </div>

              <div class="billing-report-grid">
                <section class="billing-report-panel">
                  <div class="billing-report-panel-heading">
                    <div>
                      <h2>Andamento del fatturato</h2>
                      <p>Totale dei documenti raggruppato per mese.</p>
                    </div>
                    <span class="billing-report-state"><?= count($monthlyBreakdown) ?> mesi</span>
                  </div>
                  <div class="billing-report-panel-body">
                    <?php if ($monthlyBreakdown === []): ?>
                      <div class="billing-report-empty"><i class="fa fa-line-chart"></i>Nessun dato per il periodo selezionato.</div>
                    <?php else: ?>
                      <?php foreach ($monthlyBreakdown as $month): ?>
                        <?php $width = $maxMonthlyAmount > 0 ? max(2, ((float) ($month['amount'] ?? 0) / $maxMonthlyAmount) * 100) : 0; ?>
                        <div class="billing-month-row">
                          <span class="billing-month-label"><?= esc((string) ($month['label'] ?? '')) ?></span>
                          <span class="billing-month-track"><span class="billing-month-fill" style="width:<?= number_format($width, 2, '.', '') ?>%"></span></span>
                          <span class="billing-month-value">&euro; <?= number_format((float) ($month['amount'] ?? 0), 2, ',', '.') ?></span>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </section>

                <section class="billing-report-panel">
                  <div class="billing-report-panel-heading">
                    <div>
                      <h2>Modalità di pagamento</h2>
                      <p>Incidenza sul totale filtrato.</p>
                    </div>
                  </div>
                  <div class="billing-report-panel-body">
                    <?php if ($paymentBreakdown === []): ?>
                      <div class="text-muted">Nessun dato disponibile.</div>
                    <?php else: ?>
                      <?php foreach ($paymentBreakdown as $item): ?>
                        <?php $share = $totalAmount > 0 ? ((float) ($item['amount'] ?? 0) / $totalAmount) * 100 : 0; ?>
                        <div class="billing-breakdown-row">
                          <div class="billing-breakdown-main">
                            <strong><?= esc((string) ($item['label'] ?? '')) ?></strong>
                            <strong>&euro; <?= number_format((float) ($item['amount'] ?? 0), 2, ',', '.') ?></strong>
                          </div>
                          <div class="billing-breakdown-meta"><?= (int) ($item['count'] ?? 0) ?> documenti &middot; <?= number_format($share, 1, ',', '.') ?>%</div>
                          <div class="billing-breakdown-track"><span class="billing-breakdown-fill" style="width:<?= number_format($share, 2, '.', '') ?>%"></span></div>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </section>
              </div>

              <section class="billing-report-panel" style="margin-bottom:18px;">
                <div class="billing-report-panel-heading">
                  <div>
                    <h2>Documenti del report</h2>
                    <p><?= count($documents) ?> risultati &middot; <?= esc($periodText) ?></p>
                  </div>
                  <?php if ($documents !== []): ?>
                    <label class="sr-only" for="billing-report-export-format">Formato esportazione</label>
                    <select class="billing-report-export-select" id="billing-report-export-format" data-csv-url="<?= esc($exportUrl) ?>" data-pdf-url="<?= esc($exportPdfUrl) ?>">
                      <option value="">Scarica report...</option>
                      <option value="csv">Scarica CSV</option>
                      <option value="pdf">Scarica PDF</option>
                    </select>
                  <?php endif; ?>
                </div>
                <?php if ($documents === []): ?>
                  <div class="billing-report-empty">
                    <i class="fa fa-search"></i>
                    <strong>Nessuna fattura corrisponde ai filtri.</strong>
                    <div style="margin-top:7px;">Modifica il periodo o gli altri criteri di ricerca.</div>
                  </div>
                <?php else: ?>
                  <div class="table-responsive billing-report-table-wrap">
                    <table class="table billing-report-table">
                      <thead>
                        <tr>
                          <th>Documento</th>
                          <th>Data</th>
                          <th>Cliente</th>
                          <th>Prestazioni</th>
                          <th>Pagamento</th>
                          <th class="text-right">Totale</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($documents as $row): ?>
                          <?php
                            $documentId = (int) ($row['id_billing_document'] ?? 0);
                            $services = implode(', ', (array) ($row['service_descriptions'] ?? []));
                          ?>
                          <tr>
                            <td>
                              <a class="billing-document-number" href="<?= site_url('admin/fatturazione-documenti/modifica/' . $documentId) ?>"><?= esc((string) ($row['document_number'] ?? '-')) ?></a>
                              <small class="text-muted" style="display:block;"><?= esc((string) ($row['document_type_label'] ?? '')) ?></small>
                            </td>
                            <td><?= esc($formatDate((string) ($row['issue_date'] ?? ''))) ?></td>
                            <td class="billing-report-client">
                              <strong><?= esc((string) ($row['patient_name'] ?? '-')) ?></strong>
                              <small><?= esc((string) ($row['patient_tax_code'] ?? '')) ?></small>
                            </td>
                            <td><span class="billing-report-services" title="<?= esc($services) ?>"><?= esc($services !== '' ? $services : '-') ?></span></td>
                            <td><?= esc((string) ($row['payment_method_label'] ?? '-')) ?></td>
                            <td class="text-right billing-report-total">&euro; <?= number_format((float) ($row['amount_total'] ?? 0), 2, ',', '.') ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
                <div class="billing-report-panel-body" style="padding-top:0;">
                  <div class="billing-report-accountant-note">
                    <i class="fa fa-file-excel-o"></i>
                    <div><strong>Export per il commercialista</strong><br><span>Scegli CSV per lavorare i dati in Excel oppure PDF per un riepilogo pronto da condividere. Entrambi rispettano i filtri correnti.</span></div>
                  </div>
                </div>
              </section>

              <?php if ($documentTypeBreakdown !== []): ?>
                <section class="billing-report-panel">
                  <div class="billing-report-panel-heading">
                    <div>
                      <h2>Riepilogo per tipo documento</h2>
                      <p>Distribuzione dei risultati selezionati.</p>
                    </div>
                  </div>
                  <div class="billing-report-panel-body">
                    <div class="row">
                      <?php foreach ($documentTypeBreakdown as $item): ?>
                        <div class="col-sm-4">
                          <div class="billing-breakdown-row">
                            <div class="billing-breakdown-main"><strong><?= esc((string) ($item['label'] ?? '')) ?></strong><strong>&euro; <?= number_format((float) ($item['amount'] ?? 0), 2, ',', '.') ?></strong></div>
                            <div class="billing-breakdown-meta"><?= (int) ($item['count'] ?? 0) ?> documenti</div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </section>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </main>
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
<script>
  (function () {
    var period = document.getElementById('billing-report-period');
    var customDates = document.querySelectorAll('.js-report-custom-date');
    if (!period || !customDates.length) {
      return;
    }
    Array.prototype.forEach.call(customDates, function (input) {
      input.addEventListener('change', function () {
        period.value = 'custom';
      });
    });

    var exportFormat = document.getElementById('billing-report-export-format');
    if (exportFormat) {
      exportFormat.addEventListener('change', function () {
        var format = exportFormat.value;
        var targetUrl = format === 'csv'
          ? exportFormat.getAttribute('data-csv-url')
          : (format === 'pdf' ? exportFormat.getAttribute('data-pdf-url') : '');
        exportFormat.value = '';
        if (targetUrl) {
          window.location.href = targetUrl;
        }
      });
    }
  })();
</script>
</body>
</html>
