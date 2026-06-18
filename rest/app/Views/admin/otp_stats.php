<?php
/** @var array $menu_items */
/** @var string $fromDate */
/** @var string $toDate */
/** @var bool $hasLoginEmailSearch */
/** @var string $selectedLoginEmailSeenFlag */
/** @var string|null $latestLoginEmailDay */
/** @var bool $hasTrackingTable */
/** @var string|null $trackingStartAt */
/** @var array $pushStats */
/** @var array $emailStats */
/** @var array $channelSummary */
/** @var array $purposeSummary */
/** @var array $dailySummary */
/** @var string $selectedLoginEmailDay */
/** @var array $loginEmailDayStats */
/** @var int $totalSuccess */
/** @var int $totalFailed */
/** @var int $totalAttempts */

$result = session()->get('menuDataAdmin');
$menu_items = $result['result'] ?? ($menu_items ?? []);
$dottori = [];
$contDott = 0;
$loginEmailCsvQuery = http_build_query([
  'login_email_day' => $selectedLoginEmailDay,
  'mail_seen_flag' => $selectedLoginEmailSeenFlag,
]);
$statsFilterResetUrl = site_url('admin/otp-statistiche')
  . ($selectedLoginEmailDay !== '' ? '?' . http_build_query(array_filter([
      'login_email_day' => $selectedLoginEmailDay,
      'mail_seen_flag' => $selectedLoginEmailSeenFlag !== 'all' ? $selectedLoginEmailSeenFlag : null,
      'mail_search' => $hasLoginEmailSearch ? '1' : null,
  ])) : '');
$emailFilterResetUrl = site_url('admin/otp-statistiche') . '?' . http_build_query([
  'from' => $fromDate,
  'to' => $toDate,
  'login_email_day' => $latestLoginEmailDay ?: $selectedLoginEmailDay,
]);
?>
<html>
  <head>
    <meta charset="UTF-8">
    <title>AmbulatoriCLOUD | Statistiche OTP</title>
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
      .stat-card .inner h3 {
        font-size: 30px;
      }
      .table td,
      .table th {
        vertical-align: middle !important;
      }
      .filter-row .form-group {
        margin-right: 12px;
      }
      .label-first-use {
        background-color: #00a65a;
      }
      .daily-email-summary {
        margin-bottom: 8px;
      }
      .csv-actions .btn {
        margin-left: 8px;
      }
    </style>
  </head>
  <body class="skin-blue sidebar-mini">
    <div class="wrapper">
      <?= view('partials/header', [
        'menu_items' => $menu_items ?? [],
      ]) ?>

      <aside class="main-sidebar" style="display:none">
        <section class="sidebar"></section>
      </aside>

      <div class="content-wrapper">
        <section class="content-header">
          <h1>
            Statistiche OTP
            <small>Mail vs notifica nel periodo scelto</small>
          </h1>
          <ol class="breadcrumb">
            <li><a href="<?= site_url('admin') ?>"><i class="fa fa-dashboard"></i> Admin</a></li>
            <li class="active">Statistiche OTP</li>
          </ol>
        </section>

        <section class="content">
          <div class="row">
            <div class="col-md-3">
              <?= view('partials/sidebar_admin', [
                'menu_items'       => $menu_items ?? [],
                'dottori'          => $dottori,
                'contDott'         => $contDott,
                'selectedDoctorId' => null,
                'result'           => $result ?? [],
              ]) ?>
            </div>

            <div class="col-md-9">
              <div class="box box-primary">
                <div class="box-header with-border">
                  <h3 class="box-title">Filtro statistiche OTP</h3>
                </div>
                <div class="box-body">
                  <form method="get" action="<?= site_url('admin/otp-statistiche') ?>">
                    <input type="hidden" name="login_email_day" value="<?= esc($selectedLoginEmailDay) ?>">
                    <?php if ($hasLoginEmailSearch): ?>
                      <input type="hidden" name="mail_search" value="1">
                      <input type="hidden" name="mail_seen_flag" value="<?= esc($selectedLoginEmailSeenFlag) ?>">
                    <?php endif; ?>
                    <div class="row filter-row">
                      <div class="col-sm-4">
                        <div class="form-group">
                          <label for="from">Dal</label>
                          <input type="date" class="form-control" id="from" name="from" value="<?= esc($fromDate) ?>">
                        </div>
                      </div>
                      <div class="col-sm-4">
                        <div class="form-group">
                          <label for="to">Al</label>
                          <input type="date" class="form-control" id="to" name="to" value="<?= esc($toDate) ?>">
                        </div>
                      </div>
                      <div class="col-sm-4">
                        <div class="form-group" style="margin-top: 25px;">
                          <button type="submit" class="btn btn-primary">Aggiorna</button>
                          <a href="<?= esc($statsFilterResetUrl) ?>" class="btn btn-default">Reset</a>
                        </div>
                      </div>
                    </div>
                  </form>
                  <?php if ($trackingStartAt): ?>
                    <p class="text-muted" style="margin-bottom:0;">
                      Tracciamento disponibile dal <?= esc(date('d/m/Y H:i', strtotime($trackingStartAt))) ?>.
                    </p>
                  <?php else: ?>
                    <p class="text-muted" style="margin-bottom:0;">
                      Il tracciamento parte da quando e` stata attivata questa funzione.
                    </p>
                  <?php endif; ?>
                </div>
              </div>

              <div class="box box-info">
                <div class="box-header with-border">
                  <h3 class="box-title">Filtro elenco mail login</h3>
                </div>
                <div class="box-body">
                  <form method="get" action="<?= site_url('admin/otp-statistiche') ?>">
                    <input type="hidden" name="from" value="<?= esc($fromDate) ?>">
                    <input type="hidden" name="to" value="<?= esc($toDate) ?>">
                    <input type="hidden" name="mail_search" value="1">
                    <div class="row filter-row">
                      <div class="col-sm-4">
                        <div class="form-group">
                          <label for="login_email_day">Giorno lista email</label>
                          <input type="date" class="form-control" id="login_email_day" name="login_email_day" value="<?= esc($selectedLoginEmailDay) ?>">
                        </div>
                      </div>
                      <div class="col-sm-4">
                        <div class="form-group">
                          <label for="mail_seen_flag">Filtro stato</label>
                          <select class="form-control" id="mail_seen_flag" name="mail_seen_flag">
                            <option value="all" <?= $selectedLoginEmailSeenFlag === 'all' ? 'selected' : '' ?>>Tutte</option>
                            <option value="new" <?= $selectedLoginEmailSeenFlag === 'new' ? 'selected' : '' ?>>Mai viste</option>
                            <option value="seen" <?= $selectedLoginEmailSeenFlag === 'seen' ? 'selected' : '' ?>>Gia viste</option>
                          </select>
                        </div>
                      </div>
                      <div class="col-sm-4">
                        <div class="form-group" style="margin-top: 25px;">
                          <button type="submit" class="btn btn-primary">Aggiorna lista mail</button>
                          <a href="<?= esc($emailFilterResetUrl) ?>" class="btn btn-default">Reset lista mail</a>
                        </div>
                      </div>
                    </div>
                  </form>
                  <?php if (!empty($latestLoginEmailDay)): ?>
                    <p class="text-muted" style="margin-bottom:0;">
                      Ultimo giorno con invii OTP login via email: <?= esc(date('d/m/Y', strtotime($latestLoginEmailDay))) ?>.
                    </p>
                  <?php endif; ?>
                </div>
              </div>

              <?php if (!$hasTrackingTable): ?>
                <div class="alert alert-warning">
                  La tabella <strong>otp_delivery_logs</strong> non e` ancora presente nel database. Finche` non viene creata, le statistiche OTP non possono essere raccolte.
                </div>
              <?php endif; ?>

              <?php if ($hasTrackingTable && $totalAttempts === 0): ?>
                <div class="alert alert-info">
                  Nessun invio OTP tracciato nel periodo selezionato.
                </div>
              <?php endif; ?>

              <div class="row">
                <div class="col-md-3 col-sm-6 col-xs-12">
                  <div class="small-box bg-aqua stat-card">
                    <div class="inner">
                      <h3><?= esc((string)$pushStats['success_count']) ?></h3>
                      <p>OTP inviati con notifica</p>
                    </div>
                    <div class="icon"><i class="fa fa-bell"></i></div>
                  </div>
                </div>
                <div class="col-md-3 col-sm-6 col-xs-12">
                  <div class="small-box bg-green stat-card">
                    <div class="inner">
                      <h3><?= esc((string)$emailStats['success_count']) ?></h3>
                      <p>OTP inviati via email</p>
                    </div>
                    <div class="icon"><i class="fa fa-envelope"></i></div>
                  </div>
                </div>
                <div class="col-md-3 col-sm-6 col-xs-12">
                  <div class="small-box bg-yellow stat-card">
                    <div class="inner">
                      <h3><?= esc((string)$totalFailed) ?></h3>
                      <p>Tentativi falliti</p>
                    </div>
                    <div class="icon"><i class="fa fa-exclamation-triangle"></i></div>
                  </div>
                </div>
                <div class="col-md-3 col-sm-6 col-xs-12">
                  <div class="small-box bg-purple stat-card">
                    <div class="inner">
                      <h3><?= esc((string)$totalAttempts) ?></h3>
                      <p>Tentativi tracciati</p>
                    </div>
                    <div class="icon"><i class="fa fa-line-chart"></i></div>
                  </div>
                </div>
              </div>

              <div class="box box-default">
                <div class="box-header with-border">
                  <h3 class="box-title">Riepilogo per canale</h3>
                </div>
                <div class="box-body table-responsive no-padding">
                  <table class="table table-striped">
                    <thead>
                      <tr>
                        <th>Canale</th>
                        <th>Invii riusciti</th>
                        <th>Invii falliti</th>
                        <th>Totale tentativi</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($channelSummary)): ?>
                        <tr>
                          <td colspan="4" class="text-center text-muted">Nessun dato disponibile.</td>
                        </tr>
                      <?php else: ?>
                        <?php foreach ($channelSummary as $stats): ?>
                          <tr>
                            <td><?= esc($stats['label']) ?></td>
                            <td><?= esc((string)$stats['success_count']) ?></td>
                            <td><?= esc((string)$stats['failed_count']) ?></td>
                            <td><?= esc((string)$stats['total_count']) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>

              <div class="box box-default">
                <div class="box-header with-border">
                  <h3 class="box-title">Dettaglio per tipo OTP</h3>
                </div>
                <div class="box-body table-responsive no-padding">
                  <table class="table table-striped">
                    <thead>
                      <tr>
                        <th>Tipologia</th>
                        <th>Push ok</th>
                        <th>Push ko</th>
                        <th>Email ok</th>
                        <th>Email ko</th>
                        <th>Totale</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($purposeSummary)): ?>
                        <tr>
                          <td colspan="6" class="text-center text-muted">Nessun dato disponibile.</td>
                        </tr>
                      <?php else: ?>
                        <?php foreach ($purposeSummary as $row): ?>
                          <tr>
                            <td><?= esc($row['label']) ?></td>
                            <td><?= esc((string)$row['push_success']) ?></td>
                            <td><?= esc((string)$row['push_failed']) ?></td>
                            <td><?= esc((string)$row['email_success']) ?></td>
                            <td><?= esc((string)$row['email_failed']) ?></td>
                            <td><?= esc((string)$row['total_count']) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>

              <div class="box box-default">
                <div class="box-header with-border">
                  <h3 class="box-title">Andamento giornaliero</h3>
                </div>
                <div class="box-body table-responsive no-padding">
                  <table class="table table-striped">
                    <thead>
                      <tr>
                        <th>Data</th>
                        <th>Push ok</th>
                        <th>Email ok</th>
                        <th>Riusciti totali</th>
                        <th>Falliti totali</th>
                        <th>Tentativi totali</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($dailySummary)): ?>
                        <tr>
                          <td colspan="6" class="text-center text-muted">Nessun dato disponibile.</td>
                        </tr>
                      <?php else: ?>
                        <?php foreach ($dailySummary as $row): ?>
                          <tr>
                            <td><?= esc(date('d/m/Y', strtotime((string)$row['day_key']))) ?></td>
                            <td><?= esc((string)$row['push_success']) ?></td>
                            <td><?= esc((string)$row['email_success']) ?></td>
                            <td><?= esc((string)$row['total_success']) ?></td>
                            <td><?= esc((string)$row['total_failed']) ?></td>
                            <td><?= esc((string)$row['total_count']) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>

              <div class="box box-default">
                <div class="box-header with-border">
                  <h3 class="box-title">Email OTP login del giorno</h3>
                  <?php if ($hasLoginEmailSearch): ?>
                    <div class="box-tools pull-right csv-actions">
                      <a href="<?= site_url('admin/otp-statistiche/csv') . '?' . esc($loginEmailCsvQuery) ?>" class="btn btn-success btn-sm">
                        <i class="fa fa-download"></i> CSV lista giorno
                      </a>
                    </div>
                  <?php endif; ?>
                </div>
                <div class="box-body">
                  <p class="text-muted">
                    Questa sezione considera solo gli invii OTP via email riusciti per il login MFA del giorno selezionato.
                  </p>
                  <p class="text-muted">
                    Il CSV scarica una riga per email con nome, cognome e cellulare quando disponibili.
                  </p>

                  <?php if (!$hasLoginEmailSearch): ?>
                    <div class="alert alert-info" style="margin-bottom:0;">
                      Seleziona un giorno e premi <strong>Aggiorna lista mail</strong> per eseguire la ricerca.
                    </div>
                  <?php else: ?>
                    <div class="box box-solid box-primary">
                      <div class="box-header">
                        <h3 class="box-title"><?= esc(date('d/m/Y', strtotime($selectedLoginEmailDay))) ?></h3>
                      </div>
                      <div class="box-body">
                        <p class="daily-email-summary text-muted">
                          OTP inviati: <strong><?= esc((string)$loginEmailDayStats['total_sent']) ?></strong> |
                          Email uniche: <strong><?= esc((string)$loginEmailDayStats['unique_email_count']) ?></strong> |
                          Mai viste: <strong><?= esc((string)$loginEmailDayStats['new_email_count']) ?></strong> |
                          Gia viste: <strong><?= esc((string)$loginEmailDayStats['seen_email_count']) ?></strong> |
                          In elenco: <strong><?= esc((string)$loginEmailDayStats['visible_unique_email_count']) ?></strong>
                        </p>

                        <?php if ($loginEmailDayStats['visible_unresolved_count'] > 0): ?>
                          <div class="alert alert-warning" style="margin-bottom:15px;">
                            <?= esc((string)$loginEmailDayStats['visible_unresolved_count']) ?> righe visibili di questo giorno non sono ricostruibili in chiaro e quindi non entrano nel CSV.
                          </div>
                        <?php endif; ?>

                        <div class="table-responsive no-padding">
                          <table class="table table-striped">
                            <thead>
                              <tr>
                                <th>Email</th>
                                <th>Nome</th>
                                <th>Cognome</th>
                                <th>Cellulare</th>
                                <th>OTP inviati nel giorno</th>
                                <th>Mai usata prima</th>
                                <th>Nel CSV</th>
                              </tr>
                            </thead>
                            <tbody>
                              <?php if (empty($loginEmailDayStats['visible_emails'])): ?>
                                <tr>
                                  <td colspan="7" class="text-center text-muted">Nessuna email corrisponde al filtro selezionato per il giorno scelto.</td>
                                </tr>
                              <?php else: ?>
                                <?php foreach ($loginEmailDayStats['visible_emails'] as $emailRow): ?>
                                  <tr>
                                    <td><?= esc($emailRow['email']) ?></td>
                                    <td><?= esc($emailRow['nome'] !== '' ? $emailRow['nome'] : '-') ?></td>
                                    <td><?= esc($emailRow['cognome'] !== '' ? $emailRow['cognome'] : '-') ?></td>
                                    <td><?= esc($emailRow['cellulare'] !== '' ? $emailRow['cellulare'] : '-') ?></td>
                                    <td><?= esc((string)$emailRow['sent_count']) ?></td>
                                    <td>
                                      <?php if (!empty($emailRow['is_first_time'])): ?>
                                        <span class="label label-first-use">Si</span>
                                      <?php else: ?>
                                        <span class="text-muted">No</span>
                                      <?php endif; ?>
                                    </td>
                                    <td>
                                      <?php if (!empty($emailRow['is_plain'])): ?>
                                        <span class="text-success">Si</span>
                                      <?php else: ?>
                                        <span class="text-warning">No</span>
                                      <?php endif; ?>
                                    </td>
                                  </tr>
                                <?php endforeach; ?>
                              <?php endif; ?>
                            </tbody>
                          </table>
                        </div>
                      </div>
                    </div>
                  <?php endif; ?>
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
        <strong>&copy; AmbulatoriCLOUD</strong>
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
