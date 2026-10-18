<?php
// Render synthetic UI only: no application bootstrap, .env, sessions or database.
if (PHP_SAPI !== 'cli') exit(1);
function esc($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function old($key) { return null; }
function session() { return new class { public function get($key) { return []; } public function getFlashdata($key) { return null; } }; }
function base_url($path='') { return '/'.$path; }
function site_url($path='') { return '/'.$path; }
function csrf_field() { return '<input type="hidden" name="csrf_test" value="synthetic">'; }
function view($name,$data=[]) {
    if ($name==='partials/sidebar_admin') return '<div class="box box-solid"><div class="box-body"><a href="#">Fatturazione</a><hr><strong>Fatture a strutture</strong><hr><a href="#">Lista fatture</a></div></div>';
    if ($name!=='admin/billing/business_fields') return '';
    extract($data);ob_start();require dirname(__DIR__).'/app/Views/'.$name.'.php';return ob_get_clean();
}
function verifyBusinessView($ok,$message) { if (!$ok) throw new RuntimeException($message); }
$menu_items=[];$businessMode=true;
$formContext=['document'=>['patient_name'=>'Clinica Aurora S.r.l.','business_vat_number'=>'12345678903','business_country'=>'IT','issue_date'=>'2026-10-18'],
    'line_items'=>[['description'=>'Prestazioni professionali di ottobre','quantity'=>1,'unit_amount'=>1000]],
    'document_type_labels'=>['invoice'=>'Fattura'],'payment_method_labels'=>['bank_transfer'=>'Bonifico'],'ts_enabled'=>true];
ob_start();require dirname(__DIR__).'/app/Views/admin/billing/document_form.php';$html=ob_get_clean();
$dom=new DOMDocument();@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
verifyBusinessView($xpath->query('//form[@action="/admin/fatturazione-strutture/save"]')->length===1,'Dedicated save route');
verifyBusinessView($xpath->query('//input[@name="business_vat_number"]')->length===1,'Business fields');
verifyBusinessView($xpath->query('//input[@name="patient_search_term"]')->length===0,'No patient autocomplete');
verifyBusinessView($xpath->query('//input[@name="ts_sync_enabled"]')->length===0,'No TS checkbox');
verifyBusinessView($xpath->query('//button[@value="final_send_ts"]')->length===0,'No TS send button');
verifyBusinessView($xpath->query('//form//button[@value="final"]')->length===1,'Submit button stays inside form');
$out=dirname(__DIR__).'/build/business-billing';if(!is_dir($out))mkdir($out,0777,true);
file_put_contents($out.'/form.html',$html);
$businessMode=false;
ob_start();require dirname(__DIR__).'/app/Views/admin/billing/document_form.php';$patientHtml=ob_get_clean();
verifyBusinessView(str_contains($patientHtml,'name="patient_search_term"'),'Patient flow preserved');
verifyBusinessView(str_contains($patientHtml,'value="final_send_ts"'),'Patient TS flow preserved');
echo "PASS: business form, patient form, fields, TS exclusion and submit structure\n";
