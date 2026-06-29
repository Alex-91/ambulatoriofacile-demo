<?php
helper('portal');

$tenantRows = is_array($tenantRows ?? null) ? $tenantRows : [];
$selectedTenant = is_array($selectedTenant ?? null) ? $selectedTenant : [];
$accounts = is_array($accounts ?? null) ? $accounts : [];
$summary = is_array($summary ?? null) ? $summary : [];
$errors = is_array($errors ?? null) ? $errors : [];
$runtimeWarning = trim((string) ($runtimeWarning ?? ''));
$success = $success ?? null;
$legacyBootstrapMode = (bool) ($legacyBootstrapMode ?? false);
$platformBootstrapWarnings = is_array($platformBootstrapWarnings ?? null) ? $platformBootstrapWarnings : [];
$selectedTenantName = trim((string) ($selectedTenant['tenant_name'] ?? ''));
$searchQuery = trim((string) ($searchQuery ?? ''));
$activeImpersonation = is_array($activeImpersonation ?? null) ? $activeImpersonation : null;
$expiresInMinutes = $activeImpersonation !== null
    ? max(1, (int) ceil(max(0, ((int) ($activeImpersonation['expires_at'] ?? 0)) - time()) / 60))
    : 0;
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Accesso delegato</title>
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
    .impersonation-table td,
    .impersonation-table th {
      vertical-align: middle !important;
    }
    .impersonation-reason {
      min-width: 240px;
    }
    .status-pill {
      display: inline-block;
      padding: 4px 8px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .02em;
    }
    .status-pill--ok {
      background: #e5f5ea;
      color: #207245;
    }
    .status-pill--muted {
      background: #eef2f3;
      color: #5b6b70;
    }
  </style>
</head>
<body class="platform-console-body">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items ?? [], 'portal_console_header' => true]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Accesso delegato</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Solo gli account master piattaforma possono aprire una sessione temporanea come un utente dello spazio senza conoscere la sua password, con audit persistente, motivo obbligatorio, banner visibile e rientro rapido alla console master.
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
          <?php foreach ($platformBootstrapWarnings as $bootstrapWarning): ?>
            <div class="alert <?= $legacyBootstrapMode ? 'alert-info' : 'alert-warning' ?>"><?= esc((string) $bootstrapWarning) ?></div>
          <?php endforeach; ?>
          <?php if ($runtimeWarning !== ''): ?>
            <div class="alert alert-warning"><?= esc($runtimeWarning) ?></div>
          <?php endif; ?>

          <?php if ($activeImpersonation !== null): ?>
            <div class="alert alert-info">
              Sessione delegata gia attiva come <strong><?= esc((string) ($activeImpersonation['target_display_name'] ?? $activeImpersonation['target_username'] ?? 'account')) ?></strong>
              nello spazio <strong><?= esc((string) ($activeImpersonation['tenant_name'] ?? '')) ?></strong>.
              Motivo: <strong><?= esc((string) ($activeImpersonation['reason'] ?? '')) ?></strong>.
              Scadenza stimata tra circa <strong><?= $expiresInMinutes ?></strong> minuti.
            </div>
          <?php endif; ?>

          <div class="intro-box">
            <h3 style="margin-top:0; margin-bottom:8px;">Impersonificazione operativa controllata</h3>
            <p style="margin:0 0 12px 0; color:#52676c;">
              Seleziona lo spazio, filtra l account da aprire e avvia una sessione temporanea. Ogni accesso delegato resta tracciato con inizio, fine e motivo.
            </p>
            <?php if ($selectedTenantName !== ''): ?>
              <span class="summary-badge">Spazio selezionato: <?= esc($selectedTenantName) ?></span>
            <?php endif; ?>
            <span class="summary-badge">Account visibili: <?= (int) ($summary['total_accounts'] ?? 0) ?></span>
            <span class="summary-badge">Account attivi: <?= (int) ($summary['active_accounts'] ?? 0) ?></span>
            <span class="summary-badge">Pazienti: <?= (int) ($summary['patient_accounts'] ?? 0) ?></span>
          </div>

          <div class="box box-default">
            <div class="box-header with-border">
              <h3 class="box-title">Seleziona spazio e filtra gli account</h3>
            </div>
            <div class="box-body">
              <form method="get" action="<?= portal_platform_url('impersonificazione') ?>" class="form-inline">
                <div class="form-group" style="min-width:280px; margin-right:10px;">
                  <label class="sr-only" for="id_tenant">Spazio</label>
                  <select class="form-control" id="id_tenant" name="id_tenant" style="min-width:280px;">
                    <?php foreach ($tenantRows as $tenantRow): ?>
                      <?php $tenantId = (int) ($tenantRow['id_tenant'] ?? 0); ?>
                      <option value="<?= $tenantId ?>" <?= $tenantId === (int) ($selectedTenantId ?? 0) ? 'selected' : '' ?>>
                        <?= esc((string) ($tenantRow['tenant_name'] ?? $tenantRow['tenant_key'] ?? 'Spazio cliente')) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group" style="min-width:260px; margin-right:10px;">
                  <label class="sr-only" for="q">Filtro account</label>
                  <input
                    type="text"
                    class="form-control"
                    id="q"
                    name="q"
                    value="<?= esc($searchQuery) ?>"
                    placeholder="Cerca per nome, username, email o ruolo"
                    style="min-width:260px;">
                </div>
                <button type="submit" class="btn btn-primary">
                  <i class="fa fa-search"></i> Aggiorna elenco
                </button>
              </form>
            </div>
          </div>

          <div class="box box-primary">
            <div class="box-header with-border">
              <h3 class="box-title">Apri un account come sessione delegata</h3>
            </div>
            <div class="box-body table-responsive">
              <?php if ($accounts === []): ?>
                <div class="alert alert-info" style="margin-bottom:0;">
                  Nessun account disponibile per lo spazio selezionato con i filtri correnti.
                </div>
              <?php else: ?>
                <table class="table table-striped table-hover impersonation-table">
                  <thead>
                    <tr>
                      <th>Account</th>
                      <th>Profilo</th>
                      <th>Ruolo spazio</th>
                      <th>Contatti</th>
                      <th>Stato</th>
                      <th style="min-width:320px;">Avvio accesso delegato</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($accounts as $account): ?>
                      <?php
                        $accountId = (int) ($account['app_user_id'] ?? 0);
                        $runtimeActive = !empty($account['is_runtime_active']);
                        $passwordStatus = strtolower(trim((string) ($account['password_status'] ?? 'ok')));
                      ?>
                      <tr>
                        <td>
                          <strong><?= esc((string) ($account['full_name'] ?? 'Account')) ?></strong><br>
                          <small class="text-muted"><?= esc((string) ($account['username'] ?? '')) ?> · ID <?= $accountId ?></small>
                        </td>
                        <td>
                          <strong><?= esc((string) ($account['user_type_label'] ?? 'Profilo')) ?></strong><br>
                          <small class="text-muted">Tipo runtime: <?= (int) ($account['tipo_user'] ?? 0) ?></small>
                        </td>
                        <td>
                          <strong><?= esc((string) ($account['tenant_role_label'] ?? 'Profilo operativo')) ?></strong>
                          <?php if (!empty($account['platform_user_email'])): ?>
                            <br><small class="text-muted"><?= esc((string) $account['platform_user_email']) ?></small>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if (!empty($account['email'])): ?>
                            <div><?= esc((string) $account['email']) ?></div>
                          <?php endif; ?>
                          <?php if (!empty($account['cellulare'])): ?>
                            <small class="text-muted"><?= esc((string) $account['cellulare']) ?></small>
                          <?php endif; ?>
                        </td>
                        <td>
                          <span class="status-pill <?= $runtimeActive ? 'status-pill--ok' : 'status-pill--muted' ?>">
                            <?= $runtimeActive ? 'attivo' : 'non attivo' ?>
                          </span>
                          <br>
                          <small class="text-muted">
                            Password: <?= $passwordStatus === 'scadenza' ? 'scaduta' : 'ok' ?>
                          </small>
                        </td>
                        <td>
                          <form method="post" action="<?= portal_platform_url('impersonificazione/start') ?>" class="form-inline">
                            <input type="hidden" name="id_tenant" value="<?= (int) ($selectedTenantId ?? 0) ?>">
                            <input type="hidden" name="app_user_id" value="<?= $accountId ?>">
                            <input
                              type="text"
                              name="reason"
                              class="form-control impersonation-reason"
                              placeholder="Motivo obbligatorio: es. supporto ticket, verifica accesso, test flusso"
                              required
                              minlength="8">
                            <button type="submit" class="btn btn-primary" <?= $runtimeActive ? '' : 'disabled' ?>>
                              <i class="fa fa-user-secret"></i> Apri come questo utente
                            </button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
</div>
</body>
</html>
