<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/app-lab-common.php';
$lab=fse_lab_config();
if (!is_file($lab['root'].'/seeded.json') || is_file($lab['root'].'/clinical-seeded.json')) throw new RuntimeException('New seeded lab required.');
fse_lab_boot();
$features = new \App\Services\TenantFeatureService();
$features->listPlatformFeatures();
$platform = \Config\Database::connect('platform');
$feature = $platform->table('platform_features')->where('feature_key','clinical_records')->get()->getRowArray();
// Only synthetic studio A receives this module. Studio B exercises denied access.
$platform->table('platform_tenant_features')->insert(['id_tenant'=>42,'id_feature'=>$feature['id_feature'],'is_enabled'=>1]);
foreach([42,43] as $tenantId) {
    $context=(new \App\Services\TsTenantDatabaseContextService())->resolveTenantContext($tenantId);$db=$context['db'];
    (new \App\Services\FseSyntheticAppBoundary())->assertDatabase($tenantId,$db);
    foreach(['nome','cognome','codice_fiscale','email','cellulare','telefono'] as $field) {
        if (!$db->fieldExists($field,'dap02_clients')) $db->query('ALTER TABLE dap02_clients ADD `'.$field.'` TEXT NULL');
    }
    if (!$db->fieldExists('vector_id','dap02_clients')) $db->query('ALTER TABLE dap02_clients ADD vector_id VARBINARY(16)');
    unset($db->dataCache['field_names']);
    (new \App\Libraries\DatabaseConfig())->setEncryptionConfig($db);
    $db->query("INSERT INTO dap02_clients (id_client,nome,cognome,codice_fiscale,vector_id) VALUES (100,HEX(AES_ENCRYPT('Persona',@key_str,@init_vector)),HEX(AES_ENCRYPT('Sintetica',@key_str,@init_vector)),HEX(AES_ENCRYPT('RSSMRA80A01H501U',@key_str,@init_vector)),@init_vector)");
    $db->table('dap01_users')->where('id_user',1)->update(['username'=>'VRDLGI70A01H501X']);
    $db->query('CREATE TABLE dap09_client_doctor (id_client INT, id_dot INT)');
    $db->table('dap09_client_doctor')->insert(['id_client'=>100,'id_dot'=>1]);
    $db->query('CREATE TABLE dap11_agenda_slot (id_slot INT PRIMARY KEY,data_slot DATE,ora_inizio TIME,ora_fine TIME)');
    $db->query('CREATE TABLE dap12_agenda_appuntamenti (id_appuntamento INT PRIMARY KEY,id_client INT,id_dot INT,id_slot INT,tipo_visita_label VARCHAR(100),stato VARCHAR(30),created_at DATETIME)');
    $db->table('dap11_agenda_slot')->insert(['id_slot'=>1,'data_slot'=>'2026-09-12','ora_inizio'=>'10:00','ora_fine'=>'10:30']);
    $db->table('dap12_agenda_appuntamenti')->insert(['id_appuntamento'=>1,'id_client'=>100,'id_dot'=>1,'id_slot'=>1,'tipo_visita_label'=>'Visita sintetica','stato'=>'CONFERMATO','created_at'=>date('Y-m-d H:i:s')]);
    require_once APPPATH.'Database/Migrations/2026-09-12-160001_CreateClinicalRecords.php';
    (new \App\Database\Migrations\CreateClinicalRecords(\Config\Database::forge($db)))->up();
}
file_put_contents($lab['root'].'/clinical-seeded.json',json_encode(['mode'=>'SYNTHETIC_CLINICAL_HTTP','tenant_ids'=>[42,43],'patient_id'=>100]));
echo "Synthetic clinical lab ready.\n";
