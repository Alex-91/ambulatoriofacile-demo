<?php
$bodyIssuerLines = [];
foreach ([
    'issuer_name' => $issuerName,
    'issuer_address' => $issuerLocation,
    'issuer_tax_code' => empty($fiscalData['tax_code']) ? '' : 'Codice fiscale: ' . $fiscalData['tax_code'],
    'issuer_vat_number' => empty($fiscalData['vat_number']) ? '' : 'Partita IVA: ' . $fiscalData['vat_number'],
    'issuer_pec' => empty($fiscalData['pec']) ? '' : 'PEC: ' . $fiscalData['pec'],
    'issuer_pension' => $pensionFundLabel,
    'issuer_extra' => $branding['header_extra'] ?? '',
] as $key => $value) {
    if (!empty($fields['show_' . $key]) && trim((string) $value) !== '') $bodyIssuerLines[] = (string) $value;
}
?>
<?php if ($bodyIssuerLines !== []): ?>
<div class="box" style="clear:both;overflow-wrap:break-word;">
  <div class="label">Professionista</div>
  <?php foreach ($bodyIssuerLines as $line): ?><div class="value"><?= nl2br(esc($line)) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>
