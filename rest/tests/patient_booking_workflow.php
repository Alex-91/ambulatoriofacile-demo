<?php
require __DIR__.'/_support/whatsapp_booking_bootstrap.php';
use App\Services\{PatientBookingWorkflow as W,PatientBookingSettings as S};
$n=0;function checkWorkflow($yes,$message){global $n;$n++;if(!$yes)throw new RuntimeException($message);}
function rejectWorkflow($w,$s,$message){try{W::validate($w,$s);checkWorkflow(false,$message);}catch(InvalidArgumentException $e){checkWorkflow(true,$message);}}
$s=S::defaults();$s['doctors']=[10,20];$s['visits']=[1,2];$s['locations']=[1];
$w=W::validate([],$s);checkWorkflow(!$w['verifica']['richiesta'] && $w['accesso']['modalita']==='aperta','Manual internal defaults');
$bad=$w;$bad['passi'][0]['id']='sede';rejectWorkflow($bad,$s,'Duplicate steps');
$bad=$w;$bad['passi'][3]['attivo']=false;rejectWorkflow($bad,$s,'Mandatory time step');
$bad=$w;[$bad['passi'][0],$bad['passi'][3]]=[$bad['passi'][3],$bad['passi'][0]];rejectWorkflow($bad,$s,'Time follows choices');
$bad=$w;[$bad['passi'][0],$bad['passi'][7]]=[$bad['passi'][7],$bad['passi'][0]];rejectWorkflow($bad,$s,'Summary last');
$bad=$w;$bad['passi'][0]['attivo']=false;rejectWorkflow($bad,$s,'Hidden service needs default');
$bad['scelte']['prestazione_predefinita']=1;checkWorkflow(W::validate($bad,$s)['scelte']['prestazione_predefinita']==='1','Valid default service');
$bad['scelte']['prestazione_predefinita']=99;rejectWorkflow($bad,$s,'Cross-catalog default rejected');
$bad=$w;$bad['verifica']['richiesta']=true;rejectWorkflow($bad,$s,'Verification not simulated');
$bad=$w;$bad['aspetto']['logo_url']='javascript:alert(1)';rejectWorkflow($bad,$s,'Unsafe logo rejected');
$bad=$w;$bad['aspetto']['logo_url']='data:image/png;base64,AAAA';rejectWorkflow($bad,$s,'Invalid image bytes rejected');
$bad=$w;$bad['senza_verifica']['recapito']='email';$bad['testi']['benvenuto_titolo']='Prenota qui';$s['workflow']=W::validate($bad,$s);$s['cancel_hours']=48;
$resolved=W::resolve($s);checkWorkflow($resolved['senza_verifica']['recapito']==='email'&&$resolved['testi']['benvenuto_titolo']==='Prenota qui'&&$resolved['regole']['preavviso_modifica_ore']===48,'Saved workflow resolves');
echo "PASS $n workflow checks.\n";
