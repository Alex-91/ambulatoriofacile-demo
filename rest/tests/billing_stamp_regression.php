<?php
// Standalone checks: no .env, network, database or runtime bootstrap.
require __DIR__.'/billing_preferences_regression.php';
use App\Services\BillingStampDuty as Stamp;
check(Stamp::settings([])['automatic'] === true, 'New settings enable automatic stamp');
check(Stamp::settings(['defaults'=>['stamp_duty_amount'=>2]])['automatic'] === false, 'Legacy settings keep flat stamp');
$s=$sanitize->invoke($settings,[]);
check($s['stamp_duty']['automatic'] && $s['stamp_duty']['charge_customer'], 'New defaults must survive sanitation');
foreach ([[77.47,0],[77.48,2],[0,0],[40,0],[100,2]] as [$net,$expected]) {
    $result=Stamp::normalize(['stamp_mode'=>'automatic','stamp_nature'=>'N4'],[],[['line_total'=>$net]]);
    check($result['stamp_duty_amount']==$expected,'Strict threshold '.$net);
}
check(Stamp::normalize(['stamp_mode'=>'automatic'],[],[['line_total'=>40],['line_total'=>40]])['stamp_duty_amount']===2.0,'Sum lines once');
check(Stamp::normalize(['stamp_mode'=>'automatic','vat_rate'=>22],[],[['line_total'=>1000]])['stamp_duty_amount']===0.0,'Taxable invoice has no automatic stamp');
check(Stamp::normalize(['stamp_mode'=>'automatic','stamp_nature'=>'EXEMPT'],[],[['line_total'=>1000]])['stamp_duty_amount']===0.0,'Explicit stamp exemption');
foreach (['N2.1','N2.2','N4'] as $nature) check(Stamp::normalize(['stamp_mode'=>'automatic','stamp_nature'=>$nature],[],[['line_total'=>100]])['stamp_duty_amount']===2.0,'Eligible nature '.$nature);
check(Stamp::normalize(['stamp_mode'=>'manual','stamp_duty_amount'=>'0'],[],[['line_total'=>100]])['stamp_duty_amount']===0.0,'Manual zero preserved');
foreach ([['stamp_identifier'=>'123'],['stamp_identifier'=>'1234567890123A'],['stamp_method'=>'virtual','stamp_authorization_number'=>'1','stamp_authorization_date'=>'2026-02-30'],['stamp_mode'=>'automatic','stamp_nature'=>'MANUAL']] as $invalid) {
    try { Stamp::normalize($invalid,[],[['line_total'=>100]]); throw new LogicException('Invalid data accepted'); }
    catch (InvalidArgumentException $expected) {}
}
$doc=['stamp_duty_amount'=>2,'template_snapshot_json'=>json_encode(['document_stamp'=>['stamp_charge_customer'=>false,'stamp_method'=>'adhesive','stamp_identifier'=>'00123456789012']])];
check(Stamp::chargedAmount($doc)===0.0,'Borne by issuer is not charged');
check(str_contains(Stamp::wording($doc),'00123456789012'),'Leading zeroes preserved');
check(!str_contains(Stamp::wording(['stamp_duty_amount'=>2]),'assolta'),'Legacy amount does not claim paid tax');
$frozen=App\Services\BillingDocumentService::resolveOutputDocument($doc+['vat_nature'=>'N4'],['vat'=>['default_nature'=>'CHANGED']]);
check($frozen['vat_nature']==='N4','New fiscal documents keep their nature');
echo "PASS: automatic and legacy settings, threshold, sum, taxable/exempt cases, manual override, validation, identifier, snapshots\n";
