<?php
require __DIR__.'/_support/whatsapp_booking_bootstrap.php';
use App\Services\{PatientBookingEditor as E,PatientBookingSettings as S,PatientBookingWorkflow as W};
$checks=0;function editorCheck($ok,$message){global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
$c=S::defaults();$c['doctors']=[10];$c['visits']=[1];$c['locations']=[1];$c['privacy_url']='https://studio.example/privacy';$c['workflow']=W::validate([],$c);
$draft=W::resolve($c);$draft['testi']['benvenuto_titolo']='Titolo della bozza';
$c=E::transition($c,'save',['draft'=>$draft],1);
editorCheck(W::resolve($c)['testi']['benvenuto_titolo']===null,'Draft must not change published configuration');
editorCheck(E::state(['config'=>$c,'revision'=>2])['draft']['testi']['benvenuto_titolo']==='Titolo della bozza','Draft survives reads');
$c=E::transition($c,'publish',[],1);
editorCheck(W::resolve($c)['testi']['benvenuto_titolo']==='Titolo della bozza','Publish applies draft');
editorCheck(count($c['editor']['history'])===1&&$c['editor']['published_revision']===2,'Publication archives previous revision');
$c=E::transition($c,'restore',['revision'=>1],1);
editorCheck($c['editor']['draft']['testi']['benvenuto_titolo']===null,'Restore copies revision to draft');
editorCheck(W::resolve($c)['testi']['benvenuto_titolo']==='Titolo della bozza','Restore must not change published configuration');
$c=E::transition($c,'discard',[],1);editorCheck($c['editor']['draft']['testi']['benvenuto_titolo']==='Titolo della bozza','Discard restores published state');
$bad=$draft;$bad['passi'][3]['attivo']=false;$c=E::transition($c,'save',['draft'=>$bad],1);
editorCheck(!$c['editor']['draft']['passi'][3]['attivo'],'Incomplete drafts can be saved');
try{E::transition($c,'publish',[],1);throw new RuntimeException('Invalid draft published');}catch(InvalidArgumentException $e){editorCheck(true,'Invalid publication rejected');}
$time=$draft;foreach($time['passi'] as &$step)if(in_array($step['id'],['prestazione','professionista','sede'],true))$step['attivo']=false;unset($step);
$time['scelte']['primo_disponibile']=false;$time['scelte']['prestazione_predefinita']=$time['scelte']['professionista_predefinito']=null;
$c['visits']=[];$c['locations']=[];$c=E::transition($c,'save',['draft'=>$time],1);$c=E::transition($c,'publish',[],1);
editorCheck(S::configured($c)&&!W::stepEnabled($c['workflow'],'prestazione'),'Time-only draft publishes without defaults or a service/site catalogue');
$legacy=$c;$legacy['visits']=[1];$legacy['workflow']['scelte']['prestazione_predefinita']='1';$legacy['workflow']['scelte']['professionista_predefinito']='10';
$legacy['editor']['draft']['testi']['benvenuto_titolo']='Modifica non pubblicata';$legacy['editor']['draft']['scelte']['prestazione_predefinita']='1';
$repaired=E::removeHiddenDefaults($legacy);
editorCheck($repaired['workflow']['scelte']['prestazione_predefinita']===null&&$repaired['workflow']['scelte']['professionista_predefinito']===null,'Legacy required defaults can be removed');
editorCheck($repaired['workflow']['testi']['benvenuto_titolo']===$legacy['workflow']['testi']['benvenuto_titolo']&&$repaired['editor']['draft']['testi']['benvenuto_titolo']==='Modifica non pubblicata','Repair preserves unpublished customer changes');
editorCheck(count($repaired['editor']['history'])===count($legacy['editor']['history'])+1,'Repair archives the previous published configuration');
editorCheck(E::removeHiddenDefaults($repaired)===$repaired,'Repair is idempotent');
echo "PASS $checks draft/publication/history checks.\n";
