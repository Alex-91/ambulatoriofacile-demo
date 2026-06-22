<?php
$result = session()->get('menuData');
$menu_items = $menu_items ?? ($result['result'] ?? []);

$slots     = $slots ?? [];
$from      = $from ?? date('Y-m-d');
$existing  = $existing ?? null;

$doctor_user = $doctor_user ?? null;
$id_medico   = (int)($id_medico ?? 0);

$msgOk  = session()->getFlashdata('message_success');
$msgErr = session()->getFlashdata('message_error');
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title><?= esc('AmbulatorioFacile') ?> | Nuova prenotazione MMG</title>
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">

  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" type="text/css" />
  <link href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css" rel="stylesheet" type="text/css" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />

  <style>
    .choice-box { border-radius: 8px; }
    .slot-row { display:flex; gap:12px; align-items:center; justify-content:space-between; flex-wrap:wrap; }
    .slot-time { font-size:16px; font-weight:600; }
    .slot-sub  { color:#777; font-size:12px; margin-top:2px; }
    .btn-row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .box-header .box-title small { font-weight: normal; opacity:.9; }
    .note-mini { max-width:260px; }
  </style>
</head>

<body class="skin-blue sidebar-mini">
<div class="wrapper">

  <?= view('partials/header', ['menu_items' => $menu_items ?? []]) ?>
  <aside class="main-sidebar" style="display:none"><section class="sidebar"></section></aside>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Nuova prenotazione MMG</h1>
      <small>Seleziona uno slot disponibile</small>

      <ol class="breadcrumb">
        <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
        <li><a href="<?= site_url('prenotazioni/mmg') ?>">Prenotazione MMG</a></li>
        <li class="active">Nuova</li>
      </ol>
    </section>

    <section class="content">

      <?php if ($msgOk): ?><div class="alert alert-success"><?= esc($msgOk) ?></div><?php endif; ?>
      <?php if ($msgErr): ?><div class="alert alert-danger"><?= esc($msgErr) ?></div><?php endif; ?>

      <?php if (!empty($existing)): ?>
        <div class="alert alert-warning">
          <b>Hai giÃ  una prenotazione futura.</b><br>
          <?= esc(($existing['data_ora_ini'] ?? '')) ?> - <?= esc(($existing['data_ora_fin'] ?? '')) ?>
          (<?= esc(($existing['cognome_med'] ?? '')) ?> <?= esc(($existing['nome_med'] ?? '')) ?>)
          <br><br>
          <a class="btn btn-primary btn-sm" href="<?= site_url('prenotazioni/mmg/gestisci') ?>">
            Vai a Gestisci prenotazioni <i class="fa fa-arrow-right"></i>
          </a>
        </div>
      <?php endif; ?>

      <div class="row">
        <div class="col-md-12">

          <!-- BOX RICERCA -->
          <div class="box box-primary choice-box">
            <div class="box-header with-border">
              <h3 class="box-title">
                <i class="fa fa-calendar"></i> Cerca disponibilitÃ 
                <small>&nbsp;| scegli da che data partire</small>
              </h3>
            </div>

            <div class="box-body">
              <form class="form-inline" method="get" action="<?= site_url('prenotazioni/mmg/nuova') ?>">
                <div class="form-group">
                  <label style="margin-right:8px;" for="from">Da:</label>
                  <input type="date" class="form-control" id="from" name="from"
                         value="<?= esc($from ?? '') ?>" style="min-width:220px;">
                </div>

                <button type="submit" class="btn btn-default" style="margin-left:8px;" <?= !empty($existing) ? 'disabled' : '' ?>>
                  <i class="fa fa-search"></i> Cerca
                </button>
              </form>

              <hr>

              <?php if (empty($doctor_user) || empty($id_medico)): ?>
                <div class="alert alert-danger" style="margin-bottom:0;">
                  Impossibile determinare il medico assegnato o non trovato nell'<b>archivio prenotazioni</b>.
                  (doctor_user=<?= esc($doctor_user ?? '') ?>, id_medico=<?= esc($id_medico ?? 0) ?>)
                </div>
              <?php endif; ?>
            </div>

            <?php if (empty($existing)): ?>
              <div class="box-body" style="padding-top:0;">

                <?php if (empty($slots)): ?>
                  <div class="alert alert-info" style="margin-bottom:0;">
                    Nessuno slot disponibile per la data selezionata.
                  </div>
                <?php else: ?>

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

                  <div class="row">
                    <?php foreach ($slots as $s): ?>
                      <?php
                        $slotDt = (string)($s['data_ora_ini'] ?? '');
                        if ($slotDt === '' && !empty($s['giorno']) && !empty($s['ora'])) {
                          $slotDt = $s['giorno'] . ' ' . $s['ora'] . ':00';
                        }

                        $labelDate = $slotDt;
                        $labelDay  = '';
                        if ($slotDt !== '' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $slotDt)) {
                          $labelDate = date('d/m/Y H:i', strtotime($slotDt));
                          $dayEn     = date('l', strtotime($slotDt));
                          $dayIt     = $giorniIt[$dayEn] ?? $dayEn;
                          $labelDay  = $dayIt . ' ' . date('d/m/Y', strtotime($slotDt));
                        }

                        $disabled = ($slotDt === '' || $id_medico <= 0);
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

                                <!-- âœ… FORM PRENOTA con NOTE -->
                                <form method="post" action="<?= site_url('prenotazioni/mmg/prenota') ?>"
                                      style="margin:0; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                                  <?= csrf_field() ?>
                                  <input type="hidden" name="id_medico" value="<?= (int)$id_medico ?>">
                                  <input type="hidden" name="slot" value="<?= esc($slotDt) ?>">

                                  <input type="text" class="form-control input-sm note-mini" name="note"
                                         placeholder="Note (opzionale)">
                                  <button type="submit" class="btn btn-success btn-sm" <?= $disabled ? 'disabled' : '' ?>>
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
            <?php endif; ?>

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

