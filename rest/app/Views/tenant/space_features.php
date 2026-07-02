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
$teamDayColumnColorSettings = is_array($teamDayColumnColorSettings ?? null) ? $teamDayColumnColorSettings : [];
$appointmentNotificationsAvailable = false;
$appointmentNotificationsEntitled = false;
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
                  <i class="fa fa-commenting"></i> Apri centro notifiche appuntamenti
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
            </div>
          </div>

          <div class="box box-success">
            <div class="box-header with-border">
              <h3 class="box-title">Funzioni gestibili dal responsabile dello studio</h3>
            </div>
            <form method="post" action="<?= portal_tenant_space_url('funzioni/save') ?>">
              <?= csrf_field() ?>
              <div class="box-body">
                <?php if ($manageableRows === []): ?>
                  <div class="alert alert-info" style="margin-bottom:0;">
                    In questo momento non ci sono funzioni gestibili in autonomia per il tuo studio.
                  </div>
                <?php else: ?>
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
              <?php if ($manageableRows !== []): ?>
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
                  <div class="col-md-4">
                    <div class="feature-card">
                      <h4><i class="fa <?= esc((string) ($row['icon_class'] ?? 'fa-lock')) ?>"></i> <?= esc((string) ($row['feature_name'] ?? '')) ?></h4>
                      <p><?= esc((string) ($row['description'] ?? '')) ?></p>
                      <span class="label label-default">gestita dalla piattaforma</span>
                      <span class="label label-<?= ((bool) ($row['effective_enabled'] ?? false)) ? 'success' : 'default' ?>">
                        <?= ((bool) ($row['effective_enabled'] ?? false)) ? 'attiva' : 'spenta' ?>
                      </span>
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
                  <div class="col-md-4">
                    <div class="feature-card">
                      <h4><i class="fa <?= esc((string) ($row['icon_class'] ?? 'fa-ban')) ?>"></i> <?= esc((string) ($row['feature_name'] ?? '')) ?></h4>
                      <p><?= esc((string) ($row['description'] ?? '')) ?></p>
                      <span class="label label-warning">non disponibile</span>
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
  })();
</script>
</body>
</html>
