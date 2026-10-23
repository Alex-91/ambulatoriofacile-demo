<?php
define('FCPATH',dirname(__DIR__,3).DIRECTORY_SEPARATOR);
require __DIR__.'/whatsapp_booking_bootstrap.php';
helper(['url','form','portal']);
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$c=$input['config']??[];
try {
    if(($input['mode']??'')==='qr'){
        require dirname(__DIR__,3).'/vendor/autoload.php';
        $options=new \chillerlan\QRCode\QROptions(['outputType'=>\chillerlan\QRCode\Output\QROutputInterface::GDIMAGE_PNG,'outputBase64'=>false,'scale'=>10]);
        echo base64_encode((new \chillerlan\QRCode\QRCode($options))->render($input['url']));exit;
    }
    if(($input['mode']??'')==='email'){
        $p=['ref'=>'K7P2QH','data'=>'2026-11-12','ora'=>'17:30','professionista'=>'10','sede'=>'1','prestazione'=>'1','paziente'=>['nome'=>'Rosa','cognome'=>'Bianchi'],'cellulare'=>'3478126650','email'=>'rosa@example.test','ics_sequence'=>0];
        echo \App\Services\PatientBookingEmail::render($input['type'],$p,['tenant_name'=>'Studio dimostrativo'],$c,$input['catalog'],'/paziente')['html'];exit;
    }
    if(($input['mode']??'')==='transition') {
        if($input['action']==='catalog'){foreach(['doctors','visits','locations'] as $key)$c[$key]=array_map('intval',$input['payload'][$key]);if(isset($input['payload']['catalogo'])){$c['workflow']['catalogo']=$input['payload']['catalogo'];if(isset($c['editor']['draft']))$c['editor']['draft']['catalogo']=$input['payload']['catalogo'];}}
        else $c=\App\Services\PatientBookingEditor::transition($c,$input['action'],$input['payload']??[],1);
        echo json_encode(['config'=>$c,'state'=>\App\Services\PatientBookingEditor::state(['config'=>$c,'revision'=>$input['revision']+1])],JSON_THROW_ON_ERROR);exit;
    }
    if(($input['mode']??'')==='design') {
        session()->set('menuDataAdmin',['result'=>[
            ['titolo_menu'=>'Agenda','link'=>'agenda','icona'=>'fa-calendar'],
            ['titolo_menu'=>'Menu personalizzato dello spazio','link'=>'pazienti','icona'=>'fa-users'],
            ['titolo_menu'=>'Prenotazione online','link'=>'http://booking-test.invalid/admin','icona'=>'fa-calendar-check-o'],
        ]]);
        echo \App\Services\PatientBookingDesign::render($input['board']??'A01-regole',[
            'tenant'=>'Studio dimostrativo','state'=>\App\Services\PatientBookingEditor::state(['config'=>$c,'revision'=>$input['revision']??1]),
            'catalog'=>$input['catalog'],'selection'=>array_intersect_key($c,array_flip(['doctors','visits','locations'])),'editorUrl'=>'/editor','adminBase'=>'/admin','patientUrl'=>'/paziente','csrf'=>'preview','csrfHeader'=>'X-CSRF-TOKEN','previewMode'=>true,'requests'=>$input['requests']??[],'apiUrl'=>'/api','assetUrl'=>'/asset',
        ]);exit;
    }
    $c['workflow']=\App\Services\PatientBookingWorkflow::validate($c['workflow']??[],$c);
    $workflow=\App\Services\PatientBookingWorkflow::resolve($c);
    if(($input['mode']??'')==='config') {echo json_encode(['config'=>$c,'workflow'=>$workflow],JSON_THROW_ON_ERROR);exit;}
    echo view('patient_booking/admin',[
        'tenant'=>['tenant_name'=>'Studio dimostrativo'], 'state'=>['config'=>$c,'revision'=>$input['revision']??1],
        'catalog'=>$input['catalog'],'workflow'=>$workflow,'saveUrl'=>'/admin/save','patientUrl'=>'/paziente',
        'error'=>$input['error']??null,'success'=>$input['success']??null,'previewMode'=>true,
    ]);
} catch(\Throwable $e) {fwrite(STDERR,$e->getMessage());exit(1);}
