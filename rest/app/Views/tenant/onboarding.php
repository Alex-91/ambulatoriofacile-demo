<?php
helper('portal');

$tenant = is_array($tenant ?? null) ? $tenant : [];
$capacity = is_array($capacity ?? null) ? $capacity : [];
$errors = is_array($errors ?? null) ? $errors : [];
$success = $success ?? null;
$tenantName = trim((string) ($tenantContext->tenantName ?? ($tenant['tenant_name'] ?? 'Spazio cliente')));
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Onboarding spazio</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/assets/css/platform-console.css') ?>" rel="stylesheet" />
  <style>
    .hero-box {
      border: 1px solid #dbe8eb;
      border-radius: 16px;
      padding: 22px 24px;
      background: linear-gradient(135deg, #f8fcfc 0%, #eff7f8 100%);
      margin-bottom: 18px;
    }
    .check-card {
      border: 1px solid #e5ecee;
      border-radius: 12px;
      padding: 16px 18px;
      margin-bottom: 14px;
      background: #fff;
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
  </style>
</head>
<body class="platform-console-body">
<div class="wrapper">
  <?= view('partials/header_portal_console', ['menu_items' => session()->get('header_menu_items') ?? []]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Onboarding iniziale2</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">Completa i passaggi essenziali del tuo spazio prima di iniziare a lavorare con il team.</p>
    </section>

    <section class="content">
      <div class="row">
        <div class="col-md-10 col-md-offset-1">
          <?php if ($success): ?>
            <div class="alert alert-success"><?= esc((string) $success) ?></div>
          <?php endif; ?>
          <?php if (!empty($errors['generic'])): ?>
            <div class="alert alert-danger"><?= esc((string) $errors['generic']) ?></div>
          <?php endif; ?>

          <div class="hero-box">
            <h3 style="margin-top:0; margin-bottom:8px;"><?= esc($tenantName) ?></h3>
            <p style="margin:0 0 12px 0; color:#52676c;">
              Hai gia il login unico attivo. Ora puoi configurare il tuo team e verificare i moduli del pacchetto prima di andare a regime.
            </p>
            <span class="summary-badge">Pacchetto: <?= esc((string) ($tenantContext->packageName ?? $tenantContext->packageCode ?? '')) ?></span>
            <span class="summary-badge">Utenti attuali: <?= esc((string) ($capacity['current_users'] ?? 0)) ?></span>
            <span class="summary-badge">Onboarding: <?= esc((string) ($tenantContext->onboardingStatus ?? 'setup')) ?></span>
          </div>

          <div class="check-card">
            <h4 style="margin-top:0;">1. Verifica accesso master</h4>
            <p class="text-muted" style="margin-bottom:0;">Hai gia completato la parte piu importante: il login unico del tenant master. Da ora l accesso passa sempre da `ambulatoriofacile.it/login`.</p>
          </div>

          <div class="check-card">
            <h4 style="margin-top:0;">2. Invita il team del cliente</h4>
            <p class="text-muted">Aggiungi collaboratori e segreteria del tuo spazio, nel limite del pacchetto assegnato.</p>
            <a href="<?= portal_tenant_space_url('utenti') ?>" class="btn btn-default btn-flat">
              <i class="fa fa-users"></i> Gestisci utenti dello spazio
            </a>
          </div>

          <div class="check-card">
            <h4 style="margin-top:0;">3. Controlla moduli e verticalizzazioni</h4>
            <p class="text-muted" style="margin-bottom:0;">Agenda, posta, chat e le eventuali verticalizzazioni abilitate per il tuo pacchetto saranno visibili solo nel tuo spazio.</p>
          </div>

          <div class="check-card">
            <h4 style="margin-top:0;">4. Chiudi onboarding</h4>
            <p class="text-muted">Quando hai verificato che il team puo entrare e che il pacchetto e corretto, chiudi l onboarding iniziale.</p>
            <form action="<?= portal_tenant_space_url('onboarding/completa') ?>" method="post" style="display:inline-block;">
              <?= csrf_field() ?>
              <button type="submit" class="btn btn-success">
                <i class="fa fa-check"></i> Conferma onboarding completato
              </button>
            </form>
            <a href="<?= site_url('/') ?>" class="btn btn-default">
              Vai alla home dello spazio
            </a>
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
