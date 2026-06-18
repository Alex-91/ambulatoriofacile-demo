<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <?php $brandName = 'AmbulatoriCLOUD'; ?>
  <title><?= esc($brandName) ?> | Scrivi messaggio</title>
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
<script src="<?= base_url('public/assets/messages/messages.js') ?>"></script>
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/plugins/iCheck/flat/blue.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/plugins/bootstrap-wysihtml5/bootstrap3-wysihtml5.min.css') ?>" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">

  <style>
    @font-face {
      font-family: 'Glyphicons Halflings';
      src: url('<?= base_url('public/bootstrap/glyphicons-halflings-regular.eot') ?>');
      src: url('<?= base_url('public/bootstrap/glyphicons-halflings-regular.eot?#iefix') ?>') format('embedded-opentype'),
           url('<?= base_url('public/bootstrap/glyphicons-halflings-regular.woff2') ?>') format('woff2'),
           url('<?= base_url('public/bootstrap/glyphicons-halflings-regular.woff') ?>') format('woff'),
           url('<?= base_url('public/bootstrap/glyphicons-halflings-regular.ttf') ?>') format('truetype'),
           url('<?= base_url('public/bootstrap/glyphicons-halflings-regular.svg#glyphicons_halflingsregular') ?>') format('svg');
    }

    #page-loader { position: fixed; inset: 0; background: rgba(255,255,255,.85); z-index: 9999; display: none; }
    #page-loader .spinner { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%); text-align: center; }
    #page-loader .spinner .msg { margin-top: 10px; font-size: 13px; color: #444; letter-spacing:.2px; }

    .main-header { background:#2c8895; }
    .navbar-static-top { background: transparent; }
    .logo, .logo:hover { background:#2c8895 !important; color:#fff !important; }
    .logo .logo-lg, .logo .logo-mini { color:#fff; }

    #attachment-list li {
      padding: 8px 10px;
      border-bottom: 1px solid #eee;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      font-size: 14px;
    }
    #attachment-list li i.fa-paperclip { margin-right: 5px; }
    #attachment-list li .file-name { flex: 1; word-break: break-all; }
    #attachment-list li .remove-file { margin-top: 5px; }
    @media (max-width: 480px) {
      #attachment-list li { flex-direction: column; align-items: flex-start; }
      #attachment-list li .remove-file { width: 100%; margin-top: 8px; }
    }
  </style>
</head>

<body class="skin-blue sidebar-mini">
<?php
  $result     = session()->get('menuData');
  $menu_items = $menu_items ?? ($result['result'] ?? []);
  $dottori = $dottori ?? [];
  $contDott = (int)($contDott ?? 0);
  $selectedDoctorId = $selectedDoctorId ?? null;
  $showDoctorsFilter = (bool)($showDoctorsFilter ?? false);

  // Nuovo controller passa:
  // $roleLabel (PAZIENTE/DOTTORE/SEGRETERIA/INFERMIERE)
  // $draft (array|null) con id_draft, body_plain, attachments[]
  $roleLabel = $roleLabel ?? 'UNKNOWN';
  $draft = $draft ?? null;

  $draftId = (int)($draft['id_draft'] ?? 0);
  $prefillText = (string)($draft['body_plain'] ?? '');
  $patientTargetCode = strtoupper(trim((string)($draft['patient_target_code'] ?? '')));

  $attachments = $draft['attachments'] ?? [];
?>
<div id="page-loader" aria-hidden="true">
  <div class="spinner">
    <i class="fa fa-spinner fa-pulse fa-3x fa-fw"></i>
    <div class="msg">Caricamentoâ€¦</div>
  </div>
</div>

<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items ?? []]) ?>
  <aside class="main-sidebar" style="display:none"><section class="sidebar"></section></aside>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Posta <small>Scrivi nuovo messaggio</small></h1>
      <ol class="breadcrumb">
        <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
        <li><a href="<?= site_url('messaggi/inbox') ?>">Posta</a></li>
        <li class="active">Scrivi</li>
      </ol>
    </section>

    <section class="content">
      <div class="row">

        <div class="col-md-3">
          <a href="<?= site_url('messaggi/inbox') ?>" class="btn btn-default btn-block margin-bottom">
            <i class="fa fa-inbox"></i> Torna alla posta in arrivo
          </a>

          <?= view('partials/sidebar_posta', [
            'activeFolder' => 'compose',
            'dottori' => $dottori,
            'contDott' => $contDott,
            'selectedDoctorId' => $selectedDoctorId,
            'showDoctorsFilter' => $showDoctorsFilter,
          ]) ?>
        </div>

        <div class="col-md-9">
          <div class="box box-primary">
            <div class="box-header with-border">
              <h3 class="box-title">Scrivi nuovo messaggio</h3>
              <div class="pull-right">
                <span class="label label-default" id="draftStatus">Bozza: non salvata</span>
              </div>
            </div>

            <!-- INVIO: si invia SEMPRE da bozza -->
            <form id="send-form" method="post" action="<?= site_url('messaggi/invia') ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="id_draft" id="send_id_draft" value="<?= $draftId ?>">

              <div class="box-body">
                <?php if ($msg = (session()->getFlashdata('err') ?: session()->getFlashdata('error'))): ?>
                  <div class="alert alert-danger">
                    <?= esc($msg) ?>
                  </div>
                <?php endif; ?>

                <!-- Contesto -->
                <input type="hidden" id="roleLabel" value="<?= esc($roleLabel) ?>">
                <input type="hidden" id="id_draft" value="<?= $draftId ?>">

                <?php if ($roleLabel === 'PAZIENTE'): ?>
                  <div class="form-group" id="patient_target_group">
                    <label for="patient_target">Tipo di messaggio</label>
                    <select id="patient_target" name="patient_target_code" class="form-control" required>
                      <option value="" disabled <?= $patientTargetCode === '' ? 'selected' : '' ?>>Seleziona oggetto mail</option>
                      <option value="MEDICO" <?= $patientTargetCode === 'MEDICO' ? 'selected' : '' ?>>Richieste mediche, invio referti visite e/o esami (NO URGENZE, NON CERTIFICATI DI MALATTIA)</option>
                      <option value="SEGRETERIA" <?= $patientTargetCode === 'SEGRETERIA' ? 'selected' : '' ?>>Richiesta medicinali IN TERAPIA CONTINUATIVA</option>
                      <option value="INFERMIERE" <?= $patientTargetCode === 'INFERMIERE' ? 'selected' : '' ?>>Prestazioni Infermieristiche</option>
                    </select>
                    <p class="help-block">Il destinatario reale viene determinato automaticamente (medico assegnato / ruolo).</p>
                  </div>

                <?php elseif ($roleLabel === 'DOTTORE'): ?>
                  <div class="form-group">
                    <label>Paziente (solo assegnati)</label>
                    <select id="to_patient" class="form-control" style="width:100%"></select>
                    <input type="hidden" id="recipient_user_id" value="<?= (int)($draft['recipient_user_id'] ?? 0) ?>">
                    <p class="help-block">Cerca per nome/cognome tra i pazienti assegnati.</p>
                  </div>

                <?php else: ?>
                  <div class="form-group">
                    <label>Destinatario</label>
                    <select id="staff_role_dest" class="form-control">
                      <?php if ($roleLabel === 'SEGRETERIA'): ?>
                        <option value="ROLE:SEGRETERIA">Segreteria</option>
                      <?php endif; ?>
                      <?php if ($roleLabel === 'INFERMIERE'): ?>
                        <option value="ROLE:INFERMIERE">Infermieri</option>
                      <?php endif; ?>
                      <?php if ($roleLabel !== 'SEGRETERIA' && $roleLabel !== 'INFERMIERE'): ?>
                        <option value="ROLE:SEGRETERIA">Segreteria</option>
                        <option value="ROLE:INFERMIERE">Infermieri</option>
                      <?php endif; ?>
                    </select>
                    <p class="help-block">Per inoltri usa la pagina del thread.</p>
                  </div>
                <?php endif; ?>

                <!-- Editor -->
                <div class="form-group">
                  <textarea id="compose-textarea" class="form-control" style="height: 300px"
                            placeholder="Scrivi il messaggio..."><?= esc($prefillText) ?></textarea>
                </div>

                <!-- Allegati -->
                <div class="form-group">
                  <label>Allegati</label>
                  <input type="file" id="files" class="form-control" multiple>
                  <p class="help-block">PDF/JPG/PNG/DOC/DOCX/TXT - max 3MB ciascuno.</p>

                  <ul id="attachment-list" class="list-unstyled">
                    <?php if (!empty($attachments)): ?>
                      <?php foreach ($attachments as $a): ?>
                        <li data-attach-id="<?= (int)$a['id_attachment'] ?>">
                          <span class="file-name">
                            <i class="fa fa-paperclip"></i> <?= esc($a['original_name']) ?>
                          </span>
                          <button type="button" class="btn btn-xs btn-default remove-file">Rimuovi</button>
                        </li>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <li class="text-muted">Nessun allegato</li>
                    <?php endif; ?>
                  </ul>
                </div>

              </div>

              <div class="box-footer">
                <button type="submit" class="btn btn-primary" id="send-submit-btn">
                  <i class="fa fa-paper-plane"></i> Invia
                </button>
                <a href="<?= site_url('messaggi/bozze') ?>" class="btn btn-default">Bozze</a>
              </div>

            </form>

          </div>
        </div>

      </div>
    </section>
  </div>
</div>

<!-- JS -->
<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
<script src="<?= base_url('public/plugins/iCheck/icheck.min.js') ?>"></script>
<script src="<?= base_url('public/plugins/bootstrap-wysihtml5/bootstrap3-wysihtml5.all.min.js') ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="<?= base_url('public/dist/js/app.min.js') ?>"></script>

<script>
(function(){
  var role = document.getElementById('roleLabel').value || '';
  var idDraftEl = document.getElementById('id_draft');
  var sendIdDraftEl = document.getElementById('send_id_draft');
  var statusEl = document.getElementById('draftStatus');
  var sendForm = document.getElementById('send-form');
  var sendSubmitBtn = document.getElementById('send-submit-btn');

  var patientTargetEl = document.getElementById('patient_target');
  var staffRoleDestEl = document.getElementById('staff_role_dest');

  var toPatientEl = $('#to_patient');
  var recipientUserIdEl = document.getElementById('recipient_user_id');

  var editorTA = $('#compose-textarea');
  // mantengo la grafica del vecchio editor
  editorTA.wysihtml5({ locale: 'it-IT' });
// --- FIX: ascolta gli eventi del vero editor (non della textarea) ---
var w5 = editorTA.data('wysihtml5');
if (w5 && w5.editor) {
  var wEditor = w5.editor;

  // eventi "change" dell'editor
  wEditor.on('change', function(){ scheduleAutosave(); });

  // eventi di digitazione sul composer (contenteditable)
  wEditor.on('load', function(){
    try {
      var composerEl = wEditor.composer.element;
      composerEl.addEventListener('input', scheduleAutosave);
      composerEl.addEventListener('keyup', scheduleAutosave);
    } catch(e) {
      console.warn('Composer non disponibile', e);
    }
  });
} else {
  console.warn('wysihtml5 editor non inizializzato, fallback su textarea');
  editorTA.on('change keyup', scheduleAutosave);
}
  var filesEl = document.getElementById('files');
  var attachList = document.getElementById('attachment-list');
  var maxUploadBytes = <?= (int) APP_UPLOAD_MAX_FILE_SIZE_BYTES ?>;
  var maxUploadLabel = '<?= (int) APP_UPLOAD_MAX_FILE_SIZE_MB ?>MB';

  var dirty = false;
  var isSending = false;
  var tmr = null;
  var autosavePromise = null;

  function setStatus(text, cls){
    statusEl.className = 'label ' + (cls || 'label-default');
    statusEl.textContent = text;
  }

  function getDraftId(){
    return parseInt(idDraftEl.value || '0', 10) || 0;
  }
  function setDraftId(id){
    idDraftEl.value = String(id);
    sendIdDraftEl.value = String(id);
  }
function getPlainText(){
  var w5 = editorTA.data('wysihtml5');
  var html = '';

  if (w5 && w5.editor) {
    html = w5.editor.getValue();
  } else {
    html = editorTA.val() || '';
  }

  var div = document.createElement('div');
  div.innerHTML = html;

  // 1) trasforma <br> in newline
  div.querySelectorAll('br').forEach(function(el){
    el.replaceWith(document.createTextNode('\n'));
  });

  // 2) aggiungi newline dopo i blocchi principali
  div.querySelectorAll('p, div, li, tr, h1, h2, h3, h4, h5, h6').forEach(function(el){
    if (!el.textContent.endsWith('\n')) {
      el.appendChild(document.createTextNode('\n'));
    }
  });

  // 3) estrai testo
  var text = div.textContent || div.innerText || '';

  // 4) normalizza newline multipli
  text = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
  text = text.replace(/\n{3,}/g, '\n\n');

  return text.trimEnd();
}

  function buildPayload(){
    var payload = {
      id_draft: getDraftId(),
      body: getPlainText(),
      draft_kind: 'NEW'
    };

    if (role === 'PAZIENTE') {
      payload.recipient_type = 'PATIENT_TARGET';
      payload.patient_target_code = patientTargetEl ? patientTargetEl.value : '';
    } else if (role === 'DOTTORE') {
      payload.recipient_type = 'USER';
      payload.recipient_user_id = parseInt(recipientUserIdEl.value || '0', 10) || null;
    } else {
      var v = staffRoleDestEl ? staffRoleDestEl.value : 'ROLE:SEGRETERIA';
      payload.recipient_type = 'ROLE';
      payload.recipient_role = (v.indexOf('ROLE:INFERMIERE') === 0) ? 'INFERMIERE' : 'SEGRETERIA';
    }
    return payload;
  }

  function validatePatientTargetForSend(){
    if (role !== 'PAZIENTE' || !patientTargetEl) return true;

    var selected = patientTargetEl.value || '';
    var ok = ['MEDICO', 'SEGRETERIA', 'INFERMIERE'].indexOf(selected) !== -1;
    var group = document.getElementById('patient_target_group');

    if (group) {
      group.classList.toggle('has-error', !ok);
    }

    if (!ok) {
      patientTargetEl.focus();
      alert('Seleziona oggetto mail.');
    }

    return ok;
  }

  async function autosave(force){
    if (!force && !dirty) return true;
    if (autosavePromise) return autosavePromise;

    autosavePromise = (async function(){
      var payload = buildPayload();
      if (role === 'DOTTORE' && !payload.recipient_user_id){
        setStatus('Seleziona un paziente per salvare la bozza', 'label-warning');
        return false;
      }
      setStatus('Salvataggio...', 'label-info');

      try{
        var res = await fetch('<?= site_url('messaggi/api/bozza/salva') ?>', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify(payload)
        });
        var json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Errore autosave');

        setDraftId(json.id_draft);
        dirty = false;

        var now = new Date();
        var hh = String(now.getHours()).padStart(2,'0');
        var mm = String(now.getMinutes()).padStart(2,'0');
        setStatus('Salvato in bozza alle '+hh+':'+mm, 'label-success');
        return true;
      } catch(e){
        console.error(e);
        setStatus('Errore salvataggio bozza', 'label-danger');
        return false;
      } finally {
        autosavePromise = null;
      }
    })();

    return autosavePromise;
  }

  function scheduleAutosave(){
    if (isSending) return;
    dirty = true;
    if (tmr) clearTimeout(tmr);
    tmr = setTimeout(autosave, 800);
  }

  function submitFormNow(form){
    if (typeof HTMLFormElement !== 'undefined' && HTMLFormElement.prototype && typeof HTMLFormElement.prototype.submit === 'function') {
      HTMLFormElement.prototype.submit.call(form);
      return;
    }

    form.submit();
  }

  // Hook cambi
  editorTA.on('change keyup', scheduleAutosave);
  if (patientTargetEl) {
    patientTargetEl.addEventListener('change', function(){
      var group = document.getElementById('patient_target_group');
      if (group) group.classList.remove('has-error');
      scheduleAutosave();
    });
  }
  if (staffRoleDestEl) staffRoleDestEl.addEventListener('change', scheduleAutosave);

  // autosave periodico robusto
  setInterval(function(){
    if (!isSending && dirty) autosave(false);
  }, 10000);

  // conferma uscita pagina
sendForm.addEventListener('submit', async function(e){
  e.preventDefault();
  if (isSending) {
    e.stopImmediatePropagation();
    return;
  }

  if (!validatePatientTargetForSend()) {
    e.stopImmediatePropagation();
    dirty = true;
    return;
  }

  var loader = document.getElementById('page-loader');
  if (loader) {
    var msgEl = loader.querySelector('.msg');
    if (msgEl) msgEl.textContent = 'Invio in corsoâ€¦';
    loader.style.display = 'block';
  }
  if (tmr) {
    clearTimeout(tmr);
    tmr = null;
  }

  isSending = true;
  if (sendSubmitBtn) {
    sendSubmitBtn.disabled = true;
  }

  var ready = true;

  if (autosavePromise) {
    ready = await autosavePromise;
  }

  if (ready && (dirty || getDraftId() <= 0)) {
    dirty = true;
    ready = await autosave(true);
  }

  if (ready && getDraftId() > 0) {
    dirty = false;   // disabilita alert uscita
    submitFormNow(this);
    return;
  }

  isSending = false;
  if (sendSubmitBtn) {
    sendSubmitBtn.disabled = false;
  }
  if (loader) loader.style.display = 'none';
  alert('Impossibile creare la bozza (vedi console F12).');
});

  // Select2 ajax pazienti (solo medico)
  if (role === 'DOTTORE') {
 toPatientEl.select2({
  placeholder: 'Cerca paziente...',
  minimumInputLength: 2,
  ajax: {
    url: "<?= site_url('messaggi/api/pazienti') ?>",
    dataType: 'json',
    delay: 250,
    data: function (params) {
      return { q: params.term };
    },
    processResults: function (data) {
      // data.items Ã¨ giÃ  [{id:'33', text:'Bassi Alessio'}, ...]
      return { results: data.items || [] };
    },
    cache: true
  }
});

    toPatientEl.on('select2:select', function(e){
      var id = e.params.data.id;
      recipientUserIdEl.value = id;
      scheduleAutosave();
    });

    // se bozza giÃ  con recipient_user_id, prefill label (richiede una fetch; qui semplice placeholder)
  }

  async function ensureDraftExists(){
    if (getDraftId() > 0) return getDraftId();
    dirty = true;
    await autosave();
    return getDraftId();
  }

  function renderAttachments(list){
    attachList.innerHTML = '';
    if (!list || !list.length){
      var li = document.createElement('li');
      li.className = 'text-muted';
      li.textContent = 'Nessun allegato';
      attachList.appendChild(li);
      return;
    }
    list.forEach(function(a){
      var li = document.createElement('li');
      li.setAttribute('data-attach-id', a.id_attachment);
      li.innerHTML = '<span class="file-name"><i class="fa fa-paperclip"></i> '+escapeHtml(a.original_name)+'</span>'
                   + '<button type="button" class="btn btn-xs btn-default remove-file">Rimuovi</button>';
      attachList.appendChild(li);
    });
  }

  function escapeHtml(str){
    return (str||'').replace(/[&<>"']/g, function(s){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]);
    });
  }

  function validateSelectedFiles(fileList){
    if (!fileList || !fileList.length) return true;

    for (var i = 0; i < fileList.length; i++) {
      var file = fileList[i];
      if (file && file.size > maxUploadBytes) {
        alert('AVVISO: il file "' + file.name + '" e troppo grosso. Il limite massimo e ' + maxUploadLabel + '.');
        return false;
      }
    }

    return true;
  }

  // Upload allegati su bozza
  if (filesEl){
    filesEl.addEventListener('change', async function(){
      if (!filesEl.files || !filesEl.files.length) return;
      if (!validateSelectedFiles(filesEl.files)) {
        filesEl.value = '';
        return;
      }

      var loader = document.getElementById('page-loader');
      if (loader) {
        var msgEl = loader.querySelector('.msg');
        if (msgEl) msgEl.textContent = 'Caricamento allegato in corsoâ€¦';
        loader.style.display = 'block';
      }

      var draftId = await ensureDraftExists();
      if (!draftId){
        if (loader) loader.style.display = 'none';
        alert('Impossibile creare la bozza per allegare file.');
        return;
      }

      var fd = new FormData();
      fd.append('id_draft', String(draftId));
      for (var i=0;i<filesEl.files.length;i++){
        fd.append('files[]', filesEl.files[i]);
      }

      var res = await fetch('<?= site_url('messaggi/api/allegati/bozza/upload') ?>', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
      });
      var json = await res.json();
      if (!json.ok){
        if (loader) loader.style.display = 'none';
        alert(json.error || 'Errore upload');
        return;
      }
      renderAttachments(json.attachments || []);
      if (loader) loader.style.display = 'none';
      filesEl.value = '';
    });
  }

  // Rimozione allegato (delegation)
  attachList.addEventListener('click', async function(e){
    var btn = e.target.closest('.remove-file');
    if (!btn) return;
    var li = btn.closest('li');
    var id = li ? li.getAttribute('data-attach-id') : null;
    if (!id) return;

    try {
      var res = await fetch('<?= site_url('messaggi/api/allegati/bozza') ?>/'+encodeURIComponent(id), {
        method: 'DELETE',
        credentials: 'same-origin'
      });
      
      // Se lo status non Ã¨ 200/400 o se non Ã¨ JSON, cattura l'errore
      var text = await res.text();
      var json = null;
      try { json = JSON.parse(text); } catch(e) {}
      
      if (!json || !json.ok) {
        alert((json && json.error) ? json.error : 'Errore durante la rimozione dell\'allegato. Riprova.');
        return;
      }

      // Rimuovo dal DOM
      if (li) li.remove();
      // Se la lista Ã¨ vuota mostro il placeholder
      if (attachList.children.length === 0) {
        var ph = document.createElement('li');
        ph.className = 'text-muted';
        ph.textContent = 'Nessun allegato';
        attachList.appendChild(ph);
      }
    } catch(e) {
      console.error(e);
      alert('Errore di rete durante la rimozione dell\'allegato');
    }
  });
})();
</script>
</body>
</html>

