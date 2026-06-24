<?php
/** @var array $menu_items */
/** @var \App\Libraries\TenantContext $tenantContext */
/** @var array $tenant */
/** @var array $capacity */
/** @var array $shortcutActions */
/** @var array $checklist */
/** @var array $dashboardStats */
/** @var array $completion */
/** @var bool $requiresLocationSetup */

$result = session()->get('menuDataAdmin');
$menu_items = $menu_items ?? ($result['result'] ?? []);
$tenant = is_array($tenant ?? null) ? $tenant : [];
$capacity = is_array($capacity ?? null) ? $capacity : [];
$shortcutActions = is_array($shortcutActions ?? null) ? $shortcutActions : [];
$checklist = is_array($checklist ?? null) ? $checklist : [];
$dashboardStats = is_array($dashboardStats ?? null) ? $dashboardStats : [];
$completion = is_array($completion ?? null) ? $completion : ['completed' => 0, 'total' => 0, 'percent' => 0];
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Dashboard spazio</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <style>
    .nav-pills.nav-stacked > li.active > a {
      background-color: #2c8895;
      color: #fff;
    }
    .tenant-hero {
      border: 1px solid #d7e8ea;
      border-radius: 18px;
      padding: 24px;
      background: linear-gradient(135deg, #f8fcfc 0%, #eef7f8 60%, #fff6ed 100%);
      margin-bottom: 18px;
    }
    .tenant-hero h3 {
      margin: 0 0 8px;
      font-size: 30px;
      font-weight: 700;
      color: #203033;
    }
    .tenant-hero p {
      color: #54686d;
      font-size: 15px;
      line-height: 1.65;
      margin-bottom: 12px;
    }
    .tenant-badge {
      display: inline-block;
      margin: 0 8px 8px 0;
      padding: 8px 12px;
      border-radius: 999px;
      background: #dff1f2;
      color: #166972;
      font-size: 12px;
      font-weight: 700;
    }
    .tenant-alert {
      border-radius: 12px;
      padding: 14px 16px;
      margin-top: 14px;
      background: #fff7e7;
      border: 1px solid #f3d799;
      color: #78571b;
    }
    .tenant-alert strong {
      color: #5f4315;
    }
    .tenant-alert .btn {
      margin-top: 10px;
    }
    .summary-card,
    .shortcut-card,
    .check-card {
      background: #fff;
      border: 1px solid #e5ecee;
      border-radius: 16px;
      box-shadow: 0 8px 26px rgba(35, 58, 62, 0.06);
    }
    .summary-card {
      padding: 18px 16px;
      min-height: 128px;
      margin-bottom: 16px;
    }
    .summary-card .label-top {
      display: block;
      color: #6c8085;
      font-size: 12px;
      font-weight: 700;
      letter-spacing: .08em;
      text-transform: uppercase;
      margin-bottom: 8px;
    }
    .summary-card .value {
      display: block;
      font-size: 34px;
      line-height: 1;
      font-weight: 700;
      color: #186b74;
      margin-bottom: 8px;
    }
    .summary-card .hint {
      color: #5e7176;
      line-height: 1.55;
    }
    .shortcut-card {
      padding: 18px;
      margin-bottom: 16px;
      min-height: 176px;
    }
    .shortcut-card h4,
    .check-card h4 {
      margin-top: 0;
      margin-bottom: 8px;
      font-size: 18px;
      color: #233335;
    }
    .shortcut-card p,
    .check-card p {
      color: #617579;
      line-height: 1.6;
      min-height: 72px;
    }
    .shortcut-card .btn {
      margin-top: 6px;
    }
    .check-card {
      padding: 18px;
      margin-bottom: 16px;
    }
    .check-status {
      display: inline-block;
      margin-bottom: 10px;
      padding: 6px 10px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 700;
    }
    .check-status-ok {
      background: #e3f4ea;
      color: #177245;
    }
    .check-status-pending {
      background: #fff4df;
      color: #8b6114;
    }
    .progress-shell {
      height: 12px;
      border-radius: 999px;
      background: #e6eff0;
      overflow: hidden;
      margin: 12px 0 8px;
    }
    .progress-shell > span {
      display: block;
      height: 100%;
      border-radius: 999px;
      background: linear-gradient(90deg, #2c8895 0%, #5bb3a6 100%);
    }
    .section-title {
      margin: 6px 0 14px;
      font-size: 22px;
      font-weight: 700;
      color: #243335;
    }
    .shortcut-icon {
      width: 42px;
      height: 42px;
      border-radius: 12px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: #ebf5f6;
      color: #1a6d76;
      font-size: 18px;
      margin-bottom: 12px;
    }
  </style>
</head>
<body class="skin-blue sidebar-mini">
<div class="wrapper">

  <?= view('partials/header', ['menu_items' => $menu_items ?? []]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Dashboard spazio</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Qui trovi i prossimi passi del tuo studio e le scorciatoie per arrivarci senza passaggi inutili.
      </p>
    </section>

    <section class="content">
      <div class="row">
        <div class="col-md-3">
          <?= view('partials/sidebar_admin', ['menu_items' => $menu_items ?? []]) ?>
        </div>

        <div class="col-md-9">
          <div class="tenant-hero">
            <h3><?= esc((string)($tenant['tenant_name'] ?? ($tenantContext->tenantName ?? 'Spazio cliente'))) ?></h3>
            <p>
              La home del responsabile dello studio ora parte direttamente dall'area operativa. Prima mettiamo ordine su sedi, personale e clienti, poi tutto il resto del team entra in uno spazio già pulito.
            </p>
            <div>
              <span class="tenant-badge">Pacchetto: <?= esc((string)($dashboardStats['package_name'] ?? 'Base')) ?></span>
              <span class="tenant-badge">Utenti spazio: <?= (int)($dashboardStats['current_users'] ?? 0) ?></span>
              <?php if (($dashboardStats['remaining_users'] ?? null) !== null): ?>
                <span class="tenant-badge">Posti liberi: <?= (int)$dashboardStats['remaining_users'] ?></span>
              <?php endif; ?>
            </div>

            <div class="progress-shell">
              <span style="width: <?= (int)($completion['percent'] ?? 0) ?>%;"></span>
            </div>
            <div class="text-muted">
              Hai completato <?= (int)($completion['completed'] ?? 0) ?> passaggi su <?= (int)($completion['total'] ?? 0) ?>.
            </div>

            <?php if ($requiresLocationSetup): ?>
              <div class="tenant-alert">
                <strong>Prima cosa da fare:</strong> configura i luoghi.
                Senza almeno una sede attiva il personale non va inserito, altrimenti ti ritrovi con flussi sporchi e luoghi incompleti.
                <br>
                <a href="<?= site_url('agenda/gestione-sedi') ?>" class="btn btn-warning">
                  <i class="fa fa-map-marker"></i> Vai a Gestione sedi
                </a>
              </div>
            <?php endif; ?>
          </div>

          <div class="row">
            <div class="col-sm-6 col-lg-3">
              <div class="summary-card">
                <span class="label-top">Sedi</span>
                <span class="value"><?= (int)($dashboardStats['location_count'] ?? 0) ?></span>
                <div class="hint">Luoghi disponibili per assegnare correttamente il personale.</div>
              </div>
            </div>
            <div class="col-sm-6 col-lg-3">
              <div class="summary-card">
                <span class="label-top">Personale</span>
                <span class="value"><?= (int)($dashboardStats['personnel_count'] ?? 0) ?></span>
                <div class="hint">Profili operativi già presenti nello studio.</div>
              </div>
            </div>
            <div class="col-sm-6 col-lg-3">
              <div class="summary-card">
                <span class="label-top">Clienti</span>
                <span class="value"><?= (int)($dashboardStats['client_count'] ?? 0) ?></span>
                <div class="hint">Anagrafiche clienti già caricate nello studio.</div>
              </div>
            </div>
            <div class="col-sm-6 col-lg-3">
              <div class="summary-card">
                <span class="label-top">Team spazio</span>
                <span class="value"><?= (int)($dashboardStats['team_count'] ?? 0) ?></span>
                <div class="hint">Accessi collegati a questo spazio cliente.</div>
              </div>
            </div>
          </div>

          <h3 class="section-title">Scorciatoie</h3>
          <div class="row">
            <?php foreach ($shortcutActions as $action): ?>
              <div class="col-md-6">
                <div class="shortcut-card">
                  <div class="shortcut-icon">
                    <i class="fa <?= esc((string)($action['icon'] ?? 'fa-circle-o')) ?>"></i>
                  </div>
                  <h4><?= esc((string)($action['title'] ?? 'Azione')) ?></h4>
                  <p><?= esc((string)($action['description'] ?? '')) ?></p>
                  <a href="<?= esc((string)($action['href'] ?? '#')) ?>" class="btn btn-primary">
                    Apri
                  </a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <h3 class="section-title">Punti da completare</h3>
          <div class="row">
            <?php foreach ($checklist as $item): ?>
              <div class="col-md-4">
                <div class="check-card">
                  <span class="check-status <?= !empty($item['complete']) ? 'check-status-ok' : 'check-status-pending' ?>">
                    <?= !empty($item['complete']) ? 'Completato' : 'Da fare' ?>
                  </span>
                  <h4><i class="fa <?= esc((string)($item['icon'] ?? 'fa-check-circle')) ?>"></i> <?= esc((string)($item['title'] ?? 'Passaggio')) ?></h4>
                  <p><?= esc((string)($item['description'] ?? '')) ?></p>
                  <div class="text-muted" style="margin-bottom:10px;">
                    <?= (int)($item['counter'] ?? 0) ?> <?= esc((string)($item['counter_label'] ?? 'elementi')) ?>
                  </div>
                  <a href="<?= esc((string)($item['cta_url'] ?? '#')) ?>" class="btn btn-default">
                    <?= esc((string)($item['cta_label'] ?? 'Apri')) ?>
                  </a>
                </div>
              </div>
            <?php endforeach; ?>
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
</div>

<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
<script src="<?= base_url('public/plugins/slimScroll/jquery.slimscroll.min.js') ?>"></script>
<script src="<?= base_url('public/plugins/fastclick/fastclick.min.js') ?>"></script>
<script src="<?= base_url('public/dist/js/app.min.js') ?>"></script>
</body>
</html>
