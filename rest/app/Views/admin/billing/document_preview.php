<?php
$preview = is_array($preview ?? null) ? $preview : [];
$tenant = is_array($preview['tenant'] ?? null) ? $preview['tenant'] : [];
$document = is_array($preview['document'] ?? null) ? $preview['document'] : [];
$lineItems = is_array($preview['line_items'] ?? null) ? $preview['line_items'] : [];
$template = is_array($preview['template'] ?? null) ? $preview['template'] : [];
$branding = is_array($template['branding'] ?? null) ? $template['branding'] : [];
$layout = is_array($template['layout'] ?? null) ? $template['layout'] : [];
$fields = is_array($template['fields'] ?? null) ? $template['fields'] : [];
$labels = is_array($template['labels'] ?? null) ? $template['labels'] : [];
$fiscalData = is_array($template['fiscal_data'] ?? null) ? $template['fiscal_data'] : [];
$pensionFund = is_array($template['pension_fund'] ?? null) ? $template['pension_fund'] : [];
$accentColor = trim((string) ($branding['accent_color'] ?? '#2c8895'));
$logoMode = trim((string) ($branding['logo_mode'] ?? 'none'));
$logoUrl = trim((string) ($branding['logo_url'] ?? ''));
$resolvedLogoUrl = $logoUrl;
if ($resolvedLogoUrl !== '' && !preg_match('#^https?://#i', $resolvedLogoUrl)) {
    $resolvedLogoUrl = str_starts_with($resolvedLogoUrl, '/')
        ? base_url(ltrim($resolvedLogoUrl, '/'))
        : base_url($resolvedLogoUrl);
}
$headerTitle = trim((string) ($branding['header_title'] ?? '')) !== ''
    ? trim((string) ($branding['header_title'] ?? ''))
    : trim((string) ($template['document_title'] ?? 'Documento fatturazione'));
$headerSubtitle = trim((string) ($branding['header_subtitle'] ?? ''));
$footerNote = trim((string) ($branding['footer_note'] ?? ''));
$issuerName = trim((string) ($fiscalData['business_name'] ?? ''));
if ($issuerName === '') {
    $issuerName = trim((string) ($tenant['tenant_name'] ?? 'Studio attivo'));
}
$issuerLocation = trim(implode(' ', array_filter([
    trim((string) ($fiscalData['address'] ?? '')),
    trim(implode(' ', array_filter([
        trim((string) ($fiscalData['postal_code'] ?? '')),
        trim((string) ($fiscalData['city'] ?? '')),
        trim((string) ($fiscalData['province'] ?? '')),
    ]))),
])));
$issuerIdentifiers = array_filter([
    trim((string) ($fiscalData['vat_number'] ?? '')) !== '' ? 'P. IVA ' . trim((string) $fiscalData['vat_number']) : '',
    trim((string) ($fiscalData['tax_code'] ?? '')) !== '' ? 'CF ' . trim((string) $fiscalData['tax_code']) : '',
    trim((string) ($fiscalData['pec'] ?? '')) !== '' ? 'PEC ' . trim((string) $fiscalData['pec']) : '',
]);
$pensionFundLabel = '';
if (!empty($pensionFund['enabled']) && trim((string) ($pensionFund['name'] ?? '')) !== '') {
    $pensionFundLabel = trim((string) $pensionFund['name']);
    if (trim((string) ($pensionFund['registration_number'] ?? '')) !== '') {
        $pensionFundLabel .= ' n. ' . trim((string) $pensionFund['registration_number']);
    }
    if ((float) ($pensionFund['contribution_rate'] ?? 0) > 0) {
        $pensionFundLabel .= ' · contributo integrativo ' . number_format((float) $pensionFund['contribution_rate'], 2, ',', '.') . '%';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Anteprima documento fatturazione</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <style>
    body { margin:0; font-family:Arial, sans-serif; background:#eef3f5; color:#1f2d3d; }
    .toolbar { padding:14px 18px; background:#17353a; color:#fff; }
    .toolbar a { color:#fff; text-decoration:none; margin-right:12px; }
    .sheet { width:900px; max-width:calc(100% - 40px); margin:24px auto; background:#fff; border-radius:16px; box-shadow:0 18px 50px rgba(19, 44, 52, .12); overflow:hidden; }
    .header { padding:26px 30px; color:#fff; background:<?= esc($accentColor) ?>; }
    .header-grid { display:flex; justify-content:space-between; gap:24px; align-items:flex-start; }
    .logo-box { max-width:180px; max-height:80px; background:rgba(255,255,255,.12); border-radius:10px; padding:8px; }
    .logo-box img { max-width:100%; max-height:64px; display:block; }
    .body { padding:28px 30px; }
    .box { border:1px solid #dde7ea; border-radius:12px; padding:16px 18px; margin-bottom:16px; }
    .row { display:flex; gap:16px; margin-bottom:14px; }
    .col { flex:1; }
    .label { color:#6b7f86; font-size:12px; text-transform:uppercase; letter-spacing:.05em; font-weight:700; margin-bottom:4px; }
    .value { font-size:15px; line-height:1.45; }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:10px 12px; border-bottom:1px solid #e5ecef; text-align:left; }
    th { background:#f7fafb; font-size:12px; text-transform:uppercase; letter-spacing:.05em; color:#61767d; }
    .totals { margin-left:auto; width:280px; }
    .totals td { border-bottom:none; padding:6px 0; }
    .totals td:last-child { text-align:right; font-weight:700; }
    .footer { padding:18px 30px 28px; color:#607278; font-size:12px; }
    @media print {
      body { background:#fff; }
      .toolbar { display:none; }
      .sheet { box-shadow:none; width:100%; max-width:100%; margin:0; border-radius:0; }
    }
  </style>
</head>
<body>
  <div class="toolbar">
    <a href="<?= site_url('admin/fatturazione-documenti/modifica/' . (int) ($document['id_billing_document'] ?? 0)) ?>">Torna al documento</a>
    <a href="<?= site_url('admin/fatturazione-documenti/pdf/' . (int) ($document['id_billing_document'] ?? 0)) ?>">Apri PDF</a>
    <a href="#" onclick="window.print(); return false;">Stampa</a>
  </div>

  <div class="sheet">
    <?php if (!empty($layout['show_header'])): ?>
      <div class="header">
        <div class="header-grid">
          <div>
            <div style="font-size:28px; font-weight:700; margin-bottom:6px;"><?= esc($headerTitle) ?></div>
            <?php if ($headerSubtitle !== ''): ?>
              <div style="font-size:15px; opacity:.92;"><?= esc($headerSubtitle) ?></div>
            <?php endif; ?>
            <div style="margin-top:10px; font-size:13px; opacity:.9;">
              <?= esc($issuerName) ?>
              <?php if ($issuerLocation !== ''): ?><br><?= esc($issuerLocation) ?><?php endif; ?>
              <?php if ($issuerIdentifiers !== []): ?><br><?= esc(implode(' · ', $issuerIdentifiers)) ?><?php endif; ?>
              <?php if ($pensionFundLabel !== ''): ?><br><?= esc('Cassa previdenziale: ' . $pensionFundLabel) ?><?php endif; ?>
            </div>
          </div>
          <?php if (!empty($layout['show_logo']) && $logoMode !== 'none' && $resolvedLogoUrl !== ''): ?>
            <div class="logo-box">
              <img src="<?= esc($resolvedLogoUrl) ?>" alt="Logo studio">
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="body">
      <div class="row">
        <div class="col box">
          <div class="label">Documento</div>
          <div class="value">
            <?= esc((string) ($preview['document_type_label'] ?? 'Documento')) ?>
            <?php if (!empty($fields['show_document_number'])): ?>
              n. <?= esc((string) ($document['document_number'] ?? '-')) ?>
            <?php endif; ?>
          </div>
          <?php if (!empty($fields['show_issue_date'])): ?>
            <div style="margin-top:8px;">
              <span class="label">Data emissione</span>
              <div class="value"><?= esc((string) ($document['issue_date'] ?? '-')) ?></div>
            </div>
          <?php endif; ?>
        </div>
        <?php if (!empty($layout['show_patient_box'])): ?>
          <div class="col box">
            <div class="label"><?= esc((string) ($labels['patient_section_title'] ?? 'Dati paziente')) ?></div>
            <?php if (!empty($fields['show_patient_name'])): ?>
              <div class="value"><?= esc((string) ($document['patient_name'] ?? '-')) ?></div>
            <?php endif; ?>
            <?php if (!empty($fields['show_patient_tax_code'])): ?>
              <div style="margin-top:8px;">
                <span class="label">Codice fiscale</span>
                <div class="value"><?= esc((string) ($document['patient_tax_code'] ?? '-')) ?></div>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="box">
        <table>
          <thead>
            <tr>
              <th>Descrizione</th>
              <th style="width:120px;">Qta</th>
              <th style="width:140px;">Prezzo</th>
              <th style="width:140px;">Totale</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($lineItems as $item): ?>
              <tr>
                <td><?= esc((string) ($item['description'] ?? '')) ?></td>
                <td><?= esc((string) ($item['quantity'] ?? '1')) ?></td>
                <td>&euro; <?= number_format((float) ($item['unit_amount'] ?? 0), 2, ',', '.') ?></td>
                <td>&euro; <?= number_format((float) ($item['line_total'] ?? 0), 2, ',', '.') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="row">
        <?php if (!empty($layout['show_payment_box'])): ?>
          <div class="col box">
            <div class="label"><?= esc((string) ($labels['payment_section_title'] ?? 'Pagamento')) ?></div>
            <?php if (!empty($fields['show_payment_method'])): ?>
              <div class="value"><?= esc((string) ($preview['payment_method_label'] ?? '-')) ?></div>
            <?php endif; ?>
            <?php if (!empty($fields['show_payment_date'])): ?>
              <div style="margin-top:8px;">
                <span class="label">Data pagamento</span>
                <div class="value"><?= esc((string) ($document['payment_date'] ?? '-')) ?></div>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <div class="col box">
          <table class="totals">
            <tr>
              <td>Subtotale</td>
              <td>&euro; <?= number_format((float) ($document['subtotal_amount'] ?? 0), 2, ',', '.') ?></td>
            </tr>
            <?php if (!empty($fields['show_stamp_duty']) && (float) ($document['stamp_duty_amount'] ?? 0) > 0): ?>
              <tr>
                <td>Marca da bollo</td>
                <td>&euro; <?= number_format((float) ($document['stamp_duty_amount'] ?? 0), 2, ',', '.') ?></td>
              </tr>
            <?php endif; ?>
            <?php if (!empty($fields['show_vat_summary'])): ?>
              <tr>
                <td>IVA/Natura</td>
                <td>
                  <?= esc((string) ($document['vat_rate'] ?? '0')) ?>%
                  <?php if (trim((string) ($document['vat_nature'] ?? '')) !== ''): ?>
                    / <?= esc((string) ($document['vat_nature'] ?? '')) ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endif; ?>
            <tr>
              <td>Totale</td>
              <td>&euro; <?= number_format((float) ($document['amount_total'] ?? 0), 2, ',', '.') ?></td>
            </tr>
          </table>
        </div>
      </div>

      <?php if (!empty($fields['show_notes']) && trim((string) ($document['notes'] ?? '')) !== ''): ?>
        <div class="box">
          <div class="label"><?= esc((string) ($labels['notes_label'] ?? 'Note')) ?></div>
          <div class="value"><?= nl2br(esc((string) ($document['notes'] ?? ''))) ?></div>
        </div>
      <?php endif; ?>

      <?php if (!empty($layout['show_signature_box'])): ?>
        <div class="box">
          <div class="label"><?= esc((string) ($labels['signature_label'] ?? 'Firma')) ?></div>
          <div style="height:60px; border-bottom:1px solid #ccd9de; margin-top:20px;"></div>
        </div>
      <?php endif; ?>

      <?php if (!empty($layout['show_terms_box'])): ?>
        <div class="box">
          <div class="label"><?= esc((string) ($labels['terms_label'] ?? 'Informativa')) ?></div>
          <div class="value"><?= $footerNote !== '' ? nl2br(esc($footerNote)) : 'Informativa personalizzabile del documento fatturazione.' ?></div>
        </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($layout['show_footer'])): ?>
      <div class="footer">
        <?php if ($footerNote !== ''): ?>
          <?= nl2br(esc($footerNote)) ?>
        <?php else: ?>
          Documento generato dal modulo Fatturazione di AmbulatorioFacile il <?= esc((string) ($preview['generated_at'] ?? '')) ?>.
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
