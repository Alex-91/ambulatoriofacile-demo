<?php
if (empty($menu_items) || !is_array($menu_items)) {
    $menu_items = session()->get('header_menu_items') ?? [];
}

$errors = $errors ?? [];
$success = $success ?? null;
$loadError = $loadError ?? null;
$nurses = $nurses ?? [];
$selectedNurseId = (int)($selectedNurseId ?? 0);
$selectedNurse = $selectedNurse ?? null;
$doctorRows = $doctorRows ?? [];
$doctorsWithoutLocation = $doctorsWithoutLocation ?? [];
$selectedCount = (int)($selectedCount ?? 0);

$totalDoctors = count($doctorRows);
$withLocationCount = $totalDoctors - count($doctorsWithoutLocation);
$missingLocationNames = array_map(static fn(array $row): string => (string)($row['label'] ?? ''), $doctorsWithoutLocation);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Infermieri e medici</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <style>
    .summary-box {
      border: 1px solid #e5e5e5;
      border-radius: 6px;
      padding: 12px 14px;
      background: #fff;
      margin-bottom: 12px;
      min-height: 96px;
    }
    .summary-box .value {
      font-size: 24px;
      font-weight: 700;
      line-height: 1.2;
    }
    .table-dap15 th,
    .table-dap15 td {
      vertical-align: middle !important;
    }
    .row-no-location td {
      background: #fcf8e3;
    }
    .toolbar-buttons .btn {
      margin-right: 6px;
      margin-bottom: 6px;
    }
  </style>
</head>

<body class="skin-blue sidebar-mini">
<div class="wrapper">

  <?= view('partials/header', ['menu_items' => $menu_items]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Infermieri e medici</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Gestisci quali medici sono visibili a ogni infermiere. Riferimento tecnico: <code>dap15_inf_dot</code>.
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
          <?php if ($loadError): ?>
            <div class="alert alert-danger"><?= esc($loadError) ?></div>
          <?php endif; ?>

            <div class="box box-primary">
              <div class="box-header with-border">
              <h3 class="box-title">Scegli l'infermiere</h3>
              </div>
            <div class="box-body">
              <form method="get" action="<?= site_url('admin/personale/dap15') ?>" class="form-inline">
                <div class="form-group" style="min-width:320px; max-width:100%; width:100%;">
                  <label for="id_infermiera" class="sr-only">Infermiera</label>
                  <select class="form-control" id="id_infermiera" name="id_infermiera" style="width:100%;">
                    <option value="">Seleziona...</option>
                    <?php foreach ($nurses as $nurse): ?>
                      <option value="<?= (int)$nurse['id_personale'] ?>" <?= ((int)$nurse['id_personale'] === $selectedNurseId) ? 'selected' : '' ?>>
                        <?= esc($nurse['label']) ?><?= !empty($nurse['username']) ? ' - ' . esc($nurse['username']) : '' ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <button class="btn btn-primary" type="submit" style="margin-top:10px;">
                  <i class="fa fa-refresh"></i> Apri elenco
                </button>
              </form>
            </div>
          </div>

          <?php if ($selectedNurse): ?>
            <div class="alert alert-info">
              <strong><?= esc($selectedNurse['label']) ?></strong>
              <?php if (!empty($selectedNurse['username'])): ?>
                <span> | Username: <?= esc($selectedNurse['username']) ?></span>
              <?php endif; ?>
              <span> | ID personale: <?= (int)$selectedNurse['id_personale'] ?></span>
            </div>

            <div class="row">
              <div class="col-md-4">
                <div class="summary-box">
                  <div class="text-muted">Medici disponibili</div>
                  <div class="value"><?= $totalDoctors ?></div>
                  <div class="text-muted">Elenco completo da gestire</div>
                </div>
              </div>
              <div class="col-md-4">
                <div class="summary-box">
                  <div class="text-muted">Con sede configurata</div>
                  <div class="value"><?= $withLocationCount ?></div>
                  <div class="text-muted">Pronti da associare</div>
                </div>
              </div>
              <div class="col-md-4">
                <div class="summary-box">
                  <div class="text-muted">Gia collegati</div>
                  <div class="value" id="selectedCounter"><?= $selectedCount ?></div>
                  <div class="text-muted">Medici gia selezionati</div>
                </div>
              </div>
            </div>

            <?php if (!empty($doctorsWithoutLocation)): ?>
              <div class="alert alert-warning">
                <strong>Medici senza sede configurata:</strong> <?= count($doctorsWithoutLocation) ?>.
                <?= esc(implode(', ', $missingLocationNames)) ?>
              </div>
            <?php endif; ?>

            <div class="box box-success">
              <div class="box-header with-border">
                <h3 class="box-title">Medici collegati all'infermiere</h3>
              </div>

              <form method="post" action="<?= site_url('admin/personale/dap15/update') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id_infermiera" value="<?= (int)$selectedNurse['id_personale'] ?>">

                <div class="box-body">
                  <p class="text-muted" style="margin-bottom:14px;">
                    I medici gia presenti nell'abbinamento <code>dap15_inf_dot</code> risultano gia selezionati. Puoi aggiungere o togliere i collegamenti e poi salvare.
                  </p>

                  <div class="toolbar-buttons">
                    <button class="btn btn-default" type="button" id="btnSelectAll">
                      <i class="fa fa-check-square-o"></i> Seleziona tutti
                    </button>
                    <button class="btn btn-default" type="button" id="btnSelectWithLocation">
                      <i class="fa fa-building-o"></i> Solo con sede
                    </button>
                    <button class="btn btn-default" type="button" id="btnClearAll">
                      <i class="fa fa-square-o"></i> Azzera selezione
                    </button>
                  </div>

                  <div class="table-responsive">
                    <table class="table table-bordered table-striped table-dap15">
                      <thead>
                        <tr>
                          <th style="width:70px;">Attivo</th>
                          <th>Medico</th>
                          <th style="width:220px;">Qualifica</th>
                          <th style="width:240px;">Sede</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($doctorRows as $row): ?>
                          <tr class="<?= !empty($row['missing_location']) ? 'row-no-location' : '' ?>">
                            <td class="text-center">
                              <input
                                type="checkbox"
                                class="doctor-checkbox"
                                name="doctor_ids[]"
                                value="<?= (int)$row['id_personale'] ?>"
                                <?= !empty($row['selected']) ? 'checked' : '' ?>
                                data-missing-location="<?= !empty($row['missing_location']) ? '1' : '0' ?>"
                              >
                            </td>
                            <td>
                              <strong><?= esc($row['label']) ?></strong>
                              <div class="text-muted">ID personale: <?= (int)$row['id_personale'] ?></div>
                            </td>
                            <td>
                              <?= esc($row['qualifica'] !== '' ? $row['qualifica'] : '-') ?>
                              <?php if (!empty($row['sostituto'])): ?>
                                <span class="label label-default" style="margin-left:6px;">Sostituto</span>
                              <?php endif; ?>
                              <?php if (!empty($row['titolare'])): ?>
                                <span class="label label-primary" style="margin-left:6px;">Titolare</span>
                              <?php endif; ?>
                            </td>
                            <td>
                              <?php if (!empty($row['missing_location'])): ?>
                                <span class="label label-warning">Senza sede assegnata</span>
                              <?php else: ?>
                                <?= esc($row['sede_nome']) ?>
                                <div class="text-muted">ID gruppo: <?= (int)$row['luogo'] ?></div>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>

                <div class="box-footer">
                  <button class="btn btn-success" type="submit">
                    <i class="fa fa-save"></i> Salva collegamenti
                  </button>
                </div>
              </form>
            </div>
          <?php elseif ($selectedNurseId <= 0): ?>
            <div class="alert alert-info">
              Seleziona un infermiere per vedere quali medici puo gestire e quali risultano gia collegati.
            </div>
          <?php endif; ?>
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
  var $checks = $('.doctor-checkbox');
  if (!$checks.length) {
    return;
  }

  function updateCounter() {
    $('#selectedCounter').text($checks.filter(':checked').length);
  }

  $('#btnSelectAll').on('click', function(){
    $checks.prop('checked', true);
    updateCounter();
  });

  $('#btnSelectWithLocation').on('click', function(){
    $checks.each(function(){
      var isMissing = String($(this).data('missing-location')) === '1';
      $(this).prop('checked', !isMissing);
    });
    updateCounter();
  });

  $('#btnClearAll').on('click', function(){
    $checks.prop('checked', false);
    updateCounter();
  });

  $checks.on('change', updateCounter);
  updateCounter();
})();
</script>
</body>
</html>
