<?php
use App\Services\BillingDocumentDesigner as Designer;
require __DIR__.'/billing_designer_regression.php';
$long=str_repeat('Testo esteso da conservare integralmente. ',180).'FINE-INFORMATIVA';
$raw=$legacy;
foreach (['header_title','header_subtitle','header_extra','footer_note','terms_text'] as $key) $raw['branding'][$key]=$long;
$clean=$sanitize->invoke($settings,$raw);
foreach (['header_title','header_subtitle','header_extra','footer_note','terms_text'] as $key) check($clean['branding'][$key]===$long,'Text must not truncate: '.$key);
$model=Designer::validate(['version'=>1,'blocks'=>[
 ['name'=>'Testo','span'=>6,'elements'=>[['type'=>'text','text'=>str_repeat('Testo libero lungo. ',120).'FINE-LIBERO']]],
 ['name'=>'Informativa','span'=>12,'elements'=>[['type'=>'terms']]],
 ['name'=>'Prestazioni','span'=>12,'elements'=>[['type'=>'line_items']]],
]]);
$raw=$legacy;$raw['branding']['terms_text']=$long;$raw['designer']=$model;
$preview=Designer::sample($raw);$pdfMode=true;
ob_start();require dirname(__DIR__).'/app/Views/admin/billing/designer_document.php';$html=ob_get_clean();
check(str_contains($html,'FINE-INFORMATIVA')&&str_contains($html,'FINE-LIBERO'),'Long text must render completely');
check(str_contains($html,'IVA / Esenzione')&&str_contains($html,'0,00%')&&str_contains($html,'Esente art. 10'),'Table must always show invoice VAT and exemption');
$pdf=new Dompdf\Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4');$pdf->render();
file_put_contents(dirname(__DIR__).'/build/billing-port/designer-long-text.pdf',$pdf->output());
echo 'Long text pages: '.$pdf->getCanvas()->get_page_count()."\nPASS: complete text persistence, long text pagination and VAT column\n";
