<?php
$result = session()->get('menuDataAdmin');
$menu_items = $menu_items ?? ($result['result'] ?? []);

$errors  = $errors ?? [];
$success = $success ?? null;

function hasErr($k, $errors) { return !empty($errors[$k]); }
?>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Modifica Cliente</title>
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <meta content='width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no' name='viewport'>

  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" type="text/css" />
  <link href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css" rel="stylesheet" type="text/css" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />

  <style>
    .nav-pills.nav-stacked > li.active > a { background-color:#2c8895; color:#fff; }
    .res-item { cursor:pointer; }
    .res-item:hover { background:#f5f5f5; }
  </style>
</head>

<body class="skin-blue sidebar-mini">
<div class="wrapper">

  <?= view('partials/header', ['menu_items' => $menu_items ?? []]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Modifica Cliente</h1>
      <ol class="breadcrumb">
        <li><a href="<?= site_url('admin') ?>"><i class="fa fa-dashboard"></i> Home</a></li>
        <li class="active">Modifica Cliente</li>
      </ol>
    </section>

    <section class="content">
      <div class="row">

        <div class="col-md-3">
          <?= view('partials/sidebar_admin', ['menu_items' => $menu_items ?? []]) ?>
        </div>

        <div class="col-md-9">

          <?php if ($success): ?>
            <div class="alert alert-success"><?= esc($success) ?></div>
          <?php endif; ?>
          <?php if (!empty($errors['generic'])): ?>
            <div class="alert alert-danger"><?= esc($errors['generic']) ?></div>
          <?php endif; ?>

          <!-- BOX RICERCA -->
         <div class="box box-primary">
  <div class="box-header with-border">
    <h3 class="box-title">Cerca Cliente</h3>
  </div>

  <div class="box-body">

    <form id="searchForm" action="javascript:void(0);">
      <div class="row">
        <div class="col-md-4">
          <label>Nome</label>
          <input class="form-control" id="s_nome" placeholder="es. mar">
        </div>
        <div class="col-md-4">
          <label>Cognome</label>
          <input class="form-control" id="s_cognome" placeholder="es. ros">
        </div>
        <div class="col-md-4">
          <label>Codice Fiscale</label>
          <input class="form-control" id="s_cf" placeholder="es. RSSM">
        </div>
      </div>

      <div style="margin-top:10px;">
        <button class="btn btn-primary" id="btnSearch" type="submit">
          <i class="fa fa-search"></i> Cerca
        </button>
      </div>
    </form>

    <hr>

    <div id="resultsWrap" style="display:none;">
      <label>Risultati</label>
      <ul class="list-group" id="resultsList"></ul>
    </div>

  </div>
</div>

          <!-- BOX MODIFICA -->
          <div class="box box-success" id="editBox" style="display:none;">
            <div class="box-header with-border">
              <h3 class="box-title">Dati Cliente</h3>
            </div>

            <form method="post" action="<?= site_url('admin/clienti/update') ?>">
              <?= csrf_field() ?>

              <input type="hidden" name="id_client" id="id_client">
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
                      <label>Cellulare *</label>
                      <input class="form-control" name="cellulare" id="cellulare" required>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Email (opzionale)</label>
                      <input type="email" class="form-control" name="email" id="email">
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Indirizzo</label>
                      <input class="form-control" name="indirizzo" id="indirizzo">
                    </div>
                  </div>
                  <div class="col-md-3">
                    <div class="form-group">
                      <label>CittÃ </label>
                      <input class="form-control" name="citta" id="citta">
                    </div>
                  </div>
                  <div class="col-md-3">
                    <div class="form-group">
                      <label>Provincia</label>
                      <input class="form-control" name="provincia" id="provincia">
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Codice Fiscale</label>
                      <input class="form-control" name="codice_fiscale" id="codice_fiscale" readonly>
                      <p class="text-muted" style="margin:6px 0 0 0;">
                        Il CF viene letto da <b>username</b>.
                      </p>
                    </div>
                  </div>

                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Dottore</label>
                      <select class="form-control" name="id_personale" id="id_personale">
                        <option value="">Seleziona...</option>
                      </select>
                      
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-12">
                    <div class="form-group">
                      <label>Dispositivo collegato</label>
                      <div id="deviceFeedback" style="display:none;"></div>
                      <div id="deviceStatus" class="alert alert-warning" style="margin-bottom:10px;">
                        Nessun cliente selezionato.
                      </div>
                      <button type="button" class="btn btn-danger" id="btnDisconnectDevice" style="display:none;">
                        <i class="fa fa-unlink"></i> Disassocia dispositivo
                      </button>
                      <p class="text-muted" style="margin:8px 0 0 0;">
                        L'amministratore puo disassociare autonomamente il telefono push del paziente.
                      </p>
                    </div>
                  </div>
                </div>

                <hr>

                <h4 style="margin-top:0;">Credenziali</h4>

                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Username (CF) *</label>
                      <input class="form-control" name="username" id="username" required>
                      <p class="text-muted" style="margin:6px 0 0 0;">Username NON cifrato (coincide col CF).</p>
                    </div>
                  </div>

                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Nuova Password</label>
                      <input type="password" class="form-control" name="password" id="password" autocomplete="new-password">
                      <p class="text-muted" style="margin:6px 0 0 0;">Se lasci vuoto, la password non cambia.</p>
                    </div>
                  </div>
                </div>

                <div class="row">
  <div class="col-md-6">
    <div class="form-group">
      <label>Scadenza account</label>
      <input type="date" class="form-control" name="datascadenza" id="datascadenza">
      <p class="text-muted" style="margin:6px 0 0 0;">Seleziona la nuova data per prorogare.</p>
    </div>
  </div>
</div>


              </div>

              <div class="box-footer">
                <button class="btn btn-success" type="submit">
                  <i class="fa fa-save"></i> Salva Modifiche
                </button>
                <button class="btn btn-default" type="button" id="btnReset">
                  Reset form
                </button>
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

  <aside class="control-sidebar control-sidebar-dark"></aside>
  <div class='control-sidebar-bg'></div>
</div>

<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
<script src="<?= base_url('public/plugins/slimScroll/jquery.slimscroll.min.js') ?>"></script>
<script src="<?= base_url('public/plugins/fastclick/fastclick.min.js') ?>"></script>
<script src="<?= base_url('public/dist/js/app.min.js') ?>"></script>

<script>
(function(){
  var csrfName = <?= json_encode(csrf_token()) ?>;
  var csrfHash = <?= json_encode(csrf_hash()) ?>;
  var disconnectUrl = <?= json_encode(site_url('admin/clienti/device/disconnect')) ?>;

  function escHtml(s){
    return String(s||'').replace(/[&<>"']/g, function(m){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
    });
  }

  function updateCsrf(name, hash){
    if(name){ csrfName = name; }
    if(hash){ csrfHash = hash; }

    $('input[name="' + csrfName + '"]').val(csrfHash);
  }

  function showDeviceFeedback(type, message){
    var $box = $('#deviceFeedback');
    if(!message){
      $box.hide().empty();
      return;
    }

    $box
      .removeClass()
      .addClass('alert alert-' + type)
      .text(message)
      .show();
  }

  function renderDeviceStatus(activeDevice, hasUser){
    var $status = $('#deviceStatus');
    var $btn = $('#btnDisconnectDevice');

    if(!hasUser){
      $status
        .removeClass()
        .addClass('alert alert-warning')
        .text('Il paziente non ha un account utente collegato, quindi non puo avere un dispositivo push associato.');
      $btn.hide();
      return;
    }

    if(activeDevice){
      var meta = [activeDevice.device_os || '', activeDevice.device_type || '']
        .filter(function(part){ return String(part).trim() !== ''; })
        .join(' - ');
      var lastSeen = String(activeDevice.last_seen || '').trim();
      var html = '<b>Attivo:</b> ' + escHtml(activeDevice.device_label || activeDevice.device_name || 'Dispositivo');

      if(meta){
        html += '<br><small class="text-muted">' + escHtml(meta) + '</small>';
      }

      if(lastSeen){
        html += '<br><small class="text-muted">Ultima attivita: ' + escHtml(lastSeen) + '</small>';
      }

      $status
        .removeClass()
        .addClass('alert alert-success')
        .html(html);
      $btn.show().prop('disabled', false);
      return;
    }

    $status
      .removeClass()
      .addClass('alert alert-warning')
      .text('Nessun dispositivo mobile attivo collegato a questo paziente.');
    $btn.hide();
  }

  function resetEdit(){
    $('#editBox').hide();
    $('#id_client,#id_user,#nome,#cognome,#cellulare,#email,#indirizzo,#citta,#provincia,#codice_fiscale,#username,#password').val('');
    $('#id_personale').html('<option value="">Seleziona...</option>');
    $('#datascadenza').val('');
    showDeviceFeedback(null, '');
    renderDeviceStatus(null, false);
  }

  function buildDoctorsSelect(doctors, selectedId){
    var html = '<option value="">Seleziona...</option>';
    (doctors||[]).forEach(function(d){
      var sel = (selectedId && String(selectedId)===String(d.id_personale)) ? ' selected' : '';
      html += '<option value="'+escHtml(d.id_personale)+'"'+sel+'>'+escHtml(d.label)+'</option>';
    });
    $('#id_personale').html(html);
  }

  function loadClient(idClient){
    $.get('<?= site_url('admin/clienti/get') ?>/' + idClient)
      .done(function(res){
        if(!res || !res.ok){ alert((res && res.error) ? res.error : 'Errore'); return; }

        var c = res.client || {};
        var u = res.user || null;

        $('#id_client').val(c.id_client||'');
        $('#id_user').val(c.id_user||'');

        $('#nome').val(c.nome||'');
        $('#cognome').val(c.cognome||'');
        $('#cellulare').val(c.cellulare||'');
        $('#email').val(c.email||'');
        $('#indirizzo').val(c.indirizzo||'');
        $('#citta').val(c.citta||'');
        $('#provincia').val(c.provincia||'');

        // username = CF
        if(u){
          $('#username').val(u.username||'');
          $('#codice_fiscale').val((u.username||'').toUpperCase());
        } else {
          $('#username').val('');
          $('#codice_fiscale').val('');
        }
       function toITDate(datetimeStr){
   if(!datetimeStr) return '';
  // prende solo la parte data YYYY-MM-DD
  var d = String(datetimeStr).split(' ')[0]; 
  var p = d.split('-');
  if(p.length !== 3) return '';
  return p[2] + '-' + p[1] + '-' + p[0];
}

function toISODate(datetimeStr){
  if(!datetimeStr) return '';
  return String(datetimeStr).split(' ')[0]; // YYYY-MM-DD
}

// ...
if (u) {
  $('#datascadenza').val(toISODate(u.datascadenza));     // per submit
} else {
  $('#datascadenza').val('');
}


        $('#password').val('');

        buildDoctorsSelect(res.doctors||[], res.selectedDoctorId || null);
        renderDeviceStatus(res.activeDevice || null, !!(u && u.id_user));
        showDeviceFeedback(null, '');

        $('#editBox').show();
        $('html, body').animate({scrollTop: $('#editBox').offset().top - 20}, 200);
      })
      .fail(function(){ alert('Errore chiamata dettaglio.'); });
  }

  function doSearch(){
    var nome = $('#s_nome').val();
    var cognome = $('#s_cognome').val();
    var cf = $('#s_cf').val();

    $.get('<?= site_url('admin/clienti/search') ?>', { nome:nome, cognome:cognome, cf:cf })
      .done(function(res){
        if(!res || !res.ok){
          alert((res && res.error) ? res.error : 'Errore durante l\'elaborazione della ricerca.');
          return;
        }
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
          li.attr('data-id', r.id_client);
          $ul.append(li);
        });

        $('#resultsWrap').show();
      })
      .fail(function(xhr){
        var res = xhr && xhr.responseJSON ? xhr.responseJSON : null;
        alert((res && res.error) ? res.error : 'Errore durante l\'elaborazione della ricerca.');
      });
  }

  $('#searchForm').on('submit', function(e){
  e.preventDefault();
  resetEdit();
  doSearch();
});


  $('#resultsList').on('click', '.res-item', function(){
    var id = $(this).data('id');
    if(id) loadClient(id);
  });

  $('#btnReset').on('click', function(){ resetEdit(); });

  $('#btnDisconnectDevice').on('click', function(){
    var idClient = parseInt($('#id_client').val(), 10) || 0;
    var idUser = parseInt($('#id_user').val(), 10) || 0;

    if(idClient <= 0 && idUser <= 0){
      showDeviceFeedback('warning', 'Seleziona prima un paziente valido.');
      return;
    }

    if(!window.confirm('Vuoi disassociare il dispositivo collegato a questo paziente?')){
      return;
    }

    var $btn = $(this);
    var originalHtml = $btn.html();
    var postData = {
      id_client: idClient,
      id_user: idUser
    };
    postData[csrfName] = csrfHash;

    $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Disassociazione...');
    showDeviceFeedback(null, '');

    $.post(disconnectUrl, postData)
      .done(function(res){
        if(res && res.csrfName && res.csrfHash){
          updateCsrf(res.csrfName, res.csrfHash);
        }

        if(!res || !res.ok){
          showDeviceFeedback('danger', (res && res.error) ? res.error : 'Errore durante la disassociazione del dispositivo.');
          return;
        }

        renderDeviceStatus(res.activeDevice || null, true);
        showDeviceFeedback('success', res.message || 'Dispositivo disassociato con successo.');
      })
      .fail(function(xhr){
        var res = xhr && xhr.responseJSON ? xhr.responseJSON : null;
        if(res && res.csrfName && res.csrfHash){
          updateCsrf(res.csrfName, res.csrfHash);
        }

        showDeviceFeedback('danger', (res && res.error) ? res.error : 'Errore durante la disassociazione del dispositivo.');
      })
      .always(function(){
        $btn.prop('disabled', false).html(originalHtml);
      });
  });

  // UI: quando cambi username aggiorno CF (readonly)
  $('#username').on('input', function(){
    $('#codice_fiscale').val(String($(this).val() || '').toUpperCase());
  });

})();
</script>

</body>
</html>
