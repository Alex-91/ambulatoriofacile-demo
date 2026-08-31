<?php
helper('portal');

$tenantCatalog = is_array($tenantCatalog ?? null) ? $tenantCatalog : [];
$selectedTenant = is_array($selectedTenant ?? null) ? $selectedTenant : null;
$accountStatus = is_array($accountStatus ?? null) ? $accountStatus : [];
$conversationDashboard = is_array($conversationDashboard ?? null) ? $conversationDashboard : [];
$errors = is_array($errors ?? null) ? $errors : [];
$gatewayReady = !empty($accountStatus['connected']) && !empty($accountStatus['logged_in']);
$selectedTenantId = (int) ($selectedTenant['id_tenant'] ?? 0);
$initialPayload = [
    'account_status' => $accountStatus,
    'conversation_dashboard' => $conversationDashboard,
];
$jsonFlags = JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT;
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Registro WhatsApp</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/assets/css/platform-console.css') ?>" rel="stylesheet" />
  <style>
    .support-intro { border:1px solid #dbe8eb; border-radius:12px; padding:18px 20px; background:linear-gradient(135deg,#f8fcfc 0%,#eff7f8 100%); margin-bottom:16px; }
    .tenant-filter { display:flex; align-items:flex-end; gap:10px; margin-top:14px; }
    .tenant-filter .form-group { flex:1; margin:0; }
    .wa-shell { border:1px solid #dfe8ea; border-radius:14px; overflow:hidden; background:#fff; box-shadow:0 4px 18px rgba(40,74,81,.06); }
    .wa-toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:15px 18px; border-bottom:1px solid #e6edef; background:#f8fbfb; }
    .wa-status { display:inline-flex; align-items:center; gap:7px; font-size:13px; font-weight:700; }
    .wa-status-dot { width:9px; height:9px; border-radius:50%; background:#b9c4c7; }
    .wa-status.is-online .wa-status-dot { background:#25d366; box-shadow:0 0 0 3px rgba(37,211,102,.13); }
    .wa-counts { color:#6f8287; font-size:12px; margin-top:4px; }
    .wa-refresh-note { min-height:18px; color:#74878c; font-size:11px; text-align:right; }
    .wa-layout { display:grid; grid-template-columns:minmax(245px,34%) minmax(0,1fr); min-height:570px; }
    .wa-sidebar { border-right:1px solid #e6edef; min-width:0; background:#fff; }
    .wa-sidebar-head { padding:14px; border-bottom:1px solid #edf1f2; }
    .wa-search { position:relative; }
    .wa-search i { position:absolute; left:12px; top:11px; color:#829398; }
    .wa-search input { border-radius:18px; padding-left:34px; background:#f5f8f9; border-color:#e5ecee; }
    .wa-conversations { height:505px; overflow-y:auto; }
    .wa-conversation { width:100%; border:0; border-bottom:1px solid #edf1f2; background:#fff; text-align:left; padding:13px 14px; display:flex; gap:11px; align-items:center; }
    .wa-conversation:hover,.wa-conversation.is-active { background:#edf8f5; }
    .wa-avatar { width:42px; height:42px; min-width:42px; border-radius:50%; background:#dff3ed; color:#16785d; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:16px; }
    .wa-conversation-copy { min-width:0; flex:1; }
    .wa-conversation-title { display:flex; justify-content:space-between; gap:8px; color:#22383d; font-weight:700; }
    .wa-conversation-title span:first-child,.wa-preview { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .wa-last-time { color:#839499; font-size:11px; font-weight:400; flex:none; }
    .wa-preview { color:#72858a; margin-top:4px; font-size:12px; }
    .wa-chat { display:flex; min-width:0; flex-direction:column; background:#efeae2; }
    .wa-chat-head { min-height:67px; padding:13px 18px; display:flex; align-items:center; gap:11px; border-bottom:1px solid #dfe7e8; background:#f7f9f9; }
    .wa-chat-name { font-weight:700; color:#21363a; }
    .wa-chat-peer { color:#72858a; font-size:12px; }
    .wa-messages { flex:1; height:503px; overflow-y:auto; padding:20px 22px; background-color:#efeae2; background-image:radial-gradient(rgba(77,96,91,.06) 1px,transparent 1px); background-size:18px 18px; }
    .wa-message-row { display:flex; margin-bottom:9px; }
    .wa-message-row.is-outgoing { justify-content:flex-end; }
    .wa-bubble { max-width:78%; min-width:90px; padding:8px 10px 6px; border-radius:8px; background:#fff; color:#26383b; box-shadow:0 1px 1px rgba(0,0,0,.08); white-space:pre-wrap; overflow-wrap:anywhere; }
    .wa-message-row.is-outgoing .wa-bubble { background:#d9fdd3; }
    .wa-message-meta { display:flex; justify-content:flex-end; gap:5px; color:#76898d; font-size:10px; margin-top:4px; }
    .wa-empty { padding:55px 24px; text-align:center; color:#6e8186; }
    .wa-empty i { display:block; color:#9bb7b0; font-size:44px; margin-bottom:12px; }
    .privacy-note { margin-top:12px; padding:10px 13px; border-left:3px solid #d7a624; background:#fffaf0; color:#735f26; font-size:12px; }
    @media (max-width:767px) {
      .tenant-filter { align-items:stretch; flex-direction:column; }
      .tenant-filter .form-group,.tenant-filter .btn { width:100%; }
      .wa-layout { grid-template-columns:1fr; }
      .wa-sidebar { border-right:0; border-bottom:1px solid #e6edef; }
      .wa-conversations { height:210px; }
      .wa-messages { height:350px; }
      .wa-bubble { max-width:88%; }
      .wa-toolbar { align-items:flex-start; flex-direction:column; }
    }
  </style>
</head>
<body class="platform-console-body">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items ?? [], 'portal_console_header' => true]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Registro WhatsApp</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Registro centralizzato delle conversazioni, separato per spazio cliente, riservato agli account master della piattaforma.
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
          <?php if (empty($gatewayConfigured)): ?>
            <div class="alert alert-warning">Il gateway WhatsApp non è configurato nell’applicazione centrale.</div>
          <?php endif; ?>

          <div class="support-intro">
            <h3 style="margin:0 0 8px;">Consultazione assistenza e reclami</h3>
            <p style="margin:0; color:#52676c;">
              Seleziona uno spazio per consultare il relativo storico. Da questo pannello non è possibile inviare messaggi o modificare le conversazioni.
            </p>
            <form method="get" action="<?= portal_platform_url('whatsapp') ?>" class="tenant-filter">
              <div class="form-group">
                <label for="tenant_id">Spazio cliente</label>
                <select class="form-control" id="tenant_id" name="tenant_id">
                  <?php if ($tenantCatalog === []): ?>
                    <option value="">Nessuno spazio collegato al gateway</option>
                  <?php else: ?>
                    <?php foreach ($tenantCatalog as $tenant): ?>
                      <?php $tenantId = (int) ($tenant['id_tenant'] ?? 0); ?>
                      <option value="<?= $tenantId ?>" <?= $tenantId === $selectedTenantId ? 'selected' : '' ?>>
                        <?= esc((string) ($tenant['tenant_name'] ?? ('Spazio #' . $tenantId))) ?>
                        — ID <?= $tenantId ?>
                        <?= !empty($tenant['status']) ? ' — ' . esc((string) $tenant['status']) : '' ?>
                      </option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </div>
              <button class="btn btn-primary" type="submit" <?= $tenantCatalog === [] ? 'disabled' : '' ?>>
                <i class="fa fa-folder-open"></i> Apri registro
              </button>
            </form>
          </div>

          <div class="wa-shell">
            <div class="wa-toolbar">
              <div>
                <div style="font-weight:700; color:#1d5f68; margin-bottom:5px;">
                  <i class="fa fa-building-o"></i>
                  <?= esc((string) ($selectedTenant['tenant_name'] ?? 'Nessuno spazio selezionato')) ?>
                </div>
                <div id="wa-status" class="wa-status<?= $gatewayReady ? ' is-online' : '' ?>">
                  <span class="wa-status-dot" aria-hidden="true"></span>
                  <span id="wa-status-label"><?= $gatewayReady ? 'WhatsApp collegato' : 'WhatsApp non collegato' ?></span>
                </div>
                <div class="wa-counts" id="wa-counts">
                  <?= (int) ($conversationDashboard['total_conversations'] ?? 0) ?> conversazioni ·
                  <?= (int) ($conversationDashboard['total_messages'] ?? 0) ?> messaggi registrati
                </div>
              </div>
              <div>
                <button type="button" class="btn btn-default btn-sm" id="wa-refresh" <?= $selectedTenantId <= 0 ? 'disabled' : '' ?>>
                  <i class="fa fa-refresh"></i> Aggiorna registro
                </button>
                <div class="wa-refresh-note" id="wa-refresh-note" aria-live="polite"></div>
              </div>
            </div>

            <div class="wa-layout">
              <aside class="wa-sidebar" aria-label="Elenco conversazioni">
                <div class="wa-sidebar-head">
                  <div class="wa-search">
                    <i class="fa fa-search" aria-hidden="true"></i>
                    <input type="search" class="form-control" id="wa-search" placeholder="Cerca nome o numero" autocomplete="off">
                  </div>
                </div>
                <div class="wa-conversations" id="wa-conversations"></div>
              </aside>

              <section class="wa-chat" aria-label="Conversazione selezionata">
                <div class="wa-chat-head">
                  <div class="wa-avatar" id="wa-chat-avatar"><i class="fa fa-whatsapp"></i></div>
                  <div>
                    <div class="wa-chat-name" id="wa-chat-name">Nessuna conversazione</div>
                    <div class="wa-chat-peer" id="wa-chat-peer">Seleziona un contatto dal registro</div>
                  </div>
                </div>
                <div class="wa-messages" id="wa-messages" aria-live="polite"></div>
              </section>
            </div>
          </div>

          <div class="privacy-note">
            <i class="fa fa-lock"></i>
            Questo registro contiene comunicazioni dei clienti: consultalo solo per assistenza, verifiche tecniche o gestione di reclami autorizzati.
          </div>
        </div>
      </div>
    </section>
  </div>
</div>

<script>
(function () {
  'use strict';

  var dashboard = <?= json_encode($initialPayload, $jsonFlags) ?>;
  var refreshUrl = <?= json_encode((string) ($refreshUrl ?? ''), $jsonFlags) ?>;
  var selectedPeer = '';
  var refreshing = false;
  var elements = {
    list: document.getElementById('wa-conversations'),
    search: document.getElementById('wa-search'),
    messages: document.getElementById('wa-messages'),
    name: document.getElementById('wa-chat-name'),
    peer: document.getElementById('wa-chat-peer'),
    avatar: document.getElementById('wa-chat-avatar'),
    status: document.getElementById('wa-status'),
    statusLabel: document.getElementById('wa-status-label'),
    counts: document.getElementById('wa-counts'),
    refresh: document.getElementById('wa-refresh'),
    refreshNote: document.getElementById('wa-refresh-note')
  };

  function conversations() {
    var data = dashboard && dashboard.conversation_dashboard;
    return data && Array.isArray(data.conversations) ? data.conversations : [];
  }

  function selectedConversation() {
    return conversations().find(function (row) { return String(row.peer || '') === selectedPeer; }) || null;
  }

  function initials(label) {
    var parts = String(label || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return 'WA';
    return parts.length === 1
      ? parts[0].slice(0, 2).toUpperCase()
      : (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
  }

  function formatDate(value, shortFormat) {
    if (!value) return '';
    var date = new Date(value);
    if (isNaN(date.getTime())) return String(value);
    return new Intl.DateTimeFormat('it-IT', shortFormat
      ? {day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}
      : {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}).format(date);
  }

  function messageText(message) {
    var text = String(message.text || '').trim();
    if (text) return text;
    var labels = {image:'📷 Immagine',video:'🎥 Video',document:'📄 Documento',audio:'🎤 Messaggio audio',sticker:'Sticker',location:'📍 Posizione',contact:'👤 Contatto'};
    return labels[String(message.message_type || '')] || 'Messaggio non testuale';
  }

  function emptyState(icon, title, detail) {
    var box = document.createElement('div'); box.className = 'wa-empty';
    var symbol = document.createElement('i'); symbol.className = 'fa ' + icon;
    var heading = document.createElement('strong'); heading.textContent = title;
    var copy = document.createElement('div'); copy.textContent = detail; copy.style.marginTop = '5px';
    box.appendChild(symbol); box.appendChild(heading); box.appendChild(copy);
    return box;
  }

  function renderStatus() {
    var status = dashboard && dashboard.account_status ? dashboard.account_status : {};
    var ready = Boolean(status.connected && status.logged_in);
    elements.status.classList.toggle('is-online', ready);
    elements.statusLabel.textContent = ready ? 'WhatsApp collegato' : 'WhatsApp non collegato';
    var data = dashboard && dashboard.conversation_dashboard ? dashboard.conversation_dashboard : {};
    elements.counts.textContent = Number(data.total_conversations || 0) + ' conversazioni · '
      + Number(data.total_messages || 0) + ' messaggi registrati';
  }

  function renderList() {
    elements.list.replaceChildren();
    var term = String(elements.search.value || '').trim().toLowerCase();
    var rows = conversations().filter(function (row) {
      return !term || String(row.label || '').toLowerCase().indexOf(term) !== -1
        || String(row.peer || '').toLowerCase().indexOf(term) !== -1;
    });
    if (!rows.length) {
      elements.list.appendChild(emptyState('fa-archive', term ? 'Nessun risultato' : 'Registro vuoto', term ? 'Prova un altro nome o numero.' : 'Non risultano conversazioni per questo spazio.'));
      return;
    }
    rows.forEach(function (conversation) {
      var button = document.createElement('button'); button.type = 'button';
      button.className = 'wa-conversation' + (String(conversation.peer || '') === selectedPeer ? ' is-active' : '');
      var avatar = document.createElement('span'); avatar.className = 'wa-avatar'; avatar.textContent = initials(conversation.label || conversation.peer);
      var copy = document.createElement('span'); copy.className = 'wa-conversation-copy';
      var title = document.createElement('span'); title.className = 'wa-conversation-title';
      var label = document.createElement('span'); label.textContent = String(conversation.label || conversation.peer || 'Contatto');
      var time = document.createElement('span'); time.className = 'wa-last-time'; time.textContent = formatDate(conversation.last_at, true);
      var preview = document.createElement('span'); preview.className = 'wa-preview'; preview.textContent = String(conversation.last_message || 'Messaggio');
      title.appendChild(label); title.appendChild(time); copy.appendChild(title); copy.appendChild(preview); button.appendChild(avatar); button.appendChild(copy);
      button.addEventListener('click', function () { selectedPeer = String(conversation.peer || ''); render(); });
      elements.list.appendChild(button);
    });
  }

  function outgoingStatusLabel(message) {
    var status = String(message.delivery_status || 'sent');
    if (status === 'read') return 'Letto';
    if (status === 'delivered') return 'Consegnato';
    if (status === 'failed') return 'Non inviato';
    return 'Inviato';
  }

  function renderChat() {
    elements.messages.replaceChildren();
    var conversation = selectedConversation();
    if (!conversation) {
      elements.name.textContent = 'Nessuna conversazione'; elements.peer.textContent = 'Seleziona un contatto dal registro'; elements.avatar.textContent = 'WA';
      elements.messages.appendChild(emptyState('fa-comments-o', 'Seleziona una conversazione', 'Qui vedrai lo storico completo del contatto nello spazio scelto.'));
      return;
    }
    elements.name.textContent = String(conversation.label || conversation.peer || 'Contatto');
    elements.peer.textContent = String(conversation.peer || ''); elements.avatar.textContent = initials(conversation.label || conversation.peer);
    (Array.isArray(conversation.messages) ? conversation.messages : []).forEach(function (message) {
      var outgoing = String(message.direction || 'incoming') === 'outgoing';
      var row = document.createElement('div'); row.className = 'wa-message-row' + (outgoing ? ' is-outgoing' : '');
      var bubble = document.createElement('div'); bubble.className = 'wa-bubble';
      var text = document.createElement('div'); text.textContent = messageText(message);
      var metaTime = outgoing ? (message.read_at || message.delivered_at || message.received_at) : message.received_at;
      var meta = document.createElement('div'); meta.className = 'wa-message-meta'; meta.textContent = (outgoing ? outgoingStatusLabel(message) + ' · ' : 'Ricevuto · ') + formatDate(metaTime, false);
      bubble.appendChild(text); bubble.appendChild(meta); row.appendChild(bubble); elements.messages.appendChild(row);
    });
    window.requestAnimationFrame(function () { elements.messages.scrollTop = elements.messages.scrollHeight; });
  }

  function render() { renderStatus(); renderList(); renderChat(); }

  function refresh() {
    if (!refreshUrl || document.hidden || refreshing) return;
    refreshing = true; elements.refresh.disabled = true; elements.refreshNote.textContent = 'Aggiornamento…';
    fetch(refreshUrl, {credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}})
      .then(function (response) { if (!response.ok) throw new Error('Aggiornamento non disponibile'); return response.json(); })
      .then(function (payload) {
        if (!payload || !payload.ok) throw new Error(payload && payload.message ? payload.message : 'Aggiornamento non disponibile');
        dashboard = payload;
        if (!selectedConversation()) selectedPeer = conversations().length ? String(conversations()[0].peer || '') : '';
        render();
        elements.refreshNote.textContent = 'Aggiornato alle ' + new Intl.DateTimeFormat('it-IT',{hour:'2-digit',minute:'2-digit'}).format(new Date());
      })
      .catch(function () { elements.refreshNote.textContent = 'Aggiornamento non riuscito'; })
      .finally(function () { refreshing = false; elements.refresh.disabled = false; });
  }

  elements.search.addEventListener('input', renderList);
  elements.refresh.addEventListener('click', refresh);
  if (conversations().length) selectedPeer = String(conversations()[0].peer || '');
  render();
  window.setInterval(refresh, 10000);
})();
</script>
</body>
</html>
