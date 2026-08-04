<?php
$menu_items = $menu_items ?? ((session()->get('menuDataAdmin')['result'] ?? []));
$tenantScope = is_array($tenantScope ?? null) ? $tenantScope : [];
$composer = is_array($composer ?? null) ? $composer : [];
$errors = is_array($errors ?? null) ? $errors : [];
$preview = is_array($composer['preview'] ?? null) ? $composer['preview'] : [];
$document = is_array($preview['document'] ?? null) ? $preview['document'] : [];
$documentId = (int) ($document['id_billing_document'] ?? 0);
$deliveryType = (string) ($composer['delivery_type'] ?? 'invoice');
$recipient = old('recipient');
$recipient = $recipient !== null ? (string) $recipient : (string) ($composer['recipient'] ?? '');
$subject = old('subject');
$subject = $subject !== null ? (string) $subject : (string) ($composer['subject'] ?? '');
$messageBody = old('message_body');
$messageBody = $messageBody !== null ? (string) $messageBody : (string) ($composer['message_body'] ?? '');
$dueDate = trim((string) ($document['due_date'] ?? ''));
$dueDateFormatted = $dueDate !== '' ? date('d/m/Y', strtotime($dueDate)) : 'Non indicata';
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | <?= esc((string) ($composer['delivery_type_label'] ?? 'Invio email')) ?></title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/assets/css/billing-ts-ui.css') ?>" rel="stylesheet" />
</head>
<body class="skin-blue sidebar-mini billing-ts-ui module-fatturazione">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items]) ?>

  <div class="content-wrapper">
    <section class="content billing-email-content">
      <div class="row billing-email-layout">
        <aside class="col-md-3 billing-email-nav">
          <?= view('partials/sidebar_admin', ['menu_items' => $menu_items]) ?>
        </aside>

        <main class="col-md-9">
          <div class="billing-email-composer">
            <?php if (!empty($errors['generic'])): ?>
              <div class="alert alert-danger"><?= esc((string) $errors['generic']) ?></div>
            <?php endif; ?>

            <header class="billing-archive-modulebar">
              <div class="billing-archive-modulecopy">
                <div class="billing-eyebrow">Fatturazione · Comunicazioni</div>
                <div class="billing-title-row">
                  <span class="billing-module-icon"><i class="fa <?= $deliveryType === 'reminder' ? 'fa-bell-o' : 'fa-envelope-o' ?>"></i></span>
                  <div>
                    <h1><?= esc((string) ($composer['delivery_type_label'] ?? 'Invio email')) ?></h1>
                    <p>Controlla e personalizza il messaggio prima di inviarlo al paziente.</p>
                  </div>
                </div>
              </div>
              <div class="billing-archive-actions">
                <a class="billing-action billing-action-secondary" href="<?= site_url('admin/fatturazione-documenti') ?>">
                  <i class="fa fa-arrow-left"></i> Torna alle fatture
                </a>
              </div>
            </header>

            <div class="billing-email-grid">
              <section class="billing-email-card billing-email-document-card">
                <div class="billing-email-card-head">
                  <div>
                    <span>Documento</span>
                    <h2><?= esc((string) ($document['document_number'] ?? '-')) ?></h2>
                  </div>
                  <span class="billing-state-pill is-issued">Definitivo</span>
                </div>
                <dl class="billing-email-document-summary">
                  <div><dt>Paziente</dt><dd><?= esc((string) ($document['patient_name'] ?? '-')) ?></dd></div>
                  <div><dt>Totale</dt><dd class="billing-email-total">€ <?= number_format((float) ($document['amount_total'] ?? 0), 2, ',', '.') ?></dd></div>
                  <div><dt>Scadenza</dt><dd><?= esc($dueDateFormatted) ?></dd></div>
                  <div><dt>Modalità di pagamento</dt><dd><span class="billing-payment-method"><i class="fa fa-credit-card"></i> <?= esc((string) ($preview['payment_method_label'] ?? '-')) ?></span></dd></div>
                </dl>
                <?php if (!empty($composer['attach_pdf'])): ?>
                  <div class="billing-email-attachment"><i class="fa fa-paperclip"></i> Il PDF della fattura sarà allegato automaticamente.</div>
                <?php elseif ($deliveryType === 'reminder'): ?>
                  <div class="billing-email-attachment is-reminder"><i class="fa fa-info-circle"></i> Il sollecito viene inviato senza allegato.</div>
                <?php endif; ?>
              </section>

              <form class="billing-email-card billing-email-form" method="post" action="<?= site_url('admin/fatturazione-documenti/email/' . $documentId . '/send') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="delivery_type" value="<?= esc($deliveryType, 'attr') ?>">
                <div class="billing-email-card-head">
                  <div>
                    <span>Messaggio</span>
                    <h2>Email al paziente</h2>
                  </div>
                  <i class="fa fa-envelope-o"></i>
                </div>
                <div class="billing-email-form-body">
                  <div class="form-group">
                    <label for="billing-email-recipient">Destinatario</label>
                    <input class="form-control" id="billing-email-recipient" type="email" name="recipient" maxlength="190" required autocomplete="email" value="<?= esc($recipient, 'attr') ?>" placeholder="paziente@esempio.it">
                    <?php if ($recipient === ''): ?><small class="billing-field-warning"><i class="fa fa-exclamation-triangle"></i> Inserisci l’email del paziente.</small><?php endif; ?>
                  </div>
                  <div class="form-group">
                    <label for="billing-email-subject">Oggetto</label>
                    <input class="form-control" id="billing-email-subject" type="text" name="subject" maxlength="255" required value="<?= esc($subject, 'attr') ?>">
                  </div>
                  <div class="form-group">
                    <label for="billing-email-message">Messaggio</label>
                    <textarea class="form-control" id="billing-email-message" name="message_body" rows="12" maxlength="5000" required><?= esc($messageBody) ?></textarea>
                  </div>
                  <p class="billing-email-placeholders">
                    Puoi usare: <code>{paziente}</code>, <code>{numero}</code>, <code>{data_emissione}</code>, <code>{scadenza}</code>, <code>{totale}</code>, <code>{modalita_pagamento}</code>, <code>{studio}</code>.
                  </p>
                </div>
                <div class="billing-email-form-actions">
                  <a class="billing-action billing-action-secondary" href="<?= site_url('admin/fatturazione-documenti') ?>">Annulla</a>
                  <button class="billing-action billing-action-primary" type="submit">
                    <i class="fa fa-paper-plane-o"></i> <?= $deliveryType === 'reminder' ? 'Invia sollecito' : 'Invia fattura' ?>
                  </button>
                </div>
              </form>
            </div>
          </div>
        </main>
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
</body>
</html>
