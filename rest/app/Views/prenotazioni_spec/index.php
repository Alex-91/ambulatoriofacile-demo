<?php
$result = session()->get('menuData');
$menu_items = $menu_items ?? ($result['result'] ?? []);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title><?= esc('AmbulatorioFacile') ?> | Prenotazioni Specialistiche</title>
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">

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
      <h1>Prenotazioni Specialistiche</h1>
      <ol class="breadcrumb">
        <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
        <li class="active">Prenotazioni Specialistiche</li>
      </ol>
    </section>

    <section class="content">
      <div class="row">

        <!-- BOX 1: Nuova prenotazione specialistica -->
        <div class="col-md-6">
          <div class="box box-primary choice-box">
            <div class="box-header with-border">
              <h3 class="box-title">
                <i class="fa fa-plus-circle"></i> Nuova prenotazione specialistica
              </h3>
            </div>
            <div class="box-body">
              <p>Avvia una nuova prenotazione selezionando la specializzazione e lo specialista.</p>
              <a class="btn btn-primary" href="<?= base_url('prenotazioni/specialisti/nuova') ?>">
                Vai <i class="fa fa-arrow-right"></i>
              </a>
            </div>
          </div>
        </div>

        <!-- BOX 2: Gestisci prenotazioni -->
        <div class="col-md-6">
          <div class="box box-success choice-box">
            <div class="box-header with-border">
              <h3 class="box-title">
                <i class="fa fa-calendar"></i> Gestisci prenotazioni
              </h3>
            </div>
            <div class="box-body">
              <p>Visualizza e gestisci le tue prenotazioni specialistiche giÃ  effettuate.</p>
              <a class="btn btn-success" href="<?= base_url('prenotazioni/specialisti/gestisci') ?>">
                Vai <i class="fa fa-arrow-right"></i>
              </a>
            </div>
          </div>
        </div>

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

