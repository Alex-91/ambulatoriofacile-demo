<h3>Intestatario: clinica, azienda o ente</h3>
<div class="row">
<?php
$businessFields = [
    'patient_name' => ['Ragione sociale *', 'text', 190, 'Es. Centro medico Aurora S.r.l.'],
    'business_vat_number' => ['Partita IVA', 'text', 28, ''],
    'patient_tax_code' => ['Codice fiscale', 'text', 16, ''],
    'patient_address' => ['Indirizzo', 'text', 190, ''],
    'business_postal_code' => ['CAP', 'text', 12, ''],
    'patient_city' => ['Comune', 'text', 120, ''],
    'business_province' => ['Provincia', 'text', 2, ''],
    'business_country' => ['Paese (codice)', 'text', 2, 'IT'],
    'patient_email' => ['Email amministrazione', 'email', 190, ''],
    'patient_phone' => ['Telefono', 'text', 40, ''],
    'business_recipient_code' => ['Codice destinatario', 'text', 7, ''],
    'business_pec' => ['PEC', 'email', 190, ''],
];
foreach ($businessFields as $key => [$label, $type, $length, $placeholder]): ?>
  <div class="col-md-6"><div class="form-group">
    <label for="business-<?= esc($key) ?>"><?= esc($label) ?></label>
    <input id="business-<?= esc($key) ?>" class="form-control" name="<?= esc($key) ?>" type="<?= esc($type) ?>" maxlength="<?= $length ?>" value="<?= esc($fieldValue($key, $key === 'business_country' ? 'IT' : '')) ?>" placeholder="<?= esc($placeholder) ?>" <?= $key === 'patient_name' ? 'required' : '' ?>>
  </div></div>
<?php endforeach; ?>
</div>
