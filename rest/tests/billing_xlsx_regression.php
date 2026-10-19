<?php
require __DIR__ . '/billing_preferences_regression.php';
use App\Services\BillingXlsxExportService as Excel;

$columns = Excel::defaultColumns();
check(array_map(static fn($key) => Excel::fields()[$key][0], $columns) === ['Numero fattura', 'Data', 'Destinatario', 'Importo', 'Imponibile', 'Marca da bollo'], 'Default columns must match A:F of supplied workbook');
foreach ([[], ['unknown'], ['issue_date', 'issue_date'], [null], 'issue_date'] as $invalid) {
    try { Excel::validateColumns($invalid); } catch (InvalidArgumentException $e) { continue; }
    throw new RuntimeException('Invalid configuration accepted');
}
$custom = ['stamp_duty_amount', 'document_number', 'patient_tax_code'];
$config = $sanitize->invoke($settings, ['xlsx_columns' => $custom]);
check($config['xlsx_columns'] === $custom, 'Selection and order must survive configuration sanitization');
check($sanitize->invoke($settings, [])['xlsx_columns'] === $columns, 'Existing doctor settings must default to six columns');
$rows = [
    ['document_number' => '0001/2026', 'issue_date' => '2026-10-19', 'patient_name' => 'Cliente sintetico & prova', 'amount_total' => 102, 'subtotal_amount' => 100, 'stamp_duty_amount' => 2, 'patient_tax_code' => '00123456789'],
    ['document_number' => '0002/2026', 'issue_date' => '2026-10-20', 'patient_name' => '=HYPERLINK("https://invalid.example")', 'amount_total' => 80.5, 'subtotal_amount' => 80.5, 'stamp_duty_amount' => 0, 'payment_method' => 'mixed'],
];
$exporter = new Excel();
function sheetXml(string $body): SimpleXMLElement {
    $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
    try {
        file_put_contents($path, $body);
        $zip = new ZipArchive();
        check($zip->open($path) === true, 'Must produce real XLSX ZIP');
        for ($i=0; $i<$zip->numFiles; $i++) { check(simplexml_load_string($zip->getFromIndex($i)) !== false, 'Every XLSX part must be valid XML'); }
        $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        $xml->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        return $xml;
    } finally { unlink($path); }
}
$body = $exporter->build($rows, $columns);
$xml = sheetXml($body);
check((string)$xml->xpath('//s:c[@r="A2"]/s:is/s:t')[0] === '0001/2026', 'Invoice numbers must preserve leading zeros as text');
check((float)$xml->xpath('//s:c[@r="D2"]/s:v')[0] === 102.0, 'Total must remain numeric');
check((float)$xml->xpath('//s:c[@r="E2"]/s:v')[0] === 100.0, 'Honorarium must exclude stamp');
check((float)$xml->xpath('//s:c[@r="F2"]/s:v')[0] === 2.0, 'Recharged stamp must be exported');
check((float)$xml->xpath('//s:c[@r="F3"]/s:v')[0] === 0.0, 'Stamp must not be inferred or charged automatically');
check($xml->xpath('//s:f') === [], 'Patient text must never become a formula');
check(str_starts_with((string)$xml->xpath('//s:c[@r="C3"]/s:is/s:t')[0], '=HYPERLINK'), 'Dangerous-looking text must remain literal');
check((int)$xml->xpath('//s:c[@r="B2"]/s:v')[0] > 45000, 'Dates must be Excel numbers');
$customXml = sheetXml($exporter->build($rows, $custom));
check((string)$customXml->xpath('//s:c[@r="A1"]/s:is/s:t')[0] === 'Marca da bollo', 'Custom order must apply');
check((string)$customXml->xpath('//s:c[@r="C2"]/s:is/s:t')[0] === '00123456789', 'Tax codes must preserve leading zeros');
check(count($customXml->xpath('//s:row[@r="1"]/s:c')) === 3, 'Unselected fields must be omitted');
check(count(sheetXml($exporter->build([], $columns))->xpath('//s:row')) === 1, 'Empty export retains headers');
sheetXml($exporter->build($rows, array_keys(Excel::fields())));
file_put_contents(__DIR__ . '/../build/billing-xlsx-synthetic.xlsx', $body);
// Render the actual new settings section without unrelated application headers.
if (!function_exists('old')) { function old($key) { return null; } }
if (!function_exists('site_url')) { function site_url($path) { return '/'.$path; } }
$config = ['xlsx_columns' => $columns];
$source = file_get_contents(__DIR__ . '/../app/Views/tenant/billing_settings.php');
$start = strpos($source, '<section class="config-card" style="margin-bottom:20px;padding:20px;">');
$end = strpos($source, '<div class="config-sticky-actions">', $start);
ob_start();
eval('if (true): ?>' . substr($source, $start, $end - $start));
$controls = ob_get_clean();
file_put_contents(__DIR__ . '/../build/billing-xlsx-controls.html', '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><link rel="stylesheet" href="../public/assets/css/billing-ts-ui.css"><style>body{font:16px Arial;background:#f5f7f9;margin:20px}form{max-width:800px;margin:auto;background:white}button{cursor:pointer;padding:5px 10px}</style><form>'.$controls.'<button type="submit">Salva configurazione</button></form>');
echo "PASS: XLSX default/custom columns, persisted config, numeric money/dates, zero stamp, literal text, empty report and all optional fields.\n";
