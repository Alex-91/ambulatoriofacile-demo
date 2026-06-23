<?php
helper('portal');

$menuDataAdmin = session()->get('menuDataAdmin');
$sidebarMenuItems = is_array($menuDataAdmin['result'] ?? null) ? $menuDataAdmin['result'] : [];

if (empty($menu_items) || !is_array($menu_items)) {
    $menu_items = $sidebarMenuItems !== [] ? $sidebarMenuItems : (session()->get('header_menu_items') ?? []);
}

$settings = is_array($settings ?? null) ? $settings : [];
$dashboard = is_array($dashboard ?? null) ? $dashboard : [];
$summary = is_array($dashboard['summary'] ?? null) ? $dashboard['summary'] : [];
$messageTypes = is_array($settings['message_types'] ?? null) ? $settings['message_types'] : [];
$availableChannels = is_array($settings['available_channels'] ?? null) ? $settings['available_channels'] : ['sms' => false, 'wa' => false, 'email' => false, 'otp' => false];
$recentRows = is_array($dashboard['recent_rows'] ?? null) ? $dashboard['recent_rows'] : [];
$byType = is_array($dashboard['by_type'] ?? null) ? $dashboard['by_type'] : [];
$errors = is_array($errors ?? null) ? $errors : [];
$channelMeta = [
    'sms' => ['label' => 'SMS', 'icon' => 'fa-comment'],
    'wa' => ['label' => 'WhatsApp', 'icon' => 'fa-whatsapp'],
    'email' => ['label' => 'Email', 'icon' => 'fa-envelope'],
    'otp' => ['label' => 'OTP', 'icon' => 'fa-key'],
];

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
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Notifiche Appuntamenti</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <style>
    .nav-pills.nav-stacked > li.active > a {
      background-color:#2c8895;
      color:#fff;
    }
    .intro-box { border:1px solid #dbe8eb; border-radius:12px; padding:18px 20px; background:linear-gradient(135deg, #f8fcfc 0%, #eff7f8 100%); margin-bottom:16px; }
    .channel-card { border:1px solid #e5ecee; border-radius:10px; padding:15px 16px; background:#fff; min-height:120px; margin-bottom:14px; }
    .metric-card { border:1px solid #e5ecee; border-radius:10px; padding:15px 16px; background:#fff; min-height:118px; margin-bottom:14px; }
    .metric-label { color:#6a7b80; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
    .metric-value { color:#1d5f68; font-size:28px; font-weight:700; margin-top:8px; }
    .notif-config-box { border:1px solid #e5ecee; border-radius:12px; padding:16px; background:#fff; margin-bottom:14px; }
    .notif-config-box h4 { margin-top:0; margin-bottom:6px; }
    .inline-check { display:inline-block; margin-right:18px; }
  </style>
</head>
<body class="skin-blue sidebar-mini">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items, 'portal_console_header' => false]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Notifiche Appuntamenti</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Qui il tenant master decide se inviare i tre messaggi appuntamento e con quali canali tra quelli attivati centralmente.
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
              Spazio attivo: <?= esc((string) ($tenantContext->tenantName ?? '')) ?>
            </h3>
            <p style="margin:0 0 12px 0; color:#52676c;">
              Qui il tenant master decide quali notifiche inviare tra conferma appuntamento, reminder e presa appuntamento da un professionista all altro. SMS e WhatsApp dipendono dal pacchetto, mentre Email e OTP sono disponibili direttamente dentro l applicazione.
            </p>
            <a class="btn btn-default" href="<?= portal_tenant_space_url('funzioni') ?>">
              <i class="fa fa-arrow-left"></i> Torna alle funzioni dello spazio
            </a>
          </div>

          <?php if (empty($settings['module']['available'])): ?>
            <div class="alert alert-warning">
              Il centro notifiche appuntamenti non e disponibile nel pacchetto attuale del tuo spazio. Chiedi al master piattaforma di abilitarlo.
            </div>
          <?php else: ?>
            <div class="row">
              <?php foreach ($channelMeta as $channelKey => $meta): ?>
                <div class="col-md-6">
                  <div class="channel-card">
                    <h4><i class="fa <?= esc((string) $meta['icon']) ?>"></i> Canale <?= esc((string) $meta['label']) ?></h4>
                    <p class="text-muted">
                      <?php if (in_array($channelKey, ['sms', 'wa'], true)): ?>
                        Disponibilita commerciale e tecnica del canale <?= esc((string) $meta['label']) ?> per questo spazio.
                      <?php elseif ($channelKey === 'email'): ?>
                        Invio email usando i recapiti salvati in agenda e in anagrafica paziente o professionista.
                      <?php else: ?>
                        Genera un codice OTP e lo recapita usando il contatto disponibile del destinatario.
                      <?php endif; ?>
                    </p>
                    <span class="label label-<?= !empty($availableChannels[$channelKey]) ? 'success' : 'default' ?>">
                      <?= !empty($availableChannels[$channelKey]) ? 'attivo' : 'non disponibile' ?>
                    </span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="row">
              <div class="col-md-4">
                <div class="metric-card">
                  <div class="metric-label">Invii registrati</div>
                  <div class="metric-value"><?= (int) ($summary['total_sent'] ?? 0) ?></div>
                  <div class="text-muted">Storico totale disponibile.</div>
                </div>
              </div>
              <div class="col-md-4">
                <div class="metric-card">
                  <div class="metric-label">Ultimi 30 giorni</div>
                  <div class="metric-value"><?= (int) ($summary['recent_sent'] ?? 0) ?></div>
                  <div class="text-muted">Invii registrati su tutti i canali.</div>
                </div>
              </div>
              <div class="col-md-4">
                <div class="metric-card">
                  <div class="metric-label">Ultimo invio</div>
                  <div class="metric-value" style="font-size:22px;"><?= esc($formatDateTime((string) ($summary['last_sent_at'] ?? ''))) ?></div>
                  <div class="text-muted">Ultimo evento utile registrato.</div>
                </div>
              </div>
            </div>

            <div class="box box-success">
              <div class="box-header with-border">
                <h3 class="box-title">Configurazione operativa dello spazio</h3>
              </div>
              <form method="post" action="<?= portal_tenant_space_url('notifiche-appuntamenti/save') ?>">
                <?= csrf_field() ?>
                <div class="box-body">
                  <?php foreach ($messageTypes as $key => $typeRow): ?>
                    <?php
                      $prefix = $key;
                      $enabledName = $prefix . '_enabled';
                      $channelsName = $prefix . '_channels[]';
                    ?>
                    <div class="notif-config-box">
                      <h4><?= esc((string) ($typeRow['label'] ?? $key)) ?></h4>
                      <p class="text-muted"><?= esc((string) ($typeRow['description'] ?? '')) ?></p>

                      <div class="checkbox" style="margin:0 0 12px 0;">
                        <label>
                          <input type="hidden" name="<?= esc($enabledName) ?>" value="0">
                          <input type="checkbox" name="<?= esc($enabledName) ?>" value="1" <?= !empty($typeRow['enabled']) ? 'checked' : '' ?>>
                          Attiva questo flusso
                        </label>
                      </div>

                      <div style="margin-bottom:10px;">
                        <?php foreach ($channelMeta as $channelKey => $meta): ?>
                          <?php $selectedKey = $channelKey . '_selected'; ?>
                          <label class="inline-check">
                            <input type="checkbox" name="<?= esc($channelsName) ?>" value="<?= esc($channelKey) ?>" <?= !empty($typeRow[$selectedKey]) ? 'checked' : '' ?> <?= empty($availableChannels[$channelKey]) ? 'disabled' : '' ?>>
                            <?= esc((string) $meta['label']) ?>
                          </label>
                        <?php endforeach; ?>
                      </div>

                      <?php if ($key === \App\Services\AppointmentNotificationSettingsService::TYPE_REMINDER): ?>
                        <div class="row">
                          <div class="col-md-3">
                            <label>Giorni di anticipo</label>
                            <input class="form-control" type="number" min="0" max="30" name="appointment_reminder_lead_days" value="<?= (int) ($typeRow['lead_days'] ?? 2) ?>">
                          </div>
                        </div>
                      <?php endif; ?>

                      <?php
                        $typeCounts = (array) ($byType[$key] ?? []);
                        $typeTotal = (int) ($typeCounts['total'] ?? 0);
                        $channelCountLabels = [];
                        foreach ($channelMeta as $channelKey => $meta) {
                            $channelCountLabels[] = $meta['label'] . ' ' . (int) ($typeCounts[$channelKey] ?? 0);
                        }
                      ?>
                      <p class="text-muted" style="margin:10px 0 0 0;">
                        Storico ultimi 30 giorni: <?= $typeTotal ?> invii
                        <?php if ($typeTotal > 0): ?>
                          (<?= esc(implode(', ', $channelCountLabels)) ?>)
                        <?php endif; ?>
                      </p>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div class="box-footer">
                  <button class="btn btn-success" type="submit">
                    <i class="fa fa-save"></i> Salva configurazione notifiche
                  </button>
                </div>
              </form>
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
                      <th>Flusso</th>
                      <th>Canale</th>
                      <th>Destinatario</th>
                      <th>Paziente</th>
                      <th>Esito</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if ($recentRows === []): ?>
                      <tr><td colspan="6" class="text-muted">Nessun invio registrato finora.</td></tr>
                    <?php else: ?>
                      <?php foreach ($recentRows as $entry): ?>
                        <tr>
                          <td><?= esc($formatDateTime((string) ($entry['created_at'] ?? ''))) ?></td>
                          <td><?= esc((string) (($messageTypes[$entry['message_type'] ?? '']['label'] ?? '') ?: ($entry['message_type'] ?? ''))) ?></td>
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
</body>
</html>
