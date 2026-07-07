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
$assetChecks = is_array($settings['asset_checks'] ?? null) ? $settings['asset_checks'] : [];
$supportedExpenseTypes = is_array($settings['supported_expense_types'] ?? null) ? $settings['supported_expense_types'] : [];
$supportedExpenseDetails = is_array($settings['supported_expense_details'] ?? null) ? $settings['supported_expense_details'] : [];
$supportedEnvironments = is_array($settings['supported_environments'] ?? null) ? $settings['supported_environments'] : ['test', 'production'];
$testPresets = is_array($settings['test_presets'] ?? null) ? array_values(array_filter($settings['test_presets'], 'is_array')) : [];
$testPresetsPath = trim((string) ($settings['test_presets_path'] ?? ''));
$testPresetsSourceLabel = trim((string) ($settings['test_presets_source_label'] ?? $testPresetsPath));
$errors = is_array($errors ?? null) ? $errors : [];
$moduleStatus = is_array($moduleStatus ?? null) ? $moduleStatus : [];
$healthcheckResult = is_array($healthcheckResult ?? null) ? $healthcheckResult : [];
$schemaSyncResult = is_array($schemaSyncResult ?? null) ? $schemaSyncResult : [];
$healthcheckChecks = is_array($healthcheckResult['checks'] ?? null) ? $healthcheckResult['checks'] : [];
$healthcheckErrors = is_array($healthcheckResult['errors'] ?? null) ? $healthcheckResult['errors'] : [];
$healthcheckWarnings = is_array($healthcheckResult['warnings'] ?? null) ? $healthcheckResult['warnings'] : [];
$healthcheckSupportLog = is_array($healthcheckResult['support_log'] ?? null) ? $healthcheckResult['support_log'] : [];
$schemaSyncStatus = trim((string) ($schemaSyncResult['status'] ?? ''));
$schemaSyncMessage = trim((string) ($schemaSyncResult['message'] ?? ''));
$schemaSyncBefore = is_array($schemaSyncResult['before'] ?? null) ? $schemaSyncResult['before'] : [];
$schemaSyncAfter = is_array($schemaSyncResult['after'] ?? null) ? $schemaSyncResult['after'] : [];
$schemaSyncCliMessages = is_array($schemaSyncResult['cli_messages'] ?? null) ? $schemaSyncResult['cli_messages'] : [];
$schemaSyncAlertClass = match ($schemaSyncStatus) {
    'ok' => 'success',
    'warning', 'blocked' => 'warning',
    'error' => 'danger',
    default => 'info',
};
$isNonProduction = !defined('ENVIRONMENT') || ENVIRONMENT !== 'production';
$showSchemaRepairTools = $isNonProduction;
$showHealthcheckTechnicalChecks = $isNonProduction || $healthcheckErrors !== [];
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

$lastCheckStatus = trim((string) ($profile['last_check_status'] ?? ''));
$lastCheckMessage = trim((string) ($profile['last_check_message'] ?? ''));
$lastCheckAt = trim((string) ($profile['last_check_at'] ?? ''));
$currentCredentialMode = trim((string) ($profile['credential_mode'] ?? 'manual'));
$currentTestPresetLabel = trim((string) ($profile['test_preset_label'] ?? ''));
$statusClass = match ($lastCheckStatus) {
    'ok' => 'success',
    'warning' => 'warning',
    'error' => 'danger',
    default => 'default',
};
$testPresetsForJs = [];
foreach ($testPresets as $preset) {
    $presetKey = trim((string) ($preset['key'] ?? ''));
    if ($presetKey === '') {
        continue;
    }

    $testPresetsForJs[$presetKey] = $preset;
}

$testPresetsJson = json_encode(
    $testPresetsForJs,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if (!is_string($testPresetsJson) || $testPresetsJson === '') {
    $testPresetsJson = '{}';
}
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
    .asset-row { border:1px solid #e5ecee; border-radius:10px; padding:12px 14px; margin-bottom:10px; background:#fff; }
    .asset-path { color:#6b8085; font-size:12px; word-break:break-all; }
    .check-row { border-left:4px solid #d8e4e7; padding:10px 12px; background:#fafcfc; margin-bottom:10px; }
    .check-row.is-ok { border-left-color:#00a65a; }
    .check-row.is-warning { border-left-color:#f39c12; }
    .check-row.is-error { border-left-color:#dd4b39; }
    .secret-note { font-size:12px; color:#6d8084; margin-top:6px; }
    .quick-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px; margin-top:14px; }
    .quick-card { border:1px solid #e3ecee; border-radius:12px; background:#fff; padding:14px; }
    .quick-card h4 { margin:0 0 6px 0; font-size:15px; }
    .quick-card p { color:#667b80; margin:0; font-size:12px; line-height:1.5; }
  </style>
</head>
<body class="skin-blue sidebar-mini">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items, 'portal_console_header' => false]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Configura Sistema TS</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Qui il responsabile dello studio salva il profilo tecnico del Sistema Tessera Sanitaria e lancia il primo healthcheck locale del modulo, separato dalla Fatturazione cliente.
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
              In questa fase salviamo il profilo TS dello studio e controlliamo se la base tecnica locale e pronta. L healthcheck qui sotto non invia nulla all esterno: verifica solo configurazione, asset e runtime.
            </p>
            <span class="status-chip">Feature: TS attiva</span>
            <span class="status-chip">Fatturazione: <?= $billingEnabled ? 'attiva' : 'spenta' ?></span>
            <span class="status-chip">Modalita: <?= $integratedEnabled ? 'integrata' : 'TS standalone' ?></span>
            <span class="status-chip">Tipi spesa abilitati: <?= esc(implode(', ', array_keys($supportedExpenseTypes))) ?></span>
            <span class="status-chip">Ambienti: <?= esc(implode(', ', $supportedEnvironments)) ?></span>
            <?php if ($currentCredentialMode === 'official_test_preset' && $currentTestPresetLabel !== ''): ?>
              <span class="status-chip">Preset TEST: <?= esc($currentTestPresetLabel) ?></span>
            <?php endif; ?>
            <div style="margin-top:12px;">
              <a class="btn btn-default" href="<?= portal_tenant_space_url('funzioni') ?>">
                <i class="fa fa-arrow-left"></i> Torna alle funzioni dello spazio
              </a>
              <a class="btn btn-primary" href="<?= site_url('admin/sistema-ts') ?>" style="margin-left:8px;">
                <i class="fa fa-flask"></i> Apri console test TS
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

          <div class="alert alert-info">
            <strong>Percorso semplificato:</strong> nello scenario normale lo studio deve solo
            <strong>salvare il profilo TS</strong> e poi <strong>verificare la configurazione</strong>.
            Gli strumenti di riallineamento schema servono solo in locale/test quando l ambiente tecnico non e ancora allineato.
          </div>

          <div class="quick-grid">
            <div class="quick-card">
              <h4><i class="fa fa-lock"></i> Segreti TS</h4>
              <p>Password, PINCODE e CF sensibili vengono salvati cifrati nel database platform.</p>
            </div>
            <div class="quick-card">
              <h4><i class="fa fa-plug"></i> Healthcheck locale</h4>
              <p>Controlla feature, campi minimi, SOAP, WSDL e certificato prima dell integrazione vera.</p>
            </div>
            <div class="quick-card">
              <h4><i class="fa fa-database"></i> Dati separati</h4>
              <p>Configurazione TS nel platform DB, documenti operativi nel DB tenant.</p>
            </div>
            <div class="quick-card">
              <h4><i class="fa fa-shield"></i> Migrazioni sicure</h4>
              <p>L healthcheck ora controlla schema TS, drift migration e allineamento del runtime prima del go-live.</p>
            </div>
          </div>

          <div class="box box-success" style="margin-top:16px;">
            <div class="box-header with-border">
              <h3 class="box-title">Profilo TS dello studio</h3>
            </div>
            <form method="post" action="<?= portal_tenant_space_url('sistema-ts/save') ?>">
              <?= csrf_field() ?>
              <div class="box-body">
                <div class="ts-panel" style="background:#f8fcff;">
                  <h4 style="margin-top:0;">Modalita TEST ufficiale TS</h4>
                  <?php if ($testPresets !== []): ?>
                    <p class="text-muted" style="margin:0 0 12px 0;">
                      Per sviluppo e collaudo tecnico possiamo caricare una delle utenze di prova ufficiali del kit Sistema TS. Il preset compila il profilo in ambiente <strong>TEST</strong> senza toccare la futura produzione del cliente.
                    </p>
                    <div class="row">
                      <div class="col-md-8">
                        <div class="form-group">
                          <label>Preset di prova ufficiale</label>
                          <select class="form-control" name="test_preset_key" id="ts-test-preset-select">
                            <option value="">Configurazione manuale</option>
                            <?php foreach ($testPresets as $preset): ?>
                              <?php $presetKey = trim((string) ($preset['key'] ?? '')); ?>
                              <?php if ($presetKey === '') { continue; } ?>
                              <option value="<?= esc($presetKey) ?>" <?= $fieldValue('test_preset_key') === $presetKey ? 'selected' : '' ?>>
                                <?= esc((string) ($preset['label'] ?? $presetKey)) ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                          <div class="secret-note">
                            Preset letti da <code><?= esc($testPresetsSourceLabel) ?></code>. Se usi un file locale non va versionato nel repository; in produzione il fallback consigliato resta <code>rest/writable</code>.
                          </div>
                        </div>
                      </div>
                      <div class="col-md-4">
                        <div class="alert alert-info" style="margin-top:25px; margin-bottom:0;">
                          Selezionando un preset, i campi profilo e credenziali vengono precompilati per il collaudo tecnico.
                        </div>
                      </div>
                    </div>
                    <div id="ts-test-preset-info" class="secret-note" style="margin-top:6px;"></div>
                  <?php else: ?>
                    <div class="alert alert-warning" style="margin-bottom:0;">
                      Nessun preset TEST trovato. Per abilitarli crea il file <code><?= esc($testPresetsPath) ?></code>.
                    </div>
                  <?php endif; ?>
                </div>

                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Nome profilo</label>
                      <input class="form-control" type="text" name="profile_name" id="ts-profile-name" maxlength="120" value="<?= esc($fieldValue('profile_name', 'Profilo TS principale')) ?>">
                    </div>
                  </div>
                  <div class="col-md-3">
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
                  <div class="col-md-3">
                    <div class="form-group">
                      <label>Ambiente</label>
                      <select class="form-control" name="environment" id="ts-environment">
                        <?php foreach ($supportedEnvironments as $environment): ?>
                          <option value="<?= esc((string) $environment) ?>" <?= $fieldValue('environment', 'test') === (string) $environment ? 'selected' : '' ?>>
                            <?= esc(strtoupper((string) $environment)) ?>
                          </option>
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
                      <input class="form-control" type="text" name="owner_cf" id="ts-owner-cf" maxlength="16" value="<?= esc($fieldValue('owner_cf')) ?>" placeholder="<?= !empty($profile['has_owner_cf']) ? 'Gia configurato, lascia vuoto per mantenerlo' : 'Opzionale in questa fase' ?>">
                      <?php if (!empty($profile['has_owner_cf'])): ?>
                        <div class="secret-note">Un CF titolare e gia salvato in forma cifrata. Se lasci il campo vuoto non viene sovrascritto.</div>
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
                      Regione, ASL e SSA restano opzionali in questo step. Li teniamo gia pronti per casi reali piu strutturati.
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
                          <div class="secret-note">Password gia presente in forma cifrata. Lascia vuoto per mantenerla.</div>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="col-md-4">
                      <div class="form-group">
                        <label>PINCODE TS</label>
                        <input class="form-control" type="password" name="pincode" id="ts-pincode" value="">
                        <?php if (!empty($profile['has_pincode'])): ?>
                          <div class="secret-note">PINCODE gia presente in forma cifrata. Lascia vuoto per mantenerlo.</div>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>

                <?php if ($lastCheckStatus !== '' || $lastCheckMessage !== '' || $lastCheckAt !== ''): ?>
                  <div class="ts-panel">
                    <h4 style="margin-top:0;">Ultimo healthcheck registrato</h4>
                    <p style="margin:0 0 8px 0;">
                      <span class="label label-<?= esc($statusClass) ?>"><?= esc($lastCheckStatus !== '' ? strtoupper($lastCheckStatus) : 'N/D') ?></span>
                      <?php if ($lastCheckAt !== ''): ?>
                        <span class="text-muted" style="margin-left:8px;"><?= esc($lastCheckAt) ?></span>
                      <?php endif; ?>
                    </p>
                    <?php if ($lastCheckMessage !== ''): ?>
                      <div class="text-muted" style="white-space:pre-line;"><?= esc($lastCheckMessage) ?></div>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
              <div class="box-footer">
                <button class="btn btn-success" type="submit">
                  <i class="fa fa-save"></i> Salva profilo TS
                </button>
                <button class="btn btn-primary" type="submit" name="after_save" value="healthcheck" style="margin-left:8px;">
                  <i class="fa fa-check"></i> Salva e valida localmente
                </button>
              </div>
            </form>
          </div>

          <div class="box box-default">
            <div class="box-header with-border">
              <h3 class="box-title">Verifica configurazione TS</h3>
            </div>
            <div class="box-body">
              <p class="text-muted">
                Questo controllo non contatta il Sistema TS. Verifica se profilo, credenziali, asset tecnici e schema TS dello spazio sono pronti.
              </p>

              <form method="post" action="<?= portal_tenant_space_url('sistema-ts/healthcheck') ?>" style="margin-bottom:16px;">
                <?= csrf_field() ?>
                <button class="btn btn-primary" type="submit">
                  <i class="fa fa-stethoscope"></i> Verifica configurazione TS
                </button>
              </form>

              <?php if ($showSchemaRepairTools): ?>
                <div class="ts-panel" style="background:#fbfcfd;">
                  <h4 style="margin-top:0;">Strumenti tecnici locali</h4>
                  <p class="text-muted" style="margin-bottom:12px;">
                    Da usare solo in locale o test se il tenant segnala schema TS mancante o non allineato.
                  </p>

                  <form method="post" action="<?= portal_tenant_space_url('sistema-ts/repair-schema') ?>" style="margin-bottom:0;">
                    <?= csrf_field() ?>
                    <button class="btn btn-default" type="submit">
                      <i class="fa fa-wrench"></i> Ripara installazione locale TS
                    </button>
                  </form>
                </div>

                <?php if ($schemaSyncResult !== []): ?>
                  <div class="alert alert-<?= esc($schemaSyncAlertClass) ?>">
                    <?= esc($schemaSyncMessage !== '' ? $schemaSyncMessage : 'Esito allineamento schema TS disponibile.') ?>
                  </div>

                  <?php if ($schemaSyncCliMessages !== []): ?>
                    <div class="check-row is-<?= esc($schemaSyncStatus !== '' ? $schemaSyncStatus : 'warning') ?>">
                      <strong>Dettaglio esecuzione migration TS</strong><br>
                      <span class="text-muted" style="white-space:pre-line;"><?= esc(implode("\n", array_map('strval', $schemaSyncCliMessages))) ?></span>
                    </div>
                  <?php endif; ?>

                  <?php foreach (['before' => $schemaSyncBefore, 'after' => $schemaSyncAfter] as $phase => $inspection): ?>
                    <?php if ($inspection !== []): ?>
                      <?php
                        $phaseLabel = $phase === 'before' ? 'prima' : 'dopo';
                        $inspectionWarnings = is_array($inspection['warnings'] ?? null) ? $inspection['warnings'] : [];
                        $inspectionErrors = is_array($inspection['errors'] ?? null) ? $inspection['errors'] : [];
                        $pendingMigrations = is_array($inspection['pending_ts_migrations'] ?? null) ? $inspection['pending_ts_migrations'] : [];
                      ?>
                      <div class="check-row is-<?= esc(trim((string) ($inspection['status'] ?? 'warning'))) ?>">
                        <strong>Schema TS <?= esc($phaseLabel) ?> dell allineamento</strong><br>
                        <span class="text-muted"><?= esc((string) ($inspection['message'] ?? '')) ?></span>
                        <?php if ($pendingMigrations !== []): ?>
                          <div class="text-muted" style="margin-top:6px;">
                            Migration TS pendenti <?= esc($phaseLabel) ?>: <?= esc(implode(', ', array_map(static fn($row) => (string) ($row['file'] ?? ''), $pendingMigrations))) ?>
                          </div>
                        <?php endif; ?>
                        <?php if ($inspectionErrors !== []): ?>
                          <div class="text-danger" style="margin-top:6px; white-space:pre-line;"><?= esc(implode("\n", array_map('strval', $inspectionErrors))) ?></div>
                        <?php endif; ?>
                        <?php if ($inspectionWarnings !== []): ?>
                          <div class="text-warning" style="margin-top:6px; white-space:pre-line;"><?= esc(implode("\n", array_map('strval', $inspectionWarnings))) ?></div>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  <?php endforeach; ?>
                <?php endif; ?>
              <?php endif; ?>

              <?php if ($healthcheckResult !== []): ?>
                <div class="alert alert-<?= ($healthcheckResult['status'] ?? '') === 'ok' ? 'success' : (($healthcheckResult['status'] ?? '') === 'warning' ? 'warning' : 'danger') ?>">
                  <?= esc((string) ($healthcheckResult['message'] ?? '')) ?>
                </div>

                <?php if (trim((string) ($healthcheckSupportLog['trace_id'] ?? '')) !== ''): ?>
                  <div class="alert alert-info" style="margin-top:12px;">
                    <strong>Riferimento supporto TS:</strong>
                    <code><?= esc((string) ($healthcheckSupportLog['trace_id'] ?? '')) ?></code>
                  </div>
                <?php endif; ?>

                <?php if ($showHealthcheckTechnicalChecks): ?>
                  <?php foreach ($healthcheckChecks as $check): ?>
                    <?php $checkStatus = trim((string) ($check['status'] ?? '')); ?>
                    <div class="check-row<?= $checkStatus !== '' ? ' is-' . esc($checkStatus) : '' ?>">
                      <strong><?= esc((string) ($check['label'] ?? 'Controllo')) ?></strong><br>
                      <span class="text-muted"><?= esc((string) ($check['message'] ?? '')) ?></span>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($healthcheckErrors !== []): ?>
                  <div class="alert alert-danger" style="margin-top:12px;">
                    <strong>Blocchi da correggere:</strong>
                    <ul style="margin:8px 0 0 18px;">
                      <?php foreach ($healthcheckErrors as $message): ?>
                        <li><?= esc((string) $message) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                <?php endif; ?>

                <?php if ($healthcheckWarnings !== []): ?>
                  <div class="alert alert-warning" style="margin-top:12px;">
                    <strong>Avvisi non bloccanti:</strong>
                    <ul style="margin:8px 0 0 18px;">
                      <?php foreach ($healthcheckWarnings as $message): ?>
                        <li><?= esc((string) $message) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>

          <div class="box box-default">
            <div class="box-header with-border">
              <h3 class="box-title">Asset tecnici locali</h3>
            </div>
            <div class="box-body">
              <?php foreach ($assetChecks as $assetCheck): ?>
                <div class="asset-row">
                  <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                    <strong><?= esc((string) ($assetCheck['key'] ?? 'asset')) ?></strong>
                    <span class="label label-<?= !empty($assetCheck['exists']) ? 'success' : 'default' ?>">
                      <?= !empty($assetCheck['exists']) ? 'presente' : 'mancante' ?>
                    </span>
                  </div>
                  <div class="asset-path"><?= esc((string) ($assetCheck['path'] ?? '')) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="box box-default">
            <div class="box-header with-border">
              <h3 class="box-title">Tipi spesa supportati oggi</h3>
            </div>
            <div class="box-body">
              <p class="text-muted" style="margin-bottom:12px;">
                I codici mostrati qui seguono il tracciato ufficiale TS. Le voci marcate come <strong>Descrizione prudente</strong> sono codici validi nell XSD, ma la descrizione estesa resta tecnica finche non la validiamo su documentazione piu esplicita o casi reali del cliente.
              </p>

              <?php if ($supportedExpenseDetails === []): ?>
                <div class="text-muted">Nessun codice spesa TS configurato.</div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-bordered table-striped">
                    <thead>
                      <tr>
                        <th>Codice</th>
                        <th>Etichetta</th>
                        <th>Stato descrizione</th>
                        <th>Nota operativa</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($supportedExpenseDetails as $detail): ?>
                        <?php
                          $confidence = trim((string) ($detail['confidence'] ?? 'verified'));
                          $confidenceLabel = trim((string) ($detail['confidence_label'] ?? 'Descrizione verificata'));
                        ?>
                        <tr>
                          <td><strong><?= esc((string) ($detail['code'] ?? '')) ?></strong></td>
                          <td><?= esc((string) ($detail['label'] ?? '')) ?></td>
                          <td>
                            <span class="label label-<?= $confidence === 'provisional' ? 'warning' : 'success' ?>">
                              <?= esc($confidenceLabel) ?>
                            </span>
                          </td>
                          <td><?= esc((string) ($detail['note'] ?? '')) ?></td>
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
<script>
(function () {
  var presets = <?= $testPresetsJson ?>;
  var select = document.getElementById('ts-test-preset-select');
  if (!select) {
    return;
  }

  var fields = {
    profile_name: document.getElementById('ts-profile-name'),
    sender_type: document.getElementById('ts-sender-type'),
    environment: document.getElementById('ts-environment'),
    owner_piva: document.getElementById('ts-owner-piva'),
    owner_cf: document.getElementById('ts-owner-cf'),
    region_code: document.getElementById('ts-region-code'),
    asl_code: document.getElementById('ts-asl-code'),
    ssa_code: document.getElementById('ts-ssa-code'),
    auth_username: document.getElementById('ts-auth-username'),
    auth_password: document.getElementById('ts-auth-password'),
    pincode: document.getElementById('ts-pincode')
  };
  var infoBox = document.getElementById('ts-test-preset-info');

  function setFieldValue(fieldName, value) {
    if (!fields[fieldName] || value === undefined || value === null || value === '') {
      return;
    }

    fields[fieldName].value = String(value);
  }

  function renderPresetInfo(preset) {
    if (!infoBox) {
      return;
    }

    if (!preset) {
      infoBox.textContent = '';
      return;
    }

    var parts = [];
    if (preset.label) {
      parts.push('Preset attivo: ' + preset.label);
    }
    if (preset.office_code) {
      parts.push('Codice ufficio: ' + preset.office_code);
    }
    if (preset.notes) {
      parts.push(preset.notes);
    }
    if (preset.source) {
      parts.push('Fonte: ' + preset.source);
    }

    infoBox.textContent = parts.join(' | ');
  }

  function applyPreset(key) {
    var preset = presets[key] || null;
    renderPresetInfo(preset);

    if (!preset) {
      return;
    }

    setFieldValue('profile_name', preset.profile_name);
    setFieldValue('sender_type', preset.sender_type);
    setFieldValue('environment', preset.environment || 'test');
    setFieldValue('owner_piva', preset.owner_piva);
    setFieldValue('owner_cf', preset.owner_cf);
    setFieldValue('region_code', preset.region_code);
    setFieldValue('asl_code', preset.asl_code);
    setFieldValue('ssa_code', preset.ssa_code);
    setFieldValue('auth_username', preset.auth_username);
    setFieldValue('auth_password', preset.auth_password);
    setFieldValue('pincode', preset.pincode);
  }

  select.addEventListener('change', function () {
    applyPreset(this.value);
  });

  if (select.value) {
    renderPresetInfo(presets[select.value] || null);
  }
})();
</script>
</body>
</html>
