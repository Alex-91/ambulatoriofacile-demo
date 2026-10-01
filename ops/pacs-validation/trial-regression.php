<?php
/** Standalone regression: only random temporary training storage, no .env or DB. */
if (PHP_SAPI!=='cli') exit(1);
$root=dirname(__DIR__,2);
foreach(['PacsException','PacsTransport','DicomWebClient','ModalityWorklist','PacsTrialService','PacsNavigation'] as $name) require $root.'/rest/app/Services/Pacs/'.$name.'.php';
use App\Services\Pacs\{PacsTrialService as Trial,PacsException,PacsNavigation};
$dir=sys_get_temp_dir().'/af-pacs-trial-'.bin2hex(random_bytes(8));
$count=0;
function check(bool $ok): void {global $count;if(!$ok)throw new RuntimeException('Assertion '.($count+1).' failed');$count++;}
function rejects(callable $f): void {try{$f();}catch(PacsException){check(true);return;}throw new RuntimeException('Expected rejection');}
try {
 $s=new Trial($dir,4,11);$input=['exam'=>'us-abdomen','station'=>'DEMO_US_1','scheduled_at'=>'2026-10-01T16:00','reason'=>'Note sintetiche'];$key=bin2hex(random_bytes(16));
 rejects(fn()=>new Trial($dir,5,11));rejects(fn()=>new Trial($dir,4,0));
 rejects(fn()=>$s->create(array_replace($input,['station'=>'DEMO_CT_1']),$key));
 rejects(fn()=>$s->create(array_replace($input,['scheduled_at'=>'2026-10-25T02:30']),$key));
 rejects(fn()=>$s->create($input,'bad'));
 $id=$s->create($input,$key);check($s->create($input,$key)===$id);check(count($s->listing())===1);
 rejects(fn()=>$s->create(array_replace($input,['reason'=>'Changed']),$key));
 check((new Trial($dir,4,11))->read($id)['state']==='draft');
 rejects(fn()=>(new Trial($dir,4,12))->read($id));
 rejects(fn()=>$s->change($id,1,'complete'));rejects(fn()=>$s->export($id,1,'wl'));
 $r=$s->change($id,1,'confirm');check($r['state']==='ready');
 rejects(fn()=>$s->change($id,1,'accept'));
 $json=$s->export($id,2,'json');$data=json_decode($json,true,32,JSON_THROW_ON_ERROR);
 check($data['00100020']['Value'][0]==='AF-DEMO-20261001');check($data['00400100']['Value'][0]['00400001']['Value'][0]==='DEMO_US_1');
 check(!str_contains($json,'Note sintetiche'));check($data['00080050']['Value'][0]===$r['accession']);
 $wl=$s->export($id,3,'wl');check(substr($wl,128,4)==='DICM');check(str_contains($wl,$r['study_uid']));
 $r=$s->change($id,4,'accept');check($r['state']==='accepted');
 $r=$s->change($id,5,'start');check($r['state']==='in_progress');rejects(fn()=>$s->export($id,6,'wl'));rejects(fn()=>$s->change($id,6,'cancel'));
 $r=$s->change($id,6,'complete');check($r['state']==='performed');
 $r=$s->change($id,7,'report','Referto interamente sintetico');check($r['report']==='Referto interamente sintetico');check(count($r['history'])===8);
 $id2=$s->create($input,bin2hex(random_bytes(16)));check($id2!==$id);check($s->change($id2,1,'cancel')['state']==='cancelled');rejects(fn()=>$s->change($id2,2,'confirm'));
 check(count(PacsNavigation::links(4,true))===5);check(count(PacsNavigation::links(5,true))===3);check(count(PacsNavigation::links(4,false))===1);
 check(PacsNavigation::owns('cartella-clinica/demo-pacs/richieste'));check(!PacsNavigation::owns('cartella-clinica/pazienti/1'));
 echo "PASS: $count assertions (storage, isolation, states, stale updates, idempotency, MWL, navigation)\n";
} finally {
 if(is_dir($dir)) { $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($dir); }
}
