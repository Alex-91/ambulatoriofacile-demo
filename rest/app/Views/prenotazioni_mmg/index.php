<?php
$result = session()->get('menuData');
$menu_items = $menu_items ?? ($result['result'] ?? []);
?>
<html>
  <head>
    <meta charset="UTF-8">
    <title><?= esc('AmbulatorioFacile') ?> | Prenotazione MMG</title>
    <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
    <meta content='width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no' name='viewport'>

    <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" type="text/css" />
    <link href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />

    <style>
      .choice-box { border-radius: 8px; padding: 18px; }
      .choice-box .big-ico { font-size: 42px; opacity: .9; }
    </style>
  </head>

  <body class="skin-blue sidebar-mini">
    <div class="wrapper">
      <?= view('partials/header', ['menu_items' => $menu_items ?? []]) ?>

      <aside class="main-sidebar" style="display:none"><section class="sidebar"></section></aside>

      <div class="content-wrapper">
        <section class="content-header">
          <h1>Prenotazione Medico di Famiglia</h1>
          <ol class="breadcrumb">
            <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
            <li class="active">Prenotazione MMG</li>
          </ol>
        </section>

        <section class="content">
          <div class="row">
              <?php if (!empty($menu_prenotazioni)): ?>
          <?php foreach ($menu_prenotazioni as $m): ?>
            <div class="col-md-6">
                 <div class="box box-success choice-box">
                <div class="box-header with-border">
                   <h3 class="box-title"><i class="fa fa-calendar-plus-o"></i> <?= esc($m['titolo']) ?></h3>
                </div>
                <div class="box-body">
                  <p><?= esc($m['descrizione']) ?></p>
                  <a href="<?= base_url($m['url']) ?>" class="btn btn-success">
                    Vai <i class="fa fa-arrow-right"></i>
                  </a>
                </div>
                </div>
            </div>
            
            
          <?php endforeach; ?>
        <?php else: ?>
          <div class="col-12">
            <div class="alert alert-warning">
              Nessuna voce di menu prenotazioni attiva .
            </div>
          </div>
        <?php endif; ?>
           
          </div>
        </section>
      </div>

      <footer class="main-footer">
        <div class="pull-right hidden-xs"><b>Version</b> 2.0</div>
        <strong>&copy; <?= esc('AmbulatorioFacile') ?></strong>
      </footer>
      <aside class="control-sidebar control-sidebar-dark"></aside>
      <div class='control-sidebar-bg'></div>
    </div>

    <script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
    <script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>" type="text/javascript"></script>
    <script src="<?= base_url('public/dist/js/app.min.js') ?>" type="text/javascript"></script>
  </body>
</html>

