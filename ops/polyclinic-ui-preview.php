<?php
// Synthetic, read-only view preview. No framework bootstrap, .env, sessions or database.
if (!in_array(PHP_SAPI,['cli','cli-server'],true)) { http_response_code(404); exit; }
$repo=dirname(__DIR__);
if (PHP_SAPI==='cli-server') {
    if (($_SERVER['REQUEST_METHOD']??'')!=='GET') { http_response_code(405); exit; }
    $path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/');
    if (str_starts_with($path,'/public/')) {
        $root=realpath($repo.'/public'); $asset=realpath($repo.$path);
        $types=['css'=>'text/css','js'=>'application/javascript','woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf','png'=>'image/png','jpg'=>'image/jpeg'];
        $ext=pathinfo((string)$asset,PATHINFO_EXTENSION);
        if (!$asset || !str_starts_with($asset,$root.DIRECTORY_SEPARATOR) || !isset($types[$ext])) { http_response_code(404); exit; }
        header('Content-Type: '.$types[$ext]); readfile($asset); exit;
    }
} else { $_GET['tab']=$argv[1]??'accettazione'; $_GET['kind']=$argv[2]??'service'; }
require $repo.'/rest/app/Services/PolyclinicMoney.php';
require $repo.'/rest/app/Services/PolyclinicAdministrationService.php';
require $repo.'/rest/app/Services/PolyclinicAccountingExport.php';
function esc($v) { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function base_url($v) { return '/'.ltrim($v,'/'); }
function site_url($v) { return '/'.ltrim($v,'/'); }
function csrf_field() { return '<input type="hidden" name="csrf_synthetic" value="preview-only">'; }
function session() { return new class { public function getFlashdata($k) { return null; } }; }
function service($name) { return new class { public function getGet($key) { return $_GET[$key]??null; } }; }
function view($name,$data) { return '<header style="background:#17636b;color:white;padding:15px 28px">AmbulatorioFacile · Anteprima sintetica · azioni disabilitate</header>'; }
$tab=$_GET['tab']??'accettazione';
if (!in_array($tab,['accettazione','catalogo','documenti','report','integrazioni','requisiti'],true)) exit(1);
$ready=true; $date='2026-09-21'; $menu_items=[]; $tenantScope=['tenant_name'=>'Centro dimostrativo'];
$catalog=array_fill_keys(array_keys(\App\Services\PolyclinicAdministrationService::KINDS),[]);
foreach (['branch'=>'Cardiologia','doctor'=>'Dott.ssa Medico Sintetico','service'=>'Visita cardiologica','list'=>'Convenzionati','agreement'=>'Fondo dimostrativo','rule'=>'Compenso visita'] as $kind=>$name) $catalog[$kind][]=['id'=>1,'name'=>$name,'code'=>'TEST','active'=>1,'version'=>0,'data'=>['branch_id'=>1,'price_cents'=>10000]];
$tariffs=[['list_id'=>1,'service_id'=>1,'amount_cents'=>8500,'version'=>0]];
$patients=[['id_client'=>100,'patient_name'=>'Paziente <sintetico>','patient_tax_code'=>'TEST']];
$snap=['service'=>'Visita cardiologica','doctor'=>'Dott.ssa Medico Sintetico','branch'=>'Cardiologia','agreement'=>'Fondo dimostrativo','authorization'=>'AUT-TEST'];
$order=['id'=>1,'quantity'=>1,'total_cents'=>10000,'payer_cents'=>7500,'billing_id'=>null,'snapshot_json'=>json_encode($snap)];
$encounters=[['id'=>1,'patient_name'=>'Paziente <sintetico>','doctor_id'=>1,'state'=>'waiting','version'=>1,'orders'=>[$order]],['id'=>2,'patient_name'=>'Secondo Paziente','doctor_id'=>1,'state'=>'completed','version'=>3,'orders'=>[$order]]];
$balance=['total_cents'=>10200,'paid_cents'=>5100,'due_cents'=>5100,'credit_cents'=>0,'net_cents'=>10200,'refund_due_cents'=>0,'organization_net_cents'=>7500,'organization_paid_cents'=>2500,'patient_net_cents'=>2700,'patient_paid_cents'=>2600];
$doc=['id_billing_document'=>1,'document_number'=>'FT-2026-PC-000001','patient_name'=>'Paziente Sintetico','document_type'=>'invoice','issue_date'=>$date,'balance'=>$balance];
$documents=[$doc];
$detail=['document'=>$doc,'state'=>['version'=>1,'original_id'=>null,'einvoice_state'=>'not_prepared'],'balance'=>$balance,'payments'=>[['payment_date'=>$date,'amount_cents'=>5100,'method'=>'bank_transfer','payer'=>'patient','reference'=>'Test']], 'installments'=>[['due_date'=>'2026-10-01','amount_cents'=>5100,'due_cents'=>0],['due_date'=>'2026-11-01','amount_cents'=>5100,'due_cents'=>5100]],'plan_needs_update'=>false,'compensation'=>[['doctor_id'=>1,'doctor'=>'Dott.ssa Medico Sintetico','earned_cents'=>2500,'settled_cents'=>0,'due_cents'=>2500]],'settlements'=>[]];
$report=['from'=>'2026-09-01','to'=>'2026-09-30','group'=>'doctor','rows'=>[['label'=>'Dott.ssa Medico Sintetico','quantity'=>2,'gross_cents'=>20000,'credit_cents'=>5000,'net_cents'=>15000,'cash_cents'=>5000]],'compensation'=>$detail['compensation']];
$accounting=$einvoice=['version'=>-1,'data'=>[]]; $audit=[];
require $repo.'/rest/app/Views/admin/polyclinic/index.php';
