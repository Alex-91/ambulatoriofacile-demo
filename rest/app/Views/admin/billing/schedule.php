<?php
$menu_items = $menu_items ?? ((session()->get('menuDataAdmin')['result'] ?? []));
$tenantScope = is_array($tenantScope ?? null) ? $tenantScope : [];
$schedule = is_array($schedule ?? null) ? $schedule : [];
$summary = is_array($schedule['summary'] ?? null) ? $schedule['summary'] : [];
$documents = is_array($schedule['documents'] ?? null) ? $schedule['documents'] : [];
$tableAvailable = !empty($schedule['table_available']);
$schemaMessage = trim((string) ($schedule['schema_message'] ?? ''));
$success = trim((string) ($success ?? ''));
$warning = trim((string) ($warning ?? ''));
$today = date('Y-m-d');
$stateLabels = [
    'overdue' => 'Scaduta',
    'due_today' => 'Scade oggi',
    'upcoming' => 'In scadenza',
    'without_due_date' => 'Senza scadenza',
    'paid' => 'Pagata',
];
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Scadenzario fatture</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/assets/css/billing-ts-ui.css') ?>" rel="stylesheet" />
</head>
<body class="skin-blue sidebar-mini billing-ts-ui module-fatturazione">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items]) ?>

  <div class="content-wrapper">
    <section class="content billing-schedule-content">
      <div class="row billing-schedule-layout">
        <aside class="col-md-3 billing-schedule-nav">
          <?= view('partials/sidebar_admin', ['menu_items' => $menu_items]) ?>
        </aside>

        <main class="col-md-9">
          <div class="billing-schedule" id="billing-schedule">
            <?php if ($success !== ''): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>
            <?php if ($warning !== ''): ?><div class="alert alert-warning"><?= esc($warning) ?></div><?php endif; ?>

            <header class="billing-archive-modulebar">
              <div class="billing-archive-modulecopy">
                <div class="billing-eyebrow">Modulo · Fatturazione</div>
                <div class="billing-title-row">
                  <span class="billing-module-icon"><i class="fa fa-calendar"></i></span>
                  <div>
                    <h1>Scadenzario fatture</h1>
                    <p>Controlla incassi, scadenze e solleciti dello spazio <?= esc((string) ($tenantScope['tenant_name'] ?? 'attivo')) ?>.</p>
                  </div>
                </div>
              </div>
              <div class="billing-archive-actions">
                <a class="billing-action billing-action-secondary" href="<?= site_url('admin/fatturazione-documenti') ?>"><i class="fa fa-list"></i> Lista fatture</a>
                <a class="billing-action billing-action-primary" href="<?= site_url('admin/fatturazione-documenti/nuovo') ?>"><i class="fa fa-plus"></i> Nuova fattura</a>
              </div>
            </header>

            <div class="billing-kpi-grid billing-schedule-kpis">
              <article class="billing-kpi-card billing-kpi-danger"><span>Scadute</span><strong><?= (int) ($summary['overdue_count'] ?? 0) ?></strong><p>da sollecitare</p></article>
              <article class="billing-kpi-card billing-kpi-warning"><span>Scadono oggi</span><strong><?= (int) ($summary['due_today_count'] ?? 0) ?></strong><p>da controllare</p></article>
              <article class="billing-kpi-card"><span>Da incassare</span><strong>€ <?= number_format((float) ($summary['outstanding_amount'] ?? 0), 2, ',', '.') ?></strong><p><?= (int) ($summary['outstanding_count'] ?? 0) ?> fatture aperte</p></article>
              <article class="billing-kpi-card billing-kpi-success"><span>Pagate</span><strong><?= (int) ($summary['paid_count'] ?? 0) ?></strong><p>incassi registrati</p></article>
            </div>

            <section class="billing-schedule-toolbar" aria-label="Filtri scadenzario">
              <label class="billing-archive-search">
                <i class="fa fa-search"></i>
                <input type="search" id="billing-schedule-search" placeholder="Cerca numero o paziente" autocomplete="off">
              </label>
              <div class="billing-filter-segment" data-filter-group="schedule">
                <button type="button" class="is-active" data-filter-value="all">Tutte</button>
                <button type="button" data-filter-value="overdue">Scadute</button>
                <button type="button" data-filter-value="due_today">Oggi</button>
                <button type="button" data-filter-value="upcoming">In scadenza</button>
                <button type="button" data-filter-value="paid">Pagate</button>
              </div>
            </section>

            <section class="billing-archive-panel billing-schedule-panel">
              <div class="billing-archive-panel-head">
                <h2>Fatture e scadenze</h2>
                <span class="billing-archive-count" id="billing-schedule-count"><?= count($documents) ?> fatture</span>
              </div>
              <?php if (!$tableAvailable): ?>
                <div class="billing-archive-panel-body"><div class="alert alert-warning" style="margin:0;"><?= esc($schemaMessage !== '' ? $schemaMessage : 'Lo scadenzario non è disponibile nel database corrente.') ?></div></div>
              <?php elseif ($documents === []): ?>
                <div class="billing-empty-state billing-archive-empty"><span class="billing-empty-icon"><i class="fa fa-calendar"></i></span><h3>Nessuna fattura definitiva</h3><p>Le scadenze compariranno dopo l’emissione delle fatture.</p></div>
              <?php else: ?>
                <div class="table-responsive billing-archive-table-wrap">
                  <table class="table billing-archive-table billing-schedule-table">
                    <thead><tr><th>Fattura</th><th>Paziente</th><th>Scadenza</th><th>Modalità pagamento</th><th class="text-right">Importo</th><th>Stato</th><th>Email e solleciti</th><th class="text-right">Azioni</th></tr></thead>
                    <tbody>
                    <?php foreach ($documents as $row): ?>
                      <?php
                        $documentId = (int) ($row['id_billing_document'] ?? 0);
                        $state = (string) ($row['schedule_state'] ?? 'without_due_date');
                        $isPaid = $state === 'paid';
                        $due = trim((string) ($row['due_date'] ?? ''));
                        $invoiceSent = trim((string) ($row['invoice_email_sent_at'] ?? ''));
                        $reminderCount = (int) ($row['reminder_count'] ?? 0);
                        $searchText = mb_strtolower(trim((string) ($row['document_number'] ?? '') . ' ' . (string) ($row['patient_name'] ?? '')));
                      ?>
                      <tr class="billing-schedule-row" data-state="<?= esc($state, 'attr') ?>" data-search="<?= esc($searchText, 'attr') ?>">
                        <td><a class="billing-document-number" href="<?= site_url('admin/fatturazione-documenti/modifica/' . $documentId) ?>"><?= esc((string) ($row['document_number'] ?? '-')) ?></a><small>Emessa il <?= esc($row['issue_date'] !== '' ? date('d/m/Y', strtotime((string) $row['issue_date'])) : '-') ?></small></td>
                        <td><span class="billing-client-name"><?= esc((string) ($row['patient_name'] ?? '-')) ?></span><?php if (trim((string) ($row['patient_email'] ?? '')) !== ''): ?><small><?= esc((string) $row['patient_email']) ?></small><?php endif; ?></td>
                        <td class="billing-schedule-due <?= $state === 'overdue' ? 'is-overdue' : '' ?>"><?= $due !== '' ? esc(date('d/m/Y', strtotime($due))) : '—' ?><?php if ($state === 'overdue'): ?><small><?= abs((int) ($row['days_to_due'] ?? 0)) ?> giorni di ritardo</small><?php elseif ($state === 'upcoming'): ?><small>tra <?= (int) ($row['days_to_due'] ?? 0) ?> giorni</small><?php endif; ?></td>
                        <td><span class="billing-payment-method"><i class="fa fa-credit-card"></i> <?= esc((string) ($row['payment_method_label'] ?? '-')) ?></span></td>
                        <td class="text-right billing-table-total">€ <?= number_format((float) ($row['amount_total'] ?? 0), 2, ',', '.') ?></td>
                        <td><span class="billing-schedule-state is-<?= esc($state, 'attr') ?>"><?= esc((string) ($stateLabels[$state] ?? $state)) ?></span><?php if ($isPaid && trim((string) ($row['payment_date'] ?? '')) !== ''): ?><small><?= esc(date('d/m/Y', strtotime((string) $row['payment_date']))) ?></small><?php endif; ?></td>
                        <td class="billing-email-status">
                          <?php if ($invoiceSent !== ''): ?><strong class="is-sent"><i class="fa fa-check-circle"></i> Fattura inviata</strong><small><?= esc(date('d/m/Y H:i', strtotime($invoiceSent))) ?></small><?php else: ?><strong class="is-pending"><i class="fa fa-envelope-o"></i> Da inviare</strong><?php endif; ?>
                          <?php if ($reminderCount > 0): ?><small><?= $reminderCount ?> <?= $reminderCount === 1 ? 'sollecito inviato' : 'solleciti inviati' ?></small><?php endif; ?>
                        </td>
                        <td class="text-right billing-schedule-actions">
                          <?php if (!$isPaid): ?>
                            <a class="billing-icon-action" href="<?= site_url('admin/fatturazione-documenti/email/' . $documentId . '?type=reminder') ?>" title="Invia sollecito" aria-label="Invia sollecito"><i class="fa fa-bell-o"></i></a>
                            <form method="post" action="<?= site_url('admin/fatturazione-documenti/pagamento/' . $documentId) ?>" class="billing-inline-form" onsubmit="return confirm('Segnare questa fattura come pagata?');">
                              <?= csrf_field() ?><input type="hidden" name="payment_status" value="paid"><input type="hidden" name="payment_date" value="<?= esc($today, 'attr') ?>"><input type="hidden" name="return_to" value="schedule">
                              <button class="billing-icon-action billing-payment-action" type="submit" title="Segna come pagata" aria-label="Segna come pagata"><i class="fa fa-check"></i></button>
                            </form>
                          <?php else: ?>
                            <form method="post" action="<?= site_url('admin/fatturazione-documenti/pagamento/' . $documentId) ?>" class="billing-inline-form" onsubmit="return confirm('Annullare la registrazione del pagamento?');">
                              <?= csrf_field() ?><input type="hidden" name="payment_status" value="unpaid"><input type="hidden" name="return_to" value="schedule">
                              <button class="billing-icon-action" type="submit" title="Segna come non pagata" aria-label="Segna come non pagata"><i class="fa fa-undo"></i></button>
                            </form>
                          <?php endif; ?>
                          <a class="billing-icon-action" href="<?= site_url('admin/fatturazione-documenti/modifica/' . $documentId) ?>" title="Apri fattura" aria-label="Apri fattura"><i class="fa fa-pencil"></i></a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <div class="billing-archive-empty-filter" id="billing-schedule-empty" hidden><span class="billing-empty-icon"><i class="fa fa-search"></i></span><h3>Nessuna fattura con questi filtri</h3></div>
              <?php endif; ?>
            </section>
          </div>
        </main>
      </div>
    </section>
  </div>

  <footer class="main-footer"><div class="pull-right hidden-xs"><b>Version</b> 2.0</div><strong>&copy; AmbulatorioFacile</strong></footer>
</div>
<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
<script>
(function () {
  var root = document.getElementById('billing-schedule');
  if (!root) { return; }
  var rows = Array.prototype.slice.call(root.querySelectorAll('.billing-schedule-row'));
  var search = document.getElementById('billing-schedule-search');
  var count = document.getElementById('billing-schedule-count');
  var empty = document.getElementById('billing-schedule-empty');
  var activeState = 'all';
  function render() {
    var term = String(search.value || '').toLowerCase().trim();
    var visible = 0;
    rows.forEach(function (row) {
      var matches = (activeState === 'all' || row.getAttribute('data-state') === activeState)
        && (!term || String(row.getAttribute('data-search') || '').indexOf(term) !== -1);
      row.style.display = matches ? '' : 'none';
      if (matches) { visible++; }
    });
    count.textContent = visible + (visible === 1 ? ' fattura' : ' fatture');
    if (empty) { empty.hidden = visible !== 0; }
  }
  Array.prototype.slice.call(root.querySelectorAll('[data-filter-group="schedule"] button')).forEach(function (button) {
    button.addEventListener('click', function () {
      activeState = button.getAttribute('data-filter-value') || 'all';
      Array.prototype.slice.call(button.parentNode.querySelectorAll('button')).forEach(function (item) { item.classList.remove('is-active'); });
      button.classList.add('is-active');
      render();
    });
  });
  search.addEventListener('input', render);
  render();
})();
</script>
</body>
</html>
