<?php
helper('portal');

$accounts = is_array($accounts ?? null) ? $accounts : [];
$disconnectUrl = trim((string) ($disconnectUrl ?? ''));
$tenantId = (int) ($tenantId ?? 0);
$emptyMessage = trim((string) ($emptyMessage ?? 'Nessun account disponibile.'));

$formatDateTime = static function (?string $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '-';
    }

    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone('Europe/Rome'))
            ->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return $value;
    }
};
?>
<div class="table-responsive">
  <table class="table table-bordered table-hover">
    <thead>
      <tr>
        <th>Account</th>
        <th>Ruolo</th>
        <th>Mapping agenda</th>
        <th>Dispositivo OTP</th>
        <th>Stato</th>
        <th style="width:120px;">Azioni</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($accounts === []): ?>
        <tr>
          <td colspan="6" class="text-muted"><?= esc($emptyMessage) ?></td>
        </tr>
      <?php else: ?>
        <?php foreach ($accounts as $account): ?>
          <?php
            $fullName = trim((string) ($account['full_name'] ?? ''));
            $email = trim((string) ($account['email'] ?? ''));
            $appUserId = (int) ($account['app_user_id'] ?? 0);
            $appUsername = trim((string) ($account['app_username'] ?? ''));
            $hasActiveDevice = !empty($account['has_active_device']);
          ?>
          <tr>
            <td>
              <strong><?= esc($fullName !== '' ? $fullName : $email) ?></strong><br>
              <span class="text-muted"><?= esc($email !== '' ? $email : '-') ?></span>
            </td>
            <td>
              <?= esc(portal_space_role_label((string) ($account['tenant_role'] ?? 'tenant_staff'))) ?>
            </td>
            <td>
              <?php if ($appUserId > 0): ?>
                <strong>ID:</strong> <?= $appUserId ?><br>
                <span class="text-muted">
                  <?= esc($appUsername !== '' ? $appUsername : 'Utente agenda senza username leggibile') ?>
                </span>
              <?php else: ?>
                <span class="text-warning">Account non collegato a un utente agenda.</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($hasActiveDevice): ?>
                <strong><?= esc((string) ($account['device_label'] ?? 'Dispositivo mobile')) ?></strong><br>
                <span class="text-muted">
                  <?= esc(trim((string) ($account['device_os'] ?? '') . ' ' . (string) ($account['device_type'] ?? ''))) ?>
                </span><br>
                <span class="text-muted">Ultimo contatto: <?= esc($formatDateTime((string) ($account['last_seen'] ?? ''))) ?></span>
              <?php else: ?>
                <span class="text-muted">Nessun dispositivo attivo collegato.</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($account['is_owner'])): ?>
                <span class="label label-primary">responsabile</span>
              <?php endif; ?>
              <?php if (!empty($account['is_default'])): ?>
                <span class="label label-info">predefinito</span>
              <?php endif; ?>
              <?php if (!empty($account['is_app_admin'])): ?>
                <span class="label label-primary">medico amministratore</span>
              <?php endif; ?>
              <?php if (trim((string) ($account['invitation_status'] ?? '')) !== ''): ?>
                <span class="label label-default"><?= esc((string) ($account['invitation_status'] ?? '')) ?></span>
              <?php endif; ?>
              <?php if (trim((string) ($account['platform_user_status'] ?? '')) !== ''): ?>
                <span class="label label-<?= ((string) ($account['platform_user_status'] ?? '') === 'active') ? 'success' : 'warning' ?>">
                  <?= esc((string) ($account['platform_user_status'] ?? '')) ?>
                </span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($hasActiveDevice && $disconnectUrl !== ''): ?>
                <form method="post" action="<?= esc($disconnectUrl) ?>">
                  <?= csrf_field() ?>
                  <?php if ($tenantId > 0): ?>
                    <input type="hidden" name="id_tenant" value="<?= $tenantId ?>">
                  <?php endif; ?>
                  <input type="hidden" name="membership_id_platform_user_tenant" value="<?= (int) ($account['membership_id'] ?? 0) ?>">
                  <button
                    type="submit"
                    class="btn btn-xs btn-danger"
                    onclick="return confirm('Vuoi disassociare il dispositivo OTP collegato a questo account?');"
                  >
                    <i class="fa fa-unlink"></i> Disassocia
                  </button>
                </form>
              <?php else: ?>
                <span class="text-muted">Nessuna azione</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>
