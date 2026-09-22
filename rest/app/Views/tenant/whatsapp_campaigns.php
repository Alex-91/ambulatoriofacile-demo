<?php
helper('portal');

$menuDataAdmin = session()->get('menuDataAdmin');
$sidebarMenuItems = is_array($menuDataAdmin['result'] ?? null) ? $menuDataAdmin['result'] : [];
$menu_items = $sidebarMenuItems !== [] ? $sidebarMenuItems : (session()->get('header_menu_items') ?? []);
$dashboard = is_array($dashboard ?? null) ? $dashboard : [];
$summary = is_array($dashboard['summary'] ?? null) ? $dashboard['summary'] : [];
$campaigns = is_array($dashboard['campaigns'] ?? null) ? $dashboard['campaigns'] : [];
$selected = is_array($dashboard['selected_campaign'] ?? null) ? $dashboard['selected_campaign'] : null;
$recipients = is_array($dashboard['recipients'] ?? null) ? $dashboard['recipients'] : [];
$errors = is_array($errors ?? null) ? $errors : [];
$smsEnabled = !empty($smsEnabled);
$submitted = (string) old('campaign_form') === '1';
$emailSelected = !$submitted || (string) old('email_enabled') === '1';
$smsSelected = $smsEnabled && (string) old('sms_enabled') === '1';
$formatDateTime = static function ($value): string { $value = trim((string) $value); if ($value === '') return '—'; try { return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Europe/Rome'))->format('d/m/Y H:i'); } catch (Throwable $e) { return $value; } };
$formatDate = static function ($value): string { $value = trim((string) $value); if ($value === '') return '—'; try { return (new DateTimeImmutable($value))->format('d/m/Y'); } catch (Throwable $e) { return $value; } };
$labels = ['paused'=>'In pausa','queued'=>'In coda','running'=>'In corso','completed'=>'Completata','pending'=>'In attesa','processing'=>'In invio','sent'=>'Inviato','failed'=>'Non inviato'];
$statusClass = static function (string $status): string { return in_array($status, ['completed', 'sent'], true) ? 'is-success' : (in_array($status, ['failed'], true) ? 'is-danger' : 'is-warning'); };
?>
<!DOCTYPE html>
<html><head>
  <meta charset="UTF-8"><title>AmbulatorioFacile | Invii massivi</title><meta content="width=device-width, initial-scale=1" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet"><link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet"><link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet"><link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet"><link href="<?= base_url('public/assets/css/billing-ts-ui.css') ?>" rel="stylesheet">
  <style>
    .whatsapp-campaigns-page{--af-module-accent:#128c6a;--af-module-hover:#0d7357;--af-module-ink:#107b5d;--af-module-soft:#eaf8f2;--af-module-border:#b9e7d4}.whatsapp-campaigns-page .billing-dashboard-content{padding-top:24px}.whatsapp-campaigns-page .billing-module-actions{max-width:330px}.whatsapp-campaigns-page .campaign-kpi.is-danger strong{color:var(--af-danger-ink)}.whatsapp-campaigns-page .campaign-kpi.is-neutral strong{color:#475467}.campaign-panel{overflow:hidden;border:1px solid var(--af-border);border-radius:12px;background:var(--af-surface);box-shadow:0 1px 2px rgba(16,24,40,.05)}.campaign-panel+.campaign-panel{margin-top:20px}.campaign-panel-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:18px 20px;border-bottom:1px solid var(--af-border)}.campaign-panel-heading h2{margin:0;color:var(--af-ink);font-size:16px;font-weight:600}.campaign-panel-heading p{margin:4px 0 0;color:var(--af-muted);font-size:13px;line-height:1.45}.campaign-panel-icon{display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;width:36px;height:36px;border:1px solid var(--af-module-border);border-radius:9px;background:var(--af-module-soft);color:var(--af-module-ink);font-size:18px}.campaign-panel-body{padding:20px}.campaign-compose-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px}.campaign-compose-grid .campaign-message-field{grid-column:1 / -1}.campaign-help{margin:7px 0 0;color:var(--af-muted);font-size:12px;line-height:1.45}.campaign-submit-row{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-top:4px;padding-top:16px;border-top:1px solid var(--af-border)}.campaign-submit-row p{max-width:530px;margin:0;color:var(--af-muted);font-size:12px;line-height:1.45}.campaign-state{display:inline-flex;align-items:center;min-height:24px;padding:4px 9px;border:1px solid var(--af-border);border-radius:999px;background:#f2f4f7;color:#475467;font-size:11px;font-weight:700;line-height:1.2;white-space:nowrap}.campaign-state.is-success{border-color:var(--af-success-border);background:var(--af-success-bg);color:var(--af-success-ink)}.campaign-state.is-warning{border-color:var(--af-warning-border);background:var(--af-warning-bg);color:var(--af-warning-ink)}.campaign-state.is-danger{border-color:var(--af-danger-border);background:var(--af-danger-bg);color:var(--af-danger-ink)}.campaign-progress{color:var(--af-muted);font-size:12px;line-height:1.5;white-space:nowrap}.campaign-progress strong{color:var(--af-ink);font-weight:600}.campaign-message{max-width:720px;margin:0;color:var(--af-text);line-height:1.6;white-space:pre-wrap;overflow-wrap:anywhere}.campaign-selected-meta{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px}.campaign-selected-meta span{display:inline-flex;align-items:center;gap:6px;min-height:28px;padding:5px 10px;border:1px solid var(--af-border);border-radius:999px;background:var(--af-surface-alt);color:var(--af-muted);font-size:12px}.campaign-selected-meta strong{color:var(--af-ink)}.campaign-empty{padding:36px 24px;text-align:center}.campaign-empty p{margin:8px auto 0;color:var(--af-muted);font-size:13px}@media(max-width:767px){.campaign-compose-grid{grid-template-columns:1fr}.campaign-submit-row,.campaign-panel-heading{align-items:flex-start;flex-direction:column}.campaign-submit-row .btn{width:100%}.campaign-progress{white-space:normal}}
  </style>
</head>
<body class="skin-blue sidebar-mini billing-ts-ui module-fatturazione whatsapp-campaigns-page"><div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items, 'portal_console_header' => false]) ?>
  <div class="content-wrapper"><section class="content billing-dashboard-content"><div class="row billing-dashboard-layout">
    <aside class="col-md-3 billing-dashboard-nav"><?= view('partials/sidebar_admin', ['menu_items' => $sidebarMenuItems]) ?></aside>
    <main class="col-md-9"><div class="billing-dashboard">
      <header class="billing-modulebar"><div class="billing-modulebar-copy"><div class="billing-eyebrow">Comunicazioni pazienti</div><div class="billing-title-row"><span class="billing-module-icon"><i class="fa fa-envelope"></i></span><div><h1>Invii massivi</h1><p>Campagne programmate per i pazienti dello studio.</p></div></div></div></header>
      <?php if (!empty($success)): ?><div class="alert alert-success"><i class="fa fa-check-circle"></i> <?= esc((string) $success) ?></div><?php endif; ?>
      <?php foreach ($errors as $error): ?><div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> <?= esc((string) $error) ?></div><?php endforeach; ?>
      <div class="billing-kpi-grid"><article class="billing-kpi-card campaign-kpi"><span>Campagne</span><strong><?= (int) ($summary['total'] ?? 0) ?></strong><p>create per questo studio</p></article><article class="billing-kpi-card campaign-kpi is-neutral"><span>In attesa</span><strong><?= (int) ($summary['queued'] ?? 0) ?></strong><p>messaggi ancora in coda</p></article><article class="billing-kpi-card campaign-kpi"><span>Inviati</span><strong><?= (int) ($summary['sent'] ?? 0) ?></strong><p>messaggi consegnati al canale</p></article><article class="billing-kpi-card campaign-kpi is-danger"><span>Da verificare</span><strong><?= (int) ($summary['failed'] ?? 0) ?></strong><p>invii non riusciti</p></article></div>
      <?php if (empty($dashboard['schema_ready'])): ?>
        <div class="alert alert-warning"><i class="fa fa-clock-o"></i> La coda campagne sarà disponibile non appena l’aggiornamento database sarà completato.</div>
      <?php else: ?>
        <section class="campaign-panel" aria-labelledby="campaign-create-title"><div class="campaign-panel-heading"><div><h2 id="campaign-create-title">Nuova campagna</h2><p>Seleziona i destinatari, scrivi il messaggio e lascia che il sistema gestisca gli invii in sicurezza.</p></div><span class="campaign-panel-icon"><i class="fa fa-paper-plane-o"></i></span></div><div class="campaign-panel-body"><form method="post" action="<?= esc(portal_tenant_space_url('invii-massivi/create'), 'attr') ?>"><?= csrf_field() ?><div class="campaign-compose-grid"><div class="form-group"><label for="audience">Destinatari</label><select class="form-control" id="audience" name="audience_type"><option value="all_patients" <?= old('audience_type') === 'appointments_on_date' ? '' : 'selected' ?>>Tutti i pazienti con recapiti validi</option><option value="appointments_on_date" <?= old('audience_type') === 'appointments_on_date' ? 'selected' : '' ?>>Pazienti con appuntamento in una data</option></select></div><div class="form-group" id="date-wrap"><label for="appointment-date">Data appuntamenti</label><input id="appointment-date" type="date" class="form-control" name="appointment_date" value="<?= esc((string) old('appointment_date'), 'attr') ?>"></div><input type="hidden" name="campaign_form" value="1">
<div class="form-group campaign-message-field">
  <label>Canali di invio</label>
  <div class="checkbox"><label><input id="campaign-email" name="email_enabled" type="checkbox" value="1" <?= $emailSelected ? 'checked' : '' ?>> Email</label></div>
  <div class="checkbox"><label><input id="campaign-sms" name="sms_enabled" type="checkbox" value="1" <?= $smsSelected ? 'checked' : '' ?> <?= $smsEnabled ? '' : 'disabled' ?>> SMS</label></div>
  <?php if (!$smsEnabled): ?><p class="campaign-help">Gli SMS devono essere abilitati per questo spazio dal Super Master.</p><?php endif; ?>
  <div id="campaign-order-wrap"><label for="campaign-order">Ordine di invio e fallback</label><select id="campaign-order" class="form-control" name="first_channel"><option value="email" <?= old('first_channel') === 'sms' ? '' : 'selected' ?>>Prima email, poi SMS se fallisce</option><option value="sms" <?= old('first_channel') === 'sms' ? 'selected' : '' ?>>Prima SMS, poi email se fallisce</option></select></div>
  <p class="campaign-help">Se il primo canale accetta il messaggio, l’invio termina. In caso di errore o recapito mancante viene provato il secondo canale selezionato. Gli invii massivi non utilizzano WhatsApp.</p>
</div>
<div class="form-group campaign-message-field"><label for="campaign-message">Messaggio</label><textarea id="campaign-message" aria-describedby="campaign-sms-count campaign-sms-warning" class="form-control" name="message_text" rows="5" maxlength="2000" required placeholder="Scrivi il messaggio da inviare..."><?= esc((string) old('message_text')) ?></textarea><p id="campaign-sms-count" class="campaign-help"></p><div id="campaign-sms-warning" class="alert alert-warning" role="status" aria-live="polite" hidden></div><p class="campaign-help">Ogni paziente viene incluso una sola volta. Sono necessari un indirizzo email o un cellulare valido per i canali selezionati.</p></div></div><div class="campaign-submit-row"><p><i class="fa fa-shield"></i> La campagna continua anche se chiudi questa pagina. È previsto <strong>un tentativo di invio al minuto per spazio</strong>, condiviso fra tutte le campagne, compresi i fallback.</p><button class="btn btn-success" id="campaign-submit" type="submit"><i class="fa fa-clock-o"></i> Accoda campagna</button></div></form></div></section>
        <section class="campaign-panel" aria-labelledby="campaign-list-title"><div class="campaign-panel-heading"><div><h2 id="campaign-list-title">Registro campagne</h2><p>Controlla l’avanzamento e apri il dettaglio degli invii.</p></div><span class="campaign-panel-icon"><i class="fa fa-list-alt"></i></span></div><?php if ($campaigns === []): ?><div class="campaign-empty"><span class="billing-empty-icon"><i class="fa fa-paper-plane-o"></i></span><h3>Nessuna campagna ancora creata</h3><p>La prima campagna comparirà qui con lo stato di ogni invio.</p></div><?php else: ?><div class="table-responsive billing-dashboard-table-wrap"><table class="table billing-dashboard-table"><thead><tr><th>Creata</th><th>Destinatari</th><th>Stato</th><th>Avanzamento</th><th class="text-right">Azione</th></tr></thead><tbody><?php foreach ($campaigns as $row): ?><?php $rowStatus = (string) ($row['status'] ?? 'queued'); ?><tr><td class="billing-table-muted"><?= esc($formatDateTime($row['created_at'] ?? '')) ?></td><td><?= ($row['audience_type'] ?? '') === 'appointments_on_date' ? 'Appuntamenti del ' . esc($formatDate($row['appointment_date'] ?? '')) : 'Tutti i pazienti' ?></td><td><span class="campaign-state <?= $statusClass($rowStatus) ?>"><?= esc($labels[$rowStatus] ?? $rowStatus) ?></span></td><td class="campaign-progress"><strong><?= (int) ($row['sent_recipients'] ?? 0) ?></strong> inviati · <?= (int) ($row['pending_recipients'] ?? 0) ?> in attesa · <?= (int) ($row['failed_recipients'] ?? 0) ?> errori</td><td class="text-right"><?= view('tenant/mass_campaign_controls', ['campaign' => $row]) ?> <a class="billing-icon-action" href="<?= esc(portal_tenant_space_url('invii-massivi') . '?campaign=' . (int) $row['id_mass_campaign'], 'attr') ?>" title="Apri dettaglio" aria-label="Apri dettaglio"><i class="fa fa-arrow-right"></i></a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
        <?php if ($selected): ?><?php $selectedStatus = (string) ($selected['status'] ?? 'queued'); ?><section class="campaign-panel" aria-labelledby="campaign-detail-title"><div class="campaign-panel-heading"><div><h2 id="campaign-detail-title">Dettaglio campagna #<?= (int) $selected['id_mass_campaign'] ?></h2><p>Stato dei destinatari della campagna selezionata.</p></div><span class="campaign-state <?= $statusClass($selectedStatus) ?>"><?= esc($labels[$selectedStatus] ?? $selectedStatus) ?></span></div><div class="campaign-panel-body"><?= view('tenant/mass_campaign_controls', ['campaign' => $selected]) ?><?php if ($selectedStatus === 'paused'): ?><p class="campaign-help">Gli invii successivi sono sospesi. Un invio già in corso può terminare. Riprendi per continuare dai destinatari rimasti in attesa.</p><?php endif; ?><div class="campaign-selected-meta"><span>Canali: <strong><?= esc(implode(" → ", array_map("strtoupper", (array) json_decode((string) ($selected["channels_json"] ?? "[]"), true)))) ?></strong></span><span><i class="fa fa-users"></i> <strong><?= (int) ($selected['total_recipients'] ?? 0) ?></strong> destinatari</span><span><i class="fa fa-clock-o"></i> creata <?= esc($formatDateTime($selected['created_at'] ?? '')) ?></span></div><p class="campaign-message"><?= esc((string) $selected['message_text']) ?></p></div><div class="table-responsive billing-dashboard-table-wrap"><table class="table billing-dashboard-table"><thead><tr><th>Paziente</th><th>Recapiti</th><th>Stato</th><th>Canali tentati</th><th>Data invio</th><th>Esito</th></tr></thead><tbody><?php foreach ($recipients as $row): ?><?php $recipientStatus = (string) ($row['status'] ?? 'pending'); $attempts = (array) json_decode((string) ($row['attempts_json'] ?? '[]'), true); ?><tr><td><?= esc((string) ($row['patient_name'] ?? 'Paziente')) ?></td><td class="billing-table-muted"><?= esc(implode(' · ', array_filter([$row['recipient_email'] ?? '', $row['recipient_phone'] ?? '']))) ?></td><td><span class="campaign-state <?= $statusClass($recipientStatus) ?>"><?= esc($labels[$recipientStatus] ?? $recipientStatus) ?></span></td><td><?php foreach ($attempts as $attempt): ?><div title="<?= esc((string) ($attempt['error'] ?? ''), 'attr') ?>"><?= esc(strtoupper((string) ($attempt['channel'] ?? ''))) ?>: <?= !empty($attempt['ok']) ? 'accettato' : 'non riuscito' ?></div><?php endforeach; ?><?php if (!$attempts): ?>—<?php endif; ?><?php if ($recipientStatus === 'pending' && (int) ($row['channel_index'] ?? 0) > 0): ?><div>Secondo canale in attesa</div><?php endif; ?></td><td class="billing-table-muted"><?= esc($formatDateTime($row['sent_at'] ?? '')) ?></td><td><?= esc((string) ($row['error_text'] ?? '')) ?></td></tr><?php endforeach; ?><?php if ($recipients === []): ?><tr><td colspan="6" class="text-muted">Nessun destinatario.</td></tr><?php endif; ?></tbody></table></div></section><?php endif; ?>
      <?php endif; ?>
    </div></main>
  </div></section></div>
</div>
<script src="<?= base_url('public/plugins/jQuery/jquery-2.2.3.min.js') ?>"></script><script>!function(){var audience=document.getElementById('audience'),dateWrap=document.getElementById('date-wrap');if(!audience||!dateWrap)return;function updateDateVisibility(){dateWrap.style.display=audience.value==='appointments_on_date'?'block':'none'}audience.addEventListener('change',updateDateVisibility);updateDateVisibility()}();</script>
<script>
(function () {
    var email = document.getElementById('campaign-email');
    var sms = document.getElementById('campaign-sms');
    var orderWrap = document.getElementById('campaign-order-wrap');
    var submit = document.getElementById('campaign-submit');
    var message = document.getElementById('campaign-message');
    var count = document.getElementById('campaign-sms-count');
    var warning = document.getElementById('campaign-sms-warning');
    if (!message || !count || !warning) return;

    function messageLength() {
        return Array.from(message.value.trim()).length;
    }

    function smsNotice(length) {
        return 'Attenzione: il messaggio supera i 160 caratteri. Se viene utilizzato l’SMS, ogni blocco di 160 caratteri (anche parziale) consuma un SMS del pacchetto acquistato. '
            + 'Con questo testo (' + length + ' caratteri) verranno utilizzati '
            + Math.ceil(length / 160) + ' SMS per ciascun destinatario raggiunto tramite SMS.';
    }

    function updateSmsNotice() {
        var length = messageLength();
        count.textContent = length + ' caratteri';
        warning.hidden = !sms.checked || length <= 160;
        warning.textContent = sms.checked && length > 160 ? smsNotice(length) : '';
        orderWrap.hidden = !(email.checked && sms.checked);
        submit.disabled = !(email.checked || sms.checked);
    }

    message.addEventListener('input', updateSmsNotice);
    email.addEventListener('change', updateSmsNotice);
    sms.addEventListener('change', updateSmsNotice);
    message.form.addEventListener('submit', function (event) {
        var length = messageLength();
        if (sms.checked && length > 160 && !window.confirm(smsNotice(length) + '\n\nVuoi accodare comunque la campagna?')) {
            event.preventDefault();
            message.focus();
        }
    });
    updateSmsNotice();
}());
</script>
</body></html>
