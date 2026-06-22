<?php
/** @var array $menu_items */
/** @var array $demoScenario */
/** @var array $demoSeedStatus */

helper('portal');

$result = session()->get('menuDataAdmin');
$menu_items = $menu_items ?? ($result['result'] ?? []);
$dottori = [];
$contDott = 0;
$seedSummary = (array)($demoSeedStatus['summary'] ?? []);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Admin</title>
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" type="text/css" />
  <link href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css" rel="stylesheet" type="text/css" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />
  <style>
    .nav-pills.nav-stacked > li.active > a {
      background-color: #2c8895;
      color: #fff;
    }

    .demo-hero {
      background: linear-gradient(135deg, #f8fcfc 0%, #eef7f8 55%, #fef6ef 100%);
      border: 1px solid #d8e8ea;
      border-radius: 18px;
      padding: 24px;
      margin-bottom: 18px;
    }

    .demo-hero h3 {
      margin-top: 0;
      margin-bottom: 8px;
      font-size: 28px;
      font-weight: 700;
      color: #243235;
    }

    .demo-hero p {
      color: #52676c;
      font-size: 15px;
      line-height: 1.6;
    }

    .demo-chip {
      display: inline-block;
      margin: 0 8px 8px 0;
      padding: 8px 12px;
      border-radius: 999px;
      background: #dff1f2;
      color: #156872;
      font-weight: 600;
      font-size: 13px;
    }

    .metric-card {
      background: #fff;
      border: 1px solid #e8eded;
      border-radius: 16px;
      padding: 18px 16px;
      min-height: 120px;
      box-shadow: 0 6px 20px rgba(35, 58, 62, 0.06);
    }

    .metric-value {
      display: block;
      font-size: 34px;
      font-weight: 700;
      color: #176a73;
      line-height: 1;
      margin-bottom: 8px;
    }

    .metric-label {
      font-size: 14px;
      color: #5d6f73;
      text-transform: uppercase;
      letter-spacing: .08em;
    }

    .role-card {
      border: 1px solid #e6ecec;
      border-radius: 14px;
      padding: 16px;
      margin-bottom: 12px;
      background: #fff;
    }

    .role-card h4 {
      margin: 0 0 6px;
      font-size: 18px;
      color: #253436;
    }

    .role-account {
      display: inline-block;
      margin-bottom: 8px;
      padding: 4px 8px;
      border-radius: 8px;
      background: #f3f6f7;
      color: #476067;
      font-family: Consolas, Monaco, monospace;
      font-size: 12px;
    }

    .timeline-list,
    .bullet-list {
      padding-left: 18px;
      margin-bottom: 0;
    }

    .timeline-list li,
    .bullet-list li {
      margin-bottom: 10px;
      line-height: 1.55;
      color: #45585d;
    }

    .status-box {
      border-radius: 14px;
      padding: 16px;
      background: #fbfcfc;
      border: 1px solid #e5eded;
      margin-bottom: 16px;
    }

    .status-box strong {
      color: #223133;
    }

    .cta-row .btn {
      margin: 0 8px 8px 0;
    }
  </style>
</head>
<body class="skin-blue sidebar-mini">
  <div class="wrapper">

    <?= view('partials/header', ['menu_items' => $menu_items ?? []]) ?>

    <aside class="main-sidebar" style="display:none">
      <section class="sidebar"></section>
    </aside>

    <div class="content-wrapper">
      <section class="content-header">
        <h1>Admin <small>Cabina di regia demo</small></h1>
        <ol class="breadcrumb">
          <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
          <li class="active">Admin</li>
        </ol>
      </section>

      <section class="content">
        <div class="row">
          <div class="col-md-3">
            <?= view('partials/sidebar_admin', [
              'menu_items' => $menu_items ?? [],
              'dottori' => $dottori,
              'contDott' => $contDott,
              'selectedDoctorId' => null,
              'result' => $result ?? [],
            ]) ?>
          </div>

          <div class="col-md-9">
            <div class="demo-hero">
              <h3><?= esc((string)($demoScenario['title'] ?? 'Demo pronta')) ?></h3>
              <p><?= esc((string)($demoScenario['subtitle'] ?? '')) ?></p>
              <div>
                <?php foreach ((array)($demoScenario['focus'] ?? []) as $focus): ?>
                  <span class="demo-chip"><?= esc((string)$focus) ?></span>
                <?php endforeach; ?>
              </div>
              <div class="cta-row" style="margin-top:16px;">
                <a href="<?= site_url('demo/access/medical') ?>" class="btn btn-primary">
                  <i class="fa fa-play-circle"></i> Apri accessi demo dietistica
                </a>
                <a href="<?= site_url('admin/personale/modifica_cliente') ?>" class="btn btn-default">
                  <i class="fa fa-users"></i> Clienti demo
                </a>
                <a href="<?= portal_platform_url('spazi-clienti') ?>" class="btn btn-default">
                  <i class="fa fa-sitemap"></i> Console piattaforma
                </a>
                <a href="<?= site_url('admin/personale/dap14') ?>" class="btn btn-default">
                  <i class="fa fa-link"></i> Segreteria e professionisti
                </a>
                <a href="<?= site_url('admin/personale/visibilita-moduli') ?>" class="btn btn-default">
                  <i class="fa fa-toggle-on"></i> Visibilita moduli
                </a>
              </div>
            </div>

            <div class="row">
              <?php foreach ((array)($demoScenario['metrics'] ?? []) as $metric): ?>
                <div class="col-sm-6 col-lg-3">
                  <div class="metric-card">
                    <span class="metric-value"><?= esc((string)($metric['value'] ?? '0')) ?></span>
                    <span class="metric-label"><?= esc((string)($metric['label'] ?? 'metrica')) ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="row" style="margin-top:18px;">
              <div class="col-md-6">
                <div class="box box-primary">
                  <div class="box-header with-border">
                    <h3 class="box-title">Ruoli da mostrare</h3>
                  </div>
                  <div class="box-body">
                    <?php foreach ((array)($demoScenario['roles'] ?? []) as $role): ?>
                      <div class="role-card">
                        <h4><?= esc((string)($role['title'] ?? 'Ruolo')) ?></h4>
                        <div class="role-account"><?= esc((string)($role['account'] ?? '')) ?></div>
                        <div><?= esc((string)($role['goal'] ?? '')) ?></div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>

              <div class="col-md-6">
                <div class="box box-primary">
                  <div class="box-header with-border">
                    <h3 class="box-title">Scaletta consigliata</h3>
                  </div>
                  <div class="box-body">
                    <ol class="timeline-list">
                      <?php foreach ((array)($demoScenario['timeline'] ?? []) as $step): ?>
                        <li><?= esc((string)$step) ?></li>
                      <?php endforeach; ?>
                    </ol>
                  </div>
                </div>
              </div>
            </div>

            <div class="row">
              <div class="col-md-6">
                <div class="box box-primary">
                  <div class="box-header with-border">
                    <h3 class="box-title">Messaggi chiave da dire</h3>
                  </div>
                  <div class="box-body">
                    <ul class="bullet-list">
                      <?php foreach ((array)($demoScenario['talkingPoints'] ?? []) as $point): ?>
                        <li><?= esc((string)$point) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                </div>
              </div>

              <div class="col-md-6">
                <div class="box box-primary">
                  <div class="box-header with-border">
                    <h3 class="box-title">Stato tecnico della demo</h3>
                  </div>
                  <div class="box-body">
                    <div class="status-box">
                      <div><strong>Database demo:</strong> <?= esc((string)($demoSeedStatus['database'] ?? 'non disponibile')) ?></div>
                      <div><strong>Ultimo seed:</strong> <?= esc((string)($demoSeedStatus['finished_at'] ?: 'non disponibile')) ?></div>
                      <div><strong>Password comune:</strong> <?= esc((string)($demoSeedStatus['password'] ?? 'Demo2026')) ?></div>
                    </div>

                    <div class="status-box">
                      <div><strong>Clienti demo:</strong> <?= esc((string)($seedSummary['clients_demo'] ?? 0)) ?></div>
                      <div><strong>Appuntamenti demo:</strong> <?= esc((string)($seedSummary['agenda_appointments'] ?? 0)) ?></div>
                      <div><strong>Messaggi interni:</strong> <?= esc((string)(((int)($seedSummary['chat_messages'] ?? 0)) + ((int)($seedSummary['posta_messages'] ?? 0)))) ?></div>
                    </div>

                    <p class="text-muted" style="margin:0;">
                      Questa schermata e pensata come promemoria operativo per la tua presentazione: dati finti, ruoli pronti e flusso locale separato.
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>

    <footer class="main-footer">
      <div class="pull-right hidden-xs">
        <b>Version</b> 2.0
      </div>
      <strong>&copy; AmbulatorioFacile</strong>
    </footer>

    <aside class="control-sidebar control-sidebar-dark"></aside>
    <div class="control-sidebar-bg"></div>
  </div>

  <script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
  <script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>" type="text/javascript"></script>
  <script src="<?= base_url('public/plugins/slimScroll/jquery.slimscroll.min.js') ?>" type="text/javascript"></script>
  <script src="<?= base_url('public/plugins/fastclick/fastclick.min.js') ?>"></script>
  <script src="<?= base_url('public/dist/js/app.min.js') ?>" type="text/javascript"></script>
</body>
</html>
