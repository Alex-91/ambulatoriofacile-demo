<?php
require __DIR__.'/billing_designer_regression.php';
$raw=$settings->defaultConfig();
$raw['branding']['terms_text']='INFORMATIVA-UNA-VOLTA';
$raw['branding']['footer_note']='FOOTER-UNA-VOLTA';
$raw['designer']=App\Services\BillingDocumentDesigner::validate(['version'=>1,'blocks'=>[
 ['span'=>4,'elements'=>[['type'=>'text','text'=>str_repeat('W',110).' FINE-COLONNA']]],
 ['span'=>4,'elements'=>[['type'=>'text','text'=>'Seconda colonna']]],
 ['span'=>4,'elements'=>[['type'=>'text','text'=>'Terza colonna']]],
 ['elements'=>[['type'=>'line_items']]],
 ['elements'=>[['type'=>'terms']]],
 ['elements'=>[['type'=>'footer']]],
]]);
$preview=App\Services\BillingDocumentDesigner::sample($raw);$preview['line_items']=[];
for($i=1;$i<=70;$i++)$preview['line_items'][]=['description'=>'RIGA-'.$i.' '.str_repeat('Prestazione ',8),'quantity'=>1,'unit_amount'=>10,'line_total'=>10];
$preview['line_items'][0]['description']=str_repeat('W',180).' FINE-PRESTAZIONE';
ob_start();require dirname(__DIR__).'/app/Views/admin/billing/designer_document.php';$html=ob_get_clean();
check(substr_count($html,'INFORMATIVA-UNA-VOLTA')===1,'Terms must appear once');
check(substr_count($html,'FOOTER-UNA-VOLTA')===1,'Footer must appear once');
$pdf=new Dompdf\Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4');$pdf->render();
check(file_put_contents(dirname(__DIR__).'/build/billing-port/designer-stress.pdf',$pdf->output())!==false,'PDF must be written');
echo "PASS: narrow columns, unspaced descriptions, unique terms/footer and 70 items\n";
