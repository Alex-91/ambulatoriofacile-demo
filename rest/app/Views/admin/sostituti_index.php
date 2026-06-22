<?php
if (empty($menu_items) || !is_array($menu_items)) {
  $menu_items = session()->get('header_menu_items') ?? [];
}
$errors  = $errors ?? [];
$success = $success ?? null;
$medici  = $medici ?? [];
$rows    = $rows ?? [];
?>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Sostituti</title>
  <meta content='width=device-width, initial-scale=1' name='viewport'>
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
</head>

<body class="skin-blue sidebar-mini">
<div class="wrapper">

  <?= view('partials/header', ['menu_items' => $menu_items]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Gestione Sostituti</h1>
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

          <div class="box box-primary">
            <div class="box-header with-border">
              <h3 class="box-title">Nuova sostituzione</h3>
            </div>

            <form method="post" action="<?= site_url('admin/sostituti/salva') ?>">
              <?= csrf_field() ?>
              <div class="box-body">

                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Medico da sostituire *</label>
                      <select class="form-control" name="id_personale_da_sostituire" required>
                        <option value="">Seleziona...</option>
                        <?php foreach ($medici as $m): ?>
                          <option value="<?= esc($m['id_personale']) ?>"><?= esc($m['nominativo']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>

                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Sostituto *</label>
                      <select class="form-control" name="id_personale" required>
                        <option value="">Seleziona...</option>
                        <?php foreach ($medici as $m): ?>
                          <option value="<?= esc($m['id_personale']) ?>"><?= esc($m['nominativo']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-3">
                    <div class="form-group">
                      <label>Data inizio *</label>
                      <input type="date" class="form-control" name="data_inizio" required>
                    </div>
                  </div>
                  <div class="col-md-3">
                    <div class="form-group">
                      <label>Data fine *</label>
                      <input type="date" class="form-control" name="data_fine" required>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <p class="text-muted" style="margin-top:25px;">
                      Le date sono obbligatorie. La fine deve essere >= della data inizio.
                      Sono ammessi piu sostituti contemporanei per lo stesso medico.
                      Il sistema blocca solo duplicati sovrapposti dello stesso sostituto.
                    </p>
                  </div>
                </div>

              </div>

              <div class="box-footer">
                <button type="submit" class="btn btn-success">
                  <i class="fa fa-plus"></i> Inserisci
                </button>
              </div>
            </form>
          </div>

          <div class="box box-success">
            <div class="box-header with-border">
              <h3 class="box-title">Sostituzioni inserite</h3>
            </div>

            <div class="box-body table-responsive">
              <table class="table table-bordered table-hover">
                <thead>
                  <tr>
                    <th>Medico da sostituire</th>
                    <th>Sostituto</th>
                    <th>Dal</th>
                    <th>Al</th>
                    <th style="width:110px;">Azioni</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($rows)): ?>
                    <tr><td colspan="5" class="text-muted">Nessuna sostituzione.</td></tr>
                  <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                      <tr>
                        <td><?= esc($r['medico_da_sostituire']) ?></td>
                        <td><?= esc($r['sostituto']) ?></td>
                        <td><?= esc($r['data_inizio']) ?></td>
                        <td><?= esc($r['data_fine']) ?></td>
                        <td>
                          <form method="post" action="<?= site_url('admin/sostituti/elimina/' . (int)$r['id_sost']) ?>" onsubmit="return confirm('Eliminare questa sostituzione?');" style="display:inline;">
                            <?= csrf_field() ?>
                            <button class="btn btn-danger btn-xs" type="submit">
                              <i class="fa fa-trash"></i> Elimina
                            </button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
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
</body>
</html>
