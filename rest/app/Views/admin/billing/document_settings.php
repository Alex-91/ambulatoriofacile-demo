<?php
helper(['form', 'portal']);

$menu_items = $menu_items ?? ((session()->get('menuDataAdmin')['result'] ?? []));
$tenantScope = is_array($tenantScope ?? null) ? $tenantScope : [];
$moduleStatus = is_array($moduleStatus ?? null) ? $moduleStatus : [];
$settings = is_array($settings ?? null) ? $settings : [];
$config = is_array($settings['config'] ?? null) ? $settings['config'] : [];
$branding = is_array($config['branding'] ?? null) ? $config['branding'] : [];
$layout = is_array($config['layout'] ?? null) ? $config['layout'] : [];
$fields = is_array($config['fields'] ?? null) ? $config['fields'] : [];
$labels = is_array($config['labels'] ?? null) ? $config['labels'] : [];
$integrationTs = is_array($config['integration_ts'] ?? null) ? $config['integration_ts'] : [];
$errors = is_array($errors ?? null) ? $errors : [];
$billingEnabled = !empty($moduleStatus['billing_enabled']);
$tsEnabled = !empty($moduleStatus['ts_enabled']);
$integratedEnabled = !empty($moduleStatus['integrated_enabled']);
$modeTitle = trim((string) ($moduleStatus['mode_title'] ?? 'Fatturazione'));
$modeMessage = trim((string) ($moduleStatus['mode_message'] ?? ''));
$preferenceRow = is_array($settings['preference_row'] ?? null) ? $settings['preference_row'] : [];
$logoUrl = trim((string) ($branding['logo_url'] ?? ''));
$accentColor = trim((string) ($branding['accent_color'] ?? '#2c8895'));
$enabledFieldCount = count(array_filter($fields, static function ($enabled): bool {
    return (bool) $enabled;
}));
$enabledLayoutCount = count(array_filter($layout, static function ($enabled): bool {
    return (bool) $enabled;
}));
$previewTitle = trim((string) ($branding['header_title'] ?? '')) !== ''
    ? trim((string) ($branding['header_title'] ?? ''))
    : trim((string) ($config['document_title'] ?? 'Documento fatturazione'));
$lastUpdatedAt = trim((string) ($preferenceRow['updated_at'] ?? ''));
$previewTenantName = trim((string) ($tenantScope['tenant_name'] ?? '')) !== ''
    ? trim((string) ($tenantScope['tenant_name'] ?? ''))
    : 'Studio attivo';
$previewGeneratedAt = date('d/m/Y H:i');
$previewSample = [
    'document_type_label' => 'Fattura sanitaria',
    'document_number' => trim((string) ($config['document_code_prefix'] ?? 'FT')) . '-2026-0017',
    'issue_date' => '06/07/2026',
    'patient_name' => 'Giulia Bianchi',
    'patient_tax_code' => 'BNCGLI90L41H501R',
    'payment_method_label' => 'Carta / POS',
    'payment_date' => '06/07/2026',
    'subtotal_amount' => '122,00',
    'stamp_duty_amount' => '2,00',
    'vat_rate' => '0',
    'vat_nature' => 'N4',
    'amount_total' => '124,00',
    'notes' => 'Prestazioni di esempio inserite solo per mostrare il layout finale del documento.',
    'terms' => 'Informativa di esempio con dati fittizi per valutare impaginazione, footer e contenuti opzionali.',
];
$previewSampleLineItems = [
    [
        'description' => 'Valutazione iniziale fisioterapica',
        'quantity' => '1',
        'unit_amount' => '70,00',
        'line_total' => '70,00',
    ],
    [
        'description' => 'Seduta di terapia manuale',
        'quantity' => '1',
        'unit_amount' => '32,00',
        'line_total' => '32,00',
    ],
    [
        'description' => 'Esercizi personalizzati e piano domiciliare',
        'quantity' => '1',
        'unit_amount' => '20,00',
        'line_total' => '20,00',
    ],
];

$oldValue = static function (string $key, string $default = ''): string {
    $old = old($key);
    if ($old !== null) {
        return trim((string) $old);
    }

    return trim((string) $default);
};

$oldChecked = static function (string $key, bool $default = false): bool {
    $old = old($key);
    if ($old !== null) {
        return in_array(strtolower(trim((string) $old)), ['1', 'true', 'on', 'yes'], true);
    }

    return $default;
};
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Documento fatturazione</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <style>
    .nav-pills.nav-stacked > li.active > a { background-color:#2c8895; color:#fff; }
    .hero-box { border:1px solid #dce8eb; border-radius:16px; padding:22px; background:linear-gradient(135deg, #fffdf7 0%, #f5efe0 52%, #edf7f8 100%); margin-bottom:16px; }
    .state-chip { display:inline-block; padding:5px 10px; border-radius:999px; background:#f4efe2; color:#7a5818; font-size:12px; font-weight:700; margin:0 8px 8px 0; }
    .summary-card { border:1px solid #e4ecef; border-radius:14px; background:#fff; padding:16px; min-height:130px; margin-bottom:16px; }
    .summary-card .value { font-size:26px; line-height:1.1; font-weight:700; color:#8a5b10; margin-bottom:6px; }
    .summary-card .label-top { color:#72868b; font-size:12px; text-transform:uppercase; letter-spacing:.05em; font-weight:700; }
    .summary-card .hint { color:#5d7176; line-height:1.5; }
    .tab-pane { padding-top:18px; }
    .option-box { border:1px solid #e9eef0; border-radius:12px; padding:14px 16px; margin-bottom:14px; background:#fcfdfd; }
    .preview-shell { border:1px solid #dde8eb; border-radius:18px; background:#fff; overflow:hidden; }
    .preview-shell .preview-head { padding:20px 22px; color:#fff; }
    .preview-shell .preview-body { padding:20px 22px; }
    .preview-chip { display:inline-block; margin:0 8px 8px 0; padding:5px 10px; border-radius:999px; background:#eef5f6; color:#225d64; font-size:12px; font-weight:700; }
    .checkbox-list label { display:block; margin-bottom:10px; font-weight:600; }
    .checkbox-list small { display:block; color:#6f8186; font-weight:400; line-height:1.45; margin-left:24px; }
    .editor-pane { padding-right:8px; }
    .preview-pane { padding-left:8px; }
    .live-preview-stage { position:sticky; top:18px; }
    .live-preview-intro { border:1px solid #dce6e9; border-radius:14px; background:#f9fcfc; padding:14px 16px; margin-bottom:14px; }
    .live-preview-canvas { border:1px solid #dbe5e8; border-radius:18px; background:#eef3f5; padding:16px; box-shadow:inset 0 1px 0 rgba(255,255,255,.45); }
    .live-preview-sheet { background:#fff; border-radius:18px; overflow:hidden; box-shadow:0 20px 45px rgba(28, 56, 63, .12); }
    .live-preview-header { padding:24px 26px; color:#fff; }
    .live-preview-header-grid { display:flex; justify-content:space-between; gap:20px; align-items:flex-start; }
    .live-preview-kicker { font-size:11px; letter-spacing:.08em; text-transform:uppercase; opacity:.8; font-weight:700; margin-bottom:8px; }
    .live-preview-title { font-size:26px; line-height:1.1; font-weight:700; margin-bottom:6px; }
    .live-preview-subtitle { font-size:14px; opacity:.92; line-height:1.45; }
    .live-preview-tenant { margin-top:10px; font-size:13px; opacity:.9; }
    .live-preview-logo-box { min-width:118px; min-height:78px; max-width:168px; border-radius:12px; padding:10px; background:rgba(255,255,255,.14); display:flex; align-items:center; justify-content:center; }
    .live-preview-logo-box img { max-width:100%; max-height:58px; display:block; }
    .live-preview-logo-placeholder { color:#fff; font-size:12px; letter-spacing:.08em; text-transform:uppercase; font-weight:700; opacity:.88; }
    .live-preview-body { padding:22px 26px; }
    .live-preview-row { display:flex; gap:14px; margin-bottom:14px; }
    .live-preview-box { flex:1 1 0; min-width:0; border:1px solid #dce5e8; border-radius:12px; padding:15px 16px; background:#fff; }
    .live-preview-label { color:#6c8086; font-size:11px; text-transform:uppercase; letter-spacing:.06em; font-weight:700; margin-bottom:5px; }
    .live-preview-value { font-size:14px; line-height:1.45; color:#22363d; }
    .live-preview-muted { color:#75888e; font-size:12px; line-height:1.5; }
    .live-preview-table { width:100%; border-collapse:collapse; }
    .live-preview-table th,
    .live-preview-table td { padding:9px 10px; border-bottom:1px solid #e6edef; text-align:left; font-size:13px; }
    .live-preview-table th { background:#f7fafb; font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:#61767d; }
    .live-preview-table td:last-child,
    .live-preview-table th:last-child { text-align:right; }
    .live-preview-totals { width:100%; border-collapse:collapse; }
    .live-preview-totals td { padding:5px 0; font-size:13px; }
    .live-preview-totals td:last-child { text-align:right; font-weight:700; }
    .live-preview-signature-line { height:54px; border-bottom:1px solid #ccd9de; margin-top:18px; }
    .live-preview-footer { padding:16px 26px 22px; color:#607278; font-size:11px; line-height:1.55; border-top:1px solid #edf2f4; background:#fbfcfc; }
    .live-preview-tags { margin-top:12px; }
    .live-preview-tags .preview-chip { margin-bottom:0; }
    @media (max-width: 991px) {
      .editor-pane { padding-right:15px; }
      .preview-pane { padding-left:15px; margin-top:18px; }
      .live-preview-stage { position:static; }
      .live-preview-row { display:block; margin-bottom:0; }
      .live-preview-box { margin-bottom:14px; }
      .live-preview-header-grid { display:block; }
      .live-preview-logo-box { margin-top:14px; }
    }
  </style>
  <link href="<?= base_url('public/assets/css/billing-ts-ui.css') ?>" rel="stylesheet" />
</head>
<body class="skin-blue sidebar-mini billing-ts-ui module-fatturazione">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Documento fatturazione</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Configura il modello base del documento cliente: campi, logo, blocchi grafici e comportamento quando il Sistema TS e attivo.
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

          <div class="hero-box">
            <h3 style="margin-top:0; margin-bottom:8px;">
              Spazio: <?= esc((string) ($tenantScope['tenant_name'] ?? 'attivo')) ?>
            </h3>
            <p style="margin:0 0 12px 0; color:#556b70;">
              <?= esc($modeMessage) ?>
            </p>
            <span class="state-chip">Fatturazione: <?= $billingEnabled ? 'attiva' : 'spenta' ?></span>
            <span class="state-chip">Sistema TS: <?= $tsEnabled ? 'attivo' : 'spento' ?></span>
            <span class="state-chip">Modalita: <?= esc($modeTitle) ?></span>
            <?php if ($lastUpdatedAt !== ''): ?>
              <span class="state-chip">Ultimo salvataggio: <?= esc($lastUpdatedAt) ?></span>
            <?php endif; ?>
            <div style="margin-top:12px;">
              <a class="btn btn-default" href="<?= site_url('admin/fatturazione') ?>">
                <i class="fa fa-arrow-left"></i> Torna alla dashboard fatturazione
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

          <div class="row">
            <div class="col-md-4">
              <div class="summary-card">
                <div class="label-top">Titolo documento</div>
                <div class="value" id="summary-document-title"><?= esc(trim((string) ($config['document_title'] ?? 'Documento fatturazione'))) ?></div>
                <div class="hint">Nome base del documento mostrato in testata e nel flusso operativo.</div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="summary-card">
                <div class="label-top">Campi attivi</div>
                <div class="value" id="summary-field-count"><?= $enabledFieldCount ?></div>
                <div class="hint">Puoi accendere o spegnere i campi che devono comparire sul documento consegnato al cliente.</div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="summary-card">
                <div class="label-top">Blocchi layout</div>
                <div class="value" id="summary-layout-count"><?= $enabledLayoutCount ?></div>
                <div class="hint">Header, footer, box pagamento e sezioni opzionali restano indipendenti dal Sistema TS.</div>
              </div>
            </div>
          </div>

          <div class="alert alert-info" style="margin-bottom:16px;">
            Questa schermata prepara il <strong>modello del documento</strong>, non l invio TS. L anteprima qui sotto usa dati fittizi e si aggiorna in tempo reale mentre cambi layout, campi, logo e personalizzazioni.
          </div>

          <?php if (!$tsEnabled): ?>
            <div class="alert alert-warning" style="margin-bottom:16px;">
              Il Sistema TS in questo spazio e spento. Puoi comunque preconfigurare le regole di integrazione: entreranno in gioco solo quando il modulo verra attivato.
            </div>
          <?php endif; ?>

          <form method="post" action="<?= site_url('admin/fatturazione-documento/save') ?>">
            <?= csrf_field() ?>

            <div class="box box-default">
              <div class="box-header with-border">
                <h3 class="box-title">Personalizzazione documento</h3>
              </div>
              <div class="box-body">
                <div class="row">
                  <div class="col-md-7 editor-pane">
                <ul class="nav nav-tabs" role="tablist">
                  <li role="presentation" class="active"><a href="#tab-layout" aria-controls="tab-layout" role="tab" data-toggle="tab">Layout</a></li>
                  <li role="presentation"><a href="#tab-fields" aria-controls="tab-fields" role="tab" data-toggle="tab">Campi</a></li>
                  <li role="presentation"><a href="#tab-branding" aria-controls="tab-branding" role="tab" data-toggle="tab">Branding</a></li>
                  <li role="presentation"><a href="#tab-ts" aria-controls="tab-ts" role="tab" data-toggle="tab">Integrazione TS</a></li>
                </ul>

                <div class="tab-content">
                  <div role="tabpanel" class="tab-pane active" id="tab-layout">
                    <div class="row">
                      <div class="col-md-6">
                        <div class="form-group">
                          <label>Titolo documento</label>
                          <input class="form-control" type="text" name="document_title" maxlength="120" value="<?= esc($oldValue('document_title', (string) ($config['document_title'] ?? 'Documento fatturazione'))) ?>">
                          <small class="text-muted">Titolo principale usato dal modulo Fatturazione per il documento cliente.</small>
                        </div>
                      </div>
                      <div class="col-md-3">
                        <div class="form-group">
                          <label>Prefisso numerazione</label>
                          <input class="form-control" type="text" name="document_code_prefix" maxlength="12" value="<?= esc($oldValue('document_code_prefix', (string) ($config['document_code_prefix'] ?? 'FT'))) ?>">
                          <small class="text-muted">Esempio: FT, RIC, DOC.</small>
                        </div>
                      </div>
                      <div class="col-md-3">
                        <div class="form-group">
                          <label>Colore accento</label>
                          <input class="form-control" type="color" name="branding_accent_color" value="<?= esc($oldValue('branding_accent_color', $accentColor)) ?>">
                        </div>
                      </div>
                    </div>

                    <div class="option-box checkbox-list">
                      <label><input type="checkbox" name="layout_show_header" value="1" <?= $oldChecked('layout_show_header', !empty($layout['show_header'])) ? 'checked' : '' ?>> Mostra header documento</label>
                      <small>Accende la parte alta del documento con titolo, studio e informazioni iniziali.</small>

                      <label><input type="checkbox" name="layout_show_footer" value="1" <?= $oldChecked('layout_show_footer', !empty($layout['show_footer'])) ? 'checked' : '' ?>> Mostra footer documento</label>
                      <small>Usa una chiusura in fondo alla pagina con nota finale o riferimenti dello studio.</small>

                      <label><input type="checkbox" name="layout_show_logo" value="1" <?= $oldChecked('layout_show_logo', !empty($layout['show_logo'])) ? 'checked' : '' ?>> Mostra logo nello header</label>
                      <small>Il logo appare solo se sotto viene impostato un path o URL valido.</small>

                      <label><input type="checkbox" name="layout_show_patient_box" value="1" <?= $oldChecked('layout_show_patient_box', !empty($layout['show_patient_box'])) ? 'checked' : '' ?>> Mostra box dati paziente</label>
                      <small>Sezione dedicata all intestazione cliente o paziente.</small>

                      <label><input type="checkbox" name="layout_show_payment_box" value="1" <?= $oldChecked('layout_show_payment_box', !empty($layout['show_payment_box'])) ? 'checked' : '' ?>> Mostra box pagamento</label>
                      <small>Raccoglie metodo, data e riepilogo del pagamento in un blocco separato.</small>

                      <label><input type="checkbox" name="layout_show_signature_box" value="1" <?= $oldChecked('layout_show_signature_box', !empty($layout['show_signature_box'])) ? 'checked' : '' ?>> Mostra box firma</label>
                      <small>Utile se vuoi lasciare spazio a una firma manuale o digitale.</small>

                      <label><input type="checkbox" name="layout_show_terms_box" value="1" <?= $oldChecked('layout_show_terms_box', !empty($layout['show_terms_box'])) ? 'checked' : '' ?>> Mostra box informativa</label>
                      <small>Permette di aggiungere una sezione finale con note privacy o condizioni.</small>
                    </div>
                  </div>

                  <div role="tabpanel" class="tab-pane" id="tab-fields">
                    <div class="row">
                      <div class="col-md-6">
                        <div class="option-box checkbox-list">
                          <label><input type="checkbox" name="field_show_document_number" value="1" <?= $oldChecked('field_show_document_number', !empty($fields['show_document_number'])) ? 'checked' : '' ?>> Numero documento</label>
                          <small>Mostra il numero progressivo della fattura o ricevuta.</small>

                          <label><input type="checkbox" name="field_show_issue_date" value="1" <?= $oldChecked('field_show_issue_date', !empty($fields['show_issue_date'])) ? 'checked' : '' ?>> Data emissione</label>
                          <small>Visualizza la data ufficiale del documento.</small>

                          <label><input type="checkbox" name="field_show_patient_name" value="1" <?= $oldChecked('field_show_patient_name', !empty($fields['show_patient_name'])) ? 'checked' : '' ?>> Nome paziente o cliente</label>
                          <small>Campo base per identificare il destinatario del documento.</small>

                          <label><input type="checkbox" name="field_show_patient_tax_code" value="1" <?= $oldChecked('field_show_patient_tax_code', !empty($fields['show_patient_tax_code'])) ? 'checked' : '' ?>> Codice fiscale paziente</label>
                          <small>Puoi tenerlo visibile sul documento anche se il TS non e attivo.</small>

                          <label><input type="checkbox" name="field_show_notes" value="1" <?= $oldChecked('field_show_notes', !empty($fields['show_notes'])) ? 'checked' : '' ?>> Campo note</label>
                          <small>Mostra eventuali annotazioni operative o descrittive.</small>
                        </div>
                      </div>
                      <div class="col-md-6">
                        <div class="option-box checkbox-list">
                          <label><input type="checkbox" name="field_show_payment_date" value="1" <?= $oldChecked('field_show_payment_date', !empty($fields['show_payment_date'])) ? 'checked' : '' ?>> Data pagamento</label>
                          <small>Separata dalla data emissione quando serve uno storico piu preciso.</small>

                          <label><input type="checkbox" name="field_show_payment_method" value="1" <?= $oldChecked('field_show_payment_method', !empty($fields['show_payment_method'])) ? 'checked' : '' ?>> Metodo pagamento</label>
                          <small>Contanti, POS, bonifico o altro canale scelto dallo studio.</small>

                          <label><input type="checkbox" name="field_show_line_items" value="1" <?= $oldChecked('field_show_line_items', !empty($fields['show_line_items'])) ? 'checked' : '' ?>> Righe prestazioni</label>
                          <small>Dettaglia le prestazioni o le voci che compongono il documento.</small>

                          <label><input type="checkbox" name="field_show_vat_summary" value="1" <?= $oldChecked('field_show_vat_summary', !empty($fields['show_vat_summary'])) ? 'checked' : '' ?>> Riepilogo IVA</label>
                          <small>Mostra il totale imponibile e la sintesi IVA quando necessario.</small>

                          <label><input type="checkbox" name="field_show_stamp_duty" value="1" <?= $oldChecked('field_show_stamp_duty', !empty($fields['show_stamp_duty'])) ? 'checked' : '' ?>> Marca da bollo</label>
                          <small>Lascia il posto per riportare l eventuale bollo sul documento.</small>
                        </div>
                      </div>
                    </div>

                    <div class="row">
                      <div class="col-md-6">
                        <div class="form-group">
                          <label>Titolo sezione paziente</label>
                          <input class="form-control" type="text" name="label_patient_section_title" maxlength="80" value="<?= esc($oldValue('label_patient_section_title', (string) ($labels['patient_section_title'] ?? 'Dati paziente'))) ?>">
                        </div>
                      </div>
                      <div class="col-md-6">
                        <div class="form-group">
                          <label>Titolo sezione pagamento</label>
                          <input class="form-control" type="text" name="label_payment_section_title" maxlength="80" value="<?= esc($oldValue('label_payment_section_title', (string) ($labels['payment_section_title'] ?? 'Pagamento'))) ?>">
                        </div>
                      </div>
                    </div>
                    <div class="row">
                      <div class="col-md-4">
                        <div class="form-group">
                          <label>Etichetta note</label>
                          <input class="form-control" type="text" name="label_notes_label" maxlength="80" value="<?= esc($oldValue('label_notes_label', (string) ($labels['notes_label'] ?? 'Note'))) ?>">
                        </div>
                      </div>
                      <div class="col-md-4">
                        <div class="form-group">
                          <label>Etichetta firma</label>
                          <input class="form-control" type="text" name="label_signature_label" maxlength="80" value="<?= esc($oldValue('label_signature_label', (string) ($labels['signature_label'] ?? 'Firma'))) ?>">
                        </div>
                      </div>
                      <div class="col-md-4">
                        <div class="form-group">
                          <label>Etichetta informativa</label>
                          <input class="form-control" type="text" name="label_terms_label" maxlength="80" value="<?= esc($oldValue('label_terms_label', (string) ($labels['terms_label'] ?? 'Informativa'))) ?>">
                        </div>
                      </div>
                    </div>
                  </div>

                  <div role="tabpanel" class="tab-pane" id="tab-branding">
                    <div class="row">
                      <div class="col-md-4">
                        <div class="form-group">
                          <label>Logo</label>
                          <?php $logoModeValue = $oldValue('branding_logo_mode', (string) ($branding['logo_mode'] ?? 'none')); ?>
                          <select class="form-control" name="branding_logo_mode">
                            <option value="none" <?= $logoModeValue === 'none' ? 'selected' : '' ?>>Nessun logo</option>
                            <option value="path" <?= $logoModeValue === 'path' ? 'selected' : '' ?>>Usa URL o path logo</option>
                          </select>
                        </div>
                      </div>
                      <div class="col-md-8">
                        <div class="form-group">
                          <label>URL o path logo</label>
                          <input class="form-control" type="text" name="branding_logo_url" maxlength="255" value="<?= esc($oldValue('branding_logo_url', $logoUrl)) ?>" placeholder="Es. /upload/logo-studio.png oppure https://...">
                          <small class="text-muted">Puoi agganciare un file gia presente negli upload oppure un URL esterno.</small>
                        </div>
                      </div>
                    </div>

                    <div class="row">
                      <div class="col-md-6">
                        <div class="form-group">
                          <label>Titolo header</label>
                          <input class="form-control" type="text" name="branding_header_title" maxlength="120" value="<?= esc($oldValue('branding_header_title', (string) ($branding['header_title'] ?? ''))) ?>" placeholder="Es. Studio Medico Rossi">
                        </div>
                      </div>
                      <div class="col-md-6">
                        <div class="form-group">
                          <label>Sottotitolo header</label>
                          <input class="form-control" type="text" name="branding_header_subtitle" maxlength="180" value="<?= esc($oldValue('branding_header_subtitle', (string) ($branding['header_subtitle'] ?? ''))) ?>" placeholder="Es. Prestazioni sanitarie e consulenze">
                        </div>
                      </div>
                    </div>

                    <div class="form-group">
                      <label>Nota footer</label>
                      <textarea class="form-control" name="branding_footer_note" rows="3" maxlength="255" placeholder="Messaggio finale, coordinate o informativa sintetica"><?= esc($oldValue('branding_footer_note', (string) ($branding['footer_note'] ?? ''))) ?></textarea>
                    </div>
                  </div>

                  <div role="tabpanel" class="tab-pane" id="tab-ts">
                    <div class="option-box checkbox-list">
                      <label><input type="checkbox" name="ts_enabled_when_available" value="1" <?= $oldChecked('ts_enabled_when_available', !empty($integrationTs['enabled_when_available'])) ? 'checked' : '' ?>> Attiva integrazione quando il Sistema TS e disponibile</label>
                      <small>Lascia il documento fatturazione autonomo, ma pronto a generare dati collegabili al TS appena il modulo viene acceso.</small>

                      <label><input type="checkbox" name="ts_show_ts_reference" value="1" <?= $oldChecked('ts_show_ts_reference', !empty($integrationTs['show_ts_reference'])) ? 'checked' : '' ?>> Mostra riferimenti TS sul documento cliente</label>
                      <small>Utile quando vuoi riportare protocollo o collegamento al record inviato al TS.</small>

                      <label><input type="checkbox" name="ts_require_expense_type" value="1" <?= $oldChecked('ts_require_expense_type', !empty($integrationTs['require_expense_type'])) ? 'checked' : '' ?>> Richiedi tipo spesa in caso di aggancio TS</label>
                      <small>Forza una classificazione spesa prima di consentire il collegamento al Sistema TS.</small>

                      <label><input type="checkbox" name="ts_require_opposition_flag" value="1" <?= $oldChecked('ts_require_opposition_flag', !empty($integrationTs['require_opposition_flag'])) ? 'checked' : '' ?>> Richiedi gestione opposizione privacy</label>
                      <small>Prepara il documento cliente a gestire il flag opposizione quando il flusso viene inviato o collegato al TS.</small>
                    </div>

                    <div class="alert alert-info" style="margin-bottom:0;">
                      <?= $tsEnabled
                          ? 'Il modulo Sistema TS e attivo: queste regole possono convivere subito con i documenti TS mantenendo separati i due percorsi.'
                          : 'Il modulo Sistema TS non e attivo: le impostazioni salvate qui restano in standby finche non verra abilitato.' ?>
                    </div>
                  </div>
                </div>
                  </div>
                  <div class="col-md-5 preview-pane">
                    <div class="live-preview-stage">
                      <div class="live-preview-intro">
                        <div style="font-size:12px; text-transform:uppercase; letter-spacing:.08em; color:#6a7f85; font-weight:700; margin-bottom:6px;">Anteprima realtime</div>
                        <div style="font-size:18px; font-weight:700; color:#244149; margin-bottom:6px;">Documento con dati fittizi</div>
                        <div class="text-muted" style="line-height:1.55;">
                          Mentre cambi campi, blocchi, logo e note, qui vedi subito come apparira il documento finale consegnato al cliente.
                        </div>
                        <div class="live-preview-tags">
                          <span class="preview-chip" id="live-preview-prefix-chip">Prefisso <?= esc(trim((string) ($config['document_code_prefix'] ?? 'FT'))) ?></span>
                          <span class="preview-chip" id="live-preview-mode-chip"><?= $integratedEnabled ? 'Convivenza TS pronta' : 'Standalone' ?></span>
                        </div>
                      </div>

                      <div class="live-preview-canvas">
                        <div class="live-preview-sheet">
                          <div class="live-preview-header" id="live-preview-header" style="background:<?= esc($accentColor) ?>;<?= $oldChecked('layout_show_header', !empty($layout['show_header'])) ? '' : ' display:none;' ?>">
                            <div class="live-preview-header-grid">
                              <div>
                                <div class="live-preview-kicker">Anteprima con dati fittizi</div>
                                <div class="live-preview-title" id="live-preview-title"><?= esc($previewTitle) ?></div>
                                <div class="live-preview-subtitle" id="live-preview-subtitle"><?= esc(trim((string) ($branding['header_subtitle'] ?? 'Documento cliente personalizzabile per lo studio'))) ?></div>
                                <div class="live-preview-tenant" id="live-preview-tenant"><?= esc($previewTenantName) ?></div>
                              </div>
                              <div class="live-preview-logo-box" id="live-preview-logo-box"<?= (!empty($layout['show_logo']) && $oldValue('branding_logo_mode', (string) ($branding['logo_mode'] ?? 'none')) !== 'none') ? '' : ' style="display:none;"' ?>>
                                <img id="live-preview-logo-image" alt="Logo studio" style="display:none;">
                                <div class="live-preview-logo-placeholder" id="live-preview-logo-placeholder"><?= $logoUrl !== '' ? 'Logo studio' : 'Logo' ?></div>
                              </div>
                            </div>
                          </div>

                          <div class="live-preview-body">
                            <div class="live-preview-row">
                              <div class="live-preview-box">
                                <div class="live-preview-label">Documento</div>
                                <div class="live-preview-value">
                                  <?= esc($previewSample['document_type_label']) ?>
                                  <span id="live-preview-document-number-wrap"<?= $oldChecked('field_show_document_number', !empty($fields['show_document_number'])) ? '' : ' style="display:none;"' ?>>
                                    n. <span id="live-preview-document-number"><?= esc($previewSample['document_number']) ?></span>
                                  </span>
                                </div>
                                <div id="live-preview-issue-date-wrap" style="margin-top:8px;<?= $oldChecked('field_show_issue_date', !empty($fields['show_issue_date'])) ? '' : ' display:none;' ?>">
                                  <div class="live-preview-label">Data emissione</div>
                                  <div class="live-preview-value"><?= esc($previewSample['issue_date']) ?></div>
                                </div>
                              </div>

                              <div class="live-preview-box" id="live-preview-patient-box"<?= $oldChecked('layout_show_patient_box', !empty($layout['show_patient_box'])) ? '' : ' style="display:none;"' ?>>
                                <div class="live-preview-label" id="live-preview-patient-title"><?= esc((string) ($labels['patient_section_title'] ?? 'Dati paziente')) ?></div>
                                <div id="live-preview-patient-name-wrap"<?= $oldChecked('field_show_patient_name', !empty($fields['show_patient_name'])) ? '' : ' style="display:none;"' ?>>
                                  <div class="live-preview-value"><?= esc($previewSample['patient_name']) ?></div>
                                </div>
                                <div id="live-preview-patient-tax-wrap" style="margin-top:8px;<?= $oldChecked('field_show_patient_tax_code', !empty($fields['show_patient_tax_code'])) ? '' : ' display:none;' ?>">
                                  <div class="live-preview-label">Codice fiscale</div>
                                  <div class="live-preview-value"><?= esc($previewSample['patient_tax_code']) ?></div>
                                </div>
                                <div class="live-preview-muted" id="live-preview-patient-empty" style="display:none; margin-top:4px;">Nessun dato paziente visibile in questo layout.</div>
                              </div>
                            </div>

                            <div class="live-preview-box" id="live-preview-line-items-box"<?= $oldChecked('field_show_line_items', !empty($fields['show_line_items'])) ? '' : ' style="display:none;"' ?>>
                              <table class="live-preview-table">
                                <thead>
                                  <tr>
                                    <th>Descrizione</th>
                                    <th style="width:70px;">Qta</th>
                                    <th style="width:110px;">Prezzo</th>
                                    <th style="width:110px;">Totale</th>
                                  </tr>
                                </thead>
                                <tbody>
                                  <?php foreach ($previewSampleLineItems as $sampleLineItem): ?>
                                    <tr>
                                      <td><?= esc((string) $sampleLineItem['description']) ?></td>
                                      <td><?= esc((string) $sampleLineItem['quantity']) ?></td>
                                      <td>EUR <?= esc((string) $sampleLineItem['unit_amount']) ?></td>
                                      <td>EUR <?= esc((string) $sampleLineItem['line_total']) ?></td>
                                    </tr>
                                  <?php endforeach; ?>
                                </tbody>
                              </table>
                            </div>

                            <div class="live-preview-row">
                              <div class="live-preview-box" id="live-preview-payment-box"<?= $oldChecked('layout_show_payment_box', !empty($layout['show_payment_box'])) ? '' : ' style="display:none;"' ?>>
                                <div class="live-preview-label" id="live-preview-payment-title"><?= esc((string) ($labels['payment_section_title'] ?? 'Pagamento')) ?></div>
                                <div id="live-preview-payment-method-wrap"<?= $oldChecked('field_show_payment_method', !empty($fields['show_payment_method'])) ? '' : ' style="display:none;"' ?>>
                                  <div class="live-preview-value"><?= esc($previewSample['payment_method_label']) ?></div>
                                </div>
                                <div id="live-preview-payment-date-wrap" style="margin-top:8px;<?= $oldChecked('field_show_payment_date', !empty($fields['show_payment_date'])) ? '' : ' display:none;' ?>">
                                  <div class="live-preview-label">Data pagamento</div>
                                  <div class="live-preview-value"><?= esc($previewSample['payment_date']) ?></div>
                                </div>
                                <div class="live-preview-muted" id="live-preview-payment-empty" style="display:none; margin-top:4px;">Nessun dettaglio pagamento visibile in questo layout.</div>
                              </div>

                              <div class="live-preview-box">
                                <table class="live-preview-totals">
                                  <tr>
                                    <td>Subtotale</td>
                                    <td id="live-preview-subtotal">EUR <?= esc($previewSample['subtotal_amount']) ?></td>
                                  </tr>
                                  <tr id="live-preview-stamp-row"<?= $oldChecked('field_show_stamp_duty', !empty($fields['show_stamp_duty'])) ? '' : ' style="display:none;"' ?>>
                                    <td>Marca da bollo</td>
                                    <td id="live-preview-stamp-duty">EUR <?= esc($previewSample['stamp_duty_amount']) ?></td>
                                  </tr>
                                  <tr id="live-preview-vat-row"<?= $oldChecked('field_show_vat_summary', !empty($fields['show_vat_summary'])) ? '' : ' style="display:none;"' ?>>
                                    <td>IVA/Natura</td>
                                    <td><span id="live-preview-vat-rate"><?= esc($previewSample['vat_rate']) ?></span>% / <span id="live-preview-vat-nature"><?= esc($previewSample['vat_nature']) ?></span></td>
                                  </tr>
                                  <tr>
                                    <td>Totale</td>
                                    <td id="live-preview-total">EUR <?= esc($previewSample['amount_total']) ?></td>
                                  </tr>
                                </table>
                              </div>
                            </div>

                            <div class="live-preview-box" id="live-preview-notes-box"<?= $oldChecked('field_show_notes', !empty($fields['show_notes'])) ? '' : ' style="display:none;"' ?>>
                              <div class="live-preview-label" id="live-preview-notes-title"><?= esc((string) ($labels['notes_label'] ?? 'Note')) ?></div>
                              <div class="live-preview-value" id="live-preview-notes-content"><?= esc($previewSample['notes']) ?></div>
                            </div>

                            <div class="live-preview-box" id="live-preview-signature-box"<?= $oldChecked('layout_show_signature_box', !empty($layout['show_signature_box'])) ? '' : ' style="display:none;"' ?>>
                              <div class="live-preview-label" id="live-preview-signature-title"><?= esc((string) ($labels['signature_label'] ?? 'Firma')) ?></div>
                              <div class="live-preview-signature-line"></div>
                            </div>

                            <div class="live-preview-box" id="live-preview-terms-box"<?= $oldChecked('layout_show_terms_box', !empty($layout['show_terms_box'])) ? '' : ' style="display:none;"' ?>>
                              <div class="live-preview-label" id="live-preview-terms-title"><?= esc((string) ($labels['terms_label'] ?? 'Informativa')) ?></div>
                              <div class="live-preview-value" id="live-preview-terms-content"><?= esc($previewSample['terms']) ?></div>
                            </div>
                          </div>

                          <div class="live-preview-footer" id="live-preview-footer"<?= $oldChecked('layout_show_footer', !empty($layout['show_footer'])) ? '' : ' style="display:none;"' ?>>
                            <span id="live-preview-footer-content"><?= esc(trim((string) ($branding['footer_note'] ?? '')) !== '' ? trim((string) ($branding['footer_note'] ?? '')) : 'Documento di esempio generato il ' . $previewGeneratedAt . '.') ?></span>
                          </div>
                        </div>
                      </div>

                      <div class="live-preview-tags" style="margin-top:14px;">
                        <span class="preview-chip" id="live-preview-ts-chip"<?= $oldChecked('ts_enabled_when_available', !empty($integrationTs['enabled_when_available'])) ? '' : ' style="display:none;"' ?>>Integrazione TS pronta</span>
                        <span class="preview-chip" id="live-preview-ts-ref-chip"<?= $oldChecked('ts_show_ts_reference', !empty($integrationTs['show_ts_reference'])) ? '' : ' style="display:none;"' ?>>Riferimento TS visibile</span>
                        <span class="preview-chip" id="live-preview-ts-expense-chip"<?= $oldChecked('ts_require_expense_type', !empty($integrationTs['require_expense_type'])) ? '' : ' style="display:none;"' ?>>Tipo spesa richiesto</span>
                        <span class="preview-chip" id="live-preview-ts-privacy-chip"<?= $oldChecked('ts_require_opposition_flag', !empty($integrationTs['require_opposition_flag'])) ? '' : ' style="display:none;"' ?>>Opposizione privacy richiesta</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="box-footer">
                <button class="btn btn-primary" type="submit">
                  <i class="fa fa-save"></i> Salva impostazioni documento
                </button>
                <a class="btn btn-default" href="<?= site_url('admin/fatturazione') ?>" style="margin-left:8px;">
                  Annulla
                </a>
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
document.addEventListener('DOMContentLoaded', function () {
  var form = document.querySelector('form[action$="fatturazione-documento/save"]');
  if (!form) {
    return;
  }

  var previewData = <?= json_encode([
      'tenant_name' => $previewTenantName,
      'generated_at' => $previewGeneratedAt,
      'document_number_suffix' => '2026-0017',
      'notes' => $previewSample['notes'],
      'terms' => $previewSample['terms'],
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var baseUrl = <?= json_encode(rtrim(base_url(), '/'), JSON_UNESCAPED_SLASHES) ?>;
  var fieldToggleNames = [
    'field_show_document_number',
    'field_show_issue_date',
    'field_show_patient_name',
    'field_show_patient_tax_code',
    'field_show_payment_date',
    'field_show_payment_method',
    'field_show_line_items',
    'field_show_vat_summary',
    'field_show_stamp_duty',
    'field_show_notes'
  ];
  var layoutToggleNames = [
    'layout_show_header',
    'layout_show_footer',
    'layout_show_logo',
    'layout_show_patient_box',
    'layout_show_payment_box',
    'layout_show_signature_box',
    'layout_show_terms_box'
  ];

  var summaryDocumentTitle = document.getElementById('summary-document-title');
  var summaryFieldCount = document.getElementById('summary-field-count');
  var summaryLayoutCount = document.getElementById('summary-layout-count');
  var previewHeader = document.getElementById('live-preview-header');
  var previewTitle = document.getElementById('live-preview-title');
  var previewSubtitle = document.getElementById('live-preview-subtitle');
  var previewTenant = document.getElementById('live-preview-tenant');
  var previewPrefixChip = document.getElementById('live-preview-prefix-chip');
  var previewModeChip = document.getElementById('live-preview-mode-chip');
  var previewLogoBox = document.getElementById('live-preview-logo-box');
  var previewLogoImage = document.getElementById('live-preview-logo-image');
  var previewLogoPlaceholder = document.getElementById('live-preview-logo-placeholder');
  var previewDocumentNumber = document.getElementById('live-preview-document-number');
  var previewDocumentNumberWrap = document.getElementById('live-preview-document-number-wrap');
  var previewIssueDateWrap = document.getElementById('live-preview-issue-date-wrap');
  var previewPatientBox = document.getElementById('live-preview-patient-box');
  var previewPatientTitle = document.getElementById('live-preview-patient-title');
  var previewPatientNameWrap = document.getElementById('live-preview-patient-name-wrap');
  var previewPatientTaxWrap = document.getElementById('live-preview-patient-tax-wrap');
  var previewPatientEmpty = document.getElementById('live-preview-patient-empty');
  var previewLineItemsBox = document.getElementById('live-preview-line-items-box');
  var previewPaymentBox = document.getElementById('live-preview-payment-box');
  var previewPaymentTitle = document.getElementById('live-preview-payment-title');
  var previewPaymentMethodWrap = document.getElementById('live-preview-payment-method-wrap');
  var previewPaymentDateWrap = document.getElementById('live-preview-payment-date-wrap');
  var previewPaymentEmpty = document.getElementById('live-preview-payment-empty');
  var previewStampRow = document.getElementById('live-preview-stamp-row');
  var previewVatRow = document.getElementById('live-preview-vat-row');
  var previewNotesBox = document.getElementById('live-preview-notes-box');
  var previewNotesTitle = document.getElementById('live-preview-notes-title');
  var previewNotesContent = document.getElementById('live-preview-notes-content');
  var previewSignatureBox = document.getElementById('live-preview-signature-box');
  var previewSignatureTitle = document.getElementById('live-preview-signature-title');
  var previewTermsBox = document.getElementById('live-preview-terms-box');
  var previewTermsTitle = document.getElementById('live-preview-terms-title');
  var previewTermsContent = document.getElementById('live-preview-terms-content');
  var previewFooter = document.getElementById('live-preview-footer');
  var previewFooterContent = document.getElementById('live-preview-footer-content');
  var previewTsChip = document.getElementById('live-preview-ts-chip');
  var previewTsRefChip = document.getElementById('live-preview-ts-ref-chip');
  var previewTsExpenseChip = document.getElementById('live-preview-ts-expense-chip');
  var previewTsPrivacyChip = document.getElementById('live-preview-ts-privacy-chip');

  function getInput(name) {
    return form.querySelector('[name="' + name + '"]');
  }

  function getValue(name, fallback) {
    var input = getInput(name);
    if (!input) {
      return fallback || '';
    }

    return String(input.value || fallback || '').trim();
  }

  function isChecked(name) {
    var input = getInput(name);
    return !!(input && input.checked);
  }

  function setVisible(element, visible, displayMode) {
    if (!element) {
      return;
    }

    element.style.display = visible ? (displayMode || '') : 'none';
  }

  function setText(element, text) {
    if (element) {
      element.textContent = text;
    }
  }

  function escapeHtml(text) {
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function nl2br(text) {
    return escapeHtml(text).replace(/\n/g, '<br>');
  }

  function countEnabled(names) {
    return names.reduce(function (total, name) {
      return total + (isChecked(name) ? 1 : 0);
    }, 0);
  }

  function resolveLogoUrl(rawUrl) {
    var url = String(rawUrl || '').trim();
    if (url === '') {
      return '';
    }

    if (/^https?:\/\//i.test(url)) {
      return url;
    }

    if (url.charAt(0) === '/') {
      return baseUrl + url;
    }

    return baseUrl + '/' + url.replace(/^\/+/, '');
  }

  previewLogoImage.addEventListener('load', function () {
    if (previewLogoImage.getAttribute('src')) {
      previewLogoImage.style.display = 'block';
      previewLogoPlaceholder.style.display = 'none';
    }
  });

  previewLogoImage.addEventListener('error', function () {
    previewLogoImage.style.display = 'none';
    previewLogoPlaceholder.style.display = 'block';
    previewLogoPlaceholder.textContent = 'Logo non trovato';
  });

  function syncLogo(showLogo, logoMode, rawLogoUrl) {
    var logoActive = showLogo && logoMode !== 'none';
    setVisible(previewLogoBox, logoActive, 'flex');

    if (!logoActive) {
      previewLogoImage.removeAttribute('src');
      previewLogoImage.style.display = 'none';
      previewLogoPlaceholder.style.display = 'block';
      previewLogoPlaceholder.textContent = 'Logo';
      return;
    }

    var resolvedLogoUrl = resolveLogoUrl(rawLogoUrl);
    if (resolvedLogoUrl === '') {
      previewLogoImage.removeAttribute('src');
      previewLogoImage.style.display = 'none';
      previewLogoPlaceholder.style.display = 'block';
      previewLogoPlaceholder.textContent = 'Logo';
      return;
    }

    previewLogoImage.style.display = 'none';
    previewLogoPlaceholder.style.display = 'block';
    previewLogoPlaceholder.textContent = 'Caricamento';
    previewLogoImage.setAttribute('src', resolvedLogoUrl);
  }

  function updatePreview() {
    var documentTitle = getValue('document_title', 'Documento fatturazione') || 'Documento fatturazione';
    var headerTitle = getValue('branding_header_title', '') || documentTitle;
    var headerSubtitle = getValue('branding_header_subtitle', '') || 'Documento cliente di esempio con dati fittizi';
    var footerNote = getValue('branding_footer_note', '');
    var accentColor = getValue('branding_accent_color', '#2c8895') || '#2c8895';
    var prefix = (getValue('document_code_prefix', 'FT') || 'FT').toUpperCase();
    var logoMode = getValue('branding_logo_mode', 'none') || 'none';
    var logoUrl = getValue('branding_logo_url', '');

    var showHeader = isChecked('layout_show_header');
    var showFooter = isChecked('layout_show_footer');
    var showLogo = isChecked('layout_show_logo');
    var showPatientBox = isChecked('layout_show_patient_box');
    var showPaymentBox = isChecked('layout_show_payment_box');
    var showSignatureBox = isChecked('layout_show_signature_box');
    var showTermsBox = isChecked('layout_show_terms_box');

    var showDocumentNumber = isChecked('field_show_document_number');
    var showIssueDate = isChecked('field_show_issue_date');
    var showPatientName = isChecked('field_show_patient_name');
    var showPatientTax = isChecked('field_show_patient_tax_code');
    var showPaymentMethod = isChecked('field_show_payment_method');
    var showPaymentDate = isChecked('field_show_payment_date');
    var showLineItems = isChecked('field_show_line_items');
    var showVatSummary = isChecked('field_show_vat_summary');
    var showStampDuty = isChecked('field_show_stamp_duty');
    var showNotes = isChecked('field_show_notes');

    var tsEnabledWhenAvailable = isChecked('ts_enabled_when_available');
    var tsShowReference = isChecked('ts_show_ts_reference');
    var tsRequireExpense = isChecked('ts_require_expense_type');
    var tsRequirePrivacy = isChecked('ts_require_opposition_flag');

    var patientTitle = getValue('label_patient_section_title', 'Dati paziente') || 'Dati paziente';
    var paymentTitle = getValue('label_payment_section_title', 'Pagamento') || 'Pagamento';
    var notesTitle = getValue('label_notes_label', 'Note') || 'Note';
    var signatureTitle = getValue('label_signature_label', 'Firma') || 'Firma';
    var termsTitle = getValue('label_terms_label', 'Informativa') || 'Informativa';
    var documentNumber = prefix + '-' + previewData.document_number_suffix;
    var footerText = footerNote !== '' ? footerNote : ('Documento di esempio generato il ' + previewData.generated_at + '.');
    var termsText = footerNote !== '' ? footerNote : previewData.terms;

    previewHeader.style.background = accentColor;
    setVisible(previewHeader, showHeader, 'block');
    setText(previewTitle, headerTitle);
    setText(previewSubtitle, headerSubtitle);
    setText(previewTenant, previewData.tenant_name);
    setText(summaryDocumentTitle, documentTitle);
    setText(summaryFieldCount, String(countEnabled(fieldToggleNames)));
    setText(summaryLayoutCount, String(countEnabled(layoutToggleNames)));
    setText(previewPrefixChip, 'Prefisso ' + prefix);
    setText(previewModeChip, tsEnabledWhenAvailable ? 'Pronto per TS' : 'Solo fatturazione');
    setText(previewDocumentNumber, documentNumber);
    setVisible(previewDocumentNumberWrap, showDocumentNumber, 'inline');
    setVisible(previewIssueDateWrap, showIssueDate, 'block');

    setText(previewPatientTitle, patientTitle);
    setVisible(previewPatientBox, showPatientBox, 'block');
    setVisible(previewPatientNameWrap, showPatientName, 'block');
    setVisible(previewPatientTaxWrap, showPatientTax, 'block');
    setVisible(previewPatientEmpty, showPatientBox && !showPatientName && !showPatientTax, 'block');

    setVisible(previewLineItemsBox, showLineItems, 'block');

    setText(previewPaymentTitle, paymentTitle);
    setVisible(previewPaymentBox, showPaymentBox, 'block');
    setVisible(previewPaymentMethodWrap, showPaymentMethod, 'block');
    setVisible(previewPaymentDateWrap, showPaymentDate, 'block');
    setVisible(previewPaymentEmpty, showPaymentBox && !showPaymentMethod && !showPaymentDate, 'block');

    setVisible(previewStampRow, showStampDuty, 'table-row');
    setVisible(previewVatRow, showVatSummary, 'table-row');

    setText(previewNotesTitle, notesTitle);
    previewNotesContent.innerHTML = nl2br(previewData.notes);
    setVisible(previewNotesBox, showNotes, 'block');

    setText(previewSignatureTitle, signatureTitle);
    setVisible(previewSignatureBox, showSignatureBox, 'block');

    setText(previewTermsTitle, termsTitle);
    previewTermsContent.innerHTML = nl2br(termsText);
    setVisible(previewTermsBox, showTermsBox, 'block');

    previewFooterContent.innerHTML = nl2br(footerText);
    setVisible(previewFooter, showFooter, 'block');

    setVisible(previewTsChip, tsEnabledWhenAvailable, 'inline-block');
    setVisible(previewTsRefChip, tsShowReference, 'inline-block');
    setVisible(previewTsExpenseChip, tsRequireExpense, 'inline-block');
    setVisible(previewTsPrivacyChip, tsRequirePrivacy, 'inline-block');

    syncLogo(showHeader && showLogo, logoMode, logoUrl);
  }

  form.addEventListener('input', updatePreview);
  form.addEventListener('change', updatePreview);
  updatePreview();
});
</script>
</body>
</html>
