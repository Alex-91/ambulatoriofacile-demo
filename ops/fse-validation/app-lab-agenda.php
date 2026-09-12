<?php
if(PHP_SAPI!=='cli') { http_response_code(404);exit; }
require __DIR__.'/app-lab-common.php';$lab=fse_lab_config();fse_lab_boot();
$server=new mysqli('127.0.0.1','root',$lab['password'],'',33079);
if(realpath($server->query('SELECT @@datadir p')->fetch_assoc()['p'])!==realpath($lab['root'].'/mysql')) throw new RuntimeException('Wrong lab database.');
$action=$argv[1] ?? 'run';$name=$action==='book' ? (string)($argv[4] ?? '') : 'fselab_agenda_'.bin2hex(random_bytes(4));
if (!preg_match('/^fselab_agenda_[a-f0-9]{8}$/D',$name)) throw new RuntimeException('Invalid synthetic database.');
if($action==='run') $server->query('CREATE DATABASE '.$name);
$db=\Config\Database::connect(array_replace((new \Config\Database())->default,['database'=>$name]),false);
$book=static fn(int $slot,int $doctor)=>['id_slot'=>$slot,'id_dot'=>$doctor,'slot_lock_required'=>false,'visit_types_feature_enabled'=>false,'visit_type_required'=>false,'created_by'=>1,'nome'=>'Persona','cognome'=>'Sintetica'];
$model=new \App\Models\AgendaAppointmentModel($db);
if($action==='book') {
    usleep(250000);
    try { $model->saveAppointment($book((int)$argv[2],(int)$argv[3]));echo 'booked'; } catch(Throwable $e) { echo 'blocked'; }
    exit;
}
if($action!=='run') throw new RuntimeException('Unknown lab operation.');
$db->query('CREATE TABLE dap11_agenda_slot (id_slot INT PRIMARY KEY,id_dot INT,id_stanza INT,data_slot DATE,ora_inizio TIME,ora_fine TIME,stato VARCHAR(20),updated_at DATETIME)');
$db->query('CREATE TABLE dap12_agenda_appuntamenti (id_appuntamento INT AUTO_INCREMENT PRIMARY KEY,id_slot INT,id_dot INT,id_paziente INT NULL,id_client INT NULL,cognome TEXT,nome TEXT,telefono TEXT,cellulare TEXT,email TEXT,note TEXT,motivo_visita TEXT,indirizzo_visita TEXT,comune_visita TEXT,stato VARCHAR(20),created_at DATETIME,updated_at DATETIME NULL,updated_by INT NULL)');
$db->query('CREATE TABLE dap14_agenda_lock (id_lock INT PRIMARY KEY,id_slot INT,id_ope INT,token_lock VARCHAR(80),stato VARCHAR(20),expires_at DATETIME,updated_at DATETIME)');
$db->query('CREATE TABLE dap21_agenda_giorni_bloccati (id_dot INT,data_agenda DATE)');
foreach([[1,1,1,'10:00','10:30'],[2,2,1,'10:00','10:30'],[3,1,2,'10:00','10:30'],[4,1,1,'10:30','11:00'],[5,1,3,'11:00','11:30'],[6,2,3,'11:00','11:30']] as [$id,$doctor,$room,$start,$end]) {
    $db->table('dap11_agenda_slot')->insert(['id_slot'=>$id,'id_dot'=>$doctor,'id_stanza'=>$room,'data_slot'=>'2026-09-12','ora_inizio'=>$start,'ora_fine'=>$end,'stato'=>'LIBERO']);
}
$checks=[];
$check=static function(bool $ok,string $name) use(&$checks):void { if(!$ok) throw new RuntimeException($name);$checks[]=$name; };
$reject=static function(callable $op,string $name) use($check):void { try{$op();}catch(Throwable $e){$check(true,$name);return;}$check(false,$name); };
$id=$model->saveAppointment($book(1,1));$check($id>0,'booking');
$reject(fn()=>$model->saveAppointment($book(1,1)),'same_slot_blocked');
$reject(fn()=>$model->saveAppointment($book(2,2)),'shared_room_blocked');
$reject(fn()=>$model->saveAppointment($book(3,1)),'doctor_overlap_blocked');
$reject(fn()=>$model->saveAppointment($book(4,2)),'wrong_doctor_blocked');
$adjacent=$model->saveAppointment($book(4,1));$check($adjacent>0,'adjacent_allowed');
$check($model->updateAppointment(['id_appuntamento'=>$id,'nome'=>'Persona','cognome'=>'Sintetica','note'=>'Aggiornamento sintetico','visit_type_required'=>false]),'update');
$check($model->deleteAppointment($id,1),'cancellation');
$replacement=$model->saveAppointment($book(2,2));$check($replacement>0,'room_reusable_after_cancellation');
$reject(fn()=>$model->updateAppointment(['id_appuntamento'=>$id,'note'=>'must not return','visit_type_required'=>false]),'cancelled_update_rejected');
$children=[];
foreach([[5,1],[6,2]] as [$slot,$doctor]) {
    $process=proc_open([PHP_BINARY,__FILE__,'book',(string)$slot,(string)$doctor,$name],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);$children[]=[$process,$pipes];
}
$results=[];
foreach($children as [$process,$pipes]) { $result=trim(stream_get_contents($pipes[1]));$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$check(proc_close($process)===0,'child_completed');$results[]=$result; }
sort($results);$check($results===['blocked','booked'],'concurrent_room_single_winner');
file_put_contents($lab['root'].'/agenda-report.json',json_encode(['status'=>'passed','mode'=>'SYNTHETIC_MYSQL_AGENDA','database'=>$name,'checks'=>$checks],JSON_PRETTY_PRINT));
echo json_encode(['status'=>'passed','checks'=>count($checks)]);
