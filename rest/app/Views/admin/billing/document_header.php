<?php
$issuerLines = [];
foreach ([
    'issuer_name' => $issuerName,
    'issuer_address' => $issuerLocation,
    'issuer_tax_code' => empty($fiscalData['tax_code']) ? '' : 'Codice fiscale: ' . $fiscalData['tax_code'],
    'issuer_vat_number' => empty($fiscalData['vat_number']) ? '' : 'Partita IVA: ' . $fiscalData['vat_number'],
    'issuer_pec' => empty($fiscalData['pec']) ? '' : 'PEC: ' . $fiscalData['pec'],
    'issuer_pension' => $pensionFundLabel === '' ? '' : 'Cassa previdenziale: ' . $pensionFundLabel,
] as $key => $value) {
    if (($branding['show_' . $key] ?? true) && trim((string) $value) !== '') {
        $issuerLines[] = (string) $value;
    }
}
$metadata = [];
{
    foreach ([
        'document_number' => ['N°', $document['document_number'] ?? ''],
        'issue_date' => ['Data emissione', $document['issue_date'] ?? ''],
        'payment_method' => ['Metodo di pagamento', $preview['payment_method_label'] ?? ''],
        'payment_date' => ['Data pagamento', $document['payment_date'] ?? ''],
    ] as $key => [$label, $value]) {
        if (($branding['header_' . $key] ?? (!empty($branding['show_header_metadata']) && !empty($fields['show_' . $key]))) && trim((string) $value) !== '') {
            $metadata[] = $label . ': ' . $value;
        }
    }
}
$patientHeaderDetails = is_array($template['patient_details'] ?? null) ? $template['patient_details'] : [];
foreach (['patient_name' => "Nome paziente",'patient_tax_code' => "Codice fiscale paziente",'patient_address' => "Indirizzo paziente",'patient_city' => "Comune paziente",'patient_email' => "Email paziente",'patient_phone' => "Telefono paziente",'patient_mobile' => "Cellulare paziente"] as $key => $label) {
    $value = trim((string) ($patientHeaderDetails[$key] ?? $document[$key] ?? ''));
    if (!empty($branding['header_' . $key]) && $value !== '') $metadata[] = $label . ': ' . $value;
}
if (!empty($branding['show_header_opposition'])) {
    $metadata[] = 'Opposizione invio al Sistema TS: ' . (!empty($document['ts_opposition_flag']) ? 'Sì' : 'No');
}
?>
<table style="width:100%;border-collapse:collapse;table-layout:fixed;color:inherit;">
  <tr>
    <td style="vertical-align:top;border:0;padding:0 16px 0 0;overflow-wrap:anywhere;word-wrap:break-word;">
      <div style="font-size:24px;font-weight:bold;"><?= esc($headerTitle) ?></div>
      <?php if ($headerSubtitle !== ''): ?><div style="font-size:13px;margin-top:6px;"><?= esc($headerSubtitle) ?></div><?php endif; ?>
      <div style="font-size:12px;line-height:1.5;margin-top:12px;">
        <?php foreach ($issuerLines as $line): ?><div><?= esc($line) ?></div><?php endforeach; ?>
        <?php if (($branding['show_issuer_extra'] ?? true) && trim((string) ($branding['header_extra'] ?? '')) !== ''): ?>
          <div style="margin-top:6px;"><?= nl2br(esc((string) $branding['header_extra'])) ?></div>
        <?php endif; ?>
      </div>
    </td>
    <?php if ($metadata !== [] || (!empty($layout['show_logo']) && ($branding['logo_mode'] ?? 'none') !== 'none' && $resolvedLogoUrl !== '')): ?>
    <td style="width:40%;vertical-align:top;border:0;padding:0;font-size:12px;line-height:1.5;word-wrap:break-word;">
      <?php if (!empty($layout['show_logo']) && ($branding['logo_mode'] ?? 'none') !== 'none' && $resolvedLogoUrl !== ''): ?>
        <img src="<?= esc($resolvedLogoUrl) ?>" alt="Logo studio" style="max-width:150px;max-height:56px;margin-bottom:12px;">
      <?php endif; ?>
      <?php foreach ($metadata as $line): ?><div><?= esc($line) ?></div><?php endforeach; ?>
    </td>
    <?php endif; ?>
  </tr>
</table>
