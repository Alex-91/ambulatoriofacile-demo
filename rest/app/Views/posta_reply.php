<?php
/** @var array $ctx        - ['id_message_ini','oggetto','destinatario', ...]
/** @var bool  $isPatient
/** @var array $menuData
/** @var array $thread     - elementi con chiavi:
 *    id_message, mitt, dest, id_mitt, id_dest, dataora,
 *    testo (HTML o plain), is_html (1/0),
 *    mittente_nome, mittente_cognome,
 *    allegati[] => ['nome','url','tipo','size']
 */
/** @var string $immagineProfilo */
/** @var string $nomeVisualizzato */

use CodeIgniter\I18n\Time;

function isMeFromMitt(array $msg): bool {
  // Paziente: 'C' sono io; Personale: P/I/S sono io
  $tipoUser = (int)(session()->get('tipoUser') ?? 0); // 3 = paziente
  if ($tipoUser === 3) return (($msg['mitt'] ?? null) === 'C');
  return in_array($msg['mitt'] ?? '', ['P','I','S'], true);
}

function msgBodyHtml(array $msg): string {
  if (array_key_exists('testo', $msg)) {
    if (!empty($msg['is_html'])) return (string)$msg['testo'];      // giÃ  HTML (sanificato lato model)
    return nl2br(esc((string)$msg['testo']));                        // plain â†’ HTML safe
  }
  // Fallback legacy
  if (!empty($msg['body_html'])) return (string)$msg['body_html'];
  if (!empty($msg['body']))      return nl2br(esc((string)$msg['body']));
  return '';
}

function msgWhen($dt): string {
  try {
    if ($dt instanceof Time) return $dt->toLocalizedString('dd/MM/yyyy HH:mm');
    if (is_string($dt)) {
      $t = Time::parse($dt, 'Europe/Rome');
      return $t->toLocalizedString('dd/MM/yyyy HH:mm');
    }
    if (is_numeric($dt)) return esc(date('d/m/Y H:i', (int)$dt));
    return esc((string)$dt);
  } catch (\Throwable $e) {
    return esc((string)$dt);
  }
}

function displayNameFromMsg(array $msg): string {
  $nome = trim(($msg['mitt_prefix'] ?? '').' '.($msg['mittente_cognome'] ?? '').' '.($msg['mittente_nome'] ?? ''));
  if ($nome !== '') return $nome;
  if (!empty($msg['display_name'])) return (string)$msg['display_name']; // fallback legacy
  return (string)($msg['mitt'] ?? 'Mittente');
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Rispondi</title>
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">

  <!-- CSS -->
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/plugins/iCheck/flat/blue.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/plugins/bootstrap-wysihtml5/bootstrap3-wysihtml5.min.css') ?>" rel="stylesheet" />
  <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">

  <style>
    .reply-header { padding:10px 15px; background:#f9f9f9; border-bottom:1px solid #eee; }
    .direct-chat-messages { height: 420px; overflow: auto; }
    .direct-chat-text { max-width: 70%; }
    .direct-chat-msg.right .direct-chat-text { float: right; }
    .direct-chat-msg .meta { font-size: 12px; color: #777; margin: 2px 2px 6px; }
    .bubble-attachments { margin-top: 6px; font-size: 12px; }
    .bubble-attachments a { display: inline-block; margin-right: 8px; }
    .box-footer .btn + .btn { margin-left: 6px; }
    .day-sep { text-align:center; margin:10px 0; color:#888; font-size:12px; position:relative; }
    .day-sep:before, .day-sep:after { content:''; position:absolute; top:50%; width:30%; height:1px; background:#ddd; }
    .day-sep:before { left:0 } .day-sep:after { right:0 }
  </style>
</head>
<body class="skin-blue sidebar-mini">
<script>const IS_PATIENT = <?= $isPatient ? 'true' : 'false' ?>;</script>

<div id="page-loader" style="display:none; position:fixed; inset:0; background:rgba(255,255,255,.85); z-index:9999;">
  <div class="spinner" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;">
    <i class="fa fa-spinner fa-pulse fa-3x fa-fw"></i>
    <div class="msg" style="margin-top:10px;font-size:13px;color:#444">Caricamentoâ€¦</div>
  </div>
</div>

<div class="wrapper">
  <!-- HEADER -->
    <?= view('partials/header', [
    'menu_items' => $menu_items ?? [],
]) ?>


  <aside class="main-sidebar" style="display:none"><section class="sidebar"></section></aside>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Posta <small>Rispondi al messaggio</small></h1>
      <ol class="breadcrumb">
        <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
        <li><a href="<?= site_url('posta') ?>">Posta</a></li>
        <li class="active">Reply</li>
      </ol>
    </section>

    <section class="content">
      <div class="row">
        <!-- Colonna sinistra: menu -->
        <div class="col-md-3">
          <a href="<?= site_url('posta') ?>" class="btn btn-primary btn-block margin-bottom"><i class="fa fa-inbox"></i> Torna alla Inbox</a>

          <div class="box box-solid" style="margin-bottom:0">
            <div class="box-header with-border">
              <h3 class="box-title">Cartelle</h3>
              <div class="box-tools"><button class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button></div>
            </div>
            <div class="box-body no-padding">
              <ul class="nav nav-pills nav-stacked">
                <?php $menu_items = $menuData['result'] ?? []; if (!empty($menu_items)): foreach ($menu_items as $menu): ?>
                  <li class="<?= esc($menu['class'] ?? '') ?>">
                    <a href="<?= base_url($menu['link'] ?? '#') ?>">
                      <i class="fa <?= esc($menu['icon'] ?? 'fa-folder') ?>"></i> <?= esc($menu['titolo_menu'] ?? '') ?>
                      <?php if (!empty($menu['conteggio'])): ?><span class="label label-primary pull-right"><?= $menu['conteggio'] ?></span><?php endif; ?>
                    </a>
                  </li>
                <?php endforeach; endif; ?>
              </ul>
            </div>
          </div>

          <?php if (!empty($menuData['dottori'])): ?>
          <div class="box box-solid">
            <div class="box-header with-border">
              <h3 class="box-title">Dottori (<?= (int)($menuData['cont_dottori'] ?? 0) ?>)</h3>
              <div class="box-tools"><button class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button></div>
            </div>
            <div class="box-body no-padding">
              <ul class="nav nav-pills nav-stacked">
                <?php foreach ($menuData['dottori'] as $id => $dottore): if (!empty($dottore)): ?>
                  <li>
                    <a href="#"><i class="fa fa-user-md"></i> <?= esc($dottore['titolo']) ?>
                      <?php if (!empty($dottore['conteggio'])): ?><span class="label label-primary pull-right"><?= $dottore['conteggio'] ?></span><?php endif; ?>
                    </a>
                  </li>
                <?php endif; endforeach; ?>
                <?php if (!empty($menuData['resultLogout'])): ?><li class="logout"><a href="logout"><i class="fa fa-sign-out"></i> Logout</a></li><?php endif; ?>
              </ul>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <!-- Colonna destra: CRONOLOGIA + REPLY -->
        <div class="col-md-9">
          <div class="box box-primary">
            <div class="box-header with-border">
              <h3 class="box-title">Conversazione con <b><?= esc($ctx['destinatario']) ?></b></h3>
              <div class="box-tools pull-right">
                <button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button>
              </div>
            </div>

            <!-- CRONOLOGIA -->
            <div class="box-body">
              <div class="direct-chat direct-chat-primary">
                <div class="direct-chat-messages" id="threadBox">
                  <?php if (!empty($thread)): ?>
                    <?php
                      $tz = 'Europe/Rome';
                      $prevDay = null;
                      foreach ($thread as $msg):
                        $mine = isMeFromMitt($msg);
                        $mDT  = isset($msg['dataora']) ? Time::parse($msg['dataora'], $tz) : null;
                        $dayKey = $mDT ? $mDT->toDateString() : '';
                        if ($dayKey && $dayKey !== $prevDay):
                          $prevDay = $dayKey; ?>
                          <div class="day-sep"><?= esc($mDT->toLocalizedString('EEEE d MMM y')) ?></div>
                        <?php endif; ?>
                        <div class="direct-chat-msg <?= $mine ? 'right' : '' ?>">
                          <div class="meta">
                            <span class="direct-chat-name <?= $mine ? 'pull-right' : 'pull-left' ?>">
                              <?= $mine ? 'Tu' : esc(displayNameFromMsg($msg)) ?>
                            </span>
                            <span class="direct-chat-timestamp <?= $mine ? 'pull-left' : 'pull-right' ?>">
                              <?= msgWhen($msg['dataora'] ?? '') ?>
                            </span>
                          </div>
                          <div class="direct-chat-text" style="clear:both;">
                            <?= msgBodyHtml($msg) ?>
                            <?php if (!empty($msg['allegati']) && is_array($msg['allegati'])): ?>
                              <div class="bubble-attachments">
                                <i class="fa fa-paperclip"></i>
                                <?php foreach ($msg['allegati'] as $att): ?>
                                  <a href="<?= esc($att['url']) ?>" target="_blank"><?= esc($att['nome'] ?? $att['name'] ?? 'Allegato') ?></a>
                                <?php endforeach; ?>
                              </div>
                            <?php endif; ?>
                          </div>
                        </div>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <p class="text-muted">Nessun messaggio precedente.</p>
                  <?php endif; ?>
                </div>
              </div>
            </div>

         <!-- REPLY -->
      <div class="reply-header">
          Da: <b><?= esc($ctx['mittente_label']) ?></b><br>
          Rispondi a: <b><?= esc($ctx['prefixDest'].' '.$ctx['destinatario']) ?></b>
      </div>


            <form id="reply-form" method="post" action="<?= site_url('posta/send') ?>" enctype="multipart/form-data">
              <?= csrf_field() ?>
              <input type="hidden" name="message_text" id="hid-message_text">
              <input type="hidden" name="draft" id="hid-draft" value="0">
              <input type="hidden" name="string_dest" id="hid-string_dest" value="">
              <input type="hidden" name="version" id="hid-version" value="desktop">
              <input type="hidden" name="count_div" id="hid-count_div" value="0">
              <input type="hidden" name="id_message" id="hid-id_message" value="<?= (int)$ctx['id_message_ini'] ?>">
              <input type="hidden" name="richiesta" id="hid-richiesta" value="0">
              <input type="hidden" name="mode" value="reply">

              <div class="box-body">
                <div class="form-group" style="display:none !important">
                  <input name="subject" class="form-control" value="<?= 'Re: '.esc($ctx['oggetto']) ?>" />
                </div>
                <div class="form-group">
                  <textarea id="reply-textarea" name="body" class="form-control" style="height: 180px" placeholder="Scrivi la rispostaâ€¦"></textarea>
                </div>
                <div class="form-group">
                  <div class="btn btn-default btn-file">
                    <i class="fa fa-paperclip"></i> Allega file
                    <input type="file" name="attachment[]" multiple />
                  </div>
                  <p class="help-block">Max 3MB per file.</p>
                </div>
              </div>
              <div class="box-footer">
                <div class="pull-right">
                  <button type="button" id="btn-draft" class="btn btn-default"><i class="fa fa-pencil"></i> Bozza</button>
                  <button type="submit" class="btn btn-primary"><i class="fa fa-reply"></i> Invia risposta</button>
                </div>
                <a href="<?= site_url('posta') ?>" class="btn btn-default no-loader"><i class="fa fa-times"></i> Annulla</a>
              </div>
            </form>
          </div>
        </div>

      </div>
    </section>
  </div>

  <footer class="main-footer">
    <div class="pull-right hidden-xs"><b>Version</b> 2.0</div>
    <strong>&copy; AmbulatorioFacile</strong>
  </footer>
</div>

<!-- Modale 48h -->
<div id="messageSendDialogPazienteSegreteria" title="Messaggio inviato con successo" style="display:none;">
  <p>Messaggio inviato</p><br>
  <p><b>Il vostro medico vi ricorda che le richieste saranno evase entro 48 h (sabato e domenica esclusi).
  Le ricette saranno stampate salvo indicazioni diverse da specificare nel testo.</b></p>
</div>

<!-- JS -->
<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
<script src="<?= base_url('public/plugins/slimScroll/jquery.slimscroll.min.js') ?>"></script>
<script src="<?= base_url('public/plugins/fastclick/fastclick.min.js') ?>"></script>
<script src="<?= base_url('public/dist/js/app.min.js') ?>"></script>
<script src="<?= base_url('public/plugins/iCheck/icheck.min.js') ?>"></script>
<script src="<?= base_url('public/plugins/bootstrap-wysihtml5/bootstrap3-wysihtml5.all.min.js') ?>"></script>
<script src="<?= base_url('public/dist/js/demo.js') ?>"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>

<script>
  // Autoscroll in fondo al thread
  (function() {
    var box = document.getElementById('threadBox');
    if (box) box.scrollTop = box.scrollHeight;
  })();

  // Init editor e iCheck
  $(function () {
    $('#reply-textarea').wysihtml5({ "locale": "it-IT" });
    $('input[type="checkbox"]').iCheck({
      checkboxClass: 'icheckbox_flat-blue',
      radioClass: 'iradio_flat-blue'
    });
  });

  // Invio AJAX
  (function() {
    var $form     = $('#reply-form');
    var $editorTA = $('#reply-textarea');
    var csrfInput = $form.find('input[name^="csrf_"]').first();
    var inboxUrl  = '<?= site_url('posta') ?>';
    var inviaUrl  = '<?= site_url('posta/send') ?>';
    var maxUploadBytes = <?= (int) APP_UPLOAD_MAX_FILE_SIZE_BYTES ?>;
    var maxUploadLabel = '<?= (int) APP_UPLOAD_MAX_FILE_SIZE_MB ?>MB';

    function getEditorHtml() {
      try { var w = $editorTA.data('wysihtml5'); if (w && w.editor) return w.editor.getValue(true); } catch(e) {}
      return $editorTA.val();
    }

    function validateFileInput(input) {
      var files = input && input.files ? input.files : [];
      for (var i = 0; i < files.length; i++) {
        var file = files[i];
        if (file && file.size > maxUploadBytes) {
          alert('AVVISO: il file "' + file.name + '" e troppo grosso. Il limite massimo e ' + maxUploadLabel + '.');
          input.value = '';
          return false;
        }
      }
      return true;
    }

    $form.find('input[type="file"]').on('change', function(){
      validateFileInput(this);
    });

    $form.off('submit').on('submit', function(e){
      e.preventDefault();
      var fileInput = $form.find('input[type=\"file\"]')[0];
      if (!validateFileInput(fileInput)) {
        return;
      }
      var fileCount = fileInput.files.length;
      if (fileCount > 0) {
        $('#page-loader .msg').text('Invio e caricamento allegati in corsoâ€¦');
      } else {
        $('#page-loader .msg').text('Invio in corsoâ€¦');
      }
      $('#page-loader').show();

      var fd = new FormData($form.get(0));
      fd.set('message_text', getEditorHtml());
      if (!fd.has('draft')) fd.set('draft', '0');
      fd.set('version', window.matchMedia('(max-width: 767px)').matches ? 'mobile' : 'desktop');
      fd.set('count_div', '0');
      if (!fd.has('richiesta')) fd.set('richiesta', '0');
      fd.set('string_dest', ''); // il backend calcola mitt/dest dalla reply

      if (csrfInput.length) fd.set(csrfInput.attr('name'), csrfInput.val());

      $.ajax({
        url: inviaUrl,
        method: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        cache: false
      })
      .done(function(resp) {
        $('#page-loader').fadeOut(150, function(){
          var showPatientNotice = (resp && typeof resp.showPatientNotice !== 'undefined')
                                  ? !!resp.showPatientNotice
                                  : (typeof IS_PATIENT !== 'undefined' && IS_PATIENT);

          if (showPatientNotice) {
            $("#messageSendDialogPazienteSegreteria").dialog({
              modal: true, width: 600,
              show: { effect: "fadeIn", duration: 300 },
              open: function () { $(this).css({ "font-size": "22px" }); },
              buttons: {
                "Ok letto": function() {
                  $(this).dialog("close");
                  $('#page-loader .msg').text('Ritorno alla postaâ€¦');
                  $('#page-loader').fadeIn(100, function(){ window.location = inboxUrl; });
                }
              }
            });
          } else {
            $('#page-loader .msg').text('Ritorno alla postaâ€¦');
            $('#page-loader').fadeIn(100, function(){ window.location = inboxUrl; });
          }
        });

        if (resp && resp.csrfName && resp.csrfHash) {
          csrfInput.attr('name', resp.csrfName).val(resp.csrfHash);
        }
      })
      .fail(function(xhr) {
        $('#page-loader').fadeOut(150);
        var msg = 'Errore durante l\'invio della risposta. Riprova.';
        if (xhr && xhr.responseJSON) {
          msg = xhr.responseJSON.error || xhr.responseJSON.err || msg;
        }
        alert(msg);
        return;
        alert('Errore durante lâ€™invio della risposta. Riprova.');
      });
    });

    $('#btn-draft').off('click').on('click', function(e){
      e.preventDefault();
      $form.find('#hid-draft').val('1');
      $form.trigger('submit');
    });
  })();
</script>
</body>
</html>
