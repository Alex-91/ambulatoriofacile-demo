<?php
helper('portal');

if (empty($menu_items) || !is_array($menu_items)) {
    $menu_items = session()->get('header_menu_items') ?? [];
}

$features = is_array($features ?? null) ? $features : [];
$selectedFeature = is_array($selectedFeature ?? null) ? $selectedFeature : null;
$selectedFeatureId = (int) ($selectedFeatureId ?? 0);
$errors = is_array($errors ?? null) ? $errors : [];
$warnings = is_array($warnings ?? null) ? $warnings : [];
$success = $success ?? null;
$legacyBootstrapMode = (bool) ($legacyBootstrapMode ?? false);
$platformBootstrapWarnings = is_array($platformBootstrapWarnings ?? null) ? $platformBootstrapWarnings : [];
$featureData = $selectedFeature ?? [];
$isEdit = $selectedFeatureId > 0;

$oldValue = static function (string $key, $fallback = '') {
    $old = old($key);
    return $old !== null ? $old : $fallback;
};
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Catalogo Funzioni</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/assets/css/platform-console.css') ?>" rel="stylesheet" />
  <style>
    .intro-box {
      border: 1px solid #dbe8eb;
      border-radius: 12px;
      padding: 18px 20px;
      background: linear-gradient(135deg, #f8fcfc 0%, #eff7f8 100%);
      margin-bottom: 16px;
    }
    .summary-badge {
      display: inline-block;
      margin: 0 8px 8px 0;
      padding: 7px 11px;
      border-radius: 999px;
      background: #dff1f2;
      color: #176872;
      font-size: 12px;
      font-weight: 600;
    }
    .catalog-note {
      color: #62767c;
      margin: 8px 0 0 0;
    }
  </style>
</head>

<body class="platform-console-body">
<div class="wrapper">
  <?= view('partials/header_portal_console', ['menu_items' => $menu_items]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Catalogo Funzioni</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Qui definisci le funzioni globali del prodotto e decidi se il tenant master puo gestirle in autonomia.
      </p>
    </section>

    <section class="content">
      <div class="row">
        <div class="col-md-3">
          <?= view('partials/sidebar_platform', ['platformMasterEmails' => $platformMasterEmails ?? []]) ?>
        </div>

        <div class="col-md-9">
          <?php if ($success): ?>
            <div class="alert alert-success"><?= esc((string) $success) ?></div>
          <?php endif; ?>
          <?php if (!empty($errors['generic'])): ?>
            <div class="alert alert-danger"><?= esc((string) $errors['generic']) ?></div>
          <?php endif; ?>
          <?php foreach ($warnings as $warning): ?>
            <div class="alert alert-warning"><?= esc((string) $warning) ?></div>
          <?php endforeach; ?>
          <?php foreach ($platformBootstrapWarnings as $bootstrapWarning): ?>
            <div class="alert <?= $legacyBootstrapMode ? 'alert-info' : 'alert-warning' ?>"><?= esc((string) $bootstrapWarning) ?></div>
          <?php endforeach; ?>

          <div class="intro-box">
            <h3 style="margin-top:0; margin-bottom:8px;">Doppio controllo delle feature</h3>
            <p style="margin:0 0 12px 0; color:#52676c;">
              La piattaforma decide se una funzione esiste nel catalogo e se puo essere delegata. Il tenant master puo attivare o disattivare solo le funzioni che hai marcato come governabili dal suo pannello sotto `/login/spazio/funzioni`.
            </p>
            <span class="summary-badge">Catalogo globale</span>
            <span class="summary-badge">Delega controllata al cliente</span>
            <span class="summary-badge">Toggle self service per tenant master</span>
          </div>

          <div class="box box-default">
            <div class="box-header with-border">
              <h3 class="box-title">Funzioni presenti</h3>
            </div>
            <div class="box-body table-responsive">
              <table class="table table-bordered table-hover">
                <thead>
                  <tr>
                    <th>Funzione</th>
                    <th>Scope</th>
                    <th>Governabile dal master</th>
                    <th>Default globale</th>
                    <th>Ordine</th>
                    <th style="width:90px;">Azioni</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($features === []): ?>
                    <tr><td colspan="6" class="text-muted">Nessuna funzione catalogata.</td></tr>
                  <?php else: ?>
                    <?php foreach ($features as $feature): ?>
                      <?php
                        $featureId = (int) ($feature['id_feature'] ?? 0);
                        $featureLink = portal_platform_url('funzioni') . '?id_feature=' . $featureId;
                      ?>
                      <tr <?= $featureId === $selectedFeatureId ? 'class="info"' : '' ?>>
                        <td>
                          <strong><?= esc((string) ($feature['feature_name'] ?? '')) ?></strong><br>
                          <span class="text-muted"><?= esc((string) ($feature['feature_key'] ?? '')) ?></span>
                        </td>
                        <td><?= esc((string) ($feature['feature_scope'] ?? 'module')) ?></td>
                        <td>
                          <span class="label label-<?= ((int) ($feature['is_tenant_managed'] ?? 0) === 1) ? 'success' : 'default' ?>">
                            <?= ((int) ($feature['is_tenant_managed'] ?? 0) === 1) ? 'si' : 'no' ?>
                          </span>
                        </td>
                        <td>
                          <span class="label label-<?= ((int) ($feature['default_enabled'] ?? 0) === 1) ? 'success' : 'default' ?>">
                            <?= ((int) ($feature['default_enabled'] ?? 0) === 1) ? 'attiva' : 'spenta' ?>
                          </span>
                        </td>
                        <td><?= (int) ($feature['sort_order'] ?? 0) ?></td>
                        <td>
                          <a class="btn btn-xs btn-primary" href="<?= esc($featureLink) ?>">
                            <i class="fa fa-pencil"></i> Apri
                          </a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="box box-success">
            <div class="box-header with-border">
              <h3 class="box-title"><?= $isEdit ? 'Modifica funzione' : 'Nuova funzione' ?></h3>
            </div>

            <form method="post" action="<?= portal_platform_url('funzioni/save') ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="id_feature" value="<?= (int) $selectedFeatureId ?>">
              <input type="hidden" name="default_enabled" value="0">
              <input type="hidden" name="is_tenant_managed" value="0">
              <input type="hidden" name="tenant_default_enabled" value="0">

              <div class="box-body">
                <div class="row">
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Chiave funzione <?= $isEdit ? '' : '*' ?></label>
                      <input
                        class="form-control"
                        name="feature_key"
                        value="<?= esc((string) $oldValue('feature_key', $featureData['feature_key'] ?? '')) ?>"
                        <?= $isEdit ? 'readonly' : 'required' ?>
                      >
                      <p class="catalog-note">Usa una chiave tecnica stabile, per esempio `agenda_whatsapp`.</p>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Nome funzione *</label>
                      <input class="form-control" name="feature_name" required value="<?= esc((string) $oldValue('feature_name', $featureData['feature_name'] ?? '')) ?>">
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Scope</label>
                      <?php $scope = (string) $oldValue('feature_scope', $featureData['feature_scope'] ?? 'module'); ?>
                      <select class="form-control" name="feature_scope">
                        <?php foreach (['module', 'channel', 'feature-flag', 'integration', 'workflow'] as $scopeOption): ?>
                          <option value="<?= esc($scopeOption) ?>" <?= $scope === $scopeOption ? 'selected' : '' ?>><?= esc($scopeOption) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-8">
                    <div class="form-group">
                      <label>Descrizione</label>
                      <textarea class="form-control" name="description" rows="3"><?= esc((string) $oldValue('description', $featureData['description'] ?? '')) ?></textarea>
                    </div>
                  </div>
                  <div class="col-md-2">
                    <div class="form-group">
                      <label>Icona FA</label>
                      <input class="form-control" name="icon_class" value="<?= esc((string) $oldValue('icon_class', $featureData['icon_class'] ?? 'fa-toggle-on')) ?>">
                    </div>
                  </div>
                  <div class="col-md-2">
                    <div class="form-group">
                      <label>Ordine</label>
                      <input type="number" class="form-control" name="sort_order" value="<?= esc((string) $oldValue('sort_order', $featureData['sort_order'] ?? 0)) ?>">
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-4">
                    <?php $defaultEnabled = (string) $oldValue('default_enabled', (string) ($featureData['default_enabled'] ?? '0')); ?>
                    <div class="checkbox">
                      <label><input type="checkbox" name="default_enabled" value="1" <?= $defaultEnabled === '1' ? 'checked' : '' ?>> Attiva di default a livello globale</label>
                    </div>
                    <p class="catalog-note">Se il pacchetto o il tenant non hanno override espliciti, questa e la base iniziale.</p>
                  </div>
                  <div class="col-md-4">
                    <?php $tenantManaged = (string) $oldValue('is_tenant_managed', (string) ($featureData['is_tenant_managed'] ?? '0')); ?>
                    <div class="checkbox">
                      <label><input type="checkbox" name="is_tenant_managed" value="1" <?= $tenantManaged === '1' ? 'checked' : '' ?>> Il tenant master puo governarla</label>
                    </div>
                    <p class="catalog-note">Se attivo, il master cliente la vedra nel pannello funzioni del suo spazio.</p>
                  </div>
                  <div class="col-md-4">
                    <?php $tenantDefaultEnabled = (string) $oldValue('tenant_default_enabled', (string) ($featureData['tenant_default_enabled'] ?? '1')); ?>
                    <div class="checkbox">
                      <label><input type="checkbox" name="tenant_default_enabled" value="1" <?= $tenantDefaultEnabled === '1' ? 'checked' : '' ?>> Attiva di default per il tenant master</label>
                    </div>
                    <p class="catalog-note">Conta solo quando la funzione e delegabile al cliente.</p>
                  </div>
                </div>
              </div>

              <div class="box-footer">
                <button class="btn btn-success" type="submit">
                  <i class="fa fa-save"></i> <?= $isEdit ? 'Salva funzione' : 'Crea funzione' ?>
                </button>
                <a class="btn btn-default" href="<?= portal_platform_url('funzioni') ?>">
                  <i class="fa fa-plus-circle"></i> Nuova
                </a>
                <a class="btn btn-default" href="<?= portal_platform_url('spazi-clienti') ?>">
                  <i class="fa fa-sitemap"></i> Vai agli spazi cliente
                </a>
              </div>
            </form>
          </div>
        </div>
      </div>
    </section>
  </div>
</div>

<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
</body>
</html>
