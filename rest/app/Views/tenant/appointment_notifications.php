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
$whatsAppConsole = is_array($whatsAppConsole ?? null) ? $whatsAppConsole : [];
$whatsAppUrls = is_array($whatsAppUrls ?? null) ? $whatsAppUrls : [];
$whatsAppAccount = is_array($whatsAppConsole['account'] ?? null) ? $whatsAppConsole['account'] : [];
$whatsAppSummary = is_array($whatsAppConsole['delivery_summary'] ?? null) ? $whatsAppConsole['delivery_summary'] : [];
$whatsAppDeliveryLog = is_array($whatsAppConsole['delivery_log'] ?? null) ? $whatsAppConsole['delivery_log'] : [];
$whatsAppEnabled = !empty($availableChannels['wa']);
$whatsAppConnected = !empty($whatsAppAccount['connected']) && !empty($whatsAppAccount['logged_in']);
$whatsAppPairing = (string) ($whatsAppAccount['state'] ?? '') === 'pairing';
$whatsAppJsonFlags = JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT;
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
  <link href="<?= base_url('public/assets/css/billing-ts-ui.css') ?>" rel="stylesheet" />
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
    .notif-config-box.is-locked { background:#f7f9fa; border-color:#d7e0e3; }
    .notif-config-box h4 { margin-top:0; margin-bottom:6px; }
    .inline-check { display:inline-block; margin-right:18px; }
    .message-template-editor { margin-top:18px; padding-top:18px; border-top:1px solid var(--af-border, #e4e7ec); }
    .message-template-header { display:flex; align-items:flex-start; justify-content:space-between; gap:14px; margin-bottom:14px; }
    .message-template-kicker { color:var(--af-module-ink, #4338ca); font-size:11px; font-weight:600; letter-spacing:.04em; text-transform:uppercase; }
    .message-template-header h5 { margin:3px 0 3px; color:var(--af-ink, #101828); font-size:15px; font-weight:600; }
    .message-template-header p { margin:0; color:var(--af-muted, #667085); font-size:12.5px; }
    .message-template-grid { display:grid; grid-template-columns:minmax(0, 1.12fr) minmax(280px, .88fr); gap:16px; align-items:stretch; }
    .message-template-field,
    .message-template-preview { min-width:0; border:1px solid var(--af-border, #e4e7ec); border-radius:12px; background:var(--af-surface, #fff); }
    .message-template-field { padding:15px; }
    .message-template-field > label { display:block; margin:0 0 8px; color:var(--af-ink, #101828); }
    .message-template-toolbar { display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin-bottom:10px; }
    .message-template-toolbar-label { margin-right:2px; color:var(--af-muted, #667085); font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.03em; }
    .message-token { min-height:29px; padding:5px 9px; border:1px solid var(--af-module-border, #c7d2fe); border-radius:999px; background:var(--af-module-soft, #eef2ff); color:var(--af-module-ink, #4338ca); font-size:11.5px; font-weight:600; }
    .message-token:hover { border-color:var(--af-module-accent, #4f46e5); background:#e0e7ff; }
    .message-template-textarea { width:100%; min-height:190px; padding:12px 13px; border:1px solid var(--af-border-strong, #d0d5dd); border-radius:9px; box-shadow:none; color:var(--af-ink, #101828); background:var(--af-surface, #fff); font:inherit; font-size:13.5px; line-height:1.55; resize:vertical; }
    .message-template-textarea:focus { outline:0; border-color:var(--af-module-accent, #4f46e5); box-shadow:0 0 0 3px rgba(79, 70, 229, .14); }
    .message-template-meta { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-top:9px; color:var(--af-muted, #667085); font-size:11.5px; }
    .message-template-reset { padding:0; border:0; background:transparent; color:var(--af-module-ink, #4338ca); font-weight:600; white-space:nowrap; }
    .message-template-warning { margin-top:8px; color:var(--af-danger-ink, #b42318); font-size:12px; }
    .message-template-warning[hidden] { display:none; }
    .message-template-preview { display:flex; flex-direction:column; overflow:hidden; background:var(--af-surface-alt, #f9fafb); }
    .message-template-preview-head { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:12px 14px; border-bottom:1px solid var(--af-border, #e4e7ec); background:var(--af-surface, #fff); }
    .message-template-preview-head strong { color:var(--af-ink, #101828); font-size:12.5px; }
    .message-template-preview-head span { color:var(--af-muted, #667085); font-size:11px; }
    .message-template-preview-body { display:flex; flex:1; align-items:flex-start; padding:16px; background:linear-gradient(135deg, #f4f7f6 0%, #eef3f1 100%); }
    .message-template-bubble { position:relative; width:min(100%, 390px); padding:12px 14px 22px; border-radius:4px 11px 11px 11px; background:#fff; color:#25313b; box-shadow:0 1px 2px rgba(16, 24, 40, .12); font-size:13px; line-height:1.5; white-space:pre-wrap; overflow-wrap:anywhere; }
    .message-template-bubble::after { position:absolute; right:9px; bottom:6px; color:#7a8a91; font-size:10px; content:'10:30  ✓✓'; }
    .message-template-note { display:flex; gap:8px; margin-top:10px; color:var(--af-muted, #667085); font-size:11.5px; }
    .message-template-note .fa { margin-top:2px; color:var(--af-module-ink, #4338ca); }
    .wa-tenant-console { margin:4px 0 18px; border:1px solid var(--af-border, #e4e7ec); border-radius:14px; background:var(--af-surface, #fff); box-shadow:0 8px 24px rgba(16, 24, 40, .05); overflow:hidden; }
    .wa-console-head { display:flex; align-items:flex-start; justify-content:space-between; gap:18px; padding:19px 20px; border-bottom:1px solid var(--af-border, #e4e7ec); background:linear-gradient(135deg, #f7fcfa 0%, #f1f8f6 100%); }
    .wa-console-kicker { color:#18785d; font-size:11px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; }
    .wa-console-head h3 { margin:4px 0 5px; color:var(--af-ink, #101828); font-size:20px; }
    .wa-console-head p { max-width:680px; margin:0; color:var(--af-muted, #667085); }
    .wa-live-status { display:inline-flex; flex:none; align-items:center; gap:8px; min-height:32px; padding:6px 11px; border:1px solid #d8e2e0; border-radius:999px; background:#fff; color:#53666a; font-size:12px; font-weight:700; }
    .wa-live-status-dot { width:9px; height:9px; border-radius:50%; background:#98a2a7; }
    .wa-live-status.is-connected { border-color:#b9e7d3; color:#12684f; background:#f1fbf6; }
    .wa-live-status.is-connected .wa-live-status-dot { background:#25d366; box-shadow:0 0 0 3px rgba(37, 211, 102, .14); }
    .wa-live-status.is-pairing { border-color:#f6d58a; color:#8a5a00; background:#fff9ea; }
    .wa-live-status.is-pairing .wa-live-status-dot { background:#e9a61a; }
    .wa-console-body { padding:20px; }
    .wa-setup-callout { display:flex; align-items:flex-start; gap:12px; margin:0; padding:15px 16px; border:1px solid #f2d69a; border-radius:10px; background:#fffbef; color:#72571d; }
    .wa-setup-callout .fa { margin-top:2px; font-size:18px; }
    .wa-device-grid { display:grid; grid-template-columns:minmax(0, 1.12fr) minmax(275px, .88fr); gap:16px; }
    .wa-device-card, .wa-qr-card { min-width:0; border:1px solid var(--af-border, #e4e7ec); border-radius:12px; background:#fff; }
    .wa-device-card { padding:18px; }
    .wa-device-title { display:flex; align-items:center; gap:12px; margin-bottom:14px; }
    .wa-device-icon { display:flex; align-items:center; justify-content:center; width:42px; height:42px; border-radius:11px; background:#e8f8f1; color:#16805f; font-size:22px; }
    .wa-device-title h4 { margin:0 0 3px; color:var(--af-ink, #101828); font-size:15px; }
    .wa-device-title p { margin:0; color:var(--af-muted, #667085); font-size:12px; }
    .wa-device-facts { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px; margin-bottom:15px; }
    .wa-device-fact { padding:10px 11px; border-radius:9px; background:var(--af-surface-alt, #f9fafb); }
    .wa-device-fact span { display:block; color:var(--af-muted, #667085); font-size:10.5px; font-weight:600; text-transform:uppercase; }
    .wa-device-fact strong { display:block; margin-top:4px; color:var(--af-ink, #101828); font-size:13px; overflow-wrap:anywhere; }
    .wa-device-actions { display:flex; flex-wrap:wrap; gap:8px; }
    .wa-device-actions form { display:inline-block; }
    .wa-qr-card { display:flex; min-height:250px; flex-direction:column; align-items:center; justify-content:center; padding:16px; text-align:center; background:linear-gradient(145deg, #fbfdfc 0%, #f4f8f7 100%); }
    .wa-qr-card h4 { margin:0 0 4px; font-size:15px; }
    .wa-qr-card p { margin:0; color:var(--af-muted, #667085); font-size:12px; }
    .wa-qr-code { width:196px; height:196px; margin:12px auto; padding:8px; border:1px solid #dde6e4; border-radius:10px; background:#fff; }
    .wa-qr-code canvas, .wa-qr-code img, .wa-qr-code svg { display:block; width:100% !important; height:100% !important; }
    .wa-qr-empty-icon { margin-bottom:10px; color:#8aa49d; font-size:44px; }
    .wa-security-note { margin:12px 0 0; color:#667a7e; font-size:11.5px; }
    .wa-delivery-section { margin-top:18px; padding-top:18px; border-top:1px solid var(--af-border, #e4e7ec); }
    .wa-delivery-heading { display:flex; align-items:flex-end; justify-content:space-between; gap:12px; margin-bottom:12px; }
    .wa-delivery-heading h4 { margin:0 0 3px; color:var(--af-ink, #101828); font-size:16px; }
    .wa-delivery-heading p { margin:0; color:var(--af-muted, #667085); font-size:12px; }
    .wa-refresh-note { min-height:17px; color:var(--af-muted, #667085); font-size:11px; text-align:right; }
    .wa-metrics { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:10px; margin-bottom:13px; }
    .wa-metric { padding:12px 13px; border:1px solid var(--af-border, #e4e7ec); border-radius:10px; background:var(--af-surface-alt, #f9fafb); }
    .wa-metric span { color:var(--af-muted, #667085); font-size:10.5px; font-weight:700; text-transform:uppercase; }
    .wa-metric strong { display:block; margin-top:4px; color:var(--af-ink, #101828); font-size:21px; }
    .wa-delivery-table { margin-bottom:0; }
    .wa-delivery-text { max-width:320px; white-space:normal; overflow-wrap:anywhere; }
    .wa-status-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 8px; border-radius:999px; background:#eef1f3; color:#526269; font-size:11px; font-weight:700; }
    .wa-status-badge.is-delivered { background:#edf7f3; color:#187459; }
    .wa-status-badge.is-read { background:#eaf3ff; color:#1d62a7; }
    .wa-status-badge.is-failed { background:#fff0ee; color:#b42318; }
    @media (max-width: 980px) {
      .message-template-grid { grid-template-columns:1fr; }
      .message-template-preview-body { min-height:180px; }
      .wa-device-grid { grid-template-columns:1fr; }
      .wa-metrics { grid-template-columns:repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 560px) {
      .message-template-header,
      .message-template-meta { flex-direction:column; }
      .message-template-field { padding:12px; }
      .message-template-preview-body { padding:12px; }
      .wa-console-head, .wa-delivery-heading { align-items:flex-start; flex-direction:column; }
      .wa-console-body { padding:14px; }
      .wa-device-facts, .wa-metrics { grid-template-columns:1fr; }
      .wa-device-actions, .wa-device-actions form, .wa-device-actions .btn { width:100%; }
    }
  </style>
</head>
<body class="skin-blue sidebar-mini billing-ts-ui module-fatturazione">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items, 'portal_console_header' => false]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Notifiche Appuntamenti</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Qui il responsabile dello studio decide se inviare i tre messaggi appuntamento e con quali canali tra quelli attivati centralmente.
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
          <?php if (!empty($settings['using_default_preferences'])): ?>
            <div class="alert alert-info">
              Questo spazio sta usando la configurazione iniziale del centro notifiche appuntamenti:
              il flusso da medico a medico via OTP è attivo, mentre i messaggi verso pazienti restano spenti
              finché il responsabile dello studio non salva una configurazione personalizzata.
            </div>
          <?php endif; ?>

          <div class="intro-box">
            <h3 style="margin-top:0; margin-bottom:8px;">
              Studio attivo: <?= esc((string) ($tenantContext->tenantName ?? '')) ?>
            </h3>
            <p style="margin:0 0 12px 0; color:#52676c;">
              Qui il responsabile dello studio decide quali notifiche inviare tra conferma appuntamento, reminder e presa appuntamento da un medico a un altro medico. I canali disponibili dipendono sia da ciò che la piattaforma ha concesso al tuo studio sia dalla configurazione commerciale attiva.
            </p>
            <a class="btn btn-default" href="<?= portal_tenant_space_url('funzioni') ?>">
              <i class="fa fa-arrow-left"></i> Torna alle funzioni dello spazio
            </a>
          </div>

          <?php if (empty($settings['module']['available'])): ?>
            <div class="alert alert-warning">
              Il centro notifiche appuntamenti non è disponibile nel pacchetto attuale del tuo studio. Chiedi al responsabile della piattaforma di abilitarlo.
            </div>
          <?php else: ?>
            <div class="row">
              <?php foreach ($channelMeta as $channelKey => $meta): ?>
                <div class="col-md-6">
                  <div class="channel-card">
                    <h4><i class="fa <?= esc((string) $meta['icon']) ?>"></i> Canale <?= esc((string) $meta['label']) ?></h4>
                    <p class="text-muted">
                      <?php if (in_array($channelKey, ['sms', 'wa'], true)): ?>
                        Disponibilità commerciale e tecnica del canale <?= esc((string) $meta['label']) ?> per questo studio.
                      <?php elseif ($channelKey === 'email'): ?>
                        Invio email usando i recapiti salvati in agenda e in anagrafica, se il canale è stato lasciato disponibile dalla piattaforma per questo studio.
                      <?php else: ?>
                        Genera un codice OTP e lo recapita usando il contatto disponibile del destinatario, se il canale è stato lasciato disponibile dalla piattaforma per questo studio.
                      <?php endif; ?>
                    </p>
                    <span class="label label-<?= !empty($availableChannels[$channelKey]) ? 'success' : 'default' ?>">
                      <?= !empty($availableChannels[$channelKey]) ? 'attivo' : 'non disponibile' ?>
                    </span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <?php if ($whatsAppEnabled): ?>
              <section class="wa-tenant-console" id="whatsapp-device" aria-labelledby="whatsapp-device-title">
                <div class="wa-console-head">
                  <div>
                    <div class="wa-console-kicker">Canale WhatsApp dello spazio</div>
                    <h3 id="whatsapp-device-title">Dispositivo e stato degli invii</h3>
                    <p>Collega o cambia il telefono autorizzato e verifica se i messaggi inviati dal tuo studio risultano inviati, consegnati oppure letti.</p>
                  </div>
                  <div class="wa-live-status<?= $whatsAppConnected ? ' is-connected' : ($whatsAppPairing ? ' is-pairing' : '') ?>" id="wa-live-status">
                    <span class="wa-live-status-dot" aria-hidden="true"></span>
                    <span id="wa-live-status-label"><?= esc((string) ($whatsAppAccount['state_label'] ?? 'Non collegato')) ?></span>
                  </div>
                </div>

                <div class="wa-console-body">
                  <?php if (!empty($whatsAppConsole['load_error'])): ?>
                    <div class="alert alert-warning" id="wa-load-error">
                      <?= esc((string) $whatsAppConsole['load_error']) ?>
                    </div>
                  <?php else: ?>
                    <div class="alert alert-warning" id="wa-load-error" hidden></div>
                  <?php endif; ?>

                  <?php if (empty($whatsAppConsole['gateway_available'])): ?>
                    <div class="wa-setup-callout">
                      <i class="fa fa-clock-o" aria-hidden="true"></i>
                      <div>
                        <strong>Collegamento in preparazione</strong><br>
                        <?= esc((string) ($whatsAppConsole['setup_message'] ?? 'La piattaforma deve completare la predisposizione del collegamento WhatsApp per questo spazio.')) ?>
                      </div>
                    </div>
                  <?php else: ?>
                    <div class="wa-device-grid">
                      <article class="wa-device-card">
                        <div class="wa-device-title">
                          <div class="wa-device-icon"><i class="fa fa-whatsapp" aria-hidden="true"></i></div>
                          <div>
                            <h4>Telefono collegato</h4>
                            <p>Il numero resta isolato e associato esclusivamente a questo spazio.</p>
                          </div>
                        </div>

                        <div class="wa-device-facts">
                          <div class="wa-device-fact">
                            <span>Stato</span>
                            <strong id="wa-device-state"><?= esc((string) ($whatsAppAccount['state_label'] ?? 'Non collegato')) ?></strong>
                          </div>
                          <div class="wa-device-fact">
                            <span>Numero</span>
                            <strong id="wa-device-number"><?= esc((string) (($whatsAppAccount['device'] ?? '') ?: 'Non disponibile')) ?></strong>
                          </div>
                          <div class="wa-device-fact">
                            <span>Nome collegamento</span>
                            <strong id="wa-device-name"><?= esc((string) (($whatsAppAccount['display_name'] ?? '') ?: ($tenantContext->tenantName ?? 'Studio'))) ?></strong>
                          </div>
                          <div class="wa-device-fact">
                            <span>Ultimo aggiornamento</span>
                            <strong id="wa-device-updated"><?= esc($formatDateTime((string) ($whatsAppAccount['updated_at'] ?? ''))) ?></strong>
                          </div>
                        </div>

                        <div class="wa-device-actions">
                          <?php if ($whatsAppPairing): ?>
                            <button class="btn btn-success" type="button" id="wa-refresh-qr">
                              <i class="fa fa-refresh"></i> Aggiorna QR
                            </button>
                            <form method="post" action="<?= esc((string) ($whatsAppUrls['pair'] ?? '')) ?>">
                              <?= csrf_field() ?>
                              <button class="btn btn-default" type="submit">
                                <i class="fa fa-qrcode"></i> Rigenera collegamento
                              </button>
                            </form>
                          <?php elseif (empty($whatsAppConsole['account_exists']) || empty($whatsAppAccount['logged_in'])): ?>
                            <form method="post" action="<?= esc((string) ($whatsAppUrls['pair'] ?? '')) ?>">
                              <?= csrf_field() ?>
                              <button class="btn btn-success" type="submit">
                                <i class="fa fa-qrcode"></i> Collega WhatsApp
                              </button>
                            </form>
                          <?php else: ?>
                            <?php if (empty($whatsAppAccount['connected'])): ?>
                              <form method="post" action="<?= esc((string) ($whatsAppUrls['reconnect'] ?? '')) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn-success" type="submit">
                                  <i class="fa fa-plug"></i> Ricollega
                                </button>
                              </form>
                            <?php endif; ?>
                            <form method="post" action="<?= esc((string) ($whatsAppUrls['change_device'] ?? '')) ?>" data-confirm="Il dispositivo attuale verrà scollegato. Vuoi generare il QR per collegarne uno nuovo?">
                              <?= csrf_field() ?>
                              <button class="btn btn-default" type="submit">
                                <i class="fa fa-exchange"></i> Cambia dispositivo
                              </button>
                            </form>
                            <form method="post" action="<?= esc((string) ($whatsAppUrls['disconnect'] ?? '')) ?>" data-confirm="Vuoi davvero scollegare WhatsApp da questo spazio? Gli invii resteranno fermi finché non collegherai nuovamente un dispositivo.">
                              <?= csrf_field() ?>
                              <button class="btn btn-danger" type="submit">
                                <i class="fa fa-unlink"></i> Scollega
                              </button>
                            </form>
                          <?php endif; ?>
                        </div>

                        <p class="wa-security-note">
                          <i class="fa fa-shield"></i> Solo il responsabile dello studio può eseguire queste operazioni. Ogni cambio dispositivo viene registrato nei log applicativi.
                        </p>
                      </article>

                      <aside class="wa-qr-card" id="wa-qr-panel">
                        <div id="wa-qr-content" <?= empty($whatsAppAccount['qr_code']) ? 'hidden' : '' ?>>
                          <h4>Inquadra il QR da WhatsApp</h4>
                          <p>Apri Dispositivi collegati sul telefono e completa l’associazione.</p>
                          <div class="wa-qr-code" id="wa-qr-code" aria-label="QR per collegare WhatsApp"></div>
                          <p id="wa-qr-expiry">Il codice si aggiorna automaticamente.</p>
                        </div>
                        <div id="wa-qr-empty" <?= !empty($whatsAppAccount['qr_code']) ? 'hidden' : '' ?>>
                          <i class="fa <?= $whatsAppConnected ? 'fa-check-circle' : 'fa-qrcode' ?> wa-qr-empty-icon" aria-hidden="true"></i>
                          <h4 id="wa-qr-empty-title"><?= $whatsAppConnected ? 'Dispositivo collegato' : 'QR non ancora generato' ?></h4>
                          <p id="wa-qr-empty-text"><?= $whatsAppConnected ? 'La connessione è pronta per gli invii dello studio.' : 'Premi “Collega WhatsApp” per iniziare.' ?></p>
                        </div>
                      </aside>
                    </div>

                    <section class="wa-delivery-section" aria-labelledby="wa-delivery-title">
                      <div class="wa-delivery-heading">
                        <div>
                          <h4 id="wa-delivery-title">Invii WhatsApp recenti</h4>
                          <p>“Consegnato” significa ricevuto dal dispositivo del destinatario; “Letto” compare quando WhatsApp rende disponibile la conferma di lettura.</p>
                        </div>
                        <div>
                          <button class="btn btn-default btn-sm" type="button" id="wa-refresh-deliveries">
                            <i class="fa fa-refresh"></i> Aggiorna
                          </button>
                          <div class="wa-refresh-note" id="wa-refresh-note" aria-live="polite"></div>
                        </div>
                      </div>

                      <div class="wa-metrics">
                        <div class="wa-metric"><span>Totale recente</span><strong id="wa-count-total"><?= (int) ($whatsAppSummary['total'] ?? 0) ?></strong></div>
                        <div class="wa-metric"><span>Solo inviati</span><strong id="wa-count-sent"><?= (int) ($whatsAppSummary['sent'] ?? 0) ?></strong></div>
                        <div class="wa-metric"><span>Consegnati</span><strong id="wa-count-delivered"><?= (int) ($whatsAppSummary['delivered'] ?? 0) ?></strong></div>
                        <div class="wa-metric"><span>Letti</span><strong id="wa-count-read"><?= (int) ($whatsAppSummary['read'] ?? 0) ?></strong></div>
                      </div>

                      <div class="table-responsive">
                        <table class="table table-hover wa-delivery-table">
                          <thead>
                            <tr>
                              <th>Inviato il</th>
                              <th>Destinatario</th>
                              <th>Messaggio</th>
                              <th>Stato</th>
                              <th>Aggiornato il</th>
                            </tr>
                          </thead>
                          <tbody id="wa-delivery-rows">
                            <?php if ($whatsAppDeliveryLog === []): ?>
                              <tr data-empty-row><td colspan="5" class="text-muted">Nessun invio WhatsApp registrato per questo dispositivo.</td></tr>
                            <?php else: ?>
                              <?php foreach ($whatsAppDeliveryLog as $waEntry): ?>
                                <?php
                                  $waStatus = (string) ($waEntry['status'] ?? 'sent');
                                  $waStatusTime = (string) (($waEntry['read_at'] ?? '') ?: (($waEntry['delivered_at'] ?? '') ?: ($waEntry['sent_at'] ?? '')));
                                ?>
                                <tr>
                                  <td><?= esc($formatDateTime((string) ($waEntry['sent_at'] ?? ''))) ?></td>
                                  <td><?= esc((string) ($waEntry['recipient'] ?? '')) ?></td>
                                  <td class="wa-delivery-text"><?= esc((string) ($waEntry['text'] ?? '')) ?></td>
                                  <td><span class="wa-status-badge is-<?= esc($waStatus, 'attr') ?>"><?= esc((string) ($waEntry['status_label'] ?? 'Inviato')) ?></span></td>
                                  <td><?= esc($formatDateTime($waStatusTime)) ?></td>
                                </tr>
                              <?php endforeach; ?>
                            <?php endif; ?>
                          </tbody>
                        </table>
                      </div>
                    </section>
                  <?php endif; ?>
                </div>
              </section>
            <?php endif; ?>

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
                      $typeLocked = empty($typeRow['tenant_can_manage']);
                      $templateSupported = in_array($key, [
                          \App\Services\AppointmentNotificationSettingsService::TYPE_PATIENT_BOOKING,
                          \App\Services\AppointmentNotificationSettingsService::TYPE_REMINDER,
                      ], true);
                      $templateName = $prefix . '_template';
                      $templateId = 'message-template-' . str_replace('_', '-', $key);
                      $templateValue = (string) ($typeRow['template'] ?? '');
                      $defaultTemplate = (string) ($typeRow['default_template'] ?? '');
                      $templateTokens = is_array($typeRow['template_tokens'] ?? null) ? $typeRow['template_tokens'] : [];
                      $templatePreviewValues = is_array($typeRow['template_preview_values'] ?? null) ? $typeRow['template_preview_values'] : [];
                    ?>
                    <div class="notif-config-box<?= $typeLocked ? ' is-locked' : '' ?>">
                      <h4><?= esc((string) ($typeRow['label'] ?? $key)) ?></h4>
                      <p class="text-muted"><?= esc((string) ($typeRow['description'] ?? '')) ?></p>
                      <div style="margin:0 0 10px 0;">
                        <span class="label label-<?= !empty($typeRow['platform_enabled']) ? 'info' : 'warning' ?>">
                          <?= !empty($typeRow['platform_enabled']) ? 'abilitato dalla piattaforma' : 'bloccato dalla piattaforma' ?>
                        </span>
                      </div>

                      <div class="checkbox" style="margin:0 0 12px 0;">
                        <label>
                          <input type="hidden" name="<?= esc($enabledName) ?>" value="0">
                          <input type="checkbox" name="<?= esc($enabledName) ?>" value="1" <?= !empty($typeRow['enabled']) ? 'checked' : '' ?> <?= $typeLocked ? 'disabled' : '' ?>>
                          Attiva questo flusso
                        </label>
                      </div>

                      <fieldset style="padding:0; margin:0; border:0;" <?= $typeLocked ? 'disabled' : '' ?>>
                        <div style="margin-bottom:10px;">
                          <?php foreach ($channelMeta as $channelKey => $meta): ?>
                            <?php $selectedKey = $channelKey . '_selected'; ?>
                            <label class="inline-check">
                              <input type="checkbox" name="<?= esc($channelsName) ?>" value="<?= esc($channelKey) ?>" <?= !empty($typeRow[$selectedKey]) ? 'checked' : '' ?> <?= empty($availableChannels[$channelKey]) ? 'disabled' : '' ?>>
                              <?= esc((string) $meta['label']) ?>
                            </label>
                          <?php endforeach; ?>
                        </div>
                        <p class="text-muted" style="margin:0 0 10px 0;">
                          Se attivi questo flusso devi selezionare almeno un canale tra quelli disponibili per lo studio.
                        </p>

                        <?php if ($key === \App\Services\AppointmentNotificationSettingsService::TYPE_REMINDER): ?>
                          <div class="row">
                            <div class="col-md-3">
                              <label>Giorni di anticipo</label>
                              <input class="form-control" type="number" min="0" max="30" name="appointment_reminder_lead_days" value="<?= (int) ($typeRow['lead_days'] ?? 2) ?>">
                            </div>
                          </div>
                        <?php endif; ?>

                        <?php if ($templateSupported): ?>
                          <section class="message-template-editor" data-message-template-editor
                                   data-preview-values="<?= esc(base64_encode((string) json_encode($templatePreviewValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 'attr') ?>">
                            <div class="message-template-header">
                              <div>
                                <div class="message-template-kicker">Testo del messaggio</div>
                                <h5><?= $key === \App\Services\AppointmentNotificationSettingsService::TYPE_REMINDER ? 'Modello reminder' : 'Modello conferma appuntamento' ?></h5>
                                <p>Scrivi il testo una volta: i dati dell’appuntamento vengono inseriti automaticamente al momento dell’invio.</p>
                              </div>
                              <span class="label label-info">Anteprima immediata</span>
                            </div>

                            <div class="message-template-grid">
                              <div class="message-template-field">
                                <label for="<?= esc($templateId) ?>">Messaggio</label>
                                <div class="message-template-toolbar" aria-label="Segnaposto disponibili">
                                  <span class="message-template-toolbar-label">Inserisci</span>
                                  <?php foreach ($templateTokens as $token => $tokenMeta): ?>
                                    <button class="message-token" type="button" data-message-token="<?= esc((string) $token, 'attr') ?>">
                                      <?= esc((string) ($tokenMeta['label'] ?? $token)) ?>
                                    </button>
                                  <?php endforeach; ?>
                                </div>
                                <textarea class="message-template-textarea"
                                          id="<?= esc($templateId) ?>"
                                          name="<?= esc($templateName) ?>"
                                          maxlength="<?= \App\Services\AppointmentMessageTemplateService::MAX_TEMPLATE_LENGTH ?>"
                                          data-message-template
                                          required><?= esc($templateValue) ?></textarea>
                                <div class="message-template-meta">
                                  <span><span data-message-count>0</span>/<?= \App\Services\AppointmentMessageTemplateService::MAX_TEMPLATE_LENGTH ?> caratteri · I segnaposto tra doppie parentesi non vanno modificati.</span>
                                  <button class="message-template-reset" type="button"
                                          data-message-reset
                                          data-default-template="<?= esc(base64_encode($defaultTemplate), 'attr') ?>">
                                    <i class="fa fa-undo"></i> Ripristina testo iniziale
                                  </button>
                                </div>
                                <div class="message-template-warning" data-message-warning hidden></div>
                                <div class="message-template-note">
                                  <i class="fa fa-shield"></i>
                                  <span>Usa solo i segnaposto disponibili: il sistema impedisce il salvataggio di variabili non riconosciute.</span>
                                </div>
                              </div>

                              <aside class="message-template-preview" aria-label="Anteprima del messaggio">
                                <div class="message-template-preview-head">
                                  <strong>Anteprima paziente</strong>
                                  <span>Dati dimostrativi</span>
                                </div>
                                <div class="message-template-preview-body">
                                  <div class="message-template-bubble" data-message-preview aria-live="polite"></div>
                                </div>
                              </aside>
                            </div>
                          </section>
                        <?php endif; ?>
                      </fieldset>
                      <?php if ($typeLocked): ?>
                        <p class="text-warning" style="margin:10px 0 0 0;">
                          Questo tipo di notifica è gestito dal master piattaforma per il tuo studio. I canali salvati restano memorizzati, ma il flusso non può essere modificato da qui finché non viene riabilitato centralmente.
                        </p>
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
<?php if ($whatsAppEnabled): ?>
  <script src="<?= base_url('js/qrcode.min.js') ?>"></script>
<?php endif; ?>
<script>
(function () {
  'use strict';

  function decodeBase64(value) {
    if (!value) {
      return '';
    }

    var binary = window.atob(value);
    var bytes = new Uint8Array(binary.length);
    for (var index = 0; index < binary.length; index += 1) {
      bytes[index] = binary.charCodeAt(index);
    }

    return new TextDecoder('utf-8').decode(bytes);
  }

  function findTokens(template) {
    var tokens = [];
    var pattern = /\{\{\s*([^{}]+?)\s*\}\}/g;
    var match;

    while ((match = pattern.exec(template)) !== null) {
      var token = String(match[1] || '').trim().toLowerCase();
      if (token && tokens.indexOf(token) === -1) {
        tokens.push(token);
      }
    }

    return tokens;
  }

  function renderMessage(template, values) {
    var tokenPattern = /\{\{\s*([a-z0-9_]+)\s*\}\}/gi;
    var lines = String(template || '').replace(/\r\n?/g, '\n').split('\n');
    var rendered = [];

    lines.forEach(function (line) {
      var lineTokens = [];
      var match;
      tokenPattern.lastIndex = 0;
      while ((match = tokenPattern.exec(line)) !== null) {
        lineTokens.push(String(match[1] || '').toLowerCase());
      }

      if (lineTokens.length && !lineTokens.some(function (token) { return String(values[token] || '').trim() !== ''; })) {
        return;
      }

      tokenPattern.lastIndex = 0;
      rendered.push(line.replace(tokenPattern, function (placeholder, token) {
        return String(values[String(token).toLowerCase()] || '');
      }));
    });

    return rendered.join('\n').replace(/\n{3,}/g, '\n\n').trim();
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-message-template-editor]'), function (editor) {
    var textarea = editor.querySelector('[data-message-template]');
    var preview = editor.querySelector('[data-message-preview]');
    var counter = editor.querySelector('[data-message-count]');
    var warning = editor.querySelector('[data-message-warning]');
    var reset = editor.querySelector('[data-message-reset]');
    var values = {};

    try {
      values = JSON.parse(decodeBase64(editor.getAttribute('data-preview-values') || '')) || {};
    } catch (error) {
      values = {};
    }

    var allowedTokens = Object.keys(values);

    function update() {
      var template = textarea.value || '';
      var unknownTokens = findTokens(template).filter(function (token) {
        return allowedTokens.indexOf(token) === -1;
      });

      counter.textContent = String(template.length);
      preview.textContent = renderMessage(template, values) || 'Scrivi il messaggio per visualizzare l’anteprima.';

      if (unknownTokens.length) {
        var message = 'Segnaposto non riconosciuti: ' + unknownTokens.map(function (token) { return '{{' + token + '}}'; }).join(', ');
        warning.textContent = message;
        warning.hidden = false;
        textarea.setCustomValidity(message);
      } else {
        warning.textContent = '';
        warning.hidden = true;
        textarea.setCustomValidity('');
      }
    }

    Array.prototype.forEach.call(editor.querySelectorAll('[data-message-token]'), function (button) {
      button.addEventListener('click', function () {
        var token = '{{' + button.getAttribute('data-message-token') + '}}';
        var start = typeof textarea.selectionStart === 'number' ? textarea.selectionStart : textarea.value.length;
        var end = typeof textarea.selectionEnd === 'number' ? textarea.selectionEnd : start;
        textarea.setRangeText(token, start, end, 'end');
        textarea.focus();
        update();
      });
    });

    reset.addEventListener('click', function () {
      textarea.value = decodeBase64(reset.getAttribute('data-default-template') || '');
      textarea.focus();
      update();
    });

    textarea.addEventListener('input', update);
    update();
  });
})();
</script>
<?php if ($whatsAppEnabled && !empty($whatsAppConsole['gateway_available'])): ?>
<script>
(function () {
  'use strict';

  var consoleState = <?= json_encode($whatsAppConsole, $whatsAppJsonFlags) ?>;
  var refreshUrl = <?= json_encode((string) ($whatsAppUrls['refresh'] ?? ''), $whatsAppJsonFlags) ?>;
  var lastQrCode = '';
  var refreshInProgress = false;
  var reloadRequested = false;
  var elements = {
    status: document.getElementById('wa-live-status'),
    statusLabel: document.getElementById('wa-live-status-label'),
    deviceState: document.getElementById('wa-device-state'),
    deviceNumber: document.getElementById('wa-device-number'),
    deviceName: document.getElementById('wa-device-name'),
    deviceUpdated: document.getElementById('wa-device-updated'),
    qrContent: document.getElementById('wa-qr-content'),
    qrEmpty: document.getElementById('wa-qr-empty'),
    qrCode: document.getElementById('wa-qr-code'),
    qrExpiry: document.getElementById('wa-qr-expiry'),
    qrEmptyTitle: document.getElementById('wa-qr-empty-title'),
    qrEmptyText: document.getElementById('wa-qr-empty-text'),
    rows: document.getElementById('wa-delivery-rows'),
    countTotal: document.getElementById('wa-count-total'),
    countSent: document.getElementById('wa-count-sent'),
    countDelivered: document.getElementById('wa-count-delivered'),
    countRead: document.getElementById('wa-count-read'),
    refreshDeliveries: document.getElementById('wa-refresh-deliveries'),
    refreshQr: document.getElementById('wa-refresh-qr'),
    refreshNote: document.getElementById('wa-refresh-note'),
    loadError: document.getElementById('wa-load-error')
  };

  if (!elements.status || !refreshUrl) {
    return;
  }

  function formatDate(value) {
    if (!value) {
      return '-';
    }
    var date = new Date(value);
    if (isNaN(date.getTime())) {
      return String(value);
    }
    return new Intl.DateTimeFormat('it-IT', {
      day: '2-digit', month: '2-digit', year: 'numeric',
      hour: '2-digit', minute: '2-digit'
    }).format(date);
  }

  function setText(element, value) {
    if (element) {
      element.textContent = String(value == null || value === '' ? '-' : value);
    }
  }

  function renderQr(account) {
    if (!elements.qrCode || !elements.qrContent || !elements.qrEmpty) {
      return;
    }

    var qrCode = String(account.qr_code || '');
    if (!qrCode) {
      elements.qrContent.hidden = true;
      elements.qrEmpty.hidden = false;
      setText(elements.qrEmptyTitle, account.connected ? 'Dispositivo collegato' : 'QR non ancora disponibile');
      setText(elements.qrEmptyText, account.connected
        ? 'La connessione è pronta per gli invii dello studio.'
        : (account.state === 'pairing' ? 'Attendi qualche secondo e aggiorna il codice.' : 'Avvia il collegamento per generare un QR.'));
      return;
    }

    elements.qrContent.hidden = false;
    elements.qrEmpty.hidden = true;
    setText(elements.qrExpiry, account.qr_expires_at
      ? 'Codice valido fino alle ' + formatDate(account.qr_expires_at)
      : 'Il codice si aggiorna automaticamente.');

    if (lastQrCode === qrCode) {
      return;
    }
    lastQrCode = qrCode;
    elements.qrCode.innerHTML = '';
    if (typeof QRCode === 'function') {
      new QRCode(elements.qrCode, {
        text: qrCode,
        width: 180,
        height: 180,
        colorDark: '#101828',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
      });
    }
  }

  function renderRows(rows) {
    if (!elements.rows) {
      return;
    }
    elements.rows.innerHTML = '';
    if (!Array.isArray(rows) || rows.length === 0) {
      var emptyRow = document.createElement('tr');
      var emptyCell = document.createElement('td');
      emptyCell.colSpan = 5;
      emptyCell.className = 'text-muted';
      emptyCell.textContent = 'Nessun invio WhatsApp registrato per questo dispositivo.';
      emptyRow.appendChild(emptyCell);
      elements.rows.appendChild(emptyRow);
      return;
    }

    rows.forEach(function (entry) {
      var row = document.createElement('tr');
      var sent = document.createElement('td');
      var recipient = document.createElement('td');
      var message = document.createElement('td');
      var statusCell = document.createElement('td');
      var updated = document.createElement('td');
      var badge = document.createElement('span');
      var status = String(entry.status || 'sent');

      sent.textContent = formatDate(entry.sent_at);
      recipient.textContent = String(entry.recipient || '-');
      message.className = 'wa-delivery-text';
      message.textContent = String(entry.text || '-');
      badge.className = 'wa-status-badge is-' + status.replace(/[^a-z]/g, '');
      badge.textContent = String(entry.status_label || 'Inviato');
      statusCell.appendChild(badge);
      updated.textContent = formatDate(entry.read_at || entry.delivered_at || entry.sent_at);

      row.appendChild(sent);
      row.appendChild(recipient);
      row.appendChild(message);
      row.appendChild(statusCell);
      row.appendChild(updated);
      elements.rows.appendChild(row);
    });
  }

  function render(payload, allowReload) {
    var previousAccount = consoleState && consoleState.account ? consoleState.account : {};
    var account = payload && payload.account ? payload.account : {};
    var summary = payload && payload.delivery_summary ? payload.delivery_summary : {};
    var connected = !!account.connected && !!account.logged_in;
    var pairing = String(account.state || '') === 'pairing';

    consoleState = payload || {};
    elements.status.className = 'wa-live-status' + (connected ? ' is-connected' : (pairing ? ' is-pairing' : ''));
    setText(elements.statusLabel, account.state_label || 'Non collegato');
    setText(elements.deviceState, account.state_label || 'Non collegato');
    setText(elements.deviceNumber, account.device || 'Non disponibile');
    setText(elements.deviceName, account.display_name || <?= json_encode((string) ($tenantContext->tenantName ?? 'Studio'), $whatsAppJsonFlags) ?>);
    setText(elements.deviceUpdated, formatDate(account.updated_at));
    setText(elements.countTotal, Number(summary.total || 0));
    setText(elements.countSent, Number(summary.sent || 0));
    setText(elements.countDelivered, Number(summary.delivered || 0));
    setText(elements.countRead, Number(summary.read || 0));
    renderQr(account);
    renderRows(payload && payload.delivery_log ? payload.delivery_log : []);

    if (elements.loadError) {
      elements.loadError.hidden = true;
      elements.loadError.textContent = '';
    }

    if (
      allowReload
      && !reloadRequested
      && (!!previousAccount.connected !== !!account.connected || !!previousAccount.logged_in !== !!account.logged_in)
    ) {
      reloadRequested = true;
      window.location.reload();
    }
  }

  function refresh(silent) {
    if (refreshInProgress || reloadRequested) {
      return;
    }
    refreshInProgress = true;
    if (!silent) {
      setText(elements.refreshNote, 'Aggiornamento in corso…');
    }

    $.ajax({
      url: refreshUrl,
      method: 'GET',
      cache: false,
      dataType: 'json'
    }).done(function (response) {
      if (!response || !response.ok || !response.console) {
        var responseMessage = response && response.message ? response.message : 'Aggiornamento non disponibile.';
        if (elements.loadError) {
          elements.loadError.hidden = false;
          elements.loadError.textContent = responseMessage;
        }
        if (!silent) {
          setText(elements.refreshNote, 'Aggiornamento non riuscito');
        }
        return;
      }
      render(response.console, true);
      if (!silent) {
        setText(elements.refreshNote, 'Aggiornato ora');
      }
    }).fail(function (xhr) {
      var response = xhr && xhr.responseJSON ? xhr.responseJSON : {};
      var message = String(response.message || 'Impossibile aggiornare lo stato WhatsApp.');
      if (elements.loadError) {
        elements.loadError.hidden = false;
        elements.loadError.textContent = message;
      }
      if (!silent) {
        setText(elements.refreshNote, 'Aggiornamento non riuscito');
      }
    }).always(function () {
      refreshInProgress = false;
    });
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-confirm]'), function (form) {
    form.addEventListener('submit', function (event) {
      if (!window.confirm(form.getAttribute('data-confirm') || 'Confermi questa operazione?')) {
        event.preventDefault();
      }
    });
  });
  if (elements.refreshDeliveries) {
    elements.refreshDeliveries.addEventListener('click', function () { refresh(false); });
  }
  if (elements.refreshQr) {
    elements.refreshQr.addEventListener('click', function () { refresh(false); });
  }

  render(consoleState, false);
  window.setInterval(function () {
    if (!document.hidden) {
      refresh(true);
    }
  }, 12000);
})();
</script>
<?php endif; ?>
</body>
</html>
