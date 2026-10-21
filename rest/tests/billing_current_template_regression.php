<?php
require __DIR__ . '/billing_preferences_regression.php';

$service = (new ReflectionClass(App\Services\BillingDocumentService::class))->newInstanceWithoutConstructor();
$resolve = new ReflectionMethod($service, 'resolveRenderingTemplate');
$snapshot = $settings->defaultConfig();
$snapshot['fiscal_data']['business_name'] = 'Emittente originale';
$snapshot['patient_details'] = ['patient_address'=>'Via originale 1', 'patient_city'=>'Roma'];
$snapshot['pension_fund']['contribution_rate'] = '2.00';
$snapshot['numbering'] = ['mode'=>'annual', 'start'=>1, 'digits'=>4];
$snapshot['designer'] = ['version'=>1,'blocks'=>[['name'=>'Vecchio modello']]];
$current = $settings->defaultConfig();
$current['fiscal_data']['business_name'] = 'Emittente modificato';
$current['patient_details'] = ['patient_address'=>'Non usare'];
$current['numbering']['start'] = 999;
$current['pension_fund']['contribution_rate'] = '4.00';
$current['branding']['terms_text'] = 'Nuova dicitura';
$current['fields']['show_patient_city'] = true;
$current['designer'] = ['version'=>1,'blocks'=>[['name'=>'Nuovo modello']]];
$original = $snapshot;
$result = $resolve->invoke($service, $snapshot, $current);
foreach (['branding','fields','designer','fiscal_data','pension_fund','numbering','defaults','vat'] as $key) {
    check($result[$key] === $current[$key], 'Latest presentation is used: '.$key);
}
foreach (['patient_details'] as $key) {
    check($result[$key] === $snapshot[$key], 'Historical data is preserved: '.$key);
}
check($snapshot === $original, 'Saved snapshot remains unchanged');
unset($current['designer']);
check(!isset($resolve->invoke($service,$snapshot,$current)['designer']), 'Removing designer does not resurrect historical layout');
$legacy = $resolve->invoke($service, [], $current);
check($legacy['branding'] === $current['branding'] && $legacy['patient_details'] === [], 'Documents without snapshots retain current-template fallback without invented patient data');
$current['vat']['default_nature']='ESENTE ART.10';
$saved=['document_number'=>'FT-2026-0006','issue_date'=>'2026-10-01','vat_nature'=>'N4','vat_rate'=>0,'amount_total'=>102,'stamp_duty_amount'=>2,'payment_method'=>'cash','linked_ts_document_id'=>17];
$output=App\Services\BillingDocumentService::resolveOutputDocument($saved,$current);
check($output['vat_nature']==='ESENTE ART.10', 'Current IVA nature is applied to historical invoices');
unset($output['vat_nature']); $expected=$saved; unset($expected['vat_nature']);
check($output===$expected, 'Official number, dates, references and transaction values are unchanged');
$current['vat']['default_nature']='';
check(App\Services\BillingDocumentService::resolveOutputDocument($saved,$current)['vat_nature']==='', 'Clearing the setting removes historical wording');
check($saved['vat_nature']==='N4', 'Dynamic output does not rewrite archived data');
$businessSnapshot=$snapshot;
$businessSnapshot['recipient_type']='business';
$businessSnapshot['patient_details']['recipient_type']='business';
$businessSnapshot['patient_details']['business_vat_number']='01234567890';
$business=$saved+['template_snapshot_json'=>json_encode($businessSnapshot)];
$business['vat_rate']=22;
check(App\Services\BillingDocumentService::resolveOutputDocument($business,$current)===$business, 'Business invoices retain their independent tax treatment');
$businessTemplate=$resolve->invoke($service,$businessSnapshot,$current);
check($businessTemplate['recipient_type']==='business' && $businessTemplate['patient_details']===$businessSnapshot['patient_details'], 'Current template preserves the original business recipient and fiscal fields');
require_once dirname(__DIR__).'/app/Services/BillingExportFormat.php';
$businessSnapshot['patient_details']['patient_first_name']='SHOULD';
$businessSnapshot['patient_details']['patient_last_name']='NOT REORDER';
check(App\Services\BillingExportFormat::patientName(['patient_name'=>'Clinica S.p.A.'],$businessSnapshot)==='Clinica S.p.A.', 'Business names remain verbatim in Excel');
echo "PASS: current configuration and IVA nature, original patient data and immutable invoice references\n";
