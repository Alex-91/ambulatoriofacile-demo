<?php
helper('portal');

$dashboard = is_array($dashboard ?? null) ? $dashboard : [];
$summary = is_array($dashboard['summary'] ?? null) ? $dashboard['summary'] : [];
$tenantRows = is_array($dashboard['tenant_rows'] ?? null) ? $dashboard['tenant_rows'] : [];
$recentRows = is_array($dashboard['recent_rows'] ?? null) ? $dashboard['recent_rows'] : [];
$logSummary = is_array($dashboard['log_summary'] ?? null) ? $dashboard['log_summary'] : [];
$logFilters = is_array($dashboard['log_filters'] ?? null) ? $dashboard['log_filters'] : [];
$logTotalMatching = (int) ($dashboard['log_total_matching'] ?? count($recentRows));
$logTruncated = !empty($dashboard['log_truncated']);
$logStorageReady = !empty($dashboard['log_storage_ready']);
$errors = is_array($errors ?? null) ? $errors : [];
$launchFeedback = is_array($launchFeedback ?? null) ? $launchFeedback : null;
$days = (int) ($days ?? 30);
$policy = is_array($policy ?? null) ? $policy : [];
$policyTenant = is_array($policyTenant ?? null) ? $policyTenant : null;
$globalSmsProvider = is_array($globalSmsProvider ?? null) ? $globalSmsProvider : [];
$tenantSmsProvider = is_array($tenantSmsProvider ?? null) ? $tenantSmsProvider : [];
$policyValue = static function (string $group, string $key, $fallback = '') {
    $oldGroup = old($group);
    if (is_array($oldGroup) && array_key_exists($key, $oldGroup)) {
        return $oldGroup[$key];
    }
    return $fallback;
};

$formatDateTime = static function (?string $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '-';
    }

    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Europe/Rome'))->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return $value;
    }
};

$typeLabels = [
    \App\Services\AppointmentNotificationSettingsService::TYPE_PATIENT_BOOKING => 'Conferma appuntamento',
    \App\Services\AppointmentNotificationSettingsService::TYPE_DOCTOR_CROSS_BOOKING => 'Da medico a medico',
    \App\Services\AppointmentNotificationSettingsService::TYPE_REMINDER => 'Reminder appuntamento',
];
$channelMeta = [
    'sms' => ['label' => 'SMS'],
    'wa' => ['label' => 'WhatsApp'],
    'email' => ['label' => 'Email'],
    'otp' => ['label' => 'OTP'],
];
$logStatusLabels = [
    'sent' => 'Accettato',
    'accepted' => 'Accettato',
    'success' => 'Accettato',
    'delivered' => 'Consegnato',
    'read' => 'Letto',
    'pending' => 'In attesa',
    'queued' => 'In coda',
    'deferred' => 'Rinviato',
    'not_sent' => 'Non inviato',
    'skipped' => 'Saltato',
    'failed' => 'Fallito',
    'error' => 'Errore',
    'rejected' => 'Rifiutato',
    'undelivered' => 'Non consegnato',
    'bounced' => 'Respinto',
    'expired' => 'Scaduto',
];
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Notifiche Appuntamenti</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/assets/css/platform-console.css') ?>" rel="stylesheet" />
  <style>
    .intro-box { border:1px solid #dbe8eb; border-radius:12px; padding:18px 20px; background:linear-gradient(135deg, #f8fcfc 0%, #eff7f8 100%); margin-bottom:16px; }
    .metric-card { border:1px solid #e5ecee; border-radius:10px; background:#fff; padding:16px; min-height:128px; margin-bottom:14px; }
    .metric-label { color:#6a7b80; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
    .metric-value { font-size:30px; font-weight:700; color:#1d5f68; margin-top:8px; }
    .metric-helper { color:#70858a; font-size:12px; margin-top:8px; }
    .status-chip { display:inline-block; margin:0 8px 8px 0; padding:7px 11px; border-radius:999px; background:#dff1f2; color:#176872; font-size:12px; font-weight:600; }
    .tenant-config-list { margin:0; padding-left:18px; color:#546a70; }
    .delivery-policy-grid { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:14px; }
    .delivery-policy-card { border:1px solid #e5ecee; border-radius:10px; background:#fbfdfd; padding:16px; }
    .delivery-policy-card h4 { margin:0 0 6px; color:#214f56; }
    .delivery-policy-card > p { min-height:38px; color:#70858a; font-size:12px; }
    .delivery-policy-rate { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .delivery-policy-rate .daily-limit { grid-column:1 / -1; }
    .policy-fixed-value { padding:7px 10px; border:1px solid #d8e3e5; border-radius:4px; background:#eef4f5; color:#52676c; }
    .provider-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:14px; margin-top:14px; }
    .provider-secret-status { display:inline-block; margin-left:6px; font-size:11px; }
    .provider-actions { display:flex; justify-content:flex-end; margin-top:14px; }
    .notification-log-summary { display:grid; grid-template-columns:repeat(5, minmax(0, 1fr)); gap:10px; margin:14px 0; }
    .notification-log-summary > div { border:1px solid #e2eaec; border-radius:8px; padding:10px 12px; background:#f9fcfc; }
    .notification-log-summary strong { display:block; font-size:22px; color:#245c64; }
    .notification-log-filters { border:1px solid #dce7e9; border-radius:10px; background:#f8fbfb; padding:14px; margin-bottom:14px; }
    .notification-log-diagnostic { min-width:260px; max-width:420px; white-space:normal; }
    .notification-log-technical { margin-top:7px; }
    .notification-log-technical summary { cursor:pointer; color:#337ab7; }
    .notification-log-technical pre { max-height:240px; overflow:auto; margin-top:7px; white-space:pre-wrap; word-break:break-word; font-size:11px; }
    @media (max-width: 991px) { .delivery-policy-grid, .provider-grid { grid-template-columns:1fr; } .delivery-policy-card > p { min-height:0; } }
    @media (max-width: 767px) { .notification-log-summary { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
  </style>
</head>
<body class="platform-console-body">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items ?? [], 'portal_console_header' => true]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Notifiche Appuntamenti</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Qui controlli in modo centralizzato i canali acquistati, le configurazioni dei responsabili di studio e lo storico invii di conferme, reminder e avvisi tra medici.
      </p>
    </section>

    <section class="content">
      <div class="row">
        <div class="col-md-3">
          <?= view('partials/sidebar_platform', ['platformMasterEmails' => $platformMasterEmails ?? []]) ?>
        </div>

        <div class="col-md-9">
          <?php if (!empty($errors['generic'])): ?>
            <div class="alert alert-danger"><?= esc((string) $errors['generic']) ?></div>
          <?php endif; ?>
          <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?= esc((string) $success) ?></div>
          <?php endif; ?>
          <?php if (!$cronConfigured): ?>
            <div class="alert alert-warning">
              `CRON_ACCESS_TOKEN` non configurato. Il pannello funziona, ma se vuoi lanciare i reminder da URL schedulato devi impostarlo.
            </div>
          <?php endif; ?>
          <?php if (empty($smsProviderConfigured)): ?>
            <div class="alert alert-warning">
              Provider SMS selezionato: <strong><?= esc((string) ($smsProviderLabel ?? 'SMS')) ?></strong>, ma le credenziali API non sono ancora configurate. Gli invii SMS resteranno bloccati con errore esplicito.
            </div>
          <?php endif; ?>

          <div class="intro-box">
            <h3 style="margin-top:0; margin-bottom:8px;">Regia unica dei canali</h3>
            <p style="margin:0 0 12px 0; color:#52676c;">
              Il responsabile della piattaforma decide quali studi hanno acquistato SMS e/o WhatsApp. Il responsabile dello studio, dentro il suo spazio, sceglie invece per quali flussi usare SMS, WhatsApp, Email e OTP.
            </p>
            <span class="status-chip">Studi attivi: <?= (int) ($summary['tenant_count'] ?? 0) ?></span>
            <span class="status-chip">Modulo attivo: <?= (int) ($summary['module_enabled_count'] ?? 0) ?></span>
            <span class="status-chip">Canale SMS: <?= (int) ($summary['sms_enabled_count'] ?? 0) ?></span>
            <span class="status-chip">Provider SMS: <?= esc((string) ($smsProviderLabel ?? 'SMS')) ?></span>
            <span class="status-chip">Canale WhatsApp: <?= (int) ($summary['wa_enabled_count'] ?? 0) ?></span>
            <span class="status-chip">Canale Email: <?= (int) ($summary['email_enabled_count'] ?? 0) ?></span>
            <span class="status-chip">Canale OTP: <?= (int) ($summary['otp_enabled_count'] ?? 0) ?></span>
            <div style="margin-top:6px;"><a class="btn btn-danger btn-sm" href="#notification-logs"><i class="fa fa-search"></i> Apri log diagnostica invii</a></div>
          </div>

          <div class="box box-primary" id="sms-provider-global">
            <div class="box-header with-border">
              <h3 class="box-title">Provider SMS globale</h3>
            </div>
            <div class="box-body">
              <p class="text-muted">Questa configurazione è il predefinito per tutti gli spazi. Le credenziali vengono cifrate e non sono mai mostrate nuovamente.</p>
              <?php if (empty($globalSmsProvider['schema_ready'])): ?>
                <div class="alert alert-warning">Esegui la migration delle configurazioni provider SMS prima di salvare.</div>
              <?php endif; ?>
              <form method="post" action="<?= portal_platform_url('notifiche-appuntamenti/sms-provider/global/save') ?>" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="return_tenant_id" value="<?= (int) ($policyTenant['id_tenant'] ?? 0) ?>">
                <div class="row">
                  <div class="col-md-6 form-group">
                    <label>Provider predefinito</label>
                    <select class="form-control" name="provider">
                      <option value="smsfactor" <?= ($globalSmsProvider['provider'] ?? '') === 'smsfactor' ? 'selected' : '' ?>>SMSFactor</option>
                      <option value="aruba" <?= ($globalSmsProvider['provider'] ?? '') === 'aruba' ? 'selected' : '' ?>>Aruba SMS</option>
                    </select>
                  </div>
                  <div class="col-md-6 form-group">
                    <label>Mittente globale</label>
                    <input class="form-control" name="sender" maxlength="11" pattern="[A-Za-z0-9]{1,11}" required value="<?= esc((string) ($globalSmsProvider['sender'] ?? 'AmbFacile'), 'attr') ?>">
                    <small class="text-muted">Massimo 11 caratteri alfanumerici.</small>
                  </div>
                </div>
                <div class="provider-grid">
                  <section class="delivery-policy-card">
                    <h4><i class="fa fa-paper-plane"></i> SMSFactor</h4>
                    <p>Token Bearer, endpoint e firma usata per autenticare le ricevute di consegna.</p>
                    <div class="form-group">
                      <label>Token API <span class="label label-<?= !empty($globalSmsProvider['smsfactor_api_token_configured']) ? 'success' : 'warning' ?> provider-secret-status"><?= !empty($globalSmsProvider['smsfactor_api_token_configured']) ? 'configurato' : 'mancante' ?></span></label>
                      <input class="form-control" type="password" name="smsfactor_api_token" maxlength="4096" autocomplete="new-password" placeholder="<?= !empty($globalSmsProvider['smsfactor_api_token_configured']) ? 'Token già configurato — lascia vuoto per mantenerlo' : 'Inserisci il token API' ?>">
                      <?php if (!empty($globalSmsProvider['smsfactor_api_token_stored'])): ?><label class="checkbox-inline"><input type="checkbox" name="clear_smsfactor_api_token" value="1"> Rimuovi token salvato</label><?php endif; ?>
                    </div>
                    <div class="form-group">
                      <label>Endpoint API</label>
                      <input class="form-control" type="url" name="smsfactor_base_url" required value="<?= esc((string) ($globalSmsProvider['smsfactor_base_url'] ?? 'https://api.smsfactor.com'), 'attr') ?>">
                    </div>
                    <div class="delivery-policy-rate">
                      <div class="form-group"><label>Timeout secondi</label><input class="form-control" type="number" min="5" max="120" name="smsfactor_timeout_seconds" value="<?= (int) ($globalSmsProvider['smsfactor_timeout_seconds'] ?? 30) ?>"></div>
                      <div class="form-group"><label>Tipo invio</label><select class="form-control" name="smsfactor_push_type"><option value="alert" <?= ($globalSmsProvider['smsfactor_push_type'] ?? '') === 'alert' ? 'selected' : '' ?>>Alert</option><option value="marketing" <?= ($globalSmsProvider['smsfactor_push_type'] ?? '') === 'marketing' ? 'selected' : '' ?>>Marketing</option></select></div>
                    </div>
                    <div class="form-group">
                      <label>Firma webhook DLR <span class="label label-<?= !empty($globalSmsProvider['smsfactor_webhook_signature_configured']) ? 'success' : 'warning' ?> provider-secret-status"><?= !empty($globalSmsProvider['smsfactor_webhook_signature_configured']) ? 'configurata' : 'mancante' ?></span></label>
                      <input class="form-control" type="password" name="smsfactor_webhook_signature" maxlength="4096" autocomplete="new-password" placeholder="<?= !empty($globalSmsProvider['smsfactor_webhook_signature_configured']) ? 'Firma già configurata — lascia vuoto per mantenerla' : 'Inserisci il segreto del webhook' ?>">
                      <?php if (!empty($globalSmsProvider['smsfactor_webhook_signature_stored'])): ?><label class="checkbox-inline"><input type="checkbox" name="clear_smsfactor_webhook_signature" value="1"> Rimuovi firma salvata</label><?php endif; ?>
                    </div>
                  </section>
                  <section class="delivery-policy-card">
                    <h4><i class="fa fa-undo"></i> Aruba SMS</h4>
                    <p>Credenziali disponibili come provider alternativo o rollback.</p>
                    <div class="form-group">
                      <label>Username <span class="label label-<?= !empty($globalSmsProvider['aruba_username_configured']) ? 'success' : 'warning' ?> provider-secret-status"><?= !empty($globalSmsProvider['aruba_username_configured']) ? 'configurato' : 'mancante' ?></span></label>
                      <input class="form-control" type="password" name="aruba_username" maxlength="4096" autocomplete="new-password" placeholder="<?= !empty($globalSmsProvider['aruba_username_configured']) ? 'Username già configurato — lascia vuoto per mantenerlo' : 'Inserisci username Aruba' ?>">
                      <?php if (!empty($globalSmsProvider['aruba_username_stored'])): ?><label class="checkbox-inline"><input type="checkbox" name="clear_aruba_username" value="1"> Rimuovi username salvato</label><?php endif; ?>
                    </div>
                    <div class="form-group">
                      <label>Password <span class="label label-<?= !empty($globalSmsProvider['aruba_password_configured']) ? 'success' : 'warning' ?> provider-secret-status"><?= !empty($globalSmsProvider['aruba_password_configured']) ? 'configurata' : 'mancante' ?></span></label>
                      <input class="form-control" type="password" name="aruba_password" maxlength="4096" autocomplete="new-password" placeholder="<?= !empty($globalSmsProvider['aruba_password_configured']) ? 'Password già configurata — lascia vuoto per mantenerla' : 'Inserisci password Aruba' ?>">
                      <?php if (!empty($globalSmsProvider['aruba_password_stored'])): ?><label class="checkbox-inline"><input type="checkbox" name="clear_aruba_password" value="1"> Rimuovi password salvata</label><?php endif; ?>
                    </div>
                    <div class="alert alert-info" style="margin-bottom:0;">Origine attuale: <?= esc((string) ($globalSmsProvider['source'] ?? 'environment')) ?>. Le variabili ambiente restano disponibili come fallback.</div>
                  </section>
                </div>
                <div class="provider-actions"><button class="btn btn-primary" type="submit" <?= empty($globalSmsProvider['schema_ready']) ? 'disabled' : '' ?>><i class="fa fa-lock"></i> Salva provider globale</button></div>
              </form>
            </div>
          </div>

          <div class="box box-success" id="delivery-policy">
            <div class="box-header with-border">
              <h3 class="box-title">Parametri di consegna per spazio</h3>
            </div>
            <div class="box-body">
              <form method="get" action="<?= portal_platform_url('notifiche-appuntamenti') ?>" style="margin-bottom:16px;">
                <div class="row">
                  <div class="col-md-6">
                    <label for="policy-tenant-select">Spazio cliente</label>
                    <select class="form-control" id="policy-tenant-select" name="tenant_id" onchange="this.form.submit()">
                      <?php foreach ($tenantRows as $tenantRow): ?>
                        <?php $tenantOption = (array) ($tenantRow['tenant'] ?? []); ?>
                        <option value="<?= (int) ($tenantOption['id_tenant'] ?? 0) ?>" <?= (int) ($tenantOption['id_tenant'] ?? 0) === (int) ($policyTenant['id_tenant'] ?? 0) ? 'selected' : '' ?>>
                          <?= esc((string) ($tenantOption['tenant_name'] ?? '')) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-6" style="padding-top:25px;">
                    <span class="label label-<?= !empty($policy['using_defaults']) ? 'warning' : 'success' ?>">
                      <?= !empty($policy['using_defaults']) ? 'valori prudenti predefiniti' : 'configurazione personalizzata' ?>
                    </span>
                  </div>
                </div>
              </form>

              <?php if (empty($policy['schema_ready'])): ?>
                <div class="alert alert-warning">
                  Esegui la migration delle politiche di notifica prima di salvare. I valori mostrati sono i default sicuri e non sono ancora persistiti.
                </div>
              <?php endif; ?>
              <?php if ($policyTenant): ?>
                <form method="post" action="<?= portal_platform_url('notifiche-appuntamenti/sms-provider/tenant/save') ?>" autocomplete="off" style="margin-bottom:18px;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="tenant_id" value="<?= (int) ($policyTenant['id_tenant'] ?? 0) ?>">
                  <section class="delivery-policy-card">
                    <h4><i class="fa fa-building"></i> Account SMS dello spazio: <?= esc((string) ($policyTenant['tenant_name'] ?? '')) ?></h4>
                    <p>Scegli se usare le credenziali globali oppure un account provider dedicato. Anche in modalità ereditata puoi assegnare un mittente diverso allo spazio.</p>
                    <?php if (empty($tenantSmsProvider['schema_ready'])): ?><div class="alert alert-warning">Schema configurazioni SMS non ancora disponibile.</div><?php endif; ?>
                    <div class="row">
                      <div class="col-md-4 form-group">
                        <label>Modalità account</label>
                        <select class="form-control" name="mode">
                          <option value="inherit" <?= ($tenantSmsProvider['mode'] ?? 'inherit') === 'inherit' ? 'selected' : '' ?>>Eredita credenziali globali</option>
                          <option value="custom" <?= ($tenantSmsProvider['mode'] ?? '') === 'custom' ? 'selected' : '' ?>>Account dedicato</option>
                        </select>
                      </div>
                      <div class="col-md-4 form-group">
                        <label>Provider se dedicato</label>
                        <select class="form-control" name="provider">
                          <option value="smsfactor" <?= ($tenantSmsProvider['provider'] ?? '') === 'smsfactor' ? 'selected' : '' ?>>SMSFactor</option>
                          <option value="aruba" <?= ($tenantSmsProvider['provider'] ?? '') === 'aruba' ? 'selected' : '' ?>>Aruba SMS</option>
                        </select>
                      </div>
                      <div class="col-md-4 form-group">
                        <label>Mittente dello spazio</label>
                        <input class="form-control" name="sender" maxlength="11" pattern="[A-Za-z0-9]{1,11}" required value="<?= esc((string) ($tenantSmsProvider['sender'] ?? $policy['sms']['sender'] ?? 'AmbFacile'), 'attr') ?>">
                      </div>
                    </div>
                    <div class="provider-grid">
                      <div>
                        <h5><strong>Credenziali SMSFactor dedicate</strong></h5>
                        <div class="form-group"><label>Token API <?= !empty($tenantSmsProvider['smsfactor_api_token_stored']) ? '<span class="label label-success">salvato per lo spazio</span>' : '<span class="label label-default">non salvato</span>' ?></label><input class="form-control" type="password" name="smsfactor_api_token" maxlength="4096" autocomplete="new-password" placeholder="Lascia vuoto per mantenere il valore"><?php if (!empty($tenantSmsProvider['smsfactor_api_token_stored'])): ?><label class="checkbox-inline"><input type="checkbox" name="clear_smsfactor_api_token" value="1"> Rimuovi token dedicato</label><?php endif; ?></div>
                        <div class="form-group"><label>Endpoint API</label><input class="form-control" type="url" name="smsfactor_base_url" value="<?= esc((string) ($tenantSmsProvider['smsfactor_base_url'] ?? 'https://api.smsfactor.com'), 'attr') ?>"></div>
                        <div class="delivery-policy-rate"><div class="form-group"><label>Timeout</label><input class="form-control" type="number" min="5" max="120" name="smsfactor_timeout_seconds" value="<?= (int) ($tenantSmsProvider['smsfactor_timeout_seconds'] ?? 30) ?>"></div><div class="form-group"><label>Tipo</label><select class="form-control" name="smsfactor_push_type"><option value="alert" <?= ($tenantSmsProvider['smsfactor_push_type'] ?? '') === 'alert' ? 'selected' : '' ?>>Alert</option><option value="marketing" <?= ($tenantSmsProvider['smsfactor_push_type'] ?? '') === 'marketing' ? 'selected' : '' ?>>Marketing</option></select></div></div>
                        <div class="form-group"><label>Firma webhook <?= !empty($tenantSmsProvider['smsfactor_webhook_signature_stored']) ? '<span class="label label-success">salvata per lo spazio</span>' : '<span class="label label-default">non salvata</span>' ?></label><input class="form-control" type="password" name="smsfactor_webhook_signature" maxlength="4096" autocomplete="new-password" placeholder="Lascia vuoto per mantenere il valore"><?php if (!empty($tenantSmsProvider['smsfactor_webhook_signature_stored'])): ?><label class="checkbox-inline"><input type="checkbox" name="clear_smsfactor_webhook_signature" value="1"> Rimuovi firma dedicata</label><?php endif; ?></div>
                      </div>
                      <div>
                        <h5><strong>Credenziali Aruba dedicate</strong></h5>
                        <div class="form-group"><label>Username <?= !empty($tenantSmsProvider['aruba_username_stored']) ? '<span class="label label-success">salvato per lo spazio</span>' : '<span class="label label-default">non salvato</span>' ?></label><input class="form-control" type="password" name="aruba_username" maxlength="4096" autocomplete="new-password" placeholder="Lascia vuoto per mantenere il valore"><?php if (!empty($tenantSmsProvider['aruba_username_stored'])): ?><label class="checkbox-inline"><input type="checkbox" name="clear_aruba_username" value="1"> Rimuovi username dedicato</label><?php endif; ?></div>
                        <div class="form-group"><label>Password <?= !empty($tenantSmsProvider['aruba_password_stored']) ? '<span class="label label-success">salvata per lo spazio</span>' : '<span class="label label-default">non salvata</span>' ?></label><input class="form-control" type="password" name="aruba_password" maxlength="4096" autocomplete="new-password" placeholder="Lascia vuoto per mantenere il valore"><?php if (!empty($tenantSmsProvider['aruba_password_stored'])): ?><label class="checkbox-inline"><input type="checkbox" name="clear_aruba_password" value="1"> Rimuovi password dedicata</label><?php endif; ?></div>
                        <div class="alert alert-info">In modalità “Eredita” queste credenziali non vengono usate. Per più spazi sullo stesso account è sufficiente cambiare il mittente.</div>
                      </div>
                    </div>
                    <div class="provider-actions"><button class="btn btn-primary" type="submit" <?= empty($tenantSmsProvider['schema_ready']) ? 'disabled' : '' ?>><i class="fa fa-save"></i> Salva account SMS spazio</button></div>
                  </section>
                </form>
              <?php endif; ?>
              <div class="alert alert-info">
                I limiti sono per spazio, ma la reputazione email appartiene al dominio condiviso ambulatoriofacile.it: controlla anche il volume complessivo di tutti gli spazi e aumenta gradualmente i tetti.
              </div>

              <?php if ($policyTenant): ?>
                <form method="post" action="<?= portal_platform_url('notifiche-appuntamenti/policy/save') ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="tenant_id" value="<?= (int) ($policyTenant['id_tenant'] ?? 0) ?>">
                  <div class="delivery-policy-grid">
                    <section class="delivery-policy-card">
                      <h4><i class="fa fa-envelope"></i> Email</h4>
                      <p>Identità AmbulatorioFacile senza risposta e cadenza massima per i batch e le future campagne email.</p>
                      <div class="form-group">
                        <label>Indirizzo mittente</label>
                        <input class="form-control" type="email" name="email[from_address]" required value="<?= esc((string) $policyValue('email', 'from_address', $policy['email']['from_address'] ?? 'noreply@ambulatoriofacile.it'), 'attr') ?>">
                        <small class="text-muted">È accettato esclusivamente il dominio @ambulatoriofacile.it.</small>
                      </div>
                      <div class="form-group">
                        <label>Nome visualizzato</label>
                        <input class="form-control" name="email[from_name]" maxlength="120" required value="<?= esc((string) $policyValue('email', 'from_name', $policy['email']['from_name'] ?? ''), 'attr') ?>">
                      </div>
                      <div class="form-group">
                        <label>Reply-To bloccato</label>
                        <div class="policy-fixed-value">noreply@ambulatoriofacile.it</div>
                      </div>
                      <div class="form-group">
                        <label>Prefisso oggetto</label>
                        <input class="form-control" name="email[subject_prefix]" maxlength="60" value="<?= esc((string) $policyValue('email', 'subject_prefix', $policy['email']['subject_prefix'] ?? ''), 'attr') ?>">
                      </div>
                      <hr>
                      <input type="hidden" name="email[smtp_enabled]" value="0">
                      <div class="checkbox">
                        <label><input type="checkbox" name="email[smtp_enabled]" value="1" <?= !empty($policyValue('email', 'smtp_enabled', $policy['email']['smtp_enabled'] ?? false)) ? 'checked' : '' ?>> Usa SMTP dedicato per questo spazio</label>
                      </div>
                      <div class="form-group">
                        <label>Host SMTP</label>
                        <input class="form-control" name="email[smtp_host]" maxlength="190" autocomplete="off" placeholder="smtps.aruba.it" value="<?= esc((string) $policyValue('email', 'smtp_host', $policy['email']['smtp_host'] ?? ''), 'attr') ?>">
                      </div>
                      <div class="delivery-policy-rate">
                        <div class="form-group"><label>Porta SMTP</label><input class="form-control" type="number" min="1" max="65535" name="email[smtp_port]" value="<?= (int) $policyValue('email', 'smtp_port', $policy['email']['smtp_port'] ?? 587) ?>"></div>
                        <div class="form-group"><label>Sicurezza</label><select class="form-control" name="email[smtp_crypto]"><?php $smtpCrypto = (string) $policyValue('email', 'smtp_crypto', $policy['email']['smtp_crypto'] ?? 'tls'); ?><option value="tls" <?= $smtpCrypto === 'tls' ? 'selected' : '' ?>>STARTTLS</option><option value="ssl" <?= $smtpCrypto === 'ssl' ? 'selected' : '' ?>>SSL</option><option value="none" <?= $smtpCrypto === '' || $smtpCrypto === 'none' ? 'selected' : '' ?>>Nessuna</option></select></div>
                      </div>
                      <div class="form-group">
                        <label>Username SMTP</label>
                        <input class="form-control" name="email[smtp_username]" maxlength="190" autocomplete="off" value="<?= esc((string) $policyValue('email', 'smtp_username', $policy['email']['smtp_username'] ?? ''), 'attr') ?>">
                      </div>
                      <div class="form-group">
                        <label>Password SMTP</label>
                        <input class="form-control" type="password" name="email[smtp_password]" maxlength="1024" autocomplete="new-password" placeholder="<?= !empty($policy['email']['smtp_password_configured']) ? 'Password già configurata' : 'Inserisci la password SMTP' ?>">
                        <small class="text-muted"><?= !empty($policy['email']['smtp_password_configured']) ? 'La password è cifrata. Lascia vuoto per mantenerla invariata.' : 'La password verrà salvata cifrata e non sarà più mostrata.' ?></small>
                      </div>
                      <div class="form-group">
                        <label>Timeout SMTP (secondi)</label>
                        <input class="form-control" type="number" min="1" max="60" name="email[smtp_timeout_seconds]" value="<?= (int) $policyValue('email', 'smtp_timeout_seconds', $policy['email']['smtp_timeout_seconds'] ?? 10) ?>">
                      </div>
                      <div class="delivery-policy-rate">
                        <div class="form-group"><label>Email</label><input class="form-control" type="number" min="1" max="100" name="email[messages_per_interval]" value="<?= (int) $policyValue('email', 'messages_per_interval', $policy['email']['messages_per_interval'] ?? 10) ?>"></div>
                        <div class="form-group"><label>Ogni minuti</label><input class="form-control" type="number" min="1" max="1440" name="email[interval_minutes]" value="<?= (int) $policyValue('email', 'interval_minutes', $policy['email']['interval_minutes'] ?? 5) ?>"></div>
                        <div class="form-group daily-limit"><label>Massimo al giorno</label><input class="form-control" type="number" min="1" max="5000" name="email[daily_limit]" value="<?= (int) $policyValue('email', 'daily_limit', $policy['email']['daily_limit'] ?? 500) ?>"></div>
                      </div>
                    </section>

                    <section class="delivery-policy-card">
                      <h4><i class="fa fa-whatsapp"></i> WhatsApp</h4>
                      <p>Il canale attivo deve essere instradato al gateway AmbulatorioFacile; la coda distribuisce gli invii nel tempo.</p>
                      <div class="delivery-policy-rate">
                        <div class="form-group"><label>Massimo messaggi</label><input class="form-control" type="number" min="1" max="30" name="whatsapp[messages_per_interval]" value="<?= (int) $policyValue('whatsapp', 'messages_per_interval', $policy['whatsapp']['messages_per_interval'] ?? 1) ?>"></div>
                        <div class="form-group"><label>Ogni minuti</label><input class="form-control" type="number" min="1" max="1440" name="whatsapp[interval_minutes]" value="<?= (int) $policyValue('whatsapp', 'interval_minutes', $policy['whatsapp']['interval_minutes'] ?? 5) ?>"></div>
                        <div class="form-group daily-limit"><label>Massimo al giorno</label><input class="form-control" type="number" min="1" max="2000" name="whatsapp[daily_limit]" value="<?= (int) $policyValue('whatsapp', 'daily_limit', $policy['whatsapp']['daily_limit'] ?? 250) ?>"></div>
                      </div>
                      <input type="hidden" name="whatsapp[sms_fallback_enabled]" value="0">
                      <div class="checkbox">
                        <label><input type="checkbox" name="whatsapp[sms_fallback_enabled]" value="1" <?= !empty($policyValue('whatsapp', 'sms_fallback_enabled', $policy['whatsapp']['sms_fallback_enabled'] ?? true)) ? 'checked' : '' ?>> Se non risulta consegnato, usa SMS</label>
                      </div>
                      <div class="form-group">
                        <label>Attesa consegna prima del fallback (minuti)</label>
                        <input class="form-control" type="number" min="5" max="1440" name="whatsapp[fallback_after_minutes]" value="<?= (int) $policyValue('whatsapp', 'fallback_after_minutes', $policy['whatsapp']['fallback_after_minutes'] ?? 30) ?>">
                        <small class="text-muted">L’SMS parte solo se WhatsApp non risulta consegnato o letto entro questo termine.</small>
                      </div>
                    </section>

                    <section class="delivery-policy-card">
                      <h4><i class="fa fa-comment"></i> SMS</h4>
                      <p>Mittente, capacità e tetto di sicurezza applicati ai fallback e alle future campagne massive.</p>
                      <div class="form-group">
                        <label>Mittente SMS</label>
                        <input type="hidden" name="sms[sender]" value="<?= esc((string) ($tenantSmsProvider['sender'] ?? $policy['sms']['sender'] ?? 'AmbFacile'), 'attr') ?>">
                        <div class="policy-fixed-value"><?= esc((string) ($tenantSmsProvider['sender'] ?? $policy['sms']['sender'] ?? 'AmbFacile')) ?></div>
                        <small class="text-muted">Gestito nel riquadro “Account SMS dello spazio”.</small>
                      </div>
                      <div class="delivery-policy-rate">
                        <div class="form-group"><label>SMS</label><input class="form-control" type="number" min="1" max="100" name="sms[messages_per_interval]" value="<?= (int) $policyValue('sms', 'messages_per_interval', $policy['sms']['messages_per_interval'] ?? 10) ?>"></div>
                        <div class="form-group"><label>Ogni minuti</label><input class="form-control" type="number" min="1" max="1440" name="sms[interval_minutes]" value="<?= (int) $policyValue('sms', 'interval_minutes', $policy['sms']['interval_minutes'] ?? 5) ?>"></div>
                        <div class="form-group daily-limit"><label>Massimo al giorno</label><input class="form-control" type="number" min="1" max="5000" name="sms[daily_limit]" value="<?= (int) $policyValue('sms', 'daily_limit', $policy['sms']['daily_limit'] ?? 500) ?>"></div>
                      </div>
                    </section>
                  </div>
                  <div style="display:flex; justify-content:space-between; gap:16px; align-items:center; margin-top:16px;">
                    <p class="text-muted" style="margin:0;">Gli stessi limiti governano i batch automatici, le campagne WhatsApp e i fallback SMS; costituiscono inoltre il meccanismo comune per le future campagne email/SMS. La singola conferma appena creato un appuntamento resta immediata.</p>
                    <button class="btn btn-success" type="submit" <?= empty($policy['schema_ready']) ? 'disabled' : '' ?>><i class="fa fa-save"></i> Salva parametri spazio</button>
                  </div>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <div class="row">
            <div class="col-md-3 col-sm-6">
              <div class="metric-card">
                <div class="metric-label">Invii ultimi <?= $days ?> giorni</div>
                <div class="metric-value"><?= (int) ($summary['recent_sent'] ?? 0) ?></div>
                <div class="metric-helper">Totale inviato da tutti gli studi attivi.</div>
              </div>
            </div>
            <div class="col-md-3 col-sm-6">
              <div class="metric-card">
                <div class="metric-label">SMS ultimi <?= $days ?> giorni</div>
                <div class="metric-value"><?= (int) ($summary['recent_sms_sent'] ?? 0) ?></div>
                <div class="metric-helper">Solo invii sul canale SMS.</div>
              </div>
            </div>
            <div class="col-md-3 col-sm-6">
              <div class="metric-card">
                <div class="metric-label">WA ultimi <?= $days ?> giorni</div>
                <div class="metric-value"><?= (int) ($summary['recent_wa_sent'] ?? 0) ?></div>
                <div class="metric-helper">Solo invii sul canale WhatsApp.</div>
              </div>
            </div>
            <div class="col-md-3 col-sm-6">
              <div class="metric-card">
                <div class="metric-label">Filtro storico</div>
                <div class="metric-value" style="font-size:22px;"><?= (int) $days ?> giorni</div>
                <div class="metric-helper">
                  <a href="<?= portal_platform_url('notifiche-appuntamenti') ?>?days=30">30</a> |
                  <a href="<?= portal_platform_url('notifiche-appuntamenti') ?>?days=60">60</a> |
                  <a href="<?= portal_platform_url('notifiche-appuntamenti') ?>?days=90">90</a>
                </div>
              </div>
            </div>
          </div>

          <div class="box box-primary">
            <div class="box-header with-border">
              <h3 class="box-title">Lancio reminder dal pannello</h3>
            </div>
            <div class="box-body">
              <form method="post" action="<?= portal_platform_url('notifiche-appuntamenti/launch') ?>">
                <?= csrf_field() ?>
                <div class="row">
                  <div class="col-md-2">
                    <label>Modalità</label>
                    <select class="form-control" name="mode">
                      <option value="dry-run">Dry-run</option>
                      <option value="send">Invio reale</option>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label>Studio</label>
                    <select class="form-control" name="tenant_id">
                      <option value="0">Tutti gli studi attivi</option>
                      <?php foreach ($tenantRows as $tenantRow): ?>
                        <?php $tenant = (array) ($tenantRow['tenant'] ?? []); ?>
                        <option value="<?= (int) ($tenant['id_tenant'] ?? 0) ?>"><?= esc((string) ($tenant['tenant_name'] ?? '')) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label>Data target</label>
                    <input class="form-control" type="date" name="target_date">
                  </div>
                  <div class="col-md-2">
                    <label>Canale</label>
                    <select class="form-control" name="channel">
                      <option value="auto">Automatico dallo studio</option>
                      <option value="sms">Solo SMS</option>
                      <option value="wa">Solo WA</option>
                      <option value="email">Solo Email</option>
                      <option value="otp">Solo OTP</option>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label>Destinatario forzato</label>
                    <input class="form-control" type="text" name="force_recipient" placeholder="+39333... oppure demo@email.it">
                  </div>
                </div>
                <div class="row" style="margin-top:12px;">
                  <div class="col-md-2">
                    <label>Override delay ms</label>
                    <input class="form-control" type="number" name="delay_ms" value="0" min="0">
                    <small class="text-muted">0 usa i parametri dello spazio.</small>
                  </div>
                  <div class="col-md-2">
                    <label>Limite</label>
                    <input class="form-control" type="number" name="limit" value="0" min="0">
                  </div>
                  <div class="col-md-3">
                    <label>Filtro dottori</label>
                    <input class="form-control" type="text" name="doctor" placeholder="67,88">
                  </div>
                  <div class="col-md-5" style="padding-top:25px;">
                    <button class="btn btn-primary" type="submit">
                      <i class="fa fa-play"></i> Esegui reminder
                    </button>
                  </div>
                </div>
              </form>
            </div>
            <?php if ($launchFeedback): ?>
              <div class="box-footer">
                <strong>Ultimo lancio:</strong>
                modalità `<?= esc((string) ($launchFeedback['mode'] ?? 'n/d')) ?>`,
                studi processati `<?= (int) ($launchFeedback['processed_tenants'] ?? 0) ?>`,
                candidati `<?= (int) ($launchFeedback['candidates'] ?? 0) ?>`,
                inviati `<?= (int) ($launchFeedback['sent'] ?? 0) ?>`,
                errori `<?= (int) ($launchFeedback['failed'] ?? 0) ?>`.
                Differiti per limite `<?= (int) ($launchFeedback['deferred'] ?? 0) ?>`.
              </div>
            <?php endif; ?>
          </div>

          <div class="box box-default">
            <div class="box-header with-border">
              <h3 class="box-title">Stato studi e configurazioni attive</h3>
            </div>
            <div class="box-body table-responsive">
              <table class="table table-bordered table-hover">
                <thead>
                  <tr>
                    <th>Studio</th>
                    <th>Canali disponibili</th>
                    <th>Configurazione responsabile dello studio</th>
                    <th>Invii ultimi <?= (int) $days ?> giorni</th>
                    <th>Ultimo invio</th>
                    <th style="width:170px;">Azioni</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($tenantRows === []): ?>
                    <tr><td colspan="6" class="text-muted">Nessuno studio attivo trovato.</td></tr>
                  <?php else: ?>
                    <?php foreach ($tenantRows as $row): ?>
                      <?php
                        $tenant = (array) ($row['tenant'] ?? []);
                        $tenantSettings = (array) ($row['settings'] ?? []);
                        $tenantSummary = (array) ($row['summary'] ?? []);
                        $availableChannels = (array) ($tenantSettings['available_channels'] ?? []);
                        $messageTypes = (array) ($tenantSettings['message_types'] ?? []);
                      ?>
                      <tr>
                        <td>
                          <strong><?= esc((string) ($tenant['tenant_name'] ?? '')) ?></strong><br>
                          <span class="text-muted"><?= esc((string) ($tenant['package_name'] ?? ($tenant['package_code'] ?? ''))) ?></span>
                        </td>
                        <td>
                          <span class="label label-<?= !empty($tenantSettings['module']['available']) ? 'success' : 'default' ?>">
                            modulo <?= !empty($tenantSettings['module']['available']) ? 'attivo' : 'spento' ?>
                          </span><br>
                          <span class="label label-<?= !empty($availableChannels['sms']) ? 'success' : 'default' ?>">SMS</span>
                          <span class="label label-<?= !empty($availableChannels['wa']) ? 'success' : 'default' ?>">WhatsApp</span>
                          <span class="label label-<?= !empty($availableChannels['email']) ? 'success' : 'default' ?>">Email</span>
                          <span class="label label-<?= !empty($availableChannels['otp']) ? 'success' : 'default' ?>">OTP</span>
                        </td>
                        <td>
                          <ul class="tenant-config-list">
                            <?php foreach ($messageTypes as $key => $typeRow): ?>
                              <?php
                                $channels = (array) ($typeRow['effective_channels'] ?? []);
                                $label = $typeLabels[$key] ?? $key;
                                $channelLabels = [];
                                foreach ($channels as $channelKey) {
                                    $channelLabels[] = $channelMeta[$channelKey]['label'] ?? strtoupper((string) $channelKey);
                                }
                              ?>
                              <li>
                                <?= esc($label) ?>:
                                <?= !empty($typeRow['enabled']) ? esc(implode(' + ', $channelLabels !== [] ? $channelLabels : ['nessun canale'])) : 'off' ?>
                                <?php if ($key === \App\Services\AppointmentNotificationSettingsService::TYPE_REMINDER): ?>
                                  (<?= (int) ($typeRow['lead_days'] ?? 0) ?> gg)
                                <?php endif; ?>
                              </li>
                            <?php endforeach; ?>
                          </ul>
                        </td>
                        <td>
                          <strong><?= (int) ($tenantSummary['recent_sent'] ?? 0) ?></strong><br>
                          <span class="text-muted">
                            SMS <?= (int) ($tenantSummary['sms_recent'] ?? 0) ?> |
                            WA <?= (int) ($tenantSummary['wa_recent'] ?? 0) ?> |
                            Email <?= (int) ($tenantSummary['email_recent'] ?? 0) ?> |
                            OTP <?= (int) ($tenantSummary['otp_recent'] ?? 0) ?>
                          </span>
                        </td>
                        <td><?= esc($formatDateTime((string) ($tenantSummary['last_sent_at'] ?? ''))) ?></td>
                        <td>
                          <a class="btn btn-xs btn-primary" href="<?= portal_platform_url('notifiche-appuntamenti') ?>?tenant_id=<?= (int) ($tenant['id_tenant'] ?? 0) ?>#delivery-policy">
                            <i class="fa fa-sliders"></i> Parametri
                          </a>
                          <a class="btn btn-xs btn-default" href="<?= portal_platform_url('spazi-clienti') ?>?id_tenant=<?= (int) ($tenant['id_tenant'] ?? 0) ?>">
                            <i class="fa fa-sitemap"></i> Spazio
                          </a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="box box-default" id="notification-logs">
            <div class="box-header with-border">
              <h3 class="box-title"><i class="fa fa-search"></i> Log diagnostica invii</h3>
            </div>
            <div class="box-body">
              <p class="text-muted">Qui trovi ogni tentativo effettuato da Email, WhatsApp, SMS e OTP. “Accettato” significa che il provider ha preso in carico il messaggio; “Consegnato” richiede invece una ricevuta finale.</p>
              <?php if (!$logStorageReady): ?><div class="alert alert-warning">Archivio centrale non ancora disponibile: applica la migration. Nel frattempo sono mostrati soltanto i log locali accessibili da questo ambiente.</div><?php endif; ?>

              <form class="notification-log-filters" method="get" action="<?= portal_platform_url('notifiche-appuntamenti') ?>">
                <input type="hidden" name="tenant_id" value="<?= (int) ($policyTenant['id_tenant'] ?? 0) ?>">
                <div class="row">
                  <div class="col-md-2 form-group">
                    <label>Periodo</label>
                    <select class="form-control" name="days">
                      <?php foreach ([1 => 'Oggi', 7 => '7 giorni', 30 => '30 giorni', 60 => '60 giorni', 90 => '90 giorni', 180 => '180 giorni', 365 => '1 anno'] as $dayValue => $dayLabel): ?>
                        <option value="<?= $dayValue ?>" <?= $days === $dayValue ? 'selected' : '' ?>><?= esc($dayLabel) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3 form-group">
                    <label>Spazio</label>
                    <select class="form-control" name="log_tenant_id">
                      <option value="0">Tutti gli spazi</option>
                      <?php foreach ($tenantRows as $tenantRow): ?>
                        <?php $logTenant = (array) ($tenantRow['tenant'] ?? []); ?>
                        <option value="<?= (int) ($logTenant['id_tenant'] ?? 0) ?>" <?= (int) ($logFilters['tenant_id'] ?? 0) === (int) ($logTenant['id_tenant'] ?? 0) ? 'selected' : '' ?>><?= esc((string) ($logTenant['tenant_name'] ?? '')) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-2 form-group">
                    <label>Canale</label>
                    <select class="form-control" name="log_channel">
                      <option value="">Tutti</option>
                      <?php foreach ($channelMeta as $channelKey => $channelRow): ?>
                        <option value="<?= esc($channelKey, 'attr') ?>" <?= ($logFilters['channel'] ?? '') === $channelKey ? 'selected' : '' ?>><?= esc((string) ($channelRow['label'] ?? strtoupper($channelKey))) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-2 form-group">
                    <label>Esito</label>
                    <select class="form-control" name="log_status">
                      <option value="">Tutti</option>
                      <option value="problem" <?= ($logFilters['status'] ?? '') === 'problem' ? 'selected' : '' ?>>Solo problemi</option>
                      <option value="accepted" <?= ($logFilters['status'] ?? '') === 'accepted' ? 'selected' : '' ?>>Accettati</option>
                      <option value="delivered" <?= ($logFilters['status'] ?? '') === 'delivered' ? 'selected' : '' ?>>Consegnati/letti</option>
                      <option value="pending" <?= ($logFilters['status'] ?? '') === 'pending' ? 'selected' : '' ?>>In attesa/rinviati</option>
                      <option value="skipped" <?= ($logFilters['status'] ?? '') === 'skipped' ? 'selected' : '' ?>>Saltati</option>
                    </select>
                  </div>
                  <div class="col-md-3 form-group">
                    <label>Flusso</label>
                    <select class="form-control" name="log_message_type">
                      <option value="">Tutti i flussi</option>
                      <?php foreach ($typeLabels as $typeKey => $typeLabel): ?>
                        <option value="<?= esc($typeKey, 'attr') ?>" <?= ($logFilters['message_type'] ?? '') === $typeKey ? 'selected' : '' ?>><?= esc($typeLabel) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="row">
                  <div class="col-md-8 form-group" style="margin-bottom:0;">
                    <label>Cerca</label>
                    <input class="form-control" name="log_query" maxlength="120" value="<?= esc((string) ($logFilters['query'] ?? ''), 'attr') ?>" placeholder="Destinatario, paziente, provider, errore o ID">
                  </div>
                  <div class="col-md-2 form-group" style="margin-bottom:0;">
                    <label>Righe</label>
                    <select class="form-control" name="log_limit">
                      <?php foreach ([50, 100, 250, 500] as $limitValue): ?><option value="<?= $limitValue ?>" <?= (int) ($logFilters['limit'] ?? 100) === $limitValue ? 'selected' : '' ?>><?= $limitValue ?></option><?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-2" style="padding-top:25px; display:flex; gap:6px;">
                    <button class="btn btn-primary" type="submit"><i class="fa fa-filter"></i> Filtra</button>
                    <a class="btn btn-default" href="<?= portal_platform_url('notifiche-appuntamenti') ?>?tenant_id=<?= (int) ($policyTenant['id_tenant'] ?? 0) ?>#notification-logs" title="Azzera filtri"><i class="fa fa-undo"></i></a>
                  </div>
                </div>
              </form>

              <div class="notification-log-summary">
                <div><span class="text-muted">Risultati</span><strong><?= (int) ($logSummary['total'] ?? 0) ?></strong></div>
                <div><span class="text-muted">Accettati</span><strong><?= (int) ($logSummary['accepted'] ?? 0) ?></strong></div>
                <div><span class="text-muted">Consegnati</span><strong><?= (int) ($logSummary['delivered'] ?? 0) ?></strong></div>
                <div><span class="text-muted">Problemi</span><strong class="text-danger"><?= (int) ($logSummary['problems'] ?? 0) ?></strong></div>
                <div><span class="text-muted">In attesa/saltati</span><strong><?= (int) ($logSummary['pending'] ?? 0) + (int) ($logSummary['skipped'] ?? 0) ?></strong></div>
              </div>

              <?php if ($recentRows === []): ?>
                <div class="alert alert-warning" style="margin-bottom:0;">
                  Nessun tentativo corrisponde ai filtri. Se hai appena attivato Email ma non compare alcuna riga, verifica che il flusso specifico sia abilitato e che l’evento (creazione o reminder) sia stato realmente eseguito.
                </div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-bordered table-hover">
                    <thead>
                      <tr>
                        <th>Quando</th>
                        <th>Spazio / flusso</th>
                        <th>Canale / destinatario</th>
                        <th>Provider</th>
                        <th>Esito</th>
                        <th>Diagnostica</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($recentRows as $entry): ?>
                        <?php
                          $effectiveStatus = (string) ($entry['effective_status'] ?? $entry['status'] ?? 'pending');
                          $statusGroup = (string) ($entry['status_group'] ?? 'pending');
                          $statusClass = in_array($statusGroup, ['accepted', 'delivered'], true)
                            ? 'success'
                            : ($statusGroup === 'problem' ? 'danger' : ($statusGroup === 'skipped' ? 'default' : 'warning'));
                          $channel = (string) ($entry['channel'] ?? '');
                        ?>
                        <tr class="<?= $statusGroup === 'problem' ? 'danger' : '' ?>">
                          <td style="white-space:nowrap;">
                            <?= esc($formatDateTime((string) ($entry['created_at'] ?? ''))) ?><br>
                            <small class="text-muted"><?= esc((string) ($entry['source'] ?? 'runtime')) ?></small>
                          </td>
                          <td>
                            <strong><?= esc((string) ($entry['tenant_name'] ?? '')) ?></strong><br>
                            <?= esc((string) ($typeLabels[$entry['message_type'] ?? ''] ?? ($entry['message_type'] ?? 'Notifica'))) ?>
                            <?php if (!empty($entry['appointment_id'])): ?><br><small class="text-muted">Appuntamento #<?= (int) $entry['appointment_id'] ?></small><?php endif; ?>
                          </td>
                          <td>
                            <span class="label label-info"><?= esc((string) ($channelMeta[$channel]['label'] ?? ($channel !== '' ? strtoupper($channel) : 'Sistema'))) ?></span><br>
                            <?= esc((string) ($entry['recipient'] ?? '')) ?>
                            <?php if (!empty($entry['patient_label'])): ?><br><small class="text-muted"><?= esc((string) $entry['patient_label']) ?></small><?php endif; ?>
                          </td>
                          <td>
                            <?= esc((string) ($entry['provider'] ?? '-')) ?>
                            <?php if (!empty($entry['provider_id'])): ?><br><small class="text-muted" style="word-break:break-all;">ID: <?= esc((string) $entry['provider_id']) ?></small><?php endif; ?>
                          </td>
                          <td><span class="label label-<?= $statusClass ?>"><?= esc((string) ($logStatusLabels[$effectiveStatus] ?? $effectiveStatus)) ?></span></td>
                          <td class="notification-log-diagnostic">
                            <?= esc((string) ($entry['diagnostic_message'] ?? '')) ?>
                            <?php if (!empty($entry['scheduled_for'])): ?><br><small class="text-muted">Previsto: <?= esc((string) $entry['scheduled_for']) ?></small><?php endif; ?>
                            <?php if (!empty($entry['response_preview'])): ?>
                              <details class="notification-log-technical">
                                <summary>Risposta tecnica del provider</summary>
                                <pre><?= esc((string) $entry['response_preview']) ?></pre>
                              </details>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <?php if ($logTruncated): ?>
                  <div class="alert alert-info" style="margin-bottom:0;">Mostrate le prime <?= (int) ($logFilters['limit'] ?? count($recentRows)) ?> righe su <?= $logTotalMatching ?> risultati. Aumenta “Righe” o restringi i filtri.</div>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
</div>

<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
</body>
</html>
