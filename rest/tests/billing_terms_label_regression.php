<?php
require __DIR__.'/billing_header_regression.php';
use App\Services\BillingDocumentDesigner;
$config=$settings->defaultConfig();
$config['designer']=BillingDocumentDesigner::validate(['version'=>1,'blocks'=>[
 ['elements'=>[['type'=>'terms','label'=>'Informativa scritta nella vecchia etichetta','show_label'=>true]]],
]]);
$preview=BillingDocumentDesigner::sample($config);$pdfMode=true;
ob_start();require dirname(__DIR__).'/app/Views/admin/billing/designer_document.php';$html=ob_get_clean();
check(substr_count($html,'Informativa scritta nella vecchia etichetta')===1,'Legacy terms label must render once as content');
$preview['template']['branding']['terms_text']='Testo informativa dedicato';
ob_start();require dirname(__DIR__).'/app/Views/admin/billing/designer_document.php';$html=ob_get_clean();
check(str_contains($html,'Testo informativa dedicato'),'Dedicated terms text must take precedence');
echo "PASS: terms label fallback and dedicated text\n";
