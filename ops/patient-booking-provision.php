<?php
declare(strict_types=1);

// Run in the deployed container, using its own configuration. No patient data is printed.
// audit is read-only; install calls the existing, idempotent module installer only
// for active spaces whose platform booking setting is already enabled.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
$mode = $argv[1] ?? 'audit';
if (!in_array($mode, ['audit', 'install'], true)) exit(2);
define('FCPATH', '/var/www/html/rest/public/');
require FCPATH . '../app/Config/Paths.php';
require FCPATH . '../system/Boot.php';

class PatientBookingProvisionBoot extends \CodeIgniter\Boot
{
    public static function start(\Config\Paths $paths): void
    {
        static::definePathConstants($paths);
        static::loadConstants();
        static::checkMissingExtensions();
        static::loadDotEnv($paths);
        static::defineEnvironment();
        static::loadEnvironmentBootstrap($paths);
        static::loadCommonFunctions();
        static::loadAutoloader();
        static::setExceptionHandler();
        static::initializeKint();
        static::autoloadHelpers();
        static::initializeCodeIgniter();
    }
}

try {
    chdir(FCPATH);
    PatientBookingProvisionBoot::start(new \Config\Paths());
    $platform = \Config\Database::connect('platform');
    if ($platform->database === 'ambulatoriofacile_demo') throw new RuntimeException('Frozen demo excluded');
    if ($mode === 'install') \App\Services\PatientBookingSchema::platform($platform);
    $ready = $platform->tableExists('platform_patient_booking_settings');
    $rows = $ready ? $platform->table('platform_patient_booking_settings s')
        ->select('s.id_tenant,s.revision,t.tenant_name,t.tenant_key,t.is_active')
        ->join('platform_tenants t', 't.id_tenant=s.id_tenant')
        ->where('s.enabled', 1)->orderBy('s.id_tenant')->get()->getResultArray() : [];
    $report = ['mode'=>$mode,'base_url'=>config('App')->baseURL,'platform_ready'=>$ready,
        'platform_fingerprint'=>hash('sha256', $platform->hostname.':'.$platform->port.'/'.$platform->database),
        'spaces'=>[]];
    $failed = false;
    foreach ($rows as $row) {
        $result = ['id'=>(int)$row['id_tenant'],'name'=>$row['tenant_name'],'key'=>$row['tenant_key'],
            'active'=>(bool)$row['is_active'],'revision'=>(int)$row['revision']];
        if (!$result['active']) { $result['skipped']='inactive'; $report['spaces'][]=$result; continue; }
        try {
            $tenant = (new \App\Services\TenantCatalogService())->getTenantById($result['id']);
            $connector = new \App\Services\TenantDatabaseConnector();
            $connection = $connector->resolveDatabaseSettings($tenant);
            if ($connection['db_name'] === 'ambulatoriofacile_demo') {
                $result['skipped']='frozen_demo'; $report['spaces'][]=$result; continue;
            }
            $db = $connector->connect($tenant);
            $result['database_fingerprint']=hash('sha256', $db->hostname.':'.$db->port.'/'.$db->database);
            $settings = (new \App\Services\PatientBookingSettings())->get($result['id']);
            if (!$settings['enabled']) throw new RuntimeException('Space disabled during provisioning');
            if ($mode === 'install') \App\Services\PatientBookingSchema::tenant($db);
            $result['schema_ready']=\App\Services\PatientBookingSchema::ready($db);
            $result['agenda_ready']=(new \App\Services\AgendaVisitTypeSchemaService($db))->isReady();
            $state = $db->query("SHOW COLUMNS FROM dap12_agenda_appuntamenti LIKE 'stato'")->getRowArray();
            $result['pending_state_ready']=$state && (!str_starts_with(strtolower($state['Type']), 'enum(') || str_contains($state['Type'], "'IN_ATTESA'"));
            if ($result['schema_ready']) {
                $catalog=(new \App\Services\PatientBookingAgenda($db,$settings,0))->catalog();
                $result['catalog_counts']=array_map('count', array_intersect_key($catalog,array_flip(['professionisti','prestazioni','sedi'])));
                $result['outbox_states']=$db->table('patient_booking_outbox')->select('state,COUNT(*) AS total')->groupBy('state')->get()->getResultArray();
                $result['bookings_without_metadata']=(int)$db->query('SELECT COUNT(*) AS total FROM patient_online_bookings b LEFT JOIN patient_booking_details d ON d.booking_ref=b.booking_ref WHERE d.booking_ref IS NULL')->getRowArray()['total'];
            }
            if ($mode==='install' && (!$result['schema_ready'] || !$result['agenda_ready'] || !$result['pending_state_ready'])) $failed=true;
        } catch (\Throwable $e) {
            // Database connection errors can contain credentials: never print their message.
            $result['error']=get_class($e); $failed=true;
        }
        $report['spaces'][]=$result;
    }
    echo 'PATIENT_BOOKING_REPORT='.json_encode($report, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit($failed ? 1 : 0);
} catch (\Throwable $e) {
    echo 'PATIENT_BOOKING_ERROR='.get_class($e).PHP_EOL;
    exit(1);
}
