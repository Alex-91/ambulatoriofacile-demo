<?php
require __DIR__.'/../app/Services/NavigationLayoutService.php';
use App\Services\NavigationLayoutService as Layout;
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
$group=['id'=>'g_root','parent'=>'','type'=>'group','label'=>'Esami','icon'=>'Esami','hidden'=>false];
$leaf=['id'=>Layout::linkId('https://test.invalid/cartella-clinica/diagnostica'),'parent'=>'g_root','type'=>'link','label'=>'Immagini','icon'=>'Esami','hidden'=>false];
$nodes=[$group,$leaf];
check(Layout::validate($nodes,$nodes)===$nodes,'Valid layout rejected');
$allowed=[$leaf['id']=>['href'=>'https://tenant.invalid/cartella-clinica/diagnostica','label'=>'Originale']];
check(Layout::project($nodes,[])===[],'Empty unauthorized sections must disappear');
check(Layout::project($nodes,$allowed)[0]['children'][0]['href']===$allowed[$leaf['id']]['href'],'URL must come from authorization set');
$moved=$nodes;$moved[1]['parent']='';$moved[1]['href']='https://attacker.invalid';
$clean=Layout::validate($moved,$nodes);check(!isset($clean[1]['href']),'Arbitrary URL persisted');
check(Layout::project($clean,[])===[],'Moving a link grants access');
$hidden=$nodes;$hidden[0]['hidden']=true;check(Layout::project($hidden,$allowed)===[],'Hidden parent leaked children');
$cases=[];$bad=$nodes;$bad[0]['parent']='g_root';$cases[]=$bad;
$bad=$nodes;$bad[1]['parent']='missing';$cases[]=$bad;
$bad=$nodes;$bad[0]['parent']=$leaf['id'];$cases[]=$bad;
$bad=$nodes;$bad[]=$leaf;$cases[]=$bad;
$bad=$nodes;$bad[1]['id']='l_forged';$cases[]=$bad;
$bad=$nodes;$bad[1]['icon']='<script>'; $cases[]=$bad;
$cases[]=[$group];
foreach($cases as $case){$rejected=false;try{Layout::validate($case,$nodes);}catch(InvalidArgumentException $e){$rejected=true;}check($rejected,'Invalid tree accepted');}
check(Layout::linkId('https://a.invalid/app/agenda?x=1')===Layout::linkId('https://b.invalid/agenda?x=1'),'Environment-independent identity');
check(Layout::linkId('/agenda?x=1')!==Layout::linkId('/agenda?x=2'),'Query-specific destinations conflated');
echo "PASS navigation layout validation, cycles, catalog integrity, permissions, hidden ancestors and route identity\n";
