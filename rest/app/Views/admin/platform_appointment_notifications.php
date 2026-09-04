<?php
helper('portal');

$dashboard = is_array($dashboard ?? null) ? $dashboard : [];
$summary = is_array($dashboard['summary'] ?? null) ? $dashboard['summary'] : [];
$tenantRows = is_array($dashboard['tenant_rows'] ?? null) ? $dashboard['tenant_rows'] : [];
$recentRows = is_array($dashboard['recent_rows'] ?? null) ? $dashboard['recent_rows'] : [];
$errors = is_array($errors ?? null) ? $errors : [];
$launchFeedback = is_array($launchFeedback ?? null) ? $launchFeedback : null;
$days = (int) ($days ?? 30);
$policy = is_array($policy ?? null) ? $policy : [];
$policyTenant = is_array($policyTenant ?? null) ? $policyTenant : null;
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
    @media (max-width: 991px) { .delivery-policy-grid { grid-template-columns:1fr; } .delivery-policy-card > p { min-height:0; } }
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

          <div class="intro-box">
            <h3 style="margin-top:0; margin-bottom:8px;">Regia unica dei canali</h3>
            <p style="margin:0 0 12px 0; color:#52676c;">
              Il responsabile della piattaforma decide quali studi hanno acquistato SMS e/o WhatsApp. Il responsabile dello studio, dentro il suo spazio, sceglie invece per quali flussi usare SMS, WhatsApp, Email e OTP.
            </p>
            <span class="status-chip">Studi attivi: <?= (int) ($summary['tenant_count'] ?? 0) ?></span>
            <span class="status-chip">Modulo attivo: <?= (int) ($summary['module_enabled_count'] ?? 0) ?></span>
            <span class="status-chip">Canale SMS: <?= (int) ($summary['sms_enabled_count'] ?? 0) ?></span>
            <span class="status-chip">Canale WhatsApp: <?= (int) ($summary['wa_enabled_count'] ?? 0) ?></span>
            <span class="status-chip">Canale Email: <?= (int) ($summary['email_enabled_count'] ?? 0) ?></span>
            <span class="status-chip">Canale OTP: <?= (int) ($summary['otp_enabled_count'] ?? 0) ?></span>
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
                        <input class="form-control" name="sms[sender]" maxlength="11" pattern="[A-Za-z0-9]{1,11}" required value="<?= esc((string) $policyValue('sms', 'sender', $policy['sms']['sender'] ?? 'AmbFacile'), 'attr') ?>">
                        <small class="text-muted">Massimo 11 caratteri alfanumerici.</small>
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

          <div class="box box-default">
            <div class="box-header with-border">
              <h3 class="box-title">Cronologia invii recente</h3>
            </div>
            <div class="box-body table-responsive">
              <table class="table table-bordered table-striped">
                <thead>
                  <tr>
                    <th>Quando</th>
                    <th>Studio</th>
                    <th>Flusso</th>
                    <th>Canale</th>
                    <th>Destinatario</th>
                    <th>Paziente</th>
                    <th>Esito</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($recentRows === []): ?>
                    <tr><td colspan="7" class="text-muted">Nessuno storico disponibile.</td></tr>
                  <?php else: ?>
                    <?php foreach ($recentRows as $entry): ?>
                      <tr>
                        <td><?= esc($formatDateTime((string) ($entry['created_at'] ?? ''))) ?></td>
                        <td><?= esc((string) ($entry['tenant_name'] ?? '')) ?></td>
                        <td><?= esc((string) ($typeLabels[$entry['message_type'] ?? ''] ?? ($entry['message_type'] ?? ''))) ?></td>
                        <td><?= esc((string) ($channelMeta[$entry['channel'] ?? '']['label'] ?? strtoupper((string) ($entry['channel'] ?? '')))) ?></td>
                        <td><?= esc((string) ($entry['recipient'] ?? '')) ?></td>
                        <td><?= esc((string) ($entry['patient_label'] ?? '')) ?></td>
                        <td>
                          <span class="label label-<?= (($entry['status'] ?? '') === 'sent') ? 'success' : 'danger' ?>">
                            <?= esc((string) ($entry['status'] ?? 'n/d')) ?>
                          </span>
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
</div>

<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
</body>
</html>
