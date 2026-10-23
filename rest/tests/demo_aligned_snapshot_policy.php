<?php
declare(strict_types=1);
$tool=dirname(__DIR__).'/tools/AlignedDemoSnapshot.php';
require is_file($tool)?$tool:__DIR__.'/AlignedDemoSnapshot.php';
$checks=0;
function expectResetPolicy(bool $pass): void { global $checks; if(!$pass)throw new RuntimeException('Demo reset policy regression');$checks++; }
foreach ([['2026-10-23','2026-10-25',0],['2026-10-23','2026-10-26',7],['2026-10-23','2026-11-02',14],['2026-10-23','2026-10-18',-7],['2026-03-27','2026-03-30',7]] as [$a,$b,$expected]) expectResetPolicy(AlignedDemoSnapshot::weekShift($a,$b)===$expected);
foreach (['2026-02-30','not-a-date','2026-13-01'] as $date) {
 $rejected=false;
 try {AlignedDemoSnapshot::weekShift('2026-10-23',$date);}catch(RuntimeException $e){$rejected=$e->getMessage()==='Data reset non valida.';}
 expectResetPolicy($rejected);
}
$saved=[];foreach(['DB_DATABASE','DEMO_SITE_ENABLED'] as $key)$saved[$key]=getenv($key);
try {
 foreach ([['other_database','1'],['ambulatoriofacile_demo','0']] as [$database,$enabled]) {
  putenv('DB_DATABASE='.$database);putenv('DEMO_SITE_ENABLED='.$enabled);
  try {AlignedDemoSnapshot::run('restore');expectResetPolicy(false);}catch(RuntimeException $e){expectResetPolicy(str_contains($e->getMessage(),'soltanto sul database'));}
 }
 try {AlignedDemoSnapshot::run('unknown');expectResetPolicy(false);}catch(RuntimeException $e){expectResetPolicy(str_contains($e->getMessage(),'Azione'));}
}finally {foreach($saved as $key=>$value)putenv($value===false?$key:$key.'='.$value);}
echo json_encode(['ok'=>true,'checks'=>$checks]),PHP_EOL;
