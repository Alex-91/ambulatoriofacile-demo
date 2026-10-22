<?php
require __DIR__.'/whatsapp_booking_bootstrap.php';
helper(['url','form','portal']);
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$c=$input['config'];
try {
    if(($input['mode']??'')==='transition') {
        $c=\App\Services\PatientBookingEditor::transition($c,$input['action'],$input['payload']??[],1);
        echo json_encode(['config'=>$c,'state'=>\App\Services\PatientBookingEditor::state(['config'=>$c,'revision'=>$input['revision']+1])],JSON_THROW_ON_ERROR);exit;
    }
    if(($input['mode']??'')==='design') {
        echo \App\Services\PatientBookingDesign::render($input['board']??'A01-regole',[
            'tenant'=>'Studio dimostrativo','state'=>\App\Services\PatientBookingEditor::state(['config'=>$c,'revision'=>$input['revision']??1]),
            'catalog'=>$input['catalog'],'editorUrl'=>'/editor','adminBase'=>'/admin','patientUrl'=>'/paziente','csrf'=>'preview','csrfHeader'=>'X-CSRF-TOKEN','previewMode'=>true,
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
