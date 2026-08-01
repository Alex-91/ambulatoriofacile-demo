<?php
$menu_items = $menu_items ?? ((session()->get('menuDataAdmin')['result'] ?? []));
$tenantScope = is_array($tenantScope ?? null) ? $tenantScope : [];
$dashboard = is_array($dashboard ?? null) ? $dashboard : [];
$moduleStatus = is_array($moduleStatus ?? null) ? $moduleStatus : [];
$profile = is_array($profile ?? null) ? $profile : [];
$summary = is_array($dashboard['summary'] ?? null) ? $dashboard['summary'] : [];
$recentDocuments = is_array($dashboard['recent_documents'] ?? null) ? $dashboard['recent_documents'] : [];
$uiStateLabels = is_array($dashboard['ui_state_labels'] ?? null) ? $dashboard['ui_state_labels'] : [];
$tableAvailable = !empty($dashboard['table_available']);
$billingEnabled = !empty($moduleStatus['billing_enabled']);
$integratedEnabled = !empty($moduleStatus['integrated_enabled']);
$profileName = trim((string) ($profile['profile_name'] ?? ''));
$profilePiva = trim((string) ($profile['owner_piva'] ?? ''));
$profileEnvironment = strtolower(trim((string) ($profile['environment'] ?? 'test')));
$profileEnvironmentLabel = $profileEnvironment === 'production' ? 'Produzione' : 'Test';
$formatDate = static function ($value): string {
    $date = trim((string) $value);
    if ($date === '') {
        return '-';
    }

    try {
        return (new DateTimeImmutable($date))->format('d/m/Y');
    } catch (Throwable $e) {
        return $date;
    }
};
$stateMeta = static function (string $state, array $labels): array {
    $normalizedState = strtolower(trim($state));
    $label = trim((string) ($labels[$normalizedState] ?? ''));

    return match ($normalizedState) {
        'sent' => ['label' => $label !== '' ? $label : 'Inviato', 'class' => 'is-sent'],
        'ready' => ['label' => $label !== '' ? $label : 'Pronto', 'class' => 'is-ready'],
        'rejected' => ['label' => $label !== '' ? $label : 'Errore', 'class' => 'is-error'],
        default => ['label' => $label !== '' ? $label : 'Bozza', 'class' => 'is-draft'],
    };
};
$moduleRelationship = $billingEnabled
    ? ($integratedEnabled ? 'Fatturazione attiva · modalità integrata' : 'Fatturazione attiva · moduli separati')
    : 'Sistema TS autonomo';
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Sistema TS</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/assets/css/billing-ts-ui.css') ?>" rel="stylesheet" />
</head>
<body class="skin-blue sidebar-mini billing-ts-ui module-sistema-ts">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items]) ?>

  <div class="content-wrapper">
    <section class="content ts-dashboard-content">
      <div class="row ts-dashboard-layout">
        <aside class="col-md-3 ts-dashboard-nav">
          <?= view('partials/sidebar_admin', ['menu_items' => $menu_items]) ?>
        </aside>

        <div class="col-md-9 ts-dashboard-main">
          <main class="ts-dashboard">
            <header class="ts-modulebar">
              <div class="ts-modulebar-copy">
                <div class="ts-eyebrow">Modulo</div>
                <div class="ts-title-row">
                  <span class="ts-module-icon"><i class="fa fa-credit-card"></i></span>
                  <div>
                    <div class="ts-title-with-env">
                      <h1>Sistema TS</h1>
                      <span class="ts-environment"><i class="fa fa-flask"></i> <?= esc($profileEnvironmentLabel) ?></span>
                    </div>
                    <p>Trasmissione dei dati di spesa al Sistema Tessera Sanitaria per il 730 precompilato.</p>
                  </div>
                </div>
                <span class="ts-module-chip"><i class="fa fa-link"></i> <?= esc($moduleRelationship) ?></span>
              </div>

              <div class="ts-module-actions">
                <a class="ts-action ts-action-secondary" href="<?= site_url('admin/sistema-ts/documenti') ?>"><i class="fa fa-file-text-o"></i> Lista documenti</a>
                <a class="ts-action ts-action-secondary" href="<?= portal_tenant_space_url('sistema-ts') ?>"><i class="fa fa-cog"></i> Configura profilo</a>
                <a class="ts-action ts-action-secondary" href="<?= site_url('admin/sistema-ts/diagnostica') ?>"><i class="fa fa-stethoscope"></i> Diagnostica</a>
                <a class="ts-action ts-action-primary" href="<?= site_url('admin/sistema-ts/documenti/nuovo') ?>"><i class="fa fa-plus"></i> Nuovo documento TS</a>
              </div>
            </header>

            <div class="ts-statebar" aria-label="Anteprima stato">
              <span>Anteprima stato</span>
              <div class="ts-state-options" role="group" aria-label="Stato della vista">
                <button type="button" class="is-active" aria-pressed="true">Popolato</button>
                <button type="button" aria-pressed="false">Caricamento</button>
                <button type="button" aria-pressed="false">Vuoto</button>
                <button type="button" aria-pressed="false">Errore TS</button>
              </div>
            </div>

            <div class="ts-pipeline" aria-label="Stato documenti Sistema TS">
              <article class="ts-pipeline-card">
                <span>Totali</span>
                <strong><?= (int) ($summary['total_documents'] ?? 0) ?></strong>
                <p>documenti TS</p>
              </article>
              <i class="fa fa-chevron-right ts-pipeline-arrow" aria-hidden="true"></i>
              <article class="ts-pipeline-card ts-pipeline-neutral">
                <span>Bozze</span>
                <strong><?= (int) ($summary['draft_count'] ?? 0) ?></strong>
                <p>da completare</p>
              </article>
              <i class="fa fa-chevron-right ts-pipeline-arrow" aria-hidden="true"></i>
              <article class="ts-pipeline-card">
                <span>Pronti</span>
                <strong><?= (int) ($summary['ready_count'] ?? 0) ?></strong>
                <p>verificati, in attesa di invio</p>
              </article>
              <i class="fa fa-chevron-right ts-pipeline-arrow" aria-hidden="true"></i>
              <article class="ts-pipeline-card">
                <span>Inviati</span>
                <strong><?= (int) ($summary['sent_count'] ?? 0) ?></strong>
                <p>trasmessi al Sistema TS</p>
              </article>
              <i class="fa fa-chevron-right ts-pipeline-arrow" aria-hidden="true"></i>
              <article class="ts-pipeline-card ts-pipeline-neutral">
                <span>Errori</span>
                <strong><?= (int) ($summary['rejected_count'] ?? 0) ?></strong>
                <p>da correggere</p>
              </article>
            </div>

            <div class="ts-dashboard-grid">
              <section class="ts-panel ts-documents-panel">
                <div class="ts-panel-heading">
                  <h2>Ultimi documenti TS</h2>
                  <a class="ts-text-action" href="<?= site_url('admin/sistema-ts/documenti') ?>">Vedi tutti <i class="fa fa-arrow-right"></i></a>
                </div>

                <?php if (!$tableAvailable): ?>
                  <div class="ts-panel-body">
                    <div class="alert alert-warning" style="margin:0;">
                      Le tabelle dei documenti TS non sono ancora disponibili in questo database operativo.
                    </div>
                  </div>
                <?php elseif ($recentDocuments === []): ?>
                  <div class="ts-empty-state">
                    <span class="ts-empty-icon"><i class="fa fa-credit-card"></i></span>
                    <h3>Non hai ancora documenti TS</h3>
                    <p>Crea il primo documento da trasmettere al Sistema Tessera Sanitaria.</p>
                    <a class="ts-action ts-action-primary" href="<?= site_url('admin/sistema-ts/documenti/nuovo') ?>"><i class="fa fa-plus"></i> Nuovo documento TS</a>
                    <a class="ts-empty-secondary" href="<?= portal_tenant_space_url('sistema-ts') ?>"><i class="fa fa-cog"></i> Configura profilo TS</a>
                  </div>
                <?php else: ?>
                  <div class="table-responsive ts-dashboard-table-wrap">
                    <table class="table ts-dashboard-table">
                      <thead>
                        <tr>
                          <th>Numero documento</th>
                          <th>Documento</th>
                          <th>Data</th>
                          <th>Stato</th>
                          <th class="text-right">Azioni</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($recentDocuments as $row): ?>
                          <?php
                            $documentId = (int) ($row['id_ts_document'] ?? 0);
                            $meta = $stateMeta((string) ($row['local_state'] ?? ''), $uiStateLabels);
                            $documentDetail = trim((string) ($row['document_type'] ?? ''));
                            $expenseType = trim((string) ($row['expense_type_code'] ?? ''));
                            $documentDetail = trim($documentDetail . ($expenseType !== '' ? ' · ' . $expenseType : '')) ?: 'Documento TS';
                          ?>
                          <tr>
                            <td><a class="ts-document-number" href="<?= site_url('admin/sistema-ts/documenti/modifica/' . $documentId) ?>"><?= esc((string) ($row['document_number'] ?? '-')) ?></a></td>
                            <td><?= esc($documentDetail) ?></td>
                            <td class="ts-table-muted"><?= esc($formatDate($row['issue_date'] ?? '')) ?></td>
                            <td><span class="ts-status-pill <?= esc($meta['class']) ?>"><i class="fa fa-circle"></i><?= esc($meta['label']) ?></span></td>
                            <td class="text-right"><a class="ts-icon-action" href="<?= site_url('admin/sistema-ts/documenti/modifica/' . $documentId) ?>" aria-label="Apri documento"><i class="fa fa-external-link"></i></a></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </section>

              <aside class="ts-side-rail">
                <section class="ts-panel ts-profile-panel">
                  <div class="ts-profile-eyebrow">Profilo TS attivo</div>
                  <strong><?= esc($profileName !== '' ? $profileName : 'Profilo TS da configurare') ?></strong>
                  <span>P.IVA erogatore <?= esc($profilePiva !== '' ? $profilePiva : 'non impostata') ?></span>
                  <span class="ts-profile-environment"><i class="fa fa-flask"></i> Ambiente: <?= esc($profileEnvironmentLabel) ?></span>
                  <a class="ts-empty-secondary" href="<?= portal_tenant_space_url('sistema-ts') ?>"><i class="fa fa-cog"></i> Configura profilo</a>
                </section>

                <?php if ($billingEnabled): ?>
                  <section class="ts-coexist-panel">
                    <p>Modulo autonomo, convive con Fatturazione.</p>
                    <a class="ts-text-action ts-billing-action" href="<?= site_url('admin/fatturazione') ?>">Apri modulo Fatturazione <i class="fa fa-arrow-right"></i></a>
                  </section>
                <?php endif; ?>
              </aside>
            </div>
          </main>
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
