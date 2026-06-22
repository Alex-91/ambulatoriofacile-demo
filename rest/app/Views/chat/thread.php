<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title><?= esc('AmbulatorioFacile') ?> | Chat</title>
  <meta content='width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no' name='viewport'>
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <style>
    .direct-chat-messages{ height: calc(100vh - 260px); min-height: 320px; }
    @media(max-width:767px){
      .content{ padding:10px; }
      .direct-chat-messages{ height: calc(100vh - 220px); }
    }
  </style>
</head>

<body class="skin-blue sidebar-mini">
<div class="wrapper">
  <!-- HEADER identico tuo -->
   <?= view('partials/header') ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Chat</h1>
    </section>

    <section class="content">
      <div class="box box-primary direct-chat direct-chat-primary">
        <div class="box-header with-border">
          <h3 class="box-title">
            <i class="fa fa-comments"></i>
            <?php
use App\Models\ChatModel;

$isGroup = (($thread['thread_type'] ?? '') === 'group');
$title = 'Chat';

if ($isGroup && !empty($thread['group_key'])) {

    $gk = (string)$thread['group_key'];
    $meIsDoctor = ((int)$meTipoPers === (int)ChatModel::TIPO_DOTTORE);

    // =========================
    // IO SONO DOTTORE
    // =========================
    if ($meIsDoctor) {
        if (strpos($gk, 'segreteria_') === 0) {
            $title = 'Segreteria';
        } elseif (strpos($gk, 'infermieri_') === 0) {
            $title = 'Infermieri';
        }
    }

    // =========================
    // IO SONO SEGRETERIA / INFERMIERE
    // =========================
    else {
        if (preg_match('/^(segreteria|infermieri)_(\d+)$/', $gk, $m)) {

            $docId = (int)$m[2];
            $docName = null;

            // cerco il nome del medico nella lista doctors
            if (!empty($doctors)) {
                foreach ($doctors as $d) {
                    if ((int)$d['id_user'] === $docId) {
                        $docName = $d['nome_completo'] ?? null;
                        break;
                    }
                }
            }

            if (!$docName) {
                $docName = 'Medico #' . $docId;
            }

            $title = 'Dott. ' . $docName;
        }
    }

} else {
    // chat non di gruppo (eventuale)
    $title = $thread['title'] ?? 'Chat';
}

echo esc($title);
?>

          </h3>
          
          <div class="box-tools pull-right">
            <a class="btn btn-box-tool" href="<?= site_url('chat') ?>"><i class="fa fa-arrow-left"></i></a>
             <form method="post"
                    action="<?= site_url('chat/clearAll') ?>"
                    style="display:inline;"
                    onsubmit="return confirm('Vuoi davvero svuotare TUTTE le chat? Questa operazione non Ã¨ reversibile.');">
                <input type="hidden" name="return_url" value="<?= site_url('chat') ?>">
                <button type="submit" class="btn btn-box-tool" title="Svuota tutte le chat">
                  <i class="fa fa-trash"></i>
                </button>
              </form>
          </div>
              
        </div>

        <div class="box-body">
          <div class="direct-chat-messages" id="chatMessages">
            <?php $lastId = 0; ?>
            <?php foreach($messages as $m): ?>
              <?php $lastId = (int)$m['id_message']; ?>
              <?php $isMe = ((int)$m['sender_id'] === (int)$me->id_user); ?>
              <div class="direct-chat-msg <?= $isMe ? 'right' : '' ?>">
                <div class="direct-chat-info clearfix">
                  <span class="direct-chat-name <?= $isMe ? 'pull-right' : 'pull-left' ?>">
                    <?= esc($isMe ? 'Tu' : ($m['sender_name'] ?? '')) ?>
                  </span>
                  <span class="direct-chat-timestamp <?= $isMe ? 'pull-left' : 'pull-right' ?>">
                    <?= esc($m['created_at']) ?>
                  </span>
                </div>
               <div class="direct-chat-text">
  <?= esc($m['body']) ?>

  <?php if (!empty($m['stored_name'])): ?>
    <div style="margin-top:8px;">
      <a href="<?= site_url('chat/attachment/' . (int)$m['id_message']) ?>" target="_blank">
        <i class="fa fa-paperclip"></i>
        <?= esc($m['original_name']) ?>
      </a>
    </div>
  <?php endif; ?>
</div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="box-footer">
         <form id="sendForm" enctype="multipart/form-data" autocomplete="off">
  <div class="input-group">
    <input id="msgInput" name="message" type="text" class="form-control" placeholder="Scrivi un messaggio...">

    <span class="input-group-btn">
      <label for="attachmentMobile" class="btn btn-default btn-flat" style="margin:0;">
        <i class="fa fa-paperclip"></i>
      </label>
      <input id="attachmentMobile" name="attachment" type="file" style="display:none;">

      <button type="submit" class="btn btn-primary btn-flat">
        <i class="fa fa-paper-plane"></i>
      </button>
    </span>
  </div>

  <div id="selectedFileBoxMobile" style="display:none; margin-top:8px;">
    <span class="label label-info">
      <i class="fa fa-paperclip"></i>
      <span id="selectedFileNameMobile"></span>
    </span>
    <button type="button" id="removeSelectedFileMobile" class="btn btn-xs btn-link" style="padding:0 0 0 8px;">
      rimuovi
    </button>
  </div>
  <div class="help-block" style="margin-bottom:0;">Dimensione massima file: 3MB.</div>
</form>
        </div>

      </div>
    </section>
  </div>
</div>

<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>

<script>
  const ID_THREAD = <?= (int)$thread['id_thread'] ?>;
  const ME_ID = <?= (int)$me->id_user ?>;
  let lastId = <?= (int)$lastId ?>;

  function scrollBottom(){
    const box = $('#chatMessages');
    box.scrollTop(box[0].scrollHeight);
  }

  function appendMsg(m){
    const isMe = parseInt(m.sender_id,10) === ME_ID;
    const name = isMe ? 'Tu' : (m.sender_name || '');
    const $el = $(`
      <div class="direct-chat-msg ${isMe ? 'right':''}">
        <div class="direct-chat-info clearfix">
          <span class="direct-chat-name ${isMe ? 'pull-right':'pull-left'}">${name}</span>
          <span class="direct-chat-timestamp ${isMe ? 'pull-left':'pull-right'}">${m.created_at}</span>
        </div>
        <div class="direct-chat-text"></div>
      </div>
    `);
    $el.find('.direct-chat-text').text(m.body);
    $('#chatMessages').append($el);
    lastId = Math.max(lastId, parseInt(m.id_message,10));
  }

  function poll(){
    $.getJSON("<?= site_url('chat/poll') ?>", { thread: ID_THREAD, after: lastId })
      .done(function(resp){
        if(resp && resp.ok && resp.messages && resp.messages.length){
          resp.messages.forEach(appendMsg);
          scrollBottom();
        }
      })
      .always(function(){ setTimeout(poll, 1500); });
  }

  $('#sendForm').on('submit', function(e){
    e.preventDefault();
    const body = $('#msgInput').val().trim();
    if(!body) return;

   $.post("<?= site_url('chat/send') ?>", { thread_id: ID_THREAD, message: body }, function(resp){
      if(resp && resp.ok){
        $('#msgInput').val('');
        appendMsg({
          id_message: resp.message.id_message,
          sender_id: resp.message.sender_id,
          body: body,
          created_at: resp.message.created_at,
          sender_name: "Tu"
        });
        scrollBottom();
      } else alert(resp.msg || "Errore invio");
    }, 'json');
  });

  $(function(){ scrollBottom(); poll(); });
</script>

<!-- Notifiche globali -->
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
.direct-chat-text a {
    color: #ffffff !important;
    font-weight: 600;
    text-decoration: underline;
}

.direct-chat-text a:hover {
    color: #e8f5e9 !important;
    text-decoration: underline;
}
</style><script>
$(function(){
  var maxUploadBytes = <?= (int) APP_UPLOAD_MAX_FILE_SIZE_BYTES ?>;
  var maxUploadLabel = '<?= (int) APP_UPLOAD_MAX_FILE_SIZE_MB ?>MB';

  $('#attachmentMobile').on('change', function(){
    var file = this.files && this.files.length ? this.files[0] : null;

    if(file){
      if (file.size > maxUploadBytes) {
        alert('AVVISO: il file "' + file.name + '" e troppo grosso. Il limite massimo e ' + maxUploadLabel + '.');
        $('#attachmentMobile').val('');
        $('#selectedFileNameMobile').text('');
        $('#selectedFileBoxMobile').hide();
        return;
      }

      $('#selectedFileNameMobile').text(file.name);
      $('#selectedFileBoxMobile').show();
    } else {
      $('#selectedFileNameMobile').text('');
      $('#selectedFileBoxMobile').hide();
    }
  });

  $('#removeSelectedFileMobile').on('click', function(){
    $('#attachmentMobile').val('');
    $('#selectedFileNameMobile').text('');
    $('#selectedFileBoxMobile').hide();
  });
});
</script>

