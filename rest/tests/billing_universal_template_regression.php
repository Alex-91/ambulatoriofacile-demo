<?php
require __DIR__.'/billing_legacy_render_regression.php';
use App\Services\BillingDocumentDesigner as Designer;
use App\Services\BillingDocumentService as Documents;
$config=$settings->defaultConfig();
$config['designer']=Designer::fromConfig($config);
$config['designer']['blocks'][0]['elements'][]=['type'=>'text','text'=>'MODELLO-CORRENTE','label'=>'','show_label'=>false];
$snapshot=['recipient_type'=>'business','patient_details'=>['business_vat_number'=>'01234567890','business_recipient_code'=>'ABC1234','business_pec'=>'pec@example.test','patient_address'=>'Via Storica 5','patient_city'=>'Roma']];
$original=$snapshot;
$template=Documents::resolveRenderingTemplate($snapshot,$config);
$preview=Designer::sample($template);
$preview['document']['patient_name']='Clinica Esempio';
$preview['document']['patient_email']='amministrazione@example.test';
$preview['managed_collections']=true;
foreach (['admin/billing/document_pdf','admin/billing/document_preview','admin/billing/business_document_print','admin/polyclinic/document_pdf'] as $route) {
    $html=view($route,['preview'=>$preview]);
    foreach (['db-section-heading','MODELLO-CORRENTE','Clinica Esempio','01234567890','ABC1234','pec@example.test','Via Storica 5'] as $expected) check(str_contains($html,$expected),$route.' retains '.$expected);
}
check($snapshot===$original,'Historical snapshot is not rewritten');
$clinic=Documents::resolveRenderingTemplate(['polyclinic_recipient'=>['type'=>'business','vat_number'=>'98765432109','address'=>'Via Clinica 8','city'=>'Pisa','recipient_code'=>'XYZ1234']],$config);
check($clinic['recipient_type']==='business' && $clinic['patient_details']['business_vat_number']==='98765432109','Legacy collection recipient is mapped without losing identifiers');
$model=$config['designer'];
$model['blocks']=array_reverse($model['blocks']);
$model['blocks'][0]['font']=14;$model['blocks'][0]['background']='#abc123';
foreach($model['blocks'] as &$block) $block['elements']=array_values(array_filter($block['elements'],static fn($e)=>$e['type']!=='business_details'));
unset($block);
$model=Designer::validate($model);
check(Designer::fromConfig(['designer'=>$model])===$model,'Saved edits, ordering and explicitly removed blocks survive reload');
$pdf=new Dompdf\Dompdf($options);$pdf->loadHtml(view('admin/billing/document_pdf',['preview'=>$preview]),'UTF-8');$pdf->setPaper('A4');$pdf->render();
check($pdf->getCanvas()->get_page_count()>=1,'Business invoice renders through common model');
file_put_contents(dirname(__DIR__).'/build/billing-port/universal-business.pdf',$pdf->output());
echo "PASS: all invoice renderers, historical recipients, current template and persistent block edits\n";
