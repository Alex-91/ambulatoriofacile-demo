<?php
require __DIR__.'/billing_legacy_render_regression.php';
use App\Services\BillingStampDuty;
$out=dirname(__DIR__).'/build/stamp';
if (!is_dir($out)) mkdir($out,0777,true);
foreach (['adhesive','virtual','business'] as $case) {
    $config=$settings->defaultConfig();
    $preview=App\Services\BillingDocumentDesigner::sample($config);
    $stamp=BillingStampDuty::defaults($config);
    $stamp['stamp_method']=$case==='virtual'?'virtual':($case==='business'?'sdi':'adhesive');
    $stamp['stamp_identifier']=$case==='adhesive'?'00123456789012':'';
    $stamp['stamp_charge_customer']=$case!=='adhesive';
    $stamp['stamp_authorization_number']='12345/TEST';
    $stamp['stamp_authorization_date']='2026-10-01';
    $stamp['stamp_authorization_office']='Firenze';
    $preview['template']['document_stamp']=$stamp;
    $preview['document']['stamp_duty_amount']=2;
    $preview['document']['subtotal_amount']=100;
    $preview['document']['amount_total']=$case==='adhesive'?100:102;
    $preview['line_items']=[['description'=>'Visita specialistica di prova','quantity'=>1,'unit_amount'=>100,'line_total'=>100]];
    if ($case==='virtual') $preview['template']['designer']=App\Services\BillingDocumentDesigner::fromConfig($config);
    if ($case==='business') {
        $preview['template']['recipient_type']='business';
        $preview['document']['patient_name']='Clinica di prova';
    }
    $preview['document']['template_snapshot_json']=json_encode($preview['template']);
    $html=view('admin/billing/document_pdf',['preview'=>$preview]);
    check(str_contains($html,'Imposta di bollo:'),'Stamp notice must render in '.$case);
    if ($case==='adhesive') check(str_contains($html,'00123456789012')&&str_contains($html,'non addebitata'),'Adhesive identifier and issuer charge');
    if ($case==='virtual') check(str_contains($html,'12345/TEST del 01/10/2026'),'Virtual authorization');
    file_put_contents($out.'/'.$case.'.html',$html);
    $pdf=new Dompdf\Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4');$pdf->render();
    check($pdf->getCanvas()->get_page_count()===1,'Simple stamp invoice fits one page');
    file_put_contents($out.'/'.$case.'.pdf',$pdf->output());
}
echo "PASS: legacy, designer and business PDF stamps, identifiers, authorization and issuer charge\n";
