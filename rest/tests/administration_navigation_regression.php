<?php
require __DIR__.'/../app/Services/AdministrationNavigation.php';
use App\Services\AdministrationNavigation as Nav;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$paths=['admin/fatturazione-documenti','admin/fatturazione-strutture','admin/fatturazione-scadenzario','admin/fatturazione/gestione?tab=documenti','admin/fatturazione/gestione?tab=catalogo&kind=service','admin/fatturazione/gestione?tab=catalogo&kind=list','admin/fatturazione/gestione?tab=catalogo&kind=agreement','admin/fatturazione/gestione?tab=catalogo&kind=rule','admin/sistema-ts','admin/sistema-ts/documenti','admin/sistema-ts/documenti/nuovo','admin/sistema-ts/diagnostica','admin/fatturazione-statistiche','admin/fatturazione/gestione?tab=report','admin/fatturazione/gestione?tab=integrazioni','admin/fatturazione-documento','login/spazio/fatturazione','login/spazio/sistema-ts','admin/fse2','admin/accettazione'];
foreach(['','demo/','login/'] as $prefix){
 $links=array_map(fn($p)=>['id'=>md5($p),'label'=>'old','icon'=>'Amministrazione','href'=>'https://example.test/'.$prefix.$p],$paths);
 $links[]=['id'=>'custom','label'=>'Custom','icon'=>'','href'=>'/custom'];
 $result=Nav::organize([['id'=>'admin','label'=>'Amministrazione','icon'=>'','children'=>$links]]);
 check(count($result['areas'])===6,'six areas');
 $available=[];$collect=function($nodes)use(&$collect,&$available){foreach($nodes as $n){if(isset($n['href']))$available[]=$n['href'];else $collect($n['children']??[]);}};
 $collect($result['tree']);foreach($result['areas'] as $area)$collect($area);
 foreach($links as $link)check(in_array($link['href'],$available,true),'lost '.$link['href']);
 check(count(array_filter($result['tree'],fn($n)=>$n['label']==='Oggi'))===1,'acceptance moved');
}
check(Nav::organize([])===['tree'=>[],'areas'=>[]],'no unauthorized links');
check(!Nav::matches('/admin/fatturazione','/admin/fatturazione/gestione?tab=documenti'),'overview not active');
check(!Nav::matches('/admin/fatturazione/gestione?tab=catalogo&kind=list','/admin/fatturazione/gestione?tab=catalogo&kind=rule'),'query-specific active');
check(Nav::matches('/login/admin/fatturazione-documenti','/login/admin/fatturazione-documenti/modifica/3'),'editor within invoices');
echo "PASS: all authorized destinations preserved, six areas, empty permissions, query-aware matching and deployment prefixes.\n";
