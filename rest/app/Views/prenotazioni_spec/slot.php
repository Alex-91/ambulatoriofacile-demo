<?php
$result = session()->get('menuData');
$menu_items = $menu_items ?? ($result['result'] ?? []);

$slots    = $slots ?? [];
$from     = $from ?? date('Y-m-d');
$id_dot   = (int)($id_dot ?? 0);       // compatibilitÃ 
$id_medico = (int)($id_medico ?? 0);   // se il controller passa id_medico
$medico   = $medico ?? null;
$existing = $existing ?? null;

$msgOk  = session()->getFlashdata('message_success');
$msgErr = session()->getFlashdata('message_error');

// id medico definitivo da usare nei form
$idMedicoHidden = (int)($id_medico ?: $id_dot);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title><?= esc('AmbulatorioFacile') ?> | Slot specialista</title>
  <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">

  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />

  <style>
    .choice-box { border-radius: 8px; }
    .slot-row { display:flex; gap:12px; align-items:center; justify-content:space-between; flex-wrap:wrap; }
    .slot-time { font-size:16px; font-weight:600; }
    .slot-sub  { color:#777; font-size:12px; margin-top:2px; }
    .note-mini { max-width: 260px; }
    .btn-row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .box-header .box-title small { font-weight: normal; opacity:.9; }
  </style>
</head>

<body class="skin-blue sidebar-mini">
<div class="wrapper">

  <?= view('partials/header', ['menu_items' => $menu_items ?? []]) ?>
  <aside class="main-sidebar" style="display:none"><section class="sidebar"></section></aside>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Seleziona uno slot</h1>

      <?php if ($medico): ?>
        <?php
          $specTit = trim((string)($medico['titolo'] ?? ''));
          $nome    = trim((string)($medico['nome'] ?? ''));
          $cognome = trim((string)($medico['cognome'] ?? ''));
          $full    = trim($specTit . ' ' . $nome . ' ' . $cognome);
        ?>
        <small><?= esc($full) ?></small>
      <?php else: ?>
        <small>Seleziona data e orario disponibili</small>
      <?php endif; ?>

      <ol class="breadcrumb">
        <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
        <li class="active">Slot specialista</li>
      </ol>
    </section>

    <section class="content">

      <?php if ($msgOk): ?><div class="alert alert-success"><?= esc($msgOk) ?></div><?php endif; ?>
      <?php if ($msgErr): ?><div class="alert alert-danger"><?= esc($msgErr) ?></div><?php endif; ?>

      <?php if ($existing): ?>
        <div class="alert alert-warning">
          Hai giÃ  una prenotazione futura. Se vuoi, annulla prima quella esistente.
        </div>
      <?php endif; ?>

      <div class="row">
        <div class="col-md-12">

          <div class="box box-primary choice-box">
            <div class="box-header with-border">
              <h3 class="box-title">
                <i class="fa fa-calendar"></i> Cerca disponibilitÃ 
                <small>&nbsp;| scegli da che data partire</small>
              </h3>
            </div>
            <div class="box-body">

              <form class="form-inline" method="get" action="">
                <div class="form-group">
                  <label style="margin-right:8px;">Da:</label>
                  <input type="date" class="form-control" name="from" value="<?= esc($from) ?>">
                </div>
                <button class="btn btn-default" type="submit" style="margin-left:8px;">
                  <i class="fa fa-search"></i> Cerca
                </button>
              </form>

              <hr>

              <?php if (empty($slots)): ?>
                <div class="alert alert-info" style="margin-bottom:0;">
                  Nessuno slot disponibile per la data selezionata.
                </div>
              <?php else: ?>

                <div class="row">
                    <?php
$giorniIt = [
  'Sunday'    => 'Domenica',
  'Monday'    => 'LunedÃ¬',
  'Tuesday'   => 'MartedÃ¬',
  'Wednesday' => 'MercoledÃ¬',
  'Thursday'  => 'GiovedÃ¬',
  'Friday'    => 'VenerdÃ¬',
  'Saturday'  => 'Sabato',
];
?>
                  <?php foreach ($slots as $s): ?>
                    <?php
                      // âœ… supporta nuovo formato (data_ora_ini) + compatibilitÃ 
                      $slotIni = $s['data_ora_ini']
                        ?? ($s['slot_ini'] ?? ($s['inizio'] ?? ''));

                      // fallback: se esistono giorno+ora
                      if ($slotIni === '' && !empty($s['giorno']) && !empty($s['ora'])) {
                        $slotIni = $s['giorno'] . ' ' . $s['ora'] . ':00';
                      }

                      // label leggibile
                      $labelDate = $slotIni;
                      $labelDay  = '';
                      if ($slotIni !== '' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $slotIni)) {
                        $labelDate = date('d/m/Y H:i', strtotime($slotIni));
                       $dayEn   = date('l', strtotime($slotIni));
                        $dayIt   = $giorniIt[$dayEn] ?? $dayEn;
                        $labelDay = $dayIt . ' ' . date('d/m/Y', strtotime($slotIni));
                      }

                      // se qualcosa manca, disabilito
                      $disabled = ($slotIni === '' || $idMedicoHidden <= 0);
                    ?>
                    <div class="col-md-6">
                      <div class="box box-success choice-box">
                        <div class="box-header with-border">
                          <h3 class="box-title">
                            <i class="fa fa-clock-o"></i>
                            <span class="slot-time"><?= esc($labelDate) ?></span>
                          </h3>
                        </div>

                        <div class="box-body">
                          <?php if ($labelDay): ?>
                            <div class="slot-sub"><?= esc($labelDay) ?></div>
                            <div style="height:8px;"></div>
                          <?php endif; ?>

                          <div class="slot-row">
                            <div class="btn-row">
                              <form method="post" action="<?= base_url('prenotazioni/specialisti/prenota') ?>" style="margin:0; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id_medico" value="<?= (int)$idMedicoHidden ?>">
                                <input type="hidden" name="slot" value="<?= esc($slotIni) ?>">

                                <input type="text" class="form-control input-sm note-mini" name="note" placeholder="Note (opzionale)">
                                <button class="btn btn-success btn-sm" type="submit" <?= $disabled ? 'disabled' : '' ?>>
                                  <i class="fa fa-check"></i> Prenota
                                </button>
                              </form>
                            </div>
                          </div>

                          <?php if ($disabled): ?>
                            <div class="text-danger" style="margin-top:10px;">
                              Impossibile prenotare: dati mancanti (id medico o slot).
                            </div>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>

              <?php endif; ?>

            </div>

            <div class="box-footer">
              <a class="btn btn-default" href="<?= base_url('prenotazioni/specialisti/nuova') ?>">
                <i class="fa fa-arrow-left"></i> Indietro
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

</div>

<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>" type="text/javascript"></script>
<script src="<?= base_url('public/dist/js/app.min.js') ?>" type="text/javascript"></script>
</body>
</html>

