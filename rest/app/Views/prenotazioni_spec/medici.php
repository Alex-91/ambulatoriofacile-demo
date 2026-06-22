<?php
$result = session()->get('menuData');
$menu_items = $menu_items ?? ($result['result'] ?? []);
$medici = $medici ?? [];
$id_spec = (int)($id_spec ?? 0);
$spec_name = trim((string)($spec_name ?? ''));
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title><?= esc('AmbulatorioFacile') ?> | Seleziona medico</title>
  <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">

  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />

  <style>
    /* ===== MOBILE FRIENDLY TABLE ===== */
    @media (max-width: 768px) {

      table,
      thead,
      tbody,
      th,
      td,
      tr {
        display: block;
        width: 100%;
      }

      thead {
        display: none;
      }

      tbody tr {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 6px;
        margin-bottom: 15px;
        padding: 10px;
      }

      tbody td {
        border: none;
        padding: 6px 0;
      }

      tbody td::before {
        content: attr(data-label);
        font-weight: bold;
        display: block;
        color: #555;
        margin-bottom: 2px;
      }

      tbody td:last-child {
        margin-top: 10px;
      }
    }
  </style>
</head>

<body class="skin-blue sidebar-mini">
<div class="wrapper">

  <?= view('partials/header') ?>
  <aside class="main-sidebar" style="display:none">
    <section class="sidebar"></section>
  </aside>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Seleziona medico</h1>
    <small>Specializzazione: <?= esc($spec_name ?: ('Specializzazione #' . $id_spec)) ?></small>
    </section>

    <section class="content">
      <div class="box box-primary">
        <div class="box-body">

          <?php if (empty($medici)): ?>
            <div class="alert alert-warning">
              Nessun medico disponibile per questa specializzazione.
            </div>
          <?php else: ?>

            <table class="table table-bordered table-hover">
              <thead>
                <tr>
                  <th>Medico</th>
                  <th>Contatti</th>
                  <th>Studio</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>

              <?php foreach ($medici as $m): ?>
                <?php
                  $idDot = (int)($m['id_dot'] ?? 0);
                  $nome  = trim((string)($m['nome'] ?? ''));
                  $cogn  = trim((string)($m['cognome'] ?? ''));
                  $tit   = trim((string)($m['titolo'] ?? ''));
                  $tel   = trim((string)($m['tel_spec'] ?? $m['tel_dot'] ?? ''));
                  $mail  = trim((string)($m['email'] ?? ''));
                  $addr  = trim((string)($m['indirizzo'] ?? ''));
                  $cap   = trim((string)($m['cap'] ?? ''));
                  $cit   = trim((string)($m['citta'] ?? ''));
                ?>

                <tr>
                  <td data-label="Medico">
                    <?= esc(trim($tit . ' ' . $nome . ' ' . $cogn)) ?>
                  </td>

                  <td data-label="Contatti">
                    <?php if ($tel): ?>
                      <div><i class="fa fa-phone"></i> <?= esc($tel) ?></div>
                    <?php endif; ?>
                    <?php if ($mail): ?>
                      <div><i class="fa fa-envelope"></i> <?= esc($mail) ?></div>
                    <?php endif; ?>
                  </td>

                  <td data-label="Studio">
                    <?= esc(trim($addr . ' ' . $cap . ' ' . $cit)) ?>
                  </td>

                  <td data-label="Azioni">
                    <a class="btn btn-success btn-sm btn-block"
                       href="<?= base_url('prenotazioni/specialisti/slot/' . $idDot) ?>">
                      <i class="fa fa-calendar"></i> Vedi slot
                    </a>
                  </td>
                </tr>

              <?php endforeach; ?>
              </tbody>
            </table>

          <?php endif; ?>

          <a class="btn btn-default" href="<?= base_url('prenotazioni/specialisti/nuova') ?>">
            <i class="fa fa-arrow-left"></i> Indietro
          </a>

        </div>
      </div>
    </section>
  </div>

  <footer class="main-footer">
    <div class="pull-right hidden-xs"><b>Version</b> 2.0</div>
    <strong>&copy; <?= esc('AmbulatorioFacile') ?></strong>
  </footer>

</div>
</body>
</html>

