<?php
$menu_items = $menu_items ?? ((session()->get('menuDataAdmin')['result'] ?? []));
$tenantScope = is_array($tenantScope ?? null) ? $tenantScope : [];
$listing = is_array($listing ?? null) ? $listing : [];
$errors = is_array($errors ?? null) ? $errors : [];
$summary = is_array($listing['summary'] ?? null) ? $listing['summary'] : [];
$documents = is_array($listing['documents'] ?? null) ? $listing['documents'] : [];
$localStateLabels = is_array($listing['local_state_labels'] ?? null) ? $listing['local_state_labels'] : [];
$documentTypeLabels = is_array($listing['document_type_labels'] ?? null) ? $listing['document_type_labels'] : [];
$tsSyncLabels = is_array($listing['ts_sync_labels'] ?? null) ? $listing['ts_sync_labels'] : [];
$tableAvailable = !empty($listing['table_available']);
$schemaMessage = trim((string) ($listing['schema_message'] ?? ''));
$success = trim((string) ($success ?? ''));
$warning = trim((string) ($warning ?? ''));
$tsEnabled = !empty($tsEnabled);
$environmentLabel = defined('ENVIRONMENT') && ENVIRONMENT === 'production' ? 'Produzione' : 'Locale';
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Archivio fatture</title>
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
    <section class="content billing-archive-content">
      <div class="row billing-archive-layout">
        <aside class="col-md-3 billing-archive-nav">
          <?= view('partials/sidebar_admin', ['menu_items' => $menu_items]) ?>
        </aside>

        <main class="col-md-9">
          <div class="billing-archive" id="billing-archive">
            <?php if (!empty($errors['generic'])): ?>
              <div class="alert alert-danger"><?= esc((string) $errors['generic']) ?></div>
            <?php endif; ?>
            <?php if ($success !== ''): ?>
              <div class="alert alert-success"><?= esc($success) ?></div>
            <?php endif; ?>
            <?php if ($warning !== ''): ?>
              <div class="alert alert-warning"><?= esc($warning) ?></div>
            <?php endif; ?>

            <header class="billing-archive-modulebar">
              <div class="billing-archive-modulecopy">
                <div class="billing-eyebrow">Modulo · Fatturazione</div>
                <div class="billing-title-row">
                  <span class="billing-module-icon"><i class="fa fa-file-text-o"></i></span>
                  <div>
                    <h1>Fatture</h1>
                    <p>Archivio dei documenti dello spazio <?= esc((string) ($tenantScope['tenant_name'] ?? 'attivo')) ?>.</p>
                  </div>
                  <span class="billing-environment"><?= esc($environmentLabel) ?></span>
                </div>
              </div>
              <div class="billing-archive-actions">
                <a class="billing-action billing-action-secondary" href="<?= site_url('admin/fatturazione-statistiche') ?>">
                  <i class="fa fa-bar-chart"></i> Statistiche e report
                </a>
                <a class="billing-action billing-action-secondary" href="<?= site_url('admin/fatturazione') ?>">
                  <i class="fa fa-arrow-left"></i> Torna alla dashboard
                </a>
                <a class="billing-action billing-action-secondary" href="<?= site_url('admin/fatturazione-documento') ?>">
                  <i class="fa fa-file-text-o"></i> Impostazioni documento
                </a>
                <a class="billing-action billing-action-primary" href="<?= site_url('admin/fatturazione-documenti/nuovo') ?>">
                  <i class="fa fa-plus"></i> Nuova fattura
                </a>
              </div>
            </header>

            <div class="billing-kpi-grid billing-archive-kpis">
              <article class="billing-kpi-card">
                <span>Totali</span>
                <strong><?= (int) ($summary['total_documents'] ?? 0) ?></strong>
                <p>documenti in archivio</p>
              </article>
              <article class="billing-kpi-card billing-kpi-neutral">
                <span>Bozze</span>
                <strong><?= (int) ($summary['draft_count'] ?? 0) ?></strong>
                <p>da completare</p>
              </article>
              <article class="billing-kpi-card">
                <span>Definitivi</span>
                <strong><?= (int) ($summary['issued_count'] ?? 0) ?></strong>
                <p>documenti chiusi</p>
              </article>
              <article class="billing-kpi-card">
                <span>Totale incassato del mese</span>
                <strong>&euro; <?= number_format((float) ($summary['month_revenue'] ?? 0), 2, ',', '.') ?></strong>
                <p>documenti definitivi</p>
              </article>
            </div>

            <section class="billing-archive-toolbar" aria-label="Ricerca e filtri archivio">
              <div class="billing-archive-toolbar-row">
                <label class="billing-archive-search">
                  <i class="fa fa-search"></i>
                  <input type="search" id="billing-archive-search" placeholder="Cerca per numero, cliente o codice fiscale" autocomplete="off">
                </label>
                <div class="billing-archive-toolbar-meta">
                  <span id="billing-archive-count" class="billing-archive-count"><?= count($documents) ?> documenti</span>
                  <button class="billing-clear-filters" id="billing-clear-filters" type="button" hidden>
                    <i class="fa fa-filter"></i> Azzera filtri
                  </button>
                </div>
              </div>

              <div class="billing-archive-filters">
                <div class="billing-filter-group">
                  <span>Stato</span>
                  <div class="billing-filter-segment" data-filter-group="status">
                    <button type="button" class="is-active" data-filter-value="all">Tutte</button>
                    <button type="button" data-filter-value="draft">Bozza</button>
                    <button type="button" data-filter-value="issued">Definitivo</button>
                  </div>
                </div>
                <div class="billing-filter-group">
                  <span>Tipo</span>
                  <div class="billing-filter-segment" data-filter-group="type">
                    <button type="button" class="is-active" data-filter-value="all">Tutti</button>
                    <button type="button" data-filter-value="invoice">Fattura</button>
                    <button type="button" data-filter-value="receipt">Ricevuta</button>
                  </div>
                </div>
                <div class="billing-filter-group">
                  <span>TS</span>
                  <div class="billing-filter-segment" data-filter-group="ts">
                    <button type="button" class="is-active" data-filter-value="all">Tutti</button>
                    <button type="button" data-filter-value="not_requested">Non richiesto</button>
                    <button type="button" data-filter-value="ready">Pronto</button>
                    <button type="button" data-filter-value="sent">Inviato</button>
                  </div>
                </div>
                <label class="billing-filter-group billing-period-filter">
                  <span>Periodo</span>
                  <select id="billing-period-filter">
                    <option value="month">Questo mese</option>
                    <option value="last_month">Mese scorso</option>
                    <option value="year">Tutto l’anno</option>
                    <option value="all">Tutti i periodi</option>
                  </select>
                </label>
              </div>

              <div class="billing-active-filters" id="billing-active-filters" hidden></div>
            </section>

            <form method="post" id="billing-bulk-send-form" action="<?= site_url('admin/sistema-ts/documenti/send-bulk-billing') ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="return_to" value="billing_archive">
              <div id="billing-bulk-inputs"></div>
              <div class="billing-bulk-bar" id="billing-bulk-bar" hidden>
                <div>
                  <span class="billing-bulk-icon"><i class="fa fa-paper-plane-o"></i></span>
                  <strong id="billing-bulk-summary">0 fatture selezionate</strong>
                  <span>Solo documenti definitivi pronti per il Sistema TS.</span>
                </div>
                <button class="billing-bulk-send" id="billing-bulk-send" type="submit" disabled>
                  <i class="fa fa-paper-plane-o"></i> Invia selezionate a TS
                </button>
              </div>
            </form>

            <section class="billing-archive-panel">
              <div class="billing-archive-panel-head">
                <h2>Elenco fatture</h2>
                <div class="billing-archive-selection-actions">
                  <span>La casella seleziona la pagina corrente</span>
                  <button class="billing-select-all-filtered" id="billing-select-all-filtered" type="button" disabled>
                    <i class="fa fa-check-square-o"></i>
                    <span id="billing-select-all-filtered-label">Seleziona tutte le fatture disponibili all'invio TS</span>
                  </button>
                </div>
              </div>

              <?php if (!$tableAvailable): ?>
                <div class="billing-archive-panel-body">
                  <div class="alert alert-warning" style="margin:0;">
                    <?= esc($schemaMessage !== '' ? $schemaMessage : 'La tabella billing_documents non è ancora presente nel database tenant corrente.') ?>
                  </div>
                </div>
              <?php elseif ($documents === []): ?>
                <div class="billing-empty-state billing-archive-empty">
                  <span class="billing-empty-icon"><i class="fa fa-file-text-o"></i></span>
                  <h3>Ancora nessun documento</h3>
                  <p>Le fatture e le ricevute che emetti compaiono qui.</p>
                  <a class="billing-action billing-action-primary" href="<?= site_url('admin/fatturazione-documenti/nuovo') ?>">
                    <i class="fa fa-plus"></i> Nuova fattura
                  </a>
                </div>
              <?php else: ?>
                <div class="table-responsive billing-archive-table-wrap">
                  <table class="table billing-archive-table" id="billing-archive-table">
                    <thead>
                      <tr>
                        <th class="billing-select-col">
                          <input type="checkbox" id="billing-select-all" aria-label="Seleziona le fatture inviabili a TS nella pagina corrente" <?= $tsEnabled ? '' : 'disabled' ?>>
                        </th>
                        <th class="billing-index-col">#</th>
                        <th><button class="billing-table-sort" type="button" data-sort-key="number">Numero <i class="fa fa-sort"></i></button></th>
                        <th><button class="billing-table-sort" type="button" data-sort-key="type">Tipo <i class="fa fa-sort"></i></button></th>
                        <th><button class="billing-table-sort" type="button" data-sort-key="client">Cliente <i class="fa fa-sort"></i></button></th>
                        <th><button class="billing-table-sort" type="button" data-sort-key="date">Emissione <i class="fa fa-sort"></i></button></th>
                        <th class="text-right"><button class="billing-table-sort" type="button" data-sort-key="amount">Totale <i class="fa fa-sort"></i></button></th>
                        <th>Stato</th>
                        <th>TS</th>
                        <th class="text-right">Azioni</th>
                      </tr>
                    </thead>
                    <tbody id="billing-archive-body">
                      <?php foreach ($documents as $index => $row): ?>
                        <?php
                          $documentId = (int) ($row['id_billing_document'] ?? 0);
                          $localState = trim((string) ($row['local_state'] ?? 'draft'));
                          $tsState = trim((string) ($row['ts_sync_state'] ?? 'not_requested'));
                          $type = trim((string) ($row['document_type'] ?? ''));
                          $canEdit = !array_key_exists('can_edit', $row) || !empty($row['can_edit']);
                          $canDelete = !array_key_exists('can_delete', $row) || !empty($row['can_delete']);
                          $lockedReason = trim((string) ($row['locked_reason'] ?? ''));
                          $tsSelectable = $tsEnabled
                            && $localState === 'issued'
                            && !empty($row['ts_sync_enabled'])
                            && !in_array($tsState, ['sent', 'sending'], true);
                          $searchText = implode(' ', [
                            (string) ($row['document_number'] ?? ''),
                            (string) ($row['patient_name'] ?? ''),
                            (string) ($row['patient_tax_code'] ?? ''),
                          ]);
                        ?>
                        <tr class="billing-archive-row"
                            data-id="<?= $documentId ?>"
                            data-search="<?= esc(mb_strtolower($searchText), 'attr') ?>"
                            data-status="<?= esc($localState, 'attr') ?>"
                            data-type="<?= esc($type, 'attr') ?>"
                            data-ts="<?= esc($tsState, 'attr') ?>"
                            data-date="<?= esc((string) ($row['issue_date'] ?? ''), 'attr') ?>"
                            data-number="<?= esc((string) ($row['document_number'] ?? ''), 'attr') ?>"
                            data-client="<?= esc(mb_strtolower((string) ($row['patient_name'] ?? '')), 'attr') ?>"
                            data-amount="<?= esc((string) ((float) ($row['amount_total'] ?? 0)), 'attr') ?>">
                          <td class="billing-select-col">
                            <?php if ($tsSelectable): ?>
                              <input class="billing-document-select" type="checkbox" value="<?= $documentId ?>" aria-label="Seleziona <?= esc((string) ($row['document_number'] ?? 'fattura')) ?> per invio TS">
                            <?php else: ?>
                              <span class="billing-not-selectable" title="<?= esc(!$tsEnabled ? 'Sistema TS non attivo' : 'Il documento non è pronto per l’invio TS') ?>">—</span>
                            <?php endif; ?>
                          </td>
                          <td class="billing-index-col billing-row-index"><?= $index + 1 ?></td>
                          <td>
                            <a class="billing-document-number" href="<?= site_url('admin/fatturazione-documenti/modifica/' . $documentId) ?>">
                              <?= esc((string) ($row['document_number'] ?? '-')) ?>
                            </a>
                          </td>
                          <td><?= esc((string) ($documentTypeLabels[$type] ?? $type ?: '-')) ?></td>
                          <td>
                            <span class="billing-client-name"><?= esc((string) ($row['patient_name'] ?? '-')) ?></span>
                            <?php if (trim((string) ($row['patient_tax_code'] ?? '')) !== ''): ?>
                              <span class="billing-client-tax-code"><?= esc((string) ($row['patient_tax_code'] ?? '')) ?></span>
                            <?php endif; ?>
                          </td>
                          <td class="billing-table-muted"><?= esc((string) ($row['issue_date'] ?? '-')) ?></td>
                          <td class="text-right billing-table-total">&euro; <?= number_format((float) ($row['amount_total'] ?? 0), 2, ',', '.') ?></td>
                          <td><span class="billing-state-pill <?= $localState === 'issued' ? 'is-issued' : 'is-draft' ?>"><?= esc((string) ($localStateLabels[$localState] ?? $localState ?: '-')) ?></span></td>
                          <td><span class="billing-ts-state billing-ts-<?= esc($tsState, 'attr') ?>"><?= esc((string) ($tsSyncLabels[$tsState] ?? $tsState ?: '-')) ?></span></td>
                          <td class="text-right billing-archive-actions-cell">
                            <a class="billing-icon-action" href="<?= site_url('admin/fatturazione-documenti/modifica/' . $documentId) ?>" aria-label="<?= $canEdit ? 'Modifica' : 'Apri' ?> documento"><i class="fa <?= $canEdit ? 'fa-pencil' : 'fa-folder-open-o' ?>"></i></a>
                            <a class="billing-icon-action" href="<?= site_url('admin/fatturazione-documenti/preview/' . $documentId) ?>" aria-label="Anteprima documento"><i class="fa fa-eye"></i></a>
                            <a class="billing-icon-action" href="<?= site_url('admin/fatturazione-documenti/pdf/' . $documentId) ?>" aria-label="Scarica PDF"><i class="fa fa-download"></i></a>
                            <?php if ((int) ($row['linked_ts_document_id'] ?? 0) > 0): ?>
                              <a class="billing-icon-action billing-ts-link-action" href="<?= site_url('admin/sistema-ts/documenti/modifica/' . (int) ($row['linked_ts_document_id'] ?? 0)) ?>" aria-label="Apri documento TS"><i class="fa fa-exchange"></i></a>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                              <form method="post" action="<?= site_url('admin/fatturazione-documenti/elimina/' . $documentId) ?>" class="billing-delete-form" onsubmit="return confirm('Confermi la cancellazione della fattura?');">
                                <?= csrf_field() ?>
                                <button class="billing-icon-action billing-delete-action" type="submit" aria-label="Elimina documento"><i class="fa fa-trash"></i></button>
                              </form>
                            <?php elseif ($lockedReason !== ''): ?>
                              <span class="billing-locked-note" title="<?= esc($lockedReason) ?>"><i class="fa fa-lock"></i></span>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <div class="billing-archive-empty-filter" id="billing-archive-empty-filter" hidden>
                  <span class="billing-empty-icon"><i class="fa fa-search"></i></span>
                  <h3>Nessun documento con questi filtri</h3>
                  <p>Prova a modificare la ricerca o azzerare i filtri attivi.</p>
                  <button class="billing-action billing-action-secondary" type="button" data-reset-filters><i class="fa fa-filter"></i> Azzera filtri</button>
                </div>
                <div class="billing-archive-pager" id="billing-archive-pager">
                  <span id="billing-archive-range">0–0 di 0</span>
                  <div>
                    <label>Righe per pagina
                      <select id="billing-per-page"><option value="10">10</option><option value="25">25</option><option value="50">50</option></select>
                    </label>
                    <span id="billing-page-summary">Pagina 1 di 1</span>
                    <button type="button" id="billing-page-prev"><i class="fa fa-chevron-left"></i> Precedente</button>
                    <button type="button" id="billing-page-next">Successiva <i class="fa fa-chevron-right"></i></button>
                  </div>
                </div>
              <?php endif; ?>
            </section>
          </div>
        </main>
      </div>
    </section>
  </div>

  <footer class="main-footer">
    <div class="pull-right hidden-xs"><b>Version</b> 2.0</div>
    <strong>&copy; AmbulatorioFacile</strong>
  </footer>
</div>
<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
<script>
  (function () {
    var root = document.getElementById('billing-archive');
    var body = document.getElementById('billing-archive-body');
    if (!root || !body) {
      return;
    }

    var rows = Array.prototype.slice.call(body.querySelectorAll('.billing-archive-row'));
    var search = document.getElementById('billing-archive-search');
    var period = document.getElementById('billing-period-filter');
    var count = document.getElementById('billing-archive-count');
    var clear = document.getElementById('billing-clear-filters');
    var activeFilters = document.getElementById('billing-active-filters');
    var emptyFilter = document.getElementById('billing-archive-empty-filter');
    var pager = document.getElementById('billing-archive-pager');
    var range = document.getElementById('billing-archive-range');
    var pageSummary = document.getElementById('billing-page-summary');
    var previous = document.getElementById('billing-page-prev');
    var next = document.getElementById('billing-page-next');
    var perPage = document.getElementById('billing-per-page');
    var selectAll = document.getElementById('billing-select-all');
    var selectAllFiltered = document.getElementById('billing-select-all-filtered');
    var selectAllFilteredLabel = document.getElementById('billing-select-all-filtered-label');
    var bulkForm = document.getElementById('billing-bulk-send-form');
    var bulkBar = document.getElementById('billing-bulk-bar');
    var bulkInputs = document.getElementById('billing-bulk-inputs');
    var bulkSummary = document.getElementById('billing-bulk-summary');
    var bulkButton = document.getElementById('billing-bulk-send');
    var filters = { status: 'all', type: 'all', ts: 'all' };
    var currentPage = 1;
    var rowLimit = 10;
    var sortKey = 'date';
    var sortDirection = -1;

    function normalize(value) {
      return String(value || '').toLowerCase();
    }

    function inPeriod(value) {
      if (!value || period.value === 'all') {
        return true;
      }

      var date = new Date(value + 'T00:00:00');
      if (isNaN(date.getTime())) {
        return true;
      }

      var today = new Date();
      var month = today.getMonth();
      var year = today.getFullYear();
      if (period.value === 'month') {
        return date.getMonth() === month && date.getFullYear() === year;
      }
      if (period.value === 'last_month') {
        var previous = new Date(year, month - 1, 1);
        return date.getMonth() === previous.getMonth() && date.getFullYear() === previous.getFullYear();
      }
      return date.getFullYear() === year;
    }

    function isMatching(row) {
      var term = normalize(search.value).trim();
      return (!term || normalize(row.getAttribute('data-search')).indexOf(term) !== -1)
        && (filters.status === 'all' || row.getAttribute('data-status') === filters.status)
        && (filters.type === 'all' || row.getAttribute('data-type') === filters.type)
        && (filters.ts === 'all' || row.getAttribute('data-ts') === filters.ts)
        && inPeriod(row.getAttribute('data-date'));
    }

    function selectedInputs() {
      return Array.prototype.slice.call(body.querySelectorAll('.billing-document-select:checked'));
    }

    function matchingEligibleInputs() {
      return rows
        .filter(isMatching)
        .map(function (row) { return row.querySelector('.billing-document-select'); })
        .filter(function (input) { return input !== null; });
    }

    function pageEligibleInputs() {
      return rows
        .filter(function (row) { return row.style.display !== 'none'; })
        .map(function (row) { return row.querySelector('.billing-document-select'); })
        .filter(function (input) { return input !== null; });
    }

    function syncBulkSelection() {
      var selected = selectedInputs();
      bulkInputs.innerHTML = '';
      selected.forEach(function (input) {
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'billing_document_ids[]';
        hidden.value = input.value;
        bulkInputs.appendChild(hidden);
      });
      bulkBar.hidden = selected.length === 0;
      bulkButton.disabled = selected.length === 0;
      bulkSummary.textContent = selected.length + (selected.length === 1 ? ' fattura selezionata' : ' fatture selezionate');

      if (selectAll) {
        var pageEligible = pageEligibleInputs();
        selectAll.disabled = pageEligible.length === 0;
        selectAll.checked = pageEligible.length > 0 && pageEligible.every(function (input) { return input.checked; });
        selectAll.indeterminate = pageEligible.some(function (input) { return input.checked; }) && !selectAll.checked;
      }

      if (selectAllFiltered && selectAllFilteredLabel) {
        var matchingEligible = matchingEligibleInputs();
        var allMatchingSelected = matchingEligible.length > 0 && matchingEligible.every(function (input) { return input.checked; });
        selectAllFiltered.disabled = matchingEligible.length === 0;
        selectAllFilteredLabel.textContent = matchingEligible.length === 0
          ? "Nessuna fattura disponibile all'invio TS"
          : (allMatchingSelected
            ? 'Deseleziona tutte le ' + matchingEligible.length + " fatture disponibili all'invio TS"
            : 'Seleziona tutte le ' + matchingEligible.length + " fatture disponibili all'invio TS");
      }
    }

    function resetFilters() {
      filters = { status: 'all', type: 'all', ts: 'all' };
      search.value = '';
      period.value = 'month';
      Array.prototype.slice.call(root.querySelectorAll('.billing-filter-segment button')).forEach(function (button) {
        button.classList.toggle('is-active', button.getAttribute('data-filter-value') === 'all');
      });
      currentPage = 1;
      render();
    }

    function updateActiveFilters() {
      var labels = [];
      if (search.value.trim()) { labels.push('Ricerca: “' + search.value.trim() + '”'); }
      var filterLabels = { status: { draft: 'Stato: Bozza', issued: 'Stato: Definitivo' }, type: { invoice: 'Tipo: Fattura', receipt: 'Tipo: Ricevuta' }, ts: { not_requested: 'TS: Non richiesto', ready: 'TS: Pronto', sent: 'TS: Inviato' } };
      Object.keys(filters).forEach(function (key) {
        if (filters[key] !== 'all' && filterLabels[key][filters[key]]) { labels.push(filterLabels[key][filters[key]]); }
      });
      if (period.value !== 'month') { labels.push('Periodo: ' + period.options[period.selectedIndex].text); }
      activeFilters.innerHTML = '';
      labels.forEach(function (label) {
        var chip = document.createElement('span');
        chip.className = 'billing-active-chip';
        chip.textContent = label;
        activeFilters.appendChild(chip);
      });
      activeFilters.hidden = labels.length === 0;
      clear.hidden = labels.length === 0;
    }

    function sortRows(items) {
      return items.sort(function (a, b) {
        var first = a.getAttribute('data-' + sortKey) || '';
        var second = b.getAttribute('data-' + sortKey) || '';
        if (sortKey === 'amount') {
          first = Number(first);
          second = Number(second);
        }
        return (first > second ? 1 : first < second ? -1 : 0) * sortDirection;
      });
    }

    function render() {
      var visible = sortRows(rows.filter(isMatching));
      visible.forEach(function (row) { body.appendChild(row); });
      var pageCount = Math.max(1, Math.ceil(visible.length / rowLimit));
      currentPage = Math.min(currentPage, pageCount);
      rows.forEach(function (row) { row.style.display = 'none'; });
      visible.slice((currentPage - 1) * rowLimit, currentPage * rowLimit).forEach(function (row) { row.style.display = ''; });
      visible.forEach(function (row, index) {
        var indexCell = row.querySelector('.billing-row-index');
        if (indexCell) { indexCell.textContent = index + 1; }
      });
      count.textContent = visible.length + (visible.length === 1 ? ' documento' : ' documenti');
      emptyFilter.hidden = visible.length !== 0;
      pager.hidden = visible.length === 0;
      var from = visible.length === 0 ? 0 : ((currentPage - 1) * rowLimit) + 1;
      var to = Math.min(currentPage * rowLimit, visible.length);
      range.textContent = from + '–' + to + ' di ' + visible.length;
      pageSummary.textContent = 'Pagina ' + currentPage + ' di ' + pageCount;
      previous.disabled = currentPage <= 1;
      next.disabled = currentPage >= pageCount;
      updateActiveFilters();
      syncBulkSelection();
    }

    Array.prototype.slice.call(root.querySelectorAll('.billing-filter-segment button')).forEach(function (button) {
      button.addEventListener('click', function () {
        var segment = button.parentNode;
        var key = segment.getAttribute('data-filter-group');
        filters[key] = button.getAttribute('data-filter-value');
        Array.prototype.slice.call(segment.querySelectorAll('button')).forEach(function (option) { option.classList.remove('is-active'); });
        button.classList.add('is-active');
        currentPage = 1;
        render();
      });
    });
    search.addEventListener('input', function () { currentPage = 1; render(); });
    period.addEventListener('change', function () { currentPage = 1; render(); });
    clear.addEventListener('click', resetFilters);
    Array.prototype.slice.call(root.querySelectorAll('[data-reset-filters]')).forEach(function (button) { button.addEventListener('click', resetFilters); });
    perPage.addEventListener('change', function () { rowLimit = Number(perPage.value); currentPage = 1; render(); });
    previous.addEventListener('click', function () { if (currentPage > 1) { currentPage--; render(); } });
    next.addEventListener('click', function () { currentPage++; render(); });

    Array.prototype.slice.call(root.querySelectorAll('.billing-table-sort')).forEach(function (button) {
      button.addEventListener('click', function () {
        var nextKey = button.getAttribute('data-sort-key');
        sortDirection = sortKey === nextKey ? sortDirection * -1 : 1;
        sortKey = nextKey;
        currentPage = 1;
        render();
      });
    });
    Array.prototype.slice.call(body.querySelectorAll('.billing-document-select')).forEach(function (input) { input.addEventListener('change', syncBulkSelection); });
    if (selectAll) {
      selectAll.addEventListener('change', function () {
        pageEligibleInputs().forEach(function (input) {
          input.checked = selectAll.checked;
        });
        syncBulkSelection();
      });
    }
    if (selectAllFiltered) {
      selectAllFiltered.addEventListener('click', function () {
        var matchingEligible = matchingEligibleInputs();
        var shouldSelect = !matchingEligible.every(function (input) { return input.checked; });
        matchingEligible.forEach(function (input) {
          input.checked = shouldSelect;
        });
        syncBulkSelection();
      });
    }
    bulkForm.addEventListener('submit', function (event) {
      var selected = selectedInputs();
      if (selected.length === 0 || !window.confirm('Inviare a TS ' + selected.length + (selected.length === 1 ? ' fattura selezionata?' : ' fatture selezionate?'))) {
        event.preventDefault();
      }
    });
    render();
  })();
</script>
</body>
</html>
