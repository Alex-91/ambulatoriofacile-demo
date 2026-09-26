<?php
require __DIR__ . '/billing_preferences_regression.php';
$template = $sanitize->invoke($settings, ['branding' => [
    'header_style' => 'plain', 'header_extra' => "Albo: n. 123\n<script>alert(1)</script>",
    'show_issuer_tax_code' => false, 'show_header_metadata' => true, 'show_header_opposition' => true,
]]);
$branding = $template['branding'];
check($branding['header_style'] === 'plain' && !$branding['show_issuer_tax_code'], 'Header choices must persist');
check($branding['show_issuer_name'], 'Legacy headers must keep issuer details');
$layout = $template['layout']; $fields = $template['fields'];
$fiscalData = ['tax_code' => 'HIDDEN-CF', 'vat_number' => 'TEST-IVA'];
$issuerName = 'Studio esempio'; $issuerLocation = 'Via Roma'; $pensionFundLabel = '';
$headerTitle = 'Fattura'; $headerSubtitle = ''; $resolvedLogoUrl = '';
$document = ['document_number' => 'FT-123', 'issue_date' => '2026-09-24', 'payment_date' => '', 'ts_opposition_flag' => 1];
$preview = ['payment_method_label' => 'Carta / POS'];
ob_start(); require dirname(__DIR__) . '/app/Views/admin/billing/document_header.php'; $html = ob_get_clean();
check(!str_contains($html, 'HIDDEN-CF') && str_contains($html, 'TEST-IVA'), 'Issuer visibility must affect rendered output');
check(str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>'), 'Free text must be escaped');
check(str_contains($html, '<br') && str_contains($html, 'FT-123') && str_contains($html, 'Sistema TS: Sì'), 'Multiline text and selected metadata must render');
check(!str_contains($html, 'Data pagamento:'), 'Empty dates must be omitted');
$fields['show_document_number'] = false;
ob_start(); require dirname(__DIR__) . '/app/Views/admin/billing/document_header.php'; $html = ob_get_clean();
check(str_contains($html, 'FT-123'), 'Header must remain visible when the body field is hidden');
$branding['header_document_number'] = false;
ob_start(); require dirname(__DIR__) . '/app/Views/admin/billing/document_header.php'; $html = ob_get_clean();
check(!str_contains($html, 'FT-123'), 'Header field must have an independent visibility setting');
echo "PASS: header persistence, legacy defaults, field visibility, escaped multiline text and metadata\n";
