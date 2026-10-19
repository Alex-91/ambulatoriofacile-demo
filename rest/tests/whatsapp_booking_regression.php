<?php
// No framework bootstrap, .env, database or network access.
define('APPPATH',__DIR__.'/../app/');
spl_autoload_register(static function($class){if(str_starts_with($class,'App\\')){ $path=APPPATH.str_replace('\\','/',substr($class,4)).'.php';if(is_file($path))require $path;}});
use App\Services\{WhatsAppBookingConfig,WhatsAppBookingEngine,WhatsAppBookingUnavailable};
$checks=0;
function check($ok,$message){global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
class BookingFakePort {
    public int $bookings=0; public bool $found=false;public bool $conflict=false; public bool $empty=false;public array $saved=[];
    public function choices($c,$s,$step){if($this->empty)return [];return $step==='visit'?[['id'=>1,'label'=>'Visita']]:($step==='day'?[['id'=>'2026-12-10','label'=>'10/12/2026']]:[['id'=>11,'label'=>'09:00']]);}
    public function patient($cf){return $this->found?['id_client'=>7,'nome'=>'Andrea','cognome'=>'Esempio']:null;}
    public function summary($c,$s){return 'Riepilogo '.$s['slot']['label'];}
    public function book($c,$s){if($this->conflict)throw new WhatsAppBookingUnavailable();$this->bookings++;$this->saved=$s;return 100;}
}
$engine=new WhatsAppBookingEngine();
foreach(['visit','day'] as $order)foreach([false,true] as $single)foreach(['name','birth','cf','both'] as $mode)foreach(['auto','manual'] as $confirm){
    $c=WhatsAppBookingConfig::normalize(['order'=>$order,'single_visit'=>$single,'default_visit_id'=>1,'visit_ids'=>[1],'patient_mode'=>$mode,'confirmation'=>$confirm]);$p=new BookingFakePort();$s=[];
    $send=function($text)use($engine,$c,$p,&$s){$r=$engine->advance($c,$s,$text,$p);$s=$r['state'];return $r;};
    $send('PRENOTA');check($s['step']===($single||$order==='day'?'day':'visit'),'First step');
    $send('1');if(!$single)$send('1');$send('1');
    check($s['step']===(in_array($mode,['cf','both'])?'cf':'name'),'Data mode');
    if(in_array($mode,['cf','both'])){$send('invalid');check($s['step']==='cf','Reject bad fiscal code');$send('RSSMRA85T10A562S');}
    check($s['step']==='name','New patient asks name');$send('Mario');$send('Rossi');
    if($mode==='birth'){$send('31/02/2000');check($s['step']==='birth','Reject invalid date');$send('10/12/1985');}
    check($s['step']==='summary','Summary before write');check($p->bookings===0,'No booking before confirmation');
    $r=$send('1');check($s['step']===($confirm==='auto'?'done':'pending'),'Confirmation mode');check($p->bookings===($confirm==='auto'?1:0),'No automatic booking in manual mode');
    $send('1');check($p->bookings<=1,'Duplicate confirmation');
}
$c=WhatsAppBookingConfig::normalize(['single_visit'=>true,'default_visit_id'=>1,'patient_mode'=>'cf']);$p=new BookingFakePort();$p->found=true;$s=[];
foreach(['prenota','1','1','RSSMRA85T10A562S'] as $text){$r=$engine->advance($c,$s,$text,$p);$s=$r['state'];}
check($s['step']==='summary'&&$s['patient']['id_client']===7,'Existing patient linked');
$p->conflict=true;$r=$engine->advance($c,$s,'conferma',$p);check($r['state']['step']==='time','Lost slot returns to availability');
$r=$engine->advance($c,$s,'segreteria',$p);check($r['request']==='handoff','Handoff queue');
$r=$engine->advance($c,$s,'annulla',$p);check($r['state']===[],'Cancellation');
check($engine->advance($c,[],'unrelated',$p)===null,'Unrelated messages remain available to legacy rules');
$p->empty=true;$r=$engine->advance($c,[],'prenota',$p);check($r['state']['step']==='empty','Empty agenda');
$c['handoff']=false;$r=$engine->advance($c,$s,'segreteria',$p);check(empty($r['request']),'Disabled handoff');
$c['unknown_patient']='staff';$p->found=false;$p->empty=false;$s=[];foreach(['prenota','1','1','RSSMRA85T10A562S'] as $text){$r=$engine->advance($c,$s,$text,$p);$s=$r['state'];}check($r['request']==='handoff','Unknown patient staff policy');
$catalog=['doctors'=>[['id'=>1]],'visits'=>[['id'=>1]],'locations'=>[['id'=>3]]];$c=WhatsAppBookingConfig::normalize(['doctor_id'=>1,'location_id'=>3,'location'=>'Studio','visit_ids'=>[1]]);WhatsAppBookingConfig::validate($c,$catalog);
foreach(['cf','both'] as $mode){$c['patient_mode']=$mode;$c['single_visit']=true;$c['default_visit_id']=1;$p=new BookingFakePort();$p->found=true;$s=[];foreach(['prenota','1','1','RSSMRA85T10A562S'] as $text){$r=$engine->advance($c,$s,$text,$p);$s=$r['state'];}check($s['step']===($mode==='both'?'name':'summary'),'Existing patient identification policy');}
$p=new class extends BookingFakePort {public function choices($c,$s,$step){return array_map(static fn($i)=>['id'=>$i,'label'=>'Opzione '.$i],range(1,12));}};
$r=$engine->advance($c,[],'prenota',$p);check(str_contains($r['reply'],'8. Opzione 8')&&!str_contains($r['reply'],'Opzione 9'),'Eight numbered choices');$r=$engine->advance($c,$r['state'],'altro',$p);check(str_contains($r['reply'],'1. Opzione 9'),'Next choices page');
$c['single_visit']=false;
foreach(['doctor_id'=>2,'visit_ids'=>[2],'location_id'=>4,'keywords'=>['annulla']] as $key=>$value){try{WhatsAppBookingConfig::validate(array_replace($c,[$key=>$value]),$catalog);check(false,'Invalid config accepted');}catch(InvalidArgumentException $expected){check(true,'Invalid config rejected');}}
echo "PASS $checks checks: booking paths, patient modes, validation, confirmation, conflicts, handoff, cancellation and tenant catalog.\n";
