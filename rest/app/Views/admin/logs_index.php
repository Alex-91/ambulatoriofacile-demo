<?php
if (empty($menu_items) || !is_array($menu_items)) {
  $menu_items = session()->get('header_menu_items') ?? [];
}
?>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatoriCLOUD | Log Server</title>
  <meta content='width=device-width, initial-scale=1' name='viewport'>
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <style>
    #logBox { white-space: pre; font-family: monospace; font-size: 12px; height: 520px; overflow:auto; background:#111; color:#eaeaea; padding:10px; border-radius:4px; }
    mark { padding:0 2px; }
  </style>
</head>

<body class="skin-blue sidebar-mini">
<div class="wrapper">

  <?= view('partials/header', ['menu_items' => $menu_items]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Log Server</h1>
    </section>

    <section class="content">
      <div class="row">
        <div class="col-md-3">
          <?= view('partials/sidebar_admin', ['menu_items' => $menu_items]) ?>
        </div>

        <div class="col-md-9">

          <div class="box box-primary">
            <div class="box-header with-border">
              <h3 class="box-title">Filtri</h3>
            </div>
            <div class="box-body">
              <div class="row">
                <div class="col-md-4">
                  <label>Data</label>
                  <input type="date" class="form-control" id="logDate">
                </div>

                <div class="col-md-3">
                  <label>Ultime righe</label>
                  <select class="form-control" id="tail">
                    <option value="0">Tutto il file</option>
                    <option value="200">200</option>
                    <option value="500" selected>500</option>
                    <option value="1000">1000</option>
                    <option value="5000">5000</option>
                  </select>
                </div>

                <div class="col-md-5">
                  <label>Cerca nel log</label>
                  <div class="input-group">
                    <input class="form-control" id="q" placeholder="es. ERROR, id_user, endpoint...">
                    <span class="input-group-btn">
                      <button class="btn btn-default" id="btnFind"><i class="fa fa-search"></i></button>
                    </span>
                  </div>
                </div>
              </div>

              <div style="margin-top:10px;">
                <button class="btn btn-primary" id="btnLoad"><i class="fa fa-refresh"></i> Carica</button>
                <a class="btn btn-default" id="btnDownload" href="#" target="_blank">
                  <i class="fa fa-download"></i> Download
                </a>

                <label style="margin-left:15px; font-weight:normal;">
                  <input type="checkbox" id="auto"> Auto-refresh (10s)
                </label>
              </div>
            </div>
          </div>

          <div class="box box-success">
            <div class="box-header with-border">
              <h3 class="box-title">Contenuto</h3>
            </div>
            <div class="box-body">
              <div id="logBox">Seleziona una data e premi â€œCaricaâ€.</div>
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
  var timer = null;

  function todayISO(){
    var d = new Date();
    var mm = String(d.getMonth()+1).padStart(2,'0');
    var dd = String(d.getDate()).padStart(2,'0');
    return d.getFullYear() + '-' + mm + '-' + dd;
  }

  function escapeHtml(s){
    return String(s||'').replace(/[&<>"']/g, function(m){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
    });
  }

  function setDownloadLink(date){
    var url = '<?= site_url('admin/logs/download') ?>' + '?date=' + encodeURIComponent(date || '');
    $('#btnDownload').attr('href', url);
  }

  function loadLog(){
    var date = $('#logDate').val();
    var tail = $('#tail').val();

    if(!date){
      alert('Seleziona una data.');
      return;
    }

    setDownloadLink(date);

    $('#logBox').text('Caricamento...');
    $.ajax({
      url: '<?= site_url('admin/logs/read') ?>',
      method: 'GET',
      data: { date: date, tail: tail },
      dataType: 'text'
    }).done(function(txt){
      $('#logBox').text(txt || '(vuoto)');
    }).fail(function(xhr){
      var msg = xhr && xhr.responseText ? xhr.responseText : 'Errore caricamento log.';
      $('#logBox').text(msg);
    });
  }

  function highlight(){
    var q = String($('#q').val() || '').trim();
    var raw = $('#logBox').text();

    if(!q){
      // ripristino semplice: ricarico (evita di tenere html)
      loadLog();
      return;
    }

    var safe = escapeHtml(raw);
    var re;
    try {
      re = new RegExp(q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
    } catch(e){
      return;
    }

    var html = safe.replace(re, function(m){ return '<mark>' + m + '</mark>'; });
    $('#logBox').html(html);
  }

  function setAuto(on){
    if(timer){ clearInterval(timer); timer = null; }
    if(on){
      timer = setInterval(loadLog, 10000);
    }
  }

  // default: oggi
  $('#logDate').val(todayISO());
  setDownloadLink($('#logDate').val());

  $('#btnLoad').on('click', function(e){ e.preventDefault(); loadLog(); });
  $('#btnFind').on('click', function(e){ e.preventDefault(); highlight(); });

  // Enter per caricare (nei campi)
  $('#logDate,#q').on('keydown', function(e){
    if(e.key === 'Enter'){
      e.preventDefault();
      loadLog();
    }
  });

  $('#auto').on('change', function(){
    setAuto(this.checked);
  });

})();
</script>
</body>
</html>
