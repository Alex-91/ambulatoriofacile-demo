<?php
helper('portal');

$menuDataAdmin = session()->get('menuDataAdmin');
$sidebarMenuItems = is_array($menuDataAdmin['result'] ?? null) ? $menuDataAdmin['result'] : [];
if (empty($menu_items) || !is_array($menu_items)) {
    $menu_items = $sidebarMenuItems !== [] ? $sidebarMenuItems : (session()->get('header_menu_items') ?? []);
}

$moduleStatus = is_array($moduleStatus ?? null) ? $moduleStatus : [];
$billingSettings = is_array($billingSettings ?? null) ? $billingSettings : [];
$config = is_array($billingSettings['config'] ?? null) ? $billingSettings['config'] : [];
$defaults = is_array($config['defaults'] ?? null) ? $config['defaults'] : [];
$vat = is_array($config['vat'] ?? null) ? $config['vat'] : [];
$pensionFund = is_array($config['pension_fund'] ?? null) ? $config['pension_fund'] : [];
$fiscalData = is_array($config['fiscal_data'] ?? null) ? $config['fiscal_data'] : [];
$documentTypes = is_array($documentTypes ?? null) ? $documentTypes : [];
$paymentMethods = is_array($paymentMethods ?? null) ? $paymentMethods : [];
$errors = is_array($errors ?? null) ? $errors : [];
$billingEnabled = !empty($moduleStatus['billing_enabled']);

$fieldValue = static function (string $key, $fallback = ''): string {
    $old = old($key);
    return trim((string) ($old !== null ? $old : $fallback));
};
$fieldBool = static function (string $key, bool $fallback = false): bool {
    $old = old($key);
    return $old !== null ? (int) $old === 1 : $fallback;
};
$serviceCatalog = is_array($config['service_catalog'] ?? null) ? $config['service_catalog'] : [];
$oldServiceDescriptions = old('service_description');
$oldServiceAmounts = old('service_amount');
$serviceRows = [];
if (is_array($oldServiceDescriptions)) {
    foreach ($oldServiceDescriptions as $index => $description) {
        $serviceRows[] = [
            'description' => trim((string) $description),
            'unit_amount' => trim((string) (is_array($oldServiceAmounts) ? ($oldServiceAmounts[$index] ?? '') : '')),
        ];
    }
} else {
    foreach ($serviceCatalog as $service) {
        if (!is_array($service)) {
            continue;
        }
        $serviceRows[] = [
            'description' => trim((string) ($service['description'] ?? '')),
            'unit_amount' => trim((string) ($service['unit_amount'] ?? '')),
        ];
    }
}
if ($serviceRows === []) {
    $serviceRows[] = ['description' => '', 'unit_amount' => ''];
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Configurazione Fatturazione</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/assets/css/billing-ts-ui.css') ?>" rel="stylesheet" />
</head>
<body class="skin-blue sidebar-mini billing-ts-ui module-fatturazione billing-settings-page">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items, 'portal_console_header' => false]) ?>

  <div class="content-wrapper">
    <section class="content billing-config-content">
      <div class="row billing-config-layout">
        <aside class="col-md-3 billing-config-nav">
          <?= view('partials/sidebar_admin', ['menu_items' => $sidebarMenuItems]) ?>
        </aside>

        <div class="col-md-9 billing-config-main">
          <main class="billing-config">
            <header class="config-modulebar">
              <div class="config-modulebar-copy">
                <div class="config-eyebrow">Configurazione</div>
                <div class="config-title-row">
                  <span class="config-module-icon"><i class="fa fa-calculator"></i></span>
                  <div>
                    <h1>Fatturazione</h1>
                    <p>Imposta i dati e i valori predefiniti del documento cliente.</p>
                  </div>
                </div>
                <span class="config-status-chip"><i class="fa fa-check-circle"></i> Documento cliente</span>
              </div>
              <div class="config-module-actions">
                <a class="config-action config-action-secondary" href="<?= site_url('admin/fatturazione') ?>"><i class="fa fa-calculator"></i> Dashboard</a>
                <a class="config-action config-action-secondary" href="<?= site_url('admin/fatturazione-documenti') ?>"><i class="fa fa-folder-open-o"></i> Lista fatture</a>
                <a class="config-action config-action-secondary" href="<?= site_url('admin/fatturazione-documento') ?>"><i class="fa fa-file-text-o"></i> Documento</a>
              </div>
            </header>

            <?php if (!empty($errors['generic'])): ?>
              <div class="alert alert-danger"><?= esc((string) $errors['generic']) ?></div>
            <?php endif; ?>
            <?php if (!empty($success)): ?>
              <div class="alert alert-success"><?= esc((string) $success) ?></div>
            <?php endif; ?>

            <div class="config-statebar">
              <span>Studio attivo</span>
              <strong><?= esc((string) ($tenantContext->tenantName ?? '')) ?></strong>
              <span class="config-mini-chip <?= $billingEnabled ? 'is-active' : '' ?>">Fatturazione <?= $billingEnabled ? 'attiva' : 'spenta' ?></span>
            </div>

            <form method="post" action="<?= portal_tenant_space_url('fatturazione/save') ?>" class="billing-config-form">
              <?= csrf_field() ?>
              <div class="billing-config-grid">
                <section class="config-card config-card-full config-document-card">
                  <div class="config-card-head">
                    <div>
                      <span class="config-card-eyebrow">Documento cliente</span>
                      <h2>Impostazioni generali</h2>
                    </div>
                    <i class="fa fa-file-text-o"></i>
                  </div>
                  <div class="config-form-grid">
                    <div class="form-group config-form-wide">
                      <label>Titolo predefinito</label>
                      <input class="form-control" name="document_title" maxlength="120" value="<?= esc($fieldValue('document_title', $config['document_title'] ?? 'Documento fatturazione')) ?>">
                      <span class="config-help">Titolo riportato nei nuovi documenti cliente.</span>
                    </div>
                    <div class="form-group">
                      <label>Prefisso numerazione</label>
                      <input class="form-control" name="document_code_prefix" maxlength="12" value="<?= esc($fieldValue('document_code_prefix', $config['document_code_prefix'] ?? 'FT')) ?>">
                    </div>
                    <div class="form-group">
                      <label>Tipo documento predefinito</label>
                      <select class="form-control" name="default_document_type">
                        <?php foreach ($documentTypes as $value => $label): ?>
                          <option value="<?= esc((string) $value) ?>" <?= $fieldValue('default_document_type', $defaults['document_type'] ?? 'invoice') === (string) $value ? 'selected' : '' ?>><?= esc((string) $label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="form-group">
                      <label>Metodo di pagamento predefinito</label>
                      <select class="form-control" name="default_payment_method">
                        <?php foreach ($paymentMethods as $value => $label): ?>
                          <option value="<?= esc((string) $value) ?>" <?= $fieldValue('default_payment_method', $defaults['payment_method'] ?? 'bank_transfer') === (string) $value ? 'selected' : '' ?>><?= esc((string) $label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                </section>

                <section class="config-card config-card-full config-services-card">
                  <div class="config-card-head">
                    <div>
                      <span class="config-card-eyebrow">Prestazioni</span>
                      <h2>Catalogo delle voci</h2>
                    </div>
                    <i class="fa fa-list-alt"></i>
                  </div>
                  <p class="config-help config-section-help">Le voci salvate saranno suggerite quando crei una nuova fattura. L'importo resta sempre modificabile nel documento.</p>
                  <div class="table-responsive">
                    <table class="table service-catalog-table" id="service-catalog-table">
                      <thead>
                        <tr><th>Descrizione prestazione</th><th>Importo predefinito</th><th aria-label="Rimuovi"></th></tr>
                      </thead>
                      <tbody>
                        <?php foreach ($serviceRows as $row): ?>
                          <tr>
                            <td><input class="form-control" name="service_description[]" maxlength="190" value="<?= esc((string) $row['description']) ?>" placeholder="Es. Visita specialistica"></td>
                            <td><input class="form-control" type="number" min="0" max="999999.99" step="0.01" name="service_amount[]" value="<?= esc((string) $row['unit_amount']) ?>" placeholder="0,00"></td>
                            <td><button class="btn btn-default btn-sm js-remove-service" type="button" title="Rimuovi prestazione"><i class="fa fa-trash-o"></i></button></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                  <button class="config-action config-action-secondary config-inline-action" id="add-service" type="button"><i class="fa fa-plus"></i> Aggiungi prestazione</button>
                </section>

                <section class="config-card config-vat-card">
                  <div class="config-card-head">
                    <div>
                      <span class="config-card-eyebrow">Aliquote IVA</span>
                      <h2>Valori predefiniti</h2>
                    </div>
                    <i class="fa fa-percent"></i>
                  </div>
                  <div class="config-form-grid">
                    <div class="form-group">
                      <label>Aliquota IVA %</label>
                      <input class="form-control" type="number" min="0" max="100" step="0.01" name="default_vat_rate" value="<?= esc($fieldValue('default_vat_rate', $vat['default_rate'] ?? '0.00')) ?>">
                    </div>
                    <div class="form-group">
                      <label>Natura IVA</label>
                      <input class="form-control" name="default_vat_nature" maxlength="16" value="<?= esc($fieldValue('default_vat_nature', $vat['default_nature'] ?? '')) ?>" placeholder="Es. N4 o esente">
                    </div>
                  </div>
                  <span class="config-help">Usati come proposta nei nuovi documenti; puoi cambiarli sulla singola fattura.</span>
                </section>

                <section class="config-card config-pension-card">
                  <div class="config-card-head">
                    <div>
                      <span class="config-card-eyebrow">Cassa previdenziale</span>
                      <h2>Dati di appartenenza</h2>
                    </div>
                    <i class="fa fa-shield"></i>
                  </div>
                  <div class="config-switch-list config-switch-list-compact">
                    <label><span><strong>Mostra nei dati fiscali</strong><small>Rende disponibili i dati della cassa nei nuovi documenti.</small></span><input type="hidden" name="pension_fund_enabled" value="0"><input type="checkbox" name="pension_fund_enabled" value="1" <?= $fieldBool('pension_fund_enabled', !empty($pensionFund['enabled'])) ? 'checked' : '' ?>></label>
                  </div>
                  <div class="config-form-grid">
                    <div class="form-group">
                      <label>Cassa</label>
                      <input class="form-control" name="pension_fund_name" maxlength="120" value="<?= esc($fieldValue('pension_fund_name', $pensionFund['name'] ?? '')) ?>" placeholder="Es. ENPAM">
                    </div>
                    <div class="form-group">
                      <label>N. iscrizione</label>
                      <input class="form-control" name="pension_fund_registration_number" maxlength="80" value="<?= esc($fieldValue('pension_fund_registration_number', $pensionFund['registration_number'] ?? '')) ?>">
                    </div>
                    <div class="form-group config-form-wide">
                      <label>Contributo integrativo %</label>
                      <input class="form-control" type="number" min="0" max="100" step="0.01" name="pension_fund_contribution_rate" value="<?= esc($fieldValue('pension_fund_contribution_rate', $pensionFund['contribution_rate'] ?? '0.00')) ?>">
                    </div>
                  </div>
                </section>

                <section class="config-card config-card-full config-fiscal-card">
                  <div class="config-card-head">
                    <div>
                      <span class="config-card-eyebrow">Dati fiscali</span>
                      <h2>Emittente del documento</h2>
                    </div>
                    <i class="fa fa-building-o"></i>
                  </div>
                  <div class="config-form-grid">
                    <div class="form-group config-form-wide">
                      <label>Denominazione o nome professionista</label>
                      <input class="form-control" name="fiscal_business_name" maxlength="140" value="<?= esc($fieldValue('fiscal_business_name', $fiscalData['business_name'] ?? '')) ?>" placeholder="Es. Dott. Mario Rossi">
                    </div>
                    <div class="form-group">
                      <label>Codice fiscale</label>
                      <input class="form-control" name="fiscal_tax_code" maxlength="32" value="<?= esc($fieldValue('fiscal_tax_code', $fiscalData['tax_code'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                      <label>Partita IVA</label>
                      <input class="form-control" name="fiscal_vat_number" maxlength="32" value="<?= esc($fieldValue('fiscal_vat_number', $fiscalData['vat_number'] ?? '')) ?>">
                    </div>
                    <div class="form-group config-form-wide">
                      <label>Indirizzo sede</label>
                      <input class="form-control" name="fiscal_address" maxlength="160" value="<?= esc($fieldValue('fiscal_address', $fiscalData['address'] ?? '')) ?>" placeholder="Via Roma 10">
                    </div>
                    <div class="form-group">
                      <label>CAP</label>
                      <input class="form-control" name="fiscal_postal_code" maxlength="12" value="<?= esc($fieldValue('fiscal_postal_code', $fiscalData['postal_code'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                      <label>Comune</label>
                      <input class="form-control" name="fiscal_city" maxlength="100" value="<?= esc($fieldValue('fiscal_city', $fiscalData['city'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                      <label>Provincia</label>
                      <input class="form-control" name="fiscal_province" maxlength="4" value="<?= esc($fieldValue('fiscal_province', $fiscalData['province'] ?? '')) ?>" placeholder="BO">
                    </div>
                    <div class="form-group">
                      <label>PEC</label>
                      <input class="form-control" type="email" name="fiscal_pec" maxlength="190" value="<?= esc($fieldValue('fiscal_pec', $fiscalData['pec'] ?? '')) ?>" placeholder="studio@pec.it">
                    </div>
                    <div class="form-group">
                      <label>Codice destinatario</label>
                      <input class="form-control" name="fiscal_recipient_code" maxlength="16" value="<?= esc($fieldValue('fiscal_recipient_code', $fiscalData['recipient_code'] ?? '')) ?>" placeholder="0000000">
                    </div>
                  </div>
                </section>
              </div>

              <div class="config-sticky-actions">
                <span>Le modifiche saranno proposte solo ai documenti creati da ora.</span>
                <div>
                  <a class="config-action config-action-secondary" href="<?= site_url('admin/fatturazione') ?>">Annulla</a>
                  <button class="config-action config-action-primary" type="submit"><i class="fa fa-save"></i> Salva configurazione</button>
                </div>
              </div>
            </form>
          </main>
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
  (function ($) {
    var emptyServiceRow = '<tr><td><input class="form-control" name="service_description[]" maxlength="190" placeholder="Es. Visita specialistica"></td><td><input class="form-control" type="number" min="0" max="999999.99" step="0.01" name="service_amount[]" placeholder="0,00"></td><td><button class="btn btn-default btn-sm js-remove-service" type="button" title="Rimuovi prestazione"><i class="fa fa-trash-o"></i></button></td></tr>';

    $('#add-service').on('click', function () {
      $('#service-catalog-table tbody').append(emptyServiceRow);
    });

    $('#service-catalog-table').on('click', '.js-remove-service', function () {
      var $rows = $('#service-catalog-table tbody tr');
      if ($rows.length === 1) {
        $rows.find('input').val('');
        return;
      }
      $(this).closest('tr').remove();
    });
  })(jQuery);
</script>
</body>
</html>
