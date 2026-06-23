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
  <title>AmbulatorioFacile | Gestione Schede Utenti</title>
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <meta content='width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no' name='viewport'>

  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" type="text/css" />
  <link href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css" rel="stylesheet" type="text/css" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />

  <style>
    .nav-pills.nav-stacked > li.active > a { background-color:#2c8895; color:#fff; }

    .scheda-row td { vertical-align: middle !important; }
    .mini-muted { margin:6px 0 0 0; color:#777; font-size:12px; }
    .pill { display:inline-block; padding:2px 8px; border-radius:10px; font-size:12px; }
    .pill-ok { background:#dff0d8; color:#3c763d; }
    .pill-no { background:#f2dede; color:#a94442; }
    .actions-bar { margin-bottom:10px; display:none; }
    .user-badge { font-size:14px; }
  </style>
</head>

<body class="skin-blue sidebar-mini">
<div class="wrapper">

  <?= view('partials/header', ['menu_items' => $menu_items ?? []]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Gestione Menu Utenti</h1>
      <ol class="breadcrumb">
        <li><a href="<?= site_url('admin') ?>"><i class="fa fa-dashboard"></i> Home</a></li>
        <li class="active">Schede Utenti</li>
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
              <h3 class="box-title"><i class="fa fa-search"></i> Cerca Utente</h3>
            </div>

            <div class="box-body">
              <form id="searchForm" action="javascript:void(0);">
                <div class="row">
                  <div class="col-md-6">
                    <label>Username</label>
                    <input class="form-control" id="s_username" placeholder="es. RSSMRA80A01H501U (o username)">
                    <p class="mini-muted">Ricerca esatta per <b>username</b>.</p>
                  </div>
                  <div class="col-md-3">
                    <button class="btn btn-primary" id="btnSearch" type="submit" style="margin-top:25px;">
                      <i class="fa fa-search"></i> Cerca
                    </button>
                  </div>
                  <div class="col-md-3 text-right">
                    <div id="utenteSelezionato" class="user-badge" style="margin-top:30px;"></div>
                  </div>
                </div>
              </form>

              <div id="alertBox" style="margin-top:10px; display:none;" class="alert" role="alert"></div>
            </div>
          </div>

          <!-- BOX GESTIONE SCHEDE -->
          <div class="box box-success" id="schedeBox" style="display:none;">
            <div class="box-header with-border">
              <h3 class="box-title"><i class="fa fa-th-large"></i> Schede home utente</h3>
            </div>

            <div class="box-body">

              <div class="actions-bar" id="actionsBar">
                <button class="btn btn-success btn-sm" id="abilitaTutteView">
                  <i class="fa fa-eye"></i> Abilita VIEW tutte
                </button>
                <button class="btn btn-warning btn-sm" id="disabilitaTutteView">
                  <i class="fa fa-eye-slash"></i> Disabilita VIEW tutte
                </button>
                <button class="btn btn-success btn-sm" id="abilitaTutteAccess">
                  <i class="fa fa-unlock"></i> Abilita ACCESS tutte
                </button>
                <button class="btn btn-warning btn-sm" id="disabilitaTutteAccess">
                  <i class="fa fa-lock"></i> Disabilita ACCESS tutte
                </button>

                <span class="mini-muted" style="margin-left:10px;">
                  Regole: se togli <b>VIEW</b> â†’ toglie anche <b>ACCESS</b>. Se metti <b>ACCESS</b> â†’ mette anche <b>VIEW</b>.
                </span>
              </div>

              <div class="table-responsive">
                <table class="table table-bordered table-hover" id="tblSchede">
                  <thead>
                    <tr>
                      <th style="width:70px;">Ordine</th>
                      <th>Codice</th>
                      <th>Titolo</th>
                      <th>URL</th>
                      <th style="width:120px;" class="text-center">Can View</th>
                      <th style="width:140px;" class="text-center">Can Access</th>
                    </tr>
                  </thead>
                  <tbody></tbody>
                </table>
              </div>

            </div>
          </div>

          <div class="box box-warning" id="adminMenuBox" style="display:none;">
            <div class="box-header with-border">
              <h3 class="box-title"><i class="fa fa-sitemap"></i> Menu admin e console</h3>
            </div>

            <div class="box-body">
              <div class="actions-bar" id="adminMenuActionsBar">
                <button class="btn btn-success btn-sm" id="mostraTuttoMenuAdmin">
                  <i class="fa fa-eye"></i> Mostra tutte
                </button>
                <button class="btn btn-warning btn-sm" id="nascondiTuttoMenuAdmin">
                  <i class="fa fa-eye-slash"></i> Nascondi tutte
                </button>
                <span class="mini-muted" style="margin-left:10px;">
                  Qui controlli le voci del menu operativo/admin visibili all utente selezionato.
                </span>
              </div>

              <div class="table-responsive">
                <table class="table table-bordered table-hover" id="tblAdminMenu">
                  <thead>
                    <tr>
                      <th style="width:180px;">Gruppo</th>
                      <th>Titolo</th>
                      <th>Link logico</th>
                      <th>Descrizione</th>
                      <th style="width:140px;" class="text-center">Mostra</th>
                    </tr>
                  </thead>
                  <tbody></tbody>
                </table>
              </div>
            </div>
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
  var CURRENT_USER = null;

  function escHtml(s){
    return String(s||'').replace(/[&<>"']/g, function(m){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
    });
  }

  function showAlert(type, msg){
    $('#alertBox')
      .removeClass('alert-success alert-danger alert-warning')
      .addClass('alert-' + type)
      .text(msg)
      .show();
  }

  function clearAlert(){
    $('#alertBox').hide().text('').removeClass('alert-success alert-danger alert-warning');
  }

  function resetUI(){
    CURRENT_USER = null;
    $('#utenteSelezionato').html('');
    $('#schedeBox').hide();
    $('#actionsBar').hide();
    $('#tblSchede tbody').empty();
    $('#adminMenuBox').hide();
    $('#adminMenuActionsBar').hide();
    $('#tblAdminMenu tbody').empty();
  }

  function renderTable(list){
    var $tb = $('#tblSchede tbody').empty();

    (list||[]).forEach(function(s){
      var viewChecked   = (parseInt(s.can_view,10) === 1) ? 'checked' : '';
      var accessChecked = (parseInt(s.can_access,10) === 1) ? 'checked' : '';

      var pillView   = (parseInt(s.can_view,10) === 1) ? '<span class="pill pill-ok">SI</span>' : '<span class="pill pill-no">NO</span>';
      var pillAccess = (parseInt(s.can_access,10) === 1) ? '<span class="pill pill-ok">SI</span>' : '<span class="pill pill-no">NO</span>';

      $tb.append(
        '<tr class="scheda-row" data-id_scheda="'+escHtml(s.id_scheda)+'">'+
          '<td>'+escHtml(s.ordine)+'</td>'+
          '<td><code>'+escHtml(s.codice)+'</code></td>'+
          '<td>'+escHtml(s.titolo)+'</td>'+
          '<td><small>'+escHtml(s.url)+'</small></td>'+
          '<td class="text-center">'+
            '<div style="display:flex;justify-content:center;gap:10px;align-items:center;">'+
              '<input type="checkbox" class="toggle-flag" data-field="can_view" '+viewChecked+'>'+
              pillView+
            '</div>'+
          '</td>'+
          '<td class="text-center">'+
            '<div style="display:flex;justify-content:center;gap:10px;align-items:center;">'+
              '<input type="checkbox" class="toggle-flag" data-field="can_access" '+accessChecked+'>'+
              pillAccess+
            '</div>'+
          '</td>'+
        '</tr>'
      );
    });
  }

  function renderAdminMenuTable(list){
    var $tb = $('#tblAdminMenu tbody').empty();

    (list || []).forEach(function(item){
      var checked = (parseInt(item.can_view, 10) === 1) ? 'checked' : '';
      var pill = (parseInt(item.can_view, 10) === 1)
        ? '<span class="pill pill-ok">SI</span>'
        : '<span class="pill pill-no">NO</span>';

      $tb.append(
        '<tr class="admin-menu-row" data-menu-key="' + escHtml(item.menu_key) + '">' +
          '<td>' + escHtml(item.gruppo || '') + '</td>' +
          '<td><strong>' + escHtml(item.titolo || '') + '</strong></td>' +
          '<td><code>' + escHtml(item.menu_link || '') + '</code></td>' +
          '<td><small>' + escHtml(item.descrizione || '') + '</small></td>' +
          '<td class="text-center">' +
            '<div style="display:flex;justify-content:center;gap:10px;align-items:center;">' +
              '<input type="checkbox" class="toggle-admin-menu" ' + checked + '>' +
              pill +
            '</div>' +
          '</td>' +
        '</tr>'
      );
    });
  }

  function loadSchede(id_user){
    clearAlert();
    $.get("<?= site_url('admin/schede-utenti/lista'); ?>", { id_user: id_user })
      .done(function(res){
        if(!res || !res.ok){
          return showAlert('danger', (res && res.error) ? res.error : 'Errore caricamento schede');
        }
        renderTable(res.schede || []);
        $('#schedeBox').show();
        $('#actionsBar').show();
        $('html, body').animate({scrollTop: $('#schedeBox').offset().top - 20}, 150);
      })
      .fail(function(){
        showAlert('danger', 'Errore di rete nel caricamento schede');
      });
  }

  function loadAdminMenu(id_user){
    clearAlert();
    $.get("<?= site_url('admin/schede-utenti/menu-admin'); ?>", { id_user: id_user })
      .done(function(res){
        if(!res || !res.ok){
          return showAlert('danger', (res && res.error) ? res.error : 'Errore caricamento menu admin');
        }

        renderAdminMenuTable(res.menu_admin || []);
        $('#adminMenuBox').show();
        $('#adminMenuActionsBar').show();
      })
      .fail(function(){
        showAlert('danger', 'Errore di rete nel caricamento menu admin');
      });
  }

  function toggleFlag(id_scheda, field, value){
    $.post("<?= site_url('admin/schede-utenti/toggle'); ?>", {
      id_user: CURRENT_USER.id_user,
      id_scheda: id_scheda,
      field: field,
      value: value
    })
    .done(function(res){
      if(!res || !res.ok){
        showAlert('danger', (res && res.error) ? res.error : 'Errore salvataggio');
        // ricarico per riallineare se serve
        if(CURRENT_USER) loadSchede(CURRENT_USER.id_user);
        return;
      }

      // riallineo SOLO la riga (server ritorna lo stato coerente)
      if(res.updated){
        var u = res.updated;
        var $tr = $('#tblSchede tr[data-id_scheda="'+id_scheda+'"]');
        $tr.find('input[data-field="can_view"]').prop('checked', parseInt(u.can_view,10) === 1);
        $tr.find('input[data-field="can_access"]').prop('checked', parseInt(u.can_access,10) === 1);

        // aggiorno le pill SI/NO senza ricostruire tutta la tabella
        var viewPill   = (parseInt(u.can_view,10) === 1) ? '<span class="pill pill-ok">SI</span>' : '<span class="pill pill-no">NO</span>';
        var accessPill = (parseInt(u.can_access,10) === 1) ? '<span class="pill pill-ok">SI</span>' : '<span class="pill pill-no">NO</span>';

        $tr.find('td:eq(4) .pill').last().replaceWith(viewPill);
        $tr.find('td:eq(5) .pill').last().replaceWith(accessPill);
      }
    })
    .fail(function(){
      showAlert('danger', 'Errore di rete nel salvataggio');
      if(CURRENT_USER) loadSchede(CURRENT_USER.id_user);
    });
  }

  function bulkSet(field, value){
    if(!CURRENT_USER) return showAlert('warning', 'Prima seleziona un utente');

    var rows = $('#tblSchede tbody tr').toArray();
    var i = 0;

    function next(){
      if(i >= rows.length) return;
      var id_scheda = parseInt($(rows[i]).data('id_scheda'), 10);
      i++;
      toggleFlag(id_scheda, field, value);
      // piccola coda per non saturare
      setTimeout(next, 30);
    }
    next();
  }

  function toggleAdminMenu(menuKey, value){
    $.post("<?= site_url('admin/schede-utenti/menu-admin/toggle'); ?>", {
      id_user: CURRENT_USER.id_user,
      menu_key: menuKey,
      value: value
    })
    .done(function(res){
      if(!res || !res.ok){
        showAlert('danger', (res && res.error) ? res.error : 'Errore salvataggio menu admin');
        if(CURRENT_USER) loadAdminMenu(CURRENT_USER.id_user);
        return;
      }

      if(res.updated){
        var u = res.updated;
        var $tr = $('#tblAdminMenu tr[data-menu-key="' + menuKey.replace(/"/g, '&quot;') + '"]');
        $tr.find('.toggle-admin-menu').prop('checked', parseInt(u.can_view, 10) === 1);

        var pill = (parseInt(u.can_view, 10) === 1)
          ? '<span class="pill pill-ok">SI</span>'
          : '<span class="pill pill-no">NO</span>';

        $tr.find('td:eq(4) .pill').last().replaceWith(pill);
      }
    })
    .fail(function(){
      showAlert('danger', 'Errore di rete nel salvataggio menu admin');
      if(CURRENT_USER) loadAdminMenu(CURRENT_USER.id_user);
    });
  }

  function bulkSetAdminMenu(value){
    if(!CURRENT_USER) return showAlert('warning', 'Prima seleziona un utente');

    var rows = $('#tblAdminMenu tbody tr').toArray();
    var i = 0;

    function next(){
      if(i >= rows.length) return;
      var menuKey = String($(rows[i]).data('menu-key') || '');
      i++;
      if(menuKey !== ''){
        toggleAdminMenu(menuKey, value);
      }
      setTimeout(next, 30);
    }

    next();
  }

  // submit ricerca utente
  $('#searchForm').on('submit', function(e){
    e.preventDefault();
    clearAlert();
    resetUI();

    var username = $('#s_username').val().trim();
    if(!username){
      return showAlert('warning', 'Inserisci uno username');
    }

    $.post("<?= site_url('admin/schede-utenti/cerca'); ?>", { username: username })
      .done(function(res){
        if(!res || !res.ok){
          return showAlert('danger', (res && res.error) ? res.error : 'Utente non trovato');
        }

        CURRENT_USER = res.user;

        $('#utenteSelezionato').html(
          'Utente: <b>'+escHtml(CURRENT_USER.username)+'</b> '+
          '<span class="label label-info">ID '+escHtml(CURRENT_USER.id_user)+'</span>'
        );

        loadSchede(CURRENT_USER.id_user);
        loadAdminMenu(CURRENT_USER.id_user);
      })
      .fail(function(){
        showAlert('danger', 'Errore di rete nella ricerca utente');
      });
  });

  // toggle checkbox
  $('#tblSchede').on('change', '.toggle-flag', function(){
    if(!CURRENT_USER){
      $(this).prop('checked', !$(this).prop('checked'));
      return showAlert('warning', 'Prima cerca e seleziona un utente');
    }
    var $tr = $(this).closest('tr');
    var id_scheda = parseInt($tr.data('id_scheda'), 10);
    var field = $(this).data('field');
    var value = $(this).is(':checked') ? 1 : 0;
    toggleFlag(id_scheda, field, value);
  });

  // bulk buttons
  $('#abilitaTutteView').on('click', function(){ bulkSet('can_view', 1); });
  $('#disabilitaTutteView').on('click', function(){ bulkSet('can_view', 0); });
  $('#abilitaTutteAccess').on('click', function(){ bulkSet('can_access', 1); });
  $('#disabilitaTutteAccess').on('click', function(){ bulkSet('can_access', 0); });
  $('#mostraTuttoMenuAdmin').on('click', function(){ bulkSetAdminMenu(1); });
  $('#nascondiTuttoMenuAdmin').on('click', function(){ bulkSetAdminMenu(0); });

  $('#tblAdminMenu').on('change', '.toggle-admin-menu', function(){
    if(!CURRENT_USER){
      $(this).prop('checked', !$(this).prop('checked'));
      return showAlert('warning', 'Prima cerca e seleziona un utente');
    }

    var $tr = $(this).closest('tr');
    var menuKey = String($tr.data('menu-key') || '');
    var value = $(this).is(':checked') ? 1 : 0;
    toggleAdminMenu(menuKey, value);
  });

})();
</script>

</body>
</html>
