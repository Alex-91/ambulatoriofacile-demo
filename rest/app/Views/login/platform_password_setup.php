<?php
helper('portal');
$platformUser = is_array($platformUser ?? null) ? $platformUser : [];
$tenant = is_array($tenant ?? null) ? $tenant : null;
$errors = is_array($errors ?? null) ? $errors : [];
$token = trim((string) ($token ?? ''));
$isPlatformAdmin = (bool) ($isPlatformAdmin ?? false);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Imposta password</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="<?= base_url('public/assets/css/login.css'); ?>">
  <style>
    .setup-box {
      margin: 0 0 16px;
      padding: 16px 18px;
      border-radius: 18px;
      background: rgba(44, 136, 149, 0.08);
      border: 1px solid rgba(44, 136, 149, 0.16);
      text-align: left;
      color: #1d5058;
      line-height: 1.5;
    }
    .setup-box strong {
      display: block;
      margin-bottom: 4px;
    }
    .rules-list {
      margin: 10px 0 0;
      padding-left: 18px;
      color: #4f5f65;
      text-align: left;
    }
    .flash-error {
      margin: 0 0 14px;
      padding: 12px 14px;
      border-radius: 14px;
      background: rgba(220, 53, 69, 0.08);
      border: 1px solid rgba(220, 53, 69, 0.16);
      color: #8f2130;
      text-align: left;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="wrapper">
      <div class="title" style="background-image:url('<?= base_url('public/assets/images/logonew.jpg'); ?>'); background-size:contain; background-repeat:no-repeat; background-position:center;"></div>

      <?php if (!empty($errors['generic'])): ?>
        <div class="flash-error"><?= esc((string) $errors['generic']) ?></div>
      <?php endif; ?>

      <div class="setup-box">
        <strong>Imposta la tua password di accesso</strong>
        Account: <?= esc((string) ($platformUser['email'] ?? '')) ?><br>
        <?php if ($tenant !== null): ?>
          Spazio: <?= esc((string) ($tenant['tenant_name'] ?? $tenant['tenant_key'] ?? '')) ?><br>
        <?php endif; ?>
        <?php if ($isPlatformAdmin && $tenant === null): ?>
          Dopo il salvataggio entrerai dal login unico e potrai aprire la console piattaforma sotto <code>/login</code>.
        <?php else: ?>
          Dopo il salvataggio entrerai dal login unico e vedrai solo i tuoi spazi.
        <?php endif; ?>
      </div>

      <form action="<?= portal_public_access_url('login/password-imposta') ?>" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= esc($token) ?>">

        <div class="row">
          <input type="password" name="password" placeholder="Nuova password" autocomplete="new-password" required>
        </div>
        <div class="row">
          <input type="password" name="password2" placeholder="Ripeti la password" autocomplete="new-password" required>
        </div>
        <ul class="rules-list">
          <li>Almeno 8 caratteri</li>
          <li>Almeno una lettera maiuscola</li>
          <li>Almeno una lettera minuscola</li>
          <li>Almeno un carattere speciale</li>
        </ul>
        <div class="row button" style="margin-top:14px;">
          <input type="submit" value="Salva password">
        </div>
      </form>
    </div>
  </div>
</body>
</html>
