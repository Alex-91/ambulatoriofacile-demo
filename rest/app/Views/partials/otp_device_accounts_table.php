<?php

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
        <th>Profilo</th>
        <th>Username</th>
        <th>Dispositivi OTP</th>
        <th>Stato</th>
        <th style="width:140px;">Azioni</th>
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
            $cellulare = trim((string) ($account['cellulare'] ?? ''));
            $username = trim((string) ($account['username'] ?? ''));
            $deviceCount = (int) ($account['device_count'] ?? 0);
            $hasActiveDevice = !empty($account['has_active_device']);
          ?>
          <tr>
            <td>
              <strong><?= esc($fullName !== '' ? $fullName : ('Account #' . (int) ($account['app_user_id'] ?? 0))) ?></strong><br>
              <?php if ($email !== ''): ?>
                <span class="text-muted"><?= esc($email) ?></span><br>
              <?php endif; ?>
              <span class="text-muted"><?= esc($cellulare !== '' ? $cellulare : 'Cellulare non disponibile') ?></span>
            </td>
            <td>
              <strong><?= esc((string) ($account['user_type_label'] ?? 'Account applicativo')) ?></strong><br>
              <span class="text-muted">ID utente: <?= (int) ($account['app_user_id'] ?? 0) ?></span>
            </td>
            <td>
              <?php if ($username !== ''): ?>
                <strong><?= esc($username) ?></strong>
              <?php else: ?>
                <span class="text-warning">Username non disponibile</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($hasActiveDevice): ?>
                <strong><?= esc((string) ($account['device_label'] ?? 'Dispositivo mobile')) ?></strong><br>
                <span class="text-muted">
                  <?= esc(trim((string) ($account['device_os'] ?? '') . ' ' . (string) ($account['device_type'] ?? ''))) ?>
                </span><br>
                <span class="text-muted">Device attivi: <?= $deviceCount ?></span>
              <?php else: ?>
                <span class="text-muted">Nessun dispositivo attivo collegato.</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($hasActiveDevice): ?>
                <span class="label label-success">OTP attivo</span>
              <?php else: ?>
                <span class="label label-default">Nessun device</span>
              <?php endif; ?>
              <?php if ($deviceCount > 1): ?>
                <span class="label label-warning"><?= $deviceCount ?> device</span>
              <?php endif; ?>
              <?php if (empty($account['is_runtime_active'])): ?>
                <span class="label label-danger">account disattivato</span>
              <?php endif; ?>
              <div class="text-muted" style="margin-top:6px;">
                Ultimo contatto: <?= esc($formatDateTime((string) ($account['last_seen'] ?? ''))) ?>
              </div>
            </td>
            <td>
              <?php if ($hasActiveDevice && $disconnectUrl !== ''): ?>
                <form method="post" action="<?= esc($disconnectUrl) ?>">
                  <?= csrf_field() ?>
                  <?php if ($tenantId > 0): ?>
                    <input type="hidden" name="id_tenant" value="<?= $tenantId ?>">
                  <?php endif; ?>
                  <input type="hidden" name="app_user_id" value="<?= (int) ($account['app_user_id'] ?? 0) ?>">
                  <button
                    type="submit"
                    class="btn btn-xs btn-danger"
                    onclick="return confirm('Vuoi disassociare tutti i dispositivi OTP attivi collegati a questo account?');"
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
