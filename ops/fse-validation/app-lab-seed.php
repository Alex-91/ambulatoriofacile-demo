<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/app-lab-common.php';
$lab = fse_lab_config();
if (is_file($lab['root'].'/seeded.json')) throw new RuntimeException('Lab already seeded: never overwrite it.');
$linux = ($lab['runtime'] ?? '') === 'linux-container';
if ($linux && (PHP_OS_FAMILY !== 'Linux' || !is_file('/opt/fse/linux-lab-image'))) throw new RuntimeException('Linux lab image required.');
$mysqli = new mysqli('127.0.0.1','root',$linux ? $lab['password'] : '','',33079);
$actual = $mysqli->query('SELECT @@datadir AS path')->fetch_assoc()['path'];
if (realpath($actual) !== realpath($lab['root'].'/mysql')) throw new RuntimeException('Wrong MySQL instance.');
foreach (['fselab_platform','fselab_a','fselab_b'] as $name) $mysqli->query('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
if (!$linux) $mysqli->query("ALTER USER 'root'@'localhost' IDENTIFIED BY '".$mysqli->real_escape_string($lab['password'])."'");
$mysqli->close();
fse_lab_boot();
$platform = \Config\Database::connect('platform');
function fse_lab_migrate($db, array $migrations): void {
    foreach ($migrations as $file=>$class) { require_once APPPATH.'Database/Migrations/'.$file.'.php'; $name='App\\Database\\Migrations\\'.$class; (new $name(\Config\Database::forge($db)))->up(); }
}
fse_lab_migrate($platform, [
    '2026-06-19-000001_CreatePlatformMultiTenantFoundation'=>'CreatePlatformMultiTenantFoundation',
    '2026-06-20-000001_AddTenantManagedFeatureControls'=>'AddTenantManagedFeatureControls',
    '2026-06-20-000002_AddPlatformAdminFlagToPlatformUsers'=>'AddPlatformAdminFlagToPlatformUsers',
    '2026-06-23-000003_AddAppAdminFlagToPlatformUserTenants'=>'AddAppAdminFlagToPlatformUserTenants',
    '2026-09-04-010001_AddFse2Feature'=>'AddFse2Feature',
    '2026-09-04-010002_CreatePlatformTenantFseProfiles'=>'CreatePlatformTenantFseProfiles',
]);
$feature = $platform->table('platform_features')->where('feature_key','fse2')->get()->getRowArray();
$profiles = new \App\Services\FseProfileService();
foreach ([42=>'a',43=>'b'] as $tenantId=>$suffix) {
    $platform->table('platform_tenants')->insert(['id_tenant'=>$tenantId,'tenant_key'=>'fse_lab_'.$suffix,'tenant_name'=>'FSE Studio Fittizio '.strtoupper($suffix),
        'status'=>'active','onboarding_status'=>'ready','is_active'=>1,'db_host'=>'127.0.0.1','db_port'=>33079,'db_name'=>'fselab_'.$suffix,
        'db_username'=>'root','db_password_ref'=>'FSE_LAB_DB_PASSWORD','db_driver'=>'MySQLi','db_prefix'=>'','storage_key'=>'fse_lab_'.$suffix]);
    $platform->table('platform_tenant_features')->insert(['id_tenant'=>$tenantId,'id_feature'=>$feature['id_feature'],'is_enabled'=>1]);
    $platform->table('platform_users')->insert(['id_platform_user'=>$tenantId,'email'=>'studio-'.$suffix.'@fse.invalid','password_hash'=>password_hash($lab['login_password'],PASSWORD_DEFAULT),'status'=>'active','must_reset_password'=>0]);
    $platform->table('platform_user_tenants')->insert(['id_platform_user'=>$tenantId,'id_tenant'=>$tenantId,'tenant_role'=>'tenant_master','app_user_id'=>1,'is_default'=>1]);
    $db=\Config\Database::connect(array_replace((new \Config\Database())->default,['database'=>'fselab_'.$suffix]),false);
    $db->query('CREATE TABLE dap01_users (id_user INT PRIMARY KEY, username VARCHAR(190), password TEXT, tipo_user INT, datascadenza DATE)');
    $db->query("INSERT INTO dap01_users VALUES (1,'lab-admin','',1,'2030-01-01')");
    $db->query('CREATE TABLE dap03_personale (id_personale INT PRIMARY KEY, id_user INT, tipo INT, nome TEXT, cognome TEXT, cellulare TEXT, email TEXT, qualifica TEXT, vector_id VARBINARY(16))');
    (new \App\Libraries\DatabaseConfig())->setEncryptionConfig($db);
    $db->query("INSERT INTO dap03_personale VALUES (1,1,1,HEX(AES_ENCRYPT('Admin',@key_str,@init_vector)),HEX(AES_ENCRYPT('Fittizio',@key_str,@init_vector)),NULL,NULL,NULL,@init_vector)");
    $db->query('CREATE TABLE dap06_mnu (id_mnu INT AUTO_INCREMENT PRIMARY KEY, titolo_menu VARCHAR(190), class VARCHAR(80), class_icon VARCHAR(80), admin INT, link VARCHAR(190), link2 VARCHAR(190), ordinamento INT)');
    $db->query('CREATE TABLE dap02_clients (id_client INT AUTO_INCREMENT PRIMARY KEY, id_user INT NULL)');
    $schema = (new \App\Services\FseTenantSchemaService())->ensureTenantSchemaReady($tenantId,true);
    if (!$schema['ready']) throw new RuntimeException('Lab schema setup failed: '.$schema['message']);
    $synthetic = require SUPPORTPATH.'fse_synthetic.php';
    $payload = $synthetic + ['profile_name'=>'Sede '.strtoupper($suffix).' — privato','access_mode'=>'toscana_privati','environment'=>'test','is_enabled'=>0,
        'site_code'=>'IMPIANTO-TEST-'.strtoupper($suffix),'care_regime'=>'NOSSN','region_code'=>'090','organization_id'=>'TEST','organization_name'=>'Organizzazione fittizia',
        'submission_oid_root'=>'1.2.3.6','repository_id'=>'1.2.3.7','locality'=>'TEST','organizational_setting'=>'TEST','jwt_audience'=>'urn:synthetic:cart'];
    $profiles->saveProfile($tenantId,$payload,0,$tenantId,true);
}
file_put_contents($lab['root'].'/seeded.json',json_encode(['mode'=>'FSE_SYNTHETIC_APP_LAB','seeded_at'=>gmdate('c'),'tenants'=>[42,43]],JSON_PRETTY_PRINT));
echo "Synthetic MySQL lab seeded. Two separate tenant DBs. No external Gateway enabled.\n";
