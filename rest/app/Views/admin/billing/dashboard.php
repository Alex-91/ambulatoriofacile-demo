<?php
$menu_items = $menu_items ?? ((session()->get('menuDataAdmin')['result'] ?? []));
$documentsDashboard = is_array($documentsDashboard ?? null) ? $documentsDashboard : [];
$summary = is_array($documentsDashboard['summary'] ?? null) ? $documentsDashboard['summary'] : [];
$recentDocuments = is_array($documentsDashboard['recent_documents'] ?? null) ? $documentsDashboard['recent_documents'] : [];
$documentsTableAvailable = !empty($documentsDashboard['table_available']);
$documentsSchemaMessage = trim((string) ($documentsDashboard['schema_message'] ?? ''));
$environmentLabel = defined('ENVIRONMENT') && ENVIRONMENT === 'production' ? 'Produzione' : 'Locale';
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Fatturazione</title>
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
    <section class="content billing-dashboard-content">
      <div class="row billing-dashboard-layout">
        <aside class="col-md-3 billing-dashboard-nav">
          <?= view('partials/sidebar_admin', ['menu_items' => $menu_items]) ?>
        </aside>

        <main class="col-md-9">
          <div class="billing-dashboard">
            <header class="billing-modulebar">
              <div class="billing-modulebar-copy">
                <div class="billing-eyebrow">Modulo</div>
                <div class="billing-title-row">
                  <span class="billing-module-icon"><i class="fa fa-file-text-o"></i></span>
                  <div>
                    <h1>Fatturazione</h1>
                    <p>Documenti e ricevute per i pazienti dello studio.</p>
                  </div>
                  <span class="billing-environment"><?= esc($environmentLabel) ?></span>
                </div>
              </div>
              <div class="billing-module-actions">
                <a class="billing-action billing-action-secondary" href="<?= site_url('admin/fatturazione-scadenzario') ?>">
                  <i class="fa fa-calendar"></i> Scadenzario
                </a>
                <a class="billing-action billing-action-secondary" href="<?= site_url('admin/fatturazione-statistiche') ?>">
                  <i class="fa fa-bar-chart"></i> Statistiche e report
                </a>
                <a class="billing-action billing-action-secondary" href="<?= site_url('admin/fatturazione-documento') ?>">
                  <i class="fa fa-file-text-o"></i> Personalizza documento
                </a>
                <a class="billing-action billing-action-secondary" href="<?= site_url('admin/fatturazione-documenti') ?>">
                  <i class="fa fa-archive"></i> Archivio
                </a>
                <a class="billing-action billing-action-primary" href="<?= site_url('admin/fatturazione-documenti/nuovo') ?>">
                  <i class="fa fa-plus"></i> Nuovo documento
                </a>
              </div>
            </header>

            <div class="billing-kpi-grid">
              <article class="billing-kpi-card">
                <span>Documenti totali</span>
                <strong><?= (int) ($summary['total_documents'] ?? 0) ?></strong>
                <p>emessi in totale</p>
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
                <p>fatture pagate</p>
              </article>
            </div>

            <div class="billing-main-grid">
              <section class="billing-panel billing-documents-panel">
                <div class="billing-panel-heading">
                  <h2>Ultimi documenti</h2>
                  <a class="billing-text-action" href="<?= site_url('admin/fatturazione-documenti') ?>">
                    Vedi tutte <i class="fa fa-arrow-right"></i>
                  </a>
                </div>

                <?php if (!$documentsTableAvailable): ?>
                  <div class="billing-panel-body">
                    <div class="alert alert-warning" style="margin:0;">
                      <?= esc($documentsSchemaMessage !== '' ? $documentsSchemaMessage : 'L’archivio documenti non è ancora disponibile nel database runtime corrente.') ?>
                    </div>
                  </div>
                <?php elseif ($recentDocuments === []): ?>
                  <div class="billing-empty-state">
                    <span class="billing-empty-icon"><i class="fa fa-file-text-o"></i></span>
                    <h3>Non hai ancora emesso documenti</h3>
                    <p>I documenti e le ricevute che emetti compaiono qui.</p>
                    <a class="billing-action billing-action-primary" href="<?= site_url('admin/fatturazione-documenti/nuovo') ?>">
                      <i class="fa fa-plus"></i> Nuovo documento
                    </a>
                  </div>
                <?php else: ?>
                  <div class="table-responsive billing-dashboard-table-wrap">
                    <table class="table billing-dashboard-table">
                      <thead>
                        <tr>
                          <th>Numero</th>
                          <th>Cliente</th>
                          <th>Data</th>
                          <th class="text-right">Totale</th>
                          <th>Stato</th>
                          <th class="text-right">Azioni</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($recentDocuments as $row): ?>
                          <?php
                            $documentId = (int) ($row['id_billing_document'] ?? 0);
                            $isIssued = (string) ($row['local_state'] ?? '') === 'issued';
                          ?>
                          <tr>
                            <td>
                              <a class="billing-document-number" href="<?= site_url('admin/fatturazione-documenti/modifica/' . $documentId) ?>">
                                <?= esc((string) ($row['document_number'] ?? '-')) ?>
                              </a>
                            </td>
                            <td><?= esc((string) ($row['patient_name'] ?? '-')) ?></td>
                            <td class="billing-table-muted"><?= esc((string) ($row['issue_date'] ?? '-')) ?></td>
                            <td class="text-right billing-table-total">&euro; <?= number_format((float) ($row['amount_total'] ?? 0), 2, ',', '.') ?></td>
                            <td><span class="billing-state-pill <?= $isIssued ? 'is-issued' : 'is-draft' ?>"><?= esc((string) ($row['local_state_label'] ?? $row['local_state'] ?? '-')) ?></span></td>
                            <td class="text-right">
                              <a class="billing-icon-action" href="<?= site_url('admin/fatturazione-documenti/modifica/' . $documentId) ?>" aria-label="Apri documento">
                                <i class="fa fa-eye"></i>
                              </a>
                              <a class="billing-icon-action" href="<?= site_url('admin/fatturazione-documenti/pdf/' . $documentId) ?>" aria-label="Scarica PDF">
                                <i class="fa fa-download"></i>
                              </a>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </section>

            </div>

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
</body>
</html>
