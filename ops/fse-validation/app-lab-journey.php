<?php
// Extends only the marked synthetic instance; no live tenant/database fallback.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/app-lab-common.php';$lab=fse_lab_config();fse_lab_boot();
$action=$argv[1] ?? 'seed';
if(!is_file($lab['root'].'/clinical-seeded.json') || !is_file($lab['root'].'/billing-seeded.json')) throw new RuntimeException('Clinical and billing synthetic seeds required.');
$contexts=[];
foreach([42,43] as $tenant){$contexts[$tenant]=(new \App\Services\TsTenantDatabaseContextService())->resolveTenantContext($tenant);(new \App\Services\FseSyntheticAppBoundary())->assertDatabase($tenant,$contexts[$tenant]['db']);}
if($action==='patient-identity') {
    $patient=(new \App\Services\TenantPatientLookupService())->getPatientByIdForTenant(42,101);
    echo json_encode($patient,JSON_THROW_ON_ERROR);exit;
}
if($action==='prepare-ts') {
    if(!is_file($lab['root'].'/journey-seeded.json')) throw new RuntimeException('Journey seed required.');
    $id=(int)($argv[2] ?? 0);$doc=$contexts[42]['db']->table('billing_documents')->where('id_billing_document',$id)->get()->getRowArray();
    if(!$doc || (int)$doc['id_client']!==101 || !str_starts_with($doc['document_number'],'JOURNEY-')) throw new RuntimeException('Synthetic journey invoice required.');
    $platform=\Config\Database::connect('platform');
    if($platform->database!=='fselab_platform' || $platform->hostname!=='127.0.0.1' || (int)$platform->port!==33079 || realpath($platform->query('SELECT @@datadir AS path')->getRowArray()['path'])!==realpath($lab['root'].'/mysql')) throw new RuntimeException('Platform lab boundary.');
    $profile=$platform->table('platform_tenant_ts_profiles')->where('id_tenant',42)->get()->getRowArray();
    if(!$profile) {
        $secret=new \App\Services\TsSecretsService();
        $platform->table('platform_tenant_ts_profiles')->insert(['id_tenant'=>42,'profile_name'=>'SYNTHETIC JOURNEY - NO DELIVERY','sender_type'=>'medico','owner_piva'=>'12345678903','owner_cf_enc'=>$secret->encrypt('VRDLGU70A01H501O'),'auth_username'=>'SYNTHETIC-NOT-A-CREDENTIAL','auth_password_enc'=>$secret->encrypt('SYNTHETIC-NOT-A-CREDENTIAL'),'pincode_enc'=>$secret->encrypt('SYNTHETIC'),'environment'=>'test','is_default'=>1,'is_enabled'=>1]);
    } elseif($profile['profile_name']!=='SYNTHETIC JOURNEY - NO DELIVERY' || $profile['environment']!=='test') throw new RuntimeException('Unexpected profile.');
    // Preparation only. All TS endpoints also resolve to loopback port 1 in this bounded lab.
    $result=(new \App\Services\BillingTsBridgeService())->prepareBillingDocumentForTs(42,$id,1);
    echo json_encode(['status'=>$result['status'],'validation'=>$result['validation'],'ts_document_id'=>$result['ts_document']['id_ts_document'] ?? null,'external_delivery'=>false],JSON_THROW_ON_ERROR);exit;
}
if($action==='snapshot') {
    $result=[];foreach($contexts as $tenant=>$c){$db=$c['db'];$result[$tenant]=[];
        foreach(['dap02_clients','dap09_client_doctor','dap12_agenda_appuntamenti','clinical_entries','clinical_consents','billing_documents','ts_documents'] as $table) $result[$tenant][$table]=$db->tableExists($table) ? $db->table($table)->get()->getResultArray() : [];
    }array_walk_recursive($result,static function(&$value){if(is_string($value) && !mb_check_encoding($value,'UTF-8'))$value='base64:'.base64_encode($value);});echo json_encode($result,JSON_THROW_ON_ERROR);exit;
}
if(in_array($action,['seed','staff'],true)) {
    foreach($contexts as $context) {
        $db=$context['db'];
        foreach(['luogo','titolare','sostituto','is_dot','legacy_id_ope','legacy_id_dot','legacy_dot_tipo_id','f_dom'] as $field) if(!$db->fieldExists($field,'dap03_personale')) $db->query("ALTER TABLE dap03_personale ADD `$field` INT DEFAULT 0");
        $db->table('dap03_personale')->where('id_personale',1)->update(['legacy_id_ope'=>1,'legacy_id_dot'=>11,'legacy_dot_tipo_id'=>2,'is_dot'=>1]);
        if($db->fieldExists('id_dot','dap11_agenda_slot'))$db->table('dap11_agenda_slot')->whereIn('id_slot',[201,202,203])->update(['id_dot'=>11]);
    }
    if($action==='staff'){echo "Synthetic legacy/staff identities prepared.\n";exit;}
}
if(in_array($action,['seed','features'],true)) {
    $platform=\Config\Database::connect('platform');$feature=$platform->table('platform_features')->where('feature_key','agenda')->get()->getRowArray();
    foreach([42,43] as $tenant) if(!$platform->table('platform_tenant_features')->where('id_tenant',$tenant)->where('id_feature',$feature['id_feature'])->countAllResults()) $platform->table('platform_tenant_features')->insert(['id_tenant'=>$tenant,'id_feature'=>$feature['id_feature'],'is_enabled'=>1]);
    if($action==='features'){echo "Synthetic agenda feature granted.\n";exit;}
}
if($action!=='seed' || is_file($lab['root'].'/journey-seeded.json')) throw new RuntimeException('New journey seed required.');
foreach($contexts as $tenant=>$context) {
    $db=$context['db'];
    $add=static function($table,$fields) use($db){foreach($fields as $name=>$type)if(!$db->fieldExists($name,$table))$db->query("ALTER TABLE `$table` ADD `$name` $type");unset($db->dataCache['field_names']);};
    $fields=array_fill_keys(['indirizzo','citta','provincia','cap','data_nascita','comune_nascita','provincia_nascita','residenza_indirizzo','residenza_comune','residenza_cap','residenza_provincia','paz_spec'],'TEXT NULL');
    foreach((new ReflectionClass(\App\Models\PazientiModel::class))->getConstant('EXTRA_PATIENT_FIELDS') as $name=>$definition)$fields[$name]=$definition['encrypted'] ? 'TEXT NULL' : 'INT DEFAULT 1';
    $add('dap02_clients',$fields+['id_personale'=>'INT NULL','legacy_id_paziente'=>'INT NULL','bloccato'=>'INT DEFAULT 0','avviso_mail'=>'INT DEFAULT 0','appointment_reminder_sms_enabled'=>'INT DEFAULT 0']);
    $add('dap09_client_doctor',['id_users_doctor'=>'INT AUTO_INCREMENT PRIMARY KEY']);
    $add('dap03_personale',['medico_famiglia'=>'INT DEFAULT 0','visibile_agenda'=>'INT DEFAULT 1','is_active'=>'INT DEFAULT 1']);
    $add('dap11_agenda_slot',['id_dot'=>'INT DEFAULT 1','id_stanza'=>'INT DEFAULT 1','stato'=>"VARCHAR(20) DEFAULT 'LIBERO'",'updated_at'=>'DATETIME NULL']);
    $add('dap12_agenda_appuntamenti',array_fill_keys(['cognome','nome','telefono','cellulare','email','note','motivo_visita','indirizzo_visita','comune_visita'],'TEXT NULL')+['id_paziente'=>'INT NULL','updated_at'=>'DATETIME NULL','updated_by'=>'INT NULL','created_by'=>'INT NULL']);
    $db->query('ALTER TABLE dap12_agenda_appuntamenti MODIFY id_appuntamento INT AUTO_INCREMENT');
    $db->query('CREATE TABLE dap14_agenda_lock (id_lock INT AUTO_INCREMENT PRIMARY KEY,id_slot INT,id_ope INT,token_lock VARCHAR(80),stato VARCHAR(20),locked_at DATETIME,expires_at DATETIME,updated_at DATETIME)');
    $db->query('CREATE TABLE dap21_agenda_giorni_bloccati (id_dot INT,data_agenda DATE)');
    $db->query('CREATE TABLE dap17_agenda_menu (id_menu INT PRIMARY KEY,id_menu_padre INT,codice VARCHAR(30),tipo_voce VARCHAR(20),label_menu VARCHAR(100),icona VARCHAR(50),rotta VARCHAR(150),ordinamento INT,attivo INT)');
    $db->query('CREATE TABLE dap18_agenda_menu_permessi (id_perm INT PRIMARY KEY,id_menu INT,id_ope INT,id_ruo INT,visibile INT)');
    foreach([201=>'09:00',202=>'09:30',203=>'10:00'] as $id=>$start) $db->table('dap11_agenda_slot')->insert(['id_slot'=>$id,'id_dot'=>11,'id_stanza'=>1,'data_slot'=>'2026-09-14','ora_inizio'=>$start,'ora_fine'=>date('H:i',strtotime($start)+1800),'stato'=>'LIBERO']);
}
file_put_contents($lab['root'].'/journey-seeded.json',json_encode(['mode'=>'SYNTHETIC_PATIENT_JOURNEY','external_delivery'=>false,'seeded_at'=>gmdate('c')]));
echo "Synthetic patient journey schema ready. External services remain disabled.\n";
