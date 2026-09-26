<?php
require __DIR__.'/billing_header_regression.php';
require_once dirname(__DIR__,2).'/vendor/autoload.php';
use App\Services\BillingDocumentDesigner as Designer;
$legacy=$settings->defaultConfig();
$legacy['branding']['terms_text']="Informativa di esempio\nSeconda riga";
$model=Designer::fromConfig($legacy);
check(count($model['blocks'])>4,'Legacy preferences must convert to blocks');
check(Designer::validate($model)===$model,'Designer normalization must be stable');
$config=$sanitize->invoke($settings,$legacy+['designer'=>$model]);
check($config['designer']===$model,'Designer must survive settings persistence');
$bad=$model;$bad['blocks'][0]['elements']=[['type'=>'unknown']];
try {Designer::validate($bad);throw new RuntimeException('Unknown type accepted');}catch(InvalidArgumentException $e){}
$bad=$model;$bad['blocks'][0]['span']=6;$bad['blocks'][0]['elements']=[['type'=>'line_items']];
try {Designer::validate($bad);throw new RuntimeException('Narrow table accepted');}catch(InvalidArgumentException $e){}
$bad=$model;$bad['blocks'][0]['background']='red;position:absolute';
try {Designer::validate($bad);throw new RuntimeException('CSS injection accepted');}catch(InvalidArgumentException $e){}
$preview=Designer::sample($config);$pdfMode=true;
$preview['document']['patient_name']='<script>unsafe</script>';
ob_start();require dirname(__DIR__).'/app/Views/admin/billing/designer_document.php';$html=ob_get_clean();
check(!str_contains($html,'<script>unsafe')&&str_contains(str_replace("\u{200B}", '', $html),'&lt;script&gt;unsafe'),'Document values must be escaped');
check(!str_contains($html,'@media screen'),'PDF must not inherit screen padding');
if (!is_dir(dirname(__DIR__).'/build/billing-port')) mkdir(dirname(__DIR__).'/build/billing-port',0700,true);
$options=new Dompdf\Options();$options->set('isRemoteEnabled',false);
$pdf=new Dompdf\Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4');$pdf->render();
file_put_contents(dirname(__DIR__).'/build/billing-port/designer-sample.pdf',$pdf->output());
echo 'Sample pages: '.$pdf->getCanvas()->get_page_count()."\n";
$preview=Designer::sample($config);
$preview['line_items']=[];
for($i=1;$i<=70;$i++) $preview['line_items'][]=['description'=>'RIGA-'.$i.' Visita specialistica con descrizione dettagliata e referto di controllo.','quantity'=>1,'unit_amount'=>10,'line_total'=>10];
$preview['document']['notes']=str_repeat('Nota clinica di esempio per controllo impaginazione. ',120);
ob_start();require dirname(__DIR__).'/app/Views/admin/billing/designer_document.php';$html=ob_get_clean();
$pdf=new Dompdf\Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4');$pdf->render();
file_put_contents(dirname(__DIR__).'/build/billing-port/designer-multipage.pdf',$pdf->output());
check($pdf->getCanvas()->get_page_count()>2,'Long invoices must paginate');
echo 'Long document pages: '.$pdf->getCanvas()->get_page_count()."\nPASS: designer migration, persistence, validation, escaping and multipage PDF generation\n";
