<?php
require __DIR__.'/billing_legacy_render_regression.php';
use App\Services\BillingDocumentDesigner as Designer;
$config=$settings->defaultConfig();
$config['branding']['header_title']='FATTURA';
$config['branding']['header_subtitle']='Prestazioni professionali';
$config['branding']['header_document_number']=true;
$config['branding']['header_issue_date']=true;
$config['fiscal_data']['business_name']='Dott.ssa Giulia Rossi';
$config['fiscal_data']['address']='Via Roma 24';
$config['fiscal_data']['city']='Milano';
$config['fiscal_data']['vat_number']='ESEMPIO';
$config['branding']['footer_note']='Esempio grafico - dati fittizi';
$config['fields']['show_patient_address']=true;
$config['fields']['show_patient_city']=true;
$config['layout']['show_document_box']=false;
$preview=Designer::sample($config);
$preview['document']['notes']='Pagamento relativo alle prestazioni indicate. Documento dimostrativo.';
$pdf=new Dompdf\Dompdf($options);
$pdf->loadHtml(view('admin/billing/document_pdf',['preview'=>$preview]),'UTF-8');
$pdf->setPaper('A4');$pdf->render();
check($pdf->getCanvas()->get_page_count()===1,'Standard professional invoice fits A4');
$dest=dirname(__DIR__).'/build/billing-professional';
if (!is_dir($dest)) mkdir($dest,0700,true);
file_put_contents($dest.'/fattura-professionale-essenziale.pdf',$pdf->output());
// Every catalog element remains accepted, ordered and rendered in a saved model.
$blocks=[];
foreach(Designer::catalog() as $type=>$label) $blocks[]=['name'=>$label,'elements'=>[['type'=>$type,'label'=>'CAMPO-'.$type,'show_label'=>true,'text'=>'CONTENUTO-LIBERO']]];
$config['designer']=Designer::validate(['version'=>1,'preset'=>'essential-v1','universal_fields'=>true,'blocks'=>$blocks]);
check(Designer::fromConfig($config)===$config['designer'],'Saved model remains identical');
$preview=Designer::sample($config);
foreach(Designer::catalog() as $type=>$label) $preview['document'][$type]=$preview['document'][$type]??'VALORE';
$preview['template']['branding']['terms_text']='INFORMATIVA-COMPLETA';
$preview['template']['branding']['header_extra']='ALBO-PROFESSIONALE';
$preview['template']['fiscal_data']['tax_code']='CF-EMITTENTE';
$preview['template']['fiscal_data']['pec']='studio@example.test';
$preview['template']['pension_fund']=['enabled'=>true,'name'=>'CASSA','registration_number'=>'123','contribution_rate'=>2];
$preview['template']['recipient_type']='business';
$preview['template']['patient_details']['business_vat_number']='01234567890';
$preview['template']['fiscal_data']['iban']='IT00TEST';
$html=str_replace("\u{200B}",'',view('admin/billing/designer_document',['preview'=>$preview,'pdfMode'=>true]));
foreach(Designer::catalog() as $type=>$label) {
 if(in_array($type,['logo','divider','spacer','line_items'],true)) continue;
 check(str_contains($html,'CAMPO-'.$type),'Dynamic element retained: '.$type);
}
check(str_contains($html,'INFORMATIVA-COMPLETA') && str_contains($html,'ALBO-PROFESSIONALE'),'Long dynamic content retained');
echo "PASS: professional A4, all catalog fields and unchanged saved model\n";
