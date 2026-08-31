<?php
helper('portal');

$menuDataAdmin = session()->get('menuDataAdmin');
$sidebarMenuItems = is_array($menuDataAdmin['result'] ?? null) ? $menuDataAdmin['result'] : [];
if (empty($menu_items) || !is_array($menu_items)) {
    $menu_items = $sidebarMenuItems !== [] ? $sidebarMenuItems : (session()->get('header_menu_items') ?? []);
}

$accountStatus = is_array($accountStatus ?? null) ? $accountStatus : [];
$conversationDashboard = is_array($conversationDashboard ?? null) ? $conversationDashboard : [];
$errors = is_array($errors ?? null) ? $errors : [];
$gatewayReady = !empty($accountStatus['connected']) && !empty($accountStatus['logged_in']);
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
  <title>AmbulatorioFacile | Conversazioni WhatsApp</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <style>
    .nav-pills.nav-stacked > li.active > a { background-color:#2c8895; color:#fff; }
    .wa-shell { border:1px solid #dfe8ea; border-radius:14px; overflow:hidden; background:#fff; box-shadow:0 4px 18px rgba(40,74,81,.06); }
    .wa-toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:15px 18px; border-bottom:1px solid #e6edef; background:#f8fbfb; }
    .wa-status { display:inline-flex; align-items:center; gap:7px; font-size:13px; font-weight:700; }
    .wa-status-dot { width:9px; height:9px; border-radius:50%; background:#b9c4c7; }
    .wa-status.is-online .wa-status-dot { background:#25d366; box-shadow:0 0 0 3px rgba(37,211,102,.13); }
    .wa-layout { display:grid; grid-template-columns:minmax(245px, 34%) minmax(0, 1fr); min-height:590px; }
    .wa-sidebar { border-right:1px solid #e6edef; background:#fff; min-width:0; }
    .wa-sidebar-head { padding:14px; border-bottom:1px solid #edf1f2; }
    .wa-search { position:relative; }
    .wa-search i { position:absolute; left:12px; top:11px; color:#829398; }
    .wa-search input { border-radius:18px; padding-left:34px; background:#f5f8f9; border-color:#e5ecee; }
    .wa-conversations { height:526px; overflow-y:auto; }
    .wa-conversation { width:100%; border:0; border-bottom:1px solid #edf1f2; background:#fff; text-align:left; padding:13px 14px; display:flex; gap:11px; align-items:center; }
    .wa-conversation:hover, .wa-conversation.is-active { background:#edf8f5; }
    .wa-avatar { width:42px; height:42px; min-width:42px; border-radius:50%; background:#dff3ed; color:#16785d; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:16px; }
    .wa-conversation-copy { min-width:0; flex:1; }
    .wa-conversation-title { display:flex; justify-content:space-between; gap:8px; color:#22383d; font-weight:700; }
    .wa-conversation-title span:first-child, .wa-preview { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .wa-last-time { color:#839499; font-size:11px; font-weight:400; flex:none; }
    .wa-preview { color:#72858a; margin-top:4px; font-size:12px; }
    .wa-chat { display:flex; min-width:0; flex-direction:column; background:#efeae2; }
    .wa-chat-head { min-height:67px; padding:13px 18px; display:flex; align-items:center; gap:11px; border-bottom:1px solid #dfe7e8; background:#f7f9f9; }
    .wa-chat-name { font-weight:700; color:#21363a; }
    .wa-chat-peer { color:#72858a; font-size:12px; }
    .wa-messages { flex:1; height:416px; overflow-y:auto; padding:20px 22px; background-color:#efeae2; background-image:radial-gradient(rgba(77,96,91,.06) 1px, transparent 1px); background-size:18px 18px; }
    .wa-message-row { display:flex; margin-bottom:9px; }
    .wa-message-row.is-outgoing { justify-content:flex-end; }
    .wa-bubble { max-width:78%; min-width:90px; padding:8px 10px 6px; border-radius:8px; background:#fff; color:#26383b; box-shadow:0 1px 1px rgba(0,0,0,.08); white-space:pre-wrap; overflow-wrap:anywhere; }
    .wa-message-row.is-outgoing .wa-bubble { background:#d9fdd3; }
    .wa-message-meta { display:flex; justify-content:flex-end; gap:5px; color:#76898d; font-size:10px; margin-top:4px; }
    .wa-composer { padding:12px; border-top:1px solid #dbe3e4; background:#f5f7f7; }
    .wa-compose-row { display:flex; align-items:flex-end; gap:9px; }
    .wa-compose-fields { flex:1; min-width:0; }
    .wa-recipient { margin-bottom:8px; }
    .wa-compose-row textarea { resize:none; min-height:44px; max-height:110px; border-radius:10px; }
    .wa-send { width:45px; height:45px; border-radius:50%; flex:none; }
    .wa-empty { padding:55px 24px; text-align:center; color:#6e8186; }
    .wa-empty i { display:block; color:#9bb7b0; font-size:44px; margin-bottom:12px; }
    .wa-counts { color:#6f8287; font-size:12px; }
    .wa-refresh-note { min-height:18px; color:#74878c; font-size:11px; text-align:right; }
    @media (max-width: 767px) {
      .wa-layout { grid-template-columns:1fr; }
      .wa-sidebar { border-right:0; border-bottom:1px solid #e6edef; }
      .wa-conversations { height:210px; }
      .wa-messages { height:330px; }
      .wa-bubble { max-width:88%; }
      .wa-toolbar { align-items:flex-start; flex-direction:column; }
    }
  </style>
</head>
<body class="skin-blue sidebar-mini">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items, 'portal_console_header' => false]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Conversazioni WhatsApp</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Leggi le risposte ricevute e continua le conversazioni dello studio da un unico pannello.
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

          <div class="wa-shell">
            <div class="wa-toolbar">
              <div>
                <div id="wa-status" class="wa-status<?= $gatewayReady ? ' is-online' : '' ?>">
                  <span class="wa-status-dot" aria-hidden="true"></span>
                  <span id="wa-status-label"><?= $gatewayReady ? 'WhatsApp collegato' : 'WhatsApp non collegato' ?></span>
                </div>
                <div class="wa-counts" id="wa-counts">
                  <?= (int) ($conversationDashboard['total_conversations'] ?? 0) ?> conversazioni ·
                  <?= (int) ($conversationDashboard['total_messages'] ?? 0) ?> messaggi recenti
                </div>
              </div>
              <div>
                <button type="button" class="btn btn-default btn-sm" id="wa-new-chat">
                  <i class="fa fa-plus"></i> Nuova conversazione
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
                    <div class="wa-chat-name" id="wa-chat-name">Nuova conversazione</div>
                    <div class="wa-chat-peer" id="wa-chat-peer">Inserisci il numero del destinatario</div>
                  </div>
                </div>
                <div class="wa-messages" id="wa-messages" aria-live="polite"></div>

                <form class="wa-composer" method="post" action="<?= esc((string) $sendUrl) ?>" id="wa-send-form">
                  <?= csrf_field() ?>
                  <div class="wa-compose-row">
                    <div class="wa-compose-fields">
                      <input
                        type="tel"
                        class="form-control wa-recipient"
                        id="wa-recipient"
                        name="to"
                        value="<?= esc((string) old('to')) ?>"
                        placeholder="Numero WhatsApp, es. +393331234567"
                        maxlength="18"
                        required
                      >
                      <textarea
                        class="form-control"
                        id="wa-text"
                        name="text"
                        rows="2"
                        maxlength="4096"
                        placeholder="Scrivi un messaggio"
                        required
                      ><?= esc((string) old('text')) ?></textarea>
                    </div>
                    <button class="btn btn-success wa-send" type="submit" title="Invia messaggio" <?= $gatewayReady ? '' : 'disabled' ?>>
                      <i class="fa fa-paper-plane"></i>
                      <span class="sr-only">Invia messaggio</span>
                    </button>
                  </div>
                </form>
              </section>
            </div>
          </div>

          <p class="text-muted" style="margin:12px 4px 0;">
            La pagina mostra gli ultimi 100 messaggi del numero collegato. Le nuove risposte vengono controllate automaticamente.
          </p>
        </div>
      </div>
    </section>
  </div>
</div>

<script>
(function () {
  'use strict';

  var dashboard = <?= json_encode($initialPayload, $jsonFlags) ?>;
  var selectedPeer = <?= json_encode((string) ($selectedPeer ?? ''), $jsonFlags) ?>;
  var refreshUrl = <?= json_encode((string) $refreshUrl, $jsonFlags) ?>;
  var elements = {
    list: document.getElementById('wa-conversations'),
    search: document.getElementById('wa-search'),
    messages: document.getElementById('wa-messages'),
    name: document.getElementById('wa-chat-name'),
    peer: document.getElementById('wa-chat-peer'),
    avatar: document.getElementById('wa-chat-avatar'),
    recipient: document.getElementById('wa-recipient'),
    text: document.getElementById('wa-text'),
    newChat: document.getElementById('wa-new-chat'),
    status: document.getElementById('wa-status'),
    statusLabel: document.getElementById('wa-status-label'),
    counts: document.getElementById('wa-counts'),
    refreshNote: document.getElementById('wa-refresh-note'),
    sendButton: document.querySelector('#wa-send-form button[type="submit"]')
  };

  function conversations() {
    var data = dashboard && dashboard.conversation_dashboard;
    return data && Array.isArray(data.conversations) ? data.conversations : [];
  }

  function selectedConversation() {
    var rows = conversations();
    for (var i = 0; i < rows.length; i++) {
      if (String(rows[i].peer || '') === selectedPeer) return rows[i];
    }
    return null;
  }

  function initials(label) {
    var parts = String(label || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return 'WA';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
  }

  function formatDate(value, shortFormat) {
    if (!value) return '';
    var date = new Date(value);
    if (isNaN(date.getTime())) return String(value);
    var options = shortFormat
      ? {day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit'}
      : {day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit'};
    return new Intl.DateTimeFormat('it-IT', options).format(date);
  }

  function messageText(message) {
    var text = String(message.text || '').trim();
    if (text) return text;
    var labels = {
      image: '📷 Immagine', video: '🎥 Video', document: '📄 Documento',
      audio: '🎤 Messaggio audio', sticker: 'Sticker', location: '📍 Posizione',
      contact: '👤 Contatto'
    };
    return labels[String(message.message_type || '')] || 'Messaggio non testuale';
  }

  function emptyState(icon, title, detail) {
    var box = document.createElement('div');
    box.className = 'wa-empty';
    var symbol = document.createElement('i');
    symbol.className = 'fa ' + icon;
    var heading = document.createElement('strong');
    heading.textContent = title;
    var copy = document.createElement('div');
    copy.textContent = detail;
    copy.style.marginTop = '5px';
    box.appendChild(symbol);
    box.appendChild(heading);
    box.appendChild(copy);
    return box;
  }

  function renderStatus() {
    var status = dashboard && dashboard.account_status ? dashboard.account_status : {};
    var ready = Boolean(status.connected && status.logged_in);
    elements.status.classList.toggle('is-online', ready);
    elements.statusLabel.textContent = ready ? 'WhatsApp collegato' : 'WhatsApp non collegato';
    elements.sendButton.disabled = !ready;
    var data = dashboard && dashboard.conversation_dashboard ? dashboard.conversation_dashboard : {};
    elements.counts.textContent = Number(data.total_conversations || 0) + ' conversazioni · '
      + Number(data.total_messages || 0) + ' messaggi recenti';
  }

  function renderList() {
    elements.list.replaceChildren();
    var term = String(elements.search.value || '').trim().toLowerCase();
    var rows = conversations().filter(function (conversation) {
      return !term || String(conversation.label || '').toLowerCase().indexOf(term) !== -1
        || String(conversation.peer || '').toLowerCase().indexOf(term) !== -1;
    });
    if (!rows.length) {
      elements.list.appendChild(emptyState('fa-comments-o', term ? 'Nessun risultato' : 'Nessuna conversazione', term ? 'Prova un altro nome o numero.' : 'Le risposte ricevute compariranno qui.'));
      return;
    }

    rows.forEach(function (conversation) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'wa-conversation' + (String(conversation.peer || '') === selectedPeer ? ' is-active' : '');
      var avatar = document.createElement('span');
      avatar.className = 'wa-avatar';
      avatar.textContent = initials(conversation.label || conversation.peer);
      var copy = document.createElement('span');
      copy.className = 'wa-conversation-copy';
      var title = document.createElement('span');
      title.className = 'wa-conversation-title';
      var label = document.createElement('span');
      label.textContent = String(conversation.label || conversation.peer || 'Contatto');
      var time = document.createElement('span');
      time.className = 'wa-last-time';
      time.textContent = formatDate(conversation.last_at, true);
      var preview = document.createElement('span');
      preview.className = 'wa-preview';
      preview.textContent = String(conversation.last_message || 'Messaggio');
      title.appendChild(label);
      title.appendChild(time);
      copy.appendChild(title);
      copy.appendChild(preview);
      button.appendChild(avatar);
      button.appendChild(copy);
      button.addEventListener('click', function () {
        selectedPeer = String(conversation.peer || '');
        elements.recipient.value = selectedPeer;
        render();
      });
      elements.list.appendChild(button);
    });
  }

  function renderChat() {
    elements.messages.replaceChildren();
    var conversation = selectedConversation();
    if (!conversation) {
      elements.name.textContent = selectedPeer || 'Nuova conversazione';
      elements.peer.textContent = selectedPeer ? 'Nessun messaggio recente' : 'Inserisci il numero del destinatario';
      elements.avatar.textContent = selectedPeer ? initials(selectedPeer) : 'WA';
      elements.messages.appendChild(emptyState('fa-whatsapp', 'Apri una conversazione', 'Seleziona un contatto oppure inserisci un nuovo numero qui sotto.'));
      return;
    }

    elements.name.textContent = String(conversation.label || conversation.peer || 'Contatto');
    elements.peer.textContent = String(conversation.peer || '');
    elements.avatar.textContent = initials(conversation.label || conversation.peer);
    elements.recipient.value = String(conversation.peer || '');
    var messages = Array.isArray(conversation.messages) ? conversation.messages : [];
    messages.forEach(function (message) {
      var row = document.createElement('div');
      var outgoing = String(message.direction || 'incoming') === 'outgoing';
      row.className = 'wa-message-row' + (outgoing ? ' is-outgoing' : '');
      var bubble = document.createElement('div');
      bubble.className = 'wa-bubble';
      var text = document.createElement('div');
      text.textContent = messageText(message);
      var meta = document.createElement('div');
      meta.className = 'wa-message-meta';
      meta.textContent = formatDate(message.received_at, false) + (outgoing ? '  ✓' : '');
      bubble.appendChild(text);
      bubble.appendChild(meta);
      row.appendChild(bubble);
      elements.messages.appendChild(row);
    });
    window.requestAnimationFrame(function () {
      elements.messages.scrollTop = elements.messages.scrollHeight;
    });
  }

  function render() {
    renderStatus();
    renderList();
    renderChat();
  }

  function refresh() {
    if (document.hidden) return;
    fetch(refreshUrl, {credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json'}})
      .then(function (response) {
        if (!response.ok) throw new Error('Aggiornamento non disponibile');
        return response.json();
      })
      .then(function (payload) {
        if (!payload || !payload.ok) throw new Error(payload && payload.message ? payload.message : 'Aggiornamento non disponibile');
        dashboard = payload;
        if (!selectedPeer && conversations().length) selectedPeer = String(conversations()[0].peer || '');
        render();
        elements.refreshNote.textContent = 'Aggiornato alle ' + new Intl.DateTimeFormat('it-IT', {hour:'2-digit', minute:'2-digit'}).format(new Date());
      })
      .catch(function () {
        elements.refreshNote.textContent = 'Riprovo automaticamente…';
      });
  }

  elements.search.addEventListener('input', renderList);
  elements.newChat.addEventListener('click', function () {
    selectedPeer = '';
    elements.recipient.value = '';
    elements.search.value = '';
    render();
    elements.recipient.focus();
  });
  elements.recipient.addEventListener('input', function () {
    if (!selectedConversation()) {
      selectedPeer = String(elements.recipient.value || '').trim();
      renderChat();
    }
  });
  elements.text.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      document.getElementById('wa-send-form').requestSubmit();
    }
  });

  if (!selectedPeer && conversations().length) selectedPeer = String(conversations()[0].peer || '');
  render();
  window.setInterval(refresh, 8000);
})();
</script>
</body>
</html>
