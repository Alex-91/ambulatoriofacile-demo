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
$sourceContext = is_array($formContext['source_context'] ?? null) ? $formContext['source_context'] : [];
$linkedPatient = is_array($formContext['linked_patient'] ?? null) ? $formContext['linked_patient'] : [];
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
$sourcePatientMetaParts = [];
$sourcePatientLabel = trim((string) ($sourceContext['patient_label'] ?? ''));
$sourcePatientLastName = trim((string) ($sourceContext['patient_last_name'] ?? ''));
$sourcePatientFirstName = trim((string) ($sourceContext['patient_first_name'] ?? ''));
$sourcePatientTaxCode = strtoupper(trim((string) ($sourceContext['patient_tax_code'] ?? '')));
$sourcePatientPhone = trim((string) ($sourceContext['patient_phone'] ?? ''));
$sourcePatientMobile = trim((string) ($sourceContext['patient_mobile'] ?? ''));
$sourcePatientEmail = trim((string) ($sourceContext['patient_email'] ?? ''));
$sourcePatientAddress = trim((string) ($sourceContext['patient_address'] ?? ''));
$sourcePatientCity = trim((string) ($sourceContext['patient_city'] ?? ''));
$sourcePatientPhoneLabel = $sourcePatientMobile !== '' ? $sourcePatientMobile : ($sourcePatientPhone !== '' ? $sourcePatientPhone : 'Non disponibile');
$sourcePatientEmailLabel = $sourcePatientEmail !== '' ? $sourcePatientEmail : 'Non disponibile';
$sourcePatientTaxCodeLabel = $sourcePatientTaxCode !== '' ? $sourcePatientTaxCode : 'Da completare';
$sourcePatientAddressLabel = trim(preg_replace('/\s+/', ' ', $sourcePatientAddress . ' ' . $sourcePatientCity) ?? '');
$sourcePatientAddressLabel = $sourcePatientAddressLabel !== '' ? $sourcePatientAddressLabel : 'Non disponibile';

if ($sourcePatientTaxCode !== '') {
    $sourcePatientMetaParts[] = 'CF ' . $sourcePatientTaxCode;
}
if ($sourcePatientMobile !== '') {
    $sourcePatientMetaParts[] = 'Cell. ' . $sourcePatientMobile;
} elseif ($sourcePatientPhone !== '') {
    $sourcePatientMetaParts[] = 'Tel. ' . $sourcePatientPhone;
}
if ($sourcePatientEmail !== '') {
    $sourcePatientMetaParts[] = $sourcePatientEmail;
}

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

$linkedPatientId = (int) $fieldValue('id_client', '0');
$patientDisplayLabel = trim((string) (preg_replace(
    '/\s+/',
    ' ',
    $fieldValue('patient_last_name') . ' ' . $fieldValue('patient_first_name')
) ?? ''));
if ($patientDisplayLabel === '') {
    $patientDisplayLabel = trim((string) $fieldValue('patient_name'));
}
$patientSearchValue = old('patient_search_term');
$patientSearchValue = $patientSearchValue !== null
    ? trim((string) $patientSearchValue)
    : ($patientDisplayLabel !== '' ? $patientDisplayLabel : trim((string) ($linkedPatient['label'] ?? '')));
$patientLinkedBadgeLabel = $patientDisplayLabel !== '' ? $patientDisplayLabel : trim((string) ($linkedPatient['label'] ?? ''));
$patientLinkedBadgeLabel = $patientLinkedBadgeLabel !== '' ? $patientLinkedBadgeLabel : 'Paziente collegato';
$linkedPatientVisibleLabel = $patientLinkedBadgeLabel;
$linkedPatientVisibleId = $linkedPatientId > 0 ? $linkedPatientId : (int) ($linkedPatient['id_client'] ?? 0);

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
    .patient-autocomplete-menu { display:none; margin-top:6px; border:1px solid #dce7eb; border-radius:12px; background:#fff; box-shadow:0 8px 24px rgba(44, 136, 149, 0.08); max-height:240px; overflow:auto; }
    .patient-autocomplete-item { display:block; width:100%; padding:10px 12px; border:0; border-bottom:1px solid #edf3f5; background:#fff; text-align:left; }
    .patient-autocomplete-item:last-child { border-bottom:0; }
    .patient-autocomplete-item:hover, .patient-autocomplete-item:focus { background:#f5fafb; outline:none; }
    .patient-autocomplete-meta { display:block; margin-top:4px; color:#70848b; font-size:12px; }
    .patient-autocomplete-help { display:block; margin-top:8px; color:#70848b; }
    .patient-autocomplete-help.is-success { color:#1f7a3f; }
    .patient-autocomplete-help.is-warning { color:#9a6a06; }
    .patient-link-state { display:flex; min-height:74px; align-items:center; justify-content:flex-end; }
    .patient-link-pill { display:inline-flex; align-items:center; gap:8px; padding:10px 14px; border-radius:14px; background:#eef7f8; color:#1d6770; font-weight:700; font-size:12px; }
    .patient-link-pill.is-manual { background:#f6f7f8; color:#607278; }
    .patient-link-pill strong { font-size:13px; }
    .patient-data-note { margin-top:8px; color:#5c7077; font-size:12px; }
    .patient-linked-summary { margin:0 0 14px 0; border:1px solid #cfe4d7; border-radius:14px; padding:14px 16px; background:linear-gradient(135deg, #f4fcf7 0%, #eef8f9 100%); color:#204b35; }
    .patient-linked-summary strong { color:#173d2b; }
    .patient-linked-summary small { display:block; margin-top:4px; color:#517064; }
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
            <?php if ($sourceContext !== []): ?>
              <div class="alert alert-info" style="margin:0 0 12px 0;">
                <strong><?= esc((string) ($sourceContext['title'] ?? 'Origine appuntamento')) ?></strong>
                <?php if (trim((string) ($sourceContext['patient_label'] ?? '')) !== ''): ?>
                  per <?= esc((string) ($sourceContext['patient_label'] ?? '')) ?>
                <?php endif; ?>
                <?php if (trim((string) ($sourceContext['appointment_date_label'] ?? '')) !== ''): ?>
                  del <?= esc((string) ($sourceContext['appointment_date_label'] ?? '')) ?>
                <?php endif; ?>
                <?php if (trim((string) ($sourceContext['appointment_time_label'] ?? '')) !== ''): ?>
                  alle <?= esc((string) ($sourceContext['appointment_time_label'] ?? '')) ?>
                <?php endif; ?>
                <?php if (trim((string) ($sourceContext['doctor_label'] ?? '')) !== ''): ?>
                  con <?= esc((string) ($sourceContext['doctor_label'] ?? '')) ?>
                <?php endif; ?>.
                <div style="margin-top:6px;"><?= esc((string) ($sourceContext['message'] ?? '')) ?></div>
                <div style="margin-top:10px; padding:10px 12px; border:1px solid #d8e6ee; border-radius:12px; background:#f9fcfd; font-size:12px; color:#425462;">
                  <div><strong>Cliente collegato:</strong> <?= esc($sourcePatientLabel !== '' ? $sourcePatientLabel : 'Anagrafica dello spazio') ?></div>
                  <div style="margin-top:4px;"><strong>Cognome:</strong> <?= esc($sourcePatientLastName !== '' ? $sourcePatientLastName : 'Non disponibile') ?></div>
                  <div style="margin-top:4px;"><strong>Nome:</strong> <?= esc($sourcePatientFirstName !== '' ? $sourcePatientFirstName : 'Non disponibile') ?></div>
                  <div style="margin-top:4px;"><strong>Codice fiscale:</strong> <?= esc($sourcePatientTaxCodeLabel) ?></div>
                  <div style="margin-top:4px;"><strong>Recapito:</strong> <?= esc($sourcePatientPhoneLabel) ?></div>
                  <div style="margin-top:4px;"><strong>Email:</strong> <?= esc($sourcePatientEmailLabel) ?></div>
                  <div style="margin-top:4px;"><strong>Indirizzo:</strong> <?= esc($sourcePatientAddressLabel) ?></div>
                </div>
              </div>
            <?php endif; ?>
            <span class="status-chip">Stato: <?= esc((string) ($localStateLabels[(string) ($document['local_state'] ?? '')] ?? $document['local_state'] ?? 'Bozza')) ?></span>
            <span class="status-chip">TS: <?= esc((string) ($tsSyncLabels[(string) ($document['ts_sync_state'] ?? '')] ?? $document['ts_sync_state'] ?? 'Non richiesto')) ?></span>
            <div style="margin-top:12px;">
              <a class="btn btn-default" href="<?= site_url('admin/fatturazione-documenti') ?>">
                <i class="fa fa-arrow-left"></i> Torna alla lista fatture
              </a>
              <?php if (trim((string) ($sourceContext['return_url'] ?? '')) !== ''): ?>
                <a class="btn btn-default" href="<?= esc((string) ($sourceContext['return_url'] ?? '')) ?>" style="margin-left:8px;">
                  <i class="fa fa-calendar"></i> <?= esc((string) ($sourceContext['return_label'] ?? 'Torna all agenda')) ?>
                </a>
              <?php endif; ?>
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
            <input type="hidden" name="id_client" value="<?= esc($fieldValue('id_client', '0')) ?>">

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

                <div class="row js-patient-autocomplete" data-autocomplete-url="<?= esc(site_url('admin/fatturazione-documenti/pazienti/search')) ?>">
                  <input type="hidden" name="patient_name" value="<?= esc($fieldValue('patient_name')) ?>">
                  <?php if ($linkedPatientVisibleId > 0): ?>
                    <div class="col-md-12">
                      <div class="patient-linked-summary">
                        <strong><i class="fa fa-link"></i> Paziente collegato all appuntamento:</strong>
                        <?= esc($linkedPatientVisibleLabel) ?>
                        <small>ID anagrafica spazio: <?= (int) $linkedPatientVisibleId ?>. Se salvi la fattura con questo collegamento attivo, aggiorni anche la sua anagrafica.</small>
                      </div>
                    </div>
                  <?php endif; ?>
                  <div class="col-md-8">
                    <div class="form-group">
                      <label>Cerca paziente nello spazio</label>
                      <div class="input-group">
                        <input class="form-control" type="text" name="patient_search_term" maxlength="190" value="<?= esc($patientSearchValue) ?>" placeholder="Scrivi nome, cognome, CF, email o telefono" autocomplete="off" data-role="patient-search">
                        <span class="input-group-btn">
                          <button class="btn btn-default" type="button" data-role="patient-unlink"<?= $linkedPatientId > 0 ? '' : ' disabled' ?>>
                            <i class="fa fa-unlink"></i> Scollega
                          </button>
                        </span>
                      </div>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="patient-link-state">
                      <div class="patient-link-pill<?= $linkedPatientId > 0 ? '' : ' is-manual' ?>" data-role="patient-link-pill">
                        <?php if ($linkedPatientId > 0): ?>
                          <span><i class="fa fa-link"></i> Collegato</span>
                          <strong><?= esc($patientLinkedBadgeLabel) ?></strong>
                        <?php else: ?>
                          <span><i class="fa fa-user-o"></i> Fattura manuale</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Cognome</label>
                      <input class="form-control" type="text" name="patient_last_name" maxlength="120" value="<?= esc($fieldValue('patient_last_name')) ?>" placeholder="Es. Rossi" autocomplete="off">
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Nome</label>
                      <input class="form-control" type="text" name="patient_first_name" maxlength="120" value="<?= esc($fieldValue('patient_first_name')) ?>" placeholder="Es. Mario" autocomplete="off">
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Codice fiscale</label>
                      <input class="form-control" type="text" name="patient_tax_code" maxlength="16" value="<?= esc($fieldValue('patient_tax_code')) ?>" placeholder="RSSMRA80A01H501Z" autocomplete="off">
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Telefono</label>
                      <input class="form-control" type="text" name="patient_phone" maxlength="40" value="<?= esc($fieldValue('patient_phone')) ?>" placeholder="Telefono fisso" autocomplete="off">
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Cellulare</label>
                      <input class="form-control" type="text" name="patient_mobile" maxlength="40" value="<?= esc($fieldValue('patient_mobile')) ?>" placeholder="Cellulare" autocomplete="off">
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Email</label>
                      <input class="form-control" type="email" name="patient_email" maxlength="190" value="<?= esc($fieldValue('patient_email')) ?>" placeholder="paziente@email.it" autocomplete="off">
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Indirizzo</label>
                      <input class="form-control" type="text" name="patient_address" maxlength="190" value="<?= esc($fieldValue('patient_address')) ?>" placeholder="Via Roma 10" autocomplete="off">
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Citta</label>
                      <input class="form-control" type="text" name="patient_city" maxlength="120" value="<?= esc($fieldValue('patient_city')) ?>" placeholder="Es. Bologna" autocomplete="off">
                    </div>
                  </div>
                  <div class="col-md-12">
                    <div class="patient-autocomplete-menu" data-role="patient-results"></div>
                    <small class="patient-autocomplete-help" data-role="patient-help">
                      Cerca un paziente gia presente nello spazio oppure compila i campi manualmente.
                    </small>
                    <div class="patient-data-note">
                      Se la fattura resta collegata a un paziente dello spazio, ogni modifica a questi dati aggiorna anche la sua anagrafica.
                    </div>
                  </div>
                  <?php if ($sourceContext !== []): ?>
                    <div class="col-md-12">
                      <div class="alert alert-info" style="margin-top:4px; margin-bottom:8px; padding:12px 14px;">
                        <strong>Dati cliente importati dall appuntamento</strong>
                        <div style="margin-top:6px; font-size:12px; color:#425462;">
                          <?= esc($sourcePatientLabel !== '' ? $sourcePatientLabel : 'Anagrafica collegata') ?>
                          | Cognome: <?= esc($sourcePatientLastName !== '' ? $sourcePatientLastName : '-') ?>
                          | Nome: <?= esc($sourcePatientFirstName !== '' ? $sourcePatientFirstName : '-') ?>
                          | CF: <?= esc($sourcePatientTaxCodeLabel) ?>
                          | Recapito: <?= esc($sourcePatientPhoneLabel) ?>
                          | Email: <?= esc($sourcePatientEmailLabel) ?>
                        </div>
                      </div>
                    </div>
                  <?php endif; ?>
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
<script>
  (function ($) {
    function initPatientAutocomplete($root) {
      if (!$root.length) {
        return;
      }

      var endpoint = $.trim($root.data('autocomplete-url') || '');
      var $search = $root.find('input[name="patient_search_term"]');
      var $hiddenName = $root.find('input[name="patient_name"]');
      var $lastName = $root.find('input[name="patient_last_name"]');
      var $firstName = $root.find('input[name="patient_first_name"]');
      var $taxCode = $root.find('input[name="patient_tax_code"]');
      var $phone = $root.find('input[name="patient_phone"]');
      var $mobile = $root.find('input[name="patient_mobile"]');
      var $email = $root.find('input[name="patient_email"]');
      var $address = $root.find('input[name="patient_address"]');
      var $city = $root.find('input[name="patient_city"]');
      var $clientId = $('input[name="id_client"]');
      var $results = $root.find('[data-role="patient-results"]');
      var $help = $root.find('[data-role="patient-help"]');
      var $unlinkButton = $root.find('[data-role="patient-unlink"]');
      var $linkPill = $root.find('[data-role="patient-link-pill"]');
      var debounceTimer = null;
      var pendingRequest = null;

      if (!$search.length || !$hiddenName.length || !$lastName.length || !$firstName.length || !$taxCode.length || !$clientId.length || endpoint === '') {
        return;
      }

      function buildPatientLabel() {
        return $.trim((
          $.trim($lastName.val() || '') + ' ' + $.trim($firstName.val() || '')
        ).replace(/\s+/g, ' '));
      }

      function syncHiddenPatientName() {
        var label = buildPatientLabel();
        if (label === '') {
          label = $.trim($search.val() || '');
        }

        $hiddenName.val(label);
        return label;
      }

      function setHelp(message, level) {
        $help.removeClass('is-success is-warning').text(message);
        if (level === 'success') {
          $help.addClass('is-success');
        } else if (level === 'warning') {
          $help.addClass('is-warning');
        }
      }

      function hideResults() {
        $results.hide().empty();
      }

      function refreshLinkedState(forceMessage) {
        var linkedId = parseInt($clientId.val() || '0', 10) || 0;
        var label = syncHiddenPatientName();

        if (linkedId > 0) {
          if (label === '') {
            label = 'Paziente #' + linkedId;
          }

          $linkPill
            .removeClass('is-manual')
            .html('<span><i class="fa fa-link"></i> Collegato</span><strong>' + $('<div>').text(label).html() + '</strong>');
          $unlinkButton.prop('disabled', false);

          if (forceMessage !== false) {
            setHelp('Paziente collegato allo spazio. Se modifichi questi campi, al salvataggio aggiorni anche la sua anagrafica.', 'success');
          }

          return;
        }

        $linkPill
          .addClass('is-manual')
          .html('<span><i class="fa fa-user-o"></i> Fattura manuale</span>');
        $unlinkButton.prop('disabled', true);

        if (forceMessage !== false) {
          setHelp('Compilazione manuale attiva. Seleziona un paziente dalla lista solo se vuoi agganciare la fattura all anagrafica dello spazio.', 'warning');
        }
      }

      function applySelection(patient) {
        $clientId.val(String(patient.id_client || 0));
        $search.val($.trim(patient.label || patient.patient_name || ''));
        $lastName.val($.trim(patient.patient_last_name || ''));
        $firstName.val($.trim(patient.patient_first_name || ''));
        $taxCode.val($.trim(patient.patient_tax_code || ''));
        $phone.val($.trim(patient.patient_phone || patient.phone || ''));
        $mobile.val($.trim(patient.patient_mobile || patient.mobile || ''));
        $email.val($.trim(patient.patient_email || patient.email || ''));
        $address.val($.trim(patient.patient_address || patient.address || ''));
        $city.val($.trim(patient.patient_city || patient.city || ''));
        syncHiddenPatientName();
        hideResults();
        refreshLinkedState(true);
      }

      function renderResults(items) {
        hideResults();

        if (!items.length) {
          setHelp('Nessun paziente trovato nello spazio. Puoi continuare con inserimento manuale.', 'warning');
          return;
        }

        $.each(items, function (_, patient) {
          var label = $.trim(patient.label || patient.patient_name || '');
          var meta = $.trim(patient.meta || '');
          var $button = $('<button type="button" class="patient-autocomplete-item"></button>');
          $button.append($('<strong></strong>').text(label !== '' ? label : ('Paziente #' + (patient.id_client || 0))));
          if (meta !== '') {
            $button.append($('<span class="patient-autocomplete-meta"></span>').text(meta));
          }
          $button.data('patient', patient);
          $results.append($button);
        });

        $results.show();
      }

      function search(term) {
        if (pendingRequest && typeof pendingRequest.abort === 'function') {
          pendingRequest.abort();
        }

        pendingRequest = $.getJSON(endpoint, { term: term })
          .done(function (response) {
            if (!response || response.ok !== true) {
              setHelp('Ricerca pazienti momentaneamente non disponibile. Puoi comunque compilare la fattura manualmente.', 'warning');
              hideResults();
              return;
            }

            renderResults($.isArray(response.results) ? response.results : []);
          })
          .fail(function () {
            setHelp('Ricerca pazienti momentaneamente non disponibile. Puoi comunque compilare la fattura manualmente.', 'warning');
            hideResults();
          })
          .always(function () {
            pendingRequest = null;
          });
      }

      function handleSearchInput() {
        var term = $.trim($search.val() || '');
        syncHiddenPatientName();

        if ((parseInt($clientId.val() || '0', 10) || 0) <= 0) {
          refreshLinkedState(false);
        }

        if (term.length < 2) {
          hideResults();
          if ((parseInt($clientId.val() || '0', 10) || 0) <= 0) {
            setHelp('Cerca un paziente gia presente nello spazio oppure compila i campi manualmente.', '');
          }
          return;
        }

        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(function () {
          search(term);
        }, 220);
      }

      function unlinkPatient() {
        $clientId.val('0');
        hideResults();
        refreshLinkedState(true);
      }

      $search.on('input', function () {
        handleSearchInput();
      });

      $unlinkButton.on('click', function () {
        unlinkPatient();
      });

      $lastName.add($firstName).on('input', function () {
        var label = syncHiddenPatientName();
        if ((parseInt($clientId.val() || '0', 10) || 0) > 0 && label !== '') {
          $search.val(label);
        }
        refreshLinkedState(false);
      });

      $taxCode.add($phone).add($mobile).add($email).add($address).add($city).on('input', function () {
        refreshLinkedState(false);
      });

      $results.on('mousedown', '.patient-autocomplete-item', function (event) {
        event.preventDefault();
      });

      $results.on('click', '.patient-autocomplete-item', function () {
        applySelection($(this).data('patient') || {});
      });

      $(document).on('click', function (event) {
        if (!$(event.target).closest($root).length) {
          hideResults();
        }
      });

      syncHiddenPatientName();
      if ((parseInt($clientId.val() || '0', 10) || 0) > 0) {
        refreshLinkedState(true);
      } else {
        refreshLinkedState(false);
      }
    }

    $(function () {
      initPatientAutocomplete($('.js-patient-autocomplete'));
    });
  })(jQuery);
</script>
</body>
</html>
