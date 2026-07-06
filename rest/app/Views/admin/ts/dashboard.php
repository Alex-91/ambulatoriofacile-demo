<?php
$menu_items = $menu_items ?? ((session()->get('menuDataAdmin')['result'] ?? []));
$tenantScope = is_array($tenantScope ?? null) ? $tenantScope : [];
$dashboard = is_array($dashboard ?? null) ? $dashboard : [];
$summary = is_array($dashboard['summary'] ?? null) ? $dashboard['summary'] : [];
$recentDocuments = is_array($dashboard['recent_documents'] ?? null) ? $dashboard['recent_documents'] : [];
$uiStateLabels = is_array($dashboard['ui_state_labels'] ?? null) ? $dashboard['ui_state_labels'] : [];
$tableAvailable = !empty($dashboard['table_available']);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Fatturazione TS</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <style>
    .nav-pills.nav-stacked > li.active > a { background-color:#2c8895; color:#fff; }
    .hero-box { border:1px solid #dbe8eb; border-radius:16px; padding:22px; background:linear-gradient(135deg, #f8fcfc 0%, #eef7f8 60%, #fff5ec 100%); margin-bottom:16px; }
    .metric-card { border:1px solid #e5ecee; border-radius:14px; background:#fff; padding:16px; min-height:130px; margin-bottom:16px; }
    .metric-card .value { font-size:32px; font-weight:700; color:#186b74; line-height:1; margin-bottom:8px; }
    .metric-card .label-top { color:#6b8085; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
    .metric-card .hint { color:#60757a; line-height:1.55; }
    .doc-row { border:1px solid #e5ecee; border-radius:12px; background:#fff; padding:14px 16px; margin-bottom:12px; }
    .state-chip { display:inline-block; padding:5px 10px; border-radius:999px; background:#eef5f6; color:#1b6770; font-size:12px; font-weight:700; }
  </style>
</head>
<body class="skin-blue sidebar-mini">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Fatturazione TS</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Dashboard iniziale del modulo Sistema Tessera Sanitaria per lo spazio <?= esc((string) ($tenantScope['tenant_name'] ?? 'attivo')) ?>.
      </p>
    </section>

    <section class="content">
      <div class="row">
        <div class="col-md-3">
          <?= view('partials/sidebar_admin', ['menu_items' => $menu_items]) ?>
        </div>

        <div class="col-md-9">
          <div class="hero-box">
            <h3 style="margin-top:0; margin-bottom:8px;">Modulo TS operativo</h3>
            <p style="margin:0 0 12px 0; color:#556b70;">
              Questa area raccoglie i documenti TS dello studio, il loro stato locale e il prossimo punto di lavoro. In questa fase la dashboard si appoggia alla nuova persistence locale e resta sicura anche se le migration non sono ancora state eseguite sul DB attuale.
            </p>
            <a class="btn btn-primary" href="<?= site_url('admin/fatturazione-ts/documenti') ?>">
              <i class="fa fa-list"></i> Apri lista documenti TS
            </a>
            <a class="btn btn-success" href="<?= site_url('admin/fatturazione-ts/documenti/nuovo') ?>" style="margin-left:8px;">
              <i class="fa fa-plus"></i> Nuovo documento TS
            </a>
            <a class="btn btn-default" href="<?= site_url('admin/fatturazione-ts/diagnostica') ?>" style="margin-left:8px;">
              <i class="fa fa-search"></i> Apri diagnostica TS
            </a>
            <a class="btn btn-default" href="<?= portal_tenant_space_url('fatturazione-ts') ?>" style="margin-left:8px;">
              <i class="fa fa-cog"></i> Configura profilo TS
            </a>
          </div>

          <?php if (!$tableAvailable): ?>
            <div class="alert alert-warning">
              Le tabelle `ts_documents` non risultano ancora presenti su questo database operativo. La UI TS e pronta, ma per vedere dati reali dobbiamo eseguire le migration del modulo sul DB locale o sul tenant di test.
            </div>
          <?php endif; ?>

          <div class="row">
            <div class="col-md-3">
              <div class="metric-card">
                <span class="label-top">Documenti totali</span>
                <div class="value"><?= (int) ($summary['total_documents'] ?? 0) ?></div>
                <div class="hint">Numero complessivo di documenti TS censiti nello spazio.</div>
              </div>
            </div>
            <div class="col-md-3">
              <div class="metric-card">
                <span class="label-top">Bozze</span>
                <div class="value"><?= (int) ($summary['draft_count'] ?? 0) ?></div>
                <div class="hint">Documenti ancora da completare o validare localmente.</div>
              </div>
            </div>
            <div class="col-md-3">
              <div class="metric-card">
                <span class="label-top">Pronti</span>
                <div class="value"><?= (int) ($summary['ready_count'] ?? 0) ?></div>
                <div class="hint">Documenti che hanno gia superato la validazione locale.</div>
              </div>
            </div>
            <div class="col-md-3">
              <div class="metric-card">
                <span class="label-top">Inviati</span>
                <div class="value"><?= (int) ($summary['sent_count'] ?? 0) ?></div>
                <div class="hint">Documenti con esito locale di invio completato.</div>
              </div>
            </div>
          </div>

          <div class="box box-default">
            <div class="box-header with-border">
              <h3 class="box-title">Ultimi documenti TS</h3>
            </div>
            <div class="box-body">
              <?php if ($recentDocuments === []): ?>
                <div class="text-muted">Nessun documento TS disponibile per ora.</div>
                <?php else: ?>
                  <?php foreach ($recentDocuments as $row): ?>
                    <?php $state = trim((string) ($row['local_state'] ?? 'draft')); ?>
                    <?php
                      $vatSummary = '';
                      if ($row['vat_rate'] !== null && $row['vat_rate'] !== '') {
                          $vatSummary = number_format((float) $row['vat_rate'], 2, ',', '.') . '%';
                      } elseif (trim((string) ($row['vat_nature'] ?? '')) !== '') {
                          $vatSummary = trim((string) ($row['vat_nature'] ?? ''));
                      }
                    ?>
                    <div class="doc-row">
                      <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                        <div>
                          <strong>Documento <?= esc((string) ($row['document_number'] ?? '#')) ?></strong>
                          <div class="text-muted" style="margin-top:4px;">
                            Data emissione: <?= esc((string) ($row['issue_date'] ?? '-')) ?> |
                            Doc: <?= esc((string) ($row['document_type'] ?? 'F')) ?> |
                            Tipo spesa: <?= esc((string) ($row['expense_type_code'] ?? 'SP')) ?> |
                            Importo: <?= esc(number_format((float) ($row['amount_total'] ?? 0), 2, ',', '.')) ?> EUR<?= $vatSummary !== '' ? ' | IVA: ' . esc($vatSummary) : '' ?>
                          </div>
                        </div>
                      <span class="state-chip"><?= esc((string) ($uiStateLabels[$state] ?? strtoupper($state))) ?></span>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
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
