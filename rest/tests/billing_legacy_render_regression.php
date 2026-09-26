<?php
require __DIR__.'/billing_header_regression.php';
require_once dirname(__DIR__,2).'/vendor/autoload.php';
function view($name,$data=[]) {extract($data);ob_start();require dirname(__DIR__).'/app/Views/'.$name.'.php';return ob_get_clean();}
function base_url($path='') {return '/'.$path;}
function site_url($path='') {return '/'.$path;}
$legacy=$settings->defaultConfig();$legacy['branding']['terms_text']='LEGACY-INFORMATIVA';$legacy['branding']['footer_note']='LEGACY-FOOTER';$legacy['layout']['show_terms_box']=true;
$preview=App\Services\BillingDocumentDesigner::sample($legacy);
foreach(['document_pdf','document_preview'] as $viewName) {
 $html=view('admin/billing/'.$viewName,['preview'=>$preview]);
 check(str_contains($html,'IVA / Esenzione'),'Legacy VAT column must render');
 check(substr_count($html,'LEGACY-INFORMATIVA')===1 && substr_count($html,'LEGACY-FOOTER')===1,'Legacy terms/footer must remain separate');
 check(str_contains($html,'Giulia Bianchi'),'Old patient data must remain printable');
}
if (!is_dir(dirname(__DIR__).'/build/billing-port')) mkdir(dirname(__DIR__).'/build/billing-port',0700,true);
$options=new Dompdf\Options();$options->setIsRemoteEnabled(false);
$pdf=new Dompdf\Dompdf($options);$pdf->loadHtml(view('admin/billing/document_pdf',['preview'=>$preview]),'UTF-8');$pdf->setPaper('A4');$pdf->render();
check(file_put_contents(dirname(__DIR__).'/build/billing-port/legacy.pdf',$pdf->output())!==false,'Legacy PDF must save');
echo "PASS: legacy invoice HTML/PDF, VAT column, patient and unique terms/footer\n";
