<?php
/** @var object $me */
/** @var int $meTipoPers */
/** @var array $threads */
/** @var array|null $selectedThread */
/** @var array $messages */
/** @var array $doctors */

use App\Models\ChatModel;

$threadId = (int)($_GET['thread'] ?? 0);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function esc2($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } // alias comodo
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title><?= esc('AmbulatoriCLOUD') ?> | Chat</title>
  <meta content='width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no' name='viewport'>
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">

  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />

  <style>
    .content-wrapper { padding-bottom: 70px; }
    .chat-layout { display:flex; gap:10px; }
    .chat-left { width: 380px; min-width: 320px; }
    .chat-right { flex: 1; min-width: 0; }

    .chat-thread-link { text-align:left; white-space:normal; }
    .chat-doctor-btn { width:100%; text-align:left; margin-bottom:6px; }

    .scroll-threads { overflow-y:auto; max-height: 280px; }
    .scroll-doctors { overflow-y:auto; max-height: calc(100vh - 610px); min-height: 160px; }

    @media (max-width: 767px){
      .chat-layout { display:block; }
      .chat-left { width:100%; min-width:0; }
      .chat-right { display:none; }
      .scroll-threads { max-height: 320px; }
      .scroll-doctors { max-height: 320px; }
    }

    .direct-chat-messages { height: calc(100vh - 360px); min-height: 320px; }
  </style>
</head>

<body class="skin-blue sidebar-mini">
<div class="wrapper">

  <!-- HEADER identico tuo -->
    <?= view('partials/header') ?>


  <div class="content-wrapper">
    <section class="content-header">
      <h1>Chat interna</h1>
    </section>

    <section class="content">

      <?php if (session()->getFlashdata('error')): ?>
        <div class="alert alert-danger"><?= h(session()->getFlashdata('error')) ?></div>
      <?php endif; ?>
      <?php if (session()->getFlashdata('success')): ?>
        <div class="alert alert-success"><?= h(session()->getFlashdata('success')) ?></div>
      <?php endif; ?>

      <div class="chat-layout">

        <!-- LEFT -->
        <div class="chat-left">

          <div class="box box-solid" style="margin-bottom:10px;">
            <div class="box-header with-border">
              <h3 class="box-title"><i class="fa fa-comments"></i> Conversazioni</h3>
              <div class="box-tools pull-right">
              <!-- [NUOVO] Svuota TUTTE le chat -->
              <form method="post"
                    action="<?= site_url('chat/clearAll') ?>"
                    style="display:inline;"
                    onsubmit="return confirm('Vuoi davvero svuotare TUTTE le chat? Questa operazione non Ã¨ reversibile.');">
                <input type="hidden" name="return_url" value="<?= site_url('chat') ?>">
                <button type="submit" class="btn btn-box-tool" title="Svuota tutte le chat">
                  <i class="fa fa-trash"></i>
                </button>
              </form>
              <!-- [/NUOVO] -->
            </div>

            </div>

            <div class="box-body no-padding scroll-threads">
              <div style="padding:10px;">
                   <div id="threadList">
               <?php if (!empty($threads)): ?>
                 
                  <?php foreach($threads as $t): ?>
                    <?php
                      $isActive = ((int)$t['id_thread'] === (int)$threadId);
                      $isUnread = ((int)($t['unread_count'] ?? 0) > 0);
                      $isActive = ((int)$t['id_thread'] === (int)$threadId);
                      $isGroup = (($t['thread_type'] ?? '') === 'group');
                      $title = $isGroup ? ($t['title'] ?? 'Gruppo') : ($t['title'] ?? 'Chat');
                      $hrefDesktop = site_url('chat?thread='.(int)$t['id_thread']);
                      $hrefMobile  = site_url('chat/thread/'.(int)$t['id_thread']);

                    ?>
                    
                   <a class="chat-thread-item chat-thread-link <?= $isActive ? 'active' : '' ?> <?= ((int)($t['unread_count'] ?? 0) > 0) ? 'unread' : '' ?>"
                    data-thread-id="<?= (int)$t['id_thread'] ?>"
                    data-href-desktop="<?= esc2($hrefDesktop) ?>"
                    data-href-mobile="<?= esc2($hrefMobile) ?>"
                    data-unread="<?= (int)($t['unread_count'] ?? 0) ?>"
                    href="<?= $hrefDesktop ?>">
                    <b><?= esc2($title) ?></b><br>
                    <small class="thread-preview"><?= esc2($t['last_preview'] ?? '') ?></small>
                  </a>

                  <?php endforeach; ?>
                  
                <?php endif; ?>
                 </div>

                  <?php if (empty($threads)): ?>
      <div class="text-muted" id="noThreadsMsg">Nessuna conversazione.</div>
    <?php endif; ?>
              </div>
            </div>
          </div>

          <?php
            // SOLO segreteria/infermiere: box "scrivi a un dottore"
            // NB: qui uso $meTipoPers che il controller ti passa (legato a dap03_personale.tipo)
            $isDoctor = ((int)$meTipoPers === (int)ChatModel::TIPO_DOTTORE);
          ?>
          <?php if (!$isDoctor): ?>
            <div class="box box-solid">
              <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-user-md"></i> Scrivi a un dottore</h3>
              </div>

              <div class="box-body">
                <input type="text" id="doctorSearch" class="form-control" placeholder="Cerca dottore...">
              </div>

              <div class="box-body scroll-doctors">
                <?php if (empty($doctors)): ?>
                  <div class="text-muted">Nessun dottore trovato.</div>
                <?php else: ?>
                  <?php foreach($doctors as $d): ?>
                    <?php $name = $d['nome_completo'] ?? 'Dottore'; ?>
                    <?php
                    
                      $hrefDesktop = site_url('chat?thread='.(int)$d['id_user']);
                      $hrefMobile  = site_url('chat/start/'.(int)$d['id_user'].'?mobile=1');

                    ?>
                    <button class="btn btn-default chat-doctor-btn js-start"
                            data-id="<?= (int)$d['id_user'] ?>"
                            data-name="<?= esc2($name) ?>"
                            data-href-desktop="<?= esc2($hrefDesktop) ?>"
                            data-href-mobile="<?= esc2($hrefMobile) ?>"
                            data-unread="<?= (int)($t['unread_count'] ?? 0) ?>"
                            >
                      <i class="fa fa-user-md"></i> <?= esc2($name) ?>
                    </button>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

        </div>

        <!-- RIGHT (desktop chat) -->
        <div class="chat-right">
          <?php if (!empty($selectedThread)): ?>
           <?php

$isGroup = (($selectedThread['thread_type'] ?? '') === 'group');
$title = $isGroup ? ($selectedThread['title'] ?? 'Chat') : ($selectedThread['title'] ?? 'Chat');

$meIsDoctor = ((int)$meTipoPers === (int)ChatModel::TIPO_DOTTORE);

if ($isGroup && !empty($selectedThread['group_key'])) {
  $gk = (string)$selectedThread['group_key'];

  // se sono DOTTORE: voglio vedere "Segreteria" / "Infermieri"
  if ($meIsDoctor) {
    if (strpos($gk, 'segreteria_') === 0) $title = 'Segreteria';
    if (strpos($gk, 'infermieri_') === 0) $title = 'Infermieri';
  }
  // se sono segreteria/infermiere: voglio vedere "Dott. Nome Cognome"
  else {
    if (preg_match('/^(segreteria|infermieri)_(\d+)$/', $gk, $m)) {
      $docId = (int)$m[2];
      $docName = null;

      // cerco in $doctors (giÃ  disponibile lato segreteria/infermiere)
      if (!empty($doctors)) {
        foreach ($doctors as $d) {
          if ((int)$d['id_user'] === $docId) {
            $docName = $d['nome_completo'] ?? null;
            break;
          }
        }
      }

      if (!$docName) $docName = 'Medico #' . $docId;
      $title = 'Dott. ' . $docName;
    }
  }
}
?>

            <div class="box box-primary direct-chat direct-chat-primary">
              <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-comments"></i> <?= esc2($title) ?></h3>
                  <!-- [NUOVO] Svuota chat singola (thread aperto) -->
    <div class="box-tools pull-right">
      <form method="post"
            action="<?= site_url('chat/clear') ?>"
            style="display:inline;"
            onsubmit="return confirm('Vuoi davvero svuotare questa chat? Questa operazione non Ã¨ reversibile.');">
        <input type="hidden" name="thread_id" value="<?= (int)$selectedThread['id_thread'] ?>">
        <input type="hidden" name="return_url" value="<?= site_url('chat') . '?thread=' . (int)$selectedThread['id_thread'] ?>">
        <button type="submit" class="btn btn-box-tool" title="Svuota chat">
          <i class="fa fa-trash"></i>
        </button>
      </form>
    </div>
    <!-- [/NUOVO] -->
              </div>

              <div class="box-body">
                <div class="direct-chat-messages" id="chatMessages">
                  <?php $lastId = 0; ?>
                  <?php if (!empty($messages)): ?>
                    <?php foreach($messages as $m): ?>
                      <?php $lastId = (int)$m['id_message']; ?>
                      <?php $isMe = ((int)$m['sender_id'] === (int)$me->id_user); ?>
                      <div class="direct-chat-msg <?= $isMe ? 'right' : '' ?>">
                        <div class="direct-chat-info clearfix">
                          <span class="direct-chat-name <?= $isMe ? 'pull-right' : 'pull-left' ?>">
                            <?= esc2($isMe ? 'Tu' : ($m['sender_name'] ?? '')) ?>
                          </span>
                          <span class="direct-chat-timestamp <?= $isMe ? 'pull-left' : 'pull-right' ?>">
                            <?= esc2($m['created_at']) ?>
                          </span>
                        </div>
<div class="direct-chat-text">
  <?= esc2($m['body']) ?>

  <?php if (!empty($m['stored_name'])): ?>
    <div style="margin-top:8px;">
      <a href="<?= site_url('chat/attachment/' . (int)$m['id_message']) ?>" target="_blank">
        <i class="fa fa-paperclip"></i>
        <?= esc2($m['original_name']) ?>
      </a>
    </div>
  <?php endif; ?>
</div>
                      </div>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <div class="text-muted" style="padding:10px;">Nessun messaggio.</div>
                  <?php endif; ?>
                </div>
              </div>

              <div class="box-footer">
                <!-- QUI tengo la tua logica attuale: submit normale verso chat/send -->
                <form method="post" action="<?= base_url('chat/send') ?>" enctype="multipart/form-data" autocomplete="off">
  <input type="hidden" name="thread_id" value="<?= (int)$selectedThread['id_thread'] ?>">

  <div class="input-group">
    <input name="message" type="text" class="form-control" placeholder="Scrivi un messaggio...">

    <span class="input-group-btn">
      <label for="attachment" class="btn btn-default btn-flat" style="margin:0;">
        <i class="fa fa-paperclip"></i>
      </label>
      <input id="attachment" name="attachment" type="file" style="display:none;">

      <button type="submit" class="btn btn-primary btn-flat">
        <i class="fa fa-paper-plane"></i>
      </button>
    </span>
  </div>

  <div id="selectedFileBox" style="display:none; margin-top:8px;">
    <span class="label label-info">
      <i class="fa fa-paperclip"></i>
      <span id="selectedFileName"></span>
    </span>
    <button type="button" id="removeSelectedFile" class="btn btn-xs btn-link" style="padding:0 0 0 8px;">
      rimuovi
    </button>
  </div>
  <div class="help-block" style="margin-bottom:0;">Dimensione massima file: 3MB.</div>
</form>
              </div>
            </div>
          <?php else: ?>
            <div class="callout callout-info">
              <h4>Seleziona una conversazione</h4>
              <p>Su desktop si apre qui a destra. Su mobile si apre nella pagina dedicata.</p>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </section>
  </div>
</div>


<script>
  var notifyAudio = new Audio("<?= base_url('public/sounds/chat.mp3') ?>");
notifyAudio.volume = 0.6;

var windowFocused = document.hasFocus();
window.addEventListener('focus', function(){ windowFocused = true; });
window.addEventListener('blur',  function(){ windowFocused = false; });

function isWindowActive(){
  return windowFocused && document.visibilityState === 'visible';
}
var chatIconUrl = "<?= base_url('notifications/icon.svg') ?>";

async function showLocalNotification(title, body) {
  if (typeof window === 'undefined') return;
  if (typeof Notification === 'undefined') {
    console.log('Notification API non disponibile su questo device/browser');
    return;
  }

  try {
    if (Notification.permission === 'default') {
      await Notification.requestPermission();
    }
  } catch (e) {}

  if (Notification.permission !== 'granted') return;

  try {
    new Notification(title, {
      body: body,
      icon: chatIconUrl
    });
  } catch (e) {
    console.error('Notifica locale chat non mostrata', e);
  }
}
//notifyAudio.play(); 
// tiene traccia dello stato unread precedente
var prevUnreadMap = {};
  function isMobile(){ return window.matchMedia && window.matchMedia("(max-width: 767px)").matches; }

  // click su conversazione: desktop resta in /chat?thread=, mobile va in /chat/thread/id (se la rotta esiste)
$(document).on('click', '.chat-doctor-btn', function(e){

  // blocca eventuali altri handler (es. .js-start) e qualsiasi submit
  e.preventDefault();
  e.stopImmediatePropagation();

  if(!isMobile()){
   
    window.location.href = "<?= site_url('chat/start') ?>/" + ($(this).data('id') || '');
    return;
  }
  
  // mobile: vai diretto al thread
  var hrefMobile = ($(this).data('href-mobile') || '').toString();
  if(!hrefMobile){
    // fallback: usa start (che poi deve redirectare bene)
    window.location.href = "<?= site_url('chat/start') ?>/" + ($(this).data('id') || '');
    return;
  }

  window.location.assign(hrefMobile);
});

$(document).on('click', '.chat-thread-link', function(e){

  // blocca eventuali altri handler (es. .js-start) e qualsiasi submit
  e.preventDefault();
  e.stopImmediatePropagation();

  if(!isMobile()){
     var hrefDesktop = ($(this).data('href-desktop') || '').toString();

    // desktop: lascia gestire al tuo handler .js-start (oppure gestisci qui)
    window.location.href = hrefDesktop;
    return;
  }
  
  // mobile: vai diretto al thread
  var hrefMobile = ($(this).data('href-mobile') || '').toString();
  if(!hrefMobile){
    // fallback: usa start (che poi deve redirectare bene)
    window.location.href = "<?= site_url('chat/start') ?>/" + ($(this).data('id') || '');
    return;
  }

  window.location.assign(hrefMobile);
});

  // start chat verso dottore:
  // NOTA: la tua logica nuova usa GET /chat/start/{id}
  $(document).on('click', '.js-start', function(){
    var otherId = $(this).data('id');
    window.location.href = "<?= site_url('chat/start') ?>/" + otherId;
  });

  // search dottori
  $('#doctorSearch').on('input', function(){
    var q = ($(this).val() || '').toLowerCase().trim();
    $('.js-start').each(function(){
      var name = (($(this).data('name') || '') + '').toLowerCase();
      $(this).toggle(name.indexOf(q) !== -1);
    });
  });

  <?php if (!empty($selectedThread)): ?>
    // polling embedded (stessa logica che giÃ  usavi: /chat/poll?thread=ID&after=LAST_ID)
    var ID_THREAD = <?= (int)$selectedThread['id_thread'] ?>;
    var ME_ID = <?= (int)$me->id_user ?>;
    var lastId = <?= (int)($lastId ?? 0) ?>;

    function scrollBottom(){
      var box = $('#chatMessages');
      if(box.length) box.scrollTop(box[0].scrollHeight);
    }

    function escapeHtml(s){
      return String(s)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
    }

function appendMsg(m){
  var isMe = parseInt(m.sender_id,10) === ME_ID;
  var name = isMe ? 'Tu' : (m.sender_name || 'Nuovo messaggio');
  var attachHtml = '';

  if (m.stored_name) {
    attachHtml = '<div style="margin-top:8px;">'
      + '<a target="_blank" href="<?= site_url('chat/attachment') ?>/' + parseInt(m.id_message,10) + '">'
      + '<i class="fa fa-paperclip"></i> ' + escapeHtml(m.original_name || 'Allegato')
      + '</a></div>';
  }

  var $el = $(`
    <div class="direct-chat-msg ${isMe ? 'right':''}">
      <div class="direct-chat-info clearfix">
        <span class="direct-chat-name ${isMe ? 'pull-right':'pull-left'}">${escapeHtml(name)}</span>
        <span class="direct-chat-timestamp ${isMe ? 'pull-left':'pull-right'}">${escapeHtml(m.created_at || '')}</span>
      </div>
      <div class="direct-chat-text">${escapeHtml(m.body || '')}${attachHtml}</div>
    </div>
  `);

  $('#chatMessages').append($el);

  var newId = parseInt(m.id_message,10) || 0;
  var oldLastId = lastId;
  lastId = Math.max(lastId, newId);

  // notifica locale solo per messaggi nuovi ricevuti da altri
  if (!isMe && newId > oldLastId && !isWindowActive()) {
    showLocalNotification(name, m.body || 'Nuovo messaggio');
  }
}

 function poll(){
  $.getJSON("<?= site_url('chat/poll') ?>", { thread: ID_THREAD, after: lastId })
    .done(function(resp){

    if (resp && resp.ok && typeof resp.total_unread !== 'undefined') {
  window.dispatchEvent(new CustomEvent('chat-unread-changed', {
    detail: { unread: parseInt(resp.total_unread, 10) || 0 }
  }));
}

      // 1) messaggi thread aperto
      if(resp && resp.ok && resp.messages && resp.messages.length){
        resp.messages.forEach(appendMsg);
        scrollBottom();
      }

      // [NUOVO] aggiungi eventuali thread nuovi in lista (sempre, non solo se unread_map esiste)
      if(resp && resp.ok && resp.threads){
        ensureThreadsFromServer(resp.threads);
        $('#noThreadsMsg').hide(); // se esiste
      }

      // 2) aggiorna lista: unread + preview + suono quando una chat diventa unread
      if(resp && resp.unread_map){
        $('.chat-thread-link').each(function(){
          var $a = $(this);
          var tid = parseInt($a.data('thread-id'), 10) || 0;
          if(!tid) return;

          var unread = parseInt(resp.unread_map[tid] || 0, 10);
          var wasUnread = parseInt(prevUnreadMap[tid] || 0, 10);

          if(unread > 0) $a.addClass('unread');
          else $a.removeClass('unread');

          if(resp.last_preview_map && typeof resp.last_preview_map[tid] !== 'undefined'){
            $a.find('.thread-preview').text(resp.last_preview_map[tid] || '');
          }

         if (tid !== ID_THREAD && unread > wasUnread){
  // altro thread: suona sempre
  try {
    notifyAudio.currentTime = 0;
    var pr = notifyAudio.play();
    if (pr && typeof pr.catch === 'function') pr.catch(function(){});
  } catch(e) {}
}

// stesso thread aperto: suona solo se finestra non attiva
if (tid === ID_THREAD && unread > wasUnread && !isWindowActive()){
  try {
    notifyAudio.currentTime = 0;
    var pr2 = notifyAudio.play();
    if (pr2 && typeof pr2.catch === 'function') pr2.catch(function(){});
  } catch(e) {}
}

          prevUnreadMap[tid] = unread;
          $a.data('unread', unread);
          $a.attr('data-unread', unread);
        });
      }

    })
    .always(function(){ setTimeout(poll, 1500); });
}




    $(function(){ $('.chat-thread-link').each(function(){
    var tid = parseInt($(this).data('thread-id'), 10) || 0;
    if(!tid) return;
    prevUnreadMap[tid] = parseInt($(this).data('unread') || 0, 10);
  });
scrollBottom(); poll(); });
  // inizializzo i conteggi reali (NO suono al primo giro)
$('.chat-thread-link').each(function(){
  var tid = parseInt($(this).data('thread-id'), 10) || 0;
  if(!tid) return;
  prevUnreadMap[tid] = parseInt($(this).data('unread') || 0, 10);
});

  <?php endif; ?>
  <?php if (empty($selectedThread)): ?>




function pollList(){
  $.getJSON("<?= site_url('chat/poll') ?>", { thread: 0, after: 0 })
    .done(function(resp){
console.log('threadList', $('#threadList').length);
      // [NUOVO] crea in pagina i thread che arrivano dal server ma non esistono ancora
      if(resp && resp.ok && resp.threads){
        ensureThreadsFromServer(resp.threads);
      }

      // aggiorna unread + preview nella lista
      if(resp && resp.ok && resp.unread_map){
        $('.chat-thread-link').each(function(){
          var $a = $(this);
          var tid = parseInt($a.data('thread-id'), 10) || 0;
          if(!tid) return;

          var unread = parseInt(resp.unread_map[tid] || 0, 10);
          var wasUnread = parseInt(prevUnreadMap[tid] || 0, 10);

          if(unread > 0) $a.addClass('unread');
          else $a.removeClass('unread');

          if(resp.last_preview_map && typeof resp.last_preview_map[tid] !== 'undefined'){
            $a.find('.thread-preview').text(resp.last_preview_map[tid] || '');
          }

          // suono se aumenta unread
          if (unread > wasUnread) {
  try {
    notifyAudio.currentTime = 0;
    var pr = notifyAudio.play();
    if (pr && typeof pr.catch === 'function') pr.catch(function(){});
  } catch(e) {}

  if (!isWindowActive()) {
    var titolo = $a.find('b').text() || 'Nuovo messaggio';
    var preview = '';
    if (resp.last_preview_map && typeof resp.last_preview_map[tid] !== 'undefined') {
      preview = resp.last_preview_map[tid] || 'Hai ricevuto un nuovo messaggio';
    } else {
      preview = 'Hai ricevuto un nuovo messaggio';
    }

    showLocalNotification(titolo, preview);
  }
}

          prevUnreadMap[tid] = unread;
          $a.data('unread', unread);
          $a.attr('data-unread', unread);
        });
      }

    })
    .always(function(){ setTimeout(pollList, 1500); });
}


$(function(){
  // inizializza prevUnreadMap
  $('.chat-thread-link').each(function(){
    var tid = parseInt($(this).data('thread-id'), 10) || 0;
    if(!tid) return;
    prevUnreadMap[tid] = parseInt($(this).data('unread') || 0, 10);
  });

  pollList();
});

<?php endif; ?>

// =========================================================
// POLL SOLO LISTA (quando NON hai chat aperta)
// chiama: /chat/poll?thread=0
// =========================================================
function buildThreadLink(t){
  var tid = parseInt(t.id_thread, 10) || 0;
  if(!tid) return null;

  var title = (t.title || 'Chat');

  var hrefDesktop = "<?= site_url('chat') ?>?thread=" + tid;
  var hrefMobile  = "<?= site_url('chat/thread') ?>/" + tid;

  var unread = parseInt(t.unread_count || 0, 10) || 0;

  var $a = $('<a/>', {
    'class': 'chat-thread-item chat-thread-link' + (unread > 0 ? ' unread' : ''),
    'href': hrefDesktop
  });

  $a.attr('data-thread-id', tid);
  $a.attr('data-href-desktop', hrefDesktop);
  $a.attr('data-href-mobile', hrefMobile);
  $a.attr('data-unread', unread);

  $a.append($('<b/>').text(title));
  $a.append('<br>');
  $a.append($('<small/>', {'class':'thread-preview'}).text(t.last_preview || ''));

  return $a;
}

function ensureThreadsFromServer(serverThreads){
  if(!serverThreads || !serverThreads.length) return;

  var $list = $('#threadList');   // DEVE ESISTERE NELLâ€™HTML
  if(!$list.length) return;

  serverThreads.forEach(function(t){
    var tid = parseInt(t.id_thread,10)||0;
    if(!tid) return;

    // se non Ã¨ giÃ  in pagina, lo aggiungo
    if($('.chat-thread-link[data-thread-id="'+tid+'"]').length === 0){
      var $a = buildThreadLink(t);
      if($a) $list.prepend($a);
    }
  });
}
</script>

<!-- Notifiche globali (se lo usi davvero) -->
<script src="<?= base_url('js/chat-notify.js') ?>"></script>
</body>
</html>
<style>
  /* ===== Lista conversazioni â€“ stile soft ===== */

.chat-thread-item{
  display: block;
  padding: 10px 12px;
  margin-bottom: 6px;
  border-radius: 6px;
  background: #ffffff;
  border: 1px solid #e0e0e0;
  color: #333;
  text-decoration: none;
  transition: background .15s ease, box-shadow .15s ease;
}

.chat-thread-item:hover{
  background: #f5f7f9;
  box-shadow: 0 1px 4px rgba(0,0,0,0.08);
  text-decoration: none;
  color: #000;
}

.chat-thread-item b{
  font-weight: 600;
  font-size: 14px;
  color: #222;
}

.chat-thread-item small{
  display: block;
  margin-top: 3px;
  font-size: 12px;
  color: #777;
}

.chat-thread-item.active{
  background: #dde5ec;          /* grigio-blu piÃ¹ deciso */
  border-color: #9fb1c5;
}

.chat-thread-item.active b{
  color: #111;
}

.chat-thread-item.active small{
  color: #555;
}


/* Mobile: un poâ€™ piÃ¹ aria */
@media (max-width: 767px){
  .chat-thread-item{
    padding: 12px 14px;
  }
}
/* ===== Conversazioni con nuovi messaggi ===== */

.chat-thread-item.unread{
  background: #00a65a;           /* verde AmbulatoriCLOUD */
  border-color: #008d4c;
  color: #fff;
}

.chat-thread-item.unread b{
  color: #fff;
}

.chat-thread-item.unread small{
  color: #eafff3;
}

/* hover su unread */
.chat-thread-item.unread:hover{
  background: #008d4c;
  box-shadow: 0 2px 6px rgba(0,0,0,0.2);
}
.chat-thread-item.active.unread{
  background: #00a65a;
  border-color: #008d4c;
}
.direct-chat-text a {
    color: #ffffff !important;
    font-weight: 600;
    text-decoration: underline;
}

.direct-chat-text a:hover {
    color: #e8f5e9 !important;
    text-decoration: underline;
}
</style>
<script>
$(function(){
  var maxUploadBytes = <?= (int) APP_UPLOAD_MAX_FILE_SIZE_BYTES ?>;
  var maxUploadLabel = '<?= (int) APP_UPLOAD_MAX_FILE_SIZE_MB ?>MB';

  $('#attachment').on('change', function(){
    var file = this.files && this.files.length ? this.files[0] : null;

    if(file){
      if (file.size > maxUploadBytes) {
        alert('AVVISO: il file "' + file.name + '" e troppo grosso. Il limite massimo e ' + maxUploadLabel + '.');
        $('#attachment').val('');
        $('#selectedFileName').text('');
        $('#selectedFileBox').hide();
        return;
      }

      $('#selectedFileName').text(file.name);
      $('#selectedFileBox').show();
    } else {
      $('#selectedFileName').text('');
      $('#selectedFileBox').hide();
    }
  });

  $('#removeSelectedFile').on('click', function(){
    $('#attachment').val('');
    $('#selectedFileName').text('');
    $('#selectedFileBox').hide();
  });
});
</script>
<script>window.__CHAT_LOCAL_POLLING__ = true;</script>

