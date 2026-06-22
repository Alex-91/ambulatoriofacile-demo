<?php
/** @var array  $ctx        - ['id_message_ini','oggetto','destinatario', ...]
/** @var bool   $isPatient
/** @var array  $menuData
/** @var array  $thread
/** @var string $immagineProfilo */
/** @var string $nomeVisualizzato */
/** @var int    $tipoPers              // 1=P,2=I,3=S (solo per personale)
/** @var bool   $isDoc
/** @var bool   $isSeg
/** @var bool   $isInf
/** @var int    $selectedDoctorId
/** @var string $selectedDoctorName
/** @var bool   $forceDoctorForward
/** @var int    $forcedDoctorId
/** @var string $forcedDoctorName
 */

use CodeIgniter\I18n\Time;

$forceDoctorForward = !empty($forceDoctorForward);
$forcedDoctorId     = (int)($forcedDoctorId ?? 0);
$forcedDoctorName   = (string)($forcedDoctorName ?? 'Dottore');

$selectedDoctorId   = (int)($selectedDoctorId ?? 0);
$selectedDoctorName = (string)($selectedDoctorName ?? 'Dottore');

$isPatient = !empty($isPatient);
$isDoc     = !empty($isDoc);
$isSeg     = !empty($isSeg);
$isInf     = !empty($isInf);

function isMeFromMitt(array $msg): bool {
  $tipoUser = (int)(session()->get('tipoUser') ?? 0); // 3 = paziente
  if ($tipoUser === 3) return (($msg['mitt'] ?? null) === 'C');
  return in_array($msg['mitt'] ?? '', ['P','I','S'], true);
}

function msgBodyHtml(array $msg): string {
  if (array_key_exists('testo', $msg)) {
    if (!empty($msg['is_html'])) return (string)$msg['testo'];
    return nl2br(esc((string)$msg['testo']));
  }
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
  $nome = trim(($msg['mittente_cognome'] ?? '').' '.($msg['mittente_nome'] ?? ''));
  if ($nome !== '') return $nome;
  if (!empty($msg['display_name'])) return (string)$msg['display_name'];
  return (string)($msg['mitt'] ?? 'Mittente');
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Inoltra</title>
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
    .forward-header { padding:10px 15px; background:#f9f9f9; border-bottom:1px solid #eee; }
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
    .dest-radio-group { margin-bottom: 10px; }
    .dest-radio-group label { margin-right: 15px; cursor:pointer; }
    .dest-radio-group i { margin-right: 4px; }
  </style>
</head>

<body class="skin-blue sidebar-mini">
<script>
  const IS_PATIENT = <?= $isPatient ? 'true' : 'false' ?>;
  const FORCE_DOCTOR_FORWARD = <?= $forceDoctorForward ? 'true' : 'false' ?>;
  const SELECTED_DOCTOR_ID = <?= (int)$selectedDoctorId ?>;
</script>

<div id="page-loader" style="display:none; position:fixed; inset:0; background:rgba(255,255,255,.85); z-index:9999;">
  <div class="spinner" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;">
    <i class="fa fa-spinner fa-pulse fa-3x fa-fw"></i>
    <div class="msg" style="margin-top:10px;font-size:13px;color:#444">Caricamentoâ€¦</div>
  </div>
</div>

<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items ?? []]) ?>

  <aside class="main-sidebar" style="display:none"><section class="sidebar"></section></aside>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Posta <small>Inoltra messaggio</small></h1>
      <ol class="breadcrumb">
        <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
        <li><a href="<?= site_url('posta') ?>">Posta</a></li>
        <li class="active">Inoltra</li>
      </ol>
    </section>

    <section class="content">
      <div class="row">
        <!-- Colonna sinistra: menu -->
        <div class="col-md-3">
          <a href="<?= site_url('posta') ?>" class="btn btn-primary btn-block margin-bottom">
            <i class="fa fa-inbox"></i> Torna alla Inbox
          </a>

          <div class="box box-solid" style="margin-bottom:0">
            <div class="box-header with-border">
              <h3 class="box-title">Cartelle</h3>
              <div class="box-tools">
                <button class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button>
              </div>
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
              <div class="box-tools">
                <button class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button>
              </div>
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
                <?php if (!empty($menuData['resultLogout'])): ?>
                  <li class="logout"><a href="logout"><i class="fa fa-sign-out"></i> Logout</a></li>
                <?php endif; ?>
              </ul>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <!-- Colonna destra -->
        <div class="col-md-9">
          <div class="box box-primary">
            <div class="box-header with-border">
              <h3 class="box-title">
                Conversazione: <b><?= esc($ctx['oggetto'] ?? '') ?></b>
              </h3>
              <div class="box-tools pull-right">
                <button type="button" class="btn btn-box-tool" data-widget="collapse">
                  <i class="fa fa-minus"></i>
                </button>
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
                                  <a href="<?= esc($att['url']) ?>" target="_blank">
                                    <?= esc($att['nome'] ?? $att['name'] ?? 'Allegato') ?>
                                  </a>
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

            <!-- INOLTRO DESTINATARIO -->
            <div class="forward-header">
              <div><b>Inoltra a:</b></div>

              <?php if ($isPatient): ?>
                <div class="alert alert-warning" style="margin:10px 0 0 0;">
                  <i class="fa fa-lock"></i>
                  I pazienti <b>non possono inoltrare</b> messaggi.
                </div>
              <?php else: ?>
                <div class="dest-radio-group" style="margin-top:8px;">
                  <?php if ($isDoc): ?>
                    <!-- Dottore: S / I -->
                    <label>
                      <input type="radio" name="dest_code_radio" value="3" checked>
                      <i class="fa fa-user"></i> Segreteria
                    </label>
                    <label>
                      <input type="radio" name="dest_code_radio" value="2">
                      <i class="fa fa-user-md"></i> Infermieri
                    </label>

                  <?php elseif ($isSeg): ?>
                    <!-- Segreteria: P / I -->
                    <label>
                      <input type="radio" name="dest_code_radio" value="1" checked>
                      <i class="fa fa-user-md"></i> <?= esc($selectedDoctorName ?: 'Dottore') ?>
                    </label>
                    <label>
                      <input type="radio" name="dest_code_radio" value="2">
                      <i class="fa fa-user-md"></i> Infermieri
                    </label>

                  <?php elseif ($isInf): ?>
                    <!-- Infermieri: P / S -->
                    <label>
                      <input type="radio" name="dest_code_radio" value="1" checked>
                      <i class="fa fa-user-md"></i> <?= esc($selectedDoctorName ?: 'Dottore') ?>
                    </label>
                    <label>
                      <input type="radio" name="dest_code_radio" value="3">
                      <i class="fa fa-user"></i> Segreteria
                    </label>

                  <?php else: ?>
                    <!-- fallback -->
                    <label>
                      <input type="radio" name="dest_code_radio" value="3" checked>
                      <i class="fa fa-user"></i> Segreteria
                    </label>
                    <label>
                      <input type="radio" name="dest_code_radio" value="2">
                      <i class="fa fa-user-md"></i> Infermieri
                    </label>
                  <?php endif; ?>
                </div>

                <?php if (($isSeg || $isInf) && $selectedDoctorId <= 0): ?>
                  <small class="text-danger">
                    Attenzione: medico non selezionato. Seleziona un medico dalla colonna â€œDottoriâ€ prima di inoltrare al dottore.
                  </small>
                <?php endif; ?>
              <?php endif; ?>
            </div>

            <!-- FORM -->
            <?php if (!$isPatient): ?>
            <form id="forward-form" method="post" action="<?= site_url('posta/inoltra') ?>" enctype="multipart/form-data">
              <?= csrf_field() ?>

              <input type="hidden" name="testo_inoltro" id="hid-testo_inoltro">
              <input type="hidden" name="version" id="hid-version" value="desktop">
              <input type="hidden" name="id_message" id="hid-id_message" value="<?= (int)($ctx['id_message_ini'] ?? 0) ?>">

              <!-- âœ… dest_code / dest_id (dest_id obbligatorio SOLO per medico) -->
              <?php
                // default radio iniziale:
                // - Doc: S (3)
                // - Seg/Inf: P (1)
                $initialDestCode = 3;
                if ($isSeg || $isInf) $initialDestCode = 1;

                if ($forceDoctorForward && $forcedDoctorId > 0) {
                    $initialDestCode = 1;
                }

                $initialDestId = 0;
                if ($initialDestCode == 1) {
                    $initialDestId = $forceDoctorForward && $forcedDoctorId > 0 ? $forcedDoctorId : $selectedDoctorId;
                }
              ?>
              <input type="hidden" name="dest_code" id="hid-dest-code" value="<?= (int)$initialDestCode ?>">
              <input type="hidden" name="dest_id"   id="hid-dest-id"   value="<?= (int)$initialDestId ?>">

              <div class="box-body">
                <div class="form-group">
                  <label for="forward-textarea">Testo aggiuntivo da inoltrare</label>
                  <textarea id="forward-textarea"
                            class="form-control"
                            style="height: 180px"
                            placeholder="Aggiungi un commentoâ€¦"></textarea>
                </div>

                <div class="form-group">
                  <label>Allega nuovi file (oltre a quelli giÃ  presenti)</label><br>
                  <div class="btn btn-default btn-file">
                    <i class="fa fa-paperclip"></i> Allega file
                    <input type="file" name="attachment[]" multiple />
                  </div>
                  <p class="help-block">Max 3MB per file.</p>
                </div>
              </div>

              <div class="box-footer">
                <div class="pull-right">
                  <button type="submit" class="btn btn-primary" id="btn-forward-submit">
                    <i class="fa fa-share"></i> Inoltra
                  </button>
                </div>
                <a href="<?= site_url('posta') ?>" class="btn btn-default no-loader">
                  <i class="fa fa-times"></i> Annulla
                </a>
              </div>
            </form>
            <?php else: ?>
              <div class="box-footer">
                <a href="<?= site_url('posta') ?>" class="btn btn-default no-loader">
                  <i class="fa fa-chevron-left"></i> Torna alla posta
                </a>
              </div>
            <?php endif; ?>

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

  $(function () {
    // editor solo se form esiste
    if (!IS_PATIENT) {
      $('#forward-textarea').wysihtml5({ "locale": "it-IT" });
    }

    $('input[type="checkbox"], input[type="radio"]').iCheck({
      checkboxClass: 'icheckbox_flat-blue',
      radioClass: 'iradio_flat-blue'
    });

    if (IS_PATIENT) return;

    // âœ… sync radio -> hidden dest_code + dest_id
    $('input[name="dest_code_radio"]').on('ifChecked', function(){
      var code = parseInt($(this).val(), 10) || 0;
      $('#hid-dest-code').val(String(code));

      if (code === 1) {
        var docId = FORCE_DOCTOR_FORWARD ? <?= (int)$forcedDoctorId ?> : SELECTED_DOCTOR_ID;
        $('#hid-dest-id').val(String(docId || 0));
      } else {
        // Per S / I non serve dest_id: lo ricava il model dal messaggio originale
        $('#hid-dest-id').val('0');
      }
    });

    // allineo i hidden ai radio iniziali (se presenti)
    var $checked = $('input[name="dest_code_radio"]:checked');
    if ($checked.length) $checked.trigger('ifChecked');
  });

  // Invio AJAX inoltro
  (function() {
    if (IS_PATIENT) return;

    var $form     = $('#forward-form');
    var $editorTA = $('#forward-textarea');
    var csrfInput = $form.find('input[name^="csrf_"]').first();
    var inboxUrl  = '<?= site_url('posta') ?>';
    var inoltraUrl= '<?= site_url('posta/inoltra') ?>';
    var maxUploadBytes = <?= (int) APP_UPLOAD_MAX_FILE_SIZE_BYTES ?>;
    var maxUploadLabel = '<?= (int) APP_UPLOAD_MAX_FILE_SIZE_MB ?>MB';

    function getEditorHtml() {
      try {
        var w = $editorTA.data('wysihtml5');
        if (w && w.editor) return w.editor.getValue(true);
      } catch(e) {}
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

      // blocco inoltro al medico se manca selectedDoctorId
      var destCode = parseInt($('#hid-dest-code').val(), 10) || 0;
      var destId   = parseInt($('#hid-dest-id').val(), 10) || 0;

      if (destCode === 1 && destId <= 0) {
        alert('Seleziona un medico prima di inoltrare al dottore.');
        return;
      }

      var fileInput = $form.find('input[type=\"file\"]')[0];
      if (!validateFileInput(fileInput)) {
        return;
      }
      var fileCount = fileInput.files.length;
      if (fileCount > 0) {
        $('#page-loader .msg').text('Inoltro e caricamento allegati in corsoâ€¦');
      } else {
        $('#page-loader .msg').text('Inoltro in corsoâ€¦');
      }
      $('#page-loader').show();

      var fd = new FormData($form.get(0));
      fd.set('testo_inoltro', getEditorHtml());
      fd.set('version', window.matchMedia('(max-width: 767px)').matches ? 'mobile' : 'desktop');

      // sicurezza
      fd.set('dest_code', String(destCode));
      fd.set('dest_id',   String(destId));

      if (csrfInput.length) {
        fd.set(csrfInput.attr('name'), csrfInput.val());
      }

      $.ajax({
        url: inoltraUrl,
        method: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        cache: false
      })
      .done(function(resp) {
        $('#page-loader').fadeOut(150, function(){
          $('#page-loader .msg').text('Ritorno alla postaâ€¦');
          $('#page-loader').fadeIn(100, function(){ window.location = inboxUrl; });
        });

        if (resp && resp.csrfName && resp.csrfHash) {
          csrfInput.attr('name', resp.csrfName).val(resp.csrfHash);
        }
      })
      .fail(function(xhr) {
        $('#page-loader').fadeOut(150);
        var msg = 'Errore durante l\'inoltro del messaggio. Riprova.';
        if (xhr && xhr.status === 403) {
          msg = 'Operazione non consentita.';
        } else if (xhr && xhr.responseJSON) {
          msg = xhr.responseJSON.error || xhr.responseJSON.err || msg;
        }
        alert(msg);
        return;
        // se il backend blocca (es 403), mostro messaggio piÃ¹ chiaro
        if (xhr && xhr.status === 403) {
          alert('Operazione non consentita.');
        } else {
          alert('Errore durante lâ€™inoltro del messaggio. Riprova.');
        }
      });
    });
  })();
</script>
</body>
</html>
