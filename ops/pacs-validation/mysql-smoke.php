<?php
if (PHP_SAPI!=='cli' || PHP_OS_FAMILY!=='Linux') throw new RuntimeException('Synthetic Linux CLI only.');
$pacsSmokePassed=false;
register_shutdown_function(static function () { if (empty($GLOBALS['pacsSmokePassed'])) exit(1); });
require __DIR__.'/bootstrap.php';
require __DIR__.'/../../rest/tests/pacs/PacsTestSupport.php';
use App\Services\{TenantCatalogService,TenantDatabaseConnector,TenantPatientLookupService,ClinicalRecordService};
use App\Services\Pacs\{PacsService,PacsProfiles,PacsOrderService,PacsException};
use Tests\Pacs\{MutablePacsGate,MemoryPacsTransport};
$lab=getenv('PACS_MYSQL_LAB');
if (!$lab || !is_file($lab.'/manifest.json')) throw new RuntimeException('Explicit synthetic lab required.');
$m=json_decode(file_get_contents($lab.'/manifest.json'),true,32,JSON_THROW_ON_ERROR);
if (($m['marker'] ?? '')!=='af-pacs-mysql-synthetic-v1' || ($m['database'] ?? '')!=='af_pacs_synthetic') throw new RuntimeException('Invalid synthetic boundary.');
config(\App\Config\Crypto::class)->keyHex=$m['crypto_key'];
putenv('DB_ENCRYPTION_KEY='.$m['crypto_key']);
$db=\Config\Database::connect(['hostname'=>'127.0.0.1','port'=>3306,'username'=>'root','password'=>$m['password'],
    'database'=>'af_pacs_synthetic','DBDriver'=>'MySQLi','DBPrefix'=>'','DBDebug'=>true,'charset'=>'utf8mb4','DBCollat'=>'utf8mb4_unicode_ci','strictOn'=>true],false);
$identity=$db->query('SELECT @@server_id AS sid, DATABASE() AS db')->getRowArray();
if ((int)$identity['sid']!==(int)$m['server_id'] || $identity['db']!=='af_pacs_synthetic') throw new RuntimeException('Unexpected database server.');
$catalog=new class extends TenantCatalogService {
    public function __construct() {}
    public function getTenantById(int $id): ?array { return in_array($id,[42,43],true) ? ['id_tenant'=>$id] : null; }
};
$connector=new class($db) extends TenantDatabaseConnector {
    public function __construct(private \CodeIgniter\Database\BaseConnection $synthetic) {}
    public function connect(array $tenant): \CodeIgniter\Database\BaseConnection { return $this->synthetic; }
};
$patients=new TenantPatientLookupService($catalog,$connector);
$gate=new MutablePacsGate();
$pacs=new PacsService($db,42,1,new PacsProfiles(['tenants'=>['42'=>[MemoryPacsTransport::profile()]]]),$gate,$transport=new MemoryPacsTransport());
$orders=new PacsOrderService($db,42,1,$pacs,$gate,$patients);
function check(bool $condition,string $label): void { if (!$condition) throw new RuntimeException('Failed: '.$label); }
function denied(callable $action): void {
    global $db;
    try { $action(); } catch (RuntimeException) { $db->resetTransStatus(); return; }
    throw new RuntimeException('Expected operation rejection');
}
if (($argv[1] ?? '')==='race') {
    $barrier=$argv[3];
    if (!str_starts_with($barrier,'/tmp/af-pacs-mysql-')) throw new RuntimeException('Invalid barrier.');
    $deadline=microtime(true)+8;
    while (!is_file($barrier) && microtime(true)<$deadline) usleep(20000);
    if (!is_file($barrier)) throw new RuntimeException('Barrier timeout.');
    try {
        if ($argv[4]==='export') $orders->export(100,$argv[2],2,'dicom');
        elseif ($argv[4]==='accept') $orders->advance(100,$argv[2],2,'accepted');
        elseif ($argv[4]==='report') $orders->saveReport(100,$argv[2],5,['body'=>'SYNTHETIC REPORT','occurred_at'=>'2026-09-20T11:30']);
        else $orders->cancel(100,$argv[2],2);
        echo json_encode(['accepted'=>true]);
    } catch (PacsException) { echo json_encode(['accepted'=>false]); }
    $pacsSmokePassed=true; exit;
}
check($db->listTables()===[],'fresh synthetic database');
foreach ([
    'dap01_users'=>'id_user INT PRIMARY KEY,is_active INT,username VARCHAR(100)',
    'dap03_personale'=>'id_personale INT PRIMARY KEY,id_user INT,tipo INT,is_active INT',
    'dap02_clients'=>'id_client INT PRIMARY KEY,id_user INT,nome TEXT,cognome TEXT,codice_fiscale TEXT,email TEXT,cellulare TEXT,telefono TEXT,data_nascita TEXT,vector_id VARBINARY(16)',
    'dap09_client_doctor'=>'id_client INT,id_dot INT',
    'dap14_seg_dot'=>'id_seg INT,id_dot INT',
    'dap15_inf_dot'=>'id_inf INT,id_dot INT',
    'dap11_agenda_slot'=>'id_slot INT PRIMARY KEY,data_slot DATE,ora_inizio TIME',
    'dap12_agenda_appuntamenti'=>'id_appuntamento INT PRIMARY KEY,id_client INT,id_dot INT,id_slot INT,id_tipo_visita INT,tipo_visita_label VARCHAR(100),stato VARCHAR(30)',
    'clinical_consents'=>'id INT PRIMARY KEY,id_client INT,kind VARCHAR(30),decision VARCHAR(30),evidence_object_id VARCHAR(50)',
] as $table=>$fields) $db->query('CREATE TABLE '.$table.' ('.$fields.') ENGINE=InnoDB');
$db->table('dap01_users')->insert(['id_user'=>1,'is_active'=>1,'username'=>'SYNTHETIC']);
$db->table('dap03_personale')->insert(['id_personale'=>10,'id_user'=>1,'tipo'=>1,'is_active'=>1]);
$db->table('dap09_client_doctor')->insert(['id_client'=>100,'id_dot'=>10]);
(new \App\Libraries\DatabaseConfig())->setEncryptionConfig($db);
$db->query("INSERT INTO dap02_clients (id_client,id_user,nome,cognome,data_nascita,vector_id) VALUES (100,1,HEX(AES_ENCRYPT('Paziente',@key_str,@init_vector)),HEX(AES_ENCRYPT('Sintetico',@key_str,@init_vector)),HEX(AES_ENCRYPT('1980-01-01',@key_str,@init_vector)),@init_vector)");
require_once APPPATH.'Database/Migrations/2026-09-13-100001_CreatePacsIntegration.php';
require_once APPPATH.'Database/Migrations/2026-09-13-110001_CreatePacsOrders.php';
require_once APPPATH.'Database/Migrations/2026-09-13-120001_AddPacsOrderWorkflow.php';
foreach ([1,2] as $iteration) {
    (new \App\Database\Migrations\CreatePacsIntegration(\Config\Database::forge($db)))->up();
    (new \App\Database\Migrations\CreatePacsOrders(\Config\Database::forge($db)))->up();
    (new \App\Database\Migrations\AddPacsOrderWorkflow(\Config\Database::forge($db)))->up();
}
require_once APPPATH.'Database/Migrations/2026-09-12-160001_CreateClinicalRecords.php';
(new \App\Database\Migrations\CreateClinicalRecords(\Config\Database::forge($db)))->up();
check($db->fieldExists('workflow_stage','pacs_orders'),'workflow migration');
$patient=$patients->getPatientByIdForTenant(42,100);
check($patient['patient_birth_date']==='1980-01-01' && $patient['patient_first_name']==='Paziente','encrypted canonical lookup');
$binding=$pacs->bind(100,'cloud','P-100','TEST-HOSPITAL',0,true);
$input=['description'=>'TC sintetica','procedure_code'=>'LAB-CT','coding_scheme'=>'99AFLAB','modality'=>'CT','station_ae'=>'FINDSCU','scheduled_at'=>'2026-09-20T10:30'];
$key=bin2hex(random_bytes(16)); $id=$orders->create(100,$binding,$input,$key);
check($orders->create(100,$binding,$input,$key)===$id,'idempotent creation');
$orders->approve(100,$id,1,true);
$file=$orders->export(100,$id,2,'dicom');
check(substr($file['bytes'],128,4)==='DICM','export');
denied(fn()=>$orders->cancel(100,$id,2));
$orders->cancel(100,$id,3);
denied(fn()=>$orders->export(100,$id,4,'dicom'));
$id2=$orders->create(100,$binding,$input,bin2hex(random_bytes(16))); $orders->approve(100,$id2,1,true);
$db->query("CREATE TRIGGER reject_pacs_audit BEFORE INSERT ON pacs_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit failure'");
denied(fn()=>$orders->export(100,$id2,2,'dicom'));
$row=$db->table('pacs_orders')->where('id',$id2)->get()->getRowArray();
check((int)$row['revision']===2 && $row['last_exported_at']===null,'audit rollback');
$db->query('DROP TRIGGER reject_pacs_audit');
function race(string $raceId,array $actions): void {
$barrier='/tmp/af-pacs-mysql-'.bin2hex(random_bytes(12)); $workers=[];
foreach ($actions as $action) {
    $pipes=[]; $proc=proc_open([PHP_BINARY,__FILE__,'race',$raceId,$barrier,$action],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    check(is_resource($proc),'worker start'); fclose($pipes[0]); $workers[]=[$proc,$pipes];
}
file_put_contents($barrier,'go'); $accepted=0;
foreach ($workers as [$proc,$pipes]) {
    $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($proc)===0,'worker exit: '.$error);
    $accepted+=(int)json_decode($output,true,8,JSON_THROW_ON_ERROR)['accepted'];
}
unlink($barrier);
check($accepted===1,'one winner among four concurrent sessions');
}
race($id2,['export','cancel','export','cancel']);
check((int)$db->table('pacs_orders')->where('id',$id2)->get()->getRowArray()['revision']===3,'one revision increment');
$db->table('dap11_agenda_slot')->insert(['id_slot'=>10,'data_slot'=>'2026-09-20','ora_inizio'=>'10:30:00']);
$db->table('dap12_agenda_appuntamenti')->insert(['id_appuntamento'=>10,'id_client'=>100,'id_dot'=>10,'id_slot'=>10,'id_tipo_visita'=>1,'tipo_visita_label'=>'SYNTHETIC FROM AGENDA','stato'=>'CONFERMATO']);
$id3=$orders->create(100,$binding,['appointment_id'=>10]+$input,bin2hex(random_bytes(16)));
check($orders->read(100,$id3)['payload']['description']==='SYNTHETIC FROM AGENDA','server-side appointment prefill');
$orders->approve(100,$id3,1,true);
race($id3,['accept','accept','accept','accept']);
check((int)$orders->read(100,$id3)['revision']===3,'one reception transition');
$orders->advance(100,$id3,3,'in_progress'); $orders->advance(100,$id3,4,'performed');
race($id3,['report','report','report','report']);
check($db->table('clinical_entries')->countAllResults()===1,'one atomic report among concurrent sessions');
$entry=$orders->report(100,$id3);
check((int)$entry['appointment_id']===10 && $entry['state']==='draft','linked chart report');
$db->query("CREATE TRIGGER reject_report_audit BEFORE INSERT ON pacs_audit FOR EACH ROW BEGIN IF NEW.event='pacs_report_saved' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic report audit failure'; END IF; END");
denied(fn()=>$orders->saveReport(100,$id3,6,['entry_revision'=>1,'body'=>'MUST ROLLBACK','occurred_at'=>'2026-09-20T11:30']));
check($orders->report(100,$id3)['content']['body']==='SYNTHETIC REPORT','nested chart/order rollback');
check((int)$orders->read(100,$id3)['revision']===6,'report revision rollback');
$db->query('DROP TRIGGER reject_report_audit');
$study=MemoryPacsTransport::study('P-100','TEST-HOSPITAL',$orders->read(100,$id3)['study_uid']);
$study['00080050']['Value'][0]=$orders->read(100,$id3)['accession'];
$transport->replies=[MemoryPacsTransport::json([$study])];
$link=$orders->linkImages(100,$id3,6);
check($orders->read(100,$id3)['study_link_id']===$link,'atomic verified image linkage');
$db->table('dap11_agenda_slot')->where('id_slot',10)->update(['ora_inizio'=>'12:00:00']);
denied(fn()=>$orders->saveReport(100,$id3,7,['entry_revision'=>1,'body'=>'STALE APPOINTMENT','occurred_at'=>'2026-09-20T11:30']));
check($orders->report(100,$id3)['content']['body']==='SYNTHETIC REPORT','rescheduled appointment blocks report mutation');
$gate->enabled=false; denied(fn()=>$orders->listing(100)); $gate->enabled=true;
$other=new PacsOrderService($db,43,1,null,$gate,$patients);
denied(fn()=>$other->read(100,$id));
$pacsSmokePassed=true;
echo json_encode(['result'=>'passed','mysql'=>$db->query('SELECT VERSION() AS v')->getRowArray()['v'],
    'checks'=>['idempotent migrations','encrypted canonical DOB','create/confirm/export/cancel','stale revision rejection',
    'audit failure rollback','four concurrent export/cancel sessions: one winner','feature revocation','tenant boundary','appointment prefill and reschedule checks','four concurrent acceptance sessions: one winner','four concurrent report sessions: one entry','nested chart/order audit rollback','atomic verified image linkage']])."\n";
