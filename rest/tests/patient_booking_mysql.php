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
    $custom=$settings;$custom['config']['workflow']['catalogo']=['prestazioni'=>[['id_tipo_visita'=>1,'online'=>true,'nome_pubblico'=>'Nome pubblico','prezzo'=>'100']],'professionisti'=>[['id_dot'=>10,'online'=>true,'prestazioni'=>[2],'sedi'=>[1]]]];
    $customAgenda=new PatientBookingAgenda($db,$custom,55);check($customAgenda->catalog()['prestazioni'][0]['nome']==='Nome pubblico','Public service name from catalogue');
    try{$customAgenda->availability($q);throw new RuntimeException('Doctor service restriction bypassed');}catch(PatientBookingException $e){check($e->status===409,'Doctor service restriction enforced');}

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
    try{(new \App\Services\PatientBookingRecords($db))->enforceLimits('+393478126650','rosa@example.test',2,\App\Services\PatientBookingWorkflow::resolve($settings['config']),$day.' 09:15:00',$day.' 10:00:00');throw new RuntimeException('Contact overlap accepted');}catch(PatientBookingException $e){check($e->reason==='appuntamento_sovrapposto','Overlapping visits for same contact rejected');}
    $moved=$agenda->move($p['ref'],['data'=>$day,'ora'=>'11:00']);check($moved['ora']==='11:00','Atomic move');
    check($db->table('dap11_agenda_slot')->where('id_slot',1)->get()->getRowArray()['stato']==='LIBERO','Old slot restored');
    check($agenda->move($p['ref'],['data'=>$day,'ora'=>'11:00'])['ref']===$p['ref'],'Move retry no-op');
    $cancel=$agenda->cancel($p['ref']);check($cancel['stato']==='annullata','Cancellation persisted');
    check($db->table('dap11_agenda_slot')->where('stato','PRENOTATO')->countAllResults()===0,'Cancellation restores all covered slots');
    $approval=$settings;$approval['config']['workflow']['accesso']['modalita']='approvazione';
    $approval['config']['workflow']['dati']['nascita']='obbligatorio';
    $requestBody=$body;$requestBody['paziente']['nascita']='03/04/1980';$requestBody['slot']['ora']='12:00';
    $pendingAgenda=new PatientBookingAgenda($db,$approval,55);
    $pending=$pendingAgenda->book($requestBody,bin2hex(random_bytes(16)));
    check($pending['stato']==='in_attesa','Approval mode creates pending booking');
    check(count($pendingAgenda->requests())===1,'Real administration request list');
    $metadata=(new \App\Services\PatientBookingRecords($db))->details($pending['ref']);
    check($metadata['data']['nascita']==='1980-04-03','Birth date encrypted and recoverable');
    $raw=$db->table('patient_booking_details')->where('booking_ref',$pending['ref'])->get()->getRowArray();
    check(!str_contains($raw['data_encrypted'],'1980'),'No plaintext birth date');
    $db->query('CREATE TABLE dap02_clients (id_client INT AUTO_INCREMENT PRIMARY KEY,nome TEXT,cognome TEXT,cellulare TEXT,email TEXT,codice_fiscale TEXT,data_nascita TEXT,vector_id VARBINARY(16))');$db->resetDataCache();
    $other=new PatientBookingAgenda($db,$approval,99);
    check($other->resolveRequest($pending['ref'],'conferma',['collega'=>['nuova'=>true]])['stato']==='confermata','Tenant administrator can confirm another author request');
    check($db->table('dap02_clients')->countAllResults()===1 && count($other->patientMatches($pending['ref']))===1,'Explicit encrypted registry creation and match');
    check($other->resolveRequest($pending['ref'],'conferma')['stato']==='confermata','Confirmation retry is idempotent');
    $another=$requestBody;$another['slot']['ora']='13:00';
    try{$pendingAgenda->book($another,bin2hex(random_bytes(16)));throw new RuntimeException('Limit bypass');}catch(PatientBookingException $e){check($e->reason==='prestazione_gia_prenotata','Per-service contact limit');}
    $pendingAgenda->cancel($pending['ref']);
    $pending=$pendingAgenda->book($requestBody,bin2hex(random_bytes(16)));
    check($other->resolveRequest($pending['ref'],'rifiuta')['stato']==='rifiutata','Request rejected');
    $pending=$pendingAgenda->book($requestBody,bin2hex(random_bytes(16)));
    $db->table('patient_booking_details')->where('booking_ref',$pending['ref'])->update(['expires_at'=>gmdate('Y-m-d H:i:s',time()-60)]);
    check($pendingAgenda->expire()===1 && $pendingAgenda->detail($pending['ref'])['stato']==='scaduta','Expired request releases agenda');
    $pending=$pendingAgenda->book($requestBody,bin2hex(random_bytes(16)));
    $other->contactAction($pending['ref'],'blocca');$other->resolveRequest($pending['ref'],'rifiuta');
    try{$pendingAgenda->book($requestBody,bin2hex(random_bytes(16)));throw new RuntimeException('Block bypass');}catch(PatientBookingException $e){check($e->reason==='prenotazioni_sospese','Blocked contact enforced');}
    $other->contactAction($pending['ref'],'sblocca');
    $invited=$settings;$invited['config']['workflow']['accesso']['modalita']='invito';$inviteAgenda=new PatientBookingAgenda($db,$invited,55);
    $token=$inviteAgenda->invite(['prestazione'=>'1','professionista'=>'10','sede'=>'1']);
    $requestBody['invito']=$token;$booked=$inviteAgenda->book($requestBody,bin2hex(random_bytes(16)));
    check($booked['stato']==='confermata','Single use internal invitation');
    try{$inviteAgenda->validateInvite($token);throw new RuntimeException('Invite reused');}catch(PatientBookingException $e){check($e->status===410,'Used invitation rejected');}
    $inviteAgenda->cancel($booked['ref']);
    $records=new \App\Services\PatientBookingRecords($db);
    $records->contact(['nome'=>'Rosa','cellulare'=>'3478126650','testo'=>'Richiamatemi','privacy_letta'=>true],55);
    $contacts=$records->contacts();check(count($contacts)===1 && $contacts[0]['testo']==='Richiamatemi','Encrypted contact request');
    $records->closeContact((int)$contacts[0]['id']);check(!$records->contacts(),'Contact request completed');
    $new=$agenda->book($body,bin2hex(random_bytes(16)));
    $appointment=(int)$db->table('patient_online_bookings')->where('booking_ref',$new['ref'])->get()->getRowArray()['id_appuntamento'];
    try{$records->absence($appointment,55,true);throw new RuntimeException('Future absence accepted');}catch(PatientBookingException $e){check($e->status===409,'No absence on future booking');}
    $db->table('dap12_agenda_appuntamenti')->where('id_appuntamento',$appointment)->update(['ora_fine_appuntamento'=>'2020-01-01 10:00:00']);
    $records->absence($appointment,55,true);$records->absence($appointment,55,true);
    check($db->table('patient_booking_absences')->countAllResults()===1,'Absence retry idempotent');
    $agenda->cancel($new['ref']);
    $forced=$settings;$forced['config']['workflow']['limiti']['assenze_per_approvazione']=1;
    $forcedAgenda=new PatientBookingAgenda($db,$forced,55);$forcedBooking=$forcedAgenda->book($body,bin2hex(random_bytes(16)));
    check($forcedBooking['stato']==='in_attesa','Absence threshold forces approval in open mode');
    $forcedAgenda->cancel($forcedBooking['ref']);
    $db->table('patient_booking_absences')->emptyTable();
    $new=$agenda->book($body,bin2hex(random_bytes(16)));
    $delivered=[];$sender=static function($to,$message,$ics)use(&$delivered){$delivered[]=[$to,$message,$ics];return true;};
    $mailSettings=$settings;$mailSettings['config']['email']='studio@example.test';
    $notifications=new \App\Services\PatientBookingNotifications($db,['id_tenant'=>1,'tenant_name'=>'Studio test'],$mailSettings,$sender);
    check($agenda->detail($new['ref'])['notificata']===false,'Pending outbox does not claim notification delivery');
    $result=$notifications->run('https://example.test/internal',100);
    check($result['sent']>0 && $result['failed']===0,'Outbox delivers using fake transport');
    check($agenda->detail($new['ref'])['notificata']===true,'Booking acknowledges successful patient delivery');
    check($notifications->run('https://example.test/internal',100)['sent']===0,'Outbox does not resend');
    foreach($delivered as [$to,$message,$ics]){check(!str_contains($message['html'],'poliambulatoriosantachiara.it')&&!str_contains($message['html'],'Franco Rinaldi'),'No sample contact in delivered messages');}
    $appointment=(int)$db->table('patient_online_bookings')->where('booking_ref',$new['ref'])->get()->getRowArray()['id_appuntamento'];
    (new \App\Models\AgendaAppointmentModel($db))->deleteAppointment($appointment,0);$agenda->expire();
    check($records->details($new['ref'])['status']==='annullata','Native agenda cancellation synchronized');
    $workers=[];
    for($n=0;$n<3;$n++) {
        $args=[PHP_BINARY,'-d','xdebug.mode=off',__FILE__,'--isolated-local-mysql','worker',$name,base64_encode(json_encode([$settings,$body,200+$n,bin2hex(random_bytes(16))]))];
        $proc=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$proc,$pipes];
    }
    $out=[];foreach($workers as [$proc,$pipes]){$out[]=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0,'Worker successful: '.$err);}
    check(count(array_filter($out,static fn($s)=>$s==='booked'))===1,'Three concurrent bookings: exactly one succeeds');
    check(count(array_filter($out,static fn($s)=>$s==='slot_non_disponibile'))===2,'Two concurrent conflicts');

    // Regression: Alice has free agenda slots with no location, even though the
    // online catalogue still contains a different location from an older setup.
    $db->query("INSERT INTO dap03_personale VALUES(3,1000000012,1,HEX(AES_ENCRYPT('Alice',@key_str,@init_vector)),HEX(AES_ENCRYPT('Moretti',@key_str,@init_vector)),HEX(AES_ENCRYPT('Dott.ssa',@key_str,@init_vector)),@init_vector)");
    foreach([[100,'09:00','09:20','LIBERO'],[101,'09:20','09:40','LIBERO'],[102,'11:00','11:40','LIBERO'],[103,'12:00','12:20','PRENOTATO']] as [$slotId,$start,$end,$state])$db->table('dap11_agenda_slot')->insert(['id_slot'=>$slotId,'id_dot'=>1000000012,'data_slot'=>$day,'ora_inizio'=>$day.' '.$start.':00','ora_fine'=>$day.' '.$end.':00','stato'=>$state,'id_stanza'=>null,'id_amb_legacy'=>null]);
    $timeSettings=$settings;$timeSettings['config']['doctors']=[1000000012,20];$timeSettings['config']['visits']=[];$timeSettings['config']['locations']=[1];$timeSettings['config']['first_available']=false;
    $timeSettings['config']['workflow']=\App\Services\PatientBookingWorkflow::defaults();
    foreach($timeSettings['config']['workflow']['passi'] as &$step)if(in_array($step['id'],['prestazione','professionista','sede'],true))$step['attivo']=false;unset($step);
    $timeAgenda=new PatientBookingAgenda($db,$timeSettings,77);$timeQuery=['dal'=>$day,'al'=>$day];
    $available=$timeAgenda->availability($timeQuery)['giorni'][$day]['orari'];
    check(count($available)===3,'Alice null-location slots come from her actual agenda and occupied slots stay excluded');
    check($available[0]['professionista']==='1000000012'&&$available[0]['sede']===''&&$available[0]['durata']===20,'Time-only availability assigns the doctor and native slot duration');
    check(!$timeAgenda->availability($timeQuery+['sede'=>'1'])['giorni'][$day]['orari'],'An explicit physical site never matches an unassigned slot');
    $db->table('dap14_agenda_lock')->insert(['id_slot'=>100,'stato'=>'ATTIVO','token_lock'=>'operator-time','expires_at'=>$day.' 23:00:00']);
    check(count($timeAgenda->availability($timeQuery)['giorni'][$day]['orari'])===2,'Operator locks are respected for time-only booking');$db->table('dap14_agenda_lock')->where('token_lock','operator-time')->delete();
    $timeBody=['slot'=>['data'=>$day,'ora'=>'9:00'],'paziente'=>['nome'=>'Test','cognome'=>'Solo orario'],'contatto'=>['cellulare'=>'3470000000','email'=>'orario@example.test'],'privacy_letta'=>true,'per'=>'me'];$timeKey=bin2hex(random_bytes(16));
    $native=$timeAgenda->book($timeBody,$timeKey);
    check($native['prestazione']===null&&$native['professionista']==='1000000012'&&$native['sede']===''&&$native['durata']===20,'A real agenda appointment needs neither a service nor a patient-selected doctor/site');
    $nativeId=(int)$db->table('patient_online_bookings')->where('booking_ref',$native['ref'])->get()->getRowArray()['id_appuntamento'];$nativeRow=$db->table('dap12_agenda_appuntamenti')->where('id_appuntamento',$nativeId)->get()->getRowArray();
    check($nativeRow['id_tipo_visita']===null&&$nativeRow['ora_fine_appuntamento']===$day.' 09:20:00','No hidden visit type is saved and the native end time is preserved');
    check($timeAgenda->book($timeBody,$timeKey)['ref']===$native['ref'],'Time-only submission is idempotent');
    $relocation=$timeAgenda->availability($timeQuery+['prenotazione'=>$native['ref']])['giorni'][$day]['orari'];
    check($relocation[0]['durata']===20,'Move availability keeps the existing appointment duration');
    $native=$timeAgenda->move($native['ref'],['data'=>$day,'ora'=>'11:00']);
    check($native['durata']===20&&$native['prestazione']===null&&$native['ora']==='11:00','A service-less appointment can move into a longer native slot without changing duration');
    $ics=\App\Services\PatientBookingEmail::calendar($native,$timeAgenda->catalog());$icsEnd=(new DateTimeImmutable($day.' 11:20',new DateTimeZone('Europe/Rome')))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    check(str_contains($ics,'DTEND:'.$icsEnd),'Calendar export uses the actual duration without a service');
    $timeAgenda->cancel($native['ref']);
    check(count($timeAgenda->availability($timeQuery)['giorni'][$day]['orari'])===3,'Cancellation releases all native slots');
    $explicit=$timeBody;$explicit['slot']['professionista']='10';
    try{$timeAgenda->book($explicit,bin2hex(random_bytes(16)));throw new RuntimeException('Foreign doctor accepted in time-only flow');}catch(PatientBookingException $e){check($e->status===409,'Time-only booking keeps the selected-doctor allowlist');}

    $defaults=$timeSettings;$defaults['config']['visits']=[2];$defaults['config']['workflow']['scelte']['prestazione_predefinita']='2';$defaults['config']['workflow']['scelte']['professionista_predefinito']='1000000012';
    $defaultAgenda=new PatientBookingAgenda($db,$defaults,77);
    check($defaultAgenda->availability($timeQuery)['giorni'][$day]['orari'][0]['durata']===30,'Optional administrative defaults resolve even when omitted from the availability request');
    $defaultBooking=$defaultAgenda->book($timeBody,bin2hex(random_bytes(16)));
    check($defaultBooking['prestazione']==='2'&&$defaultBooking['durata']===30&&$defaultBooking['professionista']==='1000000012','An explicitly configured optional default still works without patient questions');
    $defaultAgenda->cancel($defaultBooking['ref']);
    echo "PASS $count MySQL checks: isolated schema, tenant settings, real agenda, locks, duration, retries, cancellation, move, concurrency.\n";
} finally { $admin->query('DROP DATABASE '.$name); }
