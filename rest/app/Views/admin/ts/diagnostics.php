<?php
$menu_items = $menu_items ?? ((session()->get('menuDataAdmin')['result'] ?? []));
$tenantScope = is_array($tenantScope ?? null) ? $tenantScope : [];
$listing = is_array($listing ?? null) ? $listing : [];
$filters = is_array($listing['filters'] ?? null) ? $listing['filters'] : [];
$results = is_array($listing['results'] ?? null) ? $listing['results'] : [];
$stats = is_array($listing['stats'] ?? null) ? $listing['stats'] : [];
$selectedTrace = is_array($selectedTrace ?? null) ? $selectedTrace : [];
$selectedEntry = is_array($selectedTrace['entry'] ?? null) ? $selectedTrace['entry'] : [];
$selectedSummary = is_array($selectedTrace['summary'] ?? null) ? $selectedTrace['summary'] : [];
$selectedRaw = trim((string) ($selectedTrace['raw_json'] ?? ''));
$availableOperations = is_array($availableOperations ?? null) ? $availableOperations : [];
$availableStatuses = is_array($availableStatuses ?? null) ? $availableStatuses : [];
$rawPreviewLimit = max(2000, (int) ($rawPreviewLimit ?? 40000));
$successMessage = session()->getFlashdata('success');
$errorMessage = session()->getFlashdata('error');

$formatDateTime = static function (?string $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '-';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('d/m/Y H:i:s', $timestamp);
};

$statusLabels = [
    'success' => 'Successo',
    'blocked' => 'Bloccato',
    'error' => 'Errore',
    'unknown' => 'Sconosciuto',
];

$statusClasses = [
    'success' => 'success',
    'blocked' => 'warning',
    'error' => 'danger',
    'unknown' => 'default',
];

$operationLabels = static function (string $operation) use ($availableOperations): string {
    $operation = trim($operation);

    return $availableOperations[$operation] ?? ($operation !== '' ? $operation : 'Operazione TS');
};

$buildDiagnosticsUrl = static function (array $overrides = []) use ($filters): string {
    $params = array_merge($filters, $overrides);
    $params = array_filter($params, static function ($value): bool {
        if (is_int($value)) {
            return $value > 0;
        }

        return trim((string) $value) !== '';
    });

    $query = http_build_query($params);

    return site_url('admin/sistema-ts/diagnostica') . ($query !== '' ? '?' . $query : '');
};

$buildDownloadUrl = static function (string $traceId): string {
    return site_url('admin/sistema-ts/diagnostica/download') . '?trace=' . rawurlencode($traceId);
};

$truncateText = static function (string $value, int $limit): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (mb_strlen($value) <= $limit) {
        return $value;
    }

    return mb_substr($value, 0, $limit) . '...[troncato]';
};

$selectedRawPreview = $truncateText($selectedRaw, $rawPreviewLimit);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Diagnostica TS</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <style>
    .nav-pills.nav-stacked > li.active > a { background-color:#2c8895; color:#fff; }
    .hero-box { border:1px solid #dbe8eb; border-radius:16px; padding:22px; background:linear-gradient(135deg, #f8fcfc 0%, #eef7f8 60%, #fff5ec 100%); margin-bottom:16px; }
    .metric-card { border:1px solid #e5ecee; border-radius:14px; background:#fff; padding:16px; min-height:120px; margin-bottom:16px; }
    .metric-card .value { font-size:30px; font-weight:700; color:#186b74; line-height:1; margin-bottom:8px; }
    .metric-card .label-top { color:#6b8085; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
    .trace-card { border:1px solid #e5ecee; border-radius:14px; background:#fff; padding:16px; margin-bottom:12px; }
    .trace-card.is-selected { border-color:#2c8895; box-shadow:0 0 0 2px rgba(44, 136, 149, .08); }
    .trace-badge { display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:700; }
    .trace-badge.status-success { background:#e8f7ee; color:#1f7a45; }
    .trace-badge.status-warning { background:#fff5df; color:#8a6414; }
    .trace-badge.status-danger { background:#fdecec; color:#9b2d30; }
    .trace-badge.status-default { background:#eef2f3; color:#556b70; }
    .trace-meta { color:#60757a; font-size:13px; line-height:1.6; }
    .trace-path { font-size:12px; color:#60757a; word-break:break-all; }
    .step-card { border-left:4px solid #d9e8ea; background:#fbfdfd; border-radius:10px; padding:12px 14px; margin-bottom:10px; }
    .step-card.level-error { border-left-color:#d9534f; background:#fff7f7; }
    .step-card.level-warning { border-left-color:#f0ad4e; background:#fffaf2; }
    .step-card.level-info { border-left-color:#2c8895; }
    .step-code { font-family:Menlo, Consolas, monospace; font-size:12px; color:#556b70; }
    .json-preview { max-height:420px; overflow:auto; background:#1e272b; color:#eef7f8; border-radius:12px; padding:14px; font-size:12px; line-height:1.5; }
    .inline-code { font-family:Menlo, Consolas, monospace; font-size:12px; }
    .toolbar-row .btn { margin-right:8px; margin-bottom:8px; }
  </style>
  <link href="<?= base_url('public/assets/css/billing-ts-ui.css') ?>" rel="stylesheet" />
</head>
<body class="skin-blue sidebar-mini billing-ts-ui module-sistema-ts">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Diagnostica TS</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Ricerca trace di supporto per lo spazio <?= esc((string) ($tenantScope['tenant_name'] ?? 'attivo')) ?> con dettaglio operativo, timeline e download JSON sanificato.
      </p>
    </section>

    <section class="content">
      <div class="row">
        <div class="col-md-3">
          <?= view('partials/sidebar_admin', ['menu_items' => $menu_items]) ?>
        </div>

        <div class="col-md-9">
          <?php if (trim((string) $successMessage) !== ''): ?>
            <div class="alert alert-success"><?= esc((string) $successMessage) ?></div>
          <?php endif; ?>
          <?php if (trim((string) $errorMessage) !== ''): ?>
            <div class="alert alert-danger"><?= esc((string) $errorMessage) ?></div>
          <?php endif; ?>

          <div class="hero-box">
            <h3 style="margin-top:0; margin-bottom:8px;">Supporto tecnico TS pronto per assistenza</h3>
            <p style="margin:0 0 12px 0; color:#556b70;">
              Qui possiamo cercare trace per documento, protocollo o data, aprire la timeline della singola operazione e scaricare il JSON completo gia sanificato per analisi interna o confronto con il cliente.
            </p>
            <div class="toolbar-row">
              <a class="btn btn-primary" href="<?= site_url('admin/sistema-ts') ?>">
                <i class="fa fa-dashboard"></i> Torna alla dashboard TS
              </a>
              <a class="btn btn-default" href="<?= site_url('admin/sistema-ts/documenti') ?>">
                <i class="fa fa-list"></i> Vai ai documenti TS
              </a>
            </div>
          </div>

          <div class="box box-default">
            <div class="box-header with-border">
              <h3 class="box-title">Filtri ricerca</h3>
            </div>
            <div class="box-body">
              <form method="get" action="<?= site_url('admin/sistema-ts/diagnostica') ?>">
                <div class="row">
                  <div class="col-md-3">
                    <div class="form-group">
                      <label>Trace ID</label>
                      <input class="form-control" type="text" name="trace_id" value="<?= esc((string) ($filters['trace_id'] ?? '')) ?>" placeholder="ts-20260705-...">
                    </div>
                  </div>
                  <div class="col-md-2">
                    <div class="form-group">
                      <label>ID documento</label>
                      <input class="form-control" type="number" min="1" name="document_id" value="<?= esc((string) ($filters['document_id'] ?? '')) ?>" placeholder="17">
                    </div>
                  </div>
                  <div class="col-md-3">
                    <div class="form-group">
                      <label>Numero documento</label>
                      <input class="form-control" type="text" name="document_number" value="<?= esc((string) ($filters['document_number'] ?? '')) ?>" placeholder="TS260705173230">
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Protocollo TS</label>
                      <input class="form-control" type="text" name="protocol" value="<?= esc((string) ($filters['protocol'] ?? '')) ?>" placeholder="99260705001852589">
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-3">
                    <div class="form-group">
                      <label>Operazione</label>
                      <select class="form-control" name="operation">
                        <option value="">Tutte</option>
                        <?php foreach ($availableOperations as $operationKey => $operationLabel): ?>
                          <option value="<?= esc((string) $operationKey) ?>" <?= ((string) ($filters['operation'] ?? '') === (string) $operationKey) ? 'selected' : '' ?>>
                            <?= esc((string) $operationLabel) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <div class="col-md-2">
                    <div class="form-group">
                      <label>Stato</label>
                      <select class="form-control" name="status">
                        <option value="">Tutti</option>
                        <?php foreach ($availableStatuses as $statusKey => $statusLabel): ?>
                          <option value="<?= esc((string) $statusKey) ?>" <?= ((string) ($filters['status'] ?? '') === (string) $statusKey) ? 'selected' : '' ?>>
                            <?= esc((string) $statusLabel) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <div class="col-md-2">
                    <div class="form-group">
                      <label>Data da</label>
                      <input class="form-control" type="date" name="date_from" value="<?= esc((string) ($filters['date_from'] ?? '')) ?>">
                    </div>
                  </div>
                  <div class="col-md-2">
                    <div class="form-group">
                      <label>Data a</label>
                      <input class="form-control" type="date" name="date_to" value="<?= esc((string) ($filters['date_to'] ?? '')) ?>">
                    </div>
                  </div>
                  <div class="col-md-3">
                    <label style="display:block;">&nbsp;</label>
                    <button class="btn btn-primary" type="submit">
                      <i class="fa fa-search"></i> Cerca trace
                    </button>
                    <a class="btn btn-default" href="<?= site_url('admin/sistema-ts/diagnostica') ?>">
                      <i class="fa fa-undo"></i> Reset
                    </a>
                  </div>
                </div>
              </form>
            </div>
          </div>

          <div class="row">
            <div class="col-md-3">
              <div class="metric-card">
                <span class="label-top">Trace trovati</span>
                <div class="value"><?= (int) ($stats['total_matched'] ?? 0) ?></div>
                <div class="text-muted">Risultati coerenti con i filtri attuali.</div>
              </div>
            </div>
            <div class="col-md-3">
              <div class="metric-card">
                <span class="label-top">Successi</span>
                <div class="value"><?= (int) (($stats['by_status']['success'] ?? 0)) ?></div>
                <div class="text-muted">Operazioni concluse senza blocchi tecnici.</div>
              </div>
            </div>
            <div class="col-md-3">
              <div class="metric-card">
                <span class="label-top">Bloccati</span>
                <div class="value"><?= (int) (($stats['by_status']['blocked'] ?? 0)) ?></div>
                <div class="text-muted">Casi fermati da validazione o prerequisiti.</div>
              </div>
            </div>
            <div class="col-md-3">
              <div class="metric-card">
                <span class="label-top">Errori</span>
                <div class="value"><?= (int) (($stats['by_status']['error'] ?? 0)) ?></div>
                <div class="text-muted">Trace con eccezioni o esito tecnico negativo.</div>
              </div>
            </div>
          </div>

          <?php if (!empty($selectedTraceMissing) && trim((string) ($selectedTraceId ?? '')) !== ''): ?>
            <div class="alert alert-danger">
              Il trace richiesto <code><?= esc((string) ($selectedTraceId ?? '')) ?></code> non e disponibile nei log TS del tenant corrente.
            </div>
          <?php endif; ?>

          <div class="box box-default">
            <div class="box-header with-border">
              <h3 class="box-title">Risultati diagnostica</h3>
            </div>
            <div class="box-body">
              <?php if (empty($listing['has_logs'])): ?>
                <div class="alert alert-warning" style="margin-bottom:0;">
                  Nessun trace TS disponibile in archivio per questo tenant. I file appariranno qui dopo i primi healthcheck, invii o recuperi ricevuta.
                </div>
              <?php elseif ($results === []): ?>
                <div class="text-muted">Nessun trace TS trovato con i filtri impostati.</div>
              <?php else: ?>
                <?php foreach ($results as $row): ?>
                  <?php if (!is_array($row)) { continue; } ?>
                  <?php
                    $traceId = trim((string) ($row['trace_id'] ?? ''));
                    $statusKey = trim((string) ($row['status'] ?? 'unknown'));
                    $statusClass = $statusClasses[$statusKey] ?? 'default';
                    $statusLabel = $statusLabels[$statusKey] ?? ($statusKey !== '' ? $statusKey : 'Sconosciuto');
                    $isSelected = $traceId !== '' && $traceId === (string) ($selectedEntry['trace_id'] ?? '');
                  ?>
                  <div class="trace-card<?= $isSelected ? ' is-selected' : '' ?>">
                    <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                      <div>
                        <div style="margin-bottom:6px;">
                          <span class="trace-badge status-<?= esc($statusClass) ?>"><?= esc($statusLabel) ?></span>
                          <span class="trace-badge status-default" style="margin-left:6px;"><?= esc($operationLabels((string) ($row['operation'] ?? ''))) ?></span>
                        </div>
                        <strong><?= esc($traceId !== '' ? $traceId : 'Trace TS') ?></strong>
                        <div class="trace-meta">
                          Avvio: <?= esc($formatDateTime((string) ($row['started_at'] ?? ''))) ?> |
                          Durata: <?= esc((string) ((int) ($row['duration_ms'] ?? 0))) ?> ms |
                          Step: <?= esc((string) ((int) ($row['step_count'] ?? 0))) ?>
                        </div>
                        <div class="trace-meta">
                          Documento: <?= (int) ($row['document_id'] ?? 0) > 0 ? '#' . esc((string) ($row['document_id'] ?? '')) : '-' ?>
                          <?php if (trim((string) ($row['document_number'] ?? '')) !== ''): ?>
                            | Numero: <?= esc((string) ($row['document_number'] ?? '')) ?>
                          <?php endif; ?>
                          <?php if (trim((string) ($row['protocol'] ?? '')) !== ''): ?>
                            | Protocollo: <?= esc((string) ($row['protocol'] ?? '')) ?>
                          <?php endif; ?>
                        </div>
                        <div class="trace-meta"><?= esc((string) ($row['message'] ?? '')) ?></div>
                      </div>
                      <div style="text-align:right;">
                        <a class="btn btn-primary btn-sm" href="<?= esc($buildDiagnosticsUrl(['trace' => $traceId])) ?>">
                          <i class="fa fa-search-plus"></i> Apri dettaglio
                        </a>
                        <?php if ((int) ($row['document_id'] ?? 0) > 0): ?>
                          <a class="btn btn-default btn-sm" href="<?= site_url('admin/sistema-ts/documenti/modifica/' . (int) ($row['document_id'] ?? 0)) ?>">
                            <i class="fa fa-file-text-o"></i> Documento
                          </a>
                        <?php endif; ?>
                        <?php if ($traceId !== ''): ?>
                          <a class="btn btn-default btn-sm" href="<?= esc($buildDownloadUrl($traceId)) ?>">
                            <i class="fa fa-download"></i> JSON
                          </a>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>

                <?php if (!empty($listing['truncated'])): ?>
                  <div class="text-muted" style="margin-top:12px;">
                    La lista e limitata ai primi 80 trace ordinati dal piu recente. Affina i filtri per restringere meglio il risultato.
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($selectedTrace !== []): ?>
            <?php $selectedStatusKey = trim((string) ($selectedEntry['status'] ?? 'unknown')); ?>
            <div class="box box-primary">
              <div class="box-header with-border">
                <h3 class="box-title">Dettaglio trace <?= esc((string) ($selectedEntry['trace_id'] ?? '')) ?></h3>
              </div>
              <div class="box-body">
                <div class="toolbar-row">
                  <?php if (trim((string) ($selectedEntry['trace_id'] ?? '')) !== ''): ?>
                    <a class="btn btn-default" href="<?= esc($buildDownloadUrl((string) ($selectedEntry['trace_id'] ?? ''))) ?>">
                      <i class="fa fa-download"></i> Scarica JSON completo
                    </a>
                  <?php endif; ?>
                  <?php if ((int) ($selectedEntry['document_id'] ?? 0) > 0): ?>
                    <a class="btn btn-default" href="<?= site_url('admin/sistema-ts/documenti/modifica/' . (int) ($selectedEntry['document_id'] ?? 0)) ?>">
                      <i class="fa fa-file-text-o"></i> Apri documento TS
                    </a>
                  <?php endif; ?>
                </div>

                <div class="row">
                  <div class="col-md-3">
                    <strong>Operazione</strong>
                    <div class="text-muted"><?= esc($operationLabels((string) ($selectedEntry['operation'] ?? ''))) ?></div>
                  </div>
                  <div class="col-md-2">
                    <strong>Stato</strong>
                    <div class="text-muted"><?= esc($statusLabels[$selectedStatusKey] ?? $selectedStatusKey) ?></div>
                  </div>
                  <div class="col-md-3">
                    <strong>Avvio</strong>
                    <div class="text-muted"><?= esc($formatDateTime((string) ($selectedEntry['started_at'] ?? ''))) ?></div>
                  </div>
                  <div class="col-md-2">
                    <strong>Durata</strong>
                    <div class="text-muted"><?= esc((string) ((int) ($selectedEntry['duration_ms'] ?? 0))) ?> ms</div>
                  </div>
                  <div class="col-md-2">
                    <strong>Step</strong>
                    <div class="text-muted"><?= esc((string) ((int) ($selectedEntry['step_count'] ?? 0))) ?></div>
                  </div>
                </div>

                <hr>

                <div class="row">
                  <div class="col-md-3">
                    <strong>Documento</strong>
                    <div class="text-muted">
                      <?php if ((int) ($selectedEntry['document_id'] ?? 0) > 0): ?>
                        #<?= esc((string) ($selectedEntry['document_id'] ?? '')) ?>
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="col-md-3">
                    <strong>Numero documento</strong>
                    <div class="text-muted"><?= esc((string) ($selectedEntry['document_number'] ?? '-')) ?></div>
                  </div>
                  <div class="col-md-3">
                    <strong>Protocollo TS</strong>
                    <div class="text-muted"><?= esc((string) ($selectedEntry['protocol'] ?? '-')) ?></div>
                  </div>
                  <div class="col-md-3">
                    <strong>Esito TS</strong>
                    <div class="text-muted"><?= esc((string) ($selectedEntry['outcome'] ?? '-')) ?></div>
                  </div>
                </div>

                <hr>

                <div class="row">
                  <div class="col-md-6">
                    <strong>Messaggio finale</strong>
                    <div class="text-muted"><?= esc((string) ($selectedEntry['message'] ?? '')) ?></div>
                  </div>
                  <div class="col-md-6">
                    <strong>File summary</strong>
                    <div class="trace-path inline-code"><?= esc((string) ($selectedEntry['summary_path'] ?? '-')) ?></div>
                    <?php if (trim((string) ($selectedEntry['timeline_path'] ?? '')) !== ''): ?>
                      <div style="margin-top:6px;">
                        <strong>Timeline mensile</strong>
                        <div class="trace-path inline-code"><?= esc((string) ($selectedEntry['timeline_path'] ?? '')) ?></div>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <?php if (!empty($selectedEntry['has_exception'])): ?>
                  <div class="alert alert-danger" style="margin-top:16px;">
                    <strong>Eccezione registrata:</strong>
                    <?= esc((string) ($selectedEntry['exception_message'] ?? '')) ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <div class="box box-default">
              <div class="box-header with-border">
                <h3 class="box-title">Timeline operazione</h3>
              </div>
              <div class="box-body">
                <?php $steps = is_array($selectedSummary['steps'] ?? null) ? $selectedSummary['steps'] : []; ?>
                <?php if ($steps === []): ?>
                  <div class="text-muted">Nessuno step disponibile per questo trace.</div>
                <?php else: ?>
                  <?php foreach ($steps as $step): ?>
                    <?php if (!is_array($step)) { continue; } ?>
                    <?php
                      $stepLevel = trim((string) ($step['level'] ?? 'info'));
                      $stepContext = is_array($step['context'] ?? null) ? $step['context'] : [];
                      $stepContextJson = $stepContext !== []
                          ? json_encode($stepContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                          : '';
                    ?>
                    <div class="step-card level-<?= esc($stepLevel) ?>">
                      <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                        <div>
                          <strong><?= esc((string) ($step['message'] ?? 'Step TS')) ?></strong><br>
                          <span class="step-code"><?= esc((string) ($step['code'] ?? '')) ?></span>
                        </div>
                        <div class="text-muted" style="text-align:right;">
                          <?= esc($formatDateTime((string) ($step['created_at'] ?? ''))) ?><br>
                          <?= esc((string) ((int) ($step['elapsed_ms'] ?? 0))) ?> ms
                        </div>
                      </div>
                      <?php if (trim((string) $stepContextJson) !== ''): ?>
                        <pre class="trace-path" style="margin:10px 0 0 0; white-space:pre-wrap;"><?= esc($truncateText((string) $stepContextJson, 1200)) ?></pre>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>

            <?php if ($selectedRawPreview !== ''): ?>
              <div class="box box-default">
                <div class="box-header with-border">
                  <h3 class="box-title">Anteprima JSON sanificato</h3>
                </div>
                <div class="box-body">
                  <p class="text-muted" style="margin-bottom:10px;">
                    Anteprima limitata a <?= esc((string) $rawPreviewLimit) ?> caratteri per restare leggibile in pagina. Per il file completo usa il download sopra.
                  </p>
                  <pre class="json-preview"><?= esc($selectedRawPreview) ?></pre>
                </div>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
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
</body>
</html>
