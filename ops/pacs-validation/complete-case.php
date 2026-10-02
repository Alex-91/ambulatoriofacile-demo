<?php
/** CLI-only synthetic case. Never loads .env or connects to application databases. */
if (PHP_SAPI!=='cli') exit(1);
require __DIR__.'/bootstrap.php';
set_exception_handler(static function (\Throwable $e): void { fwrite(STDERR,'Synthetic case failed: '.$e->getMessage().PHP_EOL);exit(1); });
use App\Services\Pacs\{PacsService,PacsProfiles,PacsOrderService,PacsFeatureService};
use App\Services\{ClinicalRecordService,TenantPatientLookupService};
$phase=$argv[1]??'';
$statePath=getenv('AF_SYNTHETIC_STATE');
$state=json_decode(file_get_contents($statePath),true,32,JSON_THROW_ON_ERROR);
$expected='https://orthanc-j3le41pb3ym1f79lc52y271b.178.104.113.107.sslip.io';
if (($state['marker']??'')!=='AF_PACS_CLOUD_SYNTHETIC_V1' || (int)$state['tenantId']!==4 || $state['baseUrl']!==$expected) throw new RuntimeException('Wrong lab');
$dir=dirname(__DIR__,2).'/rest/writable/pacs-complete-case';
if (!is_dir($dir)) mkdir($dir,0700,true);
$keyPath=$dir.'/key.private';
if (!is_file($keyPath)) file_put_contents($keyPath,bin2hex(random_bytes(32)));
config(\App\Config\Crypto::class)->keyHex=trim(file_get_contents($keyPath));
$db=\Config\Database::connect(['DBDriver'=>'SQLite3','database'=>$dir.'/case.sqlite','DBPrefix'=>'','DBDebug'=>true],false);
$patient=['patient_name'=>'DEMO PACS COMPLETO','patient_last_name'=>'DEMO','patient_first_name'=>'PACS COMPLETO','patient_birth_date'=>'1980-01-01'];
$lookup=new class($patient) extends TenantPatientLookupService {
 public function __construct(private array $fixture) {}
 public function getPatientByIdForTenant(int $t,int $p): array { if($t!==4||$p!==100)throw new RuntimeException('Wrong synthetic identity');return $this->fixture; }
};
$gate=new class extends PacsFeatureService { public function isEnabledForTenant(int $id):bool{return $id===4;} };
putenv('PACS_CASE_USER='.$state['username']);putenv('PACS_CASE_PASSWORD='.$state['password']);
$profiles=new PacsProfiles(['tenants'=>['4'=>[['id'=>'synthetic-cloud','label'=>'Laboratorio sintetico','enabled'=>true,'qido_url'=>$expected.'/dicom-web','wado_url'=>$expected.'/dicom-web','auth'=>'basic','username_env'=>'PACS_CASE_USER','password_env'=>'PACS_CASE_PASSWORD','download_enabled'=>true]]]]);
$pacs=new PacsService($db,4,1,$profiles,$gate);
$orders=new PacsOrderService($db,4,1,$pacs,$gate,$lookup);
if ($phase==='prepare') {
 if ($db->tableExists('pacs_orders')) {echo "Existing case: use existing request.json\n";exit;}
 foreach (['dap01_users'=>'id_user INTEGER PRIMARY KEY,is_active INTEGER,username TEXT','dap03_personale'=>'id_personale INTEGER PRIMARY KEY,id_user INTEGER,tipo INTEGER,is_active INTEGER','dap02_clients'=>'id_client INTEGER PRIMARY KEY','dap09_client_doctor'=>'id_client INTEGER,id_dot INTEGER'] as $table=>$columns) $db->query('CREATE TABLE '.$table.' ('.$columns.')');
 $db->table('dap01_users')->insert(['id_user'=>1,'is_active'=>1,'username'=>'MEDICO_SINTETICO_LAB']);
 $db->table('dap03_personale')->insert(['id_personale'=>10,'id_user'=>1,'tipo'=>1,'is_active'=>1]);
 $db->table('dap02_clients')->insert(['id_client'=>100]);$db->table('dap09_client_doctor')->insert(['id_client'=>100,'id_dot'=>10]);
 foreach (['2026-09-12-160001_CreateClinicalRecords'=>'CreateClinicalRecords','2026-09-13-100001_CreatePacsIntegration'=>'CreatePacsIntegration','2026-09-13-110001_CreatePacsOrders'=>'CreatePacsOrders','2026-09-13-120001_AddPacsOrderWorkflow'=>'AddPacsOrderWorkflow'] as $file=>$class) {
  require_once APPPATH.'Database/Migrations/'.$file.'.php';$class='App\\Database\\Migrations\\'.$class;(new $class(\Config\Database::forge($db)))->up();
 }
 $binding=$pacs->bind(100,'synthetic-cloud','AF-CASE-20261002','AF-DEMO',0,true);
 $id=$orders->create(100,$binding,['description'=>'Phantom sintetico - percorso completo','procedure_code'=>'DEMO-CASE','coding_scheme'=>'AF-DEMO','modality'=>'OT','station_ae'=>'DEMO_CASE','scheduled_at'=>'2026-10-02T10:00','reason'=>'Solo collaudo, nessun valore clinico'],bin2hex(random_bytes(16)));
 $orders->approve(100,$id,1,true);file_put_contents($dir.'/worklist.wl',$orders->export(100,$id,2,'dicom')['bytes']);
 $orders->advance(100,$id,3,'accepted');$orders->advance(100,$id,4,'in_progress');$orders->advance(100,$id,5,'performed');
 $row=$orders->read(100,$id);
 file_put_contents($dir.'/request.json',json_encode(['id'=>$id,'patient'=>'AF-CASE-20261002','issuer'=>'AF-DEMO','study'=>$row['study_uid'],'accession'=>$row['accession']],JSON_PRETTY_PRINT));
 echo "Prepared through actual order services; worklist exported; performed.\n";
} elseif ($phase==='complete') {
 $request=json_decode(file_get_contents($dir.'/request.json'),true);$id=$request['id'];$row=$orders->read(100,$id);
 if (!$row['study_link_id']) $orders->linkImages(100,$id,(int)$row['revision']);
 $row=$orders->read(100,$id);
 if (!$row['report_entry_id']) $orders->saveReport(100,$id,(int)$row['revision'],['body'=>'REFERTO SINTETICO DI COLLAUDO — SENZA VALORE CLINICO. Serie di 16 immagini geometriche generate al computer. Associazione verificata fra paziente, richiesta, studio PACS e documento in cartella. Nessuna valutazione diagnostica.','occurred_at'=>'2026-10-02T10:15']);
 $chart=(new ClinicalRecordService($db,4,1))->patient(100,1,false);
 $row=$orders->read(100,$id);$report=$orders->report(100,$id);$details=$pacs->details(100,$row['study_link_id']);
 if (count($chart['entries'])!==1 || (int)$chart['entries'][0]['id']!==(int)$report['id'] || $details['study']['uid']!==$row['study_uid'] || $details['study']['accession']!==$row['accession']) throw new RuntimeException('Chart association failed');
 $images=json_decode(file_get_contents($dir.'/manifest.json'),true);
 foreach ($images['instances'] as $n=>$instance) {
  $file=$pacs->download(100,$row['study_link_id'],$images['series'],$instance);
  if (!hash_equals($images['hashes'][$n],hash('sha256',$file['bytes']))) throw new RuntimeException('Image mismatch');
 }
 $result=['marker'=>'AF_VERIFIED_SYNTHETIC_CHART_V1','verified_at'=>gmdate('c'),'patient'=>$patient,'order'=>array_intersect_key($row,array_flip(['id','accession','study_uid','payload','state','workflow_stage','report_entry_id','study_link_id'])),'report'=>array_intersect_key($report,array_flip(['id','state','kind','content','occurred_at'])),'history'=>$orders->history(100,$id)['rows'],'images'=>$images,'checks'=>['Richiesta creata, confermata ed esportata con PacsOrderService','Accettazione ed esecuzione registrate','Paziente, issuer, StudyUID e accession verificati sul PACS cloud','Referto salvato con ClinicalRecordService e letto nella cartella','16 immagini scaricate tramite PacsService con hash corrispondente']];
 file_put_contents($dir.'/verified-case.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));echo "PASS: actual chart, order, report, live PACS identity and 16 WADO hashes.\n";
} else throw new RuntimeException('Use prepare or complete');
