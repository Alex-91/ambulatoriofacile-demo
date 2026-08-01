<?php
$menu_items = $menu_items ?? ((session()->get('menuDataAdmin')['result'] ?? []));
$tenantScope = is_array($tenantScope ?? null) ? $tenantScope : [];
$listing = is_array($listing ?? null) ? $listing : [];
$summary = is_array($listing['summary'] ?? null) ? $listing['summary'] : [];
$documents = is_array($listing['documents'] ?? null) ? $listing['documents'] : [];
$uiStateLabels = is_array($listing['ui_state_labels'] ?? null) ? $listing['ui_state_labels'] : [];
$sourceTypeLabels = is_array($listing['source_type_labels'] ?? null) ? $listing['source_type_labels'] : [];
$tableAvailable = !empty($listing['table_available']);
$billingQueue = is_array($billingQueue ?? null) ? $billingQueue : [];
$pendingBillingDocuments = is_array($billingQueue['pending_documents'] ?? null) ? $billingQueue['pending_documents'] : [];
$sentBillingDocuments = is_array($billingQueue['sent_documents'] ?? null) ? $billingQueue['sent_documents'] : [];
$pendingBillingCount = (int) ($billingQueue['pending_count'] ?? count($pendingBillingDocuments));
$sentBillingCount = (int) ($billingQueue['sent_count'] ?? count($sentBillingDocuments));
$errors = is_array($errors ?? null) ? $errors : [];
$warning = trim((string) ($warning ?? ''));
$error = trim((string) ($error ?? ''));
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
  <link href="<?= base_url('public/assets/css/billing-ts-ui.css') ?>" rel="stylesheet" />
</head>
<body class="skin-blue sidebar-mini billing-ts-ui module-sistema-ts">
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
          <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?= esc((string) $success) ?></div>
          <?php endif; ?>
          <?php if ($warning !== ''): ?>
            <div class="alert alert-warning"><?= esc($warning) ?></div>
          <?php endif; ?>
          <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= esc($error) ?></div>
          <?php endif; ?>
          <?php if (!empty($errors['generic'])): ?>
            <div class="alert alert-danger"><?= esc((string) $errors['generic']) ?></div>
          <?php endif; ?>

          <div class="summary-strip">
            <strong>Riepilogo rapido:</strong>
            <?= (int) ($summary['total_documents'] ?? 0) ?> documenti totali,
            <?= (int) ($summary['draft_count'] ?? 0) ?> bozze,
            <?= (int) ($summary['ready_count'] ?? 0) ?> pronti,
            <?= (int) ($summary['sent_count'] ?? 0) ?> inviati,
            <?= (int) ($summary['rejected_count'] ?? 0) ?> scartati.
            <span style="margin-left:10px;">Fatture in coda: <?= $pendingBillingCount ?></span>
            <span style="margin-left:10px;">Fatture già inviate: <?= $sentBillingCount ?></span>
            <a class="btn btn-success btn-sm pull-right" href="<?= site_url('admin/sistema-ts/documenti/nuovo') ?>">
              <i class="fa fa-plus"></i> Nuovo documento TS
            </a>
          </div>

          <?php if (!$tableAvailable): ?>
            <div class="alert alert-warning">
              La tabella `ts_documents` non è ancora disponibile su questo database. Prima di usare davvero la lista documenti TS dobbiamo eseguire le migration del modulo.
            </div>
          <?php endif; ?>

          <div class="box box-warning">
            <div class="box-header with-border">
              <h3 class="box-title">Fatture da inviare a TS</h3>
            </div>
            <div class="box-body">
              <?php if ($pendingBillingDocuments === []): ?>
                <p class="text-muted" style="margin:0;">
                  Nessuna fattura definitiva pronta per l’invio TS. Le fatture salvate in Fatturazione con integrazione TS attiva compariranno qui.
                </p>
              <?php else: ?>
                <form method="post" action="<?= site_url('admin/sistema-ts/documenti/send-bulk-billing') ?>">
                  <?= csrf_field() ?>
                  <div style="margin-bottom:12px;">
                    <button class="btn btn-warning btn-sm" type="submit" id="billing-ts-bulk-send-btn" disabled>
                      <i class="fa fa-paper-plane-o"></i> Invia selezionate a TS
                    </button>
                  </div>
                  <div class="table-responsive">
                    <table class="table table-bordered table-striped" style="margin-bottom:0;">
                      <thead>
                        <tr>
                          <th style="width:40px;"><input type="checkbox" id="billing-ts-check-all"></th>
                          <th>Fattura</th>
                          <th>Cliente</th>
                          <th>Emissione</th>
                          <th>Totale</th>
                          <th>Spesa TS</th>
                          <th>Stato TS</th>
                          <th>Documento TS</th>
                          <th style="width:180px;">Azioni</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($pendingBillingDocuments as $row): ?>
                          <tr>
                            <td><input type="checkbox" class="billing-ts-check" name="billing_document_ids[]" value="<?= (int) ($row['id_billing_document'] ?? 0) ?>"></td>
                            <td>#<?= (int) ($row['id_billing_document'] ?? 0) ?> / <?= esc((string) ($row['document_number'] ?? '-')) ?></td>
                            <td><?= esc((string) ($row['patient_name'] ?? '-')) ?></td>
                            <td><?= esc((string) ($row['issue_date'] ?? '-')) ?></td>
                            <td>&euro; <?= number_format((float) ($row['amount_total'] ?? 0), 2, ',', '.') ?></td>
                            <td><?= esc(trim((string) ($row['ts_expense_type_code'] ?? '')) !== '' ? (string) ($row['ts_expense_type_code'] ?? '') : 'SP') ?></td>
                            <td>
                              <?= esc((string) ($row['ts_sync_state'] ?? 'ready')) ?>
                              <?php if (trim((string) ($row['ts_local_state'] ?? '')) !== ''): ?>
                                / <?= esc((string) ($uiStateLabels[(string) ($row['ts_local_state'] ?? '')] ?? (string) ($row['ts_local_state'] ?? ''))) ?>
                              <?php endif; ?>
                            </td>
                            <td>
                              <?php if ((int) ($row['linked_ts_document_id'] ?? 0) > 0): ?>
                                #<?= (int) ($row['linked_ts_document_id'] ?? 0) ?>
                              <?php else: ?>
                                Non creato
                              <?php endif; ?>
                            </td>
                            <td>
                              <a class="btn btn-default btn-xs" href="<?= site_url('admin/fatturazione-documenti/modifica/' . (int) ($row['id_billing_document'] ?? 0)) ?>">
                                <i class="fa fa-file-text-o"></i> Fattura
                              </a>
                              <?php if ((int) ($row['linked_ts_document_id'] ?? 0) > 0): ?>
                                <a class="btn btn-warning btn-xs" href="<?= site_url('admin/sistema-ts/documenti/modifica/' . (int) ($row['linked_ts_document_id'] ?? 0)) ?>">
                                  <i class="fa fa-exchange"></i> TS
                                </a>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <div class="box box-success">
            <div class="box-header with-border">
              <h3 class="box-title">Fatture già inviate a TS</h3>
            </div>
            <div class="box-body">
              <?php if ($sentBillingDocuments === []): ?>
                <p class="text-muted" style="margin:0;">
                  Nessuna fattura proveniente da Fatturazione risulta ancora inviata a TS.
                </p>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-bordered table-striped" style="margin-bottom:0;">
                    <thead>
                      <tr>
                        <th>Fattura</th>
                        <th>Cliente</th>
                        <th>Emissione</th>
                        <th>Totale</th>
                        <th>Documento TS</th>
                        <th>Protocollo</th>
                        <th>Stato TS</th>
                        <th style="width:260px;">Azioni</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($sentBillingDocuments as $row): ?>
                        <tr>
                          <td>#<?= (int) ($row['id_billing_document'] ?? 0) ?> / <?= esc((string) ($row['document_number'] ?? '-')) ?></td>
                          <td><?= esc((string) ($row['patient_name'] ?? '-')) ?></td>
                          <td><?= esc((string) ($row['issue_date'] ?? '-')) ?></td>
                          <td>&euro; <?= number_format((float) ($row['amount_total'] ?? 0), 2, ',', '.') ?></td>
                          <td>#<?= (int) ($row['linked_ts_document_id'] ?? 0) ?></td>
                          <td><?= esc((string) ($row['ts_protocol'] ?? '-')) ?></td>
                          <td><?= esc((string) ($row['ts_state'] ?? 'accepted')) ?></td>
                          <td>
                            <a class="btn btn-default btn-xs" href="<?= site_url('admin/fatturazione-documenti/modifica/' . (int) ($row['id_billing_document'] ?? 0)) ?>">
                              <i class="fa fa-file-text-o"></i> Fattura
                            </a>
                            <a class="btn btn-info btn-xs" href="<?= site_url('admin/sistema-ts/documenti/ricevuta/download-latest/' . (int) ($row['linked_ts_document_id'] ?? 0)) ?>">
                              <i class="fa fa-download"></i> Ricevuta
                            </a>
                            <a class="btn btn-success btn-xs" href="<?= site_url('admin/sistema-ts/documenti/modifica/' . (int) ($row['linked_ts_document_id'] ?? 0)) ?>">
                              <i class="fa fa-exchange"></i> TS
                            </a>
                          </td>
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
              <h3 class="box-title">Archivio completo documenti TS</h3>
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
                          <a class="btn btn-default btn-xs" href="<?= site_url('admin/sistema-ts/documenti/modifica/' . (int) ($row['id_ts_document'] ?? 0)) ?>">
                            <i class="fa fa-pencil"></i> Apri
                          </a>
                          <?php if (trim((string) ($row['local_state'] ?? '')) === 'sent'): ?>
                            <a class="btn btn-info btn-xs" href="<?= site_url('admin/sistema-ts/documenti/ricevuta/download-latest/' . (int) ($row['id_ts_document'] ?? 0)) ?>">
                              <i class="fa fa-download"></i> Ricevuta
                            </a>
                          <?php endif; ?>
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
<script>
  (function () {
    var checkAll = document.getElementById('billing-ts-check-all');
    var bulkButton = document.getElementById('billing-ts-bulk-send-btn');
    var rowChecks = Array.prototype.slice.call(document.querySelectorAll('.billing-ts-check'));

    if (!checkAll || !bulkButton || rowChecks.length === 0) {
      return;
    }

    function syncBulkState() {
      var anyChecked = rowChecks.some(function (input) {
        return input.checked;
      });
      bulkButton.disabled = !anyChecked;
      checkAll.checked = rowChecks.length > 0 && rowChecks.every(function (input) {
        return input.checked;
      });
    }

    checkAll.addEventListener('change', function () {
      rowChecks.forEach(function (input) {
        input.checked = checkAll.checked;
      });
      syncBulkState();
    });

    rowChecks.forEach(function (input) {
      input.addEventListener('change', syncBulkState);
    });
  })();
</script>
</body>
</html>
