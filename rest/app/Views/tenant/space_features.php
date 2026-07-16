<?php
helper('portal');

$menuDataAdmin = session()->get('menuDataAdmin');
$sidebarMenuItems = is_array($menuDataAdmin['result'] ?? null) ? $menuDataAdmin['result'] : [];

if (empty($menu_items) || !is_array($menu_items)) {
    $menu_items = $sidebarMenuItems !== [] ? $sidebarMenuItems : (session()->get('header_menu_items') ?? []);
}

$errors = is_array($errors ?? null) ? $errors : [];
$success = $success ?? null;
$featureStates = is_array($featureStates ?? null) ? $featureStates : [];
$tenantContext = $tenantContext ?? null;
$agendaHomeBlockOrderSettings = is_array($agendaHomeBlockOrderSettings ?? null) ? $agendaHomeBlockOrderSettings : [];
$agendaHomeBlockOrderRows = is_array($agendaHomeBlockOrderSettings['block_rows'] ?? null)
    ? $agendaHomeBlockOrderSettings['block_rows']
    : [];
$agendaHomeBlockOrderAvailable = !empty($agendaHomeBlockOrderSettings['order_management_available']);
$oldAgendaHomeBlockOrderEnabled = old('agenda_home_block_order_enabled');
$oldAgendaHomeBlockOrderKeys = old('agenda_home_block_order_keys');
$oldAgendaHomeBlockOrderKeys = is_array($oldAgendaHomeBlockOrderKeys) ? $oldAgendaHomeBlockOrderKeys : [];
$oldAgendaHomeBlockOrderColumns = old('agenda_home_block_columns');
$oldAgendaHomeBlockOrderColumns = is_array($oldAgendaHomeBlockOrderColumns) ? $oldAgendaHomeBlockOrderColumns : [];
$agendaHomeBlockDefaultOrderKeys = array_values(array_filter(array_map(
    static fn($value): string => trim((string) $value),
    (array) ($agendaHomeBlockOrderSettings['default_order_keys'] ?? [])
), static fn(string $value): bool => $value !== ''));
$agendaHomeBlockColumnLabels = [
    'main' => 'colonna principale',
    'left' => 'colonna sinistra con menu',
    'hidden' => 'nascosto',
];
$agendaProfessionalOrderSettings = is_array($agendaProfessionalOrderSettings ?? null) ? $agendaProfessionalOrderSettings : [];
$agendaProfessionalOrderRows = is_array($agendaProfessionalOrderSettings['doctor_rows'] ?? null)
    ? $agendaProfessionalOrderSettings['doctor_rows']
    : [];
$agendaProfessionalOrderAvailable = !empty($agendaProfessionalOrderSettings['order_management_available']);
$oldAgendaProfessionalOrderEnabled = old('agenda_professional_order_enabled');
$oldAgendaProfessionalOrderIds = old('agenda_professional_order_ids');
$oldAgendaProfessionalOrderIds = is_array($oldAgendaProfessionalOrderIds) ? $oldAgendaProfessionalOrderIds : [];
$agendaProfessionalDefaultOrderIds = array_values(array_filter(array_map(
    'intval',
    (array) ($agendaProfessionalOrderSettings['default_order_ids'] ?? [])
), static fn(int $value): bool => $value > 0));
$teamDayColumnColorSettings = is_array($teamDayColumnColorSettings ?? null) ? $teamDayColumnColorSettings : [];
$appointmentNotificationsAvailable = false;
$appointmentNotificationsEntitled = false;
$billingWorkspaceAccessible = !empty($billingWorkspaceAccessible);
$tsConfigurationAccessible = !empty($tsConfigurationAccessible);
$teamDayColorRows = is_array($teamDayColumnColorSettings['doctor_rows'] ?? null)
    ? $teamDayColumnColorSettings['doctor_rows']
    : [];
$teamDayColorsAvailable = !empty($teamDayColumnColorSettings['color_management_available']);
$teamDayColorsActive = !empty($teamDayColumnColorSettings['tenant_colors_enabled']);
$teamDayFeatureEnabled = !empty($teamDayColumnColorSettings['feature_enabled']);
$oldTeamDayColorsEnabled = old('team_day_column_colors_enabled');
$oldTeamDayCustomEnabledMap = old('team_day_column_color_custom_enabled');
$oldTeamDayCustomEnabledMap = is_array($oldTeamDayCustomEnabledMap) ? $oldTeamDayCustomEnabledMap : [];
$oldTeamDayColorValueMap = old('team_day_column_color_value');
$oldTeamDayColorValueMap = is_array($oldTeamDayColorValueMap) ? $oldTeamDayColorValueMap : [];

$reorderRowsByScalarKey = static function (array $rows, array $orderedValues, string $keyField, callable $normalize): array {
    $orderedValues = array_values(array_filter(array_map($normalize, $orderedValues), static fn($value): bool => $value !== null));
    if ($rows === [] || $orderedValues === []) {
        return $rows;
    }

    $rowsByKey = [];
    $remainingRows = [];

    foreach ($rows as $row) {
        $rowValue = $normalize($row[$keyField] ?? null);
        if ($rowValue === null) {
            continue;
        }

        $rowKey = (string) $rowValue;
        $rowsByKey[$rowKey] = $row;
        $remainingRows[$rowKey] = $row;
    }

    $sortedRows = [];
    foreach ($orderedValues as $orderedValue) {
        $orderedKey = (string) $orderedValue;
        if (!isset($rowsByKey[$orderedKey])) {
            continue;
        }

        $sortedRows[] = $rowsByKey[$orderedKey];
        unset($remainingRows[$orderedKey]);
    }

    foreach ($remainingRows as $row) {
        $sortedRows[] = $row;
    }

    return $sortedRows !== [] ? $sortedRows : $rows;
};

if ($oldAgendaProfessionalOrderIds !== []) {
    $agendaProfessionalOrderRows = $reorderRowsByScalarKey(
        $agendaProfessionalOrderRows,
        $oldAgendaProfessionalOrderIds,
        'id_dot',
        static function ($value): ?int {
            $normalized = (int) $value;
            return $normalized > 0 ? $normalized : null;
        }
    );
}

if ($oldAgendaHomeBlockOrderKeys !== []) {
    $agendaHomeBlockOrderRows = $reorderRowsByScalarKey(
        $agendaHomeBlockOrderRows,
        $oldAgendaHomeBlockOrderKeys,
        'key',
        static function ($value): ?string {
            $normalized = trim((string) $value);
            return $normalized !== '' ? $normalized : null;
        }
    );
}

$manageableRows = [];
$lockedRows = [];
$unavailableRows = [];

foreach ($featureStates as $row) {
    $entitled = (bool) ($row['entitlement_enabled'] ?? false);
    $tenantManaged = (bool) ($row['is_tenant_managed'] ?? false);
    $featureKey = trim((string) ($row['feature_key'] ?? ''));

    if ($featureKey === 'appointment_notifications') {
        $appointmentNotificationsEntitled = (bool) ($row['entitlement_enabled'] ?? false);
        $appointmentNotificationsAvailable = (bool) ($row['effective_enabled'] ?? false);
    }

    if (!$entitled) {
        $unavailableRows[] = $row;
        continue;
    }

    if ($tenantManaged) {
        $manageableRows[] = $row;
        continue;
    }

    $lockedRows[] = $row;
}

$hasSupplementalSpaceControls = ($agendaHomeBlockOrderAvailable && $agendaHomeBlockOrderRows !== [])
    || ($agendaProfessionalOrderAvailable && $agendaProfessionalOrderRows !== [])
    || ($teamDayColorsAvailable && $teamDayColorRows !== []);
$showGenericEmptyMessage = ($manageableRows === []) && !$hasSupplementalSpaceControls;
$canSubmitSpaceSettings = ($manageableRows !== []) || $hasSupplementalSpaceControls;
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Funzioni Studio</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <style>
    .nav-pills.nav-stacked > li.active > a {
      background-color:#2c8895;
      color:#fff;
    }
    .intro-box {
      border: 1px solid #dbe8eb;
      border-radius: 12px;
      padding: 18px 20px;
      background: linear-gradient(135deg, #f8fcfc 0%, #eff7f8 100%);
      margin-bottom: 16px;
    }
    .feature-card {
      border: 1px solid #e5ecee;
      border-radius: 10px;
      padding: 14px 16px;
      min-height: 170px;
      margin-bottom: 14px;
      background: #fff;
    }
    .feature-card h4 {
      margin-top: 0;
      margin-bottom: 8px;
      font-size: 17px;
    }
    .feature-card p {
      color: #667b80;
      min-height: 44px;
      margin-bottom: 10px;
    }
    .status-chip {
      display: inline-block;
      margin: 0 8px 8px 0;
      padding: 6px 10px;
      border-radius: 999px;
      background: #eef5f6;
      color: #1b6770;
      font-size: 12px;
      font-weight: 600;
    }
    .team-day-colors-box {
      margin-top: 18px;
      border: 1px solid #dbe8eb;
      border-radius: 12px;
      background: linear-gradient(180deg, #fbfefe 0%, #f6fbfc 100%);
      padding: 16px;
    }
    .team-day-colors-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 12px;
      margin-top: 14px;
    }
    .team-day-color-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      padding: 14px;
      border: 1px solid #dde9ed;
      border-radius: 12px;
      background: #fff;
    }
    .team-day-color-row.is-custom {
      box-shadow: 0 10px 22px rgba(30, 82, 92, 0.08);
      border-color: #c6dde3;
    }
    .team-day-color-main {
      min-width: 0;
    }
    .team-day-color-title {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 14px;
      font-weight: 700;
      color: #223a40;
      line-height: 1.35;
    }
    .team-day-color-meta {
      margin-top: 4px;
      font-size: 12px;
      color: #647b80;
      line-height: 1.45;
    }
    .team-day-color-swatch {
      width: 16px;
      height: 16px;
      flex: 0 0 16px;
      border-radius: 999px;
      border: 1px solid rgba(31, 45, 61, 0.14);
      box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.28);
      background: var(--team-day-color, #3C8DBC);
    }
    .team-day-color-actions {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }
    .team-day-color-picker {
      width: 42px;
      height: 32px;
      padding: 0;
      border: 1px solid #d7e4ea;
      border-radius: 10px;
      background: #fff;
      cursor: pointer;
    }
    .team-day-colors-note {
      margin-top: 10px;
      color: #60777c;
      font-size: 12px;
      line-height: 1.5;
    }
    .agenda-order-box {
      margin-top: 18px;
      border: 1px solid #dbe8eb;
      border-radius: 12px;
      background: linear-gradient(180deg, #fbfefe 0%, #f5fbfc 100%);
      padding: 16px;
    }
    .agenda-order-list {
      margin-top: 14px;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .agenda-order-row {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 14px;
      border: 1px solid #dde9ed;
      border-radius: 12px;
      background: #fff;
    }
    .agenda-order-rank {
      width: 34px;
      height: 34px;
      flex: 0 0 34px;
      border-radius: 999px;
      background: #e8f4f6;
      color: #1a6a74;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 14px;
    }
    .agenda-order-main {
      min-width: 0;
      flex: 1 1 auto;
    }
    .agenda-order-title {
      font-size: 14px;
      font-weight: 700;
      color: #223a40;
      line-height: 1.35;
    }
    .agenda-order-meta {
      margin-top: 4px;
      font-size: 12px;
      color: #647b80;
      line-height: 1.5;
    }
    .agenda-order-actions {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }
    .agenda-order-column-picker {
      min-width: 180px;
      text-align: left;
    }
    .agenda-order-column-picker-label {
      display: block;
      margin: 0 0 4px;
      color: #60777c;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .agenda-order-actions .btn {
      min-width: 40px;
    }
    .agenda-order-note {
      margin-top: 10px;
      color: #60777c;
      font-size: 12px;
      line-height: 1.5;
    }
    .agenda-order-toolbar {
      margin-top: 12px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }
    @media (max-width: 767px) {
      .agenda-order-row {
        align-items: flex-start;
        flex-wrap: wrap;
      }
      .agenda-order-column-picker {
        width: 100%;
      }
      .agenda-order-actions {
        width: 100%;
        justify-content: flex-start;
      }
    }
  </style>
</head>

<body class="skin-blue sidebar-mini">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items, 'portal_console_header' => false]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Funzioni dello studio</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Qui il responsabile dello studio decide quali funzioni attivare per il proprio studio, entro i limiti concessi dalla piattaforma.
      </p>
    </section>

    <section class="content">
      <div class="row">
        <div class="col-md-3">
          <?= view('partials/sidebar_admin', ['menu_items' => $sidebarMenuItems]) ?>
        </div>

        <div class="col-md-9">
          <?php if ($success): ?>
            <div class="alert alert-success"><?= esc((string) $success) ?></div>
          <?php endif; ?>
          <?php if (!empty($errors['generic'])): ?>
            <div class="alert alert-danger"><?= esc((string) $errors['generic']) ?></div>
          <?php endif; ?>

          <div class="intro-box">
            <h3 style="margin-top:0; margin-bottom:8px;">
              Studio attivo: <?= esc((string) ($tenantContext->tenantName ?? '')) ?>
            </h3>
            <p style="margin:0 0 12px 0; color:#52676c;">
              Le funzioni che vedi qui sono già state concesse dal pacchetto o dalla configurazione centrale. Tu puoi gestire solo quelle segnate come autonome.
            </p>
            <span class="status-chip">Gestibili da te: <?= count($manageableRows) ?></span>
            <span class="status-chip">Centrali: <?= count($lockedRows) ?></span>
            <span class="status-chip">Non incluse: <?= count($unavailableRows) ?></span>
            <div style="margin-top:12px;">
              <?php if ($appointmentNotificationsAvailable): ?>
                <a class="btn btn-default" href="<?= portal_tenant_space_url('notifiche-appuntamenti') ?>">
                  <i class="fa fa-comments"></i> Apri centro notifiche appuntamenti
                </a>
              <?php elseif ($appointmentNotificationsEntitled): ?>
                <span class="label label-warning" style="display:inline-block; padding:8px 12px;">
                  Centro notifiche disponibile ma non ancora attivo per questo studio
                </span>
              <?php else: ?>
                <span class="label label-default" style="display:inline-block; padding:8px 12px;">
                  Centro notifiche non incluso nel pacchetto attuale
                </span>
              <?php endif; ?>
              <?php if ($billingWorkspaceAccessible): ?>
                <a class="btn btn-default" href="<?= portal_tenant_space_url('fatturazione') ?>" style="margin-left:8px;">
                  <i class="fa fa-calculator"></i> Apri modulo fatturazione
                </a>
              <?php endif; ?>
              <?php if ($tsConfigurationAccessible): ?>
                <a class="btn btn-default" href="<?= portal_tenant_space_url('sistema-ts') ?>" style="margin-left:8px;">
                  <i class="fa fa-file-text-o"></i> Apri configurazione Sistema TS
                </a>
              <?php endif; ?>
            </div>
          </div>

          <div class="box box-success">
            <div class="box-header with-border">
              <h3 class="box-title">Funzioni gestibili dal responsabile dello studio</h3>
            </div>
            <form method="post" action="<?= portal_tenant_space_url('funzioni/save') ?>">
              <?= csrf_field() ?>
              <div class="box-body">
                <?php if ($showGenericEmptyMessage): ?>
                  <div class="alert alert-info" style="margin-bottom:0;">
                    In questo momento non ci sono funzioni gestibili in autonomia per il tuo studio.
                  </div>
                <?php elseif ($manageableRows !== []): ?>
                  <input type="hidden" name="tenant_managed_features_form" value="1">
                  <div class="row">
                    <?php foreach ($manageableRows as $row): ?>
                      <?php
                        $featureKey = (string) ($row['feature_key'] ?? '');
                        $enabled = (bool) ($row['effective_enabled'] ?? false);
                        $sourceLabel = ($row['tenant_preference_enabled'] ?? null) === null ? 'default spazio' : 'personalizzata';
                      ?>
                      <div class="col-md-4">
                        <div class="feature-card">
                          <h4><i class="fa <?= esc((string) ($row['icon_class'] ?? 'fa-toggle-on')) ?>"></i> <?= esc((string) ($row['feature_name'] ?? $featureKey)) ?></h4>
                          <p><?= esc((string) ($row['description'] ?? '')) ?></p>
                          <div class="checkbox" style="margin:0 0 10px 0;">
                            <label>
                              <input type="checkbox" name="enabled_features[]" value="<?= esc($featureKey) ?>" <?= $enabled ? 'checked' : '' ?>>
                              Funzione attiva per questo studio
                            </label>
                          </div>
                          <span class="label label-<?= $enabled ? 'success' : 'default' ?>">
                            <?= $enabled ? 'attiva' : 'spenta' ?>
                          </span>
                          <span class="label label-info"><?= esc($sourceLabel) ?></span>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

                <?php if ($agendaHomeBlockOrderAvailable && $agendaHomeBlockOrderRows !== []): ?>
                  <div class="agenda-order-box">
                    <input type="hidden" name="agenda_home_block_order_form" value="1">
                    <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                      <div>
                        <h4 style="margin:0 0 6px 0;">Ordine blocchi home agenda</h4>
                        <p style="margin:0; color:#587075;">
                          Qui decidi in quale sequenza mostrare i blocchi principali della home agenda dello studio: scelta professionista, ricerca visite del paziente, note del giorno, agenda e memo. Per ogni blocco puoi anche scegliere se lasciarlo nella colonna principale, spostarlo nella colonna sinistra dove si trovano gia menu e filtri, oppure nasconderlo del tutto.
                        </p>
                      </div>
                      <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <span class="label label-<?= (((string) $oldAgendaHomeBlockOrderEnabled === '1') || ($oldAgendaHomeBlockOrderEnabled === null && !empty($agendaHomeBlockOrderSettings['tenant_order_enabled']))) ? 'success' : 'default' ?>">
                          <?= (((string) $oldAgendaHomeBlockOrderEnabled === '1') || ($oldAgendaHomeBlockOrderEnabled === null && !empty($agendaHomeBlockOrderSettings['tenant_order_enabled']))) ? 'ordine personalizzato attivo' : 'ordine standard attivo' ?>
                        </span>
                        <span class="label label-info">concesso dalla piattaforma</span>
                      </div>
                    </div>

                    <div class="checkbox" style="margin:14px 0 0 0;">
                      <label>
                        <input
                          type="checkbox"
                          name="agenda_home_block_order_enabled"
                          value="1"
                          <?= (((string) $oldAgendaHomeBlockOrderEnabled === '1') || ($oldAgendaHomeBlockOrderEnabled === null && !empty($agendaHomeBlockOrderSettings['tenant_order_enabled']))) ? 'checked' : '' ?>
                        >
                        Usa questo ordine personalizzato nella home agenda dello studio
                      </label>
                    </div>

                    <div class="agenda-order-toolbar">
                      <div class="text-muted" style="font-size:12px;">
                        Usa le frecce per spostare ogni blocco su e giu, poi scegli se deve stare nella colonna principale, nella colonna sinistra del menu oppure restare nascosto.
                      </div>
                      <button type="button" class="btn btn-default btn-sm js-order-reset" id="agendaHomeBlockOrderReset">
                        <i class="fa fa-refresh"></i> Ripristina ordine standard
                      </button>
                    </div>

                    <div
                      class="agenda-order-list js-order-list"
                      id="agendaHomeBlockOrderList"
                      data-default-order="<?= esc(implode(',', $agendaHomeBlockDefaultOrderKeys), 'attr') ?>"
                      data-input-name="agenda_home_block_order_keys[]"
                      data-input-container-id="agendaHomeBlockOrderInputs"
                      data-reset-button-id="agendaHomeBlockOrderReset"
                    >
                      <?php foreach ($agendaHomeBlockOrderRows as $row): ?>
                        <?php
                          $blockKey = trim((string) ($row['key'] ?? ''));
                          $defaultPosition = (int) ($row['default_position'] ?? 0);
                          $savedPosition = (int) ($row['saved_position'] ?? 0);
                          $defaultColumn = trim((string) ($row['default_column'] ?? 'main'));
                          $savedColumn = trim((string) ($row['saved_column'] ?? $defaultColumn));
                          $selectedColumn = trim((string) ($oldAgendaHomeBlockOrderColumns[$blockKey] ?? $savedColumn));
                          if (!isset($agendaHomeBlockColumnLabels[$defaultColumn])) {
                              $defaultColumn = 'main';
                          }
                          if (!isset($agendaHomeBlockColumnLabels[$savedColumn])) {
                              $savedColumn = $defaultColumn;
                          }
                          if (!isset($agendaHomeBlockColumnLabels[$selectedColumn])) {
                              $selectedColumn = $savedColumn;
                          }
                          $defaultColumnLabel = (string) ($agendaHomeBlockColumnLabels[$defaultColumn] ?? 'colonna principale');
                          $savedColumnLabel = (string) ($agendaHomeBlockColumnLabels[$savedColumn] ?? $defaultColumnLabel);
                        ?>
                        <div class="agenda-order-row" data-order-value="<?= esc($blockKey, 'attr') ?>">
                          <span class="agenda-order-rank js-order-rank"><?= esc((string) $savedPosition) ?></span>
                          <div class="agenda-order-main">
                            <div class="agenda-order-title">
                              <?= esc((string) ($row['label'] ?? $blockKey)) ?>
                            </div>
                            <div class="agenda-order-meta">
                              <?= ($savedPosition !== $defaultPosition || $savedColumn !== $defaultColumn)
                                  ? 'Layout standard #' . $defaultPosition . ' in ' . $defaultColumnLabel . '. Layout personalizzato salvato #' . $savedPosition . ' in ' . $savedColumnLabel . '.'
                                  : 'Al momento coincide con il layout standard (#' . $defaultPosition . ' in ' . $defaultColumnLabel . ').' ?>
                              <?php if (trim((string) ($row['description'] ?? '')) !== ''): ?>
                                <br><?= esc((string) $row['description']) ?>
                              <?php endif; ?>
                            </div>
                          </div>
                          <div class="agenda-order-actions">
                            <div class="agenda-order-column-picker">
                              <label class="agenda-order-column-picker-label" for="agenda-home-block-column-<?= esc($blockKey, 'attr') ?>">
                                Colonna
                              </label>
                              <select
                                class="form-control input-sm js-order-column"
                                id="agenda-home-block-column-<?= esc($blockKey, 'attr') ?>"
                                name="agenda_home_block_columns[<?= esc($blockKey, 'attr') ?>]"
                                data-default-column="<?= esc($defaultColumn, 'attr') ?>"
                              >
                                <option value="main" <?= $selectedColumn === 'main' ? 'selected' : '' ?>>Principale</option>
                                <option value="left" <?= $selectedColumn === 'left' ? 'selected' : '' ?>>Sinistra menu</option>
                                <option value="hidden" <?= $selectedColumn === 'hidden' ? 'selected' : '' ?>>Nascosto</option>
                              </select>
                            </div>
                            <button type="button" class="btn btn-default btn-sm js-order-up" title="Sposta su">
                              <i class="fa fa-arrow-up"></i>
                            </button>
                            <button type="button" class="btn btn-default btn-sm js-order-down" title="Sposta giu">
                              <i class="fa fa-arrow-down"></i>
                            </button>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>

                    <div id="agendaHomeBlockOrderInputs"></div>

                    <div class="agenda-order-note">
                      Se lasci l opzione spenta, la home agenda continua a usare la disposizione standard. La sequenza, le colonne e gli eventuali blocchi nascosti che prepari qui restano comunque salvati e pronti da riattivare quando vuoi.
                    </div>
                  </div>
                <?php endif; ?>

                <?php if ($agendaProfessionalOrderAvailable && $agendaProfessionalOrderRows !== []): ?>
                  <div class="agenda-order-box">
                    <input type="hidden" name="agenda_professional_order_form" value="1">
                    <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                      <div>
                        <h4 style="margin:0 0 6px 0;">Ordine professionisti in agenda</h4>
                        <p style="margin:0; color:#587075;">
                          Qui decidi in quale sequenza mostrare i professionisti nei selettori agenda e nella vista Giorno Team. Se arrivano nuovi medici, li aggiungiamo automaticamente in coda mantenendo l ordine base finche non li sistemi.
                        </p>
                      </div>
                      <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <span class="label label-<?= (((string) $oldAgendaProfessionalOrderEnabled === '1') || ($oldAgendaProfessionalOrderEnabled === null && !empty($agendaProfessionalOrderSettings['tenant_order_enabled']))) ? 'success' : 'default' ?>">
                          <?= (((string) $oldAgendaProfessionalOrderEnabled === '1') || ($oldAgendaProfessionalOrderEnabled === null && !empty($agendaProfessionalOrderSettings['tenant_order_enabled']))) ? 'ordine personalizzato attivo' : 'ordine alfabetico attivo' ?>
                        </span>
                        <span class="label label-info">concesso dalla piattaforma</span>
                      </div>
                    </div>

                    <div class="checkbox" style="margin:14px 0 0 0;">
                      <label>
                        <input
                          type="checkbox"
                          name="agenda_professional_order_enabled"
                          value="1"
                          <?= (((string) $oldAgendaProfessionalOrderEnabled === '1') || ($oldAgendaProfessionalOrderEnabled === null && !empty($agendaProfessionalOrderSettings['tenant_order_enabled']))) ? 'checked' : '' ?>
                        >
                        Usa questo ordine personalizzato in tutta l agenda dello studio
                      </label>
                    </div>

                    <div class="agenda-order-toolbar">
                      <div class="text-muted" style="font-size:12px;">
                        Usa le frecce per spostare ogni professionista su e giu.
                      </div>
                      <button type="button" class="btn btn-default btn-sm js-order-reset" id="agendaProfessionalOrderReset">
                        <i class="fa fa-refresh"></i> Ripristina ordine base
                      </button>
                    </div>

                    <div
                      class="agenda-order-list js-order-list"
                      id="agendaProfessionalOrderList"
                      data-default-order="<?= esc(implode(',', $agendaProfessionalDefaultOrderIds), 'attr') ?>"
                      data-input-name="agenda_professional_order_ids[]"
                      data-input-container-id="agendaProfessionalOrderInputs"
                      data-reset-button-id="agendaProfessionalOrderReset"
                    >
                      <?php foreach ($agendaProfessionalOrderRows as $row): ?>
                        <?php
                          $doctorId = (int) ($row['id_dot'] ?? 0);
                          $defaultPosition = (int) ($row['default_position'] ?? 0);
                          $savedPosition = (int) ($row['saved_position'] ?? 0);
                        ?>
                        <div class="agenda-order-row" data-order-value="<?= esc((string) $doctorId, 'attr') ?>">
                          <span class="agenda-order-rank js-order-rank"><?= esc((string) $savedPosition) ?></span>
                          <div class="agenda-order-main">
                            <div class="agenda-order-title">
                              <?= esc((string) ($row['label'] ?? ('Professionista ' . $doctorId))) ?>
                            </div>
                            <div class="agenda-order-meta">
                              <?= $savedPosition !== $defaultPosition
                                  ? 'Base alfabetica #' . $defaultPosition . '. Ordine personalizzato salvato #' . $savedPosition . '.'
                                  : 'Al momento coincide con l ordine base alfabetico (#' . $defaultPosition . ').' ?>
                            </div>
                          </div>
                          <div class="agenda-order-actions">
                            <button type="button" class="btn btn-default btn-sm js-order-up" title="Sposta su">
                              <i class="fa fa-arrow-up"></i>
                            </button>
                            <button type="button" class="btn btn-default btn-sm js-order-down" title="Sposta giu">
                              <i class="fa fa-arrow-down"></i>
                            </button>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>

                    <div id="agendaProfessionalOrderInputs"></div>

                    <div class="agenda-order-note">
                      Se lasci l opzione spenta, l agenda continua a usare l ordine standard. La sequenza che prepari qui resta comunque salvata e pronta da riattivare quando vuoi.
                    </div>
                  </div>
                <?php endif; ?>

                <?php if ($teamDayColorsAvailable && $teamDayColorRows !== []): ?>
                  <div class="team-day-colors-box">
                    <input type="hidden" name="team_day_column_color_form" value="1">
                    <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                      <div>
                        <h4 style="margin:0 0 6px 0;">Colori colonne Giorno Team</h4>
                        <p style="margin:0; color:#587075;">
                          Se attivi questa opzione, ogni professionista nella vista Giorno Team usa un colore riconoscibile. Se non personalizzi una colonna, applichiamo una palette consigliata stabile.
                        </p>
                      </div>
                      <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <span class="label label-<?= $teamDayColorsActive ? 'success' : 'default' ?>">
                          <?= $teamDayColorsActive ? 'colori attivi' : 'colori spenti' ?>
                        </span>
                        <span class="label label-<?= $teamDayFeatureEnabled ? 'info' : 'warning' ?>">
                          <?= $teamDayFeatureEnabled ? 'Giorno Team attivo' : 'Giorno Team spento nello spazio' ?>
                        </span>
                      </div>
                    </div>

                    <div class="checkbox" style="margin:14px 0 0 0;">
                      <label>
                        <input
                          type="checkbox"
                          name="team_day_column_colors_enabled"
                          value="1"
                          <?= ((string) $oldTeamDayColorsEnabled === '1' || ($oldTeamDayColorsEnabled === null && $teamDayColorsActive)) ? 'checked' : '' ?>
                        >
                        Usa colori dedicati per le colonne dei professionisti nella vista Giorno Team
                      </label>
                    </div>

                    <?php if (!$teamDayFeatureEnabled): ?>
                      <div class="alert alert-info" style="margin:12px 0 0 0;">
                        La vista Giorno Team e al momento spenta nello spazio. Puoi comunque preparare ora i colori, cosi saranno pronti appena la funzione verra attivata.
                      </div>
                    <?php endif; ?>

                    <div class="team-day-colors-grid">
                      <?php foreach ($teamDayColorRows as $row): ?>
                        <?php
                          $doctorId = (int) ($row['id_dot'] ?? 0);
                          $hasCustomColor = array_key_exists((string) $doctorId, $oldTeamDayCustomEnabledMap)
                              ? ((int) ($oldTeamDayCustomEnabledMap[$doctorId] ?? 0) === 1)
                              : !empty($row['has_custom_color']);
                          $pickerValue = trim((string) ($oldTeamDayColorValueMap[$doctorId] ?? ''));
                          if ($pickerValue === '') {
                              $pickerValue = $hasCustomColor
                                  ? (string) ($row['custom_color'] ?? '')
                                  : (string) ($row['suggested_color'] ?? '');
                          }
                          if ($pickerValue === '') {
                              $pickerValue = '#3C8DBC';
                          }
                          $previewColor = $hasCustomColor
                              ? $pickerValue
                              : (string) ($row['suggested_color'] ?? $pickerValue);
                        ?>
                        <div class="team-day-color-row<?= $hasCustomColor ? ' is-custom' : '' ?>">
                          <div class="team-day-color-main">
                            <div class="team-day-color-title">
                              <span class="team-day-color-swatch" style="--team-day-color:<?= esc($previewColor) ?>;"></span>
                              <span><?= esc((string) ($row['label'] ?? ('Professionista ' . $doctorId))) ?></span>
                            </div>
                            <div class="team-day-color-meta">
                              <?= $hasCustomColor ? 'Colore personalizzato salvato per questa colonna.' : 'Usa la palette consigliata automatica finche non personalizzi.' ?>
                              <br>
                              Suggerito: <strong><?= esc((string) ($row['suggested_color'] ?? '#3C8DBC')) ?></strong>
                            </div>
                          </div>
                          <div class="team-day-color-actions">
                            <label class="checkbox-inline" style="margin:0;">
                              <input
                                type="checkbox"
                                name="team_day_column_color_custom_enabled[<?= $doctorId ?>]"
                                value="1"
                                class="js-team-day-custom-toggle"
                                data-target="team-day-color-value-<?= $doctorId ?>"
                                <?= $hasCustomColor ? 'checked' : '' ?>
                              >
                              Personalizza
                            </label>
                            <input
                              type="color"
                              id="team-day-color-value-<?= $doctorId ?>"
                              name="team_day_column_color_value[<?= $doctorId ?>]"
                              value="<?= esc($pickerValue) ?>"
                              class="team-day-color-picker"
                              <?= $hasCustomColor ? '' : 'disabled' ?>
                            >
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>

                    <div class="team-day-colors-note">
                      Il master piattaforma ha gia autorizzato questa opzione per lo spazio attivo. Qui stai decidendo se usarla e, se serve, quali eccezioni cromatiche applicare ai singoli professionisti.
                    </div>
                  </div>
                <?php endif; ?>
              </div>
              <?php if ($canSubmitSpaceSettings): ?>
              <div class="box-footer">
                <button class="btn btn-success" type="submit">
                  <i class="fa fa-save"></i> Salva funzioni dello studio
                </button>
              </div>
              <?php endif; ?>
            </form>
          </div>

          <?php if ($lockedRows !== []): ?>
          <div class="box box-default">
            <div class="box-header with-border">
              <h3 class="box-title">Funzioni gestite centralmente</h3>
            </div>
            <div class="box-body">
              <div class="row">
                <?php foreach ($lockedRows as $row): ?>
                  <?php $lockedFeatureKey = trim((string) ($row['feature_key'] ?? '')); ?>
                  <?php $lockedFeatureEnabled = (bool) ($row['effective_enabled'] ?? false); ?>
                  <div class="col-md-4">
                    <div class="feature-card">
                      <h4><i class="fa <?= esc((string) ($row['icon_class'] ?? 'fa-lock')) ?>"></i> <?= esc((string) ($row['feature_name'] ?? '')) ?></h4>
                      <p><?= esc((string) ($row['description'] ?? '')) ?></p>
                      <span class="label label-default">gestita dalla piattaforma</span>
                      <span class="label label-<?= $lockedFeatureEnabled ? 'success' : 'default' ?>">
                        <?= $lockedFeatureEnabled ? 'attiva' : 'spenta' ?>
                      </span>
                      <?php if ($lockedFeatureKey === \App\Config\BillingModule::FEATURE_KEY): ?>
                        <div class="text-muted" style="margin-top:10px; line-height:1.5;">
                          <?= $lockedFeatureEnabled
                              ? 'La Fatturazione e pronta come modulo separato per il documento cliente. Quando attivi anche il Sistema TS, i due moduli possono convivere.'
                              : 'La Fatturazione per questo studio viene attivata centralmente dal master piattaforma.' ?>
                        </div>
                        <?php if ($lockedFeatureEnabled): ?>
                          <div style="margin-top:10px;">
                            <a class="btn btn-default btn-sm" href="<?= portal_tenant_space_url('fatturazione') ?>">
                              <i class="fa fa-calculator"></i> Apri modulo fatturazione
                            </a>
                          </div>
                        <?php endif; ?>
                      <?php endif; ?>
                      <?php if ($lockedFeatureKey === \App\Config\TsBilling::FEATURE_KEY): ?>
                        <div class="text-muted" style="margin-top:10px; line-height:1.5;">
                          <?= ($lockedFeatureEnabled || $tsConfigurationAccessible)
                              ? 'Il Sistema TS e pronto per la configurazione operativa dello studio. Da qui puoi aprire subito il profilo e inserire i dati richiesti anche se la Fatturazione resta un modulo separato.'
                              : 'Il Sistema TS per questo studio viene attivato centralmente dal master piattaforma.' ?>
                        </div>
                        <?php if ($lockedFeatureEnabled || $tsConfigurationAccessible): ?>
                          <div style="margin-top:10px;">
                            <a class="btn btn-default btn-sm" href="<?= portal_tenant_space_url('sistema-ts') ?>">
                              <i class="fa fa-cog"></i> Apri configurazione Sistema TS
                            </a>
                          </div>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <?php if ($unavailableRows !== []): ?>
          <div class="box box-warning">
            <div class="box-header with-border">
              <h3 class="box-title">Funzioni non incluse nel tuo studio</h3>
            </div>
            <div class="box-body">
              <div class="row">
                <?php foreach ($unavailableRows as $row): ?>
                  <?php $unavailableFeatureKey = trim((string) ($row['feature_key'] ?? '')); ?>
                  <div class="col-md-4">
                    <div class="feature-card">
                      <h4><i class="fa <?= esc((string) ($row['icon_class'] ?? 'fa-ban')) ?>"></i> <?= esc((string) ($row['feature_name'] ?? '')) ?></h4>
                      <p><?= esc((string) ($row['description'] ?? '')) ?></p>
                      <span class="label label-warning">non disponibile</span>
                      <?php if ($unavailableFeatureKey === \App\Config\TsBilling::FEATURE_KEY && $tsConfigurationAccessible): ?>
                        <div class="text-muted" style="margin-top:10px; line-height:1.5;">
                          In questo ambiente locale di test la configurazione TS resta comunque raggiungibile per permetterti di inserire credenziali e dati dello studio.
                        </div>
                        <div style="margin-top:10px;">
                          <a class="btn btn-default btn-sm" href="<?= portal_tenant_space_url('sistema-ts') ?>">
                            <i class="fa fa-cog"></i> Apri configurazione Sistema TS
                          </a>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
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
<script>
  (function() {
    function initOrderedList(list) {
      if (!list) {
        return;
      }

      var inputsContainerId = list.getAttribute('data-input-container-id') || '';
      var inputName = list.getAttribute('data-input-name') || '';
      var inputsContainer = inputsContainerId !== '' ? document.getElementById(inputsContainerId) : null;
      if (!inputsContainer || inputName === '') {
        return;
      }

      var resetButtonId = list.getAttribute('data-reset-button-id') || '';
      var resetButton = resetButtonId !== '' ? document.getElementById(resetButtonId) : null;

      function getRows() {
        return Array.prototype.slice.call(list.querySelectorAll('.agenda-order-row'));
      }

      function syncOrderState() {
        var rows = getRows();
        inputsContainer.innerHTML = '';

        for (var i = 0; i < rows.length; i++) {
          var row = rows[i];
          var orderValue = (row.getAttribute('data-order-value') || '').trim();
          var rank = row.querySelector('.js-order-rank');
          var upButton = row.querySelector('.js-order-up');
          var downButton = row.querySelector('.js-order-down');

          if (rank) {
            rank.textContent = String(i + 1);
          }

          if (upButton) {
            upButton.disabled = i === 0;
          }

          if (downButton) {
            downButton.disabled = i === rows.length - 1;
          }

          if (orderValue !== '') {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = inputName;
            input.value = orderValue;
            inputsContainer.appendChild(input);
          }
        }
      }

      function moveRow(row, direction) {
        if (!row) {
          return;
        }

        if (direction < 0) {
          var previous = row.previousElementSibling;
          if (previous) {
            list.insertBefore(row, previous);
          }
        } else {
          var next = row.nextElementSibling;
          if (next) {
            list.insertBefore(next, row);
          }
        }

        syncOrderState();
      }

      list.addEventListener('click', function(event) {
        var button = event.target.closest('button');
        if (!button) {
          return;
        }

        if (button.classList.contains('js-order-up')) {
          moveRow(button.closest('.agenda-order-row'), -1);
        }

        if (button.classList.contains('js-order-down')) {
          moveRow(button.closest('.agenda-order-row'), 1);
        }
      });

      if (resetButton) {
        resetButton.addEventListener('click', function() {
          var defaultOrder = (list.getAttribute('data-default-order') || '')
            .split(',')
            .map(function(value) {
              return value.trim();
            })
            .filter(function(value) {
              return value !== '';
            });

          if (defaultOrder.length === 0) {
            return;
          }

          var rowsByValue = {};
          getRows().forEach(function(row) {
            var orderValue = (row.getAttribute('data-order-value') || '').trim();
            if (orderValue !== '') {
              rowsByValue[orderValue] = row;
            }
          });

          defaultOrder.forEach(function(orderValue) {
            if (!rowsByValue[orderValue]) {
              return;
            }

            list.appendChild(rowsByValue[orderValue]);
            delete rowsByValue[orderValue];
          });

          Object.keys(rowsByValue).forEach(function(orderValue) {
            list.appendChild(rowsByValue[orderValue]);
          });

          getRows().forEach(function(row) {
            var columnSelect = row.querySelector('.js-order-column');
            if (!columnSelect) {
              return;
            }

            var defaultColumn = (columnSelect.getAttribute('data-default-column') || '').trim();
            if (defaultColumn !== '') {
              columnSelect.value = defaultColumn;
            }
          });

          syncOrderState();
        });
      }

      syncOrderState();
    }

    function syncTeamDayColorToggle(toggle) {
      var targetId = toggle.getAttribute('data-target');
      if (!targetId) {
        return;
      }

      var input = document.getElementById(targetId);
      if (!input) {
        return;
      }

      input.disabled = !toggle.checked;

      var row = toggle.closest('.team-day-color-row');
      if (row) {
        row.classList.toggle('is-custom', toggle.checked);
      }
    }

    var toggles = document.querySelectorAll('.js-team-day-custom-toggle');
    for (var i = 0; i < toggles.length; i++) {
      syncTeamDayColorToggle(toggles[i]);
      toggles[i].addEventListener('change', function() {
        syncTeamDayColorToggle(this);
      });
    }

    var orderedLists = document.querySelectorAll('.js-order-list');
    for (var j = 0; j < orderedLists.length; j++) {
      initOrderedList(orderedLists[j]);
    }
  })();
</script>
</body>
</html>
