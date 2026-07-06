<?php
$menu_items = $menu_items ?? ((session()->get('menuDataAdmin')['result'] ?? []));
$tenantScope = is_array($tenantScope ?? null) ? $tenantScope : [];
$listing = is_array($listing ?? null) ? $listing : [];
$summary = is_array($listing['summary'] ?? null) ? $listing['summary'] : [];
$documents = is_array($listing['documents'] ?? null) ? $listing['documents'] : [];
$uiStateLabels = is_array($listing['ui_state_labels'] ?? null) ? $listing['ui_state_labels'] : [];
$sourceTypeLabels = is_array($listing['source_type_labels'] ?? null) ? $listing['source_type_labels'] : [];
$tableAvailable = !empty($listing['table_available']);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Documenti TS</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <style>
    .nav-pills.nav-stacked > li.active > a { background-color:#2c8895; color:#fff; }
    .summary-strip { border:1px solid #dbe8eb; border-radius:14px; background:#f8fcfc; padding:14px 16px; margin-bottom:16px; }
    .state-chip { display:inline-block; padding:5px 10px; border-radius:999px; background:#eef5f6; color:#1b6770; font-size:12px; font-weight:700; }
  </style>
</head>
<body class="skin-blue sidebar-mini">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Documenti TS</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Elenco locale dei documenti Sistema Tessera Sanitaria dello spazio <?= esc((string) ($tenantScope['tenant_name'] ?? 'attivo')) ?>.
      </p>
    </section>

    <section class="content">
      <div class="row">
        <div class="col-md-3">
          <?= view('partials/sidebar_admin', ['menu_items' => $menu_items]) ?>
        </div>

        <div class="col-md-9">
          <div class="summary-strip">
            <strong>Riepilogo rapido:</strong>
            <?= (int) ($summary['total_documents'] ?? 0) ?> documenti totali,
            <?= (int) ($summary['draft_count'] ?? 0) ?> bozze,
            <?= (int) ($summary['ready_count'] ?? 0) ?> pronti,
            <?= (int) ($summary['sent_count'] ?? 0) ?> inviati,
            <?= (int) ($summary['rejected_count'] ?? 0) ?> scartati.
            <a class="btn btn-success btn-sm pull-right" href="<?= site_url('admin/fatturazione-ts/documenti/nuovo') ?>">
              <i class="fa fa-plus"></i> Nuovo documento TS
            </a>
          </div>

          <?php if (!$tableAvailable): ?>
            <div class="alert alert-warning">
              La tabella `ts_documents` non e ancora disponibile su questo database. Prima di usare davvero la lista documenti TS dobbiamo eseguire le migration del modulo.
            </div>
          <?php endif; ?>

          <div class="box box-default">
            <div class="box-header with-border">
              <h3 class="box-title">Archivio documenti TS</h3>
            </div>
            <div class="box-body table-responsive">
              <table class="table table-bordered table-striped">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Operazione</th>
                    <th>Numero</th>
                    <th>Emissione</th>
                    <th>Pagamento</th>
                    <th>Doc.</th>
                    <th>Spesa</th>
                    <th>Pagamento</th>
                    <th>Importo</th>
                    <th>IVA</th>
                    <th>Stato locale</th>
                    <th>Stato TS</th>
                    <th>Protocollo</th>
                    <th>Origine</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($documents === []): ?>
                    <tr>
                      <td colspan="15" class="text-muted">Nessun documento TS disponibile al momento.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($documents as $row): ?>
                      <?php $state = trim((string) ($row['local_state'] ?? 'draft')); ?>
                      <?php $sourceType = trim((string) ($row['source_type'] ?? 'manual')); ?>
                      <?php
                        $vatSummary = '';
                        if ($row['vat_rate'] !== null && $row['vat_rate'] !== '') {
                            $vatSummary = number_format((float) $row['vat_rate'], 2, ',', '.') . '%';
                        } elseif (trim((string) ($row['vat_nature'] ?? '')) !== '') {
                            $vatSummary = trim((string) ($row['vat_nature'] ?? ''));
                        }
                      ?>
                      <tr>
                        <td><?= (int) ($row['id_ts_document'] ?? 0) ?></td>
                        <td><?= esc((string) ($sourceTypeLabels[$sourceType] ?? strtoupper($sourceType))) ?></td>
                        <td><?= esc((string) ($row['document_number'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['issue_date'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['payment_date'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['document_type'] ?? 'F')) ?></td>
                        <td><?= esc((string) ($row['expense_type_code'] ?? 'SP')) ?></td>
                        <td><?= esc((string) ($row['payment_mode'] ?? '')) ?></td>
                        <td><?= esc(number_format((float) ($row['amount_total'] ?? 0), 2, ',', '.')) ?> EUR</td>
                        <td><?= esc($vatSummary !== '' ? $vatSummary : '-') ?></td>
                        <td><span class="state-chip"><?= esc((string) ($uiStateLabels[$state] ?? strtoupper($state))) ?></span></td>
                        <td><?= esc((string) ($row['ts_state'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['ts_protocol'] ?? '')) ?></td>
                        <td>
                          <?php if ((int) ($row['source_ref_id'] ?? 0) > 0): ?>
                            #<?= (int) ($row['source_ref_id'] ?? 0) ?>
                          <?php else: ?>
                            -
                          <?php endif; ?>
                        </td>
                        <td>
                          <a class="btn btn-default btn-xs" href="<?= site_url('admin/fatturazione-ts/documenti/modifica/' . (int) ($row['id_ts_document'] ?? 0)) ?>">
                            <i class="fa fa-pencil"></i> Apri
                          </a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
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
