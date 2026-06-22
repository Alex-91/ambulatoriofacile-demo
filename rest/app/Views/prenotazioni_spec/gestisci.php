<?php
$result = session()->get('menuData');
$menu_items = $menu_items ?? ($result['result'] ?? []);

// =======================
// Helper formato data IT
// =======================
function formatDataOraIt(?string $datetime): string
{
  if (empty($datetime)) return '';

  try {
    $dt = new DateTime($datetime);

    $mesi = [
      1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
      5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
      9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre'
    ];

    $giorno = (int)$dt->format('d');
    $mese   = $mesi[(int)$dt->format('m')] ?? $dt->format('m');
    $anno   = $dt->format('Y');
    $ora    = $dt->format('H:i');

    return "{$giorno} {$mese} {$anno} alle {$ora}";
  } catch (\Throwable $e) {
    return $datetime;
  }
}
?>
<!DOCTYPE html>
<html>
  <head>
    <meta charset="UTF-8">
    <title><?= esc('AmbulatorioFacile') ?> | Gestisci prenotazioni Specialistiche</title>
    <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
    <meta content='width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no' name='viewport'>

    <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />
  </head>

  <body class="skin-blue sidebar-mini">
    <div class="wrapper">
      <?= view('partials/header', ['menu_items' => $menu_items ?? []]) ?>
      <aside class="main-sidebar" style="display:none"><section class="sidebar"></section></aside>

      <div class="content-wrapper">
        <section class="content-header">
          <h1>Gestisci prenotazioni Specialistiche</h1>
          <ol class="breadcrumb">
            <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
            <li><a href="<?= site_url('prenotazioni/specialisti') ?>">Prenotazioni Specialistiche</a></li>
            <li class="active">Gestisci</li>
          </ol>
        </section>

        <section class="content">
          <?php if ($msg = session()->getFlashdata('message_success')): ?>
            <div class="alert alert-success"><?= esc($msg) ?></div>
          <?php endif; ?>

          <?php if ($msg = session()->getFlashdata('message_error')): ?>
            <div class="alert alert-danger"><?= esc($msg) ?></div>
          <?php endif; ?>

          <div class="box box-primary">
            <div class="box-header with-border">
              <h3 class="box-title"><i class="fa fa-list"></i> Prenotazione attiva</h3>
            </div>

            <div class="box-body">

              <?php if (empty($existing)): ?>
                <div class="alert alert-info">
                  Nessuna prenotazione futura trovata.
                  <a href="<?= site_url('prenotazioni/specialisti/nuova') ?>" class="btn btn-success btn-sm" style="margin-left:8px;">
                    Nuova prenotazione <i class="fa fa-arrow-right"></i>
                  </a>
                </div>
              <?php else: ?>

                <p>
                  <b>Specialista:</b>
                  <?= esc(($existing['titolo'] ?? '')) ?>
                  <?= esc(($existing['cognome_med'] ?? ($existing['cognome'] ?? '')) ) ?>
                  <?= esc(($existing['nome_med'] ?? ($existing['nome'] ?? '')) ) ?>
                  <br>

                  <?php if (!empty($existing['specializzazione'])): ?>
                    <b>Specializzazione:</b> <?= esc($existing['specializzazione']) ?><br>
                  <?php endif; ?>

                  <b>Quando:</b>
                  <?= esc(formatDataOraIt($existing['data_ora_ini'] ?? ($existing['slot_ini'] ?? ''))) ?>
                </p>

                <hr>

                <form method="post"
                      action="<?= site_url('prenotazioni/specialisti/cancella') ?>"
                      style="display:inline;"
                      onsubmit="return confirm('Confermi la cancellazione della prenotazione?');">
                  <?= csrf_field() ?>

                  <input type="hidden" name="id_prenotazione"
                         value="<?= (int)($existing['id_prenotazione'] ?? 0) ?>">

                  <button class="btn btn-danger btn-sm" type="submit">
                    Cancella prenotazione <i class="fa fa-trash"></i>
                  </button>
                </form>

              <?php endif; ?>

            </div>
          </div>

          <a class="btn btn-default" href="<?= site_url('prenotazioni/specialisti') ?>">
            <i class="fa fa-arrow-left"></i> Indietro
          </a>

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

