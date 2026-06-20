<?php
$success = $success ?? null;
$errors = is_array($errors ?? null) ? $errors : [];
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Recupero accesso</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="<?= base_url('public/assets/css/login.css'); ?>">
  <style>
    .access-card {
      margin-top: 28px;
      padding: 18px 20px;
      border-radius: 18px;
      background: rgba(44, 136, 149, 0.08);
      border: 1px solid rgba(44, 136, 149, 0.16);
      text-align: left;
    }
    .access-card h3 {
      margin: 0 0 8px;
      color: #1d5058;
    }
    .access-card p {
      color: #4f5f65;
      line-height: 1.45;
      margin: 0 0 12px;
    }
    .access-card a {
      color: #1c6670;
      font-weight: 700;
      text-decoration: none;
    }
    .flash-box {
      margin: 0 0 14px;
      padding: 12px 14px;
      border-radius: 14px;
      line-height: 1.45;
    }
    .flash-success {
      background: rgba(40, 167, 69, 0.08);
      border: 1px solid rgba(40, 167, 69, 0.18);
      color: #1f6b35;
    }
    .flash-error {
      background: rgba(220, 53, 69, 0.08);
      border: 1px solid rgba(220, 53, 69, 0.16);
      color: #8f2130;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="wrapper">
      <div class="title" style="background-image:url('<?= base_url('public/assets/images/logonew.jpg'); ?>'); background-size:contain; background-repeat:no-repeat; background-position:center;"></div>

      <?php if ($success): ?>
        <div class="flash-box flash-success"><?= esc((string) $success) ?></div>
      <?php endif; ?>
      <?php if (!empty($errors['generic'])): ?>
        <div class="flash-box flash-error"><?= esc((string) $errors['generic']) ?></div>
      <?php endif; ?>

      <form action="<?= site_url('login/recupero/invia') ?>" method="post">
        <?= csrf_field() ?>
        <div class="access-card">
          <h3>Recupera accesso spazi cliente</h3>
          <p>Inserisci l email che usi nel login unico. Se l account esiste, riceverai un link per impostare o reimpostare la password.</p>
        </div>

        <div class="row">
          <input type="email" name="email" placeholder="La tua email di accesso" value="<?= esc((string) old('email')) ?>" required autocomplete="email">
        </div>
        <div class="row button">
          <input type="submit" value="Invia link di accesso">
        </div>
        <div class="access-card" style="margin-top:12px;">
          <h3>Hai un account legacy?</h3>
          <p>Se usi ancora il vecchio login con username o codice fiscale, continua con il recupero password storico.</p>
          <a href="<?= site_url('reset') ?>">Apri recupero password legacy</a>
        </div>
        <div class="row button" style="margin-top:12px;">
          <input type="button" value="Torna al login" onclick="window.location.href='<?= site_url('login') ?>'">
        </div>
      </form>
    </div>
  </div>
</body>
</html>
