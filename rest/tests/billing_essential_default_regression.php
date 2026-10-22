<?php
require __DIR__.'/billing_legacy_render_regression.php';
use App\Services\BillingDocumentDesigner as Designer;
$old=['version'=>1,'blocks'=>[
 ['background'=>'#000000','color'=>'#ffffff','elements'=>[['type'=>'title'],['type'=>'issuer_name'],['type'=>'issuer_vat_number','label'=>'P. IVA personalizzata','show_label'=>true]]],
 ['span'=>6,'elements'=>[['type'=>'patient_name','label'=>'Destinatario speciale','show_label'=>true],['type'=>'patient_address']]],
 ['elements'=>[['type'=>'line_items']]],
 ['elements'=>[['type'=>'text','text'=>'IBAN PERSONALIZZATO DA CONSERVARE']]],
 ['elements'=>[['type'=>'terms','label'=>'INFORMATIVA PERSONALIZZATA']]],
 ['span'=>6,'elements'=>[['type'=>'subtotal'],['type'=>'vat'],['type'=>'stamp'],['type'=>'total']]],
]];
$raw=Designer::validate($old);
$model=Designer::fromConfig(['designer'=>$old]);
$elements=static function($m) { $items=[];foreach($m['blocks'] as $b)foreach($b['elements'] as $e)$items[]=json_encode($e);sort($items);return $items; };
check(array_diff($elements($raw),$elements($model))===[],'Upgrade retains every field, duplicate, label and literal text');
check(($model['preset']??'')==='essential-v1','Upgrade loads essential default');
check($old['blocks'][0]['background']==='#000000','Read-time upgrade does not rewrite stored preferences');
check(Designer::fromConfig(['designer'=>$model])===$model,'Reopening is stable');
$model['blocks'][0]['background']='#ffeeaa';$model['blocks'][0]['span']=4;$model['blocks'][0]['font']=14;
$model['blocks']=array_reverse($model['blocks']);
$model=Designer::validate($model);
$config=$sanitize->invoke($settings, $settings->defaultConfig()+['designer'=>$model]);
check(Designer::fromConfig($config)===$model,'Edited order, width, color and size survive save and reload');
$reset=Designer::essential($model);
check($elements($reset)===$elements($model),'Reapply default retains current unsaved content');
$preview=Designer::sample($settings->defaultConfig());
$pdfHtml=view('admin/billing/document_pdf',['preview'=>$preview]);
$html=view('admin/billing/document_preview',['preview'=>$preview]);
check(str_contains($pdfHtml,'db-section-patient')&&str_contains($html,'db-section-patient'),'Default invoice and preview both use the essential model');
$preview['template']['designer']=$old;
$render=view('admin/billing/document_pdf',['preview'=>$preview]);
check(str_contains($render,'IBAN PERSONALIZZATO DA CONSERVARE')&&str_contains($render,'INFORMATIVA PERSONALIZZATA'),'Existing custom content appears in actual PDF HTML');
echo "PASS: default, legacy upgrade, field conservation, editable saved model and shared preview/PDF renderer\n";
