<?php
helper('portal');

if (empty($menu_items) || !is_array($menu_items)) {
    $menu_items = session()->get('header_menu_items') ?? [];
}

$packages = is_array($packages ?? null) ? $packages : [];
$features = is_array($features ?? null) ? $features : [];
$filters = is_array($filters ?? null) ? $filters : ['q' => '', 'status' => '', 'package' => ''];
$tenantRows = is_array($tenantRows ?? null) ? $tenantRows : [];
$selectedTenant = is_array($selectedTenant ?? null) ? $selectedTenant : null;
$selectedTenantId = (int)($selectedTenantId ?? 0);
$tenantMembers = is_array($tenantMembers ?? null) ? $tenantMembers : [];
$tenantCapacity = is_array($tenantCapacity ?? null) ? $tenantCapacity : [];
$errors = is_array($errors ?? null) ? $errors : [];
$success = $success ?? null;
$tempPassword = trim((string)($tempPassword ?? ''));
$memberSuccess = $memberSuccess ?? null;
$memberErrors = is_array($memberErrors ?? null) ? $memberErrors : [];
$memberTempPassword = trim((string)($memberTempPassword ?? ''));

$tenantData = is_array($selectedTenant['tenant'] ?? null) ? $selectedTenant['tenant'] : [];
$ownerData = is_array($selectedTenant['owner'] ?? null) ? $selectedTenant['owner'] : [];
$featureMap = is_array($selectedTenant['feature_map'] ?? null) ? $selectedTenant['feature_map'] : [];
$runtime = is_array($selectedTenant['runtime'] ?? null) ? $selectedTenant['runtime'] : [];
$metadata = is_array($selectedTenant['metadata'] ?? null) ? $selectedTenant['metadata'] : [];
$provisioningMeta = is_array($metadata['provisioning'] ?? null) ? $metadata['provisioning'] : [];
$isEdit = $selectedTenantId > 0;
$editingMemberId = (int) old('member_id_platform_user_tenant');
$isEditingMember = $editingMemberId > 0;
$warnings = is_array($warnings ?? null) ? $warnings : [];
$memberWarnings = is_array($memberWarnings ?? null) ? $memberWarnings : [];
$legacyBootstrapMode = (bool)($legacyBootstrapMode ?? false);
$platformBootstrapWarnings = is_array($platformBootstrapWarnings ?? null) ? $platformBootstrapWarnings : [];
$masterAccountWarnings = is_array($masterAccountWarnings ?? null) ? $masterAccountWarnings : [];
$masterTempPasswords = is_array($masterTempPasswords ?? null) ? $masterTempPasswords : [];
$platformMasterAccounts = is_array($platformMasterAccounts ?? null) ? $platformMasterAccounts : [];

$oldValue = static function (string $key, $fallback = '') {
    $old = old($key);
    return $old !== null ? $old : $fallback;
};
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Spazi Cliente</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <style>
    .nav-pills.nav-stacked > li.active > a { background-color:#2c8895; color:#fff; }
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
    .feature-card {
      border: 1px solid #e5ecee;
      border-radius: 8px;
      padding: 12px 14px;
      min-height: 108px;
      margin-bottom: 12px;
      background: #fff;
    }
    .feature-card h4 {
      margin-top: 0;
      margin-bottom: 6px;
      font-size: 16px;
    }
    .feature-card p {
      color: #667b80;
      min-height: 34px;
      margin-bottom: 8px;
    }
    .runtime-list dt {
      width: 150px;
    }
    .runtime-list dd {
      margin-left: 165px;
      margin-bottom: 8px;
      word-break: break-word;
    }
    .table-status {
      white-space: nowrap;
    }
    .member-meta .label {
      display: inline-block;
      margin: 0 6px 6px 0;
    }
    .start-steps {
      margin: 10px 0 0;
      padding-left: 18px;
      color: #52676c;
    }
    .master-account-actions form {
      display: inline-block;
      margin: 0 8px 8px 0;
    }
    .master-account-table td,
    .master-account-table th {
      vertical-align: middle !important;
    }
    .master-password-list {
      margin: 0;
      padding-left: 18px;
    }
  </style>
</head>

<body class="skin-blue sidebar-mini">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Spazi Cliente</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Qui gestisci i tenant applicativi: anagrafica spazio, pacchetto, master email, database dedicato e feature disponibili.
      </p>
    </section>

    <section class="content">
      <div class="row">
        <div class="col-md-3">
          <?= view('partials/sidebar_platform', ['platformMasterEmails' => $platformMasterEmails ?? []]) ?>
        </div>

        <div class="col-md-9">
          <?php if ($success): ?>
            <div class="alert alert-success"><?= esc((string)$success) ?></div>
          <?php endif; ?>
          <?php if (!empty($errors['generic'])): ?>
            <div class="alert alert-danger"><?= esc((string)$errors['generic']) ?></div>
          <?php endif; ?>
          <?php foreach ($warnings as $warning): ?>
            <div class="alert alert-warning"><?= esc((string)$warning) ?></div>
          <?php endforeach; ?>
          <?php foreach ($masterAccountWarnings as $warning): ?>
            <div class="alert alert-warning"><?= esc((string)$warning) ?></div>
          <?php endforeach; ?>
          <?php foreach ($platformBootstrapWarnings as $bootstrapWarning): ?>
            <div class="alert <?= $legacyBootstrapMode ? 'alert-info' : 'alert-warning' ?>"><?= esc((string)$bootstrapWarning) ?></div>
          <?php endforeach; ?>
          <?php if ($tempPassword !== ''): ?>
            <div class="alert alert-warning">
              <strong>Password temporanea tenant master:</strong> <code><?= esc($tempPassword) ?></code>
            </div>
          <?php endif; ?>
          <?php if ($masterTempPasswords !== []): ?>
            <div class="alert alert-warning">
              <strong>Password temporanee account master piattaforma:</strong>
              <ul class="master-password-list">
                <?php foreach ($masterTempPasswords as $email => $password): ?>
                  <li><strong><?= esc((string)$email) ?>:</strong> <code><?= esc((string)$password) ?></code></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <div class="intro-box">
            <h3 style="margin-top:0; margin-bottom:8px;">Console master sotto /login</h3>
            <p style="margin:0 0 12px 0; color:#52676c;">
              Questo pannello e disponibile solo agli account master del login unico. Qui gestisci onboarding commerciale, tenant, pacchetti e accessi senza usare `/admin`.
            </p>
            <?php if ($legacyBootstrapMode): ?>
              <p style="margin:0 0 12px 0; color:#2b5d67;">
                Modalita bootstrap attiva: puoi completare l avvio iniziale dalla console nuova, ma il passaggio definitivo agli account master del login unico richiede la configurazione finale delle email master.
              </p>
            <?php endif; ?>
            <span class="summary-badge">Login unico via email</span>
            <span class="summary-badge">DB separato per cliente</span>
            <span class="summary-badge">Feature flag per verticalizzazioni</span>
            <span class="summary-badge">Storage per tenant</span>
            <ul class="start-steps">
              <li>1. Prepara gli account master che devono governare la piattaforma.</li>
              <li>2. Definisci o aggiorna il catalogo funzioni comune a tutti.</li>
              <li>3. Crea lo spazio cliente, assegna pacchetto e master email.</li>
              <li>4. Salva e provisiona lo spazio quando il database cliente e pronto.</li>
            </ul>
          </div>

          <div class="box box-info">
            <div class="box-header with-border">
              <h3 class="box-title">Account master piattaforma</h3>
            </div>
            <div class="box-body">
              <p class="text-muted" style="margin:0 0 14px 0;">
                Qui vedi le email master configurate in Coolify e puoi preparare il primo accesso senza dipendere dalla creazione del primo cliente. Anche i master entrano sempre da <code>/login</code>.
              </p>

              <div class="master-account-actions">
                <form method="post" action="<?= portal_platform_url('spazi-clienti/master-accounts/sync') ?>">
                  <?= csrf_field() ?>
                  <button class="btn btn-default" type="submit">
                    <i class="fa fa-user-plus"></i> Prepara account master
                  </button>
                </form>
                <form method="post" action="<?= portal_platform_url('spazi-clienti/master-accounts/sync') ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="send_access" value="1">
                  <button class="btn btn-primary" type="submit">
                    <i class="fa fa-envelope"></i> Prepara e invia accesso
                  </button>
                </form>
                <a class="btn btn-default" href="<?= portal_platform_url('funzioni') ?>">
                  <i class="fa fa-toggle-on"></i> Apri catalogo funzioni
                </a>
              </div>

              <div class="table-responsive" style="margin-top:10px;">
                <table class="table table-bordered table-hover master-account-table">
                  <thead>
                    <tr>
                      <th>Email master</th>
                      <th>Stato account</th>
                      <th>Primo accesso</th>
                      <th>Ultimo accesso</th>
                      <th style="width:140px;">Azioni</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if ($platformMasterAccounts === []): ?>
                      <tr>
                        <td colspan="5" class="text-muted">
                          Nessuna email master configurata. Inserisci `PLATFORM_MASTER_EMAILS` in Coolify per attivare il login centrale dei master.
                        </td>
                      </tr>
                    <?php else: ?>
                      <?php foreach ($platformMasterAccounts as $account): ?>
                        <?php
                          $exists = !empty($account['exists']);
                          $needsSetup = !empty($account['needs_password_setup']);
                          $statusLabelClass = !$exists
                              ? 'default'
                              : (((string)($account['status'] ?? '')) === 'active' ? 'success' : 'warning');
                        ?>
                        <tr>
                          <td>
                            <strong><?= esc((string)($account['email'] ?? '')) ?></strong>
                            <?php if (trim((string)($account['full_name'] ?? '')) !== ''): ?>
                              <br><span class="text-muted"><?= esc((string)($account['full_name'] ?? '')) ?></span>
                            <?php endif; ?>
                          </td>
                          <td>
                            <span class="label label-<?= esc($statusLabelClass) ?>">
                              <?= esc((string)($account['status_label'] ?? 'n/d')) ?>
                            </span>
                          </td>
                          <td>
                            <span class="label label-<?= $needsSetup ? 'warning' : 'success' ?>">
                              <?= esc((string)($account['access_label'] ?? 'n/d')) ?>
                            </span>
                          </td>
                          <td>
                            <?php if (trim((string)($account['last_login_at'] ?? '')) !== ''): ?>
                              <?= esc((string)($account['last_login_at'] ?? '')) ?>
                            <?php else: ?>
                              <span class="text-muted">Mai entrato</span>
                            <?php endif; ?>
                          </td>
                          <td>
                            <form method="post" action="<?= portal_platform_url('spazi-clienti/master-accounts/accesso') ?>">
                              <?= csrf_field() ?>
                              <input type="hidden" name="email" value="<?= esc((string)($account['email'] ?? ''), 'attr') ?>">
                              <button class="btn btn-xs btn-default" type="submit">
                                <i class="fa fa-envelope"></i> Invia accesso
                              </button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="box box-primary">
            <div class="box-header with-border">
              <h3 class="box-title">Ricerca Spazi</h3>
            </div>
            <div class="box-body">
              <form method="get" action="<?= portal_platform_url('spazi-clienti') ?>">
                <div class="row">
                  <div class="col-md-4">
                    <label>Ricerca</label>
                    <input class="form-control" type="text" name="q" value="<?= esc((string)($filters['q'] ?? '')) ?>" placeholder="tenant, chiave, email master">
                  </div>
                  <div class="col-md-4">
                    <label>Stato</label>
                    <select class="form-control" name="status">
                      <option value="">Tutti</option>
                      <?php foreach (['draft' => 'Draft', 'active' => 'Active', 'suspended' => 'Suspended', 'archived' => 'Archived'] as $value => $label): ?>
                        <option value="<?= esc($value) ?>" <?= (($filters['status'] ?? '') === $value) ? 'selected' : '' ?>><?= esc($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label>Pacchetto</label>
                    <select class="form-control" name="package">
                      <option value="">Tutti</option>
                      <?php foreach ($packages as $package): ?>
                        <?php $packageCode = (string)($package['package_code'] ?? ''); ?>
                        <option value="<?= esc($packageCode) ?>" <?= (($filters['package'] ?? '') === $packageCode) ? 'selected' : '' ?>>
                          <?= esc((string)($package['package_name'] ?? $packageCode)) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div style="margin-top:12px;">
                  <button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> Filtra</button>
                  <a class="btn btn-default" href="<?= portal_platform_url('spazi-clienti') ?>"><i class="fa fa-refresh"></i> Reset</a>
                </div>
              </form>
            </div>
          </div>

          <div class="box box-default">
            <div class="box-header with-border">
              <h3 class="box-title">Spazi presenti</h3>
            </div>
            <div class="box-body table-responsive">
              <table class="table table-bordered table-hover">
                <thead>
                  <tr>
                    <th>Tenant</th>
                    <th>Pacchetto</th>
                    <th>Master</th>
                    <th>Stato</th>
                    <th>Storage</th>
                    <th style="width:90px;">Azioni</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($tenantRows === []): ?>
                    <tr><td colspan="6" class="text-muted">Nessun tenant trovato.</td></tr>
                  <?php else: ?>
                    <?php foreach ($tenantRows as $row): ?>
                      <?php $tenantLink = portal_platform_url('spazi-clienti') . '?id_tenant=' . (int)$row['id_tenant']; ?>
                      <tr <?= ((int)$row['id_tenant'] === $selectedTenantId) ? 'class="info"' : '' ?>>
                        <td>
                          <strong><?= esc((string)($row['tenant_name'] ?? '')) ?></strong><br>
                          <span class="text-muted"><?= esc((string)($row['tenant_key'] ?? '')) ?></span>
                        </td>
                        <td><?= esc((string)($row['package_name'] ?? $row['package_code'] ?? '-')) ?></td>
                        <td><?= esc((string)($row['owner_email'] ?? '-')) ?></td>
                        <td class="table-status">
                          <span class="label label-<?= ((string)($row['status'] ?? '') === 'active') ? 'success' : 'default' ?>">
                            <?= esc((string)($row['status'] ?? 'draft')) ?>
                          </span>
                          <?php if ((int)($row['is_active'] ?? 0) !== 1): ?>
                            <span class="label label-warning">inactive</span>
                          <?php endif; ?>
                        </td>
                        <td><?= esc((string)($row['storage_key'] ?? '-')) ?></td>
                        <td>
                          <a class="btn btn-xs btn-primary" href="<?= esc($tenantLink) ?>">
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
              <h3 class="box-title"><?= $isEdit ? 'Modifica spazio cliente' : 'Nuovo spazio cliente' ?></h3>
            </div>

            <form method="post" action="<?= portal_platform_url('spazi-clienti/save') ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="id_tenant" value="<?= (int)$selectedTenantId ?>">
              <input type="hidden" name="is_active" value="0">

              <div class="box-body">
                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Nome spazio *</label>
                      <input class="form-control" name="tenant_name" required value="<?= esc((string)$oldValue('tenant_name', $tenantData['tenant_name'] ?? '')) ?>">
                    </div>
                  </div>
                  <div class="col-md-3">
                    <div class="form-group">
                      <label>Chiave tenant *</label>
                      <input class="form-control" name="tenant_key" required value="<?= esc((string)$oldValue('tenant_key', $tenantData['tenant_key'] ?? '')) ?>">
                    </div>
                  </div>
                  <div class="col-md-3">
                    <div class="form-group">
                      <label>Storage key</label>
                      <input class="form-control" name="storage_key" value="<?= esc((string)$oldValue('storage_key', $tenantData['storage_key'] ?? '')) ?>">
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-5">
                    <div class="form-group">
                      <label>Ragione sociale</label>
                      <input class="form-control" name="legal_name" value="<?= esc((string)$oldValue('legal_name', $tenantData['legal_name'] ?? '')) ?>">
                    </div>
                  </div>
                  <div class="col-md-3">
                    <div class="form-group">
                      <label>Pacchetto *</label>
                      <select class="form-control" name="package_code" required>
                        <option value="">Seleziona...</option>
                        <?php foreach ($packages as $package): ?>
                          <?php
                            $packageCode = (string)($package['package_code'] ?? '');
                            $selectedPackageCode = (string)$oldValue('package_code', $selectedTenant['package']['package_code'] ?? $tenantData['package_code'] ?? '');
                          ?>
                          <option value="<?= esc($packageCode) ?>" <?= $selectedPackageCode === $packageCode ? 'selected' : '' ?>>
                            <?= esc((string)($package['package_name'] ?? $packageCode)) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <div class="col-md-2">
                    <div class="form-group">
                      <label>Stato</label>
                      <?php $currentStatus = (string)$oldValue('status', $tenantData['status'] ?? 'draft'); ?>
                      <select class="form-control" name="status">
                        <?php foreach (['draft', 'active', 'suspended', 'archived'] as $status): ?>
                          <option value="<?= esc($status) ?>" <?= $currentStatus === $status ? 'selected' : '' ?>><?= esc($status) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <div class="col-md-2">
                    <div class="form-group">
                      <label>Onboarding</label>
                      <?php $currentOnboarding = (string)$oldValue('onboarding_status', $tenantData['onboarding_status'] ?? 'draft'); ?>
                      <select class="form-control" name="onboarding_status">
                        <?php foreach (['draft', 'setup', 'ready', 'live'] as $status): ?>
                          <option value="<?= esc($status) ?>" <?= $currentOnboarding === $status ? 'selected' : '' ?>><?= esc($status) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Profilo verticale</label>
                      <input class="form-control" name="feature_profile" value="<?= esc((string)$oldValue('feature_profile', $tenantData['feature_profile'] ?? '')) ?>" placeholder="es. medical">
                    </div>
                  </div>
                  <div class="col-md-5">
                    <div class="form-group">
                      <label>Login hint</label>
                      <input class="form-control" name="login_hint" value="<?= esc((string)$oldValue('login_hint', $tenantData['login_hint'] ?? '')) ?>" placeholder="testo di aiuto lato login">
                    </div>
                  </div>
                  <div class="col-md-3">
                    <div class="checkbox" style="margin-top:32px;">
                      <?php $activeValue = (string)$oldValue('is_active', (string)($tenantData['is_active'] ?? '1')); ?>
                      <label><input type="checkbox" name="is_active" value="1" <?= $activeValue !== '0' ? 'checked' : '' ?>> Tenant attivo</label>
                    </div>
                    <div class="checkbox">
                      <label><input type="checkbox" name="prepare_local_dirs" value="1"> Prepara cartelle locali</label>
                    </div>
                    <div class="checkbox">
                      <label><input type="checkbox" name="send_master_access_email" value="1"> Invia accesso al tenant master dopo il salvataggio</label>
                    </div>
                  </div>
                </div>

                <hr>
                <h4 style="margin-top:0;">Tenant master</h4>
                <div class="row">
                  <div class="col-md-5">
                    <div class="form-group">
                      <label>Email master *</label>
                      <input type="email" class="form-control" name="master_email" required value="<?= esc((string)$oldValue('master_email', $ownerData['email'] ?? '')) ?>">
                    </div>
                  </div>
                  <div class="col-md-3">
                    <div class="form-group">
                      <label>Nome</label>
                      <input class="form-control" name="master_first_name" value="<?= esc((string)$oldValue('master_first_name', $ownerData['first_name'] ?? '')) ?>">
                    </div>
                  </div>
                  <div class="col-md-2">
                    <div class="form-group">
                      <label>Cognome</label>
                      <input class="form-control" name="master_last_name" value="<?= esc((string)$oldValue('master_last_name', $ownerData['last_name'] ?? '')) ?>">
                    </div>
                  </div>
                  <div class="col-md-2">
                    <div class="form-group">
                      <label><?= $isEdit ? 'Nuova password' : 'Password iniziale' ?></label>
                      <input type="password" class="form-control" name="master_password" autocomplete="new-password">
                    </div>
                  </div>
                </div>
                <div class="row">
                  <div class="col-md-3">
                    <div class="form-group">
                      <label>App user ID master</label>
                      <input type="number" class="form-control" name="master_app_user_id" value="<?= esc((string)$oldValue('master_app_user_id', $ownerData['app_user_id'] ?? '')) ?>">
                    </div>
                  </div>
                  <div class="col-md-9">
                    <p class="text-muted" style="margin-top:32px;">
                      Se lasci vuoto questo campo, il login unico prova a collegare automaticamente il master tramite la stessa email presente nel DB del tenant.
                    </p>
                  </div>
                </div>

                <hr>
                <h4 style="margin-top:0;">Database tenant</h4>
                <div class="row">
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>DB host</label>
                      <input class="form-control" name="db_host" value="<?= esc((string)$oldValue('db_host', $tenantData['db_host'] ?? '')) ?>">
                    </div>
                  </div>
                  <div class="col-md-2">
                    <div class="form-group">
                      <label>DB port</label>
                      <input type="number" class="form-control" name="db_port" value="<?= esc((string)$oldValue('db_port', $tenantData['db_port'] ?? 3306)) ?>">
                    </div>
                  </div>
                  <div class="col-md-3">
                    <div class="form-group">
                      <label>DB name</label>
                      <input class="form-control" name="db_name" value="<?= esc((string)$oldValue('db_name', $tenantData['db_name'] ?? '')) ?>">
                    </div>
                  </div>
                  <div class="col-md-3">
                    <div class="form-group">
                      <label>DB user</label>
                      <input class="form-control" name="db_username" value="<?= esc((string)$oldValue('db_username', $tenantData['db_username'] ?? '')) ?>">
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Env var password DB</label>
                      <input class="form-control" name="db_password_ref" value="<?= esc((string)$oldValue('db_password_ref', $tenantData['db_password_ref'] ?? '')) ?>" placeholder="es. TENANT_STUDIO_VERDE_DB_PASSWORD">
                      <p class="text-muted" style="margin:6px 0 0 0;">Se lasci vuoti host, user o password ref, il provisioning usera i default configurati per la piattaforma.</p>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>DB driver</label>
                      <input class="form-control" name="db_driver" value="<?= esc((string)$oldValue('db_driver', $tenantData['db_driver'] ?? 'MySQLi')) ?>">
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Prefisso tabelle</label>
                      <input class="form-control" name="db_prefix" value="<?= esc((string)$oldValue('db_prefix', $tenantData['db_prefix'] ?? '')) ?>">
                    </div>
                  </div>
                </div>

                <hr>
                <h4 style="margin-top:0;">Feature override tenant</h4>
                <p class="text-muted">
                  Qui decidi quali funzioni sono concesse a questo cliente. Se una funzione e marcata come governabile dal tenant master, il cliente potra poi accenderla o spegnerla dal suo pannello sotto `/login/spazio/funzioni`.
                </p>
                <div class="row">
                  <?php foreach ($features as $feature): ?>
                    <?php
                      $featureKey = (string)($feature['feature_key'] ?? '');
                      $checked = (bool)($featureMap[$featureKey] ?? false);
                      $oldEnabled = old('enabled_features');
                      if (is_array($oldEnabled)) {
                          $checked = in_array($featureKey, array_map('strval', $oldEnabled), true);
                      }
                    ?>
                    <div class="col-md-4">
                      <div class="feature-card">
                        <h4><?= esc((string)($feature['feature_name'] ?? $featureKey)) ?></h4>
                        <p><?= esc((string)($feature['description'] ?? '')) ?></p>
                        <div class="checkbox" style="margin:0;">
                          <label>
                            <input type="checkbox" name="enabled_features[]" value="<?= esc($featureKey) ?>" <?= $checked ? 'checked' : '' ?>>
                            Abilitata
                          </label>
                        </div>
                        <div class="text-muted" style="font-size:12px; margin-top:6px;">
                          Scope: <?= esc((string)($feature['feature_scope'] ?? 'module')) ?>
                        </div>
                        <div style="margin-top:6px;">
                          <span class="label label-<?= ((int)($feature['is_tenant_managed'] ?? 0) === 1) ? 'info' : 'default' ?>">
                            <?= ((int)($feature['is_tenant_managed'] ?? 0) === 1) ? 'Governabile dal master cliente' : 'Gestita centralmente' ?>
                          </span>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>

                <?php if ($isEdit && $runtime !== []): ?>
                  <hr>
                  <h4 style="margin-top:0;">Blueprint runtime</h4>
                  <dl class="runtime-list">
                    <dt>Upload path</dt>
                    <dd><?= esc((string)($runtime['upload_path'] ?? '')) ?></dd>
                    <dt>Writability root</dt>
                    <dd><?= esc((string)($runtime['writable_path'] ?? '')) ?></dd>
                    <dt>DB host</dt>
                    <dd><?= esc((string)($runtime['db_host'] ?? '')) ?></dd>
                    <dt>DB name</dt>
                    <dd><?= esc((string)($runtime['db_name'] ?? '')) ?></dd>
                    <dt>Env password key</dt>
                    <dd><?= esc((string)($runtime['env_password_key'] ?? '')) ?></dd>
                  </dl>
                  <?php if ($provisioningMeta !== []): ?>
                    <div class="intro-box" style="margin-top:14px;">
                      <h3 style="margin-top:0; margin-bottom:8px;">Ultimo provisioning tecnico</h3>
                      <span class="summary-badge">Stato: <?= esc((string)($provisioningMeta['status'] ?? 'n/d')) ?></span>
                      <span class="summary-badge">Template: <?= esc((string)($provisioningMeta['template_mode'] ?? 'n/d')) ?></span>
                      <span class="summary-badge">Ultima esecuzione: <?= esc((string)($provisioningMeta['last_run_at'] ?? 'n/d')) ?></span>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              </div>

              <div class="box-footer">
                <button class="btn btn-success" type="submit">
                  <i class="fa fa-save"></i> <?= $isEdit ? 'Salva aggiornamenti' : 'Crea spazio cliente' ?>
                </button>
                <button class="btn btn-primary" type="submit" name="provision_after_save" value="1">
                  <i class="fa fa-database"></i> <?= $isEdit ? 'Salva e provisiona' : 'Crea e provisiona' ?>
                </button>
                <a class="btn btn-default" href="<?= portal_platform_url('spazi-clienti') ?>">
                  <i class="fa fa-plus"></i> Nuovo spazio
                </a>
              </div>
            </form>
          </div>

          <?php if ($isEdit): ?>
            <?php
              $capacityCurrent = (int)($tenantCapacity['current_users'] ?? 0);
              $capacityMax = $tenantCapacity['max_users'] ?? null;
              $capacityRemaining = $tenantCapacity['remaining_users'] ?? null;
              $capacityLabel = $capacityMax === null
                  ? 'Illimitato'
                  : ((string)$capacityMax);
            ?>
            <div class="box box-info" id="tenant-members">
              <div class="box-header with-border">
                <h3 class="box-title">Utenti dello spazio</h3>
              </div>
              <div class="box-body">
                <?php if ($memberSuccess): ?>
                  <div class="alert alert-success"><?= esc((string)$memberSuccess) ?></div>
                <?php endif; ?>
                <?php if (!empty($memberErrors['generic'])): ?>
                  <div class="alert alert-danger"><?= esc((string)$memberErrors['generic']) ?></div>
                <?php endif; ?>
                <?php foreach ($memberWarnings as $warning): ?>
                  <div class="alert alert-warning"><?= esc((string)$warning) ?></div>
                <?php endforeach; ?>
                <?php if ($memberTempPassword !== ''): ?>
                  <div class="alert alert-warning">
                    <strong>Password temporanea nuovo utente:</strong> <code><?= esc($memberTempPassword) ?></code>
                  </div>
                <?php endif; ?>

                <div class="intro-box" style="margin-bottom:18px;">
                  <h3 style="margin-top:0; margin-bottom:8px;">Capienza pacchetto</h3>
                  <p style="margin:0 0 12px 0; color:#52676c;">
                    Gli utenti aggiunti qui preparano lo spazio del cliente per il login unico. Il tenant master si modifica dal blocco principale qui sopra.
                  </p>
                  <span class="summary-badge">Utenti attuali: <?= esc((string)$capacityCurrent) ?></span>
                  <span class="summary-badge">Limite pacchetto: <?= esc($capacityLabel) ?></span>
                  <?php if ($capacityRemaining !== null): ?>
                    <span class="summary-badge">Posti liberi: <?= esc((string)$capacityRemaining) ?></span>
                  <?php endif; ?>
                </div>

                <div class="table-responsive" style="margin-bottom:18px;">
                  <table class="table table-bordered table-hover">
                    <thead>
                      <tr>
                        <th>Email</th>
                        <th>Nome</th>
                        <th>Ruolo</th>
                        <th>Mapping app user</th>
                        <th>Stato</th>
                        <th style="width:110px;">Azioni</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if ($tenantMembers === []): ?>
                        <tr><td colspan="6" class="text-muted">Nessun utente aggiuntivo configurato.</td></tr>
                      <?php else: ?>
                        <?php foreach ($tenantMembers as $member): ?>
                          <?php
                            $memberId = (int)($member['id_platform_user_tenant'] ?? 0);
                            $isOwnerMember = (int)($member['is_owner'] ?? 0) === 1;
                          ?>
                          <tr>
                            <td><?= esc((string)($member['email'] ?? '')) ?></td>
                            <td><?= esc(trim((string)($member['first_name'] ?? '') . ' ' . (string)($member['last_name'] ?? ''))) ?: '<span class="text-muted">-</span>' ?></td>
                            <td><?= esc((string)($member['tenant_role'] ?? 'tenant_staff')) ?></td>
                            <td><?= esc((string)($member['app_user_id'] ?? '-')) ?></td>
                            <td class="member-meta">
                              <?php if ($isOwnerMember): ?>
                                <span class="label label-primary">owner</span>
                              <?php endif; ?>
                              <?php if ((int)($member['is_default'] ?? 0) === 1): ?>
                                <span class="label label-info">default</span>
                              <?php endif; ?>
                              <span class="label label-default"><?= esc((string)($member['invitation_status'] ?? 'pending')) ?></span>
                              <span class="label label-<?= ((string)($member['platform_user_status'] ?? '') === 'active') ? 'success' : 'warning' ?>">
                                <?= esc((string)($member['platform_user_status'] ?? 'invited')) ?>
                              </span>
                            </td>
                            <td>
                              <?php if ($isOwnerMember): ?>
                                <span class="text-muted">Master</span>
                              <?php else: ?>
                                <button
                                  class="btn btn-xs btn-primary js-member-edit"
                                  type="button"
                                  data-member-id="<?= esc((string)$memberId, 'attr') ?>"
                                  data-email="<?= esc((string)($member['email'] ?? ''), 'attr') ?>"
                                  data-first-name="<?= esc((string)($member['first_name'] ?? ''), 'attr') ?>"
                                  data-last-name="<?= esc((string)($member['last_name'] ?? ''), 'attr') ?>"
                                  data-role="<?= esc((string)($member['tenant_role'] ?? 'tenant_staff'), 'attr') ?>"
                                  data-app-user-id="<?= esc((string)($member['app_user_id'] ?? ''), 'attr') ?>"
                                  data-is-default="<?= esc((string)($member['is_default'] ?? 0), 'attr') ?>"
                                >
                                  <i class="fa fa-pencil"></i> Modifica
                                </button>
                                <form method="post" action="<?= portal_platform_url('spazi-clienti/members/accesso') ?>" style="display:inline-block; margin-top:4px;">
                                  <?= csrf_field() ?>
                                  <input type="hidden" name="id_tenant" value="<?= (int)$selectedTenantId ?>">
                                  <input type="hidden" name="member_id_platform_user_tenant" value="<?= esc((string)$memberId, 'attr') ?>">
                                  <button class="btn btn-xs btn-default" type="submit">
                                    <i class="fa fa-envelope"></i> Invia accesso
                                  </button>
                                </form>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>

                <form method="post" action="<?= portal_platform_url('spazi-clienti/members/save') ?>" id="tenant-member-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id_tenant" value="<?= (int)$selectedTenantId ?>">
                  <input type="hidden" name="member_id_platform_user_tenant" id="member_id_platform_user_tenant" value="<?= esc((string)$oldValue('member_id_platform_user_tenant', '')) ?>">
                  <input type="hidden" name="member_is_default" value="0">

                  <h4 id="member-form-title" style="margin-top:0;"><?= $isEditingMember ? 'Modifica utente dello spazio' : 'Aggiungi utente allo spazio' ?></h4>
                  <div class="row">
                    <div class="col-md-4">
                      <div class="form-group">
                        <label>Email utente *</label>
                        <input type="email" class="form-control" name="member_email" id="member_email" required value="<?= esc((string)$oldValue('member_email', '')) ?>">
                      </div>
                    </div>
                    <div class="col-md-2">
                      <div class="form-group">
                        <label>Nome</label>
                        <input class="form-control" name="member_first_name" id="member_first_name" value="<?= esc((string)$oldValue('member_first_name', '')) ?>">
                      </div>
                    </div>
                    <div class="col-md-2">
                      <div class="form-group">
                        <label>Cognome</label>
                        <input class="form-control" name="member_last_name" id="member_last_name" value="<?= esc((string)$oldValue('member_last_name', '')) ?>">
                      </div>
                    </div>
                    <div class="col-md-2">
                      <div class="form-group">
                        <label>Ruolo tenant</label>
                        <?php $memberRole = (string)$oldValue('member_tenant_role', 'tenant_staff'); ?>
                        <select class="form-control" name="member_tenant_role" id="member_tenant_role">
                          <option value="tenant_staff" <?= $memberRole === 'tenant_staff' ? 'selected' : '' ?>>tenant_staff</option>
                          <option value="tenant_admin" <?= $memberRole === 'tenant_admin' ? 'selected' : '' ?>>tenant_admin</option>
                        </select>
                      </div>
                    </div>
                    <div class="col-md-2">
                      <div class="form-group">
                        <label>App user ID</label>
                        <input type="number" class="form-control" name="member_app_user_id" id="member_app_user_id" value="<?= esc((string)$oldValue('member_app_user_id', '')) ?>">
                      </div>
                    </div>
                  </div>

                  <div class="row">
                    <div class="col-md-4">
                      <div class="form-group">
                        <label><?= $isEditingMember ? 'Nuova password' : 'Password iniziale' ?></label>
                        <input type="password" class="form-control" name="member_password" id="member_password" autocomplete="new-password">
                      </div>
                    </div>
                    <div class="col-md-4">
                      <div class="checkbox" style="margin-top:32px;">
                        <?php $memberIsDefault = (string)$oldValue('member_is_default', '0'); ?>
                        <label>
                          <input type="checkbox" name="member_is_default" id="member_is_default" value="1" <?= $memberIsDefault === '1' ? 'checked' : '' ?>>
                          Imposta come spazio predefinito per questo utente
                        </label>
                      </div>
                      <div class="checkbox">
                        <label>
                          <input type="checkbox" name="member_send_access_email" value="1" <?= (string)$oldValue('member_send_access_email', '0') === '1' ? 'checked' : '' ?>>
                          Invia accesso dopo il salvataggio
                        </label>
                      </div>
                    </div>
                  </div>

                  <div>
                    <button class="btn btn-info" type="submit" id="member-submit-button">
                      <i class="fa fa-user-plus"></i> <?= $isEditingMember ? 'Salva utente' : 'Aggiungi utente' ?>
                    </button>
                    <button class="btn btn-default" type="button" id="member-reset-button">
                      <i class="fa fa-refresh"></i> Nuovo inserimento
                    </button>
                  </div>
                </form>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </div>
</div>

<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
<script src="<?= base_url('public/dist/js/app.min.js') ?>"></script>
<script>
(function () {
  var form = document.getElementById('tenant-member-form');
  if (!form) {
    return;
  }

  var memberId = document.getElementById('member_id_platform_user_tenant');
  var memberEmail = document.getElementById('member_email');
  var memberFirstName = document.getElementById('member_first_name');
  var memberLastName = document.getElementById('member_last_name');
  var memberRole = document.getElementById('member_tenant_role');
  var memberAppUserId = document.getElementById('member_app_user_id');
  var memberPassword = document.getElementById('member_password');
  var memberIsDefault = document.getElementById('member_is_default');
  var memberTitle = document.getElementById('member-form-title');
  var memberSubmit = document.getElementById('member-submit-button');
  var memberReset = document.getElementById('member-reset-button');

  function resetForm() {
    if (memberId) memberId.value = '';
    if (memberEmail) memberEmail.value = '';
    if (memberFirstName) memberFirstName.value = '';
    if (memberLastName) memberLastName.value = '';
    if (memberRole) memberRole.value = 'tenant_staff';
    if (memberAppUserId) memberAppUserId.value = '';
    if (memberPassword) memberPassword.value = '';
    if (memberIsDefault) memberIsDefault.checked = false;
    if (memberTitle) memberTitle.textContent = 'Aggiungi utente allo spazio';
    if (memberSubmit) memberSubmit.innerHTML = '<i class="fa fa-user-plus"></i> Aggiungi utente';
  }

  document.querySelectorAll('.js-member-edit').forEach(function (button) {
    button.addEventListener('click', function () {
      if (memberId) memberId.value = button.getAttribute('data-member-id') || '';
      if (memberEmail) memberEmail.value = button.getAttribute('data-email') || '';
      if (memberFirstName) memberFirstName.value = button.getAttribute('data-first-name') || '';
      if (memberLastName) memberLastName.value = button.getAttribute('data-last-name') || '';
      if (memberRole) memberRole.value = button.getAttribute('data-role') || 'tenant_staff';
      if (memberAppUserId) memberAppUserId.value = button.getAttribute('data-app-user-id') || '';
      if (memberPassword) memberPassword.value = '';
      if (memberIsDefault) memberIsDefault.checked = (button.getAttribute('data-is-default') || '0') === '1';
      if (memberTitle) memberTitle.textContent = 'Modifica utente dello spazio';
      if (memberSubmit) memberSubmit.innerHTML = '<i class="fa fa-save"></i> Salva utente';
      window.location.hash = 'tenant-members';
    });
  });

  if (memberReset) {
    memberReset.addEventListener('click', function () {
      resetForm();
    });
  }
})();
</script>
</body>
</html>
