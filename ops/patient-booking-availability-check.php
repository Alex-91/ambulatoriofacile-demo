<?php
declare(strict_types=1);

// Container-only availability audit. Does not create, move or cancel appointments.
// The explicit repair removes obsolete defaults only when all choice steps are off;
// it uses the configuration service, revision CAS and normal publication history.
if(PHP_SAPI!=='cli' || !is_file('/.dockerenv'))exit(2);
$target=$argv[1]??'';$space=$argv[2]??'';$action=$argv[3]??'audit';$revision=(int)($argv[4]??0);
$urls=['demo'=>'https://ambulatoriofacile.it/demo/','login'=>'https://ambulatoriofacile.it/app/'];
if(!isset($urls[$target]) || $space==='' || !in_array($action,['audit','remove-hidden-defaults'],true))exit(2);
define('FCPATH','/var/www/html/rest/public/');
require FCPATH.'../app/Config/Paths.php';require FCPATH.'../system/Boot.php';
final class PatientBookingAvailabilityBoot extends \CodeIgniter\Boot
{
    public static function start(\Config\Paths $paths): void
    {
        static::definePathConstants($paths);static::loadConstants();static::checkMissingExtensions();
        static::loadDotEnv($paths);static::defineEnvironment();static::loadEnvironmentBootstrap($paths);
        static::loadCommonFunctions();static::loadAutoloader();static::setExceptionHandler();
        static::initializeKint();static::autoloadHelpers();static::initializeCodeIgniter();
    }
}
try{
    chdir(FCPATH);PatientBookingAvailabilityBoot::start(new \Config\Paths());
    if(config('App')->baseURL!==$urls[$target])throw new RuntimeException('Unexpected deployment');
    $platform=\Config\Database::connect('platform');
    if($action==='audit')$platform->query('START TRANSACTION READ ONLY');
    $matches=$platform->table('platform_tenants')->where('tenant_name',$space)->where('is_active',1)->get()->getResultArray();
    if(count($matches)!==1)throw new RuntimeException('Expected one active space');
    $tenant=(new \App\Services\TenantCatalogService())->getTenantById((int)$matches[0]['id_tenant']);
    $store=new \App\Services\PatientBookingSettings($platform);$settings=$store->get((int)$tenant['id_tenant']);
    if(!$settings['enabled'])throw new RuntimeException('Booking module is not enabled');
    $changed=false;
    if($action==='remove-hidden-defaults'){
        if($revision<=0 || $settings['revision']!==$revision)throw new RuntimeException('Configuration revision changed');
        $config=\App\Services\PatientBookingEditor::removeHiddenDefaults($settings['config']);
        $changed=$config!==$settings['config'];
        if($changed){
            $store->saveConfiguration((int)$tenant['id_tenant'],$config,0,$revision);
            $settings=$store->get((int)$tenant['id_tenant']);
        }
    }
    $db=(new \App\Services\TenantDatabaseConnector())->connect($tenant);$db->query('START TRANSACTION READ ONLY');
    $agenda=new \App\Services\PatientBookingAgenda($db,$settings,0);$workflow=\App\Services\PatientBookingWorkflow::resolve($settings['config']);
    $now=new DateTimeImmutable('now',new DateTimeZone('Europe/Rome'));
    $query=['professionista'=>'primo'];
    $service=$workflow['scelte']['prestazione_predefinita'];
    if(!$service && \App\Services\PatientBookingWorkflow::stepEnabled($workflow,'prestazione'))$service=$settings['config']['visits'][0]??null;
    if($service)$query['prestazione']=(string)$service;
    $method=new ReflectionMethod($agenda,'slots');
    $slots=$method->invoke($agenda,$query,$now->format('Y-m-d'),$now->modify('+'.$settings['config']['days'].' days')->format('Y-m-d'));
    $first=$slots[0]??null;
    $result=['target'=>$target,'space'=>$space,'revision'=>$settings['revision'],'defaults_removed'=>$changed,
        'service_default'=>$workflow['scelte']['prestazione_predefinita'],'doctor_default'=>$workflow['scelte']['professionista_predefinito'],
        'active_steps'=>array_column(array_filter($workflow['passi'],static fn($step)=>$step['attivo']),'id'),
        'available_slots'=>count($slots),'first'=>$first?['doctor'=>(int)$first['id_dot'],'date'=>$first['data_slot'],'start'=>$first['ora_inizio'],'end'=>$first['end'],'location'=>$first['id_amb_legacy']]:null];
    $db->transRollback();if($action==='audit')$platform->transRollback();
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);}
