<?php
if(PHP_SAPI!=='cli')exit(1);
function helper($names){}
function esc($v,$context='html'){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function old($key){return null;}
function session(){return new class {public function get($key){return [];} };}
function base_url($path=''){return '/'.$path;}
function portal_tenant_space_url($path=''){return '/login/spazio/'.$path;}
function csrf_field(){return '<input type="hidden" name="csrf_test" value="synthetic">';}
function csrf_token(){return 'csrf_test';}
function view($name,$data=[]){return '';}
define('APPPATH',dirname(__DIR__,2).'/app/');
spl_autoload_register(static function($class){if(str_starts_with($class,'App\\')){$file=APPPATH.str_replace('\\','/',substr($class,4)).'.php';if(is_file($file))require $file;}});
$catalog=['doctors'=>[['id'=>10,'label'=>'Dott. Marco Rossi']],'visits'=>[['id'=>1,'label'=>'Prima visita','duration'=>30],['id'=>2,'label'=>'Visita di controllo','duration'=>20]],'locations'=>[['id'=>1,'label'=>'Studio centrale']]];
if(($argv[1]??'')==='preview'){
    $data=json_decode(stream_get_contents(STDIN),true);$c=\App\Services\WhatsAppBookingConfig::normalize($data['config']);
    \App\Services\WhatsAppBookingConfig::validate($c,$catalog);
    $result=(new \App\Services\WhatsAppBookingEngine())->advance($c,$data['state'],$data['message'],new \App\Services\WhatsAppBookingPreview($catalog,$data['found']));
    echo json_encode($result+['csrf'=>'synthetic']);exit;
}
$tenant=['tenant_name'=>'Studio medico Rossi'];$config=\App\Services\WhatsAppBookingConfig::normalize(['configured'=>true,'doctor_id'=>10,'location_id'=>1,'location'=>'Studio centrale · Via Roma 10','visit_ids'=>[1,2],'default_visit_id'=>1,'patient_mode'=>'cf']);
$ready=$enabled=true;$errors=$requests=[];$success=null;
ob_start();require APPPATH.'Views/tenant/whatsapp_booking.php';$html=ob_get_clean();
// The surrounding application header is intentionally omitted from this standalone fixture.
echo str_replace('<body class="skin-blue sidebar-mini">','<body class="skin-blue sidebar-mini"><style>.content-wrapper{margin-left:0!important}</style>',$html);
