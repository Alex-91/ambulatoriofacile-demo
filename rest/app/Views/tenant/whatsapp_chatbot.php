<?php
helper('portal');

$platformConsole = !empty($platformConsole);
$tenantCatalog = is_array($tenantCatalog ?? null) ? $tenantCatalog : [];
$tenantSelectionUrl = trim((string) ($tenantSelectionUrl ?? ''));
if (!$platformConsole) {
    $menuDataAdmin = session()->get('menuDataAdmin');
    $sidebarMenuItems = is_array($menuDataAdmin['result'] ?? null) ? $menuDataAdmin['result'] : [];
    if (empty($menu_items) || !is_array($menu_items)) {
        $menu_items = $sidebarMenuItems !== [] ? $sidebarMenuItems : (session()->get('header_menu_items') ?? []);
    }
} else {
    $menu_items = [];
}

$config = is_array($config ?? null) ? $config : [];
$rules = is_array($config['rules'] ?? null) ? $config['rules'] : [];
$dashboard = is_array($dashboard ?? null) ? $dashboard : [];
$messages = is_array($dashboard['messages'] ?? null) ? $dashboard['messages'] : [];
$errors = is_array($errors ?? null) ? $errors : [];
$statusLabels = [
    'processed' => 'Eseguita',
    'replied' => 'Risposta inviata',
    'unmatched' => 'Nessuna regola',
    'no_context' => 'Nessun appuntamento',
    'ignored' => 'Ignorata',
    'failed' => 'Errore',
    'reply_failed' => 'Risposta fallita',
    'processing' => 'In elaborazione',
];
$actionLabels = [
    'confirm_appointment' => 'Conferma appuntamento',
    'cancel_appointment' => 'Annulla appuntamento',
    'send_reply' => 'Invia solo una risposta',
];
$formatDate = static function ($value): string {
    try {
        return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Rome'))
            ->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return (string) $value;
    }
};
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Chatbot WhatsApp</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet">
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet">
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('public/assets/css/billing-ts-ui.css') ?>" rel="stylesheet">
  <style>
    .bot-hero { display:flex; align-items:flex-start; justify-content:space-between; gap:18px; padding:20px; margin-bottom:18px; border:1px solid #d9ebe5; border-radius:14px; background:linear-gradient(135deg,#f6fcf9,#edf8f4); }
    .bot-hero h2 { margin:4px 0 7px; color:#143c34; font-size:24px; }
    .bot-hero p { max-width:760px; margin:0; color:#60716d; }
    .bot-kicker { color:#16805f; font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
    .bot-state { flex:none; padding:7px 12px; border:1px solid #cfdad7; border-radius:999px; background:#fff; color:#65736f; font-size:12px; font-weight:700; }
    .bot-state.is-on { border-color:#a9dfcc; background:#edfaf5; color:#12684f; }
    .bot-grid { display:grid; grid-template-columns:minmax(0,1.25fr) minmax(290px,.75fr); gap:18px; }
    .bot-card { margin-bottom:18px; border:1px solid #e1e8e6; border-radius:13px; background:#fff; box-shadow:0 6px 20px rgba(16,44,37,.04); overflow:hidden; }
    .bot-card-head { padding:16px 18px; border-bottom:1px solid #e8eeec; }
    .bot-card-head h3 { margin:0 0 4px; font-size:17px; }
    .bot-card-head p { margin:0; color:#6c7b77; font-size:12px; }
    .bot-card-body { padding:18px; }
    .bot-enable { display:flex; align-items:center; gap:11px; padding:13px 14px; margin-bottom:16px; border-radius:10px; background:#f5f8f7; }
    .bot-enable input { width:18px; height:18px; margin:0; }
    .bot-enable strong { display:block; }
    .bot-enable small { display:block; margin-top:2px; color:#71807c; }
    .bot-rule { position:relative; padding:15px; margin-bottom:12px; border:1px solid #dde6e3; border-radius:11px; background:#fbfcfc; }
    .bot-rule-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px; }
    .bot-rule-head strong { color:#24463e; }
    .bot-rule-actions { display:flex; gap:5px; }
    .bot-rule-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .bot-rule .form-group:last-child { margin-bottom:0; }
    .bot-rule-reply { grid-column:1/-1; }
    .bot-tokens { margin-top:6px; color:#71807c; font-size:11px; }
    .bot-tokens code { color:#187459; background:#edf7f3; }
    .bot-add { width:100%; border-style:dashed; }
    .bot-flow { margin:0; padding:0; list-style:none; }
    .bot-flow li { position:relative; padding:0 0 18px 34px; color:#536661; }
    .bot-flow li:before { content:''; position:absolute; left:10px; top:21px; bottom:0; width:2px; background:#dfe8e5; }
    .bot-flow li:last-child:before { display:none; }
    .bot-flow-number { position:absolute; left:0; top:0; display:flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; background:#1c8b69; color:#fff; font-size:11px; font-weight:700; }
    .bot-metrics { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-bottom:18px; }
    .bot-metric { padding:13px; border:1px solid #e1e8e6; border-radius:10px; background:#fff; }
    .bot-metric span { display:block; color:#72817d; font-size:10px; font-weight:700; text-transform:uppercase; }
    .bot-metric strong { display:block; margin-top:5px; color:#1b5e4d; font-size:22px; }
    .bot-log-text { max-width:260px; white-space:normal; overflow-wrap:anywhere; }
    .status-pill { display:inline-block; padding:4px 8px; border-radius:999px; background:#eef1f0; color:#52635f; font-size:11px; font-weight:700; }
    .status-pill.is-ok { background:#eaf8f2; color:#147054; }
    .status-pill.is-error { background:#fff0ee; color:#b42318; }
    @media(max-width:900px){ .bot-grid{grid-template-columns:1fr}.bot-rule-grid{grid-template-columns:1fr}.bot-rule-reply{grid-column:auto}.bot-hero{flex-direction:column}.bot-metrics{grid-template-columns:1fr} }
  </style>
</head>
<body class="skin-blue sidebar-mini billing-ts-ui">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items, 'portal_console_header' => $platformConsole]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Chatbot WhatsApp <small><?= esc((string) ($tenant['tenant_name'] ?? 'Spazio cliente')) ?></small></h1>
      <ol class="breadcrumb">
        <?php if ($platformConsole): ?>
          <li><a href="<?= portal_platform_url('spazi-clienti') ?>"><i class="fa fa-sitemap"></i> Console piattaforma</a></li>
        <?php else: ?>
          <li><a href="<?= portal_tenant_agenda_url() ?>"><i class="fa fa-calendar"></i> Agenda</a></li>
        <?php endif; ?>
        <li class="active">Chatbot WhatsApp</li>
      </ol>
    </section>

    <section class="content">
      <?php if ($platformConsole): ?>
      <div class="row">
        <div class="col-md-3">
          <?= view('partials/sidebar_platform', ['platformMasterEmails' => $platformMasterEmails ?? []]) ?>
        </div>
        <div class="col-md-9">
      <?php endif; ?>
      <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc((string) $success) ?></div><?php endif; ?>
      <?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= esc((string) $error) ?></div><?php endforeach; ?>
      <?php if (!$gatewayAvailable): ?>
        <div class="alert alert-warning"><i class="fa fa-exclamation-triangle"></i> Il chatbot può essere preparato, ma partirà solo quando questo spazio sarà instradato al gateway e avrà un dispositivo collegato. <a href="<?= esc($notificationsUrl) ?>">Gestisci WhatsApp</a>.</div>
      <?php endif; ?>
      <?php if (empty($dashboard['ready'])): ?>
        <div class="alert alert-warning"><i class="fa fa-database"></i> La migration del chatbot non è ancora applicata in questo ambiente. La configurazione diventerà salvabile dopo l’aggiornamento del database.</div>
      <?php endif; ?>

      <?php if ($platformConsole): ?>
      <section class="bot-card">
        <div class="bot-card-head"><h3>Spazio cliente</h3><p>Seleziona lo spazio da configurare. Il chatbot, le regole e i messaggi restano separati per tenant.</p></div>
        <div class="bot-card-body">
          <form method="get" action="<?= esc($tenantSelectionUrl) ?>" class="row">
            <div class="col-sm-9 form-group" style="margin-bottom:0">
              <label for="tenant-id">Spazio cliente</label>
              <select class="form-control" id="tenant-id" name="tenant_id">
                <?php foreach ($tenantCatalog as $catalogTenant): ?>
                  <?php $catalogTenantId = (int) ($catalogTenant['id_tenant'] ?? 0); ?>
                  <option value="<?= $catalogTenantId ?>"<?= $catalogTenantId === (int) ($tenant['id_tenant'] ?? 0) ? ' selected' : '' ?>><?= esc((string) ($catalogTenant['tenant_name'] ?? ('Spazio #' . $catalogTenantId))) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-3" style="padding-top:25px"><button class="btn btn-primary btn-block" type="submit"><i class="fa fa-folder-open"></i> Apri spazio</button></div>
          </form>
        </div>
      </section>
      <?php endif; ?>

      <div class="bot-hero">
        <div>
          <div class="bot-kicker">Automazione isolata per spazio</div>
          <h2>Costruisci le risposte del tuo studio</h2>
          <p>Il bot legge le risposte ricevute dal numero WhatsApp di questo spazio. Le azioni agenda vengono eseguite solo sulla richiesta pendente inviata a quello stesso paziente.</p>
        </div>
        <span class="bot-state<?= !empty($config['enabled']) ? ' is-on' : '' ?>"><?= !empty($config['enabled']) ? 'Bot attivo' : 'Bot in pausa' ?></span>
      </div>

      <div class="bot-grid">
        <main>
          <form method="post" action="<?= esc($saveUrl) ?>" id="chatbot-form">
            <?= csrf_field() ?>
            <?php if ($platformConsole): ?><input type="hidden" name="tenant_id" value="<?= (int) ($tenant['id_tenant'] ?? 0) ?>"><?php endif; ?>
            <section class="bot-card">
              <div class="bot-card-head"><h3>Attivazione e contesto</h3><p>Decidi quando aprire l’attesa di una risposta per un appuntamento.</p></div>
              <div class="bot-card-body">
                <label class="bot-enable">
                  <input type="hidden" name="enabled" value="0">
                  <input type="checkbox" name="enabled" value="1"<?= !empty($config['enabled']) ? ' checked' : '' ?>>
                  <span><strong>Attiva chatbot per questo spazio</strong><small>Le regole non influenzano nessun altro tenant.</small></span>
                </label>
                <div class="row">
                  <div class="col-sm-5 form-group">
                    <label for="response-window">Validità della risposta (ore)</label>
                    <input class="form-control" id="response-window" type="number" name="response_window_hours" min="1" max="720" value="<?= (int) ($config['response_window_hours'] ?? 168) ?>">
                  </div>
                  <div class="col-sm-7 form-group">
                    <label>Apri l’attesa quando invio</label><br>
                    <label class="checkbox-inline"><input type="checkbox" name="open_on[]" value="appointment_reminder"<?= in_array('appointment_reminder', (array) ($config['open_on'] ?? []), true) ? ' checked' : '' ?>> Reminder</label>
                    <label class="checkbox-inline"><input type="checkbox" name="open_on[]" value="patient_booking"<?= in_array('patient_booking', (array) ($config['open_on'] ?? []), true) ? ' checked' : '' ?>> Conferma appena prenotato</label>
                  </div>
                </div>
                <div class="form-group">
                  <label for="prompt-text">Istruzioni inserite nel reminder</label>
                  <textarea class="form-control" id="prompt-text" name="prompt_text" rows="3" maxlength="2000"><?= esc((string) ($config['prompt_text'] ?? '')) ?></textarea>
                </div>
                <div class="form-group">
                  <label for="fallback-reply">Risposta se nessuna regola corrisponde <small>(facoltativa)</small></label>
                  <textarea class="form-control" id="fallback-reply" name="fallback_reply" rows="3" maxlength="2000" placeholder="Esempio: Non ho capito. Rispondi 1 per confermare o 2 per annullare."><?= esc((string) ($config['fallback_reply'] ?? '')) ?></textarea>
                </div>
              </div>
            </section>

            <section class="bot-card">
              <div class="bot-card-head"><h3>Regole di risposta</h3><p>Ogni regola riconosce una o più risposte esatte e avvia una sola azione.</p></div>
              <div class="bot-card-body">
                <div id="rules-list">
                  <?php foreach ($rules as $index => $rule): ?>
                    <article class="bot-rule" data-rule>
                      <input type="hidden" data-field="id" name="rules[<?= $index ?>][id]" value="<?= esc((string) ($rule['id'] ?? '')) ?>">
                      <div class="bot-rule-head">
                        <strong data-rule-title><?= esc((string) ($rule['name'] ?? ('Regola ' . ($index + 1)))) ?></strong>
                        <div class="bot-rule-actions">
                          <button class="btn btn-xs btn-default" type="button" data-move-up title="Sposta prima"><i class="fa fa-arrow-up"></i></button>
                          <button class="btn btn-xs btn-default" type="button" data-move-down title="Sposta dopo"><i class="fa fa-arrow-down"></i></button>
                          <button class="btn btn-xs btn-danger" type="button" data-remove-rule><i class="fa fa-trash"></i> Rimuovi</button>
                        </div>
                      </div>
                      <div class="bot-rule-grid">
                        <div class="form-group">
                          <label>Nome regola</label>
                          <input class="form-control" data-field="name" name="rules[<?= $index ?>][name]" maxlength="120" value="<?= esc((string) ($rule['name'] ?? '')) ?>" required>
                        </div>
                        <div class="form-group">
                          <label>Azione</label>
                          <select class="form-control" data-field="action" name="rules[<?= $index ?>][action]">
                            <?php foreach ($actionLabels as $action => $label): ?><option value="<?= esc($action) ?>"<?= (string) ($rule['action'] ?? '') === $action ? ' selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?>
                          </select>
                        </div>
                        <div class="form-group">
                          <label>Risposte riconosciute</label>
                          <input class="form-control" data-field="answers" name="rules[<?= $index ?>][answers]" value="<?= esc(implode(', ', (array) ($rule['answers'] ?? []))) ?>" placeholder="1, sì, confermo" required>
                          <small class="text-muted">Separale con virgola, punto e virgola o a capo.</small>
                        </div>
                        <div class="form-group">
                          <label>Stato</label><br>
                          <input type="hidden" data-field="enabled-hidden" name="rules[<?= $index ?>][enabled]" value="0">
                          <label class="checkbox-inline"><input data-field="enabled" type="checkbox" name="rules[<?= $index ?>][enabled]" value="1"<?= !empty($rule['enabled']) ? ' checked' : '' ?>> Regola attiva</label>
                        </div>
                        <div class="form-group bot-rule-reply">
                          <label>Risposta inviata dopo l’azione</label>
                          <textarea class="form-control" data-field="reply" name="rules[<?= $index ?>][reply]" rows="3" maxlength="2000"><?= esc((string) ($rule['reply'] ?? '')) ?></textarea>
                          <div class="bot-tokens">Segnaposto: <code>{{paziente}}</code> <code>{{data_ora}}</code> <code>{{dottore}}</code> <code>{{nome_spazio}}</code></div>
                        </div>
                      </div>
                    </article>
                  <?php endforeach; ?>
                </div>
                <button class="btn btn-default bot-add" id="add-rule" type="button"><i class="fa fa-plus"></i> Aggiungi regola</button>
              </div>
            </section>

            <button class="btn btn-success btn-lg" type="submit"<?= empty($dashboard['ready']) ? ' disabled' : '' ?>><i class="fa fa-save"></i> Salva chatbot dello spazio</button>
          </form>
        </main>

        <aside>
          <div class="bot-metrics">
            <div class="bot-metric"><span>In attesa</span><strong><?= (int) ($dashboard['pending'] ?? 0) ?></strong></div>
            <div class="bot-metric"><span>Gestite 30 gg</span><strong><?= (int) ($dashboard['processed_30_days'] ?? 0) ?></strong></div>
            <div class="bot-metric"><span>Errori 30 gg</span><strong><?= (int) ($dashboard['failed_30_days'] ?? 0) ?></strong></div>
          </div>
          <section class="bot-card">
            <div class="bot-card-head"><h3>Come funziona</h3></div>
            <div class="bot-card-body">
              <ol class="bot-flow">
                <li><span class="bot-flow-number">1</span>Il reminder WhatsApp apre una richiesta legata all’ID appuntamento.</li>
                <li><span class="bot-flow-number">2</span>Il gateway inoltra la risposta firmata indicando tenant e messaggio.</li>
                <li><span class="bot-flow-number">3</span>La prima regola compatibile aggiorna l’agenda, una sola volta.</li>
                <li><span class="bot-flow-number">4</span>Il paziente riceve subito la risposta configurata.</li>
              </ol>
            </div>
          </section>
        </aside>
      </div>

      <section class="bot-card" style="margin-top:18px">
        <div class="bot-card-head"><h3>Ultimi messaggi elaborati</h3><p>Registro separato dello spazio corrente; non mostra conversazioni degli altri tenant.</p></div>
        <div class="table-responsive">
          <table class="table table-striped table-hover" style="margin-bottom:0">
            <thead><tr><th>Quando</th><th>Numero</th><th>Messaggio</th><th>Azione</th><th>Appuntamento</th><th>Esito</th></tr></thead>
            <tbody>
            <?php if ($messages === []): ?><tr><td colspan="6" class="text-muted text-center" style="padding:25px">Nessun messaggio elaborato.</td></tr><?php endif; ?>
            <?php foreach ($messages as $message):
                $status = (string) ($message['status'] ?? '');
                $statusClass = in_array($status, ['processed', 'replied'], true) ? ' is-ok' : (in_array($status, ['failed', 'reply_failed'], true) ? ' is-error' : '');
            ?>
              <tr>
                <td><?= esc($formatDate($message['received_at'] ?? $message['created_at'] ?? '')) ?></td>
                <td>+<?= esc((string) ($message['phone_key'] ?? '')) ?></td>
                <td class="bot-log-text"><?= esc((string) ($message['message_text'] ?? '')) ?></td>
                <td><?= esc((string) ($actionLabels[$message['action_name'] ?? ''] ?? ($message['action_name'] ?? '-'))) ?></td>
                <td><?= (int) ($message['appointment_id'] ?? 0) > 0 ? '#' . (int) $message['appointment_id'] : '-' ?></td>
                <td><span class="status-pill<?= $statusClass ?>"><?= esc((string) ($statusLabels[$status] ?? $status)) ?></span><?php if (!empty($message['error_text'])): ?><div class="text-danger small"><?= esc((string) $message['error_text']) ?></div><?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php if ($platformConsole): ?>
        </div>
      </div>
      <?php endif; ?>
    </section>
  </div>
</div>

<script>
(function () {
  var list = document.getElementById('rules-list');
  var addButton = document.getElementById('add-rule');
  if (!list || !addButton) return;

  function reindex() {
    Array.prototype.forEach.call(list.querySelectorAll('[data-rule]'), function (rule, index) {
      Array.prototype.forEach.call(rule.querySelectorAll('[data-field]'), function (field) {
        field.name = 'rules[' + index + '][' + field.getAttribute('data-field').replace('-hidden', '') + ']';
      });
      var name = rule.querySelector('[data-field="name"]');
      var title = rule.querySelector('[data-rule-title]');
      if (name && title) {
        var updateTitle = function () { title.textContent = name.value.trim() || ('Regola ' + (index + 1)); };
        name.oninput = updateTitle;
        updateTitle();
      }
    });
  }

  function bindControls(root) {
    var button = root.querySelector('[data-remove-rule]');
    if (button) button.onclick = function () { root.remove(); reindex(); };
    var up = root.querySelector('[data-move-up]');
    if (up) up.onclick = function () {
      var previous = root.previousElementSibling;
      if (previous) list.insertBefore(root, previous);
      reindex();
    };
    var down = root.querySelector('[data-move-down]');
    if (down) down.onclick = function () {
      var next = root.nextElementSibling;
      if (next) list.insertBefore(next, root);
      reindex();
    };
  }

  Array.prototype.forEach.call(list.querySelectorAll('[data-rule]'), bindControls);
  addButton.onclick = function () {
    if (list.querySelectorAll('[data-rule]').length >= 30) return;
    var article = document.createElement('article');
    article.className = 'bot-rule';
    article.setAttribute('data-rule', '');
    article.innerHTML = '<input type="hidden" data-field="id" value="">' +
      '<div class="bot-rule-head"><strong data-rule-title>Nuova regola</strong><div class="bot-rule-actions"><button class="btn btn-xs btn-default" type="button" data-move-up title="Sposta prima"><i class="fa fa-arrow-up"></i></button><button class="btn btn-xs btn-default" type="button" data-move-down title="Sposta dopo"><i class="fa fa-arrow-down"></i></button><button class="btn btn-xs btn-danger" type="button" data-remove-rule><i class="fa fa-trash"></i> Rimuovi</button></div></div>' +
      '<div class="bot-rule-grid">' +
      '<div class="form-group"><label>Nome regola</label><input class="form-control" data-field="name" maxlength="120" value="Nuova regola" required></div>' +
      '<div class="form-group"><label>Azione</label><select class="form-control" data-field="action"><option value="send_reply">Invia solo una risposta</option><option value="confirm_appointment">Conferma appuntamento</option><option value="cancel_appointment">Annulla appuntamento</option></select></div>' +
      '<div class="form-group"><label>Risposte riconosciute</label><input class="form-control" data-field="answers" placeholder="esempio, informazioni" required><small class="text-muted">Separale con virgola, punto e virgola o a capo.</small></div>' +
      '<div class="form-group"><label>Stato</label><br><input type="hidden" data-field="enabled-hidden" value="0"><label class="checkbox-inline"><input data-field="enabled" type="checkbox" value="1" checked> Regola attiva</label></div>' +
      '<div class="form-group bot-rule-reply"><label>Risposta inviata dopo l’azione</label><textarea class="form-control" data-field="reply" rows="3" maxlength="2000"></textarea><div class="bot-tokens">Segnaposto: <code>{{paziente}}</code> <code>{{data_ora}}</code> <code>{{dottore}}</code> <code>{{nome_spazio}}</code></div></div>' +
      '</div>';
    list.appendChild(article);
    bindControls(article);
    reindex();
  };
  reindex();
})();
</script>
</body>
</html>
