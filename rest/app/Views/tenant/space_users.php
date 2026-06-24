<?php
helper('portal');

$menuDataAdmin = session()->get('menuDataAdmin');
$sidebarMenuItems = is_array($menuDataAdmin['result'] ?? null) ? $menuDataAdmin['result'] : [];
$headerMenuItems = $sidebarMenuItems !== [] ? $sidebarMenuItems : (session()->get('header_menu_items') ?? []);

$tenantMembers = is_array($tenantMembers ?? null) ? $tenantMembers : [];
$tenantCapacity = is_array($tenantCapacity ?? null) ? $tenantCapacity : [];
$memberErrors = is_array($memberErrors ?? null) ? $memberErrors : [];
$memberSuccess = $memberSuccess ?? null;
$memberTempPassword = trim((string)($memberTempPassword ?? ''));
$memberWarnings = is_array($memberWarnings ?? null) ? $memberWarnings : [];
$tenant = is_array($tenant ?? null) ? $tenant : [];
$tenantName = trim((string)($tenantContext->tenantName ?? ($tenant['tenant_name'] ?? 'Studio cliente')));
$editingMemberId = (int) old('member_id_platform_user_tenant');
$isEditingMember = $editingMemberId > 0;

$oldValue = static function (string $key, $fallback = '') {
    $old = old($key);
    return $old !== null ? $old : $fallback;
};
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Utenti Studio</title>
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
    .workspace-hero {
      border: 1px solid #dbe8eb;
      border-radius: 16px;
      padding: 20px 22px;
      background: linear-gradient(135deg, #f8fcfc 0%, #eff7f8 100%);
      margin-bottom: 18px;
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
    .member-meta .label {
      display: inline-block;
      margin: 0 6px 6px 0;
    }
  </style>
</head>
<body class="skin-blue sidebar-mini">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $headerMenuItems, 'portal_console_header' => false]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Utenti dello studio</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Gestisci gli accessi del tuo studio senza intervenire sulla configurazione tecnica del database.
      </p>
    </section>

    <section class="content">
      <div class="row">
        <div class="col-md-3">
          <?= view('partials/sidebar_admin', ['menu_items' => $sidebarMenuItems]) ?>
        </div>

        <div class="col-md-9">
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

          <?php
            $capacityCurrent = (int)($tenantCapacity['current_users'] ?? 0);
            $capacityMax = $tenantCapacity['max_users'] ?? null;
            $capacityRemaining = $tenantCapacity['remaining_users'] ?? null;
            $capacityLabel = $capacityMax === null ? 'Illimitato' : ((string)$capacityMax);
          ?>

          <div class="workspace-hero">
            <h3 style="margin-top:0; margin-bottom:8px;"><?= esc($tenantName) ?></h3>
            <p style="margin:0 0 12px 0; color:#52676c;">
              Qui puoi invitare o aggiornare gli utenti del tuo studio. La configurazione del database resta gestita dall'amministrazione centrale.
            </p>
            <span class="summary-badge">Pacchetto: <?= esc((string)($tenantContext->packageName ?? $tenantContext->packageCode ?? '')) ?></span>
            <span class="summary-badge">Utenti attuali: <?= esc((string)$capacityCurrent) ?></span>
            <span class="summary-badge">Limite: <?= esc($capacityLabel) ?></span>
            <?php if ($capacityRemaining !== null): ?>
              <span class="summary-badge">Posti liberi: <?= esc((string)$capacityRemaining) ?></span>
            <?php endif; ?>
          </div>

          <div class="box box-primary">
            <div class="box-header with-border">
              <h3 class="box-title">Utenti configurati</h3>
            </div>
            <div class="box-body table-responsive">
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
                        $fullName = trim((string)($member['first_name'] ?? '') . ' ' . (string)($member['last_name'] ?? ''));
                        $memberRole = (string)($member['tenant_role'] ?? 'tenant_staff');
                      ?>
                      <tr>
                        <td><?= esc((string)($member['email'] ?? '')) ?></td>
                        <td><?= $fullName !== '' ? esc($fullName) : '<span class="text-muted">-</span>' ?></td>
                        <td><?= esc(portal_space_role_label($memberRole)) ?></td>
                        <td><?= esc((string)($member['app_user_id'] ?? '-')) ?></td>
                        <td class="member-meta">
                          <?php if ($isOwnerMember): ?>
                            <span class="label label-primary">responsabile</span>
                          <?php endif; ?>
                          <?php if ((int)($member['is_default'] ?? 0) === 1): ?>
                            <span class="label label-info">predefinito</span>
                          <?php endif; ?>
                          <?php if ((int)($member['is_app_admin'] ?? 0) === 1): ?>
                            <span class="label label-primary">medico amministratore</span>
                          <?php endif; ?>
                          <span class="label label-default"><?= esc((string)($member['invitation_status'] ?? 'pending')) ?></span>
                          <span class="label label-<?= ((string)($member['platform_user_status'] ?? '') === 'active') ? 'success' : 'warning' ?>">
                            <?= esc((string)($member['platform_user_status'] ?? 'invited')) ?>
                          </span>
                        </td>
                        <td>
                          <?php if ($isOwnerMember): ?>
                            <span class="text-muted">Responsabile</span>
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
                              data-is-app-admin="<?= esc((string)($member['is_app_admin'] ?? 0), 'attr') ?>"
                              data-is-default="<?= esc((string)($member['is_default'] ?? 0), 'attr') ?>"
                            >
                              <i class="fa fa-pencil"></i> Modifica
                            </button>
                            <form method="post" action="<?= portal_tenant_space_url('utenti/accesso') ?>" style="display:inline-block; margin-top:4px;">
                              <?= csrf_field() ?>
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
          </div>

          <div class="box box-success">
            <div class="box-header with-border">
              <h3 class="box-title"><?= $isEditingMember ? 'Modifica utente' : 'Aggiungi utente' ?></h3>
            </div>
            <form method="post" action="<?= portal_tenant_space_url('utenti/save') ?>" id="tenant-member-form">
              <?= csrf_field() ?>
              <input type="hidden" name="member_id_platform_user_tenant" id="member_id_platform_user_tenant" value="<?= esc((string)$oldValue('member_id_platform_user_tenant', '')) ?>">
              <input type="hidden" name="member_is_default" value="0">

              <div class="box-body">
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
                      <label>Ruolo nello studio</label>
                      <?php $memberRole = (string)$oldValue('member_tenant_role', 'tenant_staff'); ?>
                      <select class="form-control" name="member_tenant_role" id="member_tenant_role">
                        <option value="tenant_staff" <?= $memberRole === 'tenant_staff' ? 'selected' : '' ?>><?= esc(portal_space_role_label('tenant_staff')) ?></option>
                        <option value="tenant_admin" <?= $memberRole === 'tenant_admin' ? 'selected' : '' ?>><?= esc(portal_space_role_label('tenant_admin')) ?></option>
                      </select>
                    </div>
                  </div>
                  <div class="col-md-2">
                    <div class="form-group">
                      <label>ID utente agenda</label>
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
                  <div class="col-md-5">
                    <p class="text-muted" style="margin-top:32px;">
                      Se lasci vuoto l'ID utente agenda, il sistema proverà a collegare automaticamente l'utente tramite la stessa email presente nel database del tuo studio.
                    </p>
                    <div class="checkbox" style="margin-top:12px;">
                      <?php $memberIsAppAdmin = (string)$oldValue('member_is_app_admin', '0'); ?>
                      <label>
                        <input type="checkbox" name="member_is_app_admin" id="member_is_app_admin" value="1" <?= $memberIsAppAdmin === '1' ? 'checked' : '' ?>>
                        Medico amministratore
                      </label>
                      <p class="text-muted" style="margin:6px 0 0 24px;">
                        Attivalo solo se l'ID utente agenda punta a un medico che deve vedere anche il menu amministrativo dell'agenda.
                      </p>
                    </div>
                  </div>
                  <div class="col-md-3">
                    <div class="checkbox" style="margin-top:32px;">
                      <?php $memberIsDefault = (string)$oldValue('member_is_default', '0'); ?>
                      <label>
                        <input type="checkbox" name="member_is_default" id="member_is_default" value="1" <?= $memberIsDefault === '1' ? 'checked' : '' ?>>
                        Spazio predefinito
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
              </div>

              <div class="box-footer">
                <button class="btn btn-success" type="submit" id="member-submit-button">
                  <i class="fa fa-user-plus"></i> <?= $isEditingMember ? 'Salva utente' : 'Aggiungi utente' ?>
                </button>
                <button class="btn btn-default" type="button" id="member-reset-button">
                  <i class="fa fa-refresh"></i> Nuovo inserimento
                </button>
              </div>
            </form>
          </div>
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
  var memberIsAppAdmin = document.getElementById('member_is_app_admin');
  var memberPassword = document.getElementById('member_password');
  var memberIsDefault = document.getElementById('member_is_default');
  var memberSubmit = document.getElementById('member-submit-button');

  function resetForm() {
    if (memberId) memberId.value = '';
    if (memberEmail) memberEmail.value = '';
    if (memberFirstName) memberFirstName.value = '';
    if (memberLastName) memberLastName.value = '';
    if (memberRole) memberRole.value = 'tenant_staff';
    if (memberAppUserId) memberAppUserId.value = '';
    if (memberIsAppAdmin) memberIsAppAdmin.checked = false;
    if (memberPassword) memberPassword.value = '';
    if (memberIsDefault) memberIsDefault.checked = false;
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
      if (memberIsAppAdmin) memberIsAppAdmin.checked = (button.getAttribute('data-is-app-admin') || '0') === '1';
      if (memberPassword) memberPassword.value = '';
      if (memberIsDefault) memberIsDefault.checked = (button.getAttribute('data-is-default') || '0') === '1';
      if (memberSubmit) memberSubmit.innerHTML = '<i class="fa fa-save"></i> Salva utente';
      window.scrollTo({ top: form.offsetTop - 80, behavior: 'smooth' });
    });
  });

  var memberReset = document.getElementById('member-reset-button');
  if (memberReset) {
    memberReset.addEventListener('click', function () {
      resetForm();
    });
  }
})();
</script>
</body>
</html>
