<?php
if (empty($menu_items) || !is_array($menu_items)) {
  $menu_items = session()->get('header_menu_items') ?? [];
}
$errors  = $errors ?? [];
$success = $success ?? null;
?>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatoriCLOUD | Modifica Personale</title>
  <meta content='width=device-width, initial-scale=1' name='viewport'>
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <style>
    .res-item { cursor:pointer; }
    .res-item:hover { background:#f5f5f5; }
  </style>
</head>

<body class="skin-blue sidebar-mini">
<div class="wrapper">

  <?= view('partials/header', ['menu_items' => $menu_items]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Modifica Personale</h1>
    </section>

    <section class="content">
      <div class="row">
        <div class="col-md-3">
          <?= view('partials/sidebar_admin', ['menu_items' => $menu_items]) ?>
        </div>

        <div class="col-md-9">
          <?php if ($success): ?>
            <div class="alert alert-success"><?= esc($success) ?></div>
          <?php endif; ?>
          <?php if (!empty($errors['generic'])): ?>
            <div class="alert alert-danger"><?= esc($errors['generic']) ?></div>
          <?php endif; ?>

          <!-- RICERCA -->
          <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title">Cerca Personale</h3></div>
            <div class="box-body">
              <form id="searchForm" action="javascript:void(0);">
                <div class="row">
                  <div class="col-md-4">
                    <label>Nome</label>
                    <input class="form-control" id="s_nome">
                  </div>
                  <div class="col-md-4">
                    <label>Cognome</label>
                    <input class="form-control" id="s_cognome">
                  </div>
                  <div class="col-md-4">
                    <label>Codice Fiscale</label>
                    <input class="form-control" id="s_cf">
                  </div>
                </div>
                <div style="margin-top:10px;">
                  <button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> Cerca</button>
                </div>
              </form>

              <hr>
              <div id="resultsWrap" style="display:none;">
                <label>Risultati</label>
                <ul class="list-group" id="resultsList"></ul>
              </div>
            </div>
          </div>

          <!-- EDIT -->
          <div class="box box-success" id="editBox" style="display:none;">
            <div class="box-header with-border"><h3 class="box-title">Dati Personale</h3></div>

            <form method="post" action="<?= site_url('admin/personale/update') ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="id_personale" id="id_personale_h">
              <input type="hidden" name="id_user" id="id_user">

              <div class="box-body">

                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Nome *</label>
                      <input class="form-control" name="nome" id="nome" required>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Cognome *</label>
                      <input class="form-control" name="cognome" id="cognome" required>
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Qualifica</label>
                      <input class="form-control" name="qualifica" id="qualifica">
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Tipo</label>
                      <select class="form-control" name="tipo" id="tipo">
                        <option value="">Seleziona...</option>
                      </select>
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Email</label>
                      <input type="email" class="form-control" name="email" id="email">
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Cellulare</label>
                      <input class="form-control" name="cellulare" id="cellulare">
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Luogo</label>
                      <input type="hidden" name="id_gruppo" id="id_gruppo">
                      <select class="form-control" name="luoghi[]" id="luoghi" multiple size="4">
                      </select>
                      <p class="text-muted" style="margin:6px 0 0 0;">
                        Per segretaria e infermiera puoi selezionare piu luoghi o "Tutti i luoghi".
                      </p>
                    </div>
                  </div>

                  <div class="col-md-6">
                    <label>Opzioni</label>
                    <div class="checkbox">
                      <label><input type="checkbox" name="titolare" id="titolare"> Titolare</label>
                    </div>
                    <div class="checkbox">
                      <label><input type="checkbox" name="sostituto" id="sostituto"> Sostituto</label>
                    </div>
                  </div>
                </div>

                <div class="row" style="margin-top:4px;">
                  <div class="col-md-12">
                    <label>Visibilita moduli</label>
                    <p class="text-muted" style="margin:4px 0 8px 0;">
                      Usa questi flag per decidere dove il personale deve comparire. Per esempio un dottore puo essere visibile in agenda ma non in posta o chat.
                    </p>
                    <div class="alert alert-info" style="margin-bottom:12px;">
                      Questi flag non assegnano le schede della home utente.
                      Per abilitare le schede operative usa
                      <a href="<?= site_url('admin/personale/schede-utenti') ?>"><strong>Gestione Schede Utenti</strong></a>.
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="checkbox">
                      <input type="hidden" name="show_in_agenda" value="0">
                      <label><input type="checkbox" name="show_in_agenda" id="show_in_agenda"> Visibile in agenda</label>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="checkbox">
                      <input type="hidden" name="show_in_posta" value="0">
                      <label><input type="checkbox" name="show_in_posta" id="show_in_posta"> Visibile in posta</label>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="checkbox">
                      <input type="hidden" name="show_in_chat" value="0">
                      <label><input type="checkbox" name="show_in_chat" id="show_in_chat"> Visibile in chat</label>
                    </div>
                  </div>
                </div>

                <hr>
                <h4 style="margin-top:0;">Credenziali (dap01_users)</h4>

                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Username (CF) *</label>
                      <input class="form-control" name="username" id="username" required>
                      <p class="text-muted" style="margin:6px 0 0 0;">Username NON cifrato.</p>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Nuova Password</label>
                      <input type="password" class="form-control" name="password" id="password" autocomplete="new-password">
                      <p class="text-muted" style="margin:6px 0 0 0;">Se vuoto, non cambia.</p>
                    </div>
                  </div>
                </div>
                <div class="row">
  <div class="col-md-6">
    <div class="form-group">
      <label>Scadenza account</label>
      <input type="date" class="form-control" name="datascadenza" id="datascadenza">
    </div>
  </div>
</div>

              </div>

              <div class="box-footer">
                <button class="btn btn-success" type="submit"><i class="fa fa-save"></i> Salva</button>
                <button class="btn btn-default" type="button" id="btnReset">Reset</button>
                <button class="btn btn-danger pull-right" type="button" id="btnJumpDeleteDoctor" style="display:none;">
                  <i class="fa fa-trash"></i> Elimina dottore
                </button>
              </div>

            </form>
          </div>

          <div class="box box-danger" id="deleteDoctorBox" style="display:none;">
            <div class="box-header with-border"><h3 class="box-title">Elimina Dottore</h3></div>
            <div class="box-body">
              <p class="text-danger" style="margin-bottom:8px;">Questa operazione e irreversibile.</p>
              <p style="margin-bottom:0;">
                Verranno eliminati account del dottore, appuntamenti, slot, memo, note, blocchi agenda, messaggi, allegati e collegamenti associati.
                Gli utenti/pazienti collegati resteranno nel sistema ma senza dottore associato.
              </p>
            </div>
            <div class="box-footer">
              <form method="post" action="<?= site_url('admin/personale/elimina-dottore') ?>" onsubmit="return confirm('Confermi l\\'eliminazione definitiva del dottore? Verranno cancellati appuntamenti, slot, memo, note, agenda, messaggi e collegamenti associati, mentre i pazienti resteranno senza dottore associato.');">
                <?= csrf_field() ?>
                <input type="hidden" name="id_personale" id="id_personale_delete">
                <button class="btn btn-danger" type="submit"><i class="fa fa-trash"></i> Elimina dottore</button>
              </form>
            </div>
          </div>

        </div>
      </div>
    </section>
  </div>
</div>

<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
<script src="<?= base_url('public/dist/js/app.min.js') ?>"></script>

<script>
(function(){
  function toggleDeleteDoctorBox(tipoValue){
    var isDoctor = String(tipoValue || '') === '1';
    $('#deleteDoctorBox').toggle(isDoctor);
    $('#btnJumpDeleteDoctor').toggle(isDoctor);
  }

  function escHtml(s){ return String(s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }

  function resetEdit(){
    $('#editBox').hide();
    $('#deleteDoctorBox').hide();
    $('#id_personale_h,#id_personale_delete,#id_user,#nome,#cognome,#qualifica,#email,#cellulare,#username,#password,#id_gruppo').val('');
    $('#tipo').html('<option value="">Seleziona...</option>');
    $('#luoghi').html('');
    $('#titolare,#sostituto,#show_in_agenda,#show_in_posta,#show_in_chat').prop('checked', false);
    $('#datascadenza').val('');
  }
function toISODate(datetimeStr){
  if(!datetimeStr) return '';
  return String(datetimeStr).split(' ')[0]; // YYYY-MM-DD
}
function buildSelect($sel, items, idKey, labelKey, selected){
  var selectedClean = String(selected ?? '').trim();  // ðŸ‘ˆ
  var html = '<option value="">Seleziona...</option>';

  (items||[]).forEach(function(it){
    var id  = String(it[idKey] ?? '').trim();         // ðŸ‘ˆ
    var lab = it[labelKey] ?? '';
    var sel = (selectedClean !== '' && selectedClean === id) ? ' selected' : '';
    html += '<option value="'+escHtml(id)+'"'+sel+'>'+escHtml(lab)+'</option>';
  });

  $sel.html(html);
}

function buildMultiSelect($sel, items, idKey, labelKey, selected){
  var selectedList = Array.isArray(selected) ? selected : [selected];
  selectedList = selectedList.map(function(v){ return String(v ?? '').trim(); }).filter(Boolean);
  var html = '<option value="__all__">Tutti i luoghi</option>';

  (items||[]).forEach(function(it){
    var id = String(it[idKey] ?? '').trim();
    var lab = it[labelKey] ?? '';
    var sel = selectedList.indexOf(id) !== -1 ? ' selected' : '';
    html += '<option value="'+escHtml(id)+'"'+sel+'>'+escHtml(lab)+'</option>';
  });

  $sel.html(html);
  syncLuoghi();
}

function syncLuoghi(){
  var sel = document.getElementById('luoghi');
  var primary = document.getElementById('id_gruppo');
  if (!sel || !primary) return;

  var allOption = Array.prototype.find.call(sel.options, function(opt){ return opt.value === '__all__'; });
  if (allOption && allOption.selected) {
    Array.prototype.forEach.call(sel.options, function(opt){
      opt.selected = opt.value !== '__all__';
    });
  }

  var selected = Array.prototype.filter.call(sel.options, function(opt){
    return opt.selected && opt.value !== '__all__';
  }).map(function(opt){ return opt.value; });

  primary.value = selected[0] || '';
}


  function doSearch(){
    $.get('<?= site_url('admin/personale/search') ?>', {
      nome: $('#s_nome').val(),
      cognome: $('#s_cognome').val(),
      cf: $('#s_cf').val()
    }).done(function(res){
      if(!res || !res.ok){ alert('Errore ricerca'); return; }
      var list = res.results || [];
      var $ul = $('#resultsList').empty();

      if(!list.length){
        $('#resultsWrap').show();
        $ul.append('<li class="list-group-item">Nessun risultato</li>');
        return;
      }

      list.forEach(function(r){
        var li = $('<li class="list-group-item res-item"></li>');
        li.text(r.label);
        li.attr('data-id', r.id_personale);
        $ul.append(li);
      });

      $('#resultsWrap').show();
    }).fail(function(){ alert('Errore chiamata ricerca'); });
  }

  function loadPersonale(id){
    $.get('<?= site_url('admin/personale/get') ?>/' + id)
      .done(function(res){
        if(!res || !res.ok){ alert(res && res.error ? res.error : 'Errore'); return; }

        var p = res.personale || {};
        var u = res.user || null;

        $('#id_personale_h').val(p.id_personale || '');
        $('#id_personale_delete').val(p.id_personale || '');
        $('#id_user').val(p.id_user || '');

        $('#nome').val(p.nome || '');
        $('#cognome').val(p.cognome || '');
        $('#qualifica').val(p.qualifica || '');
        $('#email').val(p.email || '');
        $('#cellulare').val(p.cellulare || '');

        $('#titolare').prop('checked', String(p.titolare) === '1');
        $('#sostituto').prop('checked', String(p.sostituto) === '1');
        $('#show_in_agenda').prop('checked', String(p.show_in_agenda) !== '0');
        $('#show_in_posta').prop('checked', String(p.show_in_posta) !== '0');
        $('#show_in_chat').prop('checked', String(p.show_in_chat) !== '0');
       
        buildSelect($('#tipo'), res.tipi || [], 'id', 'label', p.tipo || '');
        buildMultiSelect($('#luoghi'), res.gruppi || [], 'id', 'label', res.selected_luoghi || [p.luogo || '']);
        toggleDeleteDoctorBox(p.tipo || '');

        if(u){
          $('#username').val(u.username || '');
        } else {
          $('#username').val('');
        }
        if (u) {
  $('#datascadenza').val(toISODate(u.datascadenza));
} else {
  $('#datascadenza').val('');
}
        $('#password').val('');

        $('#editBox').show();
        $('html, body').animate({scrollTop: $('#editBox').offset().top - 20}, 200);
      })
      .fail(function(){ alert('Errore chiamata dettaglio'); });
  }

  $('#searchForm').on('submit', function(e){
    e.preventDefault();
    resetEdit();
    doSearch();
  });

  $('#resultsList').on('click', '.res-item', function(){
    var id = $(this).data('id');
    if(id) loadPersonale(id);
  });

  $('#btnReset').on('click', function(){ resetEdit(); });
  $('#luoghi').on('change', syncLuoghi);
  $('#tipo').on('change', function(){ toggleDeleteDoctorBox($(this).val()); });
  $('#btnJumpDeleteDoctor').on('click', function(){
    var $box = $('#deleteDoctorBox');
    if (!$box.is(':visible')) {
      return;
    }

    $('html, body').animate({scrollTop: $box.offset().top - 20}, 200);
  });

})();
</script>
</body>
</html>
