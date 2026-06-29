<?php
helper('portal');

$dashboard = is_array($dashboard ?? null) ? $dashboard : [];
$summary = is_array($dashboard['summary'] ?? null) ? $dashboard['summary'] : [];
$tenantRows = is_array($dashboard['tenant_rows'] ?? null) ? $dashboard['tenant_rows'] : [];
$recentRows = is_array($dashboard['recent_rows'] ?? null) ? $dashboard['recent_rows'] : [];
$errors = is_array($errors ?? null) ? $errors : [];
$launchFeedback = is_array($launchFeedback ?? null) ? $launchFeedback : null;
$days = (int) ($days ?? 30);

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
                    <label>Delay ms</label>
                    <input class="form-control" type="number" name="delay_ms" value="0" min="0">
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
                    <th style="width:130px;">Azioni</th>
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
