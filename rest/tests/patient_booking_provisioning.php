<?php
// Runs only on the disposable loopback MySQL started by the QA script.
if (($argv[1] ?? '') !== '--isolated-local-mysql') exit(2);
require __DIR__.'/_support/whatsapp_booking_bootstrap.php';
require APPPATH.'Database/Migrations/2026-10-23-100001_CreatePatientBookingSchema.php';

use Config\Database;
use App\Services\{PatientBookingSchema, PatientBookingSettings, PatientBookingMaintenanceRunner, TenantInfrastructureProvisioningService};
use App\Database\Migrations\CreatePatientBookingSchema;

function provisioningConnection(string $name = ''): array {
    return ['DBDriver'=>'MySQLi','hostname'=>'127.0.0.1','port'=>13371,'username'=>'root','password'=>'','database'=>$name,'DBPrefix'=>'','DBDebug'=>true,'charset'=>'utf8mb4','DBCollat'=>'utf8mb4_unicode_ci'];
}
$checks = 0;
function provisionCheck(bool $condition, string $message): void {
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($message);
}
set_exception_handler(static function(Throwable $e) { fwrite(STDERR, $e->getMessage()."\n".$e->getTraceAsString()."\n"); exit(1); });
$name = 'af_web_test_'.bin2hex(random_bytes(8));
$admin = Database::connect(provisioningConnection(), false);
$admin->query('CREATE DATABASE '.$name);
try {
    $db = Database::connect(provisioningConnection($name), false);
    $db->query("CREATE TABLE dap12_agenda_appuntamenti (id_appuntamento INT PRIMARY KEY, stato ENUM('CONFERMATO','ANNULLATO') NOT NULL DEFAULT 'CONFERMATO')");
    $db->query("INSERT INTO dap12_agenda_appuntamenti VALUES (1,'CONFERMATO')");
    $migration = new CreatePatientBookingSchema(Database::forge($db));
    $migration->up();
    provisionCheck(PatientBookingSchema::ready($db), 'A new tenant receives all booking tables');
    $state = $db->query("SHOW COLUMNS FROM dap12_agenda_appuntamenti LIKE 'stato'")->getRowArray();
    provisionCheck(str_contains($state['Type'], "'IN_ATTESA'") && $state['Default'] === 'CONFERMATO', 'Pending state added, existing default preserved');
    $db->query("INSERT INTO patient_booking_contacts(author_id,data_encrypted,vector_id,created_at) VALUES(1,'synthetic','synthetic',NOW())");
    $migration->up(); $migration->down();
    provisionCheck($db->table('patient_booking_contacts')->countAllResults() === 1 && $db->table('dap12_agenda_appuntamenti')->countAllResults() === 1, 'Repeated migration and rollback preserve data');

    $db->query('CREATE TABLE platform_tenants(id_tenant INT PRIMARY KEY,is_active TINYINT NOT NULL)');
    $db->query('INSERT INTO platform_tenants VALUES(1,1),(2,0)');
    PatientBookingSchema::platform($db);
    $store = new PatientBookingSettings($db);
    provisionCheck(!$store->enabled(1) && $db->table('platform_patient_booking_settings')->countAllResults() === 0, 'Preparation never enables a space');
    $called = [];
    $runner = new PatientBookingMaintenanceRunner($db, static function($tenant, $settings, $url) use (&$called) {
        $called[] = (int)$tenant['id_tenant'];
        provisionCheck($settings['enabled'] && $url === 'https://example.test/internal', 'Worker receives enabled settings and internal URL');
        return ['status'=>'processed','expired'=>0,'sent'=>0,'failed'=>0,'skipped'=>0];
    });
    $url = 'https://example.test/internal';
    provisionCheck($runner->runEnabled($url)['processed'] === 0 && !$called, 'No enabled spaces means no work');
    $config = PatientBookingSettings::defaults();
    $config['doctors'] = $config['visits'] = $config['locations'] = [1];
    $config['privacy_url'] = 'https://example.test/privacy';
    $store->save(1, $config, true, 1, 0); $store->save(2, $config, true, 1, 0);
    provisionCheck($runner->runEnabled($url)['processed'] === 1 && $called === [1], 'Inactive spaces excluded even when booking is enabled');
    $db->query('INSERT INTO platform_tenants VALUES(3,1)');
    $called = [];
    provisionCheck($runner->runEnabled($url)['processed'] === 1 && $called === [1], 'Future spaces start disabled');
    $store->save(3, $config, true, 1, 0); $called = [];
    provisionCheck($runner->runEnabled($url)['processed'] === 2 && $called === [1,3], 'New enablement discovered without changing the scheduled job');
    $store->save(1, $config, false, 1, 1); $called = [];
    provisionCheck($runner->runTenant(1, $url)['status'] === 'skipped' && $runner->runEnabled($url)['processed'] === 1 && $called === [3], 'Disablement immediately excludes both execution modes');
    $store->save(1, $config, true, 1, 2);
    $failing = new PatientBookingMaintenanceRunner($db, static function($tenant) {
        if ((int)$tenant['id_tenant'] === 1) throw new RuntimeException('Sensitive exception must not leak');
        return ['status'=>'processed'];
    });
    $result = $failing->runEnabled($url);
    provisionCheck($result['failed'] === 1 && $result['processed'] === 1 && !str_contains(json_encode($result), 'Sensitive'), 'A failed space is reported safely and does not stop other spaces');

    // Exercise the real template cleanup on synthetic data only.
    $service = (new ReflectionClass(TenantInfrastructureProvisioningService::class))->newInstanceWithoutConstructor();
    $reset = new ReflectionMethod($service, 'resetProvisioningTemplateData');
    $reset->invoke($service, $db->connID, $name);
    provisionCheck($db->table('patient_booking_contacts')->countAllResults() === 0 && $db->table('platform_patient_booking_settings')->countAllResults() === 0, 'New tenant template cleanup removes copied booking data and enablements');
    $identity = new \CodeIgniter\Database\MySQLi\Connection(provisioningConnection('ambulatoriofacile_demo'));
    provisionCheck(PatientBookingSchema::isFrozenDemoDatabase($identity), 'Local frozen DB identity remains excluded without connecting to it');
    echo "PASS $checks provisioning checks: migration, disabled defaults, future enablement, isolation, template cleanup.\n";
} finally {
    $admin->query('DROP DATABASE '.$name);
}
