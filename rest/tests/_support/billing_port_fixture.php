<?php
// CLI-only synthetic preview fixture. Never loads .env, database or patient data.
if (PHP_SAPI !== 'cli') exit(1);
ob_start();require dirname(__DIR__).'/billing_header_regression.php';ob_end_clean();
function helper($names) {}
function old($key) {return null;}
function base_url($path='') {return '/'.$path;}
function site_url($path='') {return '/'.$path;}
function portal_tenant_space_url($path='') {return '/login/spazio/'.$path;}
function csrf_field() {return '<input type="hidden" name="csrf_test" value="synthetic">';}
function csrf_token() {return 'csrf_test';}
function view($name,$data=[]) {return '';}
if (($argv[1]??'')==='render') {
 $input=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
 $cfg=$settings->defaultConfig();$cfg['designer']=App\Services\BillingDocumentDesigner::validate(json_decode($input['designer_json'],true));
 foreach(['header_title','header_subtitle','header_extra','terms_text','footer_note','logo_url'] as $key)$cfg['branding'][$key]=$input['branding_'.$key]??'';
 $cfg['document_title']=$input['document_title']??'Fattura';
 $cfg['branding']['logo_mode']=isset($input['branding_logo_enabled'])?'path':'none';
 $preview=App\Services\BillingDocumentDesigner::sample($cfg);
 require dirname(__DIR__,2).'/app/Views/admin/billing/designer_document.php';
} else {
 $settings=['config'=>$settings->defaultConfig()];$menu_items=[];
 require dirname(__DIR__,2).'/app/Views/admin/billing/document_designer.php';
}
