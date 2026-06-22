<?php
if (empty($menu_items) || !is_array($menu_items)) {
    $menu_items = session()->get('header_menu_items') ?? [];
}

$errors = $errors ?? [];
$success = $success ?? null;
$preselectedId = (int)($preselectedId ?? 0);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Visibilita Moduli Personale</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <style>
    .res-item { cursor: pointer; }
    .res-item:hover { background: #f5f5f5; }
    .flag-card {
      border: 1px solid #e5e5e5;
      border-radius: 6px;
      padding: 14px 16px;
      min-height: 110px;
      background: #fff;
      margin-bottom: 12px;
    }
    .flag-card h4 {
      margin-top: 0;
      margin-bottom: 8px;
    }
    .flag-card p {
      color: #666;
      margin: 0 0 10px 0;
      min-height: 38px;
    }
  </style>
</head>

<body class="skin-blue sidebar-mini">
<div class="wrapper">

  <?= view('partials/header', ['menu_items' => $menu_items]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Visibilita Moduli Personale</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Pannello riservato all'amministratore per decidere se il personale deve comparire in agenda, posta e chat.
      </p>
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
          <div class="alert alert-info">
            Questi flag regolano dove il personale compare dentro agenda, posta e chat.
            Non assegnano le schede della home utente: per quelle usa
            <a href="<?= site_url('admin/personale/schede-utenti') ?>"><strong>Gestione Schede Utenti</strong></a>.
          </div>

          <div class="box box-primary">
            <div class="box-header with-border">
              <h3 class="box-title">Cerca Personale</h3>
            </div>
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
                    <label>Codice Fiscale / Username</label>
                    <input class="form-control" id="s_cf">
                  </div>
                </div>
                <div style="margin-top:10px;">
                  <button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> Cerca</button>
                  <button class="btn btn-default" type="button" id="btnResetSearch">Reset</button>
                </div>
              </form>

              <hr>
              <div id="resultsWrap" style="display:none;">
                <label>Risultati</label>
                <ul class="list-group" id="resultsList"></ul>
              </div>
            </div>
          </div>

          <div class="box box-success" id="editBox" style="display:none;">
            <div class="box-header with-border">
              <h3 class="box-title">Gestione Flag Moduli</h3>
            </div>

            <form method="post" action="<?= site_url('admin/personale/visibilita-moduli/update') ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="id_personale" id="id_personale">

              <div class="box-body">
                <div class="alert alert-info" style="margin-bottom:20px;">
                  <strong id="personaleSummary">Seleziona un utente.</strong><br>
                  <span id="personaleMeta"></span>
                </div>

                <div class="row">
                  <div class="col-md-4">
                    <div class="flag-card">
                      <h4>Agenda</h4>
                      <p>Se attivo, il personale compare nei selettori e nelle viste dell'agenda.</p>
                      <input type="hidden" name="show_in_agenda" value="0">
                      <div class="checkbox" style="margin:0;">
                        <label><input type="checkbox" name="show_in_agenda" id="show_in_agenda" value="1"> Visibile in agenda</label>
                      </div>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="flag-card">
                      <h4>Posta</h4>
                      <p>Se attivo, il personale compare nei contesti e nei filtri della posta.</p>
                      <input type="hidden" name="show_in_posta" value="0">
                      <div class="checkbox" style="margin:0;">
                        <label><input type="checkbox" name="show_in_posta" id="show_in_posta" value="1"> Visibile in posta</label>
                      </div>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="flag-card">
                      <h4>Chat</h4>
                      <p>Se attivo, il personale compare tra i medici e nei thread visibili in chat.</p>
                      <input type="hidden" name="show_in_chat" value="0">
                      <div class="checkbox" style="margin:0;">
                        <label><input type="checkbox" name="show_in_chat" id="show_in_chat" value="1"> Visibile in chat</label>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="box-footer">
                <button class="btn btn-success" type="submit"><i class="fa fa-save"></i> Salva Flag</button>
                <button class="btn btn-default" type="button" id="btnResetEdit">Reset selezione</button>
              </div>
            </form>
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
  var preselectedId = <?= json_encode($preselectedId) ?>;

  function escHtml(s){
    return String(s || '').replace(/[&<>"']/g, function(m){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m];
    });
  }

  function resetEdit(){
    $('#editBox').hide();
    $('#id_personale').val('');
    $('#personaleSummary').text('Seleziona un utente.');
    $('#personaleMeta').text('');
    $('#show_in_agenda,#show_in_posta,#show_in_chat').prop('checked', false);
  }

  function resetSearch(){
    $('#s_nome,#s_cognome,#s_cf').val('');
    $('#resultsList').empty();
    $('#resultsWrap').hide();
  }

  function buildMeta(personale, user){
    var parts = [];
    var qualifica = $.trim(personale.qualifica || '');
    var username = user && user.username ? user.username : '';

    if (qualifica) parts.push('Qualifica: ' + qualifica);
    if (username) parts.push('Username: ' + username);
    parts.push('ID personale: ' + (personale.id_personale || ''));

    return parts.join(' | ');
  }

  function doSearch(){
    $.get('<?= site_url('admin/personale/visibilita-moduli/search') ?>', {
      nome: $('#s_nome').val(),
      cognome: $('#s_cognome').val(),
      cf: $('#s_cf').val()
    }).done(function(res){
      if (!res || !res.ok) {
        alert('Errore ricerca');
        return;
      }

      var list = res.results || [];
      var $ul = $('#resultsList').empty();
      $('#resultsWrap').show();

      if (!list.length) {
        $ul.append('<li class="list-group-item">Nessun risultato</li>');
        return;
      }

      list.forEach(function(r){
        var li = $('<li class="list-group-item res-item"></li>');
        li.text(r.label);
        li.attr('data-id', r.id_personale);
        $ul.append(li);
      });
    }).fail(function(){
      alert('Errore chiamata ricerca');
    });
  }

  function loadPersonale(id){
    $.get('<?= site_url('admin/personale/visibilita-moduli/get') ?>/' + id)
      .done(function(res){
        if (!res || !res.ok) {
          alert((res && res.error) ? res.error : 'Errore caricamento');
          return;
        }

        var p = res.personale || {};
        var u = res.user || null;
        var label = $.trim((p.cognome || '') + ' ' + (p.nome || ''));

        $('#id_personale').val(p.id_personale || '');
        $('#personaleSummary').text(label || ('Personale #' + (p.id_personale || '')));
        $('#personaleMeta').text(buildMeta(p, u));
        $('#show_in_agenda').prop('checked', String(p.show_in_agenda) !== '0');
        $('#show_in_posta').prop('checked', String(p.show_in_posta) !== '0');
        $('#show_in_chat').prop('checked', String(p.show_in_chat) !== '0');

        $('#editBox').show();
        $('html, body').animate({scrollTop: $('#editBox').offset().top - 20}, 200);
      })
      .fail(function(){
        alert('Errore chiamata dettaglio');
      });
  }

  $('#searchForm').on('submit', function(e){
    e.preventDefault();
    resetEdit();
    doSearch();
  });

  $('#resultsList').on('click', '.res-item', function(){
    var id = $(this).data('id');
    if (id) loadPersonale(id);
  });

  $('#btnResetSearch').on('click', function(){
    resetSearch();
    resetEdit();
  });

  $('#btnResetEdit').on('click', function(){
    resetEdit();
  });

  if (preselectedId > 0) {
    loadPersonale(preselectedId);
  }
})();
</script>
</body>
</html>
