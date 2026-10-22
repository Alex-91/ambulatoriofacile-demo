<?php
// Isolated loopback MySQL, generated af_web_test_* schemas, no .env loading.
if (($argv[1]??'')!=='--isolated-local-mysql') exit('Requires --isolated-local-mysql');
require __DIR__.'/_support/whatsapp_booking_bootstrap.php';
use Config\Database;
use App\Services\{PatientBookingAgenda,PatientBookingSchema,PatientBookingSettings,PatientBookingException};
function connection(string $name=''): array { return ['DBDriver'=>'MySQLi','hostname'=>'127.0.0.1','port'=>13371,'username'=>'root','password'=>'','database'=>$name,'DBPrefix'=>'','DBDebug'=>true,'charset'=>'utf8mb4','DBCollat'=>'utf8mb4_unicode_ci']; }
$count=0;
function check(bool $yes,string $what): void { global $count; $count++; if(!$yes) throw new RuntimeException($what); }
set_exception_handler(static function(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
putenv('DB_ENCRYPTION_KEY=synthetic-patient-booking-key');
if (($argv[2]??'')==='worker') {
    $name=$argv[3]??''; if(!preg_match('/^af_web_test_[a-f0-9]{16}$/D',$name))exit(2);
    [$settings,$body,$actor,$key]=json_decode(base64_decode($argv[4]),true);
    try {(new PatientBookingAgenda(Database::connect(connection($name),false),$settings,$actor))->book($body,$key);echo 'booked';}
    catch(PatientBookingException $e){echo $e->reason;}exit;
}
$name='af_web_test_'.bin2hex(random_bytes(8));
$admin=Database::connect(connection(),false);$admin->query('CREATE DATABASE '.$name);
try {
    $db=Database::connect(connection($name),false);
    PatientBookingSchema::platform($db);PatientBookingSchema::platform($db);PatientBookingSchema::tenant($db);PatientBookingSchema::tenant($db);
    $db->query('CREATE TABLE dap03_personale (id_personale INT PRIMARY KEY,legacy_id_dot INT,tipo INT,nome TEXT,cognome TEXT,qualifica TEXT,vector_id VARBINARY(16))');
    $db->query('CREATE TABLE dap42_ambulatori (id_amb_legacy INT PRIMARY KEY,nome VARCHAR(80),indirizzo VARCHAR(100))');
    $db->query("INSERT INTO dap42_ambulatori VALUES(1,'Empoli','Via di prova 1'),(2,'Vinci','Via di prova 2')");
    $db->query('CREATE TABLE dap11_agenda_slot (id_slot INT PRIMARY KEY,id_dot INT,data_slot DATE,ora_inizio DATETIME,ora_fine DATETIME,stato VARCHAR(20),id_stanza INT,id_amb_legacy INT,updated_at DATETIME)');
    $db->query('CREATE TABLE dap12_agenda_appuntamenti (id_appuntamento INT AUTO_INCREMENT PRIMARY KEY,id_slot INT,id_dot INT,id_client INT,id_paziente INT,nome VARCHAR(80),cognome VARCHAR(80),telefono VARCHAR(32),cellulare VARCHAR(32),email VARCHAR(120),note TEXT,motivo_visita TEXT,indirizzo_visita TEXT,comune_visita TEXT,stato VARCHAR(20),created_by INT,created_at DATETIME,id_tipo_visita INT,tipo_visita_label VARCHAR(80),durata_minuti INT,ora_inizio_appuntamento DATETIME,ora_fine_appuntamento DATETIME)');
    $db->query('CREATE TABLE dap44_agenda_tipi_visita (id_tipo_visita INT PRIMARY KEY,nome VARCHAR(80),durata_minuti INT,attivo TINYINT,colore VARCHAR(20),usa_colore_tipo_visita_slot TINYINT,ordinamento INT,created_by INT,updated_by INT,created_at DATETIME,updated_at DATETIME)');
    $db->query("INSERT INTO dap44_agenda_tipi_visita VALUES(1,'Prima visita',45,1,'#000000',0,1,NULL,NULL,NOW(),NOW()),(2,'Controllo',30,1,'#000000',0,2,NULL,NULL,NOW(),NOW())");
    $db->query('CREATE TABLE dap45_agenda_appuntamenti_slot (id_appuntamento_slot INT AUTO_INCREMENT PRIMARY KEY,id_appuntamento INT,id_slot INT,posizione INT,is_primario TINYINT,created_at DATETIME)');
    $db->query('CREATE TABLE dap14_agenda_lock (id_lock INT AUTO_INCREMENT PRIMARY KEY,id_slot INT,stato VARCHAR(20),token_lock VARCHAR(80),expires_at DATETIME)');
    $db->query('CREATE TABLE dap21_agenda_giorni_bloccati (id_dot INT,data_agenda DATE)');
    $db->query('CREATE TABLE dap48_gio_ros (gio_ros VARCHAR(20))');
    $db->resetDataCache();
    $config=PatientBookingSettings::defaults();$config['doctors']=[10];$config['visits']=[1,2];$config['locations']=[1];$config['privacy_url']='https://studio.example/privacy';
    $store=new PatientBookingSettings($db);
    check(!$store->enabled(1),'Default disabled');$store->save(1,$config,true,55,0);
    check($store->enabled(1) && !$store->enabled(2),'Tenant enablement isolation');
    try{$store->save(1,$config,true,55,0);throw new RuntimeException('Stale config accepted');}catch(PatientBookingException $e){check($e->status===409,'Concurrent config rejected');}
    $settings=$store->get(1);$agenda=new PatientBookingAgenda($db,$settings,55);
    $db->query("INSERT INTO dap03_personale VALUES(1,10,1,HEX(AES_ENCRYPT('Elena',@key_str,@init_vector)),HEX(AES_ENCRYPT('Conti',@key_str,@init_vector)),HEX(AES_ENCRYPT('Dott.ssa',@key_str,@init_vector)),@init_vector),(2,20,1,HEX(AES_ENCRYPT('Giulia',@key_str,@init_vector)),HEX(AES_ENCRYPT('Ferri',@key_str,@init_vector)),HEX(AES_ENCRYPT('Dott.ssa',@key_str,@init_vector)),@init_vector)");
    $day=(new DateTimeImmutable('next monday',new DateTimeZone('Europe/Rome')))->modify('+7 days')->format('Y-m-d');
    for($i=0;$i<12;$i++){
        $start=$day.' '.sprintf('%02d:%02d:00',9+intdiv($i,2),($i%2)*30);$end=date('Y-m-d H:i:s',strtotime($start)+1800);
        $db->table('dap11_agenda_slot')->insert(['id_slot'=>$i+1,'id_dot'=>10,'data_slot'=>$day,'ora_inizio'=>$start,'ora_fine'=>$end,'stato'=>'LIBERO','id_stanza'=>1,'id_amb_legacy'=>1]);
    }
    $q=['prestazione'=>'1','professionista'=>'10','sede'=>'1','dal'=>$day,'al'=>$day];
    check(count($agenda->catalog()['professionisti'])===1,'Only enabled professionals');
    check(count($agenda->availability($q)['giorni'][$day]['orari'])===11,'45 minute visit on 30 minute slots');
    $db->table('dap21_agenda_giorni_bloccati')->insert(['id_dot'=>10,'data_agenda'=>$day]);
    check(!$agenda->availability($q)['giorni'][$day]['orari'],'Blocked day');$db->table('dap21_agenda_giorni_bloccati')->where('id_dot',10)->delete();
    $db->table('dap48_gio_ros')->insert(['gio_ros'=>date('d/m',strtotime($day))]);check(!$agenda->availability($q)['giorni'][$day]['orari'],'Holiday');$db->table('dap48_gio_ros')->emptyTable();
    $db->table('dap14_agenda_lock')->insert(['id_slot'=>1,'stato'=>'ATTIVO','token_lock'=>'operator','expires_at'=>$day.' 23:00:00']);
    check(count($agenda->availability($q)['giorni'][$day]['orari'])===10,'Operator lock excluded');$db->table('dap14_agenda_lock')->emptyTable();
    try{$agenda->availability(array_merge($q,['professionista'=>'20']));throw new RuntimeException('Disabled doctor accepted');}catch(PatientBookingException $e){check($e->status===409,'Disabled doctor rejected');}
    $body=['slot'=>['prestazione'=>'1','professionista'=>'10','sede'=>'1','data'=>$day,'ora'=>'9:00'],'paziente'=>['nome'=>'Rosa','cognome'=>'Bianchi'],'contatto'=>['cellulare'=>'3478126650','email'=>'rosa@example.test'],'nota'=>'','privacy_letta'=>true,'per'=>'me'];
    $emailSettings=$settings;$emailSettings['config']['workflow']['senza_verifica']['recapito']='email';
    $noEmail=$body;$noEmail['contatto']['email']='';
    try{(new PatientBookingAgenda($db,$emailSettings,55))->book($noEmail,bin2hex(random_bytes(16)));throw new RuntimeException('Required email bypassed');}catch(PatientBookingException $e){check($e->reason==='dati_non_validi','Required email enforced by server');}
    $noteSettings=$settings;$noteSettings['config']['workflow']['dati']['note']='obbligatorio';
    try{(new PatientBookingAgenda($db,$noteSettings,55))->book($body,bin2hex(random_bytes(16)));throw new RuntimeException('Required note bypassed');}catch(PatientBookingException $e){check($e->reason==='dati_non_validi','Required note enforced by server');}
    $config['workflow']=$emailSettings['config']['workflow'];$store->save(1,$config,true,55,$settings['revision']);
    check($store->get(1)['config']['workflow']['senza_verifica']['recapito']==='email','Workflow persisted in platform settings');
    $key=bin2hex(random_bytes(16));$p=$agenda->book($body,$key);
    check($p['stato']==='confermata','Real agenda booking');
    $row=$db->table('dap12_agenda_appuntamenti')->get()->getRowArray();check($row['id_client']===null,'Unverified data never auto-links registry');
    check($row['ora_fine_appuntamento']===$day.' 09:45:00','Exact visit duration');
    check($db->table('dap45_agenda_appuntamenti_slot')->countAllResults()===2,'Both slots reserved');
    check($agenda->book($body,$key)['ref']===$p['ref'],'Retry is idempotent');
    $noMove=$settings;$noMove['config']['workflow']['regole']['spostamento_consentito']=false;
    try{(new PatientBookingAgenda($db,$noMove,55))->move($p['ref'],['data'=>$day,'ora'=>'11:00']);throw new RuntimeException('Move restriction bypassed');}catch(PatientBookingException $e){check($e->reason==='spostamento_non_consentito','Move disabled by server');}
    try{$agenda->book($body,bin2hex(random_bytes(16)));throw new RuntimeException('Occupied slot booked');}catch(PatientBookingException $e){check($e->reason==='slot_non_disponibile','Occupied slot rejected');}
    try{(new PatientBookingAgenda($db,$settings,99))->cancel($p['ref']);throw new RuntimeException('Foreign booking cancelled');}catch(PatientBookingException $e){check($e->status===404,'Ownership checked on cancellation');}
    $moved=$agenda->move($p['ref'],['data'=>$day,'ora'=>'11:00']);check($moved['ora']==='11:00','Atomic move');
    check($db->table('dap11_agenda_slot')->where('id_slot',1)->get()->getRowArray()['stato']==='LIBERO','Old slot restored');
    check($agenda->move($p['ref'],['data'=>$day,'ora'=>'11:00'])['ref']===$p['ref'],'Move retry no-op');
    $cancel=$agenda->cancel($p['ref']);check($cancel['stato']==='annullata','Cancellation persisted');
    check($db->table('dap11_agenda_slot')->where('stato','PRENOTATO')->countAllResults()===0,'Cancellation restores all covered slots');
    $workers=[];
    for($n=0;$n<3;$n++) {
        $args=[PHP_BINARY,'-d','xdebug.mode=off',__FILE__,'--isolated-local-mysql','worker',$name,base64_encode(json_encode([$settings,$body,200+$n,bin2hex(random_bytes(16))]))];
        $proc=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$proc,$pipes];
    }
    $out=[];foreach($workers as [$proc,$pipes]){$out[]=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0,'Worker successful: '.$err);}
    check(count(array_filter($out,static fn($s)=>$s==='booked'))===1,'Three concurrent bookings: exactly one succeeds');
    check(count(array_filter($out,static fn($s)=>$s==='slot_non_disponibile'))===2,'Two concurrent conflicts');
    echo "PASS $count MySQL checks: isolated schema, tenant settings, real agenda, locks, duration, retries, cancellation, move, concurrency.\n";
} finally { $admin->query('DROP DATABASE '.$name); }
