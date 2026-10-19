<?php
// CLI render of destination views, without sessions, network or database connections.
if (PHP_SAPI !== 'cli') { exit(1); }
function esc($s,$context='html') {return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function session() {return new class {function get($k){return null;} function has($k){return false;} function getFlashdata($k){return null;}};}
function view($name,$data=[]) {return '';}
function site_url($p='') {return '/'.$p;}
function base_url($p='') {return '/'.$p;}
function csrf_field() {return '<input type="hidden" name="csrf_test" value="synthetic">';}
function portal_tenant_space_url($p='') {return '/login/spazio/'.$p;}
$menu_items=[]; $tsEnabled=false;
$rows=[];
foreach (range(1,14) as $id) $rows[]=['id_billing_document'=>$id,'document_number'=>'SYNTHETIC-'.$id,'patient_name'=>'Cliente '.$id,
    'issue_date'=>'2026-10-19','local_state'=>$id===1?'draft':'issued','payment_status'=>'paid','amount_total'=>102,
    'subtotal_amount'=>100,'stamp_duty_amount'=>2,'document_type'=>'invoice','payment_method'=>'cash','ts_sync_state'=>'not_requested'];
$listing=['table_available'=>true,'documents'=>$rows];
ob_start(); require dirname(__DIR__).'/app/Views/admin/billing/documents.php'; $html=ob_get_clean();
file_put_contents(dirname(__DIR__).'/build/billing-export-list.html',$html);
$billingQueue=['pending_documents'=>array_slice($rows,1,2),'sent_documents'=>array_slice($rows,3,2)];
$listing=['table_available'=>true,'documents'=>[]];
ob_start(); require dirname(__DIR__).'/app/Views/admin/ts/documents.php'; $html=ob_get_clean();
file_put_contents(dirname(__DIR__).'/build/billing-export-ts.html',$html);
echo "PASS: synthetic invoice and TS views rendered\n";
