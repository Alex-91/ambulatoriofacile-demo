<?php
$menu_items = $menu_items ?? ((session()->get('menuDataAdmin')['result'] ?? []));
$tenantScope = is_array($tenantScope ?? null) ? $tenantScope : [];
$formContext = is_array($formContext ?? null) ? $formContext : [];
$document = is_array($formContext['document'] ?? null) ? $formContext['document'] : [];
$template = is_array($formContext['template'] ?? null) ? $formContext['template'] : [];
$lineItems = is_array($formContext['line_items'] ?? null) ? $formContext['line_items'] : [];
$documentTypeLabels = is_array($formContext['document_type_labels'] ?? null) ? $formContext['document_type_labels'] : [];
$paymentMethodLabels = is_array($formContext['payment_method_labels'] ?? null) ? $formContext['payment_method_labels'] : [];
$localStateLabels = is_array($formContext['local_state_labels'] ?? null) ? $formContext['local_state_labels'] : [];
$tsSyncLabels = is_array($formContext['ts_sync_labels'] ?? null) ? $formContext['ts_sync_labels'] : [];
$tsExpenseTypes = is_array($formContext['ts_expense_types'] ?? null) ? $formContext['ts_expense_types'] : [];
$tsEnabled = !empty($formContext['ts_enabled']);
$tableAvailable = !array_key_exists('table_available', $formContext) || !empty($formContext['table_available']);
$schemaMessage = trim((string) ($formContext['schema_message'] ?? ''));
$editLocked = !empty($formContext['edit_locked']);
$editLockReason = trim((string) ($formContext['edit_lock_reason'] ?? ''));
$actionState = is_array($formContext['action_state'] ?? null) ? $formContext['action_state'] : [];
$linkedTsDocumentId = (int) ($actionState['linked_ts_document_id'] ?? ($document['linked_ts_document_id'] ?? 0));
$errors = is_array($errors ?? null) ? $errors : [];
$warning = trim((string) ($warning ?? ''));
$oldInput = session()->getFlashdata('_ci_old_input');
$oldInput = is_array($oldInput) ? $oldInput : [];
$documentId = (int) ($document['id_billing_document'] ?? 0);
$saveDisabled = !$tableAvailable || $editLocked;

$fieldValue = static function (string $key, $default = '') use ($document): string {
    $old = old($key);
    if ($old !== null) {
        return trim((string) $old);
    }

    return trim((string) ($document[$key] ?? $default));
};

$fieldBool = static function (string $key, bool $default = false) use ($document, $oldInput): bool {
    if ($oldInput !== []) {
        if (!array_key_exists($key, $oldInput)) {
            return false;
        }

        return in_array(strtolower(trim((string) $oldInput[$key])), ['1', 'true', 'on', 'yes'], true);
    }

    $old = old($key);
    if ($old !== null) {
        return in_array(strtolower(trim((string) $old)), ['1', 'true', 'on', 'yes'], true);
    }

    return ((int) ($document[$key] ?? ($default ? 1 : 0))) === 1;
};

$oldDescriptions = is_array($oldInput['item_description'] ?? null) ? $oldInput['item_description'] : [];
$oldQuantities = is_array($oldInput['item_qty'] ?? null) ? $oldInput['item_qty'] : [];
$oldUnitAmounts = is_array($oldInput['item_unit_amount'] ?? null) ? $oldInput['item_unit_amount'] : [];
$itemRows = [];
if ($oldDescriptions !== [] || $oldQuantities !== [] || $oldUnitAmounts !== []) {
    $rowCount = max(count($oldDescriptions), count($oldQuantities), count($oldUnitAmounts), 6);
    for ($i = 0; $i < $rowCount; $i++) {
        $itemRows[] = [
            'description' => trim((string) ($oldDescriptions[$i] ?? '')),
            'quantity' => trim((string) ($oldQuantities[$i] ?? '')),
            'unit_amount' => trim((string) ($oldUnitAmounts[$i] ?? '')),
        ];
    }
} else {
    foreach ($lineItems as $item) {
        $itemRows[] = [
            'description' => trim((string) ($item['description'] ?? '')),
            'quantity' => trim((string) ($item['quantity'] ?? '')),
            'unit_amount' => trim((string) ($item['unit_amount'] ?? '')),
        ];
    }
    while (count($itemRows) < 6) {
        $itemRows[] = ['description' => '', 'quantity' => '', 'unit_amount' => ''];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | <?= esc((string) ($pageTitle ?? 'Documento fatturazione')) ?></title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <style>
    .nav-pills.nav-stacked > li.active > a { background-color:#2c8895; color:#fff; }
    .intro-box { border:1px solid #dce9ec; border-radius:16px; padding:20px 22px; background:linear-gradient(135deg, #fffdf8 0%, #f6f0e0 52%, #edf7f8 100%); margin-bottom:16px; }
    .status-chip { display:inline-block; margin:0 8px 8px 0; padding:6px 10px; border-radius:999px; background:#eef5f6; color:#1d6770; font-size:12px; font-weight:700; }
    .item-total { font-weight:700; color:#8a5b10; }
  </style>
</head>
<body class="skin-blue sidebar-mini">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1><?= esc((string) ($pageTitle ?? 'Documento fatturazione')) ?></h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Compila il documento cliente e usa il template di Fatturazione per ottenere subito anteprima e PDF.
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
          <?php if (!$tableAvailable): ?>
            <div class="alert alert-warning">
              <?= esc($schemaMessage !== '' ? $schemaMessage : 'Il database di questo spazio non e ancora pronto per salvare documenti fatturazione.') ?>
            </div>
          <?php endif; ?>

            <div class="intro-box">
            <h3 style="margin-top:0; margin-bottom:8px;">Spazio: <?= esc((string) ($tenantScope['tenant_name'] ?? 'attivo')) ?></h3>
            <p style="margin:0 0 12px 0; color:#556b70;">
              Questo documento nasce nel modulo Fatturazione. L eventuale collegamento al Sistema TS resta separato e si attiva solo se richiesto.
            </p>
            <span class="status-chip">Stato: <?= esc((string) ($localStateLabels[(string) ($document['local_state'] ?? '')] ?? $document['local_state'] ?? 'Bozza')) ?></span>
            <span class="status-chip">TS: <?= esc((string) ($tsSyncLabels[(string) ($document['ts_sync_state'] ?? '')] ?? $document['ts_sync_state'] ?? 'Non richiesto')) ?></span>
            <div style="margin-top:12px;">
              <a class="btn btn-default" href="<?= site_url('admin/fatturazione-documenti') ?>">
                <i class="fa fa-arrow-left"></i> Torna alla lista fatture
              </a>
              <?php if ($documentId > 0 && $tableAvailable): ?>
                <a class="btn btn-info" href="<?= site_url('admin/fatturazione-documenti/preview/' . $documentId) ?>" style="margin-left:8px;">
                  <i class="fa fa-eye"></i> Preview
                </a>
                <a class="btn btn-success" href="<?= site_url('admin/fatturazione-documenti/pdf/' . $documentId) ?>" style="margin-left:8px;">
                  <i class="fa fa-file-pdf-o"></i> PDF
                </a>
                <?php if ($linkedTsDocumentId > 0): ?>
                  <a class="btn btn-warning" href="<?= site_url('admin/sistema-ts/documenti/modifica/' . $linkedTsDocumentId) ?>" style="margin-left:8px;">
                    <i class="fa fa-exchange"></i> Apri TS
                  </a>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($editLocked && $editLockReason !== ''): ?>
            <div class="alert alert-warning">
              <?= esc($editLockReason) ?>
            </div>
          <?php endif; ?>

          <form method="post" action="<?= site_url('admin/fatturazione-documenti/save') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id_billing_document" value="<?= $documentId ?>">

            <div class="box box-default">
              <div class="box-header with-border">
                <h3 class="box-title">Dati documento</h3>
              </div>
              <div class="box-body">
                <div class="row">
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Numero documento *</label>
                      <input class="form-control" type="text" name="document_number" maxlength="32" value="<?= esc($fieldValue('document_number')) ?>">
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Tipo documento *</label>
                      <select class="form-control" name="document_type">
                        <?php foreach ($documentTypeLabels as $value => $label): ?>
                          <option value="<?= esc($value) ?>" <?= $fieldValue('document_type', 'invoice') === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <div class="col-md-2">
                    <div class="form-group">
                      <label>Data emissione *</label>
                      <input class="form-control" type="date" name="issue_date" value="<?= esc($fieldValue('issue_date', date('Y-m-d'))) ?>">
                    </div>
                  </div>
                  <div class="col-md-2">
                    <div class="form-group">
                      <label>Data pagamento</label>
                      <input class="form-control" type="date" name="payment_date" value="<?= esc($fieldValue('payment_date', date('Y-m-d'))) ?>">
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-7">
                    <div class="form-group">
                      <label>Nome cliente o paziente *</label>
                      <input class="form-control" type="text" name="patient_name" maxlength="190" value="<?= esc($fieldValue('patient_name')) ?>" placeholder="Es. Mario Rossi">
                    </div>
                  </div>
                  <div class="col-md-5">
                    <div class="form-group">
                      <label>Codice fiscale</label>
                      <input class="form-control" type="text" name="patient_tax_code" maxlength="16" value="<?= esc($fieldValue('patient_tax_code')) ?>" placeholder="RSSMRA80A01H501Z">
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Metodo pagamento *</label>
                      <select class="form-control" name="payment_method">
                        <?php foreach ($paymentMethodLabels as $value => $label): ?>
                          <option value="<?= esc($value) ?>" <?= $fieldValue('payment_method', 'bank_transfer') === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Aliquota IVA</label>
                      <input class="form-control" type="number" step="0.01" min="0" name="vat_rate" value="<?= esc($fieldValue('vat_rate', '0')) ?>">
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Natura IVA</label>
                      <input class="form-control" type="text" name="vat_nature" maxlength="16" value="<?= esc($fieldValue('vat_nature')) ?>" placeholder="Es. N4 o esente">
                    </div>
                  </div>
                </div>

                <div class="form-group">
                  <label>Righe documento *</label>
                  <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                      <thead>
                        <tr>
                          <th>Descrizione</th>
                          <th style="width:120px;">Qta</th>
                          <th style="width:160px;">Importo unitario</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($itemRows as $row): ?>
                          <tr>
                            <td>
                              <input class="form-control" type="text" name="item_description[]" value="<?= esc((string) ($row['description'] ?? '')) ?>" maxlength="190" placeholder="Es. Visita specialistica">
                            </td>
                            <td>
                              <input class="form-control" type="number" name="item_qty[]" step="0.01" min="0" value="<?= esc((string) ($row['quantity'] ?? '')) ?>" placeholder="1">
                            </td>
                            <td>
                              <input class="form-control" type="number" name="item_unit_amount[]" step="0.01" min="0" value="<?= esc((string) ($row['unit_amount'] ?? '')) ?>" placeholder="120.00">
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                  <small class="text-muted">Lascia vuote le righe inutilizzate. Il totale viene calcolato automaticamente dalla somma delle righe piu la marca da bollo.</small>
                </div>

                <div class="row">
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Marca da bollo</label>
                      <input class="form-control" type="number" step="0.01" min="0" name="stamp_duty_amount" value="<?= esc($fieldValue('stamp_duty_amount', '0')) ?>">
                    </div>
                  </div>
                  <div class="col-md-8">
                    <div class="form-group">
                      <label>Note documento</label>
                      <textarea class="form-control" name="notes" rows="3" maxlength="1000"><?= esc($fieldValue('notes')) ?></textarea>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="box box-default">
              <div class="box-header with-border">
                <h3 class="box-title">Preparazione Sistema TS</h3>
              </div>
              <div class="box-body">
                <div class="alert alert-info">
                  Il documento resta nel modulo Fatturazione. Se attivi questa sezione e salvi la fattura come definitiva, la ritroverai nel modulo Sistema TS tra quelle da inviare. Se vuoi, puoi anche inviarla subito direttamente da qui.
                </div>
                <?php if (!$tsEnabled): ?>
                  <div class="alert alert-warning">
                    Il modulo Sistema TS in questo spazio non e attivo: le impostazioni qui sotto restano salvate e potranno essere riusate quando il modulo verra acceso.
                  </div>
                <?php endif; ?>
                <div class="row">
                  <div class="col-md-4">
                    <div class="checkbox">
                      <label>
                        <input type="checkbox" name="ts_sync_enabled" value="1" <?= $fieldBool('ts_sync_enabled') ? 'checked' : '' ?>>
                        Prepara il documento per aggancio TS
                      </label>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Tipo spesa TS</label>
                      <select class="form-control" name="ts_expense_type_code">
                        <option value="">Nessuno</option>
                        <?php foreach ($tsExpenseTypes as $value => $label): ?>
                          <option value="<?= esc($value) ?>" <?= $fieldValue('ts_expense_type_code', 'SP') === $value ? 'selected' : '' ?>><?= esc($value . ' - ' . $label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="checkbox" style="margin-top:30px;">
                      <label>
                        <input type="checkbox" name="ts_opposition_flag" value="1" <?= $fieldBool('ts_opposition_flag') ? 'checked' : '' ?>>
                        Opposizione privacy per TS
                      </label>
                    </div>
                  </div>
                </div>
              </div>
              <div class="box-footer">
                <button class="btn btn-default" type="submit" name="save_mode" value="draft" <?= $saveDisabled ? 'disabled' : '' ?>>
                  <i class="fa fa-save"></i> Salva bozza
                </button>
                <button class="btn btn-primary" type="submit" name="save_mode" value="final" style="margin-left:8px;" <?= $saveDisabled ? 'disabled' : '' ?>>
                  <i class="fa fa-check"></i> Salva fattura
                </button>
                <?php if ($tsEnabled): ?>
                  <button class="btn btn-warning" type="submit" name="save_mode" value="final_send_ts" style="margin-left:8px;" <?= $saveDisabled ? 'disabled' : '' ?>>
                    <i class="fa fa-paper-plane-o"></i> Salva e invia a TS
                  </button>
                <?php endif; ?>
                <?php if ($documentId > 0 && $tableAvailable): ?>
                  <a class="btn btn-info" href="<?= site_url('admin/fatturazione-documenti/preview/' . $documentId) ?>" style="margin-left:8px;">
                    <i class="fa fa-eye"></i> Preview
                  </a>
                <?php endif; ?>
              </div>
            </div>
          </form>
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
