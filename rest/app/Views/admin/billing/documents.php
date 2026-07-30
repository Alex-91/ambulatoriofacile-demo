<?php
$menu_items = $menu_items ?? ((session()->get('menuDataAdmin')['result'] ?? []));
$tenantScope = is_array($tenantScope ?? null) ? $tenantScope : [];
$listing = is_array($listing ?? null) ? $listing : [];
$errors = is_array($errors ?? null) ? $errors : [];
$summary = is_array($listing['summary'] ?? null) ? $listing['summary'] : [];
$documents = is_array($listing['documents'] ?? null) ? $listing['documents'] : [];
$localStateLabels = is_array($listing['local_state_labels'] ?? null) ? $listing['local_state_labels'] : [];
$documentTypeLabels = is_array($listing['document_type_labels'] ?? null) ? $listing['document_type_labels'] : [];
$paymentMethodLabels = is_array($listing['payment_method_labels'] ?? null) ? $listing['payment_method_labels'] : [];
$tsSyncLabels = is_array($listing['ts_sync_labels'] ?? null) ? $listing['ts_sync_labels'] : [];
$tableAvailable = !empty($listing['table_available']);
$schemaMessage = trim((string) ($listing['schema_message'] ?? ''));
$warning = trim((string) ($warning ?? ''));
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Lista fatture</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <style>
    .nav-pills.nav-stacked > li.active > a { background-color:#2c8895; color:#fff; }
    .hero-box { border:1px solid #dde9ec; border-radius:16px; padding:20px 22px; background:linear-gradient(135deg, #fbfdfd 0%, #edf7f8 60%, #fff5ec 100%); margin-bottom:16px; }
    .stat-card { border:1px solid #e4ecef; border-radius:14px; padding:14px 16px; background:#fff; margin-bottom:16px; min-height:118px; }
    .stat-card .value { font-size:25px; line-height:1.1; font-weight:700; color:#8a5b10; margin-bottom:6px; }
    .stat-card .label-top { color:#72868b; font-size:12px; text-transform:uppercase; letter-spacing:.05em; font-weight:700; }
  </style>
  <link href="<?= base_url('public/assets/css/billing-ts-ui.css') ?>" rel="stylesheet" />
</head>
<body class="skin-blue sidebar-mini billing-ts-ui module-fatturazione">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Lista fatture</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Qui lo studio crea, modifica, elimina e stampa le fatture del modulo Fatturazione, tenendole separate dal Sistema TS.
      </p>
    </section>

    <section class="content">
      <div class="row">
        <div class="col-md-3">
          <?= view('partials/sidebar_admin', ['menu_items' => $menu_items]) ?>
        </div>

        <div class="col-md-9">
          <?php if (!empty($errors['generic'])): ?>
            <div class="alert alert-danger"><?= esc((string) $errors['generic']) ?></div>
          <?php endif; ?>
          <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?= esc((string) $success) ?></div>
          <?php endif; ?>
          <?php if ($warning !== ''): ?>
            <div class="alert alert-warning"><?= esc($warning) ?></div>
          <?php endif; ?>

          <div class="hero-box">
            <h3 style="margin-top:0; margin-bottom:8px;">Spazio: <?= esc((string) ($tenantScope['tenant_name'] ?? 'attivo')) ?></h3>
            <p style="margin:0 0 12px 0; color:#556b70;">
              La lista fatture del modulo usa il template configurato per generare documenti pronti per stampa e, se serve, preparati all aggancio TS.
            </p>
            <div>
              <a class="btn btn-success" href="<?= site_url('admin/fatturazione-documenti/nuovo') ?>">
                <i class="fa fa-plus"></i> Nuova fattura
              </a>
              <a class="btn btn-default" href="<?= site_url('admin/fatturazione-documento') ?>" style="margin-left:8px;">
                <i class="fa fa-file-text-o"></i> Impostazioni documento
              </a>
              <a class="btn btn-default" href="<?= site_url('admin/fatturazione') ?>" style="margin-left:8px;">
                <i class="fa fa-arrow-left"></i> Torna alla dashboard
              </a>
            </div>
          </div>

          <div class="row">
            <div class="col-md-4">
              <div class="stat-card">
                <div class="label-top">Documenti totali</div>
                <div class="value"><?= (int) ($summary['total_documents'] ?? 0) ?></div>
                <div class="text-muted">Fatture presenti nello spazio di lavoro corrente.</div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="stat-card">
                <div class="label-top">Bozze</div>
                <div class="value"><?= (int) ($summary['draft_count'] ?? 0) ?></div>
                <div class="text-muted">Documenti ancora modificabili prima della chiusura definitiva.</div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="stat-card">
                <div class="label-top">Definitivi</div>
                <div class="value"><?= (int) ($summary['issued_count'] ?? 0) ?></div>
                <div class="text-muted">Documenti pronti per consegna o ristampa PDF.</div>
              </div>
            </div>
          </div>

          <div class="box box-default">
            <div class="box-header with-border">
              <h3 class="box-title">Elenco fatture</h3>
            </div>
            <div class="box-body">
              <?php if (!$tableAvailable): ?>
                <div class="alert alert-warning" style="margin-bottom:0;">
                  <?= esc($schemaMessage !== '' ? $schemaMessage : 'La tabella billing_documents non e ancora presente nel database tenant corrente. Prima di usare l archivio serve eseguire la migration del modulo Fatturazione.') ?>
                </div>
              <?php elseif ($documents === []): ?>
                <p class="text-muted" style="margin:0;">
                  Nessuna fattura presente. Puoi iniziare da <strong>Nuova fattura</strong> e generare il primo documento usando il template configurato.
                </p>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-striped table-bordered" style="margin-bottom:0;">
                    <thead>
                      <tr>
                        <th>#</th>
                        <th>Numero</th>
                        <th>Tipo</th>
                        <th>Cliente</th>
                        <th>Emissione</th>
                        <th>Totale</th>
                        <th>Stato</th>
                        <th>TS</th>
                        <th style="width:340px;">Azioni</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($documents as $row): ?>
                        <?php $canEdit = !array_key_exists('can_edit', $row) || !empty($row['can_edit']); ?>
                        <?php $canDelete = !array_key_exists('can_delete', $row) || !empty($row['can_delete']); ?>
                        <?php $lockedReason = trim((string) ($row['locked_reason'] ?? '')); ?>
                        <tr>
                          <td>#<?= (int) ($row['id_billing_document'] ?? 0) ?></td>
                          <td><?= esc((string) ($row['document_number'] ?? '-')) ?></td>
                          <td><?= esc((string) ($documentTypeLabels[(string) ($row['document_type'] ?? '')] ?? $row['document_type'] ?? '-')) ?></td>
                          <td><?= esc((string) ($row['patient_name'] ?? '-')) ?></td>
                          <td><?= esc((string) ($row['issue_date'] ?? '-')) ?></td>
                          <td>&euro; <?= number_format((float) ($row['amount_total'] ?? 0), 2, ',', '.') ?></td>
                          <td><?= esc((string) ($localStateLabels[(string) ($row['local_state'] ?? '')] ?? $row['local_state'] ?? '-')) ?></td>
                          <td><?= esc((string) ($tsSyncLabels[(string) ($row['ts_sync_state'] ?? '')] ?? $row['ts_sync_state'] ?? '-')) ?></td>
                          <td>
                            <a class="btn btn-xs btn-default" href="<?= site_url('admin/fatturazione-documenti/modifica/' . (int) ($row['id_billing_document'] ?? 0)) ?>">
                              <i class="fa <?= $canEdit ? 'fa-pencil' : 'fa-folder-open-o' ?>"></i> <?= $canEdit ? 'Modifica' : 'Apri' ?>
                            </a>
                            <a class="btn btn-xs btn-info" href="<?= site_url('admin/fatturazione-documenti/preview/' . (int) ($row['id_billing_document'] ?? 0)) ?>">
                              <i class="fa fa-eye"></i> Preview
                            </a>
                            <a class="btn btn-xs btn-success" href="<?= site_url('admin/fatturazione-documenti/pdf/' . (int) ($row['id_billing_document'] ?? 0)) ?>">
                              <i class="fa fa-file-pdf-o"></i> PDF
                            </a>
                            <?php if ((int) ($row['linked_ts_document_id'] ?? 0) > 0): ?>
                              <a class="btn btn-xs btn-warning" href="<?= site_url('admin/sistema-ts/documenti/modifica/' . (int) ($row['linked_ts_document_id'] ?? 0)) ?>">
                                <i class="fa fa-exchange"></i> TS
                              </a>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                              <form method="post" action="<?= site_url('admin/fatturazione-documenti/elimina/' . (int) ($row['id_billing_document'] ?? 0)) ?>" style="display:inline;" onsubmit="return confirm('Confermi la cancellazione della fattura?');">
                                <?= csrf_field() ?>
                                <button class="btn btn-xs btn-danger" type="submit">
                                  <i class="fa fa-trash"></i> Elimina
                                </button>
                              </form>
                            <?php elseif ($lockedReason !== ''): ?>
                              <span class="text-muted" style="display:inline-block; margin-left:6px; font-size:11px;">
                                <?= esc($lockedReason) ?>
                              </span>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
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
