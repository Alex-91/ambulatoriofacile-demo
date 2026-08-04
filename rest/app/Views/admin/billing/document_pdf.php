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
  <style>
    body { font-family: DejaVu Sans, Arial, sans-serif; color:#1f2d3d; font-size:12px; margin:0; }
    .header { background:<?= esc($accentColor) ?>; color:#fff; padding:22px 26px; }
    .title { font-size:24px; font-weight:700; margin:0 0 6px 0; }
    .subtitle { font-size:12px; opacity:.95; }
    .body { padding:24px 26px; }
    .box { border:1px solid #dfe8eb; border-radius:10px; padding:12px 14px; margin-bottom:14px; }
    .label { font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:#677b82; font-weight:700; margin-bottom:4px; }
    .value { font-size:13px; line-height:1.45; }
    .row { width:100%; margin-bottom:14px; }
    .row:after { content:""; display:block; clear:both; }
    .col-50 { width:48%; float:left; }
    .col-50.right { float:right; }
    table { width:100%; border-collapse:collapse; }
    th, td { border-bottom:1px solid #e5ecef; padding:8px 10px; text-align:left; }
    th { background:#f7fafb; font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:#61767d; }
    .totals { width:280px; margin-left:auto; }
    .totals td { border-bottom:none; padding:5px 0; }
    .totals td:last-child { text-align:right; font-weight:700; }
    .footer { padding:16px 26px 24px; color:#607278; font-size:10px; }
  </style>
</head>
<body>
  <?php if (!empty($layout['show_header'])): ?>
    <div class="header">
      <div class="title"><?= esc($headerTitle) ?></div>
      <?php if ($headerSubtitle !== ''): ?>
        <div class="subtitle"><?= esc($headerSubtitle) ?></div>
      <?php endif; ?>
      <div class="subtitle" style="margin-top:6px;">
        <?= esc($issuerName) ?>
        <?php if ($issuerLocation !== ''): ?><br><?= esc($issuerLocation) ?><?php endif; ?>
        <?php if ($issuerIdentifiers !== []): ?><br><?= esc(implode(' · ', $issuerIdentifiers)) ?><?php endif; ?>
        <?php if ($pensionFundLabel !== ''): ?><br><?= esc('Cassa previdenziale: ' . $pensionFundLabel) ?><?php endif; ?>
      </div>
      <?php if (!empty($layout['show_logo']) && trim((string) ($branding['logo_mode'] ?? 'none')) !== 'none' && $resolvedLogoUrl !== ''): ?>
        <div style="margin-top:10px;">
          <img src="<?= esc($resolvedLogoUrl) ?>" alt="Logo studio" style="max-width:160px; max-height:56px;">
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="body">
    <div class="row">
      <div class="col-50">
        <div class="box">
          <div class="label">Documento</div>
          <div class="value">
            <?= esc((string) ($preview['document_type_label'] ?? 'Documento')) ?>
            <?php if (!empty($fields['show_document_number'])): ?>
              n. <?= esc((string) ($document['document_number'] ?? '-')) ?>
            <?php endif; ?>
          </div>
          <?php if (!empty($fields['show_issue_date'])): ?>
            <div class="label" style="margin-top:8px;">Data emissione</div>
            <div class="value"><?= esc((string) ($document['issue_date'] ?? '-')) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php if (!empty($layout['show_patient_box'])): ?>
        <div class="col-50 right">
          <div class="box">
            <div class="label"><?= esc((string) ($labels['patient_section_title'] ?? 'Dati paziente')) ?></div>
            <?php if (!empty($fields['show_patient_name'])): ?>
              <div class="value"><?= esc((string) ($document['patient_name'] ?? '-')) ?></div>
            <?php endif; ?>
            <?php if (!empty($fields['show_patient_tax_code'])): ?>
              <div class="label" style="margin-top:8px;">Codice fiscale</div>
              <div class="value"><?= esc((string) ($document['patient_tax_code'] ?? '-')) ?></div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div class="box">
      <table>
        <thead>
          <tr>
            <th>Descrizione</th>
            <th style="width:90px;">Qta</th>
            <th style="width:110px;">Prezzo</th>
            <th style="width:110px;">Totale</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lineItems as $item): ?>
            <tr>
              <td><?= esc((string) ($item['description'] ?? '')) ?></td>
              <td><?= esc((string) ($item['quantity'] ?? '1')) ?></td>
              <td>EUR <?= number_format((float) ($item['unit_amount'] ?? 0), 2, ',', '.') ?></td>
              <td>EUR <?= number_format((float) ($item['line_total'] ?? 0), 2, ',', '.') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="row">
      <?php if (!empty($layout['show_payment_box'])): ?>
        <div class="col-50">
          <div class="box">
            <div class="label"><?= esc((string) ($labels['payment_section_title'] ?? 'Pagamento')) ?></div>
            <?php if (!empty($fields['show_payment_method'])): ?>
              <div class="value"><?= esc((string) ($preview['payment_method_label'] ?? '-')) ?></div>
            <?php endif; ?>
            <?php if (trim((string) ($document['due_date'] ?? '')) !== ''): ?>
              <div class="label" style="margin-top:8px;">Data scadenza</div>
              <div class="value"><?= esc((string) $document['due_date']) ?></div>
            <?php endif; ?>
            <?php if (!empty($fields['show_payment_date']) && trim((string) ($document['payment_date'] ?? '')) !== ''): ?>
              <div class="label" style="margin-top:8px;">Data pagamento</div>
              <div class="value"><?= esc((string) $document['payment_date']) ?></div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
      <div class="col-50 right">
        <div class="box">
          <table class="totals">
            <tr>
              <td>Subtotale</td>
              <td>EUR <?= number_format((float) ($document['subtotal_amount'] ?? 0), 2, ',', '.') ?></td>
            </tr>
            <?php if (!empty($fields['show_stamp_duty']) && (float) ($document['stamp_duty_amount'] ?? 0) > 0): ?>
              <tr>
                <td>Marca da bollo</td>
                <td>EUR <?= number_format((float) ($document['stamp_duty_amount'] ?? 0), 2, ',', '.') ?></td>
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
              <td>EUR <?= number_format((float) ($document['amount_total'] ?? 0), 2, ',', '.') ?></td>
            </tr>
          </table>
        </div>
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
        <div style="height:46px; border-bottom:1px solid #ccd9de; margin-top:20px;"></div>
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
        Documento generato dal modulo Fatturazione il <?= esc((string) ($preview['generated_at'] ?? '')) ?>.
      <?php endif; ?>
    </div>
  <?php endif; ?>
</body>
</html>
