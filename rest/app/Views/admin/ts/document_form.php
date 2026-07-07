<?php
$menu_items = $menu_items ?? ((session()->get('menuDataAdmin')['result'] ?? []));
$tenantScope = is_array($tenantScope ?? null) ? $tenantScope : [];
$formContext = is_array($formContext ?? null) ? $formContext : [];
$document = is_array($formContext['document'] ?? null) ? $formContext['document'] : [];
$profile = is_array($formContext['profile'] ?? null) ? $formContext['profile'] : [];
$validation = is_array($formContext['validation'] ?? null) ? $formContext['validation'] : [];
$events = is_array($formContext['events'] ?? null) ? $formContext['events'] : [];
$receipts = is_array($formContext['receipts'] ?? null) ? $formContext['receipts'] : [];
$parentDocument = is_array($formContext['parent_document'] ?? null) ? $formContext['parent_document'] : [];
$relatedOperations = is_array($formContext['related_operations'] ?? null) ? $formContext['related_operations'] : [];
$requestSnapshot = is_array($formContext['request_snapshot'] ?? null) ? $formContext['request_snapshot'] : [];
$responseSnapshot = is_array($formContext['response_snapshot'] ?? null) ? $formContext['response_snapshot'] : [];
$sourceTypeLabels = is_array($formContext['source_type_labels'] ?? null) ? $formContext['source_type_labels'] : [];
$supportedExpenseTypes = is_array($formContext['supported_expense_types'] ?? null) ? $formContext['supported_expense_types'] : [];
$supportedExpenseDetails = is_array($formContext['supported_expense_details'] ?? null) ? $formContext['supported_expense_details'] : [];
$supportedDocumentTypes = is_array($formContext['supported_document_types'] ?? null) ? $formContext['supported_document_types'] : [];
$paymentModes = is_array($formContext['payment_modes'] ?? null) ? $formContext['payment_modes'] : [];
$errors = is_array($errors ?? null) ? $errors : [];
$warning = trim((string) ($warning ?? ''));
$validationResultFlash = session()->getFlashdata('validation_result');
$validationResult = is_array($validationResultFlash) ? $validationResultFlash : [];
$effectiveValidation = $validationResult !== [] ? $validationResult : $validation;
$validationErrors = is_array($effectiveValidation['errors'] ?? null) ? $effectiveValidation['errors'] : [];
$validationWarnings = is_array($effectiveValidation['warnings'] ?? null) ? $effectiveValidation['warnings'] : [];
$documentId = (int) ($document['id_ts_document'] ?? 0);
$sourceType = trim((string) ($document['source_type'] ?? 'manual'));
$sourceTypeLabel = (string) ($sourceTypeLabels[$sourceType] ?? strtoupper($sourceType));
$isBillingSourceDocument = $sourceType === 'billing';
$billingSourceDocumentId = $isBillingSourceDocument ? (int) ($document['source_ref_id'] ?? 0) : 0;
$isPrimaryDocument = in_array($sourceType, ['manual', 'billing'], true);
$isVariationDocument = $sourceType === 'ts_variation';
$isCancellationDocument = $sourceType === 'ts_cancellation';
$documentState = trim((string) ($document['local_state'] ?? 'draft'));
$documentTsState = trim((string) ($document['ts_state'] ?? ''));
$documentSent = $documentId > 0 && ($documentState === 'sent' || in_array($documentTsState, ['accepted', 'varied', 'cancelled'], true));
$documentLocked = $documentState === 'sent' || $isCancellationDocument;
$identityLocked = $documentLocked || $isVariationDocument || $isBillingSourceDocument;
$allFieldsLocked = $documentLocked || $isCancellationDocument || $isBillingSourceDocument;
$canCreateVariation = $documentId > 0 && $isPrimaryDocument && $documentSent && $documentTsState !== 'cancelled';
$canCreateCancellation = $documentId > 0 && $isPrimaryDocument && $documentSent && $documentTsState !== 'cancelled';
$canAttemptSend = $documentId > 0 && $documentState === 'ready';
$canFetchReceipt = $documentId > 0 && trim((string) ($document['ts_protocol'] ?? '')) !== '';
$latestReceipt = is_array($receipts[0] ?? null) ? $receipts[0] : [];
$latestReceiptId = (int) ($latestReceipt['id_ts_receipt'] ?? 0);
$lastErrorMessage = trim((string) ($document['last_error_message'] ?? ''));
$responseMessages = is_array($responseSnapshot['messages'] ?? null) ? $responseSnapshot['messages'] : [];
$supportLog = is_array($responseSnapshot['support_log'] ?? null) ? $responseSnapshot['support_log'] : [];

$fieldValue = static function (string $key, $default = '') use ($document): string {
    $old = old($key);
    if ($old !== null) {
        return trim((string) $old);
    }

    return trim((string) ($document[$key] ?? $default));
};

$fieldBool = static function (string $key, bool $default = false) use ($document): bool {
    $old = old($key);
    if ($old !== null) {
        return (int) $old === 1;
    }

    return ((int) ($document[$key] ?? ($default ? 1 : 0))) === 1;
};

$readonlyAttr = $allFieldsLocked ? 'readonly' : '';
$disabledAttr = $allFieldsLocked ? 'disabled' : '';
$identityReadonlyAttr = $identityLocked ? 'readonly' : '';

$supportedExpenseDetailsJson = json_encode(
    $supportedExpenseDetails,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if (!is_string($supportedExpenseDetailsJson) || $supportedExpenseDetailsJson === '') {
    $supportedExpenseDetailsJson = '{}';
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | <?= esc((string) ($pageTitle ?? 'Documento TS')) ?></title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <style>
    .nav-pills.nav-stacked > li.active > a { background-color:#2c8895; color:#fff; }
    .intro-box { border:1px solid #dbe8eb; border-radius:14px; padding:18px 20px; background:linear-gradient(135deg, #f8fcfc 0%, #eef7f8 60%, #fff5ec 100%); margin-bottom:16px; }
    .status-chip { display:inline-block; margin:0 8px 8px 0; padding:6px 10px; border-radius:999px; background:#eef5f6; color:#1b6770; font-size:12px; font-weight:700; }
    .event-row { border-left:4px solid #dbe8eb; padding:10px 12px; background:#fafcfc; margin-bottom:10px; }
    .event-row.is-error { border-left-color:#dd4b39; }
    .event-row.is-warning { border-left-color:#f39c12; }
    .event-row.is-info { border-left-color:#2c8895; }
  </style>
</head>
<body class="skin-blue sidebar-mini">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1><?= esc((string) ($pageTitle ?? 'Documento TS')) ?></h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        <?php if ($isCancellationDocument): ?>
          Questa schermata rappresenta una cancellazione TS collegata a un documento gia inviato. Se l operazione e pronta puoi inviarla subito o riprovare in caso di errore.
        <?php elseif ($isBillingSourceDocument): ?>
          Questa schermata deriva da una fattura del modulo Fatturazione. Qui puoi controllare lo stato TS e lanciare l invio, mentre le modifiche ai dati della fattura restano nel modulo origine.
        <?php elseif ($isVariationDocument): ?>
          Questa schermata rappresenta una variazione TS collegata a un documento gia inviato. Aggiorna i dati ammessi e poi usa <strong>Salva e invia</strong>.
        <?php else: ?>
          Compila i dati del documento, premi <strong>Salva e invia</strong> e lascia che il modulo salvi la bozza, esegua i controlli locali e provi subito l invio SOAP al Sistema TS.
        <?php endif; ?>
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
          <?php if (!empty($errors['generic'])): ?>
            <div class="alert alert-danger"><?= esc((string) $errors['generic']) ?></div>
          <?php endif; ?>
          <?php if ($lastErrorMessage !== '' && empty($errors['generic'])): ?>
            <div class="alert alert-warning">
              <strong>Ultimo esito tecnico:</strong> <?= esc($lastErrorMessage) ?>
            </div>
          <?php endif; ?>

          <div class="intro-box">
            <h3 style="margin-top:0; margin-bottom:8px;">Spazio: <?= esc((string) ($tenantScope['tenant_name'] ?? 'attivo')) ?></h3>
            <p style="margin:0 0 12px 0; color:#556b70;">
              <?php if ($isCancellationDocument): ?>
                Questa operazione riusa l identificativo fiscale del documento originale e serve ad annullarlo su TS senza alterare lo storico locale.
              <?php elseif ($isBillingSourceDocument): ?>
                Questo record TS nasce da una fattura gia definitiva. Se devi correggere importi, anagrafica o metadati da inviare, torna nella fattura origine e poi rilancia l invio da qui o dalla coda massiva.
              <?php elseif ($isVariationDocument): ?>
                Questa operazione parte dal documento originale inviato e ti lascia correggere i dati variabili mantenendo agganciati numero, dispositivo e data emissione.
              <?php else: ?>
                Il documento usa il profilo TS attivo dello studio come sorgente per Partita IVA e configurazione di invio. Se l invio va bene, da qui sotto puoi scaricare la ricevuta e passare subito al documento successivo.
              <?php endif; ?>
            </p>
            <span class="status-chip">Tipo record: <?= esc($sourceTypeLabel) ?></span>
            <span class="status-chip">Profilo TS: <?= esc(trim((string) ($profile['profile_name'] ?? 'Non configurato')) !== '' ? (string) ($profile['profile_name'] ?? '') : 'Non configurato') ?></span>
            <span class="status-chip">P.IVA erogatore: <?= esc((string) ($profile['owner_piva'] ?? '-')) ?></span>
            <span class="status-chip">Ambiente: <?= esc((string) ($profile['environment'] ?? 'test')) ?></span>
            <span class="status-chip">Tipo soggetto: <?= esc((string) ($profile['sender_type'] ?? '-')) ?></span>
            <span class="status-chip">Stato documento: <?= esc((string) ($document['local_state'] ?? 'draft')) ?></span>
            <?php if ($parentDocument !== []): ?>
              <span class="status-chip">Documento origine: #<?= (int) ($parentDocument['id_ts_document'] ?? 0) ?></span>
            <?php endif; ?>
            <?php if ($parentDocument !== []): ?>
              <div class="alert alert-info" style="margin:12px 0 0 0;">
                Operazione collegata al documento #<?= (int) ($parentDocument['id_ts_document'] ?? 0) ?>
                del <?= esc((string) ($parentDocument['issue_date'] ?? '')) ?>
                numero <?= esc((string) ($parentDocument['document_number'] ?? '')) ?>.
                <a href="<?= site_url('admin/sistema-ts/documenti/modifica/' . (int) ($parentDocument['id_ts_document'] ?? 0)) ?>" style="margin-left:8px;">
                  Apri documento origine
                </a>
              </div>
            <?php endif; ?>
            <?php if ($isBillingSourceDocument && $billingSourceDocumentId > 0): ?>
              <div class="alert alert-info" style="margin:12px 0 0 0;">
                Documento TS generato dalla fattura #<?= $billingSourceDocumentId ?>.
                <a href="<?= site_url('admin/fatturazione-documenti/modifica/' . $billingSourceDocumentId) ?>" style="margin-left:8px;">
                  Apri fattura origine
                </a>
              </div>
            <?php endif; ?>
            <div style="margin-top:12px;">
              <a class="btn btn-default" href="<?= site_url('admin/sistema-ts/documenti') ?>">
                <i class="fa fa-arrow-left"></i> Torna alla lista documenti TS
              </a>
            </div>
          </div>

          <?php if ($validationErrors !== []): ?>
            <div class="alert alert-danger">
              <strong>Errori validazione locale:</strong>
              <ul style="margin:8px 0 0 18px;">
                <?php foreach ($validationErrors as $message): ?>
                  <li><?= esc((string) $message) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <?php if ($validationWarnings !== []): ?>
            <div class="alert alert-warning">
              <strong>Avvisi:</strong>
              <ul style="margin:8px 0 0 18px;">
                <?php foreach ($validationWarnings as $message): ?>
                  <li><?= esc((string) $message) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <div class="box box-success">
            <div class="box-header with-border">
              <h3 class="box-title">
                <?php if ($isCancellationDocument): ?>
                  Dettaglio cancellazione TS
                <?php elseif ($documentLocked): ?>
                  Documento TS inviato
                <?php else: ?>
                  <?= $documentId > 0 ? 'Modifica bozza documento TS' : 'Nuova bozza documento TS' ?>
                <?php endif; ?>
              </h3>
            </div>
            <form method="post" action="<?= site_url('admin/sistema-ts/documenti/save') ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="id_ts_document" value="<?= $documentId ?>">
              <input type="hidden" name="id_client" value="<?= esc($fieldValue('id_client', '0')) ?>">
              <div class="box-body">
                <?php if ($isVariationDocument): ?>
                  <div class="alert alert-info">
                    Per la variazione TS restano bloccati numero documento, dispositivo e data emissione, cosi l operazione rimane agganciata al record gia trasmesso.
                <?php elseif ($isBillingSourceDocument): ?>
                  <div class="alert alert-info">
                    Questo documento e in sola lettura perche nasce dalla fattura. Se devi correggere i dati, aggiorna prima la fattura e poi rilancia da Sistema TS.
                  </div>
                <?php elseif ($isCancellationDocument): ?>
                  <div class="alert alert-info">
                    La cancellazione TS non richiede modifiche manuali ai campi: qui vedi solo il riepilogo dell identificativo che verra usato verso TS.
                  </div>
                <?php endif; ?>

                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Nome paziente</label>
                      <input class="form-control" type="text" name="patient_label_plain" maxlength="190" value="<?= esc($fieldValue('patient_label_plain')) ?>" placeholder="Es. Mario Rossi" <?= $readonlyAttr ?>>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Codice Fiscale paziente *</label>
                      <input class="form-control" type="text" name="patient_cf_plain" maxlength="16" value="<?= esc($fieldValue('patient_cf_plain')) ?>" placeholder="RSSMRA80A01H501Z" <?= $readonlyAttr ?>>
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Numero documento *</label>
                      <input class="form-control" type="text" name="document_number" maxlength="32" value="<?= esc($fieldValue('document_number')) ?>" <?= $identityReadonlyAttr ?>>
                    </div>
                  </div>
                  <div class="col-md-2">
                    <div class="form-group">
                      <label>Dispositivo *</label>
                      <input class="form-control" type="number" min="1" max="999" step="1" name="document_device" value="<?= esc($fieldValue('document_device')) ?>" <?= $identityReadonlyAttr ?>>
                      <small class="text-muted">Valore numerico richiesto dal tracciato TS.</small>
                    </div>
                  </div>
                  <div class="col-md-3">
                    <div class="form-group">
                      <label>Data emissione *</label>
                      <input class="form-control" type="date" name="issue_date" value="<?= esc($fieldValue('issue_date', date('Y-m-d'))) ?>" <?= $identityReadonlyAttr ?>>
                    </div>
                  </div>
                  <div class="col-md-3">
                    <div class="form-group">
                      <label>Data pagamento *</label>
                      <input class="form-control" type="date" name="payment_date" value="<?= esc($fieldValue('payment_date', date('Y-m-d'))) ?>" <?= $readonlyAttr ?>>
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-3">
                    <div class="form-group">
                      <label>Tipo documento *</label>
                      <select class="form-control" name="document_type" <?= $disabledAttr ?>>
                        <?php foreach ($supportedDocumentTypes as $value => $label): ?>
                          <option value="<?= esc((string) $value) ?>" <?= $fieldValue('document_type', 'F') === (string) $value ? 'selected' : '' ?>>
                            <?= esc((string) $value . ' - ' . $label) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <div class="col-md-3">
                    <div class="form-group">
                      <label>Tipo spesa *</label>
                      <select class="form-control" name="expense_type_code" <?= $disabledAttr ?>>
                        <?php foreach ($supportedExpenseTypes as $value => $label): ?>
                          <option value="<?= esc((string) $value) ?>" <?= $fieldValue('expense_type_code', 'SP') === (string) $value ? 'selected' : '' ?>>
                            <?= esc((string) $value . ' - ' . $label) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <small class="text-muted" id="ts-expense-type-help">
                        I codici ammessi seguono il tracciato ufficiale TS. Alcune descrizioni avanzate sono mostrate in forma prudente direttamente per codice.
                      </small>
                    </div>
                  </div>
                  <div class="col-md-3">
                    <div class="form-group">
                      <label>Pagamento *</label>
                      <select class="form-control" name="payment_mode" <?= $disabledAttr ?>>
                        <?php foreach ($paymentModes as $value => $label): ?>
                          <option value="<?= esc((string) $value) ?>" <?= $fieldValue('payment_mode', 'tracciato') === (string) $value ? 'selected' : '' ?>>
                            <?= esc($label) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <div class="col-md-3">
                    <div class="form-group">
                      <label>Importo totale *</label>
                      <input class="form-control" type="text" name="amount_total" value="<?= esc($fieldValue('amount_total', '0,00')) ?>" placeholder="0,00" <?= $readonlyAttr ?>>
                    </div>
                  </div>
                  <div class="col-md-3">
                    <div class="checkbox" style="margin-top:32px;">
                      <label>
                        <input type="hidden" name="opposition_flag" value="0">
                        <input type="checkbox" name="opposition_flag" value="1" <?= $fieldBool('opposition_flag', false) ? 'checked' : '' ?> <?= $disabledAttr ?>>
                        Opposizione del cittadino
                      </label>
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Aliquota IVA</label>
                      <input class="form-control" type="text" name="vat_rate" value="<?= esc($fieldValue('vat_rate')) ?>" placeholder="Es. 10,00" <?= $readonlyAttr ?>>
                      <small class="text-muted">Inserisci una percentuale con due decimali, ad esempio `10,00`.</small>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Natura IVA</label>
                      <input class="form-control" type="text" name="vat_nature" maxlength="10" value="<?= esc($fieldValue('vat_nature')) ?>" placeholder="Es. N2.2" <?= $readonlyAttr ?>>
                      <small class="text-muted">Alternativa all aliquota IVA, utile per esenzioni o non imponibilita.</small>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="alert alert-info" id="ts-vat-hint" style="margin-top:25px; margin-bottom:0;">
                      Per il tipo documento F compila una sola tra aliquota IVA e natura IVA.
                    </div>
                  </div>
                </div>

                <div class="form-group">
                  <label>Note interne</label>
                  <textarea class="form-control" name="notes" rows="4" placeholder="Annotazioni operative o note di controllo" <?= $readonlyAttr ?>><?= esc($fieldValue('notes')) ?></textarea>
                </div>
              </div>

              <div class="box-footer">
                <?php if ($isCancellationDocument): ?>
                  <div class="alert alert-info" style="margin-bottom:0;">
                    Questa operazione usa i dati del documento originale. Se serve rilanciare la cancellazione usa il pulsante di invio nella sezione esito qui sotto.
                <?php elseif ($isBillingSourceDocument): ?>
                  <div class="alert alert-info" style="margin-bottom:0;">
                    Questo record nasce da Fatturazione. Per modificare i dati usa la fattura origine; per l invio puoi usare il pulsante nella sezione esito qui sotto o la coda massiva.
                  </div>
                <?php elseif ($documentLocked): ?>
                  <div class="alert alert-success" style="margin-bottom:0;">
                    Questo documento risulta gia inviato. Da qui sotto puoi scaricare la ricevuta, creare una variazione TS oppure annullarlo su TS.
                  </div>
                <?php else: ?>
                  <button class="btn btn-default" type="submit" name="save_mode" value="draft">
                    <i class="fa fa-save"></i> Salva bozza
                  </button>
                  <button class="btn btn-primary" type="submit" name="save_mode" value="send">
                    <i class="fa fa-paper-plane-o"></i> Salva e invia
                  </button>
                  <span class="text-muted" style="margin-left:10px;">
                    Il modulo salva la bozza, controlla i dati e prova subito l invio TS.
                  </span>
                <?php endif; ?>
              </div>
            </form>
          </div>

          <?php if ($documentId > 0): ?>
            <div class="box <?= $documentSent ? 'box-success' : 'box-info' ?>">
              <div class="box-header with-border">
                <h3 class="box-title">Esito invio TS</h3>
              </div>
              <div class="box-body">
                <div class="row">
                  <div class="col-md-3">
                    <strong>Stato locale</strong>
                    <div class="text-muted"><?= esc((string) ($document['local_state'] ?? 'draft')) ?></div>
                  </div>
                  <div class="col-md-3">
                    <strong>Stato TS</strong>
                    <div class="text-muted"><?= esc((string) ($document['ts_state'] ?? '-')) ?></div>
                  </div>
                  <div class="col-md-3">
                    <strong>Protocollo</strong>
                    <div class="text-muted"><?= esc((string) ($document['ts_protocol'] ?? '-')) ?></div>
                  </div>
                  <div class="col-md-3">
                    <strong>Ultimo invio</strong>
                    <div class="text-muted"><?= esc((string) ($document['ts_sent_at'] ?? '-')) ?></div>
                  </div>
                </div>
                <?php if (trim((string) ($supportLog['trace_id'] ?? '')) !== ''): ?>
                  <hr style="margin:16px 0;">
                  <strong>Riferimento supporto TS</strong>
                  <div class="text-muted">
                    <code><?= esc((string) ($supportLog['trace_id'] ?? '')) ?></code>
                    <a href="<?= site_url('admin/sistema-ts/diagnostica?trace=' . rawurlencode((string) ($supportLog['trace_id'] ?? '')) . '&document_id=' . (int) ($document['id_ts_document'] ?? 0)) ?>" style="margin-left:10px;">
                      Apri diagnostica
                    </a>
                  </div>
                <?php endif; ?>
                <?php if ($responseMessages !== []): ?>
                  <hr style="margin:16px 0;">
                  <strong>Messaggi restituiti da TS</strong>
                  <ul style="margin:8px 0 0 18px;">
                    <?php foreach ($responseMessages as $messageRow): ?>
                      <?php if (!is_array($messageRow)) { continue; } ?>
                      <?php
                        $rowCode = trim((string) ($messageRow['codice'] ?? ''));
                        $rowDescription = trim((string) ($messageRow['descrizione'] ?? ''));
                        $rowType = trim((string) ($messageRow['tipo'] ?? ''));
                      ?>
                      <li><?= esc(trim(($rowCode !== '' ? '[' . $rowCode . '] ' : '') . $rowDescription . ($rowType !== '' ? ' (' . $rowType . ')' : ''))) ?></li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
                <hr style="margin:16px 0;">
                <div>
                  <?php if ($canAttemptSend): ?>
                    <form method="post" action="<?= site_url('admin/sistema-ts/documenti/send') ?>" style="display:inline-block; margin:0 8px 8px 0;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id_ts_document" value="<?= $documentId ?>">
                      <button class="btn btn-primary" type="submit">
                        <i class="fa fa-refresh"></i> <?= $isCancellationDocument ? 'Invia cancellazione TS' : 'Invia di nuovo' ?>
                      </button>
                    </form>
                  <?php endif; ?>

                  <?php if ($canFetchReceipt && $latestReceiptId > 0): ?>
                    <a class="btn btn-success" href="<?= site_url('admin/sistema-ts/documenti/ricevuta/download/' . $latestReceiptId) ?>" style="margin:0 8px 8px 0;">
                      <i class="fa fa-file-pdf-o"></i> Scarica ricevuta PDF TS
                    </a>
                  <?php elseif ($canFetchReceipt): ?>
                    <form method="post" action="<?= site_url('admin/sistema-ts/documenti/ricevuta/download-latest/' . $documentId) ?>" style="display:inline-block; margin:0 8px 8px 0;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id_ts_document" value="<?= $documentId ?>">
                      <button class="btn btn-success" type="submit">
                        <i class="fa fa-download"></i> Scarica ricevuta PDF TS
                      </button>
                    </form>
                  <?php endif; ?>

                  <?php if ($documentSent): ?>
                    <?php if ($canCreateVariation): ?>
                      <form method="post" action="<?= site_url('admin/sistema-ts/documenti/variazione/' . $documentId) ?>" style="display:inline-block; margin:0 8px 8px 0;">
                        <?= csrf_field() ?>
                        <button class="btn btn-warning" type="submit">
                          <i class="fa fa-pencil-square-o"></i> Crea variazione TS
                        </button>
                      </form>
                    <?php endif; ?>
                    <?php if ($canCreateCancellation): ?>
                      <form method="post" action="<?= site_url('admin/sistema-ts/documenti/cancellazione/' . $documentId) ?>" style="display:inline-block; margin:0 8px 8px 0;" onsubmit="return confirm('Confermi la creazione e l invio della cancellazione TS per questo documento?');">
                        <?= csrf_field() ?>
                        <button class="btn btn-danger" type="submit">
                          <i class="fa fa-ban"></i> Annulla su TS
                        </button>
                      </form>
                    <?php endif; ?>
                    <a class="btn btn-default" href="<?= site_url('admin/sistema-ts/documenti/nuovo') ?>" style="margin:0 8px 8px 0;">
                      <i class="fa fa-plus"></i> Nuovo documento TS
                    </a>
                    <a class="btn btn-default" href="<?= site_url('admin/sistema-ts/documenti') ?>" style="margin:0 8px 8px 0;">
                      <i class="fa fa-list"></i> Torna alla lista
                    </a>
                  <?php endif; ?>
                </div>

                <?php if (!$documentSent && !$canAttemptSend && !$canFetchReceipt): ?>
                  <div class="text-muted" style="margin-top:4px;">
                    <?= $isBillingSourceDocument
                      ? 'Correggi la fattura origine e poi rilancia l invio da Sistema TS.'
                      : 'Correggi i campi del documento e usa Salva e invia per rilanciare il flusso completo.' ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <?php if ($relatedOperations !== []): ?>
              <div class="box box-default">
                <div class="box-header with-border">
                  <h3 class="box-title">Operazioni collegate</h3>
                </div>
                <div class="box-body">
                  <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                      <thead>
                        <tr>
                          <th>ID</th>
                          <th>Operazione</th>
                          <th>Stato locale</th>
                          <th>Stato TS</th>
                          <th>Protocollo</th>
                          <th>Aggiornato</th>
                          <th></th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($relatedOperations as $operation): ?>
                          <?php if (!is_array($operation)) { continue; } ?>
                          <tr>
                            <td>#<?= (int) ($operation['id_ts_document'] ?? 0) ?></td>
                            <td><?= esc((string) ($operation['source_type_label'] ?? 'Operazione TS')) ?></td>
                            <td><?= esc((string) ($operation['local_state'] ?? 'draft')) ?></td>
                            <td><?= esc((string) ($operation['ts_state'] ?? '')) ?></td>
                            <td><?= esc((string) ($operation['ts_protocol'] ?? '')) ?></td>
                            <td><?= esc((string) ($operation['updated_at'] ?? '')) ?></td>
                            <td>
                              <a class="btn btn-default btn-xs" href="<?= site_url('admin/sistema-ts/documenti/modifica/' . (int) ($operation['id_ts_document'] ?? 0)) ?>">
                                <i class="fa fa-folder-open-o"></i> Apri
                              </a>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <div class="box box-warning">
              <div class="box-header with-border">
                <h3 class="box-title">Archivio ricevute TS</h3>
              </div>
              <div class="box-body">
                <p class="text-muted" style="margin-bottom:12px;">
                  Qui trovi le ricevute gia salvate localmente per questo documento. Se manca ancora il PDF, usa il pulsante <strong>Scarica ricevuta PDF TS</strong> nell area esito qui sopra.
                </p>

                <?php if ($receipts === []): ?>
                  <div class="text-muted">Nessuna ricevuta TS archiviata per questo documento.</div>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                      <thead>
                        <tr>
                          <th>ID</th>
                          <th>Tipo</th>
                          <th>Protocollo</th>
                          <th>Creata il</th>
                          <th>Dimensione</th>
                          <th></th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($receipts as $receipt): ?>
                          <tr>
                            <td><?= (int) ($receipt['id_ts_receipt'] ?? 0) ?></td>
                            <td><?= esc((string) ($receipt['receipt_type'] ?? 'pdf')) ?></td>
                            <td><?= esc((string) ($receipt['ts_protocol'] ?? '')) ?></td>
                            <td><?= esc((string) ($receipt['created_at'] ?? '')) ?></td>
                            <td><?= esc(number_format(((int) ($receipt['file_size'] ?? 0)) / 1024, 1, ',', '.')) ?> KB</td>
                            <td>
                              <a class="btn btn-default btn-xs" href="<?= site_url('admin/sistema-ts/documenti/ricevuta/download/' . (int) ($receipt['id_ts_receipt'] ?? 0)) ?>">
                                <i class="fa fa-file-pdf-o"></i> Scarica
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
          <?php endif; ?>

          <?php if ($documentId > 0 && ($requestSnapshot !== [] || $responseSnapshot !== [])): ?>
            <div class="box box-default">
              <div class="box-header with-border">
                <h3 class="box-title">Diagnostica tecnica TS</h3>
              </div>
              <div class="box-body">
                <?php if ($requestSnapshot !== []): ?>
                  <h4 style="margin-top:0;">Ultimo request snapshot</h4>
                  <pre style="max-height:260px; overflow:auto; background:#f7f9fa; border:1px solid #e5ecee;"><?= esc(json_encode($requestSnapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                <?php endif; ?>

                <?php if ($responseSnapshot !== []): ?>
                  <h4>Ultimo response snapshot</h4>
                  <pre style="max-height:260px; overflow:auto; background:#f7f9fa; border:1px solid #e5ecee;"><?= esc(json_encode($responseSnapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($documentId > 0): ?>
            <div class="box box-default">
              <div class="box-header with-border">
                <h3 class="box-title">Timeline documento TS</h3>
              </div>
              <div class="box-body">
                <?php if ($events === []): ?>
                  <div class="text-muted">Nessun evento disponibile per questa bozza.</div>
                <?php else: ?>
                  <?php foreach ($events as $event): ?>
                    <?php $level = trim((string) ($event['event_level'] ?? 'info')); ?>
                    <div class="event-row is-<?= esc($level) ?>">
                      <strong><?= esc((string) ($event['event_type'] ?? 'evento')) ?></strong>
                      <div class="text-muted" style="margin-top:4px;"><?= esc((string) ($event['message'] ?? '')) ?></div>
                      <div class="text-muted" style="margin-top:6px; font-size:12px;">
                        <?= esc((string) ($event['created_at'] ?? '')) ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
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
    var expenseDetails = <?= $supportedExpenseDetailsJson ?>;

    function updateExpenseTypeHint() {
      var code = $.trim($('select[name="expense_type_code"]').val() || '');
      var detail = expenseDetails[code] || null;
      var message = 'I codici ammessi seguono il tracciato ufficiale TS.';

      if (detail) {
        message += ' ' + (detail.note || '');
        if ((detail.confidence || '') === 'provisional') {
          message = 'Descrizione UI prudente per il codice ' + code + '. ' + (detail.note || '');
        }
      }

      $('#ts-expense-type-help').text(message);
    }

    function updateTsVatHint() {
      var type = $.trim($('select[name="document_type"]').val() || 'F');
      var vatRate = $.trim($('input[name="vat_rate"]').val() || '');
      var vatNature = $.trim($('input[name="vat_nature"]').val() || '');
      var message = type === 'F'
        ? 'Per il tipo documento F compila una sola tra aliquota IVA e natura IVA.'
        : 'Per il tipo documento D i campi IVA sono facoltativi, ma se li compili lascia valorizzato un solo campo.';

      if (vatRate !== '' && vatNature !== '') {
        message = 'Hai compilato sia aliquota IVA sia natura IVA: il modulo ne accetta solo una.';
      }

      $('#ts-vat-hint').text(message);
    }

    $(document).on('change keyup', 'select[name="document_type"], input[name="vat_rate"], input[name="vat_nature"]', updateTsVatHint);
    $(document).on('change', 'select[name="expense_type_code"]', updateExpenseTypeHint);
    $(updateTsVatHint);
    $(updateExpenseTypeHint);
  })(jQuery);
</script>
</body>
</html>
