<?php
helper('portal');

$menuDataAdmin = session()->get('menuDataAdmin');
$sidebarMenuItems = is_array($menuDataAdmin['result'] ?? null) ? $menuDataAdmin['result'] : [];

if (empty($menu_items) || !is_array($menu_items)) {
    $menu_items = $sidebarMenuItems !== [] ? $sidebarMenuItems : (session()->get('header_menu_items') ?? []);
}

$settings = is_array($settings ?? null) ? $settings : [];
$profile = is_array($settings['profile'] ?? null) ? $settings['profile'] : [];
$senderTypes = is_array($settings['sender_types'] ?? null) ? $settings['sender_types'] : [
    'medico' => 'Medico',
    'struttura_autorizzata' => 'Struttura autorizzata',
    'struttura_accreditata' => 'Struttura accreditata',
    'studio_associato' => 'Studio associato',
];
$supportedExpenseTypes = is_array($settings['supported_expense_types'] ?? null) ? $settings['supported_expense_types'] : [];
$supportedDocumentTypes = is_array($settings['supported_document_types'] ?? null) ? $settings['supported_document_types'] : [];
$paymentModes = is_array($settings['payment_modes'] ?? null) ? $settings['payment_modes'] : [];
$serviceCatalog = is_array($serviceCatalog ?? null) ? array_values(array_filter($serviceCatalog, 'is_array')) : [];
$errors = is_array($errors ?? null) ? $errors : [];
$moduleStatus = is_array($moduleStatus ?? null) ? $moduleStatus : [];
$billingEnabled = !empty($moduleStatus['billing_enabled']);
$integratedEnabled = !empty($moduleStatus['integrated_enabled']);

$fieldValue = static function (string $key, $default = '') use ($profile): string {
    $old = old($key);
    if ($old !== null) {
        return trim((string) $old);
    }

    return trim((string) ($profile[$key] ?? $default));
};

$fieldBool = static function (string $key, bool $default = false) use ($profile): bool {
    $old = old($key);
    if ($old !== null) {
        return (int) $old === 1;
    }

    return (bool) ($profile[$key] ?? $default);
};

$documentDefaults = is_array($profile['document_defaults'] ?? null) ? $profile['document_defaults'] : [];
$serviceExpenseTypes = is_array($profile['service_expense_types'] ?? null) ? $profile['service_expense_types'] : [];
$serviceExpenseMap = [];
foreach ($serviceExpenseTypes as $item) {
    if (!is_array($item)) {
        continue;
    }

    $descriptionKey = mb_strtolower(preg_replace('/\s+/', ' ', trim((string) ($item['description'] ?? ''))) ?? '');
    if ($descriptionKey !== '') {
        $serviceExpenseMap[$descriptionKey] = trim((string) ($item['expense_type_code'] ?? ''));
    }
}
$oldServiceDescriptions = old('service_expense_description');
$oldServiceExpenseTypes = old('service_expense_type_code');
if (is_array($oldServiceDescriptions) && is_array($oldServiceExpenseTypes)) {
    foreach ($oldServiceDescriptions as $index => $description) {
        $descriptionKey = mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $description)) ?? '');
        $expenseType = strtoupper(trim((string) ($oldServiceExpenseTypes[$index] ?? '')));
        if ($descriptionKey !== '' && $expenseType !== '') {
            $serviceExpenseMap[$descriptionKey] = $expenseType;
        }
    }
}
$defaultDocumentType = trim((string) (old('default_document_type') ?? ($documentDefaults['document_type'] ?? 'F')));
$defaultExpenseType = trim((string) (old('default_expense_type_code') ?? ($documentDefaults['expense_type_code'] ?? 'SP')));
$defaultPaymentMode = trim((string) (old('default_payment_mode') ?? ($documentDefaults['payment_mode'] ?? 'tracciato')));
$defaultOppositionFlag = old('default_opposition_flag') !== null
    ? (int) old('default_opposition_flag') === 1
    : !empty($documentDefaults['opposition_flag']);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Configura Sistema TS</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <style>
    .nav-pills.nav-stacked > li.active > a { background-color:#2c8895; color:#fff; }
    .intro-box { border:1px solid #dbe8eb; border-radius:12px; padding:18px 20px; background:linear-gradient(135deg, #f8fcfc 0%, #eff7f8 100%); margin-bottom:16px; }
    .ts-panel { border:1px solid #e5ecee; border-radius:12px; background:#fff; padding:16px; margin-bottom:16px; }
    .status-chip { display:inline-block; margin:0 8px 8px 0; padding:6px 10px; border-radius:999px; background:#eef5f6; color:#1b6770; font-size:12px; font-weight:600; }
    .secret-note { font-size:12px; color:#6d8084; margin-top:6px; }
  </style>
  <link href="<?= base_url('public/assets/css/billing-ts-ui.css') ?>" rel="stylesheet" />
</head>
<body class="skin-blue sidebar-mini billing-ts-ui module-sistema-ts ts-settings-page">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items, 'portal_console_header' => false]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Configurazione Sistema TS</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Qui il responsabile dello studio inserisce le credenziali, i dati richiesti dal Sistema Tessera Sanitaria e associa le prestazioni ai relativi tipi di spesa.
      </p>
    </section>

    <section class="content">
      <div class="row">
        <div class="col-md-3">
          <?= view('partials/sidebar_admin', ['menu_items' => $sidebarMenuItems]) ?>
        </div>

        <div class="col-md-9">
          <?php if (!empty($errors['generic'])): ?>
            <div class="alert alert-danger"><?= esc((string) $errors['generic']) ?></div>
          <?php endif; ?>
          <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?= esc((string) $success) ?></div>
          <?php endif; ?>

          <div class="intro-box">
            <h3 style="margin-top:0; margin-bottom:8px;">
              Studio attivo: <?= esc((string) ($tenantContext->tenantName ?? '')) ?>
            </h3>
            <p style="margin:0 0 12px 0; color:#52676c;">
              Inserisci i dati dello studio, le credenziali e le preferenze da utilizzare per il Sistema TS.
            </p>
            <span class="status-chip">Feature: TS attiva</span>
            <span class="status-chip">Fatturazione: <?= $billingEnabled ? 'attiva' : 'spenta' ?></span>
            <span class="status-chip">Modalità: <?= $integratedEnabled ? 'integrata' : 'TS standalone' ?></span>
            <div style="margin-top:12px;">
              <a class="btn btn-default" href="<?= portal_tenant_space_url('funzioni') ?>">
                <i class="fa fa-arrow-left"></i> Torna alle funzioni dello spazio
              </a>
              <a class="btn btn-primary" href="<?= site_url('admin/sistema-ts') ?>" style="margin-left:8px;">
                <i class="fa fa-dashboard"></i> Apri Sistema TS
              </a>
              <a class="btn btn-success" href="<?= site_url('admin/sistema-ts/documenti/nuovo') ?>" style="margin-left:8px;">
                <i class="fa fa-plus"></i> Nuovo documento TS
              </a>
              <?php if ($billingEnabled): ?>
                <a class="btn btn-default" href="<?= site_url('admin/fatturazione') ?>" style="margin-left:8px;">
                  <i class="fa fa-calculator"></i> Apri modulo Fatturazione
                </a>
              <?php endif; ?>
            </div>
          </div>

          <div class="box box-success" style="margin-top:16px;">
            <div class="box-header with-border">
              <h3 class="box-title">Profilo TS dello studio</h3>
            </div>
            <form method="post" action="<?= portal_tenant_space_url('sistema-ts/save') ?>">
              <?= csrf_field() ?>
              <div class="box-body">
                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Nome profilo</label>
                      <input class="form-control" type="text" name="profile_name" id="ts-profile-name" maxlength="120" value="<?= esc($fieldValue('profile_name', 'Profilo TS principale')) ?>">
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Tipo soggetto</label>
                      <select class="form-control" name="sender_type" id="ts-sender-type">
                        <option value="">Seleziona</option>
                        <?php foreach ($senderTypes as $value => $label): ?>
                          <option value="<?= esc($value) ?>" <?= $fieldValue('sender_type') === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Partita IVA erogatore</label>
                      <input class="form-control" type="text" name="owner_piva" id="ts-owner-piva" maxlength="16" value="<?= esc($fieldValue('owner_piva')) ?>" placeholder="Solo numeri">
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Codice Fiscale titolare</label>
                      <input class="form-control" type="text" name="owner_cf" id="ts-owner-cf" maxlength="16" value="<?= esc($fieldValue('owner_cf')) ?>" placeholder="<?= !empty($profile['has_owner_cf']) ? 'Già configurato, lascia vuoto per mantenerlo' : 'Opzionale in questa fase' ?>">
                      <?php if (!empty($profile['has_owner_cf'])): ?>
                        <div class="secret-note">Un CF titolare e già salvato in forma cifrata. Se lasci il campo vuoto non viene sovrascritto.</div>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="checkbox" style="margin-top:32px;">
                      <label>
                        <input type="hidden" name="is_enabled" value="0">
                        <input type="checkbox" name="is_enabled" value="1" <?= $fieldBool('is_enabled', true) ? 'checked' : '' ?>>
                        Profilo TS attivo per lo studio
                      </label>
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-2">
                    <div class="form-group">
                      <label>Codice regione</label>
                      <input class="form-control" type="text" name="region_code" id="ts-region-code" maxlength="3" value="<?= esc($fieldValue('region_code')) ?>">
                    </div>
                  </div>
                  <div class="col-md-2">
                    <div class="form-group">
                      <label>Codice ASL</label>
                      <input class="form-control" type="text" name="asl_code" id="ts-asl-code" maxlength="3" value="<?= esc($fieldValue('asl_code')) ?>">
                    </div>
                  </div>
                  <div class="col-md-2">
                    <div class="form-group">
                      <label>Codice SSA</label>
                      <input class="form-control" type="text" name="ssa_code" id="ts-ssa-code" maxlength="6" value="<?= esc($fieldValue('ssa_code')) ?>">
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="alert alert-info" style="margin-top:25px; margin-bottom:0;">
                      Regione, ASL e SSA restano opzionali in questo step. Li teniamo già pronti per casi reali più strutturati.
                    </div>
                  </div>
                </div>

                <div class="ts-panel">
                  <h4 style="margin-top:0;">Credenziali TS</h4>
                  <div class="row">
                    <div class="col-md-4">
                      <div class="form-group">
                        <label>Username TS</label>
                        <input class="form-control" type="text" name="auth_username" id="ts-auth-username" maxlength="120" value="<?= esc($fieldValue('auth_username')) ?>">
                      </div>
                    </div>
                    <div class="col-md-4">
                      <div class="form-group">
                        <label>Password TS</label>
                        <input class="form-control" type="password" name="auth_password" id="ts-auth-password" value="">
                        <?php if (!empty($profile['has_auth_password'])): ?>
                          <div class="secret-note">Password già presente in forma cifrata. Lascia vuoto per mantenerla.</div>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="col-md-4">
                      <div class="form-group">
                        <label>PINCODE TS</label>
                        <input class="form-control" type="password" name="pincode" id="ts-pincode" value="">
                        <?php if (!empty($profile['has_pincode'])): ?>
                          <div class="secret-note">PINCODE già presente in forma cifrata. Lascia vuoto per mantenerlo.</div>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="ts-panel ts-defaults-panel">
                  <h4 style="margin-top:0;">Tipo di spesa per prestazione</h4>
                  <p class="text-muted" style="margin:0 0 14px 0;">
                    Associa ogni prestazione salvata in Fatturazione al relativo codice TS. Quando la prestazione viene usata in fattura, il tipo di spesa viene proposto automaticamente.
                  </p>
                  <?php if ($serviceCatalog === []): ?>
                    <div class="alert alert-info" style="margin-bottom:0;">
                      Non ci sono ancora prestazioni salvate. Aggiungile da
                      <a href="<?= portal_tenant_space_url('fatturazione') ?>"><strong>Configura Fatturazione</strong></a>
                      oppure scrivile nella prossima fattura: compariranno qui automaticamente.
                    </div>
                  <?php else: ?>
                    <div class="table-responsive">
                      <table class="table table-bordered table-striped ts-service-expense-table" style="margin-bottom:0;">
                        <thead>
                          <tr>
                            <th>Prestazione</th>
                            <th style="width:48%;">Tipo di spesa TS</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($serviceCatalog as $service): ?>
                            <?php
                              $serviceDescription = trim((string) ($service['description'] ?? ''));
                              if ($serviceDescription === '') {
                                  continue;
                              }
                              $serviceKey = mb_strtolower(preg_replace('/\s+/', ' ', $serviceDescription) ?? $serviceDescription);
                              $selectedExpenseType = trim((string) ($serviceExpenseMap[$serviceKey] ?? $defaultExpenseType));
                            ?>
                            <tr>
                              <td>
                                <strong><?= esc($serviceDescription) ?></strong>
                                <input type="hidden" name="service_expense_description[]" value="<?= esc($serviceDescription) ?>">
                              </td>
                              <td>
                                <select class="form-control" name="service_expense_type_code[]">
                                  <?php foreach ($supportedExpenseTypes as $value => $label): ?>
                                    <option value="<?= esc((string) $value) ?>" <?= $selectedExpenseType === (string) $value ? 'selected' : '' ?>>
                                      <?= esc((string) $value . ' — ' . $label) ?>
                                    </option>
                                  <?php endforeach; ?>
                                </select>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="ts-panel ts-defaults-panel">
                  <h4 style="margin-top:0;">Valori predefiniti per i nuovi documenti TS</h4>
                  <p class="text-muted" style="margin:0 0 14px 0;">
                    Questi valori vengono proposti nei nuovi documenti TS manuali. Restano sempre modificabili sul singolo documento e non sovrascrivono le fatture già create.
                  </p>
                  <div class="row">
                    <div class="col-md-4">
                      <div class="form-group">
                        <label>Tipo documento</label>
                        <select class="form-control" name="default_document_type">
                          <?php foreach ($supportedDocumentTypes as $value => $label): ?>
                            <option value="<?= esc((string) $value) ?>" <?= $defaultDocumentType === (string) $value ? 'selected' : '' ?>><?= esc((string) $label) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                    </div>
                    <div class="col-md-4">
                      <div class="form-group">
                        <label>Tipo spesa</label>
                        <select class="form-control" name="default_expense_type_code">
                          <?php foreach ($supportedExpenseTypes as $value => $label): ?>
                            <option value="<?= esc((string) $value) ?>" <?= $defaultExpenseType === (string) $value ? 'selected' : '' ?>><?= esc((string) $value . ' — ' . $label) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                    </div>
                    <div class="col-md-4">
                      <div class="form-group">
                        <label>Modalità pagamento</label>
                        <select class="form-control" name="default_payment_mode">
                          <?php foreach ($paymentModes as $value => $label): ?>
                            <option value="<?= esc((string) $value) ?>" <?= $defaultPaymentMode === (string) $value ? 'selected' : '' ?>><?= esc((string) $label) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                    </div>
                  </div>
                  <div class="checkbox" style="margin:0;">
                    <label>
                      <input type="hidden" name="default_opposition_flag" value="0">
                      <input type="checkbox" name="default_opposition_flag" value="1" <?= $defaultOppositionFlag ? 'checked' : '' ?>>
                      Propone l’opposizione al 730 precompilato
                    </label>
                    <div class="secret-note">Attivala solo se è una regola organizzativa dello studio: la scelta del paziente resta sempre modificabile nel singolo documento.</div>
                  </div>
                </div>

              </div>
              <div class="box-footer">
                <button class="btn btn-success" type="submit">
                  <i class="fa fa-save"></i> Salva profilo TS
                </button>
              </div>
            </form>
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
