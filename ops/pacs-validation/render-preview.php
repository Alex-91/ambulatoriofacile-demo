<?php
require __DIR__.'/bootstrap.php';
helper(['form','url']);
$patientId=100; $patient=['patient_name'=>'Paziente sintetico']; $tenant=['tenant_name'=>'Ambulatorio di prova'];
$binding=['id'=>str_repeat('a',32),'profile_id'=>'cloud','identity'=>['patient_id'=>'P-100','issuer'=>'TEST-HOSPITAL'],'active'=>1,'revision'=>1,'owner_user_id'=>1];
$study=['uid'=>'1.2.826.0.1.3680043.10.543.20260913.1','description'=>'Esame diagnostico di prova','date'=>'20260913','modalities'=>'OT','patient_name'=>'SINTETICO^PAZIENTE','birth_date'=>'19800101','accession'=>'SYNTHETIC-1'];
$link=['id'=>str_repeat('b',32),'study'=>$study,'owner_user_id'=>1,'created_at'=>'2026-09-13 10:00:00','entry_id'=>null];
$overview=['bindings'=>[$binding],'links'=>[$link],'profiles'=>['cloud'=>['id'=>'cloud','label'=>'PACS di laboratorio']],'doctor'=>true,'user_id'=>1];
$root=dirname(__DIR__,2).'/rest/writable/pacs-preview';
if (!is_dir($root)) mkdir($root,0700,true);
file_put_contents($root.'/index.html',view('clinical/pacs',compact('patientId','patient','tenant','overview')));
$search=['studies'=>[$study],'binding'=>$binding,'page'=>1,'more'=>false];
file_put_contents($root.'/search.html',view('clinical/pacs',compact('patientId','patient','tenant','overview','search')));
$details=['study'=>$study,'link'=>$link,'viewer'=>true,'download'=>true,'series'=>['rows'=>[['uid'=>'1.2.3','description'=>'Serie sintetica','modality'=>'OT','number'=>'1']],'more'=>false],'instances'=>['rows'=>[['uid'=>'1.2.3.4','number'=>'1']],'page'=>1,'more'=>false]];
$selectedSeries='1.2.3';
$search=null;
file_put_contents($root.'/study.html',view('clinical/pacs',compact('patientId','patient','tenant','overview','details','selectedSeries','search')));
echo "Three synthetic page previews rendered.\n";
$listing=['rows'=>[],'page'=>1,'more'=>false,'doctor'=>true,'user_id'=>1];
file_put_contents($root.'/orders.html',view('clinical/pacs_orders',compact('patientId','patient','tenant','overview','listing'),['saveData'=>false]));
$payload=\App\Services\Pacs\ModalityWorklist::payload(
    ['description'=>'TC addome di prova','procedure_code'=>'LAB-CT','coding_scheme'=>'99AFLAB','modality'=>'CT','station_ae'=>'FINDSCU','scheduled_at'=>'2026-09-20T10:30','reason'=>'Richiesta sintetica per verifica interfaccia'],
    ['patient_last_name'=>'Sintetico','patient_first_name'=>'Paziente','patient_birth_date'=>'1980-01-01']
)+['pacs_patient_id'=>'P-100','pacs_issuer'=>'TEST-HOSPITAL'];
$order=['id'=>str_repeat('c',32),'payload'=>$payload,'owner_user_id'=>1,'accession'=>'AF0123456789ABCD','study_uid'=>'2.25.267115564795301338902383769944783562680','state'=>'draft','revision'=>1,'last_exported_at'=>null];
foreach (['draft','ready','cancelled'] as $state) {
    $order['state']=$state;
    file_put_contents($root.'/order-'.$state.'.html',view('clinical/pacs_orders',compact('patientId','patient','tenant','listing','order'),['saveData'=>false]));
}
echo "Four synthetic request page previews rendered.\n";

$order['state']='ready'; $order['workflow_stage']='performed'; $order['appointment_id']=10;
$order['study_link_id']=str_repeat('b',32); $order['report_entry_id']=1;
$report=['id'=>1,'state'=>'draft','revision'=>1,'occurred_at'=>'2026-09-20 11:30:00','content'=>['title'=>'Referto sintetico','body'=>'Documento dimostrativo, privo di dati clinici reali.']];
$history=['rows'=>[['event'=>'pacs_stage_performed','actor_user_id'=>1,'recorded_at'=>'2026-09-20 09:00:00'],['event'=>'pacs_stage_accepted','actor_user_id'=>3,'recorded_at'=>'2026-09-20 08:25:00']],'page'=>1,'more'=>false];
file_put_contents($root.'/order-performed.html',view('clinical/pacs_orders',compact('patientId','patient','tenant','listing','order','history','report'),['saveData'=>false]));
$queue=['date'=>'2026-09-20','page'=>1,'more'=>false,'completed'=>true,'role'=>3,'rows'=>[]];
foreach (['awaiting','accepted','in_progress','performed'] as $stage) $queue['rows'][]=['id'=>str_repeat('c',32),'patient_id'=>100,'patient_name'=>'Paziente sintetico','description'=>'Ecografia di prova','scheduled_at'=>'2026-09-20T10:30','accession'=>'AF0123456789ABCD','stage'=>$stage,'revision'=>2,'appointment_id'=>10,'clinical_owner'=>false,'problem'=>''];
file_put_contents($root.'/queue-reception.html',view('clinical/pacs_queue',compact('queue','tenant'),['saveData'=>false]));
$queue['role']=1; foreach ($queue['rows'] as &$item) $item['clinical_owner']=true; unset($item);
file_put_contents($root.'/queue-doctor.html',view('clinical/pacs_queue',compact('queue','tenant'),['saveData'=>false]));
echo "Three workflow previews rendered.\n";
