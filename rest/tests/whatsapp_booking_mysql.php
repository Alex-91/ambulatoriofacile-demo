<?php
// Disposable MySQL only: loopback port 13369, no .env, random database name.
if(($argv[1]??'')!=='--isolated-local-mysql')exit('Requires --isolated-local-mysql');
require __DIR__.'/_support/whatsapp_booking_bootstrap.php';
use Config\Database;
use App\Services\{WhatsAppBookingAgenda,WhatsAppBookingService,WhatsAppBookingConfig,WhatsAppBookingUnavailable,WhatsAppGatewayClient};
function connConfig($name=''){return ['DBDriver'=>'MySQLi','hostname'=>'127.0.0.1','port'=>13369,'username'=>'root','password'=>'','database'=>$name,'DBPrefix'=>'','DBDebug'=>true,'charset'=>'utf8mb4','DBCollat'=>'utf8mb4_unicode_ci'];}
$count=0;function verify($ok,$message){global $count;$count++;if(!$ok)throw new RuntimeException($message);}
class BookingGatewayStub extends WhatsAppGatewayClient {public bool $fail=false;public int $sent=0;public function __construct(){} public function sendText(int $tenantId,string $recipient,string $message): array {$this->sent++;return ['ok'=>!$this->fail];}}
set_exception_handler(static function(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
if(($argv[2]??'')==='worker'){
    $name=$argv[3]??'';if(!preg_match('/^af_wa_test_[a-f0-9]{16}$/D',$name))exit(2);
    [$c,$s]=json_decode(base64_decode($argv[4]),true);putenv('DB_ENCRYPTION_KEY=synthetic-whatsapp-key');
    try{(new WhatsAppBookingAgenda(Database::connect(connConfig($name),false),'393330000005'))->book($c,$s);echo 'booked';}catch(WhatsAppBookingUnavailable $e){echo 'unavailable';}exit;
}
$name='af_wa_test_'.bin2hex(random_bytes(8));$admin=Database::connect(connConfig(),false);$admin->query('CREATE DATABASE '.$name);
try{
    $db=Database::connect(connConfig($name),false);config(Database::class)->platform=connConfig($name);
    $db->query('CREATE TABLE platform_tenants (id_tenant INT UNSIGNED PRIMARY KEY)');$db->query('INSERT INTO platform_tenants VALUES (1),(2)');
    $db->query('CREATE TABLE platform_whatsapp_chatbots (id_tenant INT PRIMARY KEY,is_enabled TINYINT)');$db->query('INSERT INTO platform_whatsapp_chatbots VALUES (1,1),(2,1)');
    require APPPATH.'Database/Migrations/2026-10-19-100001_CreateWhatsAppBookings.php';
    $migration=new \App\Database\Migrations\CreateWhatsAppBookings();$migration->up();$migration->up();
    verify($db->tableExists('platform_whatsapp_booking_sessions'),'Migration applied twice');
    $db->query('CREATE TABLE dap03_personale (id_personale INT PRIMARY KEY,legacy_id_dot INT,tipo INT,nome TEXT,cognome TEXT,vector_id VARBINARY(16))');
    $db->query('CREATE TABLE dap42_ambulatori (id_amb_legacy INT PRIMARY KEY,nome VARCHAR(80))');$db->query("INSERT INTO dap42_ambulatori VALUES(1,'Studio centrale'),(2,'Studio nord')");
    $db->query('CREATE TABLE dap11_agenda_slot (id_slot INT PRIMARY KEY,id_dot INT,data_slot DATE,ora_inizio DATETIME,ora_fine DATETIME,stato VARCHAR(20),id_stanza INT,id_amb_legacy INT,updated_at DATETIME)');
    $db->query('CREATE TABLE dap12_agenda_appuntamenti (id_appuntamento INT AUTO_INCREMENT PRIMARY KEY,id_slot INT,id_dot INT,id_client INT,id_paziente INT,nome VARCHAR(80),cognome VARCHAR(80),telefono VARCHAR(32),cellulare VARCHAR(32),email VARCHAR(120),note TEXT,motivo_visita TEXT,indirizzo_visita TEXT,comune_visita TEXT,stato VARCHAR(20),created_by INT,created_at DATETIME,id_tipo_visita INT,tipo_visita_label VARCHAR(80),durata_minuti INT,ora_inizio_appuntamento DATETIME,ora_fine_appuntamento DATETIME)');
    $db->query('CREATE TABLE dap44_agenda_tipi_visita (id_tipo_visita INT PRIMARY KEY,nome VARCHAR(80),durata_minuti INT,attivo TINYINT,colore VARCHAR(20),usa_colore_tipo_visita_slot TINYINT,ordinamento INT,created_by INT,updated_by INT,created_at DATETIME,updated_at DATETIME)');
    $db->query("INSERT INTO dap44_agenda_tipi_visita VALUES(1,'Prima visita',30,1,'#000000',0,1,NULL,NULL,NOW(),NOW()),(2,'Controllo',20,1,'#000000',0,2,NULL,NULL,NOW(),NOW())");
    $db->query('CREATE TABLE dap45_agenda_appuntamenti_slot (id_appuntamento_slot INT AUTO_INCREMENT PRIMARY KEY,id_appuntamento INT,id_slot INT,posizione INT,is_primario TINYINT,created_at DATETIME)');
    $db->query('CREATE TABLE dap14_agenda_lock (id_lock INT AUTO_INCREMENT PRIMARY KEY,id_slot INT,stato VARCHAR(20),token_lock VARCHAR(80),expires_at DATETIME)');
    $db->query('CREATE TABLE dap21_agenda_giorni_bloccati (id_dot INT,data_agenda DATE)');
    $db->query('CREATE TABLE dap02_clients (id_client INT AUTO_INCREMENT PRIMARY KEY,nome TEXT,cognome TEXT,codice_fiscale TEXT,data_nascita TEXT,cellulare TEXT,telefono TEXT,email TEXT,indirizzo TEXT,citta TEXT,provincia TEXT,cap TEXT,comune_nascita TEXT,provincia_nascita TEXT,residenza_indirizzo TEXT,residenza_comune TEXT,residenza_cap TEXT,residenza_provincia TEXT,paz_spec TEXT,vector_id VARBINARY(16),id_personale INT,bloccato TINYINT,avviso_mail TINYINT)');
    $db->query('ALTER TABLE dap02_clients ADD legacy_id_paziente INT NULL');
    $db->query('CREATE TABLE dap09_client_doctor (id_users_doctor INT AUTO_INCREMENT PRIMARY KEY,id_client INT,id_dot INT)');
    $db->query('CREATE TABLE dap26_doctor_patient_search (id_dot INT,id_client INT,cognome_norm VARCHAR(191),nome_norm VARCHAR(191),full_norm VARCHAR(191),cf_norm VARCHAR(191),tel_norm VARCHAR(191),cell_norm VARCHAR(191),email_norm VARCHAR(191),paz_spec_norm VARCHAR(191),updated_at DATETIME,PRIMARY KEY(id_dot,id_client))');
    $db->resetDataCache();putenv('DB_ENCRYPTION_KEY=synthetic-whatsapp-key');$agenda=new WhatsAppBookingAgenda($db,'393330000000');
    $db->query("INSERT INTO dap03_personale VALUES(1,10,1,HEX(AES_ENCRYPT('Marco',@key_str,@init_vector)),HEX(AES_ENCRYPT('Esempio',@key_str,@init_vector)),@init_vector)");
    $day=(new DateTimeImmutable('now',new DateTimeZone('Europe/Rome')))->modify('+3 days')->format('Y-m-d');
    for($i=0;$i<12;$i++){$start=$day.' '.sprintf('%02d:%02d:00',9+intdiv($i,4),($i%4)*15);$end=date('Y-m-d H:i:s',strtotime($start)+900);$db->table('dap11_agenda_slot')->insert(['id_slot'=>$i+1,'id_dot'=>10,'data_slot'=>$day,'ora_inizio'=>$start,'ora_fine'=>$end,'stato'=>'LIBERO','id_stanza'=>1,'id_amb_legacy'=>1]);}
    $c=WhatsAppBookingConfig::normalize(['doctor_id'=>10,'location_id'=>1,'location'=>'Studio centrale','visit_ids'=>[1,2],'single_visit'=>true,'default_visit_id'=>1,'patient_mode'=>'name']);
    verify($agenda->catalog()['doctors'][0]['label']==='Esempio Marco','Encrypted doctor read');
    $s=['visit_id'=>1,'day'=>$day];$slots=$agenda->choices($c,$s,'time');verify(count($slots)===11,'30 minute contiguous spans');
    verify($agenda->choices($c,['visit_id'=>2,'day'=>$day],'time')===[],'Incompatible duration excluded');
    $db->table('dap21_agenda_giorni_bloccati')->insert(['id_dot'=>10,'data_agenda'=>$day]);verify($agenda->choices($c,$s,'time')===[],'Blocked days excluded');$db->table('dap21_agenda_giorni_bloccati')->where('id_dot',10)->delete();
    $db->table('dap14_agenda_lock')->insert(['id_slot'=>1,'stato'=>'ATTIVO','token_lock'=>'test','expires_at'=>date('Y-m-d H:i:s',time()+3600)]);verify(count($agenda->choices($c,$s,'time'))===10,'Operator locks excluded');$db->table('dap14_agenda_lock')->where('id_slot',1)->delete();
    $other=$c;$other['location_id']=2;$other['location']='Sede obsoleta';$other['lead_hours']=168;$other['window_days']=1;
    verify(count($agenda->choices($other,$s,'time'))===11,'Legacy chatbot filters do not override agenda');
    $db->query('ALTER TABLE dap42_ambulatori ADD indirizzo VARCHAR(120), ADD citta VARCHAR(80)');
    $db->query("UPDATE dap42_ambulatori SET indirizzo='Via Esempio 1',citta='Roma' WHERE id_amb_legacy=2");
    $db->query("INSERT INTO dap03_personale VALUES(2,20,1,HEX(AES_ENCRYPT('Anna',@key_str,@init_vector)),HEX(AES_ENCRYPT('Seconda',@key_str,@init_vector)),@init_vector)");
    foreach([[100,10,2,'09:00:00','09:15:00'],[101,10,2,'09:15:00','09:30:00'],[102,20,2,'14:00:00','14:30:00']] as [$slotId,$doctor,$site,$start,$end]){
        $db->table('dap11_agenda_slot')->insert(['id_slot'=>$slotId,'id_dot'=>$doctor,'data_slot'=>$day,'ora_inizio'=>$day.' '.$start,'ora_fine'=>$day.' '.$end,'stato'=>'LIBERO','id_stanza'=>2,'id_amb_legacy'=>$site]);
    }
    $multi=$agenda->choices($c,$s,'time');
    verify(count($multi)===12,'Parallel site slots preserve contiguous duration spans');
    $north=array_values(array_filter($multi,static fn($v)=>$v['id']===100))[0];
    verify(str_contains($north['label'],'Studio nord, Via Esempio 1, Roma'),'Slot label derives address from agenda site');
    verify(!in_array(102,array_column($multi,'id')),'Other doctor slots never leak');
    $second=$c;$second['doctor_id']=20;
    verify(array_column($agenda->choices($second,$s,'time'),'id')===[102],'Switching doctor switches entire agenda');
    $summary=$agenda->summary($other,$s+['slot'=>$north,'patient'=>['nome'=>'Test','cognome'=>'Agenda']]);
    verify(str_contains($summary,'Studio nord, Via Esempio 1, Roma')&&!str_contains($summary,'obsoleta'),'Summary uses selected slot actual site');
    $db->table('dap11_agenda_slot')->where('id_slot >=',100)->delete();
    foreach([['+1 hour',110],['+120 days',111]] as [$offset,$slotId]){
        $start=(new DateTimeImmutable('now',new DateTimeZone('Europe/Rome')))->modify($offset);
        $db->table('dap11_agenda_slot')->insert(['id_slot'=>$slotId,'id_dot'=>10,'data_slot'=>$start->format('Y-m-d'),'ora_inizio'=>$start->format('Y-m-d H:i:s'),'ora_fine'=>$start->modify('+30 minutes')->format('Y-m-d H:i:s'),'stato'=>'LIBERO','id_stanza'=>1,'id_amb_legacy'=>1]);
        verify(in_array($start->format('Y-m-d'),array_column($agenda->choices($other,['visit_id'=>1],'day'),'id')),'Agenda dates are not limited by chatbot lead time or horizon');
    }
    $db->table('dap11_agenda_slot')->where('id_slot >=',110)->delete();
    $s+=['slot'=>$slots[0],'patient'=>['nome'=>'Mario','cognome'=>'Rossi','codice_fiscale'=>'RSSMRA85T10A562S'],'booking_key'=>bin2hex(random_bytes(16))];
    $id=$agenda->book($c,$s);verify($id>0,'Appointment created');verify($db->table('dap45_agenda_appuntamenti_slot')->where('id_appuntamento',$id)->countAllResults()===2,'Both slots reserved');
    verify($agenda->patient('RSSMRA85T10A562S')['nome']==='Mario','Encrypted patient created and exact tax code lookup');verify($agenda->patient('RSSMRA85')===null,'Partial tax code rejected');
    verify($agenda->book($c,$s)===$id,'Replay after tenant commit is idempotent');verify($db->table('dap02_clients')->countAllResults()===1,'No duplicate patient on replay');
    verify($db->table('dap26_doctor_patient_search')->where('id_dot',10)->countAllResults()===1,'Patient indexed in doctor registry');
    $s['booking_key']=bin2hex(random_bytes(16));try{$agenda->book($c,$s);throw new RuntimeException('Double booking accepted');}catch(WhatsAppBookingUnavailable $expected){verify(true,'Occupied slot rejected');}
    $gateway=new BookingGatewayStub();$service=new WhatsAppBookingService($db,static fn($tenant,$phone)=>new WhatsAppBookingAgenda($db,$phone),$gateway);
    $c['confirmation']='manual';$service->save(1,$c);$i=0;$phone='393330000001';
    foreach(['prenota','1','1','Anna','Bianchi','1'] as $text)$result=$service->incoming(1,$phone,hash('sha256','msg'.++$i),$text);
    $requests=$service->requests(1);verify(count($requests)===1&&$requests[0]['kind']==='approval','Pending request persisted');verify($db->table('dap12_agenda_appuntamenti')->countAllResults()===1,'Pending request does not occupy agenda');
    verify($service->incoming(1,$phone,hash('sha256','msg'.$i),'1')===$result,'Inbound receipt replay');verify($service->requests(2)===[],'Tenant request isolation');
    try{$service->resolve(2,(int)$requests[0]['id'],'approve',1);throw new RuntimeException('Cross tenant approval');}catch(RuntimeException $expected){verify($expected->getMessage()==='Richiesta non trovata.','Cross tenant action denied');}
    $gateway->fail=true;try{$service->resolve(1,(int)$requests[0]['id'],'approve',1);}catch(RuntimeException $expected){verify(str_contains($expected->getMessage(),'Riprova'),'Delivery failure visible');}
    verify($db->table('dap12_agenda_appuntamenti')->countAllResults()===2,'Approval creates appointment despite delivery failure');
    $gateway->fail=false;$service->resolve(1,(int)$requests[0]['id'],'retry',1);verify($db->table('dap12_agenda_appuntamenti')->countAllResults()===2,'Notification retry never rebooks');
    verify($service->incoming(1,$phone,hash('sha256','legacy-after-completion'),'1')===null,'Completed booking releases legacy reminder responses');
    $service->incoming(1,$phone,hash('sha256','restart'),'prenota');
    $db->table('platform_whatsapp_booking_sessions')->where('id_tenant',1)->update(['expires_at'=>'2000-01-01 00:00:00']);
    $expired=$service->incoming(1,$phone,hash('sha256','expired'),'1');verify(str_contains($expired['reply'],'scaduta'),'Expired selections do not fall into reminder actions');
    $db->table('platform_whatsapp_chatbots')->where('id_tenant',1)->update(['is_enabled'=>0]);verify($service->incoming(1,$phone,hash('sha256','disabled'),'prenota')===null,'Disabled bot ignores inbound');
    try{$service->save(1,$c);throw new RuntimeException('Disabled config write');}catch(RuntimeException $e){verify($e->getMessage()==='Chatbot non abilitato per questo spazio.','Tenant cannot enable chatbot through config');}
    $free=$agenda->choices($c,['visit_id'=>1,'day'=>$day],'time');$workers=[];
    for($n=0;$n<3;$n++){
        $parallel=['visit_id'=>1,'day'=>$day,'slot'=>$free[0],'patient'=>['nome'=>'Test','cognome'=>'Concorrenza'],'booking_key'=>bin2hex(random_bytes(16))];
        $pipes=[];$process=proc_open([PHP_BINARY,'-d','xdebug.mode=off',__FILE__,'--isolated-local-mysql','worker',$name,base64_encode(json_encode([$c,$parallel]))],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$process,$pipes];
    }
    $outcomes=[];foreach($workers as [$process,$pipes]){$outcomes[]=trim(stream_get_contents($pipes[1]));$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);verify(proc_close($process)===0,'Concurrent worker: '.$error);}
    verify(count(array_filter($outcomes,static fn($v)=>$v==='booked'))===1,'Exactly one simultaneous booking succeeds');
    verify($db->table('dap12_agenda_appuntamenti')->countAllResults()===3,'No double booking under concurrency');
    verify($db->table('dap02_clients')->countAllResults()===3,'Failed bookings roll back new patient creation');
    echo "PASS $count MySQL integration checks: migration, encryption, isolation, locks, booking, idempotency, approval, delivery retry and three concurrent writers.\n";
    $db->close();Database::connect('platform')->close();
}finally{$admin->query('DROP DATABASE '.$name);$admin->close();}
