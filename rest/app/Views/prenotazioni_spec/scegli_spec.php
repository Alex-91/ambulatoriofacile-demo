<?php
$result = session()->get('menuData');
$menu_items = $menu_items ?? ($result['result'] ?? []);
$specs = $specs ?? [];
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title><?= esc('AmbulatoriCLOUD') ?> | Scegli specialista</title>
  <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">

  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />

<style>
  .spec-card {
    background: linear-gradient(135deg, #3c8dbc, #367fa9); /* blu AmbulatoriCLOUD */
    border-radius: 6px;
    padding: 20px 15px;
    margin-bottom: 20px;
    text-align: center;
    transition: transform .2s ease, box-shadow .2s ease;
    color: #fff;
    height: 100%;
  }

  .spec-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 6px 16px rgba(0,0,0,.25);
  }

  .spec-card img {
    max-height: 90px;
    margin-bottom: 15px;
    filter: drop-shadow(0 2px 4px rgba(0,0,0,.4));
  }

  .spec-card h4 {
    font-weight: 700;
    margin-bottom: 8px;
    color: #fff;
  }

  .spec-card p {
    font-size: 13px;
    color: #eaf3fa;
    min-height: 45px;
  }

  .spec-card .btn {
    background: #ffffff;
    color: #3c8dbc;
    border: none;
    font-weight: 600;
  }

  .spec-card .btn:hover {
    background: #e6e6e6;
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
      <h1>Nuova prenotazione specialistica</h1>
      <small>Seleziona la specializzazione</small>
    </section>

    <section class="content">
      <div class="box box-primary">
        <div class="box-body">

          <?php if (empty($specs)): ?>
            <div class="alert alert-warning">
              Nessuna specializzazione disponibile.
            </div>
          <?php else: ?>
            <div class="row">
              <?php foreach ($specs as $s): ?>
                <?php
                  $id    = (int)$s['id_spec'];
                  $tit   = trim((string)($s['titolo'] ?? 'Specialista'));
                  $desc  = trim((string)($s['descr'] ?? ''));
                  $img   = trim((string)($s['icona'] ?? ''));
                  $imgUrl = $img !== ''
                      ? base_url('public/assets/images/' . $img)
                      : base_url('public/assets/images/default-spec.png');
                ?>

                <div class="col-sm-6 col-md-4">
                  <a href="<?= base_url('prenotazioni/specialisti/medici/' . $id) ?>"
                     style="color:inherit; text-decoration:none;">
                    <div class="spec-card">
                      <img src="<?= esc($imgUrl) ?>" alt="<?= esc($tit) ?>">
                      <h4><?= esc($tit) ?></h4>
                      <p><?= esc($desc) ?></p>
                      <span class="btn btn-primary btn-sm">
                        Seleziona
                      </span>
                    </div>
                  </a>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <a class="btn btn-default" href="<?= base_url('prenotazioni/specialisti') ?>">
            <i class="fa fa-arrow-left"></i> Indietro
          </a>

        </div>
      </div>
    </section>
  </div>

  <footer class="main-footer">
    <div class="pull-right hidden-xs"><b>Version</b> 2.0</div>
    <strong>&copy; <?= esc('AmbulatoriCLOUD') ?></strong>
  </footer>

</div>
</body>
</html>

